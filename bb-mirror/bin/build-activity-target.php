<?php
/**
 * bb-mirror/bin/build-activity-target.php — populate discovery.bb_activity_target.
 *
 * Maps legacy BuddyPress activity ids → Hub cards (post_type, item_id) so the
 * comments+reactions ENGINE can remap legacy BuddyBoss reactions (keyed by
 * activity_id) onto discovery.card_reactions at cutover. The engine SELECTs this
 * map and runs its reaction migration --all; an empty map is a safe no-op, so this
 * can run any time before that migration.
 *
 * Derivation (from wp_bp_activity.type), per the coordinator contract:
 *   bbp_topic_create   → ('topic', secondary_item_id)   -- secondary_item_id = topic post id
 *   new_blog_<cpt>     → (<cpt>,  secondary_item_id)     -- e.g. new_blog_post-imgcap → 'post-imgcap'
 * Both are JOINed to wp_posts for integrity: a topic row must point at a real
 * `topic` post (drops a known glitch row whose secondary_item_id is a forum), and
 * a new_blog_<cpt> row's post must actually be of that cpt. item_id>0 is enforced
 * by the table CHECK. src_type keeps the original activity type for provenance.
 *
 * Idempotent: upsert on activity_id, so re-running (incl. at cutover on live) just
 * refreshes the map. Only legacy activities mapped — net-new Hub reactions write
 * straight to card_reactions, no activity id involved.
 *
 * Usage (dev now, and again on live at cutover):
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file \
 *     /home/ubuntu/projects/bb-mirror/bin/build-activity-target.php
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp --path=/var/www/dev eval-file " . __FILE__ . "\n");
    exit(2);
}

require __DIR__ . '/../config.php';

global $wpdb;
$db = bb_mirror_db(readonly: false);   // PG write handle (looth-dev role has INSERT)
$act = $wpdb->prefix . 'bp_activity';
$posts = $wpdb->posts;

// Pull the mappable activities from WordPress (MySQL), integrity-joined to wp_posts.
//  - topics: secondary_item_id must be a real `topic` post (drops forum-pointing glitches)
//  - content: the post must actually be the cpt the activity type names
$sql = "
    SELECT a.id AS activity_id, 'topic' AS post_type, a.secondary_item_id AS item_id, a.type AS src_type
      FROM {$act} a
      JOIN {$posts} p ON p.ID = a.secondary_item_id AND p.post_type = 'topic'
     WHERE a.type = 'bbp_topic_create'
    UNION ALL
    SELECT a.id AS activity_id, SUBSTRING(a.type, 10) AS post_type, a.secondary_item_id AS item_id, a.type AS src_type
      FROM {$act} a
      JOIN {$posts} p ON p.ID = a.secondary_item_id AND p.post_type = SUBSTRING(a.type, 10)
     WHERE a.type LIKE 'new_blog_%' AND a.secondary_item_id > 0
";
$rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
echo "Source activities to map: " . count($rows) . "\n";

$up = $db->prepare(
    'INSERT INTO discovery.bb_activity_target (activity_id, post_type, item_id, src_type)
     VALUES (?,?,?,?)
     ON CONFLICT (activity_id)
     DO UPDATE SET post_type = EXCLUDED.post_type,
                   item_id   = EXCLUDED.item_id,
                   src_type  = EXCLUDED.src_type'
);

$n = 0; $skip = 0; $byType = [];
$db->beginTransaction();
try {
    foreach ($rows as $r) {
        $pt = (string) $r['post_type'];
        $id = (int) $r['item_id'];
        if ($pt === '' || $id <= 0) { $skip++; continue; }   // belt-and-suspenders (CHECK enforces too)
        $up->execute([(int) $r['activity_id'], $pt, $id, (string) $r['src_type']]);
        $n++;
        $byType[$pt] = ($byType[$pt] ?? 0) + 1;
    }
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

$total = (int) $db->query("SELECT COUNT(*) FROM discovery.bb_activity_target")->fetchColumn();
echo "Upserted: {$n}  (skipped {$skip})\n";
ksort($byType);
foreach ($byType as $pt => $c) echo "  {$pt}: {$c}\n";
echo "discovery.bb_activity_target total rows: {$total}\n";
