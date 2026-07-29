<?php
/**
 * bb-mirror/bin/test-native-delete-hooks.php — the WP-native delete backstop,
 * proven in the exact scenario it exists for.
 *
 *   sudo -u looth-dev wp eval-file /srv/bb-mirror/bin/test-native-delete-hooks.php
 *
 * WHAT THIS IS FOR. Measured on dev2 2026-07-29: a WP-native delete DOES reach
 * the mirror today, because BuddyBoss bridges deleted_post -> bbp_deleted_reply
 * and on WP 6.9.5 `deleted_post` fires at post.php:3936, one line BEFORE
 * clean_post_cache() at :3938 — so bbp_deleted_reply()'s bbp_is_reply() guard
 * still finds a warm post cache and passes. atlas §7 path 5 says otherwise and
 * is wrong.
 *
 * But that bridge is held up by two lines of WordPress core staying in that
 * order and by the post cache being warm at that instant. Neither is a contract.
 * So this test DELETES THE BRIDGE and asserts our own backstop carries the event
 * regardless — which is the only way to test a backstop. With bbPress's
 * deleted_post handlers removed, an unhooked mirror would hear nothing at all;
 * that is the state that produced 13 ghost replies and 2 ghost topics on live.
 *
 * Containment: every outbound HTTP request is intercepted, fixtures are inserted
 * with $wpdb (never wp_insert_post), the mirror's row counts are asserted
 * unchanged, and every outbox row and WP row this test creates is removed.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . "\n");
    exit(2);
}

// Load OUR outbox + OUR mu-plugin, not whatever is currently deployed. This test
// is run from a worktree before the merge, so /srv/bb-mirror is deliberately not
// the source here.
$repo = dirname(__DIR__, 2);
require_once $repo . '/bb-mirror/lib/outbox.php';

global $wpdb;
$TABLE = bb_mirror_outbox_table();

$GLOBALS['nd_pass'] = 0; $GLOBALS['nd_fail'] = 0;
function check(string $what, $got, $want): void {
    $ok = $got === $want;
    $ok ? $GLOBALS['nd_pass']++ : $GLOBALS['nd_fail']++;
    printf("  [%s] %-50s got %s, want %s\n", $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

function mirror_counts(): array {
    $db = bb_mirror_db();
    return $db->query("SELECT (SELECT count(*) FROM topic) t, (SELECT count(*) FROM reply) r,
                              (SELECT count(*) FROM attachment) a")->fetch();
}

$counts_before = mirror_counts();
$high_water    = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `$TABLE`");
$wp_before     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");

// ---- containment: nothing leaves the box -----------------------------------
add_filter('pre_http_request', fn() => ['headers' => [], 'body' => '{"intercepted":true}',
    'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null], 1, 3);

// ---- REMOVE THE BRIDGE ------------------------------------------------------
// Both the currently-deployed mu-plugin's registrations AND BuddyBoss's own
// deleted_post -> bbp_deleted_* bridge. What remains after this is a WordPress
// in which nothing tells the mirror about a native delete — the failure state.
foreach (['before_delete_post', 'deleted_post', 'bbp_deleted_reply', 'bbp_deleted_topic',
          'bbp_deleted_forum', 'bbp_trashed_reply', 'bbp_trashed_topic'] as $h) {
    remove_all_actions($h);
}

// ---- now load OUR mu-plugin, which registers the backstop ------------------
require_once $repo . '/platform/mu-plugins/bb-mirror-sync.php';

echo "mirror before: " . json_encode($counts_before) . "\n";
echo "outbox high-water: $high_water\n\n";

$real_topic = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='topic' AND post_status='publish' ORDER BY ID DESC LIMIT 1");
$real_forum = (int) get_post_meta($real_topic, '_bbp_forum_id', true);

function fixture(string $type, int $parent, int $forum_id): int {
    global $wpdb;
    $now = current_time('mysql');
    $wpdb->insert($wpdb->posts, [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => get_gmt_from_date($now),
        'post_content' => 'ZZ NATIVEHOOK', 'post_title' => 'ZZ NATIVEHOOK ' . $type,
        'post_status' => 'publish', 'post_type' => $type, 'post_parent' => $parent,
        'post_modified' => $now, 'post_modified_gmt' => get_gmt_from_date($now),
    ]);
    $id = (int) $wpdb->insert_id;
    update_post_meta($id, '_bbp_forum_id', $forum_id);
    if ($type === 'reply') update_post_meta($id, '_bbp_topic_id', $parent);
    clean_post_cache($id);
    return $id;
}

/** The outbox rows this test caused, oldest first.
 *
 * NOTE THE TABLE LOOKUP, it is load-bearing. `wp eval-file` includes this file
 * from inside a METHOD, so everything at "top level" here is function-local —
 * a `global $TABLE` in here would import an UNSET global and interpolate the
 * empty string, querying `FROM ``  `. That fails silently through $wpdb (0 rows,
 * not an exception), so every assertion below would read "got 0, want 1" while
 * the hook under test was working perfectly. Measured 2026-07-29: it cost a full
 * red run. Ask the outbox for its own table name instead of trusting scope. */
