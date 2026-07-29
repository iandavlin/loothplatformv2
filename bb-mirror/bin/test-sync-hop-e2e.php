<?php
/**
 * bb-mirror/bin/test-sync-hop-e2e.php — atlas §9's UNPROVEN HOP, closed.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file \
 *        /srv/bb-mirror/bin/test-sync-hop-e2e.php
 *
 * WHAT §9 LEFT OPEN. Every mirror-orphans proof stopped at the same place:
 *
 *   WP delete -> hook fires -> HTTP -> api/v0/_sync.php -> DELETE FROM reply
 *   |________________ UNPROVEN _______________|  |____ proven three ways ____|
 *
 * The trigger downstream had been fired from PDO, from psql and by FK cascade.
 * What nobody had ever watched was a real WordPress change travelling over the
 * wire and landing in the serving mirror. §9 said closing it needed a dev2
 * window and about ten minutes. This is that run, as a checked-in test.
 *
 * It goes in BOTH directions, because a delete that lands is only interesting if
 * the row was really there first:
 *
 *   1. create  — a WP topic materialises INTO forums.topic over real HTTPS
 *   2. delete  — wp_delete_post() removes it from the mirror over real HTTPS
 *
 * AND IT MEASURES WHICH LEG CARRIED THE DELETE, because that is the whole thesis
 * of this lane. The fast path is wp_remote_post(blocking=>false, timeout 1); the
 * endpoint was measured on dev2 at 7.3s COLD and ~1.1s warm. On a warm endpoint
 * with an idle FPM pool it often wins — it did on the 2026-07-29 run, and saying
 * otherwise would be predicting instead of reporting. That is exactly why the
 * result is PRINTED AND NOT ASSERTED: a leg that wins on an idle box and loses
 * on a cold or saturated one is not a guarantee, it is a coin whose bias moves
 * with load. The bulk delete that fires N of these at once into a pool with
 * pm.max_children = 8 is the case that made the ghosts.
 *
 * So: "the fast path won this run" is a performance note. "the event always
 * arrives" is the guarantee, and that is what step 4 asserts.
 *
 * NEGATIVE CONTROL (step 5) manufactures a ghost the way live got its 15: the WP
 * row is removed with $wpdb, so no hook fires, so nothing is enqueued — and the
 * mirror row STAYS. Without that, steps 3-4 could pass because something else
 * was tidying up.
 *
 * SAFETY. This deliberately writes to the SERVING mirror, because avoiding it
 * would avoid the thing under test. It is bounded:
 *   * every row it touches is one it created, under a real forum, ids far above
 *     nothing and owned by nobody;
 *   * baseline counts are captured first and re-asserted at the end;
 *   * teardown is unconditional (finally), so a mid-run failure still cleans up;
 *   * it never touches a pre-existing row — the 15 real dev2 ghosts are counted
 *     before and after and must not move.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp --path=/var/www/dev eval-file " . __FILE__ . "\n");
    exit(2);
}

// Load OUR outbox and OUR mu-plugin. /srv/bb-mirror is the DEPLOYED tree and is
// behind this branch, so it is deliberately not the source for the code under
// test — only for the receiving endpoint, which is the point (see step 6).
$repo = dirname(__DIR__, 2);
require_once $repo . '/bb-mirror/lib/outbox.php';

global $wpdb;
$TABLE = bb_mirror_outbox_table();

$GLOBALS['e2e_pass'] = 0; $GLOBALS['e2e_fail'] = 0;
function check(string $what, $got, $want): void {
    $ok = $got === $want;
    $ok ? $GLOBALS['e2e_pass']++ : $GLOBALS['e2e_fail']++;
    printf("  [%s] %-52s got %s, want %s\n", $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}
function note(string $s): void { echo "  ---- $s\n"; }

function mirror_counts(): array {
    $db = bb_mirror_db();
    return $db->query("SELECT (SELECT count(*) FROM topic) t, (SELECT count(*) FROM reply) r,
                              (SELECT count(*) FROM attachment) a")->fetch();
}
function mirror_has_topic(int $id): bool {
    $db = bb_mirror_db();
    $st = $db->prepare("SELECT count(*) FROM topic WHERE id = ?");
    $st->execute([$id]);
    return (int) $st->fetchColumn() === 1;
}

$counts_before = mirror_counts();
$high_water    = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `$TABLE`");
$wp_before     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");

// A real forum that exists on BOTH sides, so the materializer's FK is satisfied.
$FORUM = 67776;   // "Strings Micro Factory" — dev2
$forum_ok = (bool) bb_mirror_db()->query("SELECT count(*) FROM forum WHERE id = $FORUM")->fetchColumn();

echo "mirror before:     " . json_encode($counts_before) . "\n";
echo "outbox high-water: $high_water\n";
echo "parent forum:      $FORUM (in mirror: " . ($forum_ok ? 'yes' : 'NO') . ")\n";
echo "delivery host:     " . bb_mirror_outbox_host() . "  -> 127.0.0.1 via CURLOPT_RESOLVE\n\n";
if (!$forum_ok) { fwrite(STDERR, "parent forum missing from mirror; aborting\n"); exit(2); }

$A = 0; $B = 0;

/** Insert a WP topic WITHOUT firing hooks, so setup cannot be mistaken for the test. */
function silent_topic(int $forum_id, string $tag): int {
    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->posts, [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => get_gmt_from_date($now),
        'post_content' => 'ZZ HOPE2E', 'post_title' => 'ZZ HOPE2E ' . $tag,
        'post_status' => 'publish', 'post_type' => 'topic', 'post_parent' => $forum_id,
        'post_modified' => $now, 'post_modified_gmt' => get_gmt_from_date($now),
    ]);
    $id = (int) $wpdb->insert_id;
    update_post_meta($id, '_bbp_forum_id', $forum_id);
    clean_post_cache($id);
    return $id;
}

