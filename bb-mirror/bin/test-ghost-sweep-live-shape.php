<?php
/**
 * bb-mirror/bin/test-ghost-sweep-live-shape.php — the reverse pass, run against
 * the REAL mirror database, on a MANUFACTURED ghost, with the real ones shielded.
 *
 *   sudo -u looth-dev wp eval-file /srv/bb-mirror/bin/test-ghost-sweep-live-shape.php
 *
 * bin/test-ghost-sweep.php already proves bb_mirror_sweep_ghosts() thoroughly in
 * a scratch database. This is the other half: the same function, the same real
 * `forums` schema, the real `looth` rows — because a sweep that works on a
 * scratch DB and a sweep that works on the serving mirror are two claims.
 *
 * WHY THE SHIELD, STATED UP FRONT SO IT IS NOT MISTAKEN FOR A FULL SWEEP.
 * dev2's mirror carries 15 REAL pre-existing ghosts (2 topics, 13 replies —
 * measured 2026-07-29, the same cohort shape live has). Running the sweep in
 * apply mode here unshielded would delete 15 rows of real content that nobody
 * authorized me to touch. So the wp_ids_for callback returns the real WordPress
 * ids PLUS those 15, which makes the pre-existing ghosts look present to the
 * sweep and leaves EXACTLY ONE ghost in the database: the one this test made.
 *
 * That the 15 survive is itself an assertion here — it is the blast-radius
 * check. If the sweep took anything it was not pointed at, this fails.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . "\n");
    exit(2);
}
require_once __DIR__ . '/../lib/materializers.php';

global $wpdb;
$db = bb_mirror_db(readonly: false);

$GLOBALS['gs_pass'] = 0; $GLOBALS['gs_fail'] = 0;
function check(string $what, $got, $want): void {
    $ok = $got === $want;
    $ok ? $GLOBALS['gs_pass']++ : $GLOBALS['gs_fail']++;
    printf("  [%s] %-48s got %s, want %s\n", $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

$FIXTURE = 999000001;   // far above any real post id on either box

function counts(PDO $db): array {
    return $db->query("SELECT (SELECT count(*) FROM topic) t, (SELECT count(*) FROM reply) r,
                              (SELECT count(*) FROM attachment) a")->fetch();
}

// Real WP id sets, and the real ghost ids we are shielding.
$wp_ids = [];
foreach (['topic', 'reply'] as $k) {
    $wp_ids[$k] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $k)));
}
$pre_existing = [];
foreach (['topic', 'reply'] as $k) {
    $have = array_flip($wp_ids[$k]);
    $mir  = array_map('intval', (array) $db->query("SELECT id FROM $k")->fetchAll(PDO::FETCH_COLUMN));
    $pre_existing[$k] = array_values(array_filter($mir, fn($id) => !isset($have[$id])));
}
echo "pre-existing REAL ghosts on dev2 — topics: " . count($pre_existing['topic'])
   . " (" . implode(',', $pre_existing['topic']) . "), replies: " . count($pre_existing['reply']) . "\n";

// The shield: real ids + the real ghosts, so only the fixture reads as a ghost.
$shielded = function (string $kind) use ($wp_ids, $pre_existing): array {
    return array_merge($wp_ids[$kind], $pre_existing[$kind]);
};

$before = counts($db);
echo "mirror before: " . json_encode($before) . "\n\n";

// A real parent topic for the fixture, so its FKs are satisfied exactly as a
// member's reply would be.
$parent = $db->query("SELECT id, forum_id FROM topic ORDER BY id DESC LIMIT 1")->fetch();

try {
    // ========================================================================
    echo "1. manufacture a ghost — a mirror reply whose WP post does not exist\n";
    // ========================================================================
    $db->prepare("INSERT INTO reply (id, topic_id, forum_id, content_html, content_text,
                                     author_id, author_name, status, created_at, modified_at, sync_at)
                  VALUES (?,?,?,?,?,?,?,'publish', now(), now(), now())")
       ->execute([$FIXTURE, $parent['id'], $parent['forum_id'],
                  '<p>ZZ GHOST FIXTURE</p>', 'ZZ GHOST FIXTURE', 1, 'ZZ Fixture']);
    check('ghost row present in mirror',
        (int) $db->query("SELECT count(*) FROM reply WHERE id = $FIXTURE")->fetchColumn(), 1);
    check('and it has NO WordPress post',
        (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = $FIXTURE"), 0);
    // This is what makes a ghost worse than an orphan: it renders.
    check('status is publish, so members would SEE it',
        (string) $db->query("SELECT status FROM reply WHERE id = $FIXTURE")->fetchColumn(), 'publish');

    // ========================================================================
    echo "\n2. REPORT-ONLY finds it and deletes nothing\n";
    // ========================================================================
    $rep = bb_mirror_sweep_ghosts($db, $shielded, false);
    check('reply sweep reports, does not act', $rep['reply']['status'], 'report_only');
    check('  found exactly our fixture',       $rep['reply']['ids'], [$FIXTURE]);
    check('  nothing deleted',
        (int) $db->query("SELECT count(*) FROM reply WHERE id = $FIXTURE")->fetchColumn(), 1);

    // ========================================================================
    echo "\n3. APPLY removes it\n";
    // ========================================================================
    $app = bb_mirror_sweep_ghosts($db, $shielded, true);
    check('reply sweep swept',            $app['reply']['status'], 'swept');
    check('  exactly one row',            $app['reply']['ghosts'], 1);
    check('  ghost is gone from mirror',
        (int) $db->query("SELECT count(*) FROM reply WHERE id = $FIXTURE")->fetchColumn(), 0);

    // ========================================================================
    echo "\n4. BLAST RADIUS — the 15 real ghosts were NOT touched\n";
    // ========================================================================
    // The shield told the sweep they exist in WordPress. If any is missing now,
    // the sweep deleted something it was not pointed at.
    $still = 0;
    foreach ($pre_existing['reply'] as $gid) {
        $still += (int) $db->query("SELECT count(*) FROM reply WHERE id = $gid")->fetchColumn();
    }
    check('all 13 real ghost replies survive', $still, count($pre_existing['reply']));
    $still_t = 0;
    foreach ($pre_existing['topic'] as $gid) {
        $still_t += (int) $db->query("SELECT count(*) FROM topic WHERE id = $gid")->fetchColumn();
    }
    check('all real ghost topics survive',     $still_t, count($pre_existing['topic']));

    // ========================================================================
    echo "\n5. NEGATIVE CONTROL — the sweep is what removed it\n";
    // ========================================================================
    // Re-manufacture, run the sweep with apply=false only, and confirm the row
    // is STILL THERE. Without this, step 3 could be passing because something
    // else cleaned up.
    $db->prepare("INSERT INTO reply (id, topic_id, forum_id, content_html, content_text,
                                     author_id, author_name, status, created_at, modified_at, sync_at)
                  VALUES (?,?,?,?,?,?,?,'publish', now(), now(), now())")
       ->execute([$FIXTURE, $parent['id'], $parent['forum_id'],
                  '<p>ZZ GHOST FIXTURE 2</p>', 'ZZ GHOST FIXTURE 2', 1, 'ZZ Fixture']);
    bb_mirror_sweep_ghosts($db, $shielded, false);
    check('report-only leaves the ghost in place',
        (int) $db->query("SELECT count(*) FROM reply WHERE id = $FIXTURE")->fetchColumn(), 1);
    bb_mirror_sweep_ghosts($db, $shielded, true);
    check('  apply then removes it',
        (int) $db->query("SELECT count(*) FROM reply WHERE id = $FIXTURE")->fetchColumn(), 0);

} finally {
    // Belt and braces: never leave a fixture rendering in the forum.
    $db->prepare("DELETE FROM reply WHERE id = ?")->execute([$FIXTURE]);
    $db->prepare("DELETE FROM attachment WHERE parent_kind = 'reply' AND parent_id = ?")->execute([$FIXTURE]);
}

echo "\n6. containment\n";
$after = counts($db);
echo "  mirror after:  " . json_encode($after) . "\n";
check('mirror row counts back to baseline', $after, $before);

echo "\n" . str_repeat('=', 64) . "\n";
$p = (int) $GLOBALS['gs_pass']; $f = (int) $GLOBALS['gs_fail'];
echo ($f === 0 ? "ALL $p CHECKS PASSED" : "$f FAILED, $p passed") . "\n";
exit($f === 0 ? 0 : 1);
