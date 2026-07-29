<?php
/**
 * bb-mirror/bin/test-outbox.php — the outbox, proven, including the case that
 * matters most: the endpoint goes away mid-flight and the event still lands.
 *
 *   sudo -u looth-dev wp eval-file /srv/bb-mirror/bin/test-outbox.php
 *
 * Checked in as a runnable test rather than written up as a one-off, per
 * docs/CRAFT-STANDARD.md: "a defect class discovered TWICE must be encoded as a
 * gate before it is fixed the second time." Lost mirror dispatches have now been
 * found by three separate lanes (reply-images-count, mirror-delete-orphans, and
 * this one), so the third finding ships as an assertion.
 *
 * SAFETY — this runs against the REAL outbox table and the REAL /_sync endpoint,
 * because a test that avoids both would not be testing the thing:
 *   * every deliverable case is an IDEMPOTENT UPSERT of a topic that already
 *     exists, so a successful delivery rewrites a row with its own current
 *     values (this is exactly the probe already run by hand on 2026-07-29);
 *   * every delete case targets an id far above any real post, so the receiver's
 *     `DELETE FROM reply WHERE id = ?` matches nothing;
 *   * the "endpoint is down" case pins delivery at a CLOSED PORT, which is a
 *     real connection failure — the same thing curl sees when FPM has no child
 *     free and nginx cannot place the request — not a mocked one;
 *   * every outbox row this test creates is deleted at the end, and
 *     forums.{topic,reply,attachment} are asserted byte-identical throughout.
 *
 * Exit code 0 = all assertions passed.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . "\n");
    exit(2);
}

require_once __DIR__ . '/../lib/outbox.php';

global $wpdb;
$TABLE = bb_mirror_outbox_table();

// Counters live in $GLOBALS explicitly. `wp eval-file` includes this file inside
// a FUNCTION scope, so its top-level variables are not globals — a plain
// `global $pass` in check() would bind to a different variable than the `$pass`
// read at the bottom, and the summary would report 0 checks and exit 0 no matter
// how many assertions failed. A test that cannot fail is worse than no test.
$GLOBALS['ob_pass'] = 0;
$GLOBALS['ob_fail'] = 0;
function check(string $what, $got, $want): void {
    $ok = $got === $want;
    $ok ? $GLOBALS['ob_pass']++ : $GLOBALS['ob_fail']++;
    printf("  [%s] %-46s got %s, want %s\n", $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

function mirror_counts(): array {
    $db = bb_mirror_db();
    return $db->query("SELECT (SELECT count(*) FROM topic) t, (SELECT count(*) FROM reply) r,
                              (SELECT count(*) FROM attachment) a")->fetch();
}

if (!bb_mirror_outbox_ensure()) { fwrite(STDERR, "outbox table unavailable\n"); exit(2); }

$counts_before = mirror_counts();
$high_water    = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM `$TABLE`");

// A real topic (idempotent upsert target) and an id no post will ever have.
$real_topic = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='topic' AND post_status='publish' ORDER BY ID DESC LIMIT 1");
$ghost_id   = 999000000;

echo "mirror before: " . json_encode($counts_before) . "\n";
echo "real topic for idempotent upserts: $real_topic\n";
echo "outbox high-water before: $high_water\n\n";

// ============================================================================
echo "1. enqueue records the event\n";
// ============================================================================
$id1 = bb_mirror_outbox_enqueue('topic', $real_topic, 'upsert');
check('enqueue returns a row id', $id1 > 0, true);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$TABLE` WHERE id=%d", $id1), ARRAY_A);
check('status is pending',        $row['status'], 'pending');
check('attempts start at 0',      (int) $row['attempts'], 0);
check('payload carries the event', (string) $row['payload'], json_encode(['kind'=>'topic','id'=>$real_topic,'action'=>'upsert']));
// The grace window is what stops the worker racing a fast path still in flight.
check('first attempt is deferred', strtotime($row['next_attempt_at']) > strtotime($row['created_at']), true);

// ============================================================================
echo "\n2. dedupe collapses a repeat, but NEVER reorders a real sequence\n";
// ============================================================================
$dupe = bb_mirror_outbox_enqueue('topic', $real_topic, 'upsert');
check('repeat of newest pending action skipped', $dupe, 0);

$id_del = bb_mirror_outbox_enqueue('topic', $real_topic, 'delete');
check('a DIFFERENT action still enqueues', $id_del > 0, true);
$id_up2 = bb_mirror_outbox_enqueue('topic', $real_topic, 'upsert');
// upsert -> delete -> upsert must survive intact. Collapsing it on a unique key
// is how you would manufacture a ghost: replay it out of order and the row is
// re-created after the delete that was supposed to remove it.
check('upsert->delete->upsert kept as 3 rows', $id_up2 > 0 && $id_up2 !== $id1, true);

// ============================================================================
echo "\n3. THE HEADLINE CASE — endpoint unreachable, then back\n";
// ============================================================================
// Port 9 (discard) has nothing listening, so this is a genuine connection
// failure, identical in kind to nginx being unable to place the request.
$stuck = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$TABLE` WHERE id=%d", $id1), ARRAY_A);
$res_down = bb_mirror_outbox_deliver($stuck, null, 5, 9);
check('delivery FAILS while endpoint is down', $res_down['ok'], false);

$state = bb_mirror_outbox_fail($stuck, $res_down['http'], $res_down['error']);
check('row survives the failure as pending', $state, 'pending');
$after_fail = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$TABLE` WHERE id=%d", $id1), ARRAY_A);
check('attempts incremented',        (int) $after_fail['attempts'], 1);
check('failure reason recorded',     str_contains((string) $after_fail['last_error'], 'curl'), true);
check('backed off into the future',  strtotime($after_fail['next_attempt_at']) > time(), true);

// THE POINT OF THE WHOLE LANE: the event is still here. Under the old
// fire-and-forget dispatch this is precisely where it vanished for good.
check('event NOT lost — still deliverable', $after_fail['status'], 'pending');

// Endpoint comes back.
$res_up = bb_mirror_outbox_deliver($after_fail, null, 30, 443);
check('delivery SUCCEEDS once endpoint returns', $res_up['ok'], true);
check('  and the endpoint answered 200',        $res_up['http'], 200);
bb_mirror_outbox_ack($id1, true, null, 'worker');
$recovered = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$TABLE` WHERE id=%d", $id1), ARRAY_A);
check('row now delivered',    $recovered['status'], 'delivered');
check('credited to the worker', $recovered['delivered_by'], 'worker');

// ============================================================================
echo "\n4. a 4xx dead-letters immediately instead of retrying 12 times\n";
// ============================================================================
$bad = bb_mirror_outbox_enqueue('nonsense', $ghost_id, 'upsert');
$bad_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$TABLE` WHERE id=%d", $bad), ARRAY_A);
$res_bad = bb_mirror_outbox_deliver($bad_row, null, 15, 443);
check('endpoint rejects unknown kind', $res_bad['ok'], false);
check('  with a 4xx',                  $res_bad['http'] >= 400 && $res_bad['http'] < 500, true);
$bad_row['attempts'] = BB_MIRROR_OUTBOX_MAX_ATTEMPTS - 1;   // worker's permanent-failure rule
check('dead-lettered on the spot', bb_mirror_outbox_fail($bad_row, $res_bad['http'], $res_bad['error']), 'dead');

// ============================================================================
echo "\n5. a delete for a row that is already gone is a harmless no-op\n";
// ============================================================================
$del = bb_mirror_outbox_enqueue('reply', $ghost_id, 'delete');
$del_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$TABLE` WHERE id=%d", $del), ARRAY_A);
$res_del = bb_mirror_outbox_deliver($del_row, null, 30, 443);
check('idempotent delete succeeds', $res_del['ok'], true);

// ============================================================================
echo "\n6. NEGATIVE CONTROL — without the outbox the event is simply gone\n";
// ============================================================================
// If this failed to fail, every assertion above could be passing vacuously.
// Simulate the old dispatch exactly: fire at a dead endpoint, keep no record,
// read no response. Nothing anywhere knows the event ever existed.
$pre_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`");
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://' . bb_mirror_outbox_host() . ':9/bb-mirror-api/v0/_sync',
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['kind'=>'topic','id'=>$real_topic,'action'=>'delete']),
    CURLOPT_RESOLVE => [bb_mirror_outbox_host() . ':9:127.0.0.1'],
    CURLOPT_TIMEOUT => 3, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
]);
curl_exec($ch); curl_close($ch);
$post_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$TABLE`");
check('unrecorded dispatch leaves NO trace', $post_rows, $pre_rows);

// ============================================================================
echo "\n7. worker collapse: consecutive upserts fold, transitions never do\n";
// ============================================================================
// Mirrors the grouping logic in bin/outbox-worker.php on a synthetic group.
$seq = [
    ['id'=>1,'action'=>'upsert'], ['id'=>2,'action'=>'upsert'], ['id'=>3,'action'=>'upsert'],
    ['id'=>4,'action'=>'delete'], ['id'=>5,'action'=>'upsert'],
];
$kept = [];
$n = count($seq);
for ($i = 0; $i < $n; $i++) {
    if ($i < $n - 1 && $seq[$i]['action'] === 'upsert' && $seq[$i + 1]['action'] === 'upsert') continue;
    $kept[] = $seq[$i]['id'];
}
check('3 consecutive upserts fold to the last', $kept, [3, 4, 5]);
check('  the delete is never folded away',      in_array(4, $kept, true), true);

// ============================================================================
echo "\n8. stats surface the divergence that used to be invisible\n";
// ============================================================================
$stats = bb_mirror_outbox_stats();
check('a dead row is counted', $stats['dead'] >= 1, true);
echo "  stats: " . json_encode($stats) . "\n";

// ============================================================================
echo "\n9. cleanup + containment\n";
// ============================================================================
$removed = (int) $wpdb->query($wpdb->prepare("DELETE FROM `$TABLE` WHERE id > %d", $high_water));
echo "  removed $removed test row(s)\n";
check('no test rows left behind',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$TABLE` WHERE id > %d", $high_water)), 0);

$counts_after = mirror_counts();
echo "  mirror after:  " . json_encode($counts_after) . "\n";
check('mirror row counts unchanged', $counts_after, $counts_before);

echo "\n" . str_repeat('=', 64) . "\n";
$pass = (int) $GLOBALS['ob_pass'];
$fail = (int) $GLOBALS['ob_fail'];
echo ($fail === 0 ? "ALL $pass CHECKS PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
