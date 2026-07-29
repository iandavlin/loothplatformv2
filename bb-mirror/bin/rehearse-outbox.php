<?php
/**
 * bb-mirror/bin/rehearse-outbox.php — prove the outbox TIMER, not just the code.
 *
 * The lane that built the outbox found that the thing which actually bites is
 * never the logic: it is that nothing is running it. So this exists to answer
 * "is delivery working on this box, right now?" after any deploy, by driving a
 * real WP change into the queue and letting the systemd timer drain it.
 *
 *   REHEARSE=seed    wp eval-file .../rehearse-outbox.php
 *   REHEARSE=status  wp eval-file .../rehearse-outbox.php
 *   REHEARSE=cleanup wp eval-file .../rehearse-outbox.php
 *
 * seed     creates a throwaway topic, materialises it into the mirror, then
 *          deletes it through the REAL WordPress path (wp_delete_post) with the
 *          FAST PATH BLOCKED — which is exactly the "dispatch lost in flight"
 *          failure the outbox exists for. Rows are left PENDING on purpose.
 * status   shows the queue and whether the mirror has caught up yet.
 * cleanup  removes the fixture from WordPress, the mirror and the outbox.
 *
 * THE FAST PATH IS BLOCKED DELIBERATELY. If it were left on it would usually
 * win on an idle box, deliver the event itself, and the rehearsal would prove
 * nothing about the worker. Blocking it is not cheating — it is the scenario:
 * a non-blocking POST with a 1s timeout that nobody reads the answer to.
 *
 * Containment: one fixture topic, its id banked in a WP option so the phases can
 * find each other. `cleanup` is idempotent and safe to run at any time.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp --path=/var/www/dev eval-file " . __FILE__ . "\n");
    exit(2);
}

// Prefer the deployed tree; fall back to this checkout when rehearsing pre-merge.
$repo = dirname(__DIR__, 2);
if (!function_exists('bb_mirror_outbox_enqueue')) require_once $repo . '/bb-mirror/lib/outbox.php';

global $wpdb;
$TABLE  = bb_mirror_outbox_table();
$OPTION = 'bb_mirror_rehearsal_fixture';
$step   = getenv('REHEARSE') ?: 'status';

function mirror_has_topic(int $id): bool {
    $st = bb_mirror_db()->prepare("SELECT count(*) FROM topic WHERE id = ?");
    $st->execute([$id]);
    return (int) $st->fetchColumn() === 1;
}

// ---------------------------------------------------------------- seed ------
if ($step === 'seed') {
    if (get_option($OPTION)) {
        fwrite(STDERR, "a rehearsal fixture already exists (" . get_option($OPTION) . "); run REHEARSE=cleanup first\n");
        exit(2);
    }
    $forum = 67776;   // "Strings Micro Factory" — present on both sides, dev2
    if (!(int) bb_mirror_db()->query("SELECT count(*) FROM forum WHERE id = $forum")->fetchColumn()) {
        fwrite(STDERR, "parent forum $forum missing from the mirror; pick another\n"); exit(2);
    }

    $now = current_time('mysql');
    $wpdb->insert($wpdb->posts, [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => get_gmt_from_date($now),
        'post_content' => 'ZZ REHEARSAL', 'post_title' => 'ZZ REHEARSAL outbox timer',
        'post_status' => 'publish', 'post_type' => 'topic', 'post_parent' => $forum,
        'post_modified' => $now, 'post_modified_gmt' => get_gmt_from_date($now),
    ]);
    $id = (int) $wpdb->insert_id;
    update_post_meta($id, '_bbp_forum_id', $forum);
    clean_post_cache($id);
    update_option($OPTION, $id, false);
    echo "fixture topic: $id\n";

    // SETUP, not the test: put it in the mirror synchronously so the delete has
    // something to remove. This one delivery is direct and blocking.
    $sid = bb_mirror_outbox_enqueue('topic', $id, 'upsert');
    $srow = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$TABLE` WHERE id = %d", $sid), ARRAY_A);
    $res  = bb_mirror_outbox_deliver($srow);
    bb_mirror_outbox_ack($sid, (bool) $res['ok'], $res['ok'] ? null : $res['error'], 'worker');
    echo "materialised into the mirror: " . ($res['ok'] ? "ok ({$res['seconds']}s)" : "FAILED {$res['error']}") . "\n";
    echo "mirror has the topic: " . (mirror_has_topic($id) ? 'yes' : 'NO') . "\n";
    if (!$res['ok']) { fwrite(STDERR, "seed could not materialise; aborting\n"); exit(2); }

    // ---- THE FAST PATH IS NOW BLOCKED. Every wp_remote_post is intercepted and
    // never leaves the box, so the only thing that can deliver is the worker.
    add_filter('pre_http_request', fn() => new WP_Error('rehearsal', 'fast path blocked on purpose'), 1, 3);

    require_once $repo . '/platform/mu-plugins/bb-mirror-sync.php';
    $mark = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `$TABLE`");

    wp_delete_post($id, true);      // REAL WordPress delete, REAL hooks

    $rows = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT id, kind, object_id, action, status FROM `$TABLE` WHERE id > %d ORDER BY id", $mark), ARRAY_A);
    echo "\nenqueued by the real delete (fast path blocked):\n";
    foreach ($rows as $r) printf("  #%s %s#%s %s [%s]\n", $r['id'], $r['kind'], $r['object_id'], $r['action'], $r['status']);
    echo "\nWP row gone: " . ((int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id)) === 0 ? 'yes' : 'no') . "\n";
    echo "mirror still has it (a GHOST until delivery): " . (mirror_has_topic($id) ? 'YES' : 'no') . "\n";
    echo "\n-> the queue is now the only thing that can heal this. Watch the timer.\n";
    exit(0);
}

// -------------------------------------------------------------- status ------
if ($step === 'status') {
    $id = (int) get_option($OPTION);
    echo "outbox stats: " . json_encode(bb_mirror_outbox_stats()) . "\n";
    $rows = (array) $wpdb->get_results(
        "SELECT id, kind, object_id, action, status, attempts, next_attempt_at, last_error
           FROM `$TABLE` ORDER BY id", ARRAY_A);
    foreach ($rows as $r) {
        printf("  #%-4s %-6s#%-7s %-7s %-10s att=%-3s next=%s  %s\n", $r['id'], $r['kind'], $r['object_id'],
            $r['action'], $r['status'], $r['attempts'], $r['next_attempt_at'], substr((string) $r['last_error'], 0, 60));
    }
    if ($id) {
        echo "\nfixture $id — WP: " . ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $id)) ? 'present' : 'gone')
            . " | mirror: " . (mirror_has_topic($id) ? 'PRESENT (not yet delivered)' : 'gone (delivered)') . "\n";
    }
    $alert = bb_mirror_db()->query("SELECT value FROM sync_state WHERE key = 'outbox_alert'")->fetchColumn();
    echo "sync_state alert: " . ($alert ? $alert : '(none)') . "\n";
    exit(0);
}

// ------------------------------------------------------------- cleanup ------
if ($step === 'cleanup') {
    $id = (int) get_option($OPTION);
    if ($id) bb_mirror_db()->prepare("DELETE FROM topic WHERE id = ?")->execute([$id]);
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_content = 'ZZ REHEARSAL' OR post_title LIKE 'ZZ REHEARSAL%'");
    $wpdb->query("DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL");
    $n = (int) $wpdb->query($wpdb->prepare("DELETE FROM `$TABLE` WHERE object_id = %d", $id));
    delete_option($OPTION);
    try { bb_mirror_db(readonly: false)->prepare("DELETE FROM sync_state WHERE key = 'outbox_alert'")->execute(); }
    catch (Throwable $e) { /* non-fatal */ }
    echo "cleaned: fixture $id, $n outbox row(s), mirror row, alert key\n";
    echo "outbox rows remaining: " . (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`") . "\n";
    exit(0);
}

fwrite(STDERR, "REHEARSE must be seed | status | cleanup\n");
exit(2);
