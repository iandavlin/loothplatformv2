#!/usr/bin/env php
<?php
/**
 * board-lane-relay.php — carries Ian's board messages to the lanes, and the
 * lanes' replies back to the board.
 *
 * Ian, 2026-08-16: "I would like to be able to interact with the lanes through
 * the workboard." Keeper's ruling the same day approved this loop, with one
 * addition that shapes the whole file: delivery must be idempotent across a
 * crash.
 *
 * ┌─ THE LOOP ───────────────────────────────────────────────────────────────┐
 * │ OUT  docs/board-lanes/<lane>.md  →  lane-say -f  →  a receipt, committed  │
 * │ IN   the devmsg store           →  a snapshot JSON the board renders      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHY ONLY ONE DIRECTION IS COMMITTED. His messages are instructions: they
 * belong in git, actor-stamped and permanent. The lanes' replies already live
 * in sqlite and are high-volume — committing them too would put hundreds of
 * commits a day on main and make the log useless for everything else. So they
 * are snapshotted, exactly as the lane lights and capacity strip already are.
 * It also removes the echo: a reply that is never committed can never look like
 * a new instruction.
 *
 * ── THE FOUR RULES THIS FILE EXISTS TO OBEY ───────────────────────────────
 *
 * 1. DELIVERY IS BY `lane-say -f`, FROM A FILE, NEVER argv. A board message
 *    that reaches a shell is command-substituted before it arrives: it has bitten
 *    twice on this box, once replacing a `redis-cli` recovery command with the
 *    literal word "OK". Ian will paste commands into these threads constantly,
 *    so this is the normal case, not an edge case. Nothing here interpolates
 *    message text into a command line, and the gate asserts it.
 *
 * 2. IDEMPOTENT ACROSS A CRASH. Every attempt is receipted through the fenced
 *    committer, and a message with a `delivered` receipt is never sent again.
 *    A crash between lane-say returning and the receipt landing re-delivers at
 *    most ONCE on the next pass — never loops. The receipt is committed rather
 *    than kept in local state precisely so it outlives this process and its disk.
 *
 * 3. AN UNDELIVERABLE MESSAGE MUST NOT WEDGE THE QUEUE. Failures are receipted
 *    too, counted, and abandoned after MAX_ATTEMPTS. A watermark that advances
 *    only on success is what stopped bb-mirror-reconcile for 11 days and 3,084
 *    runs on ONE poisoned row.
 *
 * 4. A FAILURE IS VISIBLE, NEVER SILENT. `lane-say` exiting non-zero means a
 *    lane did not hear him, and its own header says never to treat that as
 *    cosmetic. The outcome goes into the snapshot, and the board shows NOT
 *    DELIVERED with the reason. Ian must never type into a thread for a dead
 *    lane and have it look sent.
 *
 *   board-lane-relay.php            # one pass
 *   board-lane-relay.php --dry-run  # read, decide, deliver NOTHING, commit NOTHING
 *
 * Exit 0 pass completed (with or without work), 2 internal failure, 3 cannot run.
 */

declare(strict_types=1);

/**
 * The relay reads from a clone of its OWN, never the committer's.
 *
 * The committer does `git reset --hard` at the start of every write. A reader
 * sharing that tree would occasionally parse a file mid-reset and act on it —
 * a race that would be rare, unreproducible, and would show up as a message
 * delivered twice or not at all. Two clones cost a few MB and remove the class.
 */
const CLONE_DIR    = '/home/ubuntu/board-lane-relay-clone';
const COMMITTER    = '/home/ubuntu/loothplatformv2-clean/tools/keeper/board-committer.php';
const LANE_SAY     = '/home/ubuntu/loothplatformv2-clean/tools/lanes/lane-say.sh';
const DEVMSG_DB    = '/var/lib/devmsg/messages.db';
const SNAPSHOT     = '/home/ubuntu/.board-threads.json';
const ACTOR        = 'board-relay';
const MAX_ATTEMPTS = 3;
const REPLY_LIMIT  = 40;     // per lane, newest kept — the board shows a thread, not an archive

