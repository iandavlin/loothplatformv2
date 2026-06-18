<?php
require __DIR__.'/../config.php';
require_once __DIR__.'/indexer.php';      // archive_poc_index_post / _delete_post / _kind_map
/**
 * archive-poc/bin/reconcile-pg.php — belt-and-suspenders reconcile for the
 * fire-and-forget save sync (modeled on bb-mirror/bin/reconcile.php).
 *
 * The mu-plugin POSTs to /archive-api/v0/{_sync,_materialize} on every save with
 * timeout=1, blocking=false — so a dropped POST (FPM busy, restart, network
 * blip) is NEVER retried and the index silently diverges from WordPress forever.
 * This walks wp_posts by post_modified_gmt since a bookmark and re-applies the
 * SAME per-post primitives the live sync uses, for BOTH stores:
 *   content_item   → archive_poc_index_post()      (the discovery feed)
 *   article_blobs  → lg_materialize_upsert()        (the standalone render cache)
 * then sweeps orphans (hard-deleted / unpublished posts the delta walk can't see)
 * out of both stores. Idempotent; safe to run as often as you like.
 *
 * Run AS THE WP WRITER (looth-dev on dev, looth-live on live) — it can read
 * WordPress AND holds the discovery INSERT/UPDATE/DELETE grants (same role the
 * _sync / _materialize FPM pool uses). archive-poc owns the schema but can't read
 * WP, so it is NOT the runner here.
 *   LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
 *     sudo -u looth-dev php bin/reconcile-pg.php
 *
 * One-time setup (schema owner): the bookmark table + grants live in
 * bin/reconcile-setup.sql — apply once per env as archive-poc:
 *   sudo -u archive-poc psql "host=/var/run/postgresql dbname=looth" \
 *     -f bin/reconcile-setup.sql
 *
 * Flags:
 *   --full        ignore the bookmark; walk EVERY managed post (first-run / repair)
 *   --since=SECS  walk posts modified in the last SECS seconds (overrides bookmark)
 *   --no-blobs    skip the article_blobs pass (content_item only)
 *   --dry-run     report what would change; write nothing, don't move the bookmark
 *   --force       allow an orphan sweep that would delete >25% of a store
 *
 * Bookmark: discovery.sync_state.archive_reconcile_at (unix seconds of the last
 * successful walk). Re-runs start from there minus a 60s overlap to absorb
 * in-flight sync POSTs + clock skew. First run bootstraps a 24h window.
 *
 * Cron: a systemd timer is SYSADMIN-owned (request from the coordinator). Until
 * it lands, run this by hand (or from any existing cron) on the cadence you want.
 *
 * READ-ONLY on WordPress (SELECT + get_post only); writes only to discovery.*.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$argvv     = $argv ?? [];
$FULL      = in_array('--full', $argvv, true);
$NO_BLOBS  = in_array('--no-blobs', $argvv, true);
$DRY       = in_array('--dry-run', $argvv, true);
$FORCE     = in_array('--force', $argvv, true);
$SINCE     = null;
foreach ($argvv as $a) {
    if (preg_match('/^--since=(\d+)$/', $a, $m)) $SINCE = (int)$m[1];
}
$ORPHAN_CAP = 0.25;   // refuse to delete more than this fraction of a store w/o --force

// Force the PG DSN before any factory reads getenv() (same as backfill-pg.php /
// the materializer). content_item + article_blobs share the one connection.
if (!getenv('LG_ARCHIVE_POC_DSN')) {
    putenv('LG_ARCHIVE_POC_DSN=pgsql:host=/var/run/postgresql;dbname=looth');
}

// --- boot WordPress (read-only) -------------------------------------------
if (!function_exists('get_post')) {
    if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
    if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    require LG_ARCHIVE_POC_WP_LOAD;
}
global $wpdb;
$wpdb->suppress_errors(true);

// --- open postgres (one connection, both stores) --------------------------
$db = lg_archive_poc_pdo();
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    fwrite(STDERR, "reconcile-pg.php requires LG_ARCHIVE_POC_DSN with the pgsql driver\n");
    exit(1);
}
if (!$NO_BLOBS) require_once __DIR__.'/materializer.php';   // lg_materialize_upsert/_delete

// --- bookmark store -------------------------------------------------------
// discovery.sync_state is created once by the schema owner (bin/reconcile-setup.sql);
// the WP writer only reads/writes the bookmark row. Fail with a clear pointer if
// the one-time setup hasn't been applied in this env.
try {
    $bookmark = (int) ($db->query(
        "SELECT value FROM discovery.sync_state WHERE key = 'archive_reconcile_at'")
        ->fetchColumn() ?: 0);
} catch (Throwable $e) {
    fwrite(STDERR, "discovery.sync_state missing — apply the one-time setup first:\n"
        . "  sudo -u archive-poc psql \"host=/var/run/postgresql dbname=looth\" -f bin/reconcile-setup.sql\n");
    exit(2);
}

$now = time();
if ($SINCE !== null) {
    $window_start = $now - $SINCE;
    echo "Window: last {$SINCE}s (--since)\n";
} elseif ($FULL || $bookmark === 0) {
    $window_start = 0;     // epoch → everything
    echo ($FULL ? "Window: ALL posts (--full)\n" : "Window: first run — bootstrapping ALL posts\n");
} else {
    $window_start = $bookmark - 60;   // 60s overlap for in-flight POSTs / clock skew
    echo "Window: since " . gmdate('Y-m-d H:i:s', $window_start) . " UTC (bookmark - 60s overlap)\n";
}
$window_iso = gmdate('Y-m-d H:i:s', $window_start);
if ($DRY) echo "(--dry-run: no writes, bookmark not moved)\n";

// --- managed CPT set (canonical map; topic/discussion dropped on PG by index_post) ---
$KINDS    = array_keys(archive_poc_kind_map());
$kinds_ph = implode(',', array_fill(0, count($KINDS), '%s'));

// --- delta walk -----------------------------------------------------------
$ids = $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts}
      WHERE post_type IN ($kinds_ph)
        AND post_modified_gmt >= %s
      ORDER BY ID",
    array_merge($KINDS, [$window_iso])
));
echo "Delta walk: " . count($ids) . " post(s) modified since window start\n";

$act = ['upsert' => 0, 'delete' => 0, 'skip' => 0];
$blob_act = ['upsert' => 0, 'delete' => 0];
foreach ($ids as $id) {
    $id = (int) $id;
    if ($DRY) continue;
    $r = archive_poc_index_post($db, $id);
    $act[$r['action']] = ($act[$r['action']] ?? 0) + 1;
    if (!$NO_BLOBS) {
        $br = lg_materialize_upsert($db, $id);   // builds blob, or deletes if not managed/unpublished
        if (($br['action'] ?? '') === 'upsert') $blob_act['upsert']++;
        elseif (($br['action'] ?? '') === 'delete') $blob_act['delete']++;
    }
}
echo "  content_item: {$act['upsert']} upsert, {$act['delete']} delete, {$act['skip']} skip\n";
if (!$NO_BLOBS) echo "  article_blobs: {$blob_act['upsert']} upsert, {$blob_act['delete']} delete\n";

// --- orphan sweep (deletes the delta walk can't see: hard-deletes / missed
//     status flips). Set-based: pull the live published-managed ID set once,
//     then any indexed row whose post isn't in it is an orphan. --------------
$published = array_flip(array_map('intval', $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($kinds_ph)",
    $KINDS
))));

$content_ids = array_map('intval', $db->query(
    "SELECT id FROM content_item WHERE source = 'wp'")->fetchAll(PDO::FETCH_COLUMN));
$content_orphans = array_values(array_filter($content_ids, fn($i) => !isset($published[$i])));

$blob_ids = $NO_BLOBS ? [] : array_map('intval', $db->query(
    "SELECT post_id FROM article_blobs")->fetchAll(PDO::FETCH_COLUMN));
$blob_orphans = array_values(array_filter($blob_ids, fn($i) => !isset($published[$i])));

$sweep = function (string $label, array $orphans, int $total, callable $delete) use ($DRY, $FORCE, $ORPHAN_CAP) {
    if (!$orphans) { echo "  $label: 0 orphans\n"; return; }
    $frac = $total > 0 ? count($orphans) / $total : 1.0;
    if (!$FORCE && $frac > $ORPHAN_CAP) {
        fwrite(STDERR, "  $label: REFUSING to delete " . count($orphans) . "/$total orphans ("
            . round($frac * 100) . "% > " . round($ORPHAN_CAP * 100) . "%); re-run with --force if intentional\n");
        return;
    }
    if ($DRY) { echo "  $label: would delete " . count($orphans) . " orphan(s)\n"; return; }
    foreach ($orphans as $id) $delete($id);
    echo "  $label: deleted " . count($orphans) . " orphan(s)\n";
};
echo "Orphan sweep:\n";
$sweep('content_item', $content_orphans, count($content_ids), fn($id) => archive_poc_delete_post($db, $id));
if (!$NO_BLOBS)
    $sweep('article_blobs', $blob_orphans, count($blob_ids), fn($id) => lg_materialize_delete($db, $id));

// --- advance bookmark -----------------------------------------------------
if (!$DRY) {
    $up = $db->prepare("INSERT INTO discovery.sync_state (key, value, updated_at)
        VALUES ('archive_reconcile_at', :v, now())
        ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()");
    $up->execute([':v' => (string) $now]);
    echo "Bookmark advanced to " . gmdate('Y-m-d H:i:s', $now) . " UTC\n";
}
echo "Reconcile complete.\n";
