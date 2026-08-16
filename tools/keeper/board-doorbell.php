#!/usr/bin/env php
<?php
/**
 * board-doorbell.php — wakes keeper when Ian has said something that is waiting
 * on an answer.
 *
 * Ian, 2026-08-16: the board chat and the questions rail only work if keeper
 * actually turns up. Keeper runs this as a tracked background task and ITS EXIT
 * IS THE DOORBELL — the harness re-invokes keeper with the last output line.
 * Same shape as tools/lanes/stall-watchdog.sh, deliberately: keeper already
 * knows how to run and relaunch that one.
 *
 *   board-doorbell.php              # ring once, then exit
 *   board-doorbell.php --once       # check once and exit even if nothing waits
 *
 * Exit 0 = it rang (or --once completed). Exit 3 = cannot run.
 *
 * ┌─ WHAT RINGS IT ──────────────────────────────────────────────────────────┐
 * │ chat      Ian's last chat message is newer than keeper's last reply       │
 * │ question  a question exists with no answer                                │
 * │ decision  Ian answered a posed decision — keeper acts on the ruling       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * IT READS, IT NEVER WRITES THE REPO. `git fetch` plus `git show origin/main:…`
 * — no checkout, no reset, no working tree touched. That is what makes it safe
 * to point at a clone another process is using: fetch only adds objects and
 * moves a remote ref, so it cannot surprise the committer mid-write.
 *
 * IT REMEMBERS WHAT IT RANG FOR. Keeper's pattern is ring → keeper acts →
 * relaunch, and an unanswered question is STILL unanswered the moment keeper
 * relaunches. Without a memory this would ring instantly and forever on the same
 * item — the doorbell equivalent of the watermark that never advances. The
 * memory lives beside keeper, not in the repo: losing it costs one duplicate
 * ring, which is the harmless direction.
 */

declare(strict_types=1);

const CLONE_DIR = '/home/ubuntu/board-lane-relay-clone';
const SEEN      = '/home/ubuntu/.board-doorbell-seen';
const POLL      = 20;   // seconds; person-paced, and cheap on a 2-core box

function out(string $line): void { fwrite(STDOUT, $line . "\n"); }

/** Read a file from origin/main WITHOUT touching any working tree. */
function blob(string $path): string
{
    $cmd = 'cd ' . escapeshellarg(CLONE_DIR) . ' && git show ' . escapeshellarg('origin/main:' . $path) . ' 2>/dev/null';
    return (string) shell_exec($cmd);
}

/** @return array<int,array{head:string,depth:int,text:string}> */
function blocks(string $raw): array
{
    $out = []; $cur = null; $buf = [];
    $flush = static function () use (&$out, &$cur, &$buf): void {
        if ($cur === null) { return; }
        $cur['text'] = rtrim(implode("\n", array_map(
            static fn (string $l): string => (string) preg_replace('/^>\s?/', '', $l), $buf)));
        $out[] = $cur;
    };
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $raw)) as $line) {
        if (preg_match('/^(#{3,4})\s+(.*)$/', $line, $m)) {
            $flush(); $buf = [];
            $cur = ['head' => trim($m[2]), 'depth' => strlen($m[1]), 'text' => ''];
            continue;
        }
        if ($cur !== null && str_starts_with($line, '>')) { $buf[] = $line; }
    }
    $flush();
    return $out;
}

function seen(): array
{
    return is_readable(SEEN)
        ? array_filter(array_map('trim', explode("\n", (string) file_get_contents(SEEN))))
        : [];
}

function remember(string $key): void
{
    // Trimmed so this cannot grow without bound on a box that was at 91% disk.
    $all = seen(); $all[] = $key;
    if (count($all) > 500) { $all = array_slice($all, -500); }
    @file_put_contents(SEEN, implode("\n", $all) . "\n");
}

/**
 * @return array{key:string,line:string}|null the first thing waiting on keeper
 */
