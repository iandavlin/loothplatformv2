<?php
/**
 * bb-mirror/bin/reconcile.php — belt-and-suspenders cron for missed sync hooks.
 *
 * Walks wp_posts (forum, topic, reply) and wp_bp_groups for anything modified
 * since the last reconcile bookmark; upserts each via the same materializer
 * helpers as _sync.php. Refreshes the total_last_active_at and
 * effective_group_id rollups at the end.
 *
 * Usage:
 *   sudo -u looth-dev wp eval-file /home/ubuntu/projects/bb-mirror/bin/reconcile.php
 *
 * Cron: systemd timer at /etc/systemd/system/bb-mirror-reconcile.{service,timer}
 *       runs every 10 minutes. First run picks up everything modified in the
 *       last 24h regardless of bookmark, as a self-bootstrap.
 *
 * Bookkeeping: sync_state.last_reconcile_at holds the last successful walk
 * timestamp (unix). Re-runs walk from that point forward, with a 60-second
 * overlap to absorb clock skew + still-in-flight sync POSTs.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . "\n");
    exit(2);
}

global $wpdb;

$db = bb_mirror_db(readonly: false);

// ---------- bookmark ------------------------------------------------------
$row = $db->query("SELECT value FROM sync_state WHERE key = 'last_reconcile_at'")->fetch();
$last_reconcile = $row ? (int)$row['value'] : 0;

// First run: bootstrap with a 24h window so we catch anything since deploy.
if ($last_reconcile === 0) {
    $last_reconcile = time() - 86400;
    echo "First reconcile — bootstrapping with 24h window\n";
}

// Overlap by 60s to absorb in-flight sync POSTs that haven't committed yet.
$window_start = $last_reconcile - 60;
$window_start_iso = gmdate('Y-m-d H:i:s', $window_start);
$now = time();

echo "Reconcile window: " . gmdate('Y-m-d H:i:s', $window_start) . " UTC → now\n";

// Shared materializers (single source — also required by api/v0/_sync.php).
require_once __DIR__ . '/../lib/materializers.php';

// ---------- bp_groups -----------------------------------------------------
// wp_bp_groups doesn't have a `modified` column. Reconcile walks all 20-ish
// rows on every run — cheap.
echo "Reconciling bp_groups...\n";
$group_ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}bp_groups");
foreach ($group_ids as $gid) {
    bb_mirror_upsert_bp_group((int)$gid, $db);
}
echo "  " . count($group_ids) . " groups\n";

// ---------- forums + topics + replies (delta walk) ------------------------
echo "Reconciling forums/topics/replies modified since $window_start_iso...\n";

$counts  = ['forum' => 0, 'topic' => 0, 'reply' => 0];
$skipped = ['forum' => [], 'topic' => [], 'reply' => []];

// ORDER BY ID so the walk is deterministic: without it the skip report below is
// not reproducible between runs, and "died at row 109" is not a diagnosis.
foreach (['forum', 'topic', 'reply'] as $kind) {
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
          WHERE post_type = %s
            AND post_modified_gmt >= %s
          ORDER BY ID",
        $kind, $window_start_iso
    ));
    // bb_mirror_walk_ids, NOT a bare foreach: one unmirrorable row must never
    // wedge the walk, the bookmark, and every pass that follows. See the
    // function's header for the 11-day outage this encodes.
    $r = bb_mirror_walk_ids($rows, match ($kind) {
        'forum' => fn(int $id) => bb_mirror_upsert_forum($id, $db),
        'topic' => fn(int $id) => bb_mirror_upsert_topic($id, $db),
        'reply' => fn(int $id) => bb_mirror_upsert_reply($id, $db),
    }, $db);
    $counts[$kind]  = $r['done'];
    $skipped[$kind] = $r['skipped'];
    echo "  {$counts[$kind]} {$kind}(s)\n";
}

// Skips are the loud part. A silent skip is the same failure as a silent drop —
// it just fails slower. One line per row, capped so a systemic break cannot
// flood the journal into uselessness.
$skipped_total = array_sum(array_map('count', $skipped));
if ($skipped_total > 0) {
    echo "SKIPPED $skipped_total unmirrorable row(s) — the walk continued:\n";
    foreach ($skipped as $kind => $rows) {
        $n = 0;
        foreach ($rows as $id => $err) {
            if ($n++ >= 10) {
                echo "  {$kind}: … and " . (count($rows) - 10) . " more\n";
                break;
            }
            echo "  SKIP {$kind} {$id}: " . str_replace("\n", ' ', substr($err, 0, 300)) . "\n";
        }
    }
}

// ---------- deep sweep (the BACKWARDS reach) ------------------------------
// ⚠️ THE DELTA WALK ABOVE IS FORWARD-ONLY, AND THAT IS A PERMANENT HOLE.
// It upserts WHERE post_modified_gmt >= last_reconcile_at - 60. Anything that
// diverged and then aged out of that window is invisible to every future pass,
// no matter how wrong the mirror is. Measured on live 2026-08-16: FIVE replies
// were diverged (4 missing, 1 stale) and ALL FIVE had WP timestamps 60-73 days
// older than the bookmark. Reply 71432 had been serving the wrong author's
// content since June and reconcile could not have repaired it in a thousand runs.
//
// So this pass asks the only question the delta walk cannot: does the mirror
// actually MATCH? It compares ids and modified times across the whole table and
// repairs only the rows that differ. That is what makes an aged-out drop
// self-healing instead of permanent.
//
// COST IS BOUNDED TWO WAYS, because a full compare every 10 minutes is not free:
//   · it runs at most every BB_MIRROR_DEEP_EVERY seconds (default 6h), tracked in
//     its own sync_state key so it cannot interfere with the delta bookmark
//   · it repairs only DIFFERING rows. The compare is two id/timestamp lists; the
//     writes are proportional to the damage, which is normally zero.
//
// It cannot fix rows whose WP data is malformed — those are the 5 above, and they
// will now be REPORTED every pass instead of being silently permanent.
$deep_every = (int)(getenv('BB_MIRROR_DEEP_EVERY') ?: 21600);
$row = $db->query("SELECT value FROM sync_state WHERE key = 'last_deep_at'")->fetch();
$last_deep = $row ? (int)$row['value'] : 0;
$deep_due  = (time() - $last_deep) >= $deep_every;
echo "Deep sweep — " . ($deep_due ? "DUE" : sprintf("not due (%ds of %ds)", time() - $last_deep, $deep_every)) . "\n";

if ($deep_due) {
    try {
        foreach (['topic', 'reply'] as $kind) {
            // WP side: id -> modified epoch, published only (the mirror's contract).
            $wp = [];
            foreach ($wpdb->get_results($wpdb->prepare(
                "SELECT ID, UNIX_TIMESTAMP(post_modified_gmt) AS m
                   FROM {$wpdb->posts}
                  WHERE post_type = %s AND post_status = 'publish'", $kind)) as $r) {
                $wp[(int)$r->ID] = (int)$r->m;
            }
            // ⚠️ REFUSE TO SCORE AN EMPTY READ. A failed query returning zero rows
            // would otherwise read as "the mirror is entirely wrong" and this pass
            // would rewrite everything. Same guard compare-mirror.py carries, for
            // the same reason — I hit exactly this while measuring, and a
            // mis-parsed capture reported 5,305 replies missing that were all fine.
            if (!$wp) {
                echo "  deep $kind: SKIPPED — zero rows read from WP, refusing to treat that as total divergence\n";
                continue;
            }
            $pg = [];
            foreach ($db->query("SELECT id, extract(epoch from modified_at)::bigint AS m FROM $kind") as $r) {
                $pg[(int)$r['id']] = (int)$r['m'];
            }
            // A row needs repair if it is absent, or the mirror's copy is older.
            // 60s of slack keeps clock skew and one-second resolution out of it.
            $need = [];
            foreach ($wp as $id => $m) {
                if (!isset($pg[$id]) || $m - $pg[$id] > 60) { $need[] = $id; }
            }
            if (!$need) { echo "  deep $kind: in sync (" . count($wp) . " rows)\n"; continue; }
            $r = bb_mirror_walk_ids($need, match ($kind) {
                'topic' => fn(int $id) => bb_mirror_upsert_topic($id, $db),
                'reply' => fn(int $id) => bb_mirror_upsert_reply($id, $db),
            }, $db);
            printf("  deep %s: %d differed, %d repaired, %d UNREPAIRABLE %s\n",
                $kind, count($need), $r['done'], $r['skipped'],
                $r['skipped'] ? '(malformed WP data — see the skip lines above)' : '');
        }
        $st = $db->prepare(
            "INSERT INTO sync_state (key, value) VALUES ('last_deep_at', ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
        $st->execute([(string) time()]);
    } catch (Throwable $e) {
        // Same rule as every other tail section: never wedge the bookmark write.
        if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable) {} }
        echo "  deep sweep FAILED (continuing): " . $e->getMessage() . "\n";
    }
}

// ---------- ghost sweep (the reverse pass) --------------------------------
// The delta walk above is driven entirely from wp_posts, so it can only repair
// rows that STILL EXIST in WordPress. Rows that exist only in the mirror —
// GHOSTS — can never appear in that query, and they still render to members.
// See bb_mirror_sweep_ghosts() in lib/materializers.php for the mechanism, the
// two paths that produce them, and the safety rails.
//
// REPORT-ONLY BY DEFAULT: set BB_MIRROR_SWEEP_GHOSTS=1 to actually delete. The
// timer starts reporting ghosts immediately at zero risk; the sweep gets turned
// on once the numbers are trusted.
$sweep_ghosts = getenv('BB_MIRROR_SWEEP_GHOSTS') === '1';
echo "Ghost sweep — " . ($sweep_ghosts ? "ACTIVE" : "report-only (BB_MIRROR_SWEEP_GHOSTS=1 to delete)") . "\n";

// Same rule as the delta walk: a throw in the tail sections must not skip the
// bookmark write below. If it did, the window would never advance and every
// later run would rewalk a forever-growing set — the exact wedge, one section
// over. Each tail step reports its own failure and the run carries on.
$ghost_report = [];
try {
    $ghost_report = bb_mirror_sweep_ghosts(
        $db,
        fn(string $kind) => $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $kind)),
        $sweep_ghosts
    );
} catch (Throwable $e) {
    if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable) {} }
    echo "  ghost sweep FAILED (continuing): " . $e->getMessage() . "\n";
}
foreach ($ghost_report as $kind => $r) {
    $sample = implode(', ', array_slice($r['ids'], 0, 20)) . (count($r['ids']) > 20 ? ', …' : '');
    switch ($r['status']) {
        case 'clean':
            echo "  {$kind}: none\n"; break;
        case 'abort_empty_wp':
            echo "  {$kind}: ABORT — wp_posts returned zero rows. Refusing to read that as "
               . "'everything was deleted'.\n"; break;
        case 'refused_cap':
            echo "  {$kind}: {$r['ghosts']} ghost(s) of {$r['total']} EXCEEDS the cap "
               . "({$r['allowed']}). Refusing to sweep — investigate. ids: {$sample}\n"; break;
        case 'report_only':
            echo "  {$kind}: {$r['ghosts']} ghost(s) of {$r['total']} — REPORT ONLY, nothing "
               . "deleted. ids: {$sample}\n"; break;
        case 'swept':
            echo "  {$kind}: swept {$r['ghosts']} ghost(s) of {$r['total']}. ids: {$sample}\n"; break;
    }
    // A ghost topic whose replies still exist in WordPress is never swept: the FK
    // cascade would take those live replies with it. Surfaced, not silently skipped.
    if (!empty($r['held'])) {
        echo "  {$kind}: HELD " . count($r['held']) . " ghost topic(s) that still have replies "
           . "live in WordPress — sweeping them would cascade those replies away. "
           . "ids: " . implode(', ', $r['held']) . "\n";
    }
}

// ---------- reply_count rollup --------------------------------------------
// bbPress doesn't bump a topic's post_modified_gmt when a reply is added or
// removed, so the delta-walk above never re-materializes the parent topic and
// its stored reply_count drifts (card shows "0 replies" while the live facepile
// shows avatars). Recompute every topic's reply_count from WP published replies
// (authoritative). Idempotent — only drifted rows are written.
echo "Refreshing topic reply_count...\n";
try {
    $rc_fixed = bb_mirror_refresh_all_reply_counts($db);
    echo "  $rc_fixed topic(s) corrected\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable) {} }
    echo "  reply_count rollup FAILED (continuing): " . $e->getMessage() . "\n";
}

// ---------- rollup refresh ------------------------------------------------
// Both rollups: ancestor chains are shallow, descendant trees too. Cheap to
// re-run sitewide; saves us from drift if per-row sync missed an ancestor
// chain refresh somewhere.
echo "Refreshing total_last_active_at...\n";
try {
$db->exec("
    WITH RECURSIVE descendants AS (
      SELECT id, id AS root_id FROM forum
      UNION ALL
      SELECT f.id, d.root_id FROM forum f JOIN descendants d ON f.parent_forum_id = d.id
    )
    UPDATE forum f SET total_last_active_at = (
      SELECT MAX(t.last_active_at) FROM topic t
      WHERE t.forum_id IN (SELECT id FROM descendants WHERE root_id = f.id)
    )
");

echo "Refreshing effective_group_id...\n";
$db->exec("
    WITH RECURSIVE chain AS (
      SELECT id AS leaf_id, id AS at_id, parent_forum_id, group_id FROM forum
      UNION ALL
      SELECT c.leaf_id, f.id, f.parent_forum_id, f.group_id
        FROM chain c JOIN forum f ON f.id = c.parent_forum_id
       WHERE c.group_id IS NULL
    )
    UPDATE forum SET effective_group_id = (
      SELECT group_id FROM chain
       WHERE chain.leaf_id = forum.id AND chain.group_id IS NOT NULL
       LIMIT 1
    )
");
} catch (Throwable $e) {
    if ($db->inTransaction()) { try { $db->rollBack(); } catch (Throwable) {} }
    echo "  rollup refresh FAILED (continuing): " . $e->getMessage() . "\n";
}

// ---------- bookmark update -----------------------------------------------
$upsert = $db->prepare(bb_mirror_upsert_sql('sync_state', ['key','value','updated_at'], 'key'));
$upsert->execute(['last_reconcile_at', (string)$now, bb_mirror_ts($now)]);

$total_rows = array_sum($counts) + count($group_ids);
echo "Reconcile complete: $total_rows row(s) touched (forums={$counts['forum']}, topics={$counts['topic']}, replies={$counts['reply']}, groups=" . count($group_ids) . "), skipped=$skipped_total\n";
echo "Next window starts: " . gmdate('Y-m-d H:i:s', $now) . " UTC\n";

// EXIT 0 EVEN WITH SKIPS, on purpose. A non-zero exit parks the unit in
// `systemctl --failed` for as long as the bad rows exist — live has 4 orphaned
// June replies that only Ian can repair — and a permanently-red unit is a dead
// alert channel, not an alert. Skips are reported in the journal and alerted on
// by tools/mirror-sync/watch-mirror-sync.sh, which can tell a NEW skip from the
// known backlog. The run itself succeeded: it walked everything it could.
