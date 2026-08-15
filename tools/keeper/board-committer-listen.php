#!/usr/bin/env php
<?php
/**
 * board-committer-listen.php — the TRANSPORT half of the committer service.
 *
 * Backlog 29, phase 2. The service logic (four fences) lives in
 * board-committer.php; this file is only how the web pool reaches it, and it is
 * deliberately thin. Keeper's ruling was "called over loopback with a shared
 * secret"; this implements the same trust model with a UNIX SOCKET instead,
 * which is strictly stronger — a loopback TCP port is reachable by every user on
 * the box (buck included), a socket with an owner and a mode is reachable by the
 * users its mode names, and nobody else. There is no secret to leak because
 * there is no secret: the filesystem is the credential.
 *
 * HOW IT IS INVOKED. systemd socket activation, Accept=yes — one process per
 * connection, the connection already on stdin/stdout, running as `ubuntu`. So
 * this script never listens, never forks and never runs when nobody is calling.
 * A crash takes one request with it, not the service.
 *
 * ┌─ WHAT THIS ADDS OVER PIPING THE SOCKET STRAIGHT INTO THE COMMITTER ───────┐
 * │ a) A SIZE CAP. The committer reads STDIN to EOF; a client that streams     │
 * │    forever would otherwise be a memory exhaustion bug on a 2-core box that │
 * │    is already at 91% disk.                                                 │
 * │ b) A READ DEADLINE. Accept=yes means a client that connects and never      │
 * │    half-closes pins a process. Bounded here, and again by RuntimeMaxSec.   │
 * │ c) PATH RESOLUTION with a LOUD REFUSAL (see below), which is the part that │
 * │    stops this deploy going stale silently.                                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHY THE BLAST RADIUS IS ACCEPTABLE, stated plainly rather than assumed: the
 * socket is readable by the `looth-dev` pool, and the WHOLE WordPress stack runs
 * as `looth-dev`. So any PHP on that site can reach this socket. That is not a
 * hole to be papered over with a token the same user can read — it is the reason
 * the committer's four fences exist. The worst a compromised WP can do through
 * here is reorder docs/BACKLOG.md or append a quoted note. It cannot write a
 * path outside the fence, cannot commit without naming an actor, and cannot
 * bypass the buck fence.
 */

declare(strict_types=1);

const MAX_BYTES   = 65536;   // an intent is a few hundred bytes; this is 100x slack
const READ_TIMEOUT = 15;     // seconds

/**
 * WHERE THE COMMITTER LIVES — and why this refuses rather than guesses.
 *
 * The committer must run from a STABLE path, which on this box means the serving
 * checkout: it only ever pulls, so it is always some commit of main and never
 * somebody's half-finished branch. A lane worktree is the wrong home — it is
 * deleted when the lane ends, and this service would then 500 with no clue why.
 *
 * BOARD_COMMITTER_BIN exists for the window BEFORE this branch merges, when the
 * serving checkout does not carry the file yet. It is set in
 * /etc/default/board-committer, which says in its own text to delete itself at
 * merge. If the override points into a worktree, this logs that it is running
 * PRE-MERGE — so a stale pointer announces itself in the journal instead of
 * quietly serving last week's fences.
 */
function committer_path(): string
{
    $override = getenv('BOARD_COMMITTER_BIN');
    $stable   = '/home/ubuntu/loothplatformv2-clean/tools/keeper/board-committer.php';

    if ($override !== false && $override !== '') {
        if (str_contains($override, '/worktrees/')) {
            error_log('board-committer: PRE-MERGE — running from a worktree via BOARD_COMMITTER_BIN (' . $override . '). Delete /etc/default/board-committer once this branch is on main.');
        }
        return $override;
    }
    return $stable;
}

function reply(array $p, int $code = 0): void
{
    fwrite(STDOUT, json_encode($p, JSON_UNESCAPED_SLASHES) . "\n");
    exit($code);
}

/* Read the request, bounded in both size and time. */
stream_set_timeout(STDIN, READ_TIMEOUT);
stream_set_blocking(STDIN, true);
$body = '';
while (!feof(STDIN)) {
    $chunk = fread(STDIN, 8192);
    if ($chunk === false || $chunk === '') {
        $meta = stream_get_meta_data(STDIN);
        if (!empty($meta['timed_out'])) { reply(['ok' => false, 'error' => 'request timed out'], 1); }
        break;
    }
    $body .= $chunk;
    if (strlen($body) > MAX_BYTES) { reply(['ok' => false, 'error' => 'request too large'], 1); }
}

/**
 * DRY RUN, and why it is a request field rather than a flag.
 *
 * The committer takes --dry-run on its command line, and a socket has no
 * command line — so without this the transport could only ever perform the
 * REAL thing. That is not a missing convenience: the board's own "would this
 * be refused?" check, and every test of this path, would otherwise have to
 * commit and push to main to find out. This lane learned that by doing it —
 * the first transport proof pushed a live note to main because there was no
 * way to ask for a rehearsal.
 *
 * Read from the request body, never from anywhere the caller cannot see, and
 * passed through as the flag the committer already understands.
 */
$req    = json_decode($body, true);
$dryRun = is_array($req) && !empty($req['dry_run']);

$bin = committer_path();
if (!is_readable($bin)) {
    error_log('board-committer: cannot read the committer at ' . $bin);
    reply(['ok' => false, 'error' => 'the committer is not deployed at ' . $bin], 3);
}

/* Hand the intent to the fences. The committer speaks JSON on stdin/stdout. */
$argv_ = ['/usr/bin/php', $bin];
if ($dryRun) { $argv_[] = '--dry-run'; }

$p = proc_open(
    $argv_,
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($p)) { reply(['ok' => false, 'error' => 'could not start the committer'], 2); }

fwrite($pipes[0], $body);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($p);

if (trim((string) $err) !== '') { error_log('board-committer stderr: ' . trim((string) $err)); }

/* Pass the committer's own answer through untouched — including its refusals,
 * which are the interesting ones and must not be reshaped into a generic error. */
fwrite(STDOUT, (string) $out);
exit($rc);
