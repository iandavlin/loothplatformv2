<?php
/**
 * lanes-poke.php — the second one-verb servant (#156, Ian approved 8/20).
 *
 * Ian, verbatim: "Can we get a button on the building lane that I can push
 * that alerts the current keeper that an agent has gone idle?" — replacing the
 * copy-paste path he described the same night ("Right now it's got a copy
 * paste thing").
 *
 * Its entire vocabulary is "say so": it queues ONE line naming a seat. It
 * cannot read anything out, cannot touch git, cannot reach GitHub, and cannot
 * write the board itself — the board is sqlite under group `devmsg`, which is
 * ubuntu alone, and the web user is `looth-dev`. So this endpoint queues and a
 * systemd path unit runs the delivery AS ubuntu (the same shape #143 proved
 * for the refresh button, and the same reason: php-fpm's PrivateTmp means a
 * file this process writes in /tmp is visible to nobody else).
 *
 * Guarded by the per-day HMAC nonce the renderer embeds — unforgeable without
 * the GitHub token, which never leaves the box.
 *
 * Standalone on purpose: no wp-load (trap 7 — __DIR__ resolves through the
 * deploy symlink into the repo, where wp-load.php isn't).
 */
declare(strict_types=1);

header('Content-Type: application/json');

// Paths are constants with production defaults, but a harness may define them
// FIRST and drive this file in a sandbox — which is how gate 77 exercises the
// refusals without ever queueing a real poke at keeper. Deliberately not env
// vars: a lane-preview fastcgi_param lands in $_SERVER and not getenv(), and a
// web endpoint whose spool path can be moved by the environment is a worse
// thing than an untestable one.
defined('LG_POKE_SPOOL')  || define('LG_POKE_SPOOL',  '/home/ubuntu/.lanes-poke-request');
defined('LG_POKE_STAMPS') || define('LG_POKE_STAMPS', '/home/ubuntu/.lanes-poke');
defined('LG_POKE_SEATS')  || define('LG_POKE_SEATS',  '/home/ubuntu/worktrees');
const DEBOUNCE = 600;   // Ian's guard: one poke per seat per 10 minutes

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST only');

$seat  = (string)($_POST['seat'] ?? '');
$nonce = (string)($_POST['nonce'] ?? '');
if ($nonce === '') fail(400, 'bad input');

// A seat name reaches a board message and a filename, so it is validated as a
// name and NEVER as free text. `..` is refused explicitly: the charset alone
// would admit "1..", which is a directory that exists.
if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$/', $seat) || strpos($seat, '..') !== false) {
    fail(400, 'not a seat name');
}
if (!is_dir(LG_POKE_SEATS . '/' . $seat)) fail(404, 'no such seat');

$token = '';
foreach (@file('/etc/looth/env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (strpos($line, 'LG_GITHUB_ISSUES_TOKEN=') === 0) {
        $token = trim(substr($line, strlen('LG_GITHUB_ISSUES_TOKEN=')));
        break;
    }
}
if ($token === '') fail(500, 'no token on box');

// Nonce: HMAC(poke:<seat>:<utc-date>, token). Today or yesterday, so a page
// rendered just before midnight still works.
$valid = false;
foreach ([0, 86400] as $back) {
    $expect = hash_hmac('sha256', 'poke:' . $seat . ':' . gmdate('Y-m-d', time() - $back), $token);
    if (hash_equals($expect, $nonce)) { $valid = true; break; }
}
if (!$valid) fail(403, 'stale page — reload and retry');

// The spool and the stamp directory are pre-created (a deploy step a `git pull`
// does not do). Their ABSENCE fails loudly rather than quietly skipping the
// debounce — an un-debounced button is a board flood, and "it seemed to work"
// is how a missing deploy step survives.
if (!is_dir(LG_POKE_STAMPS) || !is_writable(LG_POKE_STAMPS)) fail(500, 'poke spool not installed on this box');
if (!is_file(LG_POKE_SPOOL) || !is_writable(LG_POKE_SPOOL))  fail(500, 'poke spool not installed on this box');

$stamp = LG_POKE_STAMPS . '/' . $seat;
clearstatcache(true, $stamp);
if (is_file($stamp) && (time() - (int)filemtime($stamp)) < DEBOUNCE) {
    $left = (int)ceil((DEBOUNCE - (time() - (int)filemtime($stamp))) / 60);
    fail(429, "keeper has already been told about $seat — give them $left more minute(s)");
}

$fh = @fopen(LG_POKE_SPOOL, 'a');
if ($fh === false) fail(500, 'cannot queue');
if (!flock($fh, LOCK_EX)) { fclose($fh); fail(500, 'cannot queue'); }
fwrite($fh, time() . ' ' . $seat . "\n");
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
@touch($stamp);

echo json_encode(['ok' => true, 'seat' => $seat]);
