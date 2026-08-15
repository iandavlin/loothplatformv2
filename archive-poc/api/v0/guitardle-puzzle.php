<?php
/**
 * archive-poc/api/v0/guitardle-puzzle.php — ONE day's puzzle, for the
 * LOGGED-OUT track only.
 *
 * Backlog 26 (keeper 2026-08-15). Backlog 25 stopped the phrase reaching
 * server-driven MEMBERS, but it could not remove the two static assets --
 * assets/sequence.json and assets/guitardle_phrases.csv -- because the
 * logged-out game still fetches them to draw its board and judge its own guess.
 * Those two files are 285 phrases plus the FIXED order, so today and every
 * future day is computable by anyone, and a member reading them solves in one
 * move for a score the server would then honestly record. Measured: that is
 * ~140 points a week against a real weekly leader on 62 -- permanent first
 * place on a board with claimable spots.
 *
 * This endpoint is what lets those files be deleted. It answers for ONE day and
 * ONE track and nothing else: no sequence, no library, no other day, no other
 * track.
 *
 * ── IT NEVER SERVES THE MEMBER TRACK. READ THIS BEFORE "GENERALISING" IT ────
 * There is deliberately no aud= parameter. Handing out the member track's
 * letters here would restore the exact hole backlog 25 closed, and it would do
 * it through a door that needs no login at all. Members do not need this
 * endpoint: under LG_GUITARDLE_SERVER_PLAY they get a board SHAPE and the
 * positions they have paid for, and their guess is judged server-side.
 *
 * The logged-out phrase in the clear is not a leak: an anonymous player learns
 * it by playing, their result is never recorded (guitardle-score.php rejects
 * uid<=0), and the weekly board reads only guitardle_results. It buys nothing.
 *
 *   GET [?local_date=YYYY-MM-DD] -> { phrase_id, phrase }
 *
 * Runs on the archive-poc pool with no WordPress boot, like guitardle-board.php
 * -- it is on the render path of every logged-out game load.
 */

declare(strict_types=1);
require_once __DIR__ . '/_guitardle-puzzle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

/**
 * Same +/-1 day clamp as the score and board endpoints. Without it this would
 * be a "read me any day you like" oracle, which is the whole thing we are
 * closing -- a client could simply walk the calendar forward.
 */
function lg_gdle_day_date($raw): ?string {
    if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) return null;
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
    $client = strtotime($raw . ' 00:00:00 UTC');
    $server = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
    if ($client === false || $server === false) return null;
    $diff = (int) round(($client - $server) / 86400);
    return ($diff >= -1 && $diff <= 1) ? $raw : null;
}

$day = lg_gdle_day_date($_GET['local_date'] ?? null) ?? gmdate('Y-m-d');

// false = the LOGGED-OUT track. Hardcoded, not derived from input.
$pid    = lg_gdle_phrase_id($day, false);
$phrase = $pid === null ? null : lg_gdle_phrase($pid);

if ($phrase === null) {
    error_log('[lg-guitardle-puzzle] no phrase for ' . $day);
    http_response_code(500);
    echo json_encode(['error' => 'no_puzzle']);
    exit;
}

echo json_encode(['phrase_id' => $pid, 'phrase' => $phrase],
                 JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
