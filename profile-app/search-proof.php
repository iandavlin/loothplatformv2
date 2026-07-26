<?php
declare(strict_types=1);
/**
 * OFFLINE PROOF for Messaging::searchFor() — runs the WORKTREE class against the dev DB
 * entirely inside one transaction that is ROLLED BACK, so every fixture row vanishes and
 * no real data (thread 367, store ed23219e, anything else) is ever mutated.
 *
 *   sudo -u profile-app php /home/ubuntu/worktrees/messages-search/profile-app/search-proof.php
 */

define('LG_PROFILE_APP_PG_DSN', 'pgsql:host=/var/run/postgresql;dbname=profile_app');

require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Messaging.php';   // pulls worktree Connections + Notifications

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Messaging;

$pg = Db::pg();

$fails = 0;
function ok(bool $cond, string $what): void
{
    global $fails;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $what . "\n";
    if (!$cond) $fails++;
}

// Sanctioned test pair (wp690 / wp1003) + a third real member as the non-participant.
$A = '7cab3bbe-bfda-52f5-9af6-f5cac0cd4b7b';
$B = 'd308f46c-fb18-5b28-8d0e-1b28b37ae4cc';
$C = $pg->query(
    "SELECT uuid FROM users WHERE uuid NOT IN ('$A','$B') ORDER BY id LIMIT 1"
)->fetchColumn();

$needle = 'zanzibarluthier';   // token that exists nowhere in the real corpus
$pre = (int)$pg->query(
    "SELECT count(*) FROM messages WHERE body ILIKE '%$needle%'"
)->fetchColumn();
ok($pre === 0, "needle '$needle' absent from the real corpus before seeding");