function waiting(): ?array
{
    $done = seen();

    /* 1. THE CHAT — his last word is newer than keeper's. */
    $lastIan = null; $lastKeeper = null;
    foreach (blocks(blob('docs/board-chat/keeper.md')) as $b) {
        if (!preg_match('/^(\S+ \S+)\s*—\s*(.+)$/u', $b['head'], $m)) { continue; }
        $who = trim($m[2]);
        if ($who === 'keeper') { $lastKeeper = $m[1]; } else { $lastIan = ['when' => $m[1], 'text' => $b['text']]; }
    }
    if ($lastIan !== null && ($lastKeeper === null || strcmp($lastIan['when'], $lastKeeper) > 0)) {
        $key = 'chat:' . $lastIan['when'];
        if (!in_array($key, $done, true)) {
            return ['key' => $key, 'line' => 'ALERT board-chat — Ian is waiting on a reply: '
                . mb_substr(str_replace("\n", ' ', $lastIan['text']), 0, 220)];
        }
    }

    /* 2. AN UNANSWERED QUESTION. */
    $qs = []; $answered = [];
    foreach (blocks(blob('docs/board-questions/questions.md')) as $b) {
        if (preg_match('/^(q\d+)\s+\S+ \S+\s*—\s*(.+)$/u', $b['head'], $m)) { $qs[$m[1]] = $b['text']; }
        elseif (preg_match('/^answer to (q\d+)\s*—/u', $b['head'], $m)) { $answered[$m[1]] = true; }
    }
    foreach ($qs as $id => $text) {
        if (isset($answered[$id])) { continue; }
        $key = 'question:' . $id;
        if (!in_array($key, $done, true)) {
            return ['key' => $key, 'line' => 'ALERT board-question ' . $id . ' — unanswered: '
                . mb_substr(str_replace("\n", ' ', $text), 0, 220)];
        }
    }

    /* 3. A DECISION IAN HAS RULED ON — keeper acts on it, whichever door it came
     *    through. This is the half that lets keeper keep working instead of
     *    idling on an open box, which was Ian's stated purpose. */
    $ls = (string) shell_exec('cd ' . escapeshellarg(CLONE_DIR)
        . ' && git ls-tree --name-only origin/main docs/board-decisions/ 2>/dev/null');
    foreach (array_filter(array_map('trim', explode("\n", $ls))) as $path) {
        $raw = blob($path);
        foreach (blocks($raw) as $b) {
            if ($b['depth'] !== 4 || !preg_match('/^answered (\S+ \S+)\s*—\s*(.+?)\s*—\s*via (\w+)$/u', $b['head'], $m)) { continue; }
            if (trim($m[2]) === 'keeper') { continue; }     // keeper's own writes are not a doorbell
            $id  = basename($path, '.md');
            $key = 'decision:' . $id . ':' . $m[1];
            if (!in_array($key, $done, true)) {
                return ['key' => $key, 'line' => 'ALERT board-decision ' . $id . ' RULED via ' . $m[3] . ': '
                    . mb_substr(str_replace("\n", ' ', $b['text']), 0, 220)];
            }
        }
    }

    return null;
}

/* ---------------------------------------------------------------------- */

$once = in_array('--once', array_slice($_SERVER['argv'], 1), true);

if (!is_dir(CLONE_DIR . '/.git')) {
    out('CANNOT RUN: no clone at ' . CLONE_DIR);
    exit(3);
}

// The relaunch order rides on the alert itself, exactly as stall-watchdog does —
// keeper reads the alert and relaunches in the SAME tool call, so the wake-up
// cannot be separated from the instruction to re-arm.
register_shutdown_function(static function (): void {
    out('==> RELAUNCH NOW (same tool call): php ~/loothplatformv2-clean/tools/keeper/board-doorbell.php in background');
});

while (true) {
    shell_exec('cd ' . escapeshellarg(CLONE_DIR) . ' && git fetch -q origin 2>/dev/null');
    $w = waiting();
    if ($w !== null) {
        remember($w['key']);
        out($w['line']);
        exit(0);
    }
    if ($once) { out('nothing waiting on keeper'); exit(0); }
    sleep(POLL);
}
