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
 * ── IT TAKES NO PARAMETERS AT ALL. THIS IS THE POINT (keeper 2026-08-15) ────
 * Not a date, not an index, not an audience. The SERVER'S OWN CLOCK picks the
 * day. An earlier draft accepted ?local_date with the same +/-1 clamp the score
 * API uses, which felt consistent -- but a read-only endpoint that answers for a
 * day you name is the answer key rebuilt on a delay, one query at a time. There
 * is no window small enough to make that safe, so there is no window.
 *
 * COST, stated rather than hidden: the logged-out day now turns over at the
 * server's midnight instead of the player's. For a UK-centred audience those
 * are the same hour; for a US player the puzzle changes in the evening. If that
 * ever matters, the fix is a site-timezone constant HERE -- never a parameter.
 *
 *   GET -> { phrase_id, phrase }
 *
 * Runs on the archive-poc pool with no WordPress boot, like guitardle-board.php
 * -- it is on the render path of every logged-out game load.
 */

declare(strict_types=1);
require_once __DIR__ . '/_guitardle-puzzle.php';

header('Content-Type: application/json; charset=utf-8');
// MUST NOT survive the day boundary. On live this sits behind Cloudflare, and
// an unauthenticated GET that caches across midnight either serves yesterday's
// phrase to everyone or pre-bakes today's for anyone who asks early. no-store
// rather than an expiry pinned to midnight: an edge that mis-rounds a pinned
// expiry by a minute is the same bug, and this response is one small row on a
// path that is already doing a DB-free file read.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('CDN-Cache-Control: no-store');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// The server's clock, and nothing else. Deliberately not derived from input:
// there is no request shape that can ask this endpoint about another day.
$day = gmdate('Y-m-d');

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
