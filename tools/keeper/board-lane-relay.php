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
function commitReceipt(string $lane, string $id, string $outcome, string $why, bool $dry, bool $probe = false): array
{
    if ($dry) { return ['ok' => true, 'dry_run' => true]; }
    $payload = json_encode(['intent' => 'lane_receipt', 'actor' => ACTOR,
                            'lane' => $lane, 'id' => $id, 'outcome' => $outcome, 'why' => $why]);

    /**
     * THE PROBE MUST PASS --dry-run ON THE COMMAND LINE, not in the body.
     *
     * `dry_run` in the JSON is the LISTENER's contract — it translates that key
     * into this flag. This function calls the committer DIRECTLY, so a body key
     * means nothing here and the probe committed for real: a `preflight`
     * receipt file, pushed to main, on every single pass. At the 30s cadence
     * proposed for this relay that is roughly 2,880 junk commits a day. Caught
     * by looking at what the sandbox actually contained rather than at what the
     * relay reported.
     */
    $argv_ = ['/usr/bin/php', COMMITTER];
    if ($probe) { $argv_[] = '--dry-run'; }

    $p = proc_open($argv_,
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

/**
 * PREFLIGHT: PROVE A RECEIPT CAN BE WRITTEN BEFORE DELIVERING ANYTHING.
 *
 * Found by driving the real socket rather than the gate's fake one, which is
 * the whole argument for doing that: the deployed committer did not yet allow
 * `lane_receipt`, and this relay delivers first and receipts second. A receipt
 * that can NEVER commit means the message has no record, so the next pass
 * delivers it again — and the next, and the next. Keeper's requirement is "at
 * worst re-deliver ONCE, never loop", and without this check a committer that
 * refuses receipts turns every message into an unbounded repeat.
 *
 * So the capability is checked ONCE, up front, with a dry run that commits
 * nothing. If receipts are not available this delivers NOTHING AT ALL — an
 * undelivered message is recoverable, a lane spammed forever is not.
 */
if (!$dry) {
    $pf = commitReceipt('preflight', '000-00000000', 'delivered', 'capability probe', false, true);
    if (empty($pf['ok'])) {
        bail('the committer will not accept a receipt (' . (string) ($pf['why'] ?? $pf['error'] ?? 'no reason')
           . ') — refusing to deliver anything, because a message that cannot be receipted '
           . 'is a message that repeats on every pass', 3);
    }
}

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
            // STOP THE PASS. One un-receipted delivery re-sends once on the next
            // pass, which is the accepted worst case. Carrying on would leave a
            // second, a third and a fourth in the same state, and a receipt
            // store that has started refusing is unlikely to accept the next
            // one — that is how "at worst once" becomes "forever".
            say(sprintf('  ! %s %s: receipt did NOT commit (%s) — STOPPING this pass so it cannot compound',
                $lane, $m['id'], (string) ($cr['error'] ?? $cr['why'] ?? 'no reason')));
            $delivery[$lane] = ['ok' => false, 'when' => gmdate('Y-m-d H:i'),
                                'why' => 'delivered but not receipted — the pass was stopped'];
            break 2;
        }
        $delivery[$lane] = ['ok' => $d['ok'], 'when' => gmdate('Y-m-d H:i'), 'why' => (string) $d['why']];
        say(sprintf('  %s %s %s — %s', $d['ok'] ? '→' : '✗', $lane, $m['id'], (string) $d['why']));
    }
}

/**
 * DESK RETIREMENT, MECHANICAL — backlog 41(b), Ian: *"completed work still
 * listed on my desk."*
 *
 * An item leaves the desk when one of three things is TRUE, never when somebody
 * remembers to remove it:
 *
 *   1. its seat's DECISION HAS BEEN ANSWERED after the post was made — the ask
 *      was "decide this" and it is decided;
 *   2. the seat's BRANCH HAS MERGED after the post — the work behind the ask has
 *      landed in a train;
 *   3. it was explicitly DISMISSED (committed, so it is a fact with an author).
 *
 * Each is a fact already in a store. Nothing here reads a status somebody typed,
 * which is the whole point: backlog 18 sat a day as UNOWNED because the only
 * thing that could move it was a person noticing.
 *
 * A RETIRED ITEM IS NOT DELETED — it is marked, and the snapshot carries it so
 * the history view can show what has been dealt with. Deleting would make the
 * desk a place where things vanish, which is how a person stops trusting it.
 *
 * @return array{live:array,retired:array}
 */
