<?php
/**
 * notif-dismiss-proof.php — DELETE = DISMISS, proven red-first, both flag states.
 *
 *     sudo -u profile-app php profile-app/bin/notif-dismiss-proof.php
 *
 * Lane: notif-bridge, 2026-08-08. Ian's ruling: the bell's × and Clear-all become a
 * dismissal — row kept, hidden from the bell, and the recap counts unread AND
 * undismissed.
 *
 * ── EVERYTHING RUNS INSIDE ONE TRANSACTION THAT IS ALWAYS ROLLED BACK ────────
 * Including the DDL — Postgres has transactional DDL, so phase 5 can drop and
 * recreate a unique index and still leave the database exactly as it found it. No
 * fixture cleanup, no "delete where name like 'test%'", nothing to leak if this
 * script dies halfway. `feedback-mutation-harness-must-snapshot-not-checkout` is the
 * memory behind that choice: a harness that mutates real state and tidies up
 * afterwards is one crash away from being the outage.
 *
 * ── AND EVERY ASSERTION IS BROKEN BEFORE IT IS TRUSTED ──────────────────────
 * `feedback-red-first-that-stays-green` — twice in one weekend a red-first stayed
 * green and the lane believed it. So phase 1 does not merely "test the old
 * behaviour": it DEMANDS that today's deleteAll destroys, and fails loudly if the
 * rows survive. If phase 1 ever passes-as-green-because-nothing-happened, the seeding
 * is broken and every later phase is measuring an empty table.
 *
 * Exit 0 = green, 1 = RED (a real finding), 2 = CANNOT RUN (no verdict) — the
 * three-state convention tools/gates/run-all.sh reads.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Recap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Flags;
use Looth\ProfileApp\Notifications;
use Looth\ProfileApp\Recap;

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$fail = 0;
$did  = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $fail, $did;
    $did++;
    if ($cond) { echo "  PASS  $what\n"; return; }
    $fail++;
    echo "  RED   $what" . ($detail !== '' ? "  — $detail" : '') . "\n";
}
function phase(string $t): void { echo "\n=== $t ===\n"; }

$pg = Db::pg();

// ── Fixtures: a real bridged member, so Recap::forWpIds() can actually be called ──
// The recap is keyed by WP id through wp_user_bridge; asserting against a raw row
// count instead would miss the OUTSTANDING filter entirely, which is the exact blind
// spot that let this class ship (RECAP-NOTIF-BRIDGE-TRACE.md §4).
$row = $pg->query(
    "SELECT u.uuid, b.wp_user_id FROM users u
       JOIN wp_user_bridge b ON b.user_id = u.id
      ORDER BY u.id LIMIT 1"
)->fetch();
if (!$row) { fwrite(STDERR, "CANNOT RUN: no bridged user in profile_app on this box\n"); exit(2); }
$victim   = (string) $row['uuid'];
$victimWp = (int)    $row['wp_user_id'];

$actorRow = $pg->query("SELECT uuid FROM users WHERE uuid <> '$victim' ORDER BY id LIMIT 1")->fetch();
$actor    = $actorRow ? (string) $actorRow['uuid'] : null;

// Does this box carry the migration? Phases 2-5 are meaningless without it, and
// reporting them RED would be reporting a missing environment as a finding
// (trap-gate-exit-code-3-blocks-every-lane).
$hasCol = (bool) $pg->query(
    "SELECT 1 FROM information_schema.columns
      WHERE table_name = 'notifications' AND column_name = 'dismissed_at'"
)->fetchColumn();

echo "victim uuid=$victim wp=$victimWp   migration_applied=" . ($hasCol ? 'yes' : 'NO') . "\n";

/** Seed N distinct hub rows on distinct targets. Returns their ids. */
$seed = function (int $n, int $base = 990001) use ($pg, $victim, $actor): array {
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        Notifications::pushHubEvent(
            $victim, 'forum.reply_to_topic', 'topic', $base + $i,
            '/hub/?topic=proof/row-' . $i, $actor, null
        );
        $ids[] = (int) $pg->query(
            "SELECT id FROM notifications WHERE user_uuid = '$victim'
               AND target_id = " . ($base + $i) . " ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
    }
    return $ids;
};
$countRows = function (string $where = '') use ($pg, $victim): int {
    return (int) $pg->query(
        "SELECT count(*) FROM notifications WHERE user_uuid = '$victim' AND target_id >= 990000 $where"
    )->fetchColumn();
};
/** Recap rows for our seeded targets only — the real read path, filter and all. */
$recapSeeded = function () use ($victimWp): int {
    $r = Recap::forWpIds([$victimWp], 7);
    $n = 0;
    foreach ($r[$victimWp]['notifications'] ?? [] as $x) {
        if ((int) ($x['target_id'] ?? 0) >= 990000) $n++;
    }
    return $n;
};

