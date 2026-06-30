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

$counts = ['forum' => 0, 'topic' => 0, 'reply' => 0];

foreach (['forum', 'topic', 'reply'] as $kind) {
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
          WHERE post_type = %s
            AND post_modified_gmt >= %s",
        $kind, $window_start_iso
    ));
    foreach ($rows as $id) {
        $id = (int)$id;
        switch ($kind) {
            case 'forum': bb_mirror_upsert_forum($id, $db); break;
            case 'topic': bb_mirror_upsert_topic($id, $db); break;
            case 'reply': bb_mirror_upsert_reply($id, $db); break;
        }
        $counts[$kind]++;
    }
    echo "  {$counts[$kind]} {$kind}(s)\n";
}

// ---------- reply_count rollup --------------------------------------------
// bbPress doesn't bump a topic's post_modified_gmt when a reply is added or
// removed, so the delta-walk above never re-materializes the parent topic and
// its stored reply_count drifts (card shows "0 replies" while the live facepile
// shows avatars). Recompute every topic's reply_count from WP published replies
// (authoritative). Idempotent — only drifted rows are written.
echo "Refreshing topic reply_count...\n";
$rc_fixed = bb_mirror_refresh_all_reply_counts($db);
echo "  $rc_fixed topic(s) corrected\n";

// ---------- rollup refresh ------------------------------------------------
// Both rollups: ancestor chains are shallow, descendant trees too. Cheap to
// re-run sitewide; saves us from drift if per-row sync missed an ancestor
// chain refresh somewhere.
echo "Refreshing total_last_active_at...\n";
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

// ---------- bookmark update -----------------------------------------------
$upsert = $db->prepare(bb_mirror_upsert_sql('sync_state', ['key','value','updated_at'], 'key'));
$upsert->execute(['last_reconcile_at', (string)$now, bb_mirror_ts($now)]);

$total_rows = array_sum($counts) + count($group_ids);
echo "Reconcile complete: $total_rows row(s) touched (forums={$counts['forum']}, topics={$counts['topic']}, replies={$counts['reply']}, groups=" . count($group_ids) . ")\n";
echo "Next window starts: " . gmdate('Y-m-d H:i:s', $now) . " UTC\n";