function retireDeskItems(array $items): array
{
    /* (3) dismissed, from the committed store */
    $dismissed = [];
    $df = CLONE_DIR . '/docs/board-desk/dismissed.md';
    if (is_readable($df)) {
        foreach (explode("\n", (string) file_get_contents($df)) as $l) {
            if (preg_match('/^- ([a-f0-9]{8,64}) /', $l, $m)) { $dismissed[$m[1]] = true; }
        }
    }

    /* (1) answered decisions, with WHEN they were answered */
    $answered = [];
    $dd = CLONE_DIR . '/docs/board-decisions';
    foreach ((array) glob($dd . '/*.md') as $f) {
        $raw = (string) file_get_contents((string) $f);
        if (preg_match('/^#### answered (\S+ \S+)/m', $raw, $m)) {
            $answered[basename((string) $f, '.md')] = strtotime($m[1] . ' UTC') ?: 0;
        }
    }

    /* (2) merged branches, with the merge time */
    $merged = [];
    $bd = CLONE_DIR . '/docs/board-branches';
    foreach ((array) glob($bd . '/*.md') as $f) {
        foreach (explode("\n", (string) file_get_contents((string) $f)) as $l) {
            if (!preg_match('/^- (\S+) — /u', $l, $m)) { continue; }
            $b = $m[1];
            if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,80}$#', $b) || str_contains($b, '..')) { continue; }
            $ref = 'origin/' . $b;
            if (run('git merge-base --is-ancestor ' . escapeshellarg($ref) . ' origin/main', CLONE_DIR)['rc'] !== 0) { continue; }
            $when = (int) trim(run('git log -1 --format=%ct ' . escapeshellarg($ref), CLONE_DIR)['out']);
            $merged[$b] = $when;
        }
    }

    $live = []; $retired = [];
    foreach ($items as $d) {
        $key  = substr(hash('sha256', ($d['who'] ?? '') . '|' . ($d['when'] ?? '') . '|' . ($d['text'] ?? '')), 0, 16);
        $d['key'] = $key;
        $posted = strtotime(((string) ($d['when'] ?? '')) . ' UTC') ?: 0;
        $why = null;

        if (isset($dismissed[$key])) { $why = 'dismissed'; }
        elseif (isset($answered[$d['who'] ?? '']) && $answered[$d['who']] >= $posted) { $why = 'decision answered'; }
        else {
            foreach ($merged as $b => $when) {
                // The branch must belong to this seat AND have landed after the
                // ask, or an old merge would retire a fresh question.
                if (str_contains($b, (string) ($d['who'] ?? '~none~')) && $when >= $posted) { $why = 'work landed (' . $b . ')'; break; }
            }
        }

        if ($why === null) { $live[] = $d; } else { $d['retired'] = $why; $retired[] = $d; }
    }
    return ['live' => $live, 'retired' => $retired];
}

/**
 * IAN'S DESK, DERIVED — Ian, 2026-08-16: *"are you hand populating my desk? Is
 * there a way to do it mechanically?"*
 *
 * Lanes already address him directly ("<lane> -> Ian: …") and those posts were
 * reaching nobody: keeper hand-copied them onto the desk, and two of
 * featured-members' went missing the same day simply because that hand lagged.
 * The desk should BE the store.
 *
 * THE BOARD CANNOT READ THE MESSAGE STORE ITSELF — it is served by a pool user
 * that is not in the `devmsg` group and must not be, because that group has
 * WRITE and would let any PHP on the site send messages as ubuntu. So the desk
 * items come through the same airlock the replies already use: this snapshot.
 * Adding them here rather than building a second reader keeps one process
 * holding the devmsg privilege instead of two.
 *
 * @return array<int,array{when:string,who:string,text:string}> newest last
 */
