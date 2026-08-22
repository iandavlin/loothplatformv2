<?php
/**
 * lanes-decisions.php — the third one-verb servant (#202, Ian's rescope 8/22).
 *
 * Ian, verbatim: "I want a button that opens up the decision box that we use
 * here and have it communicate with you. Can we build that ?"
 *
 * This half is READ-ONLY. Its entire vocabulary is "what is Ian being asked
 * right now": it returns the pending questions from keeper's store, each option
 * carrying its own per-day HMAC nonce. It cannot write the store, cannot write
 * the spool, cannot reach GitHub, and cannot answer anything.
 *
 * ⚠️ WHY THE PAGE FETCHES INSTEAD OF BAKING. The renderer could have baked the
 * questions and their nonces into the 5-minute static page. It deliberately
 * does not, and the reason is the whole point of the feature:
 *   · a question posed 30 seconds ago is answerable NOW, not at the next redraw;
 *   · a question Ian already answered IN CHAT is gone from the box the moment
 *     he opens it — first answer wins is visible, not merely enforced;
 *   · a page cached from yesterday still works, because the nonces are minted
 *     here, today, rather than frozen into the HTML.
 * The renderer bakes only the COUNT, as the button's snapshot line.
 *
 * ⚠️ AN EMPTY STORE AND AN UNREADABLE STORE ARE DIFFERENT ANSWERS, and this
 * file is where that distinction is born. "Nothing waits on you" and "I could
 * not look" must never render alike — the lanes page's oldest law. A missing or
 * unreadable store directory is an ERROR here, never an empty list.
 *
 * Standalone on purpose: no wp-load (trap 7 — __DIR__ resolves through the
 * deploy symlink into the repo, where wp-load.php isn't).
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Constants with production defaults, definable FIRST by a harness so gate 77
// can drive this file against a scratch store. Deliberately not env vars: a
// lane-preview fastcgi_param lands in $_SERVER and not getenv(), and a web
// endpoint whose store can be moved by the environment is a worse thing than an
// untestable one (the same call lanes-poke.php made, for the same reason).
defined('LG_DECIDE_STORE') || define('LG_DECIDE_STORE', '/home/ubuntu/.lg-decisions');
defined('LG_DECIDE_ENV')   || define('LG_DECIDE_ENV',   '/etc/looth/env');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') fail(405, 'GET only');

$token = '';
foreach (@file(LG_DECIDE_ENV, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (strpos($line, 'LG_GITHUB_ISSUES_TOKEN=') === 0) {
        $token = trim(substr($line, strlen('LG_GITHUB_ISSUES_TOKEN=')));
        break;
    }
}
if ($token === '') fail(500, 'no token on box');

// The store must EXIST. Its absence means the deploy step was never run, and
// that must be loud — an endpoint that answers "[]" on a box with no store is
// telling Ian nothing is waiting for him when the truth is nobody can tell.
if (!is_dir(LG_DECIDE_STORE) || !is_readable(LG_DECIDE_STORE)) {
    fail(500, 'the question store is not readable on this box');
}
$names = @scandir(LG_DECIDE_STORE);
if ($names === false) fail(500, 'the question store is not readable on this box');

$out = [];
$unreadable = 0;
$day = gmdate('Y-m-d');

foreach ($names as $name) {
    if (substr($name, -5) !== '.json') continue;
    $id = substr($name, 0, -5);
    // An id reaches a filename and a nonce, so it is validated as a name and
    // never as free text — the same discipline lanes-poke.php applies to a seat.
    if (!preg_match('/^[0-9a-z][0-9a-z-]{2,39}$/', $id) || strpos($id, '..') !== false) {
        $unreadable++;
        continue;
    }
    $raw = @file_get_contents(LG_DECIDE_STORE . '/' . $name);
    $q = $raw === false ? null : json_decode($raw, true);
    if (!is_array($q) || !isset($q['question']) || !is_array($q['options'] ?? null)) {
        $unreadable++;
        continue;
    }
    // Answered questions never reach the box. Two sources, and the CLAIM FILE
    // OUTRANKS THE JSON: the claim is written first and the json rewrite can be
    // lost to a crash, so a question whose claim exists has been answered even
    // when its own body still says otherwise. Reading only the json would
    // re-offer a settled question — the exact failure first-answer-wins exists
    // to prevent. This side cannot repair the json (it has no write permission,
    // by design); the CLI self-heals it on keeper's next read.
    if (!empty($q['answered'])) continue;
    if (file_exists(LG_DECIDE_STORE . '/' . $id . '.claim')) continue;

    $opts = [];
    foreach ($q['options'] as $o) {
        $k = (string)($o['key'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,15}$/', $k)) continue;
        $opts[] = [
            'key'         => $k,
            'label'       => (string)($o['label'] ?? $k),
            'description' => (string)($o['description'] ?? ''),
            'recommended' => !empty($o['recommended']),
            // Per-OPTION and per-DAY. The digest is therefore proof that keeper
            // posed THIS option on THIS question today — a browser cannot
            // fabricate an option keeper never offered, which is the rescope's
            // explicit constraint. Only the digest ever leaves the box.
            'nonce'       => hash_hmac('sha256', 'decide:' . $id . ':' . $k . ':' . $day, $token),
        ];
    }
    if (count($opts) < 2) { $unreadable++; continue; }

    $out[] = [
        'id'       => $id,
        'question' => (string)$q['question'],
        'detail'   => (string)($q['detail'] ?? ''),
        'issue'    => isset($q['issue']) ? (int)$q['issue'] : null,
        'created'  => (int)($q['created'] ?? 0),
        'options'  => $opts,
    ];
}

// Newest first, so the freshest thing keeper asked is the first thing he sees.
usort($out, static fn($a, $b) => $b['created'] <=> $a['created']);

// `unreadable` is REPORTED, not swallowed. A store with a corrupt file in it is
// a store that may be hiding a question, and the box says so rather than
// quietly rendering one fewer.
echo json_encode(['ok' => true, 'questions' => $out,
                  'unreadable' => $unreadable]);