function say(string $m): void { fwrite(STDOUT, $m . "\n"); }
function bail(string $m, int $code): void { fwrite(STDERR, 'board-lane-relay: ' . $m . "\n"); exit($code); }

function run(string $cmd, ?string $cwd = null): array
{
    $full = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd : $cmd;
    $out = []; $rc = 0;
    exec($full . ' 2>&1', $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
}

/**
 * A message's id: its POSITION in the file plus a hash of its content.
 *
 * Position alone would be silently wrong the first time anyone hand-edited the
 * file — every later message would inherit a receipt meant for a different one.
 * With the hash, an edited message reads as NEW and is delivered once more,
 * which is the safe direction: a duplicate is an annoyance, a swallowed
 * instruction is the thing this whole system exists to prevent.
 */
function messageId(int $pos, string $when, string $actor, string $text): string
{
    return sprintf('%03d-%s', $pos, substr(sha1($when . '|' . $actor . '|' . $text), 0, 8));
}

/** @return array<int,array{id:string,when:string,actor:string,text:string}> */
function parseMessages(string $file): array
{
    if (!is_readable($file)) { return []; }
    // Split on newlines EXPLICITLY, never \R: without /u it also matches byte
    // 0x85, the third byte of "✅", and halves any line carrying one.
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file)));

    $out = []; $cur = null; $buf = []; $pos = 0;
    $flush = static function () use (&$out, &$cur, &$buf, &$pos): void {
        if ($cur === null) { return; }
        $text = trim(implode("\n", array_map(
            static fn (string $l): string => preg_replace('/^>\s?/', '', $l) ?? $l, $buf)));
        if ($text === '') { return; }
        $pos++;
        // Built key by key, NOT with the array union operator. `$cur` already
        // carries an empty 'text', and `+` keeps the LEFT side's key — so
        // `['id'=>…] + $cur + ['text'=>$text]` silently discarded the body and
        // delivered an EMPTY message, while every count and log line said it
        // had gone out. The gate caught it; nothing else would have.
        $out[] = [
            'id'    => messageId($pos, $cur['when'], $cur['actor'], $text),
            'when'  => $cur['when'],
            'actor' => $cur['actor'],
            'text'  => $text,
        ];
    };
    foreach ($lines as $line) {
        if (str_starts_with($line, '### ')) {
            $flush(); $cur = null; $buf = [];
            $head = trim(substr($line, 4));
            if (preg_match('/^(\S+ \S+)\s*—\s*(.+)$/u', $head, $m)) {
                $cur = ['when' => $m[1], 'actor' => trim($m[2]), 'text' => ''];
            }
            continue;
        }
        if ($cur !== null && str_starts_with($line, '>')) { $buf[] = $line; }
    }
    $flush();
    return $out;
}

/** @return array<string,array{delivered:bool,failures:int,last:?string,when:string}> keyed by message id */
function parseReceipts(string $file): array
{
    if (!is_readable($file)) { return []; }
    $out = [];
    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        if (!str_starts_with(trim($line), '- ')) { continue; }
        $parts = array_map('trim', explode('·', substr(trim($line), 2)));
        if (count($parts) < 3) { continue; }
        [$when, $id, $outcome] = $parts;
        $why = $parts[4] ?? ($parts[3] ?? '');
        if (!isset($out[$id])) { $out[$id] = ['delivered' => false, 'failures' => 0, 'last' => null, 'when' => $when]; }
        if ($outcome === 'delivered') { $out[$id]['delivered'] = true; }
        if ($outcome === 'failed')    { $out[$id]['failures']++; $out[$id]['last'] = $why; }
        $out[$id]['when'] = $when;
    }
    return $out;
}

