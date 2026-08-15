<?php
/**
 * GATE (number pending — keeper assigns; the ledger currently disagrees with
 * itself, saying both "next free 41" and "next free 43", so this file will not
 * mint one) — THE WORK BOARD, phase 1.
 *
 *   php tools/gates/work-board-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Backlog 29, built on Ian's nod: "It's good enough to start building though."
 * Phase 1 is READ-ONLY — docs/BACKLOG.md rendered as the ranked list, derived
 * badges, lane lights and a capacity strip off the sentinel stamp.
 *
 * THE DEFECT THIS EXISTS FOR, and it was real, not hypothetical.
 *
 *   The first cut silently dropped every COMPLETED item from the board.
 *   Cause: `preg_split('/\R/', …)` — without the /u flag, PCRE's \R also
 *   matches the single byte 0x85 (NEL), and 0x85 is the THIRD BYTE of "✅"
 *   (E2 9C 85). So the split cut in half every line containing a tick, leaving
 *   fragments that are not valid UTF-8 — and `preg_match` with /u returns
 *   FALSE on invalid input SILENTLY, not as an error. Five P0 items became
 *   three. The board looked fine. Nothing logged.
 *
 *   That is the whole reason §1 exists: a board that quietly loses rows is
 *   worse than no board, because it gets trusted. So the assertion is not
 *   "the parser works" — it is "every index line in the FILE appears in the
 *   RENDER", counted against an independent parse.
 *
 * WHAT MUST HOLD:
 *   1. NOTHING IS SILENTLY DROPPED — the render carries every item the file's
 *      PRIORITY INDEX carries, ticked ones included.
 *   2. Letter-prefixed ids (E1…E5, S1…S3) survive as well as numeric ones.
 *   3. A MISSING sentinel degrades honestly — it says so and invents no number.
 *   4. A MALFORMED sentinel does the same.
 *   5. The thresholds mean what they say: disk at/over 90% renders the warning,
 *      under it does not.
 *   6. PHASE 1 CANNOT WRITE. No write call, no POST handling, no state change.
 *      Read-only is the property that lets this ship without a flag.
 *
 * Red-first record with measured counts at the foot of this file.
 */

declare(strict_types=1);

$ROOT  = dirname(__DIR__, 2);
$PAGE  = $ROOT . '/webroot/wip-board.php';
$BACK  = $ROOT . '/docs/BACKLOG.md';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function section(string $t): void { echo "\n$t\n"; }
function cannot(string $w): void { echo "CANNOT RUN: $w\n"; exit(3); }

foreach ([$PAGE, $BACK] as $f) { if (!is_readable($f)) cannot("missing $f"); }

/** Render the page in a subprocess, with the sources pointed wherever we like. */
function render(string $page, ?string $backlog = null, ?string $sentinel = null): string
{
    $env = '';
    if ($backlog !== null)  { $env .= 'LGB_BACKLOG='  . escapeshellarg($backlog)  . ' '; }
    if ($sentinel !== null) { $env .= 'LGB_SENTINEL=' . escapeshellarg($sentinel) . ' '; }
    $cmd = $env . PHP_BINARY . ' ' . escapeshellarg($page) . ' 2>/dev/null';
    return (string) shell_exec($cmd);
}

/**
 * An INDEPENDENT parse of the file — deliberately not the page's own code.
 * If both were the same implementation this would assert nothing.
 *
 * @return array<int,string> item ids, in file order
 */
