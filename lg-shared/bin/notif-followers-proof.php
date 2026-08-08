<?php
/**
 * notif-followers-proof.php — leg 4 sees the follows it is supposed to see.
 *
 *     sudo -u looth-dev php lg-shared/bin/notif-followers-proof.php
 *
 * notif-bridge lane, 2026-08-08. Covers lg_notify_topic_followers() and its two
 * halves, in BOTH flag states, against real data on this box.
 *
 * ── WHAT IS BEING DEFENDED ──────────────────────────────────────────────────
 * `forums.topic_follow` holds 12 rows on live. `wp_bb_notifications_subscriptions`
 * type='topic' holds 1,515 across 381 members. Leg 4 reads only the first, so 381
 * members follow discussions our bell has never rung for — measured in
 * docs/atlas/NOTIF-BRIDGE-GAP-2026-08-08.md, pair-by-pair on Ian's own account.
 * `bell_follows_bb_subscriptions` unions them, and ships OFF pending Ian's call.
 *
 * ── THE ABSENCE ASSERTION IS PAIRED WITH A LIVENESS ONE ─────────────────────
 * feedback-absence-assertion-needs-liveness: "flag OFF ⇒ no BB followers" is
 * trivially true on a box with an empty table, a wrong table name, or a typo'd
 * column. So every OFF assertion here names a topic whose subscribers are proven to
 * exist by a direct query first. The empty result has to be earned.
 *
 * Mutating phases run inside a MySQL transaction that is always rolled back.
 *
 * Exit 0 = green, 1 = RED, 2 = CANNOT RUN.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$wpLoad = '/var/www/dev/wp-load.php';
if (!is_file($wpLoad)) { fwrite(STDERR, "CANNOT RUN: no wp-load at $wpLoad\n"); exit(2); }
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'dev2.loothgroup.com';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require_once $wpLoad;

require_once __DIR__ . '/../notify-bridge.php';

global $wpdb;

$fail = 0; $did = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $fail, $did;
    $did++;
    if ($cond) { echo "  PASS  $what\n"; return; }
    $fail++;
    echo "  RED   $what" . ($detail !== '' ? "  — $detail" : '') . "\n";
}
function phase(string $t): void { echo "\n=== $t ===\n"; }

$subs = $wpdb->prefix . 'bb_notifications_subscriptions';

// ── Fixture: a real topic with real BuddyBoss subscribers ────────────────────
$topic = (int) $wpdb->get_var(
    "SELECT item_id FROM `{$subs}` WHERE type='topic' AND status=1
      GROUP BY item_id ORDER BY COUNT(*) DESC, item_id DESC LIMIT 1"
);
if ($topic < 1) { fwrite(STDERR, "CANNOT RUN: no type='topic' subscriptions on this box\n"); exit(2); }

$expected = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
    "SELECT user_id FROM `{$subs}` WHERE type='topic' AND item_id=%d AND status=1", $topic
)));
sort($expected);

echo "fixture topic=$topic  BB subscribers=" . count($expected)
   . " [" . implode(',', array_slice($expected, 0, 8)) . (count($expected) > 8 ? ',…' : '') . "]\n";

// ── LIVENESS: the fixture is real, so an empty result below means something ──
phase('PHASE 0 — liveness: the subscribers this topic has really exist');
ok('the fixture topic has at least one status=1 subscriber', count($expected) >= 1);

phase('PHASE 1 — flag OFF: BuddyBoss follows do NOT ring the bell (today)');
$GLOBALS['lg_notify_bridge_config_test'] = ['bell_follows_bb_subscriptions' => false];
ok('the BB half returns nothing', lg_notify_topic_followers_bb($topic) === []);
$offAll = lg_notify_topic_followers($topic);
$native = lg_notify_topic_followers_native($topic);
sort($offAll); sort($native);
ok('and the whole of leg 4 is exactly the native store', $offAll === $native,
   'off=' . json_encode($offAll) . ' native=' . json_encode($native));
// THE GAP, asserted rather than asserted-about: at least one real subscriber is
// invisible to leg 4 as it ships today.
$missed = array_values(array_diff($expected, $offAll));
ok('THE GAP: ' . count($missed) . ' real subscriber(s) get no bell today', count($missed) >= 1);

phase('PHASE 2 — flag ON: they do');
$GLOBALS['lg_notify_bridge_config_test'] = ['bell_follows_bb_subscriptions' => true];
$got = lg_notify_topic_followers_bb($topic);
sort($got);
ok('the BB half returns exactly the status=1 subscribers', $got === $expected,
   'got ' . json_encode(array_slice($got, 0, 8)));

$onAll = lg_notify_topic_followers($topic);
sort($onAll);
$union = array_values(array_unique(array_merge($native, $expected)));
sort($union);
ok('leg 4 is the UNION of both stores', $onAll === $union,
   'on=' . count($onAll) . ' union=' . count($union));
ok('nobody appears twice', count($onAll) === count(array_unique($onAll)));
ok('and it is strictly more than the OFF state', count($onAll) > count($offAll));

// ── Mutating phases, inside a transaction that is always rolled back ─────────
phase('PHASE 3 — status=0 is HONOURED (the 8/8 sweep must not be undone)');
// IAN-RULINGS §5: the group-sub sweep disarmed 9,297 rows with status=0 rather than
// deleting them, so it stays reversible. If this predicate were dropped, flipping
// the bell flag would resurrect every one of them as a bell recipient — the sweep
// undone through a side door. Proven by moving a real row both ways.
$wpdb->query('START TRANSACTION');
try {
    $victimUser = $expected[0];
    $wpdb->query($wpdb->prepare(
        "UPDATE `{$subs}` SET status=0 WHERE type='topic' AND item_id=%d AND user_id=%d",
        $topic, $victimUser
    ));
    $afterOff = lg_notify_topic_followers_bb($topic);
    ok("a status=0 subscriber ($victimUser) is EXCLUDED",
        !in_array($victimUser, $afterOff, true));
    ok('…and the others are untouched', count($afterOff) === count($expected) - 1,
        'got ' . count($afterOff) . ' expected ' . (count($expected) - 1));

    // Red-first control: put it back and it must return. An exclusion test that
    // passes because the lookup is broken would pass this too — this is what
    // separates the two.
    $wpdb->query($wpdb->prepare(
        "UPDATE `{$subs}` SET status=1 WHERE type='topic' AND item_id=%d AND user_id=%d",
        $topic, $victimUser
    ));
    ok('CONTROL: restoring status=1 brings them back',
        in_array($victimUser, lg_notify_topic_followers_bb($topic), true));

    phase('PHASE 4 — a GROUP or FORUM subscription is not a discussion follow');
    // type is the whole scoping rule. lg_fd_items_for() joins type='topic' for the
    // roundup and this must agree, or the bell and the email disagree about what a
    // "follow" is. 1,830 members hold type='group' rows; none of them mean "tell me
    // about replies in topic N".
    $other = (int) $wpdb->get_var("SELECT user_id FROM `{$subs}` WHERE type='group' AND status=1 LIMIT 1");
    if ($other > 0) {
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$subs}` SET item_id=%d WHERE type='group' AND status=1 AND user_id=%d LIMIT 1",
            $topic, $other
        ));
        ok("a type='group' row on the same item_id does NOT ring ($other)",
            !in_array($other, lg_notify_topic_followers_bb($topic), true));
    } else {
        echo "  SKIP  no type='group' row to test with\n";
    }
} catch (Throwable $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    $wpdb->query('ROLLBACK');
}

// Prove the rollback took.
$after = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
    "SELECT user_id FROM `{$subs}` WHERE type='topic' AND item_id=%d AND status=1", $topic
)));
sort($after);
echo "\nrollback check: subscribers before=" . count($expected) . " after=" . count($after) . "\n";
if ($after !== $expected) { echo "RED: the transaction did not roll back cleanly\n"; $fail++; }

unset($GLOBALS['lg_notify_bridge_config_test']);

// ── The shipped state ────────────────────────────────────────────────────────
phase('PHASE 5 — what this box actually ships');
$shipped = lg_notify_bridge_config();
ok('the tracked config has bell_follows_bb_subscriptions OFF',
   empty($shipped['bell_follows_bb_subscriptions']),
   'it is ON — that needs Ian, see platform/config/notify-bridge.php');
ok('and with it off, leg 4 ignores BuddyBoss', lg_notify_topic_followers_bb($topic) === []);

echo "\n" . ($fail ? "RED — $fail of $did assertions failed\n" : "GREEN — all $did assertions passed\n");
exit($fail ? 1 : 0);