/** Hand a receipt to the committer. The relay never writes the repo itself. */
function commitReceipt(string $lane, string $id, string $outcome, string $why, bool $dry): array
{
    if ($dry) { return ['ok' => true, 'dry_run' => true]; }
    $payload = json_encode(['intent' => 'lane_receipt', 'actor' => ACTOR,
                            'lane' => $lane, 'id' => $id, 'outcome' => $outcome, 'why' => $why]);
    $p = proc_open(['/usr/bin/php', COMMITTER],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['ok' => false, 'error' => 'could not start the committer']; }
    fwrite($pipes[0], (string) $payload); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return json_decode((string) $out, true) ?: ['ok' => false, 'error' => 'unreadable committer reply'];
}

/**
 * Deliver one message. THE MESSAGE TEXT NEVER BECOMES argv.
 *
 * It is written to a private temp file and handed over with `-f`. lane-say
 * reads it with `$(cat "$MSGFILE")`, so backticks, `$(...)`, quotes and
 * newlines all arrive as typed instead of being executed on the way.
 */
function deliver(string $lane, string $text, bool $dry): array
{
    if (!is_readable(LANE_SAY)) { return ['ok' => false, 'why' => 'lane-say is not deployed at ' . LANE_SAY]; }
    if ($dry) { return ['ok' => true, 'why' => 'dry run — not sent']; }

    $tmp = tempnam(sys_get_temp_dir(), 'blr');
    if ($tmp === false) { return ['ok' => false, 'why' => 'could not create a message file']; }
    @chmod($tmp, 0600);
    file_put_contents($tmp, $text);

    // Only the LANE NAME and the FILE PATH are on this command line, and both
    // are values this process controls. The message is not here at all.
    $r = run(sprintf('%s --quiet %s -f %s',
        escapeshellarg(LANE_SAY), escapeshellarg($lane), escapeshellarg($tmp)));
    @unlink($tmp);

    return ['ok' => $r['rc'] === 0, 'why' => $r['rc'] === 0 ? 'delivered' : (trim($r['out']) ?: 'lane-say exited ' . $r['rc'])];
}

/**
 * The lanes' replies, read from the devmsg store.
 *
 * ATTRIBUTION IS BY THE LANE'S OWN SELF-IDENTIFYING PREFIX — every lane on this
 * box already opens its board posts with "<lane> -> keeper:". That is a
 * convention, not a schema, so anything that does not match is left
 * UNATTRIBUTED rather than guessed at: a reply filed under the wrong lane would
 * be worse than one not shown, because Ian would answer the wrong seat.
 *
 * @return array<string,array<int,array{when:string,text:string}>>
 */