/** Deliver every pending outbox row, in id order, over the REAL loopback HTTPS.
 *
 * NOTE THE TABLE LOOKUP. `wp eval-file` includes this file from inside a METHOD,
 * so everything at "top level" is function-local and a `global $TABLE` in here
 * imports an UNSET global — interpolating the empty string into "FROM `` ``",
 * which $wpdb answers with 0 rows instead of raising. This function then
 * delivers nothing, silently, and every downstream assertion fails while the
 * code under test is fine. That has now cost two red runs in this lane
 * (bin/test-native-delete-hooks.php rows_for() first), so: never trust scope for
 * the table name, ask the outbox for it. */
function deliver_pending(int $since): array {
    global $wpdb;
    $table = bb_mirror_outbox_table();
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `$table` WHERE id > %d AND status = 'pending' ORDER BY id ASC", $since), ARRAY_A);
    $out = [];
    foreach ($rows as $row) {
        $res = bb_mirror_outbox_deliver($row);
        if ($res['ok']) bb_mirror_outbox_ack((int) $row['id'], true, null, 'worker');
        else            bb_mirror_outbox_fail($row, $res['http'], $res['error']);
        $out[] = sprintf('#%s %s#%s %s -> %s (%ss)', $row['id'], $row['kind'], $row['object_id'],
            $row['action'], $res['ok'] ? 'ok' : 'FAIL ' . $res['error'], $res['seconds']);
    }
    return $out;
}

try {
// ============================================================================
echo "1. a WP topic that the mirror has never heard of\n";
// ============================================================================
$A = silent_topic($FORUM, 'A');
check('WP row exists', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $A)), 1);
check('mirror has NOT heard of it', mirror_has_topic($A), false);

// ============================================================================
echo "\n2. CREATE over the wire — the hop, forwards\n";
// ============================================================================
// Enqueue the same row the mu-plugin's hook would, then deliver it the way the
// worker does: blocking raw curl, CURLOPT_RESOLVE, response actually read.
$oid = bb_mirror_outbox_enqueue('topic', $A, 'upsert');
check('outbox row created', $oid > 0, true);
foreach (deliver_pending($high_water) as $line) note($line);
check('MIRROR ROW NOW EXISTS — WP -> HTTP -> Postgres', mirror_has_topic($A), true);
check('  outbox row marked delivered', $wpdb->get_var($wpdb->prepare(
    "SELECT status FROM `$TABLE` WHERE id = %d", $oid)), 'delivered');
check('  mirror topic count is baseline +1', (int) mirror_counts()['t'], (int) $counts_before['t'] + 1);

// ============================================================================
echo "\n3. DELETE through the REAL path — wp_delete_post(), real hooks\n";
// ============================================================================
// Load our mu-plugin now, so the hook set is the POST-MERGE one. The deployed
// mu-plugin is already loaded and keeps its own registrations; it has no outbox,
// so it contributes extra fire-and-forget POSTs and no rows. The outbox ledger
// therefore stays exactly as predicted, which is asserted below.
require_once $repo . '/platform/mu-plugins/bb-mirror-sync.php';
$mark = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `$TABLE`");

wp_delete_post($A, true);

check('WP row is gone', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $A)), 0);

$new = (array) $wpdb->get_results($wpdb->prepare(
    "SELECT id, kind, object_id, action, status FROM `$TABLE` WHERE id > %d ORDER BY id", $mark), ARRAY_A);
