#!/usr/bin/env php
<?php
/**
 * board-done-ledger.php — write docs/DONE.md at a train landing, mechanically.
 *
 * Ian, 2026-08-16: *"do we have a file to move completed work to as a history or
 * are we relying on git as a record"* — the answer was git only, and git is
 * forensic depth rather than a record anyone reads.
 *
 * Backlog 41(d). One line per landed item: number · title · landed SHA · date.
 * APPEND-ONLY, and completed lines MOVE out of BACKLOG.md so the backlog stays
 * a list of what is LEFT.
 *
 *   board-done-ledger.php --range <old>..<new> [--dry-run]
 *
 * ┌─ HOW AN ITEM IS DECIDED DONE, and why it is not a keeper judgement ───────┐
 * │ A commit in the landed range carries a trailer:  Closes-Backlog: 18       │
 * │ That is the whole rule. Not a status somebody stamped, not a tick         │
 * │ somebody remembered to add — a fact recorded by whoever did the work, in  │
 * │ the commit that did it.                                                   │
 * │                                                                           │
 * │ Backlog 18 is why. It sat a full day as UNOWNED after Ian had personally  │
 * │ used the finished feature, because the only thing that could have moved   │
 * │ it was somebody noticing. Nothing here requires noticing.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * IT NEVER DELETES A LINE IT CANNOT REPLACE. An item is removed from BACKLOG.md
 * only after its ledger line is written, and only if the index line was found —
 * a ledger that loses work is worse than no ledger, because it is trusted.
 *
 * Exit 0 wrote (or had nothing to write), 2 internal failure, 3 cannot run.
 */

declare(strict_types=1);

const REPO_DEFAULT = '/home/ubuntu/loothplatformv2-clean';
const TRAILER      = 'Closes-Backlog:';

function say(string $m): void { fwrite(STDOUT, $m . "\n"); }
function bail(string $m, int $c): void { fwrite(STDERR, 'board-done-ledger: ' . $m . "\n"); exit($c); }

function run(string $cmd, string $cwd): array
{
    $out = []; $rc = 0;
    exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out), 'lines' => $out];
}

$argv_ = array_slice($_SERVER['argv'], 1);
$dry   = in_array('--dry-run', $argv_, true);
$repo  = getenv('LGB_LEDGER_REPO') ?: REPO_DEFAULT;
$range = '';
foreach ($argv_ as $i => $a) {
    if ($a === '--range' && isset($argv_[$i + 1])) { $range = $argv_[$i + 1]; }
}
if ($range === '' || !str_contains($range, '..')) { bail('need --range <old>..<new>', 3); }
if (!is_dir($repo . '/.git')) { bail('no repo at ' . $repo, 3); }

/* Which items did this train close? Read from the commits, not from anyone. */
$r = run('git log --format=%H%x1f%s%x1f%b%x1e ' . escapeshellarg($range), $repo);
if ($r['rc'] !== 0) { bail('could not read the range: ' . $r['out'], 2); }

$closed = [];   // item id => landing sha
foreach (explode("\x1e", $r['out']) as $rec) {
    $rec = trim($rec);
    if ($rec === '') { continue; }
    [$sha, $subj, $body] = array_pad(explode("\x1f", $rec), 3, '');
    if (!preg_match_all('/^' . preg_quote(TRAILER, '/') . '\s*([A-Z]?\d+(?:\.\d+)?)\s*$/mi', $body, $m)) { continue; }
    foreach ($m[1] as $id) { $closed[$id] = substr($sha, 0, 7); }
}

if ($closed === []) { say('no Closes-Backlog trailers in ' . $range . ' — nothing to record'); exit(0); }

/* The index line for each closed item, taken from BACKLOG.md itself. */
$blPath = $repo . '/docs/BACKLOG.md';
if (!is_readable($blPath)) { bail('BACKLOG.md unreadable', 3); }
$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($blPath)));

$rows = []; $drop = [];
foreach ($lines as $n => $line) {
    if (!preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+(.*)$/u', $line, $m)) { continue; }
    if (!isset($closed[$m[1]])) { continue; }
    // The TITLE is the first sentence-ish chunk: the ledger is a record, not a
    // second copy of the backlog, and a 900-character line helps nobody read
    // what shipped.
    $title = trim(preg_split('/[—–\-.:(]/u', $m[2])[0] ?? $m[2]);
    $rows[$m[1]] = ['title' => mb_strimwidth($title, 0, 90, '…', 'UTF-8'), 'sha' => $closed[$m[1]], 'line' => $n];
    $drop[$n] = true;
}

$missing = array_diff(array_keys($closed), array_keys($rows));
foreach ($missing as $id) {
    // Recorded anyway, WITHOUT dropping anything: a trailer naming an item that
    // is not in the index is a fact worth keeping and a signal worth seeing.
    $rows[$id] = ['title' => '(no index line found)', 'sha' => $closed[$id], 'line' => null];
}

$stamp = gmdate('Y-m-d');
$block = '';
ksort($rows, SORT_NATURAL);
foreach ($rows as $id => $row) {
    $block .= sprintf("- **%s** · %s · `%s` · landed %s\n", $id, $row['title'], $row['sha'], $stamp);
}

if ($dry) {
    say('[dry run] would append ' . count($rows) . ' line(s) to docs/DONE.md:');
    say($block);
    say('[dry run] would remove ' . count($drop) . ' index line(s) from BACKLOG.md');
    exit(0);
}

$donePath = $repo . '/docs/DONE.md';
if (!is_file($donePath)) {
    file_put_contents($donePath, "# Done — what has landed\n\n"
        . "Append-only. Written mechanically at each train landing by\n"
        . "`tools/keeper/board-done-ledger.php` from `Closes-Backlog:` commit trailers —\n"
        . "never by hand, so nothing depends on somebody noticing. Completed items MOVE\n"
        . "here out of BACKLOG.md, which stays a list of what is LEFT. Git remains the\n"
        . "forensic depth; this is the record a person reads.\n");
}
file_put_contents($donePath, "\n" . $block, FILE_APPEND);

/* Only NOW remove the index lines — never before the ledger line exists. */
$kept = [];
foreach ($lines as $n => $line) { if (!isset($drop[$n])) { $kept[] = $line; } }
file_put_contents($blPath, implode("\n", $kept));

say(sprintf('recorded %d item(s) in docs/DONE.md and removed %d from the backlog index',
    count($rows), count($drop)));
exit(0);