function replies(array $lanes): array
{
    $out = array_fill_keys($lanes, []);
    if (!is_readable(DEVMSG_DB)) { return $out; }
    try {
        // Read-only, and immutable so a reader can never block a lane's write.
        $db = new PDO('sqlite:file:' . DEVMSG_DB . '?mode=ro&immutable=1', null, null,
                      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $db->query('SELECT ts, body FROM messages ORDER BY ts DESC LIMIT 2000');
        foreach ($rows as $row) {
            $body = (string) ($row['body'] ?? '');
            foreach ($lanes as $lane) {
                if (str_starts_with($body, $lane . ' ->')) {
                    if (count($out[$lane]) >= REPLY_LIMIT) { break; }
                    $out[$lane][] = ['when' => gmdate('Y-m-d H:i', (int) $row['ts']),
                                     'text' => $body];
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        // A snapshot that cannot be built must not take the delivery pass with
        // it — the two halves are independent and the outbound half is the one
        // Ian is waiting on.
        return $out;
    }
    foreach ($out as $lane => $rs) { $out[$lane] = array_reverse($rs); }
    return $out;
}

/* ---------------------------------------------------------------------- */

$dry = in_array('--dry-run', array_slice($_SERVER['argv'], 1), true);

if (!is_dir(CLONE_DIR . '/.git')) {
    bail('no clone at ' . CLONE_DIR . ' — create it with: git clone <origin> ' . CLONE_DIR, 3);
}
if (!$dry && !is_readable(COMMITTER)) {
    bail('the committer is not deployed at ' . COMMITTER, 3);
}

$r = run('git fetch -q origin && git reset -q --hard origin/main', CLONE_DIR);
if ($r['rc'] !== 0) { bail('could not sync the clone: ' . $r['out'], 2); }

$dir = CLONE_DIR . '/docs/board-lanes';
$laneFiles = is_dir($dir) ? glob($dir . '/*.md') : [];
$lanes = [];
foreach ((array) $laneFiles as $f) {
    $base = basename((string) $f, '.md');
    if (str_ends_with($base, '.receipts')) { continue; }
    if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $base)) { $lanes[] = $base; }
}

$sent = 0; $failed = 0; $skipped = 0;
$delivery = [];

foreach ($lanes as $lane) {
    $messages = parseMessages($dir . '/' . $lane . '.md');
    $receipts = parseReceipts($dir . '/' . $lane . '.receipts.md');

    foreach ($messages as $m) {
        $r = $receipts[$m['id']] ?? null;
        if ($r !== null && $r['delivered']) { $skipped++; continue; }
        if ($r !== null && $r['failures'] >= MAX_ATTEMPTS) {
            // Abandoned. Recorded so the board can say so, and NOT retried —
            // one undeliverable message must never hold up the ones behind it.
            $delivery[$lane] = ['ok' => false, 'when' => gmdate('Y-m-d H:i'),
                                'why' => 'gave up after ' . MAX_ATTEMPTS . ' attempts — ' . ($r['last'] ?? 'no reason recorded')];
            $skipped++;
            continue;
        }

        $d = deliver($lane, $m['text'], $dry);
        $outcome = $d['ok'] ? 'delivered' : 'failed';
        $d['ok'] ? $sent++ : $failed++;

        // The receipt is committed AFTER the attempt, deliberately. If this
        // process dies in between, the next pass sees no receipt and delivers
        // once more — at most one duplicate, never a loop. The other order
        // would risk a message recorded as delivered that never arrived, which
        // is the failure nobody can see.
        $cr = commitReceipt($lane, $m['id'], $outcome, (string) $d['why'], $dry);
        if (empty($cr['ok'])) {
            say(sprintf('  ! %s %s: receipt did NOT commit (%s) — it may be delivered again next pass',
                $lane, $m['id'], (string) ($cr['error'] ?? $cr['why'] ?? 'no reason')));
        }
        $delivery[$lane] = ['ok' => $d['ok'], 'when' => gmdate('Y-m-d H:i'), 'why' => (string) $d['why']];
        say(sprintf('  %s %s %s — %s', $d['ok'] ? '→' : '✗', $lane, $m['id'], (string) $d['why']));
    }
}

/* The inbound half. Independent of everything above. */
$snapshotLanes = [];
$reps = replies($lanes);
foreach ($lanes as $lane) {
    $snapshotLanes[$lane] = ['replies' => $reps[$lane] ?? [],
                             'delivery' => $delivery[$lane] ?? null];
}
if (!$dry) {
    $tmp = SNAPSHOT . '.tmp';
    file_put_contents($tmp, json_encode(['ts' => time(), 'lanes' => $snapshotLanes], JSON_UNESCAPED_SLASHES));
    // World-readable ON PURPOSE: the board is served by a pool user that is not
    // in the devmsg group and must never be — that group has WRITE, and it
    // would let any PHP on the site send messages as ubuntu. The snapshot is
    // the airlock, so it carries only what the board already displays.
    @chmod($tmp, 0644);
    rename($tmp, SNAPSHOT);   // atomic: the board never reads a half-written file
}

say(sprintf('%s%d lane(s): %d delivered, %d failed, %d already receipted',
    $dry ? '[dry run] ' : '', count($lanes), $sent, $failed, $skipped));
exit(0);