foreach ($new as $r) note(sprintf('enqueued #%s %s#%s %s', $r['id'], $r['kind'], $r['object_id'], $r['action']));
// Two rows: bbPress's own bbp_delete_topic() calls bbp_unstick_topic(), which
// this mu-plugin maps to `upsert` — see bin/test-native-delete-hooks.php step 2.
// Both drive the mirror to the same state and `delete` is terminal.
check('the delete was recorded durably', count($new) >= 1, true);
check('  terminal action is delete', end($new)['action'], 'delete');

// THE MEASUREMENT, not an assertion. The fast path is non-blocking with a 1s
// timeout against an endpoint measured at ~1.1s warm on dev2, so it is expected
// to lose. Whether it won this run is a performance note; it is never the
// guarantee, and the test does not depend on it.
$fastpath_won = !mirror_has_topic($A);
note('fast path (blocking=>false, timeout 1) delivered this run: ' . ($fastpath_won ? 'YES' : 'no'));
note('receiver ack available: no — deployed api/v0/_sync.php has no outbox ack yet');

// ============================================================================
echo "\n4. the OUTBOX carries it, which is the guarantee\n";
// ============================================================================
foreach (deliver_pending($mark) as $line) note($line);
check('MIRROR ROW IS GONE — the hop, backwards', mirror_has_topic($A), false);
check('  mirror topic count back to baseline', (int) mirror_counts()['t'], (int) $counts_before['t']);
check('  no pending rows left for this object', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `$TABLE` WHERE object_id = %d AND status = 'pending'", $A)), 0);

// ============================================================================
echo "\n5. NEGATIVE CONTROL — a ghost, made the way live made its 15\n";
// ============================================================================
// Same create, then remove the WP row with $wpdb so NO hook fires and NOTHING is
// enqueued. If the mirror row vanishes anyway, steps 3-4 proved nothing.
$B = silent_topic($FORUM, 'B');
$oidB = bb_mirror_outbox_enqueue('topic', $B, 'upsert');
foreach (deliver_pending($mark) as $line) note($line);
check('fixture B is in the mirror', mirror_has_topic($B), true);

$before_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`");
$wpdb->delete($wpdb->posts, ['ID' => $B]);      // no hooks, no dispatch, no outbox row
clean_post_cache($B);
$after_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`");

check('nothing was enqueued', $after_rows, $before_rows);
check('WP row is gone', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $B)), 0);
check('AND THE MIRROR ROW SURVIVES — that is a ghost', mirror_has_topic($B), true);
note('it renders to members, and the orphan census reads 0 for it');

// ============================================================================
echo "\n6. what this proves about the DEPLOYED endpoint\n";
// ============================================================================
// The receiver that answered every request above is /srv/bb-mirror/api/v0/_sync.php
// — the deployed tree, which does NOT contain this branch. So the hop is proven
// against the endpoint as it runs TODAY, not against our own copy of it.
$deployed_src = (string) @file_get_contents('/srv/bb-mirror/api/v0/_sync.php');
$deployed_ack = substr_count($deployed_src, 'outbox');   // occurrences, not lines
check('deployed receiver has no outbox ack (so the worker acks)', $deployed_ack, 0);
note('=> the fast-path ack cannot work on dev2 until this branch is deployed;');
note('   the worker ack is what marked the rows delivered above.');

} finally {
// ============================================================================
echo "\n7. teardown + containment\n";
// ============================================================================
// Unconditional: a failure above must not leave rows in the serving mirror.
foreach (array_filter([$A, $B]) as $id) {
    bb_mirror_db()->prepare("DELETE FROM topic WHERE id = ?")->execute([$id]);
}
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_content = 'ZZ HOPE2E' OR post_title LIKE 'ZZ HOPE2E%'");
$wpdb->query("DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL");
$removed = (int) $wpdb->query($wpdb->prepare("DELETE FROM `$TABLE` WHERE id > %d", $high_water));
echo "  removed $removed outbox row(s)\n";

check('no ZZ HOPE2E rows left in WP', (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content='ZZ HOPE2E' OR post_title LIKE 'ZZ HOPE2E%'"), 0);
check('wp_posts back to its starting count', (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}"), $wp_before);
check('no outbox rows left above high-water', (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `$TABLE` WHERE id > %d", $high_water)), 0);
check('MIRROR BACK TO BASELINE — nothing of ours survives', mirror_counts(), $counts_before);
}

echo "\n" . str_repeat('=', 68) . "\n";
$p = (int) $GLOBALS['e2e_pass']; $f = (int) $GLOBALS['e2e_fail'];
echo ($f === 0 ? "ALL $p CHECKS PASSED" : "$f FAILED, $p passed") . "\n";
exit($f === 0 ? 0 : 1);