$pg->beginTransaction();
try {
    // ── fixtures ─────────────────────────────────────────────────────────────
    $mkThread = function (bool $group) use ($pg): int {
        $st = $pg->prepare('INSERT INTO message_threads (is_group) VALUES (:g) RETURNING id');
        $st->execute([':g' => $group ? 'true' : 'false']);
        return (int)$st->fetchColumn();
    };
    $addRec = function (int $t, string $u, bool $viewDeleted = false) use ($pg): void {
        $pg->prepare('INSERT INTO message_recipients (thread_id, user_uuid, is_deleted) VALUES (:t,:u,:d)')
           ->execute([':t' => $t, ':u' => $u, ':d' => $viewDeleted ? 'true' : 'false']);
    };
    $say = function (int $t, string $sender, string $body, string $kind = 'message', bool $tombstone = false) use ($pg): int {
        $st = $pg->prepare(
            "INSERT INTO messages (thread_id, sender_uuid, body, kind, deleted_at)
             VALUES (:t,:s,:b,:k, CASE WHEN :d THEN now() ELSE NULL END) RETURNING id"
        );
        $st->execute([':t' => $t, ':s' => $sender, ':b' => $body, ':k' => $kind, ':d' => $tombstone ? 1 : 0]);
        return (int)$st->fetchColumn();
    };

    // 1:1 A<->B with the needle; group A+B+C with the needle; assorted traps.
    $dm = $mkThread(false); $addRec($dm, $A); $addRec($dm, $B);
    $dmHit = $say($dm, $B, "hey did you see that $needle bridge saddle?");
    $say($dm, $A, "totally unrelated reply");
    $say($dm, $B, "the $needle one that got DELETED", 'message', true);          // tombstone trap
    $say($dm, $B, "Buck added $needle to the group", 'system');                  // system-line trap
    $say($dm, $A, "literal percent 100% here and an under_score too");           // metachar fixtures
    $longTail = str_repeat('padding words before the match roll on and on ', 8) . "then finally $needle appears near the tail of a very long message body";
    $dmLong = $say($dm, $A, $longTail);                                          // snippet-window fixture

    $gr = $mkThread(true); $addRec($gr, $A); $addRec($gr, $B); $addRec($gr, (string)$C);
    $grHit = $say($gr, $C, "group chatter mentioning $needle once");

    $gone = $mkThread(false); $addRec($gone, $A, true); $addRec($gone, $B);      // A cleared this view
    $say($gone, $B, "needle in a view-deleted thread $needle");

    // ── assertions ───────────────────────────────────────────────────────────
    $rA = Messaging::searchFor($A, $needle);
    $idsA = array_column($rA['hits'], 'message_id');
    ok(in_array($dmHit, $idsA, true) && in_array($grHit, $idsA, true),
       'participant A finds the needle in the 1:1 AND the group');
    ok(count($idsA) === 3 && in_array($dmLong, $idsA, true),
       'A gets exactly 3 hits (dm, long-body dm, group) — tombstone/system/view-deleted all excluded');
    ok($idsA[0] === $grHit, 'newest-first ordering (group hit seeded last comes first)');

    $grRow = null; $dmRow = null; $longRow = null;
    foreach ($rA['hits'] as $h) {
        if ($h['message_id'] === $grHit) $grRow = $h;
        if ($h['message_id'] === $dmHit) $dmRow = $h;
        if ($h['message_id'] === $dmLong) $longRow = $h;
    }
    ok($dmRow && $dmRow['is_group'] === false && $dmRow['label'] !== '' && $dmRow['sender_name'] !== '',
       '1:1 hit carries peer-name label + sender name');
    ok($grRow && $grRow['is_group'] === true, 'group hit flagged is_group');
    ok($longRow && mb_stripos($longRow['snippet'], $needle) !== false && mb_strlen($longRow['snippet']) < mb_strlen($longTail),
       'long-body snippet is windowed AND still contains the match');

    // Non-participant: same term, ZERO hits — asserted at the class (= the API's core).
    $rB = Messaging::searchFor((string)$C, $needle);
    // C is in the group → C legitimately sees ONLY the group hit, never the 1:1 ones.
    $idsC = array_column($rB['hits'], 'message_id');
    ok($idsC === [$grHit], 'group member C sees ONLY the group hit — 1:1 thread rows are structurally unreachable');
    $rNone = Messaging::searchFor('00000000-0000-0000-0000-000000000000', $needle);
    ok($rNone['hits'] === [], 'total stranger gets zero hits for the same term');

    // Tombstone body unfindable even though the seeded row's body still holds the text
    // (prod blanks it; the predicate must not depend on that).
    $rDel = Messaging::searchFor($A, 'that got DELETED');
    ok($rDel['hits'] === [], 'tombstone body is unfindable');
    $rSys = Messaging::searchFor($A, 'added ' . $needle);
    ok($rSys['hits'] === [], 'system line is unfindable');

    // LIKE metacharacters are literal.
    ok(count(Messaging::searchFor($A, '100%')['hits']) === 1, "literal '100%' finds exactly the percent fixture");
    ok(Messaging::searchFor($A, '99%')['hits'] === [], "'99%' matches nothing (percent is not a wildcard)");
    ok(count(Messaging::searchFor($A, 'under_score')['hits']) === 1, "literal underscore matches itself");
    ok(Messaging::searchFor($A, 'under-score')['hits'] === [], 'underscore is not a single-char wildcard');
    ok(Messaging::searchFor($A, '%%')['hits'] === [], "a bare '%%' query returns nothing, not everything");

    // Short-circuit + pagination.
    ok(Messaging::searchFor($A, 'z')['hits'] === [], '1-char query answers empty without scanning');
    $p1 = Messaging::searchFor($A, $needle, 2, 0);
    $p2 = Messaging::searchFor($A, $needle, 2, 2);
    ok(count($p1['hits']) === 2 && $p1['more'] === true && count($p2['hits']) === 1 && $p2['more'] === false,
       'pagination: limit 2 → page1 has 2 + more, page2 has the last hit');
} finally {
    $pg->rollBack();
}

$post = (int)$pg->query("SELECT count(*) FROM messages WHERE body ILIKE '%$needle%'")->fetchColumn();
ok($post === 0, 'rollback left zero fixture rows behind');

echo $fails === 0 ? "\nALL GREEN\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