function rows_for(int $object_id): array {
    global $wpdb;
    $table = bb_mirror_outbox_table();
    return (array) $wpdb->get_results($wpdb->prepare(
        "SELECT kind, object_id, action, status FROM `$table` WHERE object_id = %d ORDER BY id", $object_id), ARRAY_A);
}

// ============================================================================
echo "1. reply, wp_delete_post() — with bbPress's bridge GONE\n";
// ============================================================================
$r = fixture('reply', $real_topic, $real_forum);
wp_delete_post($r, true);
$rows = rows_for($r);
check('exactly one outbox row recorded', count($rows), 1);
check('  kind',   $rows[0]['kind'] ?? null,   'reply');
check('  action', $rows[0]['action'] ?? null, 'delete');
check('  durable and awaiting delivery', $rows[0]['status'] ?? null, 'pending');
// The point: the type was captured in before_delete_post, while wp_posts still
// had the row. By deleted_post the type is exactly what you cannot look up —
// which is the same cliff bbPress's guard is standing on.
check('WP row really is gone',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $r)), 0);

// ============================================================================
echo "\n2. topic, wp_delete_post() — and the extra event nobody expected\n";
// ============================================================================
// A topic delete records TWO rows, not one, and that is correct rather than a
// bug. Traced on dev2 2026-07-29 with an `all`-hook tracer: bbPress's own
// bbp_delete_topic() cleanup calls bbp_unstick_topic(), which fires the
// `bbp_unstick_topic` action — and this mu-plugin has always mapped that to an
// `upsert`. So the real sequence for ANY topic delete, before this lane and
// after it, is upsert-then-delete.
//
// It converges instead of corrupting, for two reasons that are each asserted
// below rather than assumed:
//   * ORDER IS PRESERVED. The worker replays per object in enqueue order
//     (lib/outbox.php:226), so `delete` is the terminal action.
//   * THE UPSERT IS SELF-CANCELLING ANYWAY. bb_mirror_upsert_topic() begins
//     `if (!$p || $p->post_type !== 'topic')` and issues DELETE FROM topic
//     (lib/materializers.php:346) — by this point the WP post is gone, so the
//     upsert deletes too. Both rows drive the mirror to the same state.
// Asserting `count == 1` here would be asserting a fiction; it is the ordering
// that carries the correctness, so that is what gets asserted.
$t = fixture('topic', $real_forum, $real_forum);
wp_delete_post($t, true);
$rows = rows_for($t);
check('topic delete records two rows (bbp_unstick_topic + ours)', count($rows), 2);
check('  kind',   $rows[0]['kind'] ?? null,   'topic');
check('  bbPress unstick lands first', $rows[0]['action'] ?? null, 'upsert');
check('  and DELETE IS TERMINAL — the ordering guarantee', $rows[1]['action'] ?? null, 'delete');