function deskItems(): array
{
    if (!is_readable(DEVMSG_DB)) { return []; }
    $out = [];
    try {
        $db = new PDO('sqlite:file:' . DEVMSG_DB . '?mode=ro&immutable=1', null, null,
                      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $db->query('SELECT ts, body FROM messages ORDER BY ts DESC LIMIT 2000');
        foreach ($rows as $row) {
            $body = (string) ($row['body'] ?? '');
            // "<lane> -> Ian: …", case-insensitive on the name only. Anything
            // addressed elsewhere is NOT a desk item — a post to keeper that
            // merely mentions Ian is not something waiting on him, and putting
            // it on his desk would make the desk noise he learns to skim.
            if (!preg_match('/^([a-z][a-z0-9-]{1,30})\s*->\s*ian\b\s*:?\s*(.*)$/is', $body, $m)) { continue; }
            if (count($out) >= 30) { break; }
            $out[] = ['when' => gmdate('Y-m-d H:i', (int) $row['ts']),
                      'who'  => $m[1],
                      'text' => trim($m[2]) !== '' ? trim($m[2]) : $body];
        }
    } catch (Throwable $e) {
        return [];
    }
    return array_reverse($out);
}

/**
 * BRANCH STATE FOR THE CARDS — backlog 39, Ian: "So I can track branches better."
 *
 * The card→branch LINK is committed; the branch's STATE is derived here, every
 * pass. Whether a branch still exists and whether it has merged change without
 * anyone editing a file, so a state recorded at write time would be a fact that
 * rots — and a stale badge is worse than no badge, because it is trusted.
 *
 * The BOARD CANNOT COMPUTE THIS ITSELF and must not learn how: it runs no
 * commands at all, which is the property that keeps a web-facing page away from
 * git. So the answer arrives the same way lane replies and desk items do —
 * through the snapshot.
 *
 * @return array<string,array{exists:bool,merged:bool,ahead:int}>
 */
function branchStates(array $names): array
{
    $out = [];
    foreach ($names as $b) {
        // Re-validated even though the committer already fenced it: this string
        // is about to be handed to git, and a fence that only exists at the
        // write end is a fence with a back door.
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,80}$#', $b) || str_contains($b, '..')) { continue; }
        $ref = 'origin/' . $b;
        $ok  = run('git rev-parse --verify -q ' . escapeshellarg($ref), CLONE_DIR)['rc'] === 0;
        if (!$ok) { $out[$b] = ['exists' => false, 'merged' => false, 'ahead' => 0]; continue; }
        $merged = run('git merge-base --is-ancestor ' . escapeshellarg($ref) . ' origin/main', CLONE_DIR)['rc'] === 0;
        $ahead  = (int) trim(run('git rev-list --count origin/main..' . escapeshellarg($ref), CLONE_DIR)['out']);
        $out[$b] = ['exists' => true, 'merged' => $merged, 'ahead' => $ahead];
    }
    return $out;
}

/** Which branches the board has attached to cards. */
function attachedBranches(): array
{
    $dir = CLONE_DIR . '/docs/board-branches';
    if (!is_dir($dir)) { return []; }
    $names = [];
    foreach ((array) glob($dir . '/*.md') as $f) {
        foreach (explode("\n", (string) file_get_contents((string) $f)) as $line) {
            if (preg_match('/^- (\S+) — /u', $line, $m)) { $names[] = $m[1]; }
        }
    }
    return array_values(array_unique($names));
}

/* The inbound half. Independent of everything above. */
$snapshotLanes = [];
$reps = replies($lanes);
foreach ($lanes as $lane) {
    $snapshotLanes[$lane] = ['replies' => $reps[$lane] ?? [],
                             'delivery' => $delivery[$lane] ?? null];
}
// Computed ONCE. Written as retireDeskItems(deskItems()) in both slots it ran
// the whole thing twice — including the git calls behind the merged-branch
// check — every pass, on a two-core box with a fleet on it.
$deskSplit = retireDeskItems(deskItems());

if (!$dry) {
    $tmp = SNAPSHOT . '.tmp';
    file_put_contents($tmp, json_encode(['ts' => time(), 'lanes' => $snapshotLanes,
                                         'desk' => $deskSplit['live'],
                                         'deskRetired' => $deskSplit['retired'],
                                         'branches' => branchStates(attachedBranches())], JSON_UNESCAPED_SLASHES));
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