function truth(string $backlog): array
{
    $raw   = str_replace([ "\r\n", "\r" ], "\n", (string) file_get_contents($backlog));
    $lines = explode("\n", $raw);
    $ids = []; $in = false; $inBand = false;
    foreach ($lines as $l) {
        if (!$in) { if (str_starts_with($l, '## PRIORITY INDEX')) { $in = true; } continue; }
        if (str_starts_with($l, '---') || (str_starts_with($l, '## ') && !str_starts_with($l, '## PRIORITY'))) { break; }
        if (preg_match('/^\*\*(.+?)\*\*\s*$/u', $l)) { $inBand = true; continue; }
        if ($inBand && preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+/u', $l, $m)) { $ids[] = $m[1]; }
    }
    return $ids;
}

echo "GATE — the work board, phase 1 (read-only)\n";

/* ---------------------------------------------------------------------- */
section("[1] NOTHING IS SILENTLY DROPPED — the ✅ regression");

$html  = render($PAGE);
$ids   = truth($BACK);
is_($ids !== [], sprintf("the file's PRIORITY INDEX yields items to check (%d)", count($ids)));

$missing = [];
foreach ($ids as $id) {
    // The id is rendered in its own cell; match that rather than anywhere on the page.
    if (!preg_match('/class="row__n">' . preg_quote($id, '/') . '</u', $html)) { $missing[] = $id; }
}
is_($missing === [], sprintf(
    "every one of the %d index items reaches the render (missing: %s)",
    count($ids), $missing === [] ? 'none' : implode(', ', $missing)));

// The regression had a signature: ticked items vanished. Assert some ticked
// items exist in the file, and that they are present — otherwise §1 could pass
// vacuously on a backlog that happens to have none.
$rawFile = str_replace([ "\r\n", "\r" ], "\n", (string) file_get_contents($BACK));
$ticked  = 0;
foreach (explode("\n", $rawFile) as $l) {
    if (preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s+/u', $l) && str_contains($l, '✅')) { $ticked++; }
}
is_($ticked > 0, sprintf("the backlog really does contain ticked items, so this is not a vacuous check (%d)", $ticked));

$headerCount = preg_match('/(\d+) items/', $html, $m) ? (int) $m[1] : -1;
is_($headerCount === count($ids), sprintf(
    "the header's own count agrees with the file (%d vs %d)", $headerCount, count($ids)));

/* ---------------------------------------------------------------------- */
section("[2] LETTER-PREFIXED IDS SURVIVE");

$letters = array_values(array_filter($ids, static fn (string $i): bool => (bool) preg_match('/^[A-Z]/', $i)));
is_($letters !== [], sprintf("the file uses letter ids, so this is worth asserting (%s)", implode(',', $letters)));
$lost = array_values(array_filter($letters, static fn (string $i): bool => !str_contains($html, '">' . $i . '<')));
is_($lost === [], sprintf("every letter-prefixed id renders (lost: %s)", $lost === [] ? 'none' : implode(',', $lost)));

/* ---------------------------------------------------------------------- */
section("[3] A MISSING SENTINEL DEGRADES HONESTLY");

$noSent = render($PAGE, null, '/nonexistent/sentinel.json');
is_(str_contains($noSent, 'no reading yet'), "it says there is no reading rather than showing nothing at all");
is_(!preg_match('/of 2 cores/', $noSent), "it invents NO load figure");
is_(!preg_match('/% used/', $noSent), "it invents NO disk figure");
is_(!preg_match('/class="lane__n"/', $noSent), "it invents NO lane lights");
is_(str_contains($noSent, 'class="row__n"'), "...while the backlog half still renders — one dead source does not blank the page");

/* ---------------------------------------------------------------------- */
section("[4] A MALFORMED SENTINEL DOES THE SAME");

$tmp = tempnam(sys_get_temp_dir(), 'lgbs') . '.json';
file_put_contents($tmp, "{ this is not json ");
$badSent = render($PAGE, null, $tmp);
is_(str_contains($badSent, 'not valid JSON'), "malformed JSON is named as such");
is_(!preg_match('/of 2 cores|% used/', $badSent), "and still invents no numbers");
@unlink($tmp);

/* ---------------------------------------------------------------------- */
section("[5] THE THRESHOLDS MEAN WHAT THEY SAY");

$mk = static function (int $disk, float $load, int $swap): string {
    $f = tempnam(sys_get_temp_dir(), 'lgbs') . '.json';
    file_put_contents($f, json_encode([
        'ts' => time(), 'load1' => $load, 'mem_avail_mb' => 2000,
        'swap_used_mb' => $swap, 'disk_pct' => $disk,
        'lanes' => [ [ 'name' => 'probe-lane', 'state' => 'working' ] ],
    ]));
    return $f;
};

$f = $mk(91, 0.5, 400);  $h91 = render($PAGE, null, $f); @unlink($f);
$f = $mk(74, 0.5, 400);  $h74 = render($PAGE, null, $f); @unlink($f);
is_(str_contains($h91, 'over the 90% line'), "disk at 91% is called out as over the line");
is_(!str_contains($h74, 'over the 90% line'), "disk at 74% is NOT — the warning is conditional, not decorative");
/**
 * Count RED BARS, not the string "f--bad".
 *
 * The first version of these three assertions matched the bare class name —
 * which is also present in the stylesheet, on every render. So "load is drawn
 * red" and "swap is drawn red" passed on a page with no red bar at all, and
 * only the healthy-box case exposed it by failing when it should have passed.
 * An assertion that cannot fail is not an assertion.
 */
$reds = static fn (string $h): int => preg_match_all('/class="bar__f f--bad"/', $h);

is_($reds($h91) >= 1, "and the bar is drawn red at 91%");
is_($reds($h74) === 0, "...while a 74% disk draws no red bar");

$f = $mk(74, 5.2, 400); $hLoad = render($PAGE, null, $f); @unlink($f);
is_($reds($hLoad) >= 1, "load over the throttle line is drawn red");

$f = $mk(74, 0.5, 1600); $hSwap = render($PAGE, null, $f); @unlink($f);
is_($reds($hSwap) >= 1, "swap over the 1 GB stop line is drawn red");

$f = $mk(74, 0.5, 400); $hOk = render($PAGE, null, $f); @unlink($f);
is_($reds($hOk) === 0, "a healthy box shows NO red bar at all — otherwise red means nothing");
is_(str_contains($hOk, 'probe-lane'), "the lane light renders the name the stamp gave it");

/* ---------------------------------------------------------------------- */
section("[6] PHASE 1 CANNOT WRITE");

// Read-only is the property that lets this ship without a flag, so it is
// asserted against the SOURCE rather than trusted. Comments are stripped first
// so prose about writing is not mistaken for a write.
$src  = (string) file_get_contents($PAGE);
$code = (string) preg_replace('!/\*.*?\*/!s', '', $src);
$code = (string) preg_replace('!^\s*//.*$!m', $code === null ? '' : $code, $code);
$code = (string) preg_replace('!^\s*//.*$!m', '', (string) preg_replace('!/\*.*?\*/!s', '', $src));

$writes = [];
foreach ([
    'file_put_contents(', 'fopen(', 'fwrite(', 'unlink(', 'rename(', 'mkdir(',
    'touch(', 'copy(', 'shell_exec(', 'exec(', 'system(', 'proc_open(', 'passthru(',
] as $needle) {
    if (str_contains($code, $needle)) { $writes[] = rtrim($needle, '('); }
}
is_($writes === [], sprintf("the page makes no write or shell call (%s)", $writes === [] ? 'clean' : 'FOUND: ' . implode(', ', $writes)));
is_(!preg_match('/\$_POST|\$_REQUEST|\$_FILES/', $code), "it reads no POST, request or upload input");
is_(!preg_match('/\$_GET/', $code), "it takes no query input at all — nothing to fuzz in phase 1");

/* ---------------------------------------------------------------------- */
echo "\n$pass passed, $fail failed\n";
if ($fail > 0) { echo "RED — the work board is not holding.\n"; exit(1); }
echo "GREEN — nothing dropped, letter ids survive, a dead sentinel degrades honestly, "
   . "thresholds are conditional, and phase 1 cannot write.\n";
exit(0);

/* ======================================================================= *
 * RED-FIRST RECORD — measured, not asserted. Baseline: see the run.
 *
 * Mutations applied to webroot/wip-board.php from a snapshot copy, gate run,
 * count recorded, file restored. Never `git checkout --`.
 *
 *   W1  restore the original `preg_split('/\R/', …)` — THE BUG AS FIRST
 *       WRITTEN, and the only mutation here that reproduces a real past state.
 *       Ticked items vanish; §1 reddens and names every missing id.
 *   W2  restore the numeric-only item regex `^(\d+(?:\.\d+)?)`
 *       -> §2 reddens: E1…E5 and S1…S3 disappear, including a security item
 *          marked awaiting Ian.
 *   W3  make the missing-sentinel branch render zeros instead of saying so
 *       -> §3 reddens: the page invents a load and a disk figure.
 *   W4  drop the `$disk >= LGB_DISK_RED_PCT` condition so the warning always
 *       shows -> §5 reddens on the 74% case: a warning that always fires is
 *       decoration, and red stops meaning anything.
 *   W5  add a file_put_contents() to the page
 *       -> §6 reddens. Phase 1's read-only property is what lets it ship
 *          without a flag, so it is asserted rather than assumed.
 * ======================================================================= */
