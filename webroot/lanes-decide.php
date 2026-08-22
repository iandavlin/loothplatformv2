<?php
/**
 * lanes-decide.php — the fourth one-verb servant (#202, Ian's rescope 8/22).
 *
 * Its entire vocabulary is "Ian picked this one". It queues ONE line naming a
 * question and the option he chose. It cannot read the board, cannot touch git,
 * cannot reach GitHub, and cannot mark the store answered — the store is
 * ubuntu-owned by design and the web user is looth-dev.
 *
 * ⚠️ IT CANNOT EVEN WRITE THE ANSWER ITSELF, AND THAT IS THE POINT. The board
 * is sqlite under group `devmsg`, which is ubuntu ALONE (measured:
 * `getent group devmsg` → `devmsg:x:1006:ubuntu`; `id looth-dev` →
 * looth-dev,www-data,loothdevs). So this endpoint queues and the systemd path
 * unit runs the delivery AS ubuntu — the same split #143 and #156 proved, and
 * the same reason: php-fpm's PrivateTmp means a file this process writes in
 * /tmp is visible to nobody else.
 *
 * The spool line is deliberately a SECOND VERB on the existing poke spool:
 *
 *     <ts> <seat>              a poke      (#156, unchanged)
 *     <ts> decide <id> <key>   an answer   (this)
 *
 * which costs zero new systemd units on a box whose scar tissue is mostly
 * missing deploy steps (#178's Decision 3). Back-compat is free by
 * construction: the old worker split `<ts> <rest>` and validated `rest` against
 * a name charset that rejects spaces, so a pre-#202 worker ignores a decide
 * line rather than mis-delivering it as a seat name.
 *
 * Guarded by the per-day HMAC nonce that lanes-decisions.php mints — per
 * QUESTION and per OPTION, so it is unforgeable without the GitHub token, which
 * never leaves the box, and cannot be replayed onto a different option.
 *
 * Standalone on purpose: no wp-load (trap 7 — __DIR__ resolves through the
 * deploy symlink into the repo, where wp-load.php isn't).
 */
declare(strict_types=1);

header('Content-Type: application/json');

defined('LG_DECIDE_STORE')  || define('LG_DECIDE_STORE',  '/home/ubuntu/.lg-decisions');
defined('LG_DECIDE_SPOOL')  || define('LG_DECIDE_SPOOL',  '/home/ubuntu/.lanes-poke-request');
defined('LG_DECIDE_STAMPS') || define('LG_DECIDE_STAMPS', '/home/ubuntu/.lanes-poke');
defined('LG_DECIDE_ENV')    || define('LG_DECIDE_ENV',    '/etc/looth/env');
const DUP_WINDOW = 120;   // a double-tap or a retried fetch is not two answers

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST only');

$id    = (string)($_POST['id'] ?? '');
$key   = (string)($_POST['key'] ?? '');
$nonce = (string)($_POST['nonce'] ?? '');
if ($nonce === '') fail(400, 'bad input');

// Both reach a filename and a board message, so both are validated as names and
// never as free text. `..` is refused explicitly: the charset alone would admit
// "d20260822-aa..", and a directory that exists is a directory that can be read.
if (!preg_match('/^[0-9a-z][0-9a-z-]{2,39}$/', $id) || strpos($id, '..') !== false) {
    fail(400, 'not a question id');
}
if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,15}$/', $key)) fail(400, 'not an option key');

$token = '';
foreach (@file(LG_DECIDE_ENV, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (strpos($line, 'LG_GITHUB_ISSUES_TOKEN=') === 0) {
        $token = trim(substr($line, strlen('LG_GITHUB_ISSUES_TOKEN=')));
        break;
    }
}
if ($token === '') fail(500, 'no token on box');

// Nonce: HMAC(decide:<id>:<key>:<utc-date>, token). Today or yesterday, so a
// box opened just before midnight still answers. Binding BOTH the id and the
// key is what stops a digest minted for one option being replayed onto
// another, or onto a different question entirely.
$valid = false;
foreach ([0, 86400] as $back) {
    $expect = hash_hmac('sha256', 'decide:' . $id . ':' . $key . ':'
        . gmdate('Y-m-d', time() - $back), $token);
    if (hash_equals($expect, $nonce)) { $valid = true; break; }
}
if (!$valid) fail(403, 'stale page — reopen the decisions box and retry');

// Server-side re-check before acting. A nonce is a claim about the PAST (keeper
// posed this option, that day); the store is the PRESENT. Both must agree.
$path = LG_DECIDE_STORE . '/' . $id . '.json';
if (!is_dir(LG_DECIDE_STORE)) fail(500, 'the question store is not installed on this box');
$raw = @file_get_contents($path);
$q = $raw === false ? null : json_decode($raw, true);
if (!is_array($q) || !is_array($q['options'] ?? null)) fail(404, 'no such question');

// The claim outranks the json, exactly as on the read side: a question answered
// a moment ago in chat is settled even if its body has not been rewritten yet.
if (!empty($q['answered']) || file_exists(LG_DECIDE_STORE . '/' . $id . '.claim')) {
    fail(409, 'that one has already been answered — the first answer wins');
}
$offered = false;
foreach ($q['options'] as $o) {
    if (($o['key'] ?? null) === $key) { $offered = true; break; }
}
// Belt and braces over the nonce: an option keeper did not pose is refused even
// if a valid digest for it somehow existed.
if (!$offered) fail(409, 'that is not one of the options on this question');

// The spool and the stamp directory are pre-created (a deploy step a `git pull`
// does not do). Their ABSENCE fails loudly rather than quietly reporting
// success — "it seemed to work" is how a missing deploy step survives, and this
// endpoint's ancestor spent two days queueing into a spool nothing drained.
if (!is_dir(LG_DECIDE_STAMPS) || !is_writable(LG_DECIDE_STAMPS)) fail(500, 'decision spool not installed on this box');
if (!is_file(LG_DECIDE_SPOOL) || !is_writable(LG_DECIDE_SPOOL))  fail(500, 'decision spool not installed on this box');

// A duplicate guard, not a debounce: the store is not marked answered until the
// worker runs a second later, so a double-tap or a retried fetch would queue the
// same answer twice and post to the board twice. One answer per question is the
// whole contract.
$stamp = LG_DECIDE_STAMPS . '/decide-' . $id;
clearstatcache(true, $stamp);
if (is_file($stamp) && (time() - (int)filemtime($stamp)) < DUP_WINDOW) {
    fail(429, 'already sent to keeper — this question is settled');
}

$fh = @fopen(LG_DECIDE_SPOOL, 'a');
if ($fh === false) fail(500, 'cannot queue');
if (!flock($fh, LOCK_EX)) { fclose($fh); fail(500, 'cannot queue'); }
fwrite($fh, time() . ' decide ' . $id . ' ' . $key . "\n");
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
@touch($stamp);

echo json_encode(['ok' => true, 'id' => $id, 'key' => $key]);