// ============================================================================
echo "\n3. FORUM delete — the hook that did not exist at all\n";
// ============================================================================
// api/v0/_sync.php has handled ['forum','delete'] since it was written and
// NOTHING IN WORDPRESS HAS EVER SENT ONE. So a deleted forum stayed in the
// mirror forever — and so did every topic and reply beneath it, because the
// Postgres cascade never got a DELETE to fire on.
$f = fixture('forum', 0, 0);
wp_delete_post($f, true);
$rows = rows_for($f);
check('forum delete is now recorded', count($rows), 1);
check('  kind',   $rows[0]['kind'] ?? null,   'forum');
check('  action', $rows[0]['action'] ?? null, 'delete');

// ============================================================================
echo "\n4. NEGATIVE CONTROL — remove OUR backstop too, and the event vanishes\n";
// ============================================================================
// Without this the three assertions above could be passing because of something
// else still listening. With every delete hook gone, a native delete must leave
// no trace whatsoever — which is precisely the pre-fix behaviour.
remove_all_actions('before_delete_post');
remove_all_actions('deleted_post');
$r2 = fixture('reply', $real_topic, $real_forum);
$before_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`");
wp_delete_post($r2, true);
$after_rows  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`");
check('unhooked delete records NOTHING', $after_rows, $before_rows);
check('  and the WP post is still gone — so the mirror would now be a ghost',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $r2)), 0);

// ============================================================================
echo "\n5. the full ledger — every row this test caused, accounted for\n";
// ============================================================================
// Asserting on each object separately cannot see a row enqueued for something
// we never touched. Print and count the lot: three deletes must produce exactly
// three rows, so a double-fire or a stray enqueue shows up as arithmetic.
$ledger = (array) $wpdb->get_results($wpdb->prepare(
    "SELECT id, kind, object_id, action, status FROM `$TABLE` WHERE id > %d ORDER BY id", $high_water), ARRAY_A);
foreach ($ledger as $row) {
    printf("  #%s  %-6s %-8s %s  (%s)\n", $row['id'], $row['kind'], $row['action'], $row['object_id'], $row['status']);
}
// 4, not 3: reply=1, topic=2 (bbp_unstick_topic + ours, see step 2), forum=1.
// Pinning the exact number is the point — it is what would catch our backstop
// double-firing with the bbp_* path if the collapse rule ever stopped working.
check('the ledger is exactly 4 rows, all accounted for', count($ledger), 4);
check('  nothing was enqueued for an object we never touched',
    count(array_filter($ledger, fn($row) => !in_array((int) $row['object_id'], [$r, $t, $f], true))), 0);

// ============================================================================
echo "\n6. cleanup + containment\n";
// ============================================================================
$wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_content = 'ZZ NATIVEHOOK' OR post_title LIKE 'ZZ NATIVEHOOK%'");
$wpdb->query("DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL");
$removed = (int) $wpdb->query($wpdb->prepare("DELETE FROM `$TABLE` WHERE id > %d", $high_water));
echo "  removed $removed outbox test row(s)\n";

check('no ZZ NATIVEHOOK rows left in WP',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content='ZZ NATIVEHOOK' OR post_title LIKE 'ZZ NATIVEHOOK%'"), 0);
check('wp_posts back to its starting count',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"), $wp_before);
check('no outbox test rows left',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$TABLE` WHERE id > %d", $high_water)), 0);
check('mirror row counts unchanged', mirror_counts(), $counts_before);

echo "\n" . str_repeat('=', 64) . "\n";
$p = (int) $GLOBALS['nd_pass']; $f2 = (int) $GLOBALS['nd_fail'];
echo ($f2 === 0 ? "ALL $p CHECKS PASSED" : "$f2 FAILED, $p passed") . "\n";
exit($f2 === 0 ? 0 : 1);