$pg->beginTransaction();

try {
    // Clear any stale proof rows inside the transaction so a previous crashed run
    // (impossible via rollback, but a hand-run INSERT is not) cannot skew counts.
    $pg->exec("DELETE FROM notifications WHERE user_uuid = '$victim' AND target_id >= 990000");

    // ─────────────────────────────────────────────────────────────────────────
    phase('PHASE 1 — RED-FIRST: today (flag OFF) DESTROYS. This must be true.');
    Flags::forTest('notifications', ['dismiss_instead_of_delete' => false]);
    ok('flag reads OFF', Notifications::dismissEnabled() === false);

    $seed(3);
    ok('seeded 3 rows', $countRows() === 3, 'got ' . $countRows());
    ok('recap sees 3 before', $recapSeeded() === 3, 'got ' . $recapSeeded());
    ok('bell shows 3 before', Notifications::unreadCount($victim) >= 3);

    $gone = Notifications::deleteAll($victim);
    ok('deleteAll reported rows removed', $gone >= 3, "reported $gone");
    // THE destruction assertion. If this ever fails, deleteAll stopped destroying —
    // which is the whole premise of the ruling and must not be assumed.
    ok('DESTROYED: 0 rows survive in the table', $countRows() === 0, 'survivors: ' . $countRows());
    ok('DESTROYED: recap lost them', $recapSeeded() === 0, 'recap still: ' . $recapSeeded());

    if (!$hasCol) {
        echo "\nCANNOT RUN phases 2-5: dismissed_at is absent — apply\n"
           . "profile-app/sql/2026-08-08-notification-dismiss.sql first.\n";
        $pg->rollBack();
        exit($fail ? 1 : 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    phase('PHASE 2 — flag ON: dismiss KEEPS the row and hides it from the bell');
    Flags::forTest('notifications', ['dismiss_instead_of_delete' => true]);
    ok('flag reads ON', Notifications::dismissEnabled() === true);

    $ids = $seed(3);
    ok('seeded 3 rows again', $countRows() === 3, 'got ' . $countRows());

    $one = $ids[0];
    ok('dismiss(one) reports true', Notifications::dismiss($victim, $one) === true);
    ok('dismiss(one) is not idempotent — second call false',
        Notifications::dismiss($victim, $one) === false);
    ok('KEPT: all 3 rows still in the table', $countRows() === 3, 'got ' . $countRows());
    ok('KEPT: the dismissed row carries a timestamp',
        $countRows(" AND dismissed_at IS NOT NULL") === 1);

    $listed = array_filter(
        Notifications::listFor($victim, 100),
        static fn(array $r): bool => ($r['ref']['id'] ?? 0) >= 990000
    );
    ok('HIDDEN: the bell lists 2 of 3', count($listed) === 2, 'listed ' . count($listed));
    ok('HIDDEN: the dismissed id is not among them',
        !in_array($one, array_column($listed, 'id'), true));

    // ─────────────────────────────────────────────────────────────────────────
    phase('PHASE 3 — the ruling: the recap counts unread AND UNDISMISSED');
    ok('recap drops the dismissed one, keeps the other 2', $recapSeeded() === 2,
        'got ' . $recapSeeded());

    $n = Notifications::dismissAll($victim);
    ok('dismissAll reported the remaining rows', $n >= 2, "reported $n");
    ok('KEPT: Clear-all destroyed NOTHING — 3 rows still present', $countRows() === 3,
        'got ' . $countRows());
    ok('KEPT: all 3 now carry dismissed_at', $countRows(" AND dismissed_at IS NOT NULL") === 3);
    ok('HIDDEN: the bell is empty of them',
        count(array_filter(Notifications::listFor($victim, 100),
            static fn(array $r): bool => ($r['ref']['id'] ?? 0) >= 990000)) === 0);
    ok('recap is empty of them', $recapSeeded() === 0, 'got ' . $recapSeeded());
    // ⚠️ The point of the ruling, stated as an assertion: the EVENTS SURVIVE. Today
    // this same sequence leaves nothing behind at all (phase 1 proved it).
    ok('THE WHOLE POINT: the week is still on disk, 3 rows, auditable',
        $countRows() === 3);

    // ─────────────────────────────────────────────────────────────────────────
    phase('PHASE 4 — THE TRAP: a dismissal must not become permanent deafness');
    // A dismissed row is still UNREAD. If it kept arbitrating
    // uq_notifications_target_unread, the next reply to the SAME target would
    // ON CONFLICT DO UPDATE the hidden row and the member would never be told —
    // silently, with the push still returning raised:true. This is the assertion
    // that the index predicate change exists for.
    $target = 990001;
    $before = (int) $pg->query(
        "SELECT count(*) FROM notifications WHERE user_uuid = '$victim' AND target_id = $target"
    )->fetchColumn();
    ok('the target has exactly 1 dismissed row to start', $before === 1, "got $before");

    Notifications::pushHubEvent(
        $victim, 'forum.reply_to_topic', 'topic', $target,
        '/hub/?topic=proof/row-0&reply=2', $actor, null
    );
    $after = (int) $pg->query(
        "SELECT count(*) FROM notifications WHERE user_uuid = '$victim' AND target_id = $target"
    )->fetchColumn();
    ok('a NEW row was raised, not an update of the hidden one', $after === 2, "rows now $after");

    $visible = (int) $pg->query(
        "SELECT count(*) FROM notifications
          WHERE user_uuid = '$victim' AND target_id = $target AND dismissed_at IS NULL"
    )->fetchColumn();
    ok('and it is VISIBLE — the member is told about the new reply', $visible === 1,
        "visible $visible");

    // ─────────────────────────────────────────────────────────────────────────
    phase('PHASE 5 — THE ONE THAT ALREADY BIT: the arbiter must match the INDEX');
    // The first cut of this feature gated the ON CONFLICT predicate on the FLAG,
    // reasoning that flag-OFF emitted the old statements and was therefore safe to
    // deploy before the migration. This phase is what refuted it, on its first run,
    // with SQLSTATE[42P10]. Postgres infers an arbiter whose predicate is IMPLIED BY
    // the ON CONFLICT WHERE — so against a MIGRATED index the old two-term clause
    // matches nothing and every hub push throws. Flag-OFF was the BROKEN state, and
    // it would have taken the bell out the moment Ian ran the SQL on live.
    //
    // Both directions are asserted here, because only proving the working one is how
    // that bug survived being reasoned about in the first place.
    // 5-pre — THE ROLLBACK HAZARD, found by this harness failing here on its first
    // run. Phase 4 legitimately left TWO unread rows on target 990001 (one dismissed,
    // one fresh) — legal under the three-term index, and a UNIQUE VIOLATION under the
    // two-term one. So the migration's DOWN block cannot simply recreate the old
    // index on a database where anyone has ever dismissed anything. That is now
    // spelled out in the DOWN block itself; here it is asserted rather than believed.
    $dupes = (int) $pg->query(
        "SELECT count(*) FROM (
            SELECT 1 FROM notifications
             WHERE target_kind IS NOT NULL AND is_read = false
             GROUP BY user_uuid, type, target_kind, target_id, COALESCE(anchor_id, 0)
            HAVING count(*) > 1) d"
    )->fetchColumn();
    ok('ROLLBACK HAZARD is real: dismissal creates keys the old index rejects',
        $dupes >= 1, "found $dupes duplicate keys — expected at least the one phase 4 made");

    // Clear the proof's own rows so phase 5 measures the arbiter, not phase 4's state.
    $pg->exec("DELETE FROM notifications WHERE user_uuid = '$victim' AND target_id >= 990000");
    $pg->exec("DROP INDEX uq_notifications_target_unread");
    $pg->exec(
        "CREATE UNIQUE INDEX uq_notifications_target_unread
           ON notifications (user_uuid, type, target_kind, target_id, COALESCE(anchor_id, 0))
          WHERE target_kind IS NOT NULL AND is_read = false"
    );

    // Implication runs ONE WAY, and which way is the whole finding:
    //   three-term arbiter (A∧B∧C) ⟹ two-term index (A∧B)   → matches, fine
    //   two-term arbiter  (A∧B)   ⇏ three-term index (A∧B∧C) → 42P10, everything lost
    // So the danger is old code meeting a migrated database — exactly the state the
    // first cut of this feature would have shipped to live.

    // 5a — the safe direction, against the OLD index now in place.
    Notifications::pinSchemaForTest(true);           // three-term SQL, two-term index
    $threw = false;
    try {
        Notifications::pushHubEvent($victim, 'forum.reply_to_topic', 'topic', 990500,
            '/hub/?topic=proof/three-vs-two', $actor, null);
    } catch (Throwable $e) { $threw = true; echo "        (" . $e->getMessage() . ")\n"; }
    ok('three-term arbiter DOES match a two-term index (implication holds)', $threw === false);

    // 5b — schema-matched, the state real code is always in.
    Notifications::pinSchemaForTest(false);          // two-term SQL, two-term index
    $threw = false;
    try {
        Notifications::pushHubEvent($victim, 'forum.reply_to_topic', 'topic', 990501,
            '/hub/?topic=proof/old-index', $actor, null);
    } catch (Throwable $e) { $threw = true; echo "        (" . $e->getMessage() . ")\n"; }
    ok('schema-matched push does NOT throw on a pre-migration index', $threw === false);
    ok('and it raised its row', $countRows(" AND target_id = 990501") === 1);

    // 5c — RED-FIRST, THE ACTUAL DEFECT. Restore the migrated index and hold the
    // old two-term arbiter against it: this MUST throw. If it ever stops throwing,
    // the reason schemaHasDismiss() exists has evaporated and someone will
    // "simplify" it away.
    $pg->exec("DROP INDEX uq_notifications_target_unread");
    $pg->exec(
        "CREATE UNIQUE INDEX uq_notifications_target_unread
           ON notifications (user_uuid, type, target_kind, target_id, COALESCE(anchor_id, 0))
          WHERE target_kind IS NOT NULL AND is_read = false AND dismissed_at IS NULL"
    );
    Notifications::pinSchemaForTest(false);          // two-term SQL, three-term index
    $threw = false; $msg = '';
    try {
        Notifications::pushHubEvent($victim, 'forum.reply_to_topic', 'topic', 990502,
            '/hub/?topic=proof/two-vs-three', $actor, null);
    } catch (Throwable $e) { $threw = true; $msg = $e->getMessage(); }
    ok('RED-FIRST: two-term arbiter vs MIGRATED index THROWS 42P10', $threw === true,
        'it did not throw — the whole reason for schemaHasDismiss() is gone');
    ok('…and it is the no-matching-constraint error, not some other failure',
        str_contains($msg, '42P10') || stripos($msg, 'ON CONFLICT') !== false, $msg);
    Notifications::pinSchemaForTest(null);

    // ─────────────────────────────────────────────────────────────────────────
    phase('PHASE 6 — an UNMIGRATED database never sees the column named');
    // The property that lets the code reach live before Ian runs the SQL. Asserted on
    // the SQL TEXT, because a box that HAS the column cannot demonstrate what happens
    // on one that does not (feedback-absence-assertion-needs-liveness: this is paired
    // with phase 1, which proves the path is live and really deleting).
    Flags::forTest('notifications', ['dismiss_instead_of_delete' => false]);
    Notifications::pinSchemaForTest(false);
    $ref  = new ReflectionMethod(Recap::class, 'outstanding');
    $ref->setAccessible(true);
    $sqlOff = (string) $ref->invoke(null);
    ok('unmigrated recap SQL does not mention dismissed_at',
        stripos($sqlOff, 'dismissed_at') === false, $sqlOff);
    ok('unmigrated recap SQL is the pre-existing predicate, verbatim',
        preg_replace('/\s+/', ' ', trim($sqlOff)) ===
        "((n.type = 'connection_request' AND c.status = 'pending') "
        . "OR (n.type = 'connection_accept' AND c.status = 'accepted' AND n.is_read = false) "
        . "OR (n.connection_id IS NULL AND n.is_read = false))",
        preg_replace('/\s+/', ' ', trim($sqlOff)));

    Notifications::pinSchemaForTest(true);
    $sqlOn = (string) $ref->invoke(null);
    ok('and a MIGRATED database DOES get it (the check above is not vacuous)',
        stripos($sqlOn, 'dismissed_at') !== false, $sqlOn);
    Notifications::pinSchemaForTest(null);

    // A strictly-true read: the string "false" must not arm an unrecallable change.
    Flags::forTest('notifications', ['dismiss_instead_of_delete' => 'false']);
    ok('the STRING "false" reads as OFF, not as truthy', Notifications::dismissEnabled() === false);
    Flags::forTest('notifications', []);
    ok('an absent key reads as OFF', Notifications::dismissEnabled() === false);

    // ⚠️ …WHICH IS EXACTLY WHY THE REAL FILE MUST BE PROVEN TO LOAD. The assertion
    // just above is the hazard stated as a feature: an absent key and a disabled
    // flag are indistinguishable to every caller. So a config that failed to load —
    // wrong path, unreadable by the FPM user, a stray parse error — would present as
    // a permanently-OFF flag that looks perfectly healthy, and flipping it on live
    // would silently do nothing. Same shape as the NULL-source-row trap: "no row"
    // and "a row saying nothing" are opposite states that one falsy check conflates.
    Flags::resetCache();
    $real = Flags::all('notifications');
    ok('THE REAL FILE LOADS (key present, not merely falsy)',
        array_key_exists('dismiss_instead_of_delete', $real),
        'Flags::all() returned ' . json_encode($real)
        . ' — check config/notifications.php is readable by the running user');
    ok('…and __DIR__ resolved it through the deploy symlink',
        is_readable(__DIR__ . '/../config/notifications.php'));
    ok('the shipped default is OFF', $real['dismiss_instead_of_delete'] === false,
        'it is ON — that needs the migration applied first');

} catch (Throwable $e) {
    echo "\nEXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail++;
} finally {
    if ($pg->inTransaction()) $pg->rollBack();
}

// Prove the rollback actually rolled back — a harness that leaves fixtures behind in
// a live table is worse than no harness.
$left = (int) $pg->query(
    "SELECT count(*) FROM notifications WHERE user_uuid = '$victim' AND target_id >= 990000"
)->fetchColumn();
echo "\nrollback check: proof rows remaining = $left (must be 0)\n";
if ($left !== 0) { echo "RED: the transaction did not roll back\n"; $fail++; }

echo "\n" . ($fail ? "RED — $fail of $did assertions failed\n" : "GREEN — all $did assertions passed\n");
exit($fail ? 1 : 0);
