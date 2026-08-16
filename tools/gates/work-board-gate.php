<?php
/**
 * GATE 50 — THE WORK BOARD, phase 1.
 *
 * Number ALLOCATED BY KEEPER, 2026-08-15 (ledger next free: 51). Not minted
 * here: the run-all ledger disagreed with itself at the time, saying both
 * "next free 41" and "next free 43", and a lane that guesses from a
 * self-contradicting ledger is how two gates end up sharing a number.
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
/** This gate's own shell helper — it does NOT have the committer gate's sh(). */
function sh2(string $cmd): void { shell_exec($cmd); }

foreach ([$PAGE, $BACK] as $f) { if (!is_readable($f)) cannot("missing $f"); }

/** Render the page in a subprocess, with the sources pointed wherever we like. */
function render(string $page, ?string $backlog = null, ?string $sentinel = null): string
{
    /**
     * EVERY RENDER IS PINNED TO A KNOWN FILE, including the ones that do not
     * care which file it is.
     *
     * The page now prefers the committer's copy of the backlog when the served
     * one is behind main — deliberately, so a drag does not look lost on the
     * next reload. That means an unpinned render reads whatever the BOX happens
     * to hold, and this gate then compares it against the REPO's file. It did:
     * main carried 52 items while this branch carried 49, and four assertions
     * went red on a page that was working correctly. A gate that does not say
     * which file it is reading is not measuring the page, it is measuring the
     * box.
     */
    global $BACK;
    $backlog ??= $BACK;
    // Pinned for the same reason as the backlog copy: an unpinned render reads
    // whatever snapshot the BOX happens to hold, and then this gate is
    // measuring the box rather than the page.
    $env = 'LGB_MAIN_COPY=/nonexistent/main-copy.md LGB_THREADS=/nonexistent/threads.json ';
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

echo "GATE 50 — the work board, phase 1 (read-only)\n";

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
section("[1b] CONSERVATION — the board can never silently show fewer");

// Ian, 2026-08-15: "the wip board doesn't have all of the backlog." The census
// (docs/BACKLOG-CENSUS-2026-08-15.md) found no live item missing — but the
// question deserves a STANDING answer, not a one-off audit. So: file count and
// board count must be equal, every run, and the census is the fixture that
// makes a shrinking FILE visible rather than mistaken for a healthy board.
$fixture = [];
$fx = $ROOT . '/tools/gates/fixtures-backlog-census.json';
if (is_readable($fx)) { $fixture = json_decode((string) file_get_contents($fx), true) ?: []; }

is_($fixture !== [], "the census fixture is present, so conservation has a baseline to speak of");

$fileCount  = count($ids);
$boardCount = preg_match_all('/data-item="/', $html);
is_($fileCount === $boardCount, sprintf(
    "CONSERVATION: the board renders exactly what the file carries (%d in the file, %d on the board)",
    $fileCount, $boardCount));

// A shrinking file is legitimate (items get archived) but must not pass silently
// as "the board is fine" — it is reported, and the fixture is what makes it
// noticeable at all.
$baseline = (int) ($fixture['index_rows'] ?? 0);
if ($baseline > 0 && $fileCount < $baseline) {
    echo sprintf("  --   NOTE: the file now carries %d index rows, down from %d at the census. "
               . "That is a FILE change, not a board fault — re-take the census if it was intended.\n",
        $fileCount, $baseline);
}
is_($baseline === 0 || $fileCount >= $baseline || $boardCount === $fileCount,
    sprintf("...and if the file shrank, the board shrank WITH it rather than losing rows of its own (baseline %d)", $baseline));

// The known collision stays known: if a NEW duplicate id appears, say so.
$dupes = array_values(array_unique(array_diff_assoc($ids, array_unique($ids))));
$known = (array) ($fixture['known_duplicate_ids'] ?? []);
$novel = array_values(array_diff($dupes, $known));
is_($novel === [], sprintf(
    "no NEW duplicate ids have appeared (known: %s; new: %s)",
    $known === [] ? 'none' : implode(',', $known), $novel === [] ? 'none' : implode(',', $novel)));

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
section("[5b] A MISSING BACKLOG IS LOUD, NOT A SILENT ZERO");

// Found by accident: an early serve-test pointed the page at a tree with no
// docs/ and it rendered "0 items". That is the same failure family as the
// tick-byte bug — a board showing nothing looks exactly like a board with
// nothing to show. It happens to be handled; it was not ASSERTED, so it could
// have regressed silently.
$noBack = render($PAGE, '/nonexistent/BACKLOG.md', null);
is_(str_contains($noBack, 'class="err"'), "a missing backlog renders a visible error, not an empty list");
is_(str_contains($noBack, 'not readable'), "...and says WHY it is empty");
is_(str_contains($noBack, 'class="rail"'), "...while the sentinel half still renders — one dead source does not blank the page");

/* ---------------------------------------------------------------------- */
section("[5c] EVERY ROW OPENS ITS OWN ITEM — ids are NOT unique");

// The read-only work modal. The trap here is real and was hit: the PRIORITY
// INDEX carries the id "9" TWICE (Shop Layout Planner in P1, Advanced search in
// P2). An id-keyed payload silently collapses them, so both rows open the
// second one's text — two rows, one payload, wrong content, no error. Rows are
// therefore keyed per ROW, and this asserts it stays that way.
if (!preg_match_all('/data-item="([^"]+)"/', $html, $dm)) {
    bad("no openable rows at all");
} else {
    $keys = $dm[1];
    is_(count($keys) === count(array_unique($keys)), sprintf(
        "every row carries a UNIQUE key (%d rows, %d unique)", count($keys), count(array_unique($keys))));
    is_(count($keys) === count($ids), sprintf(
        "and there is one openable row per index item (%d vs %d)", count($keys), count($ids)));

    $payload = [];
    if (preg_match('/id="lgb-details">(.*?)<\/script>/s', $html, $pm)) {
        $payload = json_decode($pm[1], true) ?: [];
    }
    is_(count($payload) === count($keys), sprintf(
        "the payload has an entry for every row (%d vs %d)", count($payload), count($keys)));
    is_(array_diff($keys, array_keys($payload)) === [], "no row points at a payload entry that does not exist");

    // The duplicate-id case specifically: if the file still has one, the two
    // rows must carry DIFFERENT text.
    $dupes = array_values(array_diff_assoc($ids, array_unique($ids)));
    if ($dupes !== []) {
        $seen = [];
        foreach ($payload as $entry) {
            $h = (string) ($entry['heading'] ?? '');
            foreach ($dupes as $d) {
                if (str_starts_with($h, $d . ' ')) { $seen[$d][] = $h; }
            }
        }
        $collapsed = [];
        foreach ($seen as $d => $headings) {
            if (count(array_unique($headings)) < 2) { $collapsed[] = $d; }
        }
        is_($collapsed === [], sprintf(
            "the duplicated id(s) %s open DIFFERENT items, not the same one twice (collapsed: %s)",
            implode(',', $dupes), $collapsed === [] ? 'none' : implode(',', $collapsed)));
    } else {
        ok("no duplicate ids in the index today — nothing to collapse");
    }
}

/* ---------------------------------------------------------------------- */
section("[5c2] THE ROW YOU CLICK OPENS THE ITEM YOU CLICKED");

// Ian hit this on the live board: he clicked a row in one project and got a
// different item's modal. The key was a COUNTER incremented in two separate
// loops — the payload built walking the file's P-bands, the rows rendered
// walking the sorted project accordions. Same key name, different item.
//
// So this does not check that keys are unique (they were) or that a payload
// entry exists (it did). It checks the thing that was actually wrong: that the
// TITLE ON THE ROW matches the TITLE IN ITS MODAL. Uniqueness was true while
// the mapping was nonsense.
$rowPairs = [];
if (preg_match_all('/data-item="([^"]+)"[^>]*>\s*<span class="row__t">([^<]*)</s', $html, $rp, PREG_SET_ORDER)) {
    foreach ($rp as $m) { $rowPairs[] = [ $m[1], html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5) ]; }
}
is_($rowPairs !== [], sprintf("rows expose their title alongside their key (%d)", count($rowPairs)));

$payload2 = [];
if (preg_match('/id="lgb-details">(.*?)<\/script>/s', $html, $pm2)) {
    $payload2 = json_decode($pm2[1], true) ?: [];
}
$mismatch = [];
foreach ($rowPairs as [$key, $title]) {
    $title = trim($title);
    $head  = (string) ($payload2[$key]['heading'] ?? '');
    if ($head === '' || ($title !== '' && !str_contains($head, mb_substr($title, 0, 30)))) {
        $mismatch[] = $key . ' (row "' . mb_substr($title, 0, 22) . '" vs modal "' . mb_substr($head, 0, 22) . '")';
    }
}
is_($mismatch === [], sprintf(
    "every row's modal is THAT row's item (%d checked; wrong: %s)",
    count($rowPairs), $mismatch === [] ? 'none' : implode(' | ', array_slice($mismatch, 0, 3))));

/* ---------------------------------------------------------------------- */
section("[5d] PROJECT NESTING — and a mapping gap must be VISIBLE");

// Ian: "nested and have names of the projects rather than the p0 etc." The
// danger in any auto-grouping is a WRONG group: it hides work under a name its
// owner would never look under, and unlike a missing row it leaves no hole to
// notice. So the map is explicit (docs/board-projects.php) and anything it does
// not cover must land in a VISIBLE "unsorted" group — never be quietly filed
// somewhere plausible.
is_(str_contains($html, 'class="proj'), "items are grouped into project accordions");
is_(!preg_match('/class="band__n">P\d</', $html), "P-bands are NOT section headings any more");

// Every row still lands inside some project panel.
$inPanels = preg_match_all('/<details class="proj.*?<\/details>/s', $html, $pm);
$rowsInPanels = 0;
foreach ($pm[0] ?? [] as $panel) { $rowsInPanels += preg_match_all('/data-item="/', $panel); }
is_($rowsInPanels === count($ids), sprintf(
    "every item sits inside a project panel (%d of %d)", $rowsInPanels, count($ids)));

// THE ASSERTION THAT MATTERS: with a rule removed, the orphan must SHOW UP as
// unsorted rather than vanish or be absorbed. Proven by running the page
// against a map with no rules at all.
$empty = tempnam(sys_get_temp_dir(), 'lgbp') . '.php';
file_put_contents($empty, "<?php return ['projects' => [], 'rules' => []];\n");
$tmpRepo = sys_get_temp_dir() . '/lgb-proj-' . getmypid();
@mkdir($tmpRepo . '/docs', 0755, true);
@copy($empty, $tmpRepo . '/docs/board-projects.php');
@copy($BACK, $tmpRepo . '/docs/BACKLOG.md');
@mkdir($tmpRepo . '/webroot', 0755, true);
@copy($PAGE, $tmpRepo . '/webroot/wip-board.php');
$orphaned = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/main-copy.md ' . PHP_BINARY . ' ' . escapeshellarg($tmpRepo . '/webroot/wip-board.php') . ' 2>/dev/null');
is_(str_contains($orphaned, 'Unsorted'), "with an EMPTY map, items surface as Unsorted — the gap is visible, not absorbed");
// Anchored on the ELEMENT, not the class name. `proj--unsorted` also appears in
// the STYLESHEET, so the match started there and ran to the first `</details>`
// anywhere after it — which was fine until a `<details>` appeared earlier in the
// document (the lane threads), at which point this counted zero rows and blamed
// the page. Fourth time this gate has matched a string that also lives in CSS or
// a comment; the cure is always the same — assert the markup that can only be
// output.
$unsortedRows = preg_match('/<details class="proj proj--unsorted".*?<\/details>/s', $orphaned, $um)
    ? preg_match_all('/data-item="/', $um[0]) : 0;
is_($unsortedRows === count($ids), sprintf(
    "...and ALL %d items are in it, so nothing is silently dropped when the map is empty (%d)",
    count($ids), $unsortedRows));
@unlink($empty);
@unlink($tmpRepo . '/docs/board-projects.php'); @unlink($tmpRepo . '/docs/BACKLOG.md');
@unlink($tmpRepo . '/webroot/wip-board.php'); @rmdir($tmpRepo . '/docs'); @rmdir($tmpRepo . '/webroot'); @rmdir($tmpRepo);

// Done work leaves the active list by STATE, not by hand (Ian's other ruling).
is_(str_contains($html, 'class="donebox"'), "finished work collapses into a drawer rather than sitting in the list");

/* ---------------------------------------------------------------------- */
section("[5e] YOUR DESK — every line of the file reaches the strip");

// Ian asked "is that on the wip list?" of something waiting on him, and it was
// not on the page at all. docs/IAN-DESK.md is keeper-maintained and is the
// truth; the board only renders it. So the assertion is the same one that
// matters everywhere here: nothing the file says may be silently dropped.
$DESK = $ROOT . '/docs/IAN-DESK.md';
if (!is_readable($DESK)) {
    ok("no IAN-DESK.md on this branch — strip is absent by design, nothing to assert");
} else {
    $draw = str_replace([ "\r\n", "\r" ], "\n", (string) file_get_contents($DESK));
    $joined = (string) preg_replace('/\n(?!\s*[-#*]|\n)\s+/', ' ', $draw);
    $bullets = 0;
    foreach (explode("\n", $joined) as $l) { if (str_starts_with(ltrim($l), '- ')) { $bullets++; } }
    is_($bullets > 0, sprintf("the desk file really has lines to render (%d)", $bullets));

    $rendered = preg_match_all('/class="desk__i/', $html);
    is_($rendered === $bullets, sprintf(
        "every desk line reaches the strip (%d in the file, %d on the board)", $bullets, $rendered));
    is_(str_contains($html, 'class="desk__t">Your desk'), "the strip is titled Your desk");

    // It sits ABOVE the work, which is the whole point of a desk.
    $pDesk = strpos($html, 'class="desk'); $pProj = strpos($html, '<details class="proj');
    is_($pDesk !== false && $pProj !== false && $pDesk < $pProj, "and it sits above the project accordion");

    // Empty file => the empty state, not a missing strip.
    $tmpRepo = sys_get_temp_dir() . '/lgb-desk-' . getmypid();
    @mkdir($tmpRepo . '/docs', 0755, true); @mkdir($tmpRepo . '/webroot', 0755, true);
    @copy($BACK, $tmpRepo . '/docs/BACKLOG.md');
    @copy($ROOT . '/docs/board-projects.php', $tmpRepo . '/docs/board-projects.php');
    file_put_contents($tmpRepo . '/docs/IAN-DESK.md', "# Ian's desk\n\n*nothing here*\n");
    @copy($PAGE, $tmpRepo . '/webroot/wip-board.php');
    $emptyDesk = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/main-copy.md ' . PHP_BINARY . ' ' . escapeshellarg($tmpRepo . '/webroot/wip-board.php') . ' 2>/dev/null');
    is_(str_contains($emptyDesk, 'Nothing waits on you'),
        "an empty desk file renders the empty state, not a blank or a missing strip");
    is_(!preg_match('/class="desk__i/', $emptyDesk), "...and lists nothing");
    foreach (['BACKLOG.md', 'IAN-DESK.md', 'board-projects.php'] as $f) { @unlink($tmpRepo . '/docs/' . $f); }
    @unlink($tmpRepo . '/webroot/wip-board.php'); @rmdir($tmpRepo . '/docs'); @rmdir($tmpRepo . '/webroot'); @rmdir($tmpRepo);
}

/* ---------------------------------------------------------------------- */
section("[5f] THE COPY-FOR-CHAT BRIDGE");

// Ian: "it should have a copy and paste section for me to bring back here into
// vs." Render-only — it produces text, it writes nothing. The value is that the
// block carries the QUESTION and a blank answer line, so what he pastes back
// says what it is answering; a bare "yes" in a chat is the thing this replaces.
is_(preg_match_all('/class="cpy" data-copy="/', $html) > 0,
    sprintf("needs-you rows carry a copy button (%d)", preg_match_all('/class="cpy" data-copy="/', $html)));
is_(str_contains($html, 'id="lgb-copytext"'), "the modal carries its own copy block");
is_(str_contains($html, 'My answer:'), "the block leaves a blank ANSWER line — the point of the bridge");
is_(str_contains($html, 'BOARD ITEM '), "...and names the item, so a pasted reply says what it answers");

// Every copy button must point at a payload entry that exists, or it silently
// copies an empty string — a button that appears to work and does nothing.
$copyKeys = preg_match_all('/class="cpy" data-copy="([^"]+)"/', $html, $ck) ? $ck[1] : [];
$orphanCopy = array_values(array_diff($copyKeys, array_keys($payload2 ?? [])));
is_($orphanCopy === [], sprintf(
    "every copy button resolves to a real item (dangling: %s)",
    $orphanCopy === [] ? 'none' : implode(',', $orphanCopy)));

// A clipboard API is not always available (older browsers, blocked contexts).
is_(str_contains($html, 'execCommand'), "there is a fallback, so the button cannot silently do nothing");
is_(str_contains($html, 'e.stopPropagation()'), "copying does not also open the modal");

// The page WRITES now (phase 2), so "no fetch anywhere" is no longer the
// property — and an assertion kept past the point where it was true is how a
// gate starts blocking the merge train instead of protecting it. What must
// still hold is that the client has exactly ONE network call, it goes to this
// page's own path, and it carries the write header. A copy button that quietly
// posted somewhere would break all three.
$fetches = preg_match_all('/fetch\(/', $html);
is_($fetches === 1, sprintf('the client makes exactly one network call (%d)', $fetches));
is_(str_contains($html, 'fetch(location.pathname'), '...to its own path, nowhere else');
is_(!preg_match('/XMLHttpRequest|navigator\.sendBeacon/', $html),
    '...and no second channel was smuggled in beside it');

/* ---------------------------------------------------------------------- */
section("[5g] THE SHIPPED ARCHIVE REACHES THE BOARD");

/**
 * The same conservation law the index gets, applied to the half that was
 * missing. This is not a display nicety: the archive was invisible because
 * lgb_parse_details reads the first token of a heading as an item id, and
 * "2026-08-01 — …" yields "2026" — so all 30 sections collapsed onto one key
 * and the last one silently won. Nothing errored; they simply were not there.
 *
 * Counted with an INDEPENDENT parse, deliberately not the page's own, or this
 * would assert only that a function agrees with itself.
 */
$archTruth = [];
foreach (explode("\n", (string) file_get_contents($BACK)) as $l) {
    if (str_starts_with($l, '## ')) {
        $probe = ltrim(trim(substr($l, 3)), "✅ \t");
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*[—–-]\s*(.+)$/u', $probe, $m)) {
            $archTruth[] = trim($m[2]);
        }
    }
}
is_(count($archTruth) >= 10, sprintf('the file really carries a shipped archive, so this is not vacuous (%d)', count($archTruth)));

$histHtml = render($PAGE, $BACK);
$missing = [];
foreach ($archTruth as $t) {
    // Compare on a distinctive slice — sliced by CHARACTER, not by byte.
    // substr() cut straight through the middle of "🔴" and "—", producing a
    // needle that is not valid UTF-8 and can never match the escaped render.
    // Two headings then reported as MISSING from a board that was showing all
    // thirty. Same family as the ✅ that halved the ticked items: a byte
    // operation applied to text that is not bytes.
    $needle = htmlspecialchars(mb_substr($t, 0, 40, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    if (!str_contains($histHtml, $needle)) { $missing[] = mb_substr($t, 0, 40, 'UTF-8'); }
}
is_($missing === [], sprintf('every archived item reaches the board (%d in the file, missing: %s)',
    count($archTruth), $missing === [] ? 'none' : implode(' | ', array_slice($missing, 0, 3))));

$rendered = substr_count($histHtml, 'class="hist__i"');
is_($rendered === count($archTruth),
    sprintf('...and the board shows exactly that many, no more (%d rendered vs %d in the file)', $rendered, count($archTruth)));

// The summary count is DERIVED — a hand-typed one would drift the first time
// anything shipped, and a stale count is worse than none.
is_((bool) preg_match('/class="hist__c">\s*' . count($archTruth) . ' items/', $histHtml),
    'the archive\'s own count agrees with the file');

// THE TWO VIEWS MUST BE DISJOINT. A date heading must never be read as an item
// id (that is the bug), and an item id must never fall into the archive.
$idsNow = truth($BACK);
$overlap = array_intersect($idsNow, ['2026', '2026-08-01']);
is_($overlap === [], 'no date leaked into the index as an item id');

// A missing archive must SAY so rather than draw a comforting zero.
$noArch = $tmp . '-noarch.md';
$src = (string) file_get_contents($BACK);
$cut = strpos($src, '## ✅ SHIPPED TO LIVE');
file_put_contents($noArch, $cut === false ? $src : substr($src, 0, $cut));
$noArchHtml = render($PAGE, $noArch);
// Matched on the USAGE form and the words, not the bare class name — the
// stylesheet carries `.hist--none` on every render, so a bare-string check is
// true on a page that has no such element at all. This gate already learned
// that once, with `f--bad` (see the footer); the mutation that should have
// reddened this passed silently until it was written this way.
is_(str_contains($noArchHtml, 'class="hist hist--none"')
    && str_contains($noArchHtml, 'No shipped archive found'),
    'with no archive in the file, the board says so rather than drawing an empty box');
is_(!str_contains($noArchHtml, 'class="hist__i"'), '...and invents no entries');
@unlink($noArch);

section("[6e] THE CHAT, THE QUESTIONS RAIL, AND THE DESK DECISION BOXES");

/**
 * Ian's first cut, 2026-08-16. Three surfaces, one property between them: the
 * page shows only what the COMMITTED STORE holds. No client echo, no second
 * status, nothing drawn from what the page hoped.
 */
$fx = $tmp . '-fx';
@mkdir($fx . '/board-chat', 0755, true);
@mkdir($fx . '/board-questions', 0755, true);
@mkdir($fx . '/board-decisions', 0755, true);
copy($BACK, $fx . '/BACKLOG.md');
file_put_contents($fx . '/board-chat/keeper.md',
    "# chat\n\n### 2026-08-16 14:00 — ian-via-board\n\n> how is it all going?\n"
  . "\n### 2026-08-16 14:01 — keeper\n\n> three lanes green\n>     indented reply\n");
file_put_contents($fx . '/board-questions/questions.md',
    "# q\n\n### q1 2026-08-16 14:00 — ian-via-board\n\n> why the Map?\n"
  . "\n### q2 2026-08-16 14:02 — ian-via-board\n\n> and the digest floor?\n"
  . "\n#### answer to q1 — 2026-08-16 14:05 — keeper\n\n> the index already existed\n");
file_put_contents($fx . '/board-decisions/aron.md',
    "# Decision aron\n\n> What happens to Aron Bach?\n\n- Retract to free\n- Give him a grace period\n");
file_put_contents($fx . '/board-decisions/price.md',
    "# Decision price\n\n> Set the price?\n\n- 9 a month\n"
  . "\n#### answered 2026-08-16 13:00 — ian-via-board — via vs\n\n> 9 a month\n");

// A DERIVED DESK ITEM. Ian's "-> Ian" posts must reach his desk without anyone
// copying them across — the whole point of the automation.
$dsnap = $tmp . '-desk.json';
file_put_contents($dsnap, json_encode(['ts' => 1, 'lanes' => [], 'desk' => [
    ['when' => '2026-08-16 15:00', 'who' => 'featured-members', 'text' => 'one ruling needed on the digest floor'],
]]));
$deskHtml = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/m.md LGB_THREADS=' . escapeshellarg($dsnap)
    . ' LGB_BACKLOG=' . escapeshellarg($BACK) . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');
is_(str_contains($deskHtml, 'one ruling needed on the digest floor'),
    "a lane's post to Ian reaches his desk without anyone copying it across");
is_(str_contains($deskHtml, 'featured-members'), '...naming the lane that is waiting on him');
$noDeskHtml = render($PAGE, $BACK);
is_(!str_contains($noDeskHtml, 'one ruling needed on the digest floor'),
    '...and with no snapshot the desk invents nothing');
@unlink($dsnap);

$fxHtml = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/m.md LGB_THREADS=/nonexistent/t.json LGB_BACKLOG='
    . escapeshellarg($fx . '/BACKLOG.md') . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');

is_(substr_count($fxHtml, 'class="msg msg--') === 2, 'both chat messages render');
is_(str_contains($fxHtml, 'msg--in"><span class="msg__w">2026-08-16 14:01 · keeper'),
    "keeper's reply is marked as keeper's — the actor decides the side, not the page");
is_(str_contains($fxHtml, '    indented reply'),
    "...and a pasted indent survives into the chat panel");

is_(substr_count($fxHtml, 'class="q q--open"') === 1, 'only the UNANSWERED question is open (1 of 2)');
is_(str_contains($fxHtml, 'answered (1)'), '...and the answered one moved to the drawer');
is_(str_contains($fxHtml, 'the index already existed'),
    '...where it still shows the QUESTION and its answer — never deleted');
is_(str_contains($fxHtml, 'why the Map?'), '...the question text itself survives being answered');

is_(substr_count($fxHtml, 'class="dbox"') === 2, 'both decisions render on the desk');
is_(str_contains($fxHtml, 'Answered — 9 a month'),
    'ONE STORE TWO DOORS: a decision answered in his VS box shows ANSWERED on the desk');
is_(str_contains($fxHtml, 'via vs'), '...naming which door answered it');

// An already-ruled box must offer NOTHING to press. Otherwise he presses it, the
// committer refuses (first answer wins), and the board looks broken when it is
// in fact working exactly as designed.
$answeredBox = preg_match('/data-dec="price".*?<\/div>\s*<\/div>/s', $fxHtml, $bm) ? $bm[0] : '';
is_($answeredBox !== '' && !str_contains($answeredBox, '<button class="w2__opt"'),
    '...and offers no buttons, so he is never invited to answer it twice');

$openBox = preg_match('/data-dec="aron".*?dbox__say/s', $fxHtml, $om) ? $om[0] : '';
// Counted on the BUTTON, not the bare class: the container is `w2__opts`,
// which CONTAINS the substring `w2__opt`, so the naive count was always one
// too high. Sixth time this session a match has caught something adjacent to
// what it named.
is_(substr_count($openBox, '<button class="w2__opt"') === 2,
    'the open decision offers its posed options (2)');

/**
 * THE CHAT REFETCHES, IT DOES NOT FABRICATE — keeper's "renders ONLY committed
 * messages", asserted against the client source. After a send the panel asks
 * the server for the store's contents; it never builds a message element out of
 * what was typed. Without this the rule holds only until someone "improves" the
 * UX with an optimistic append, which is exactly how a chat starts showing
 * messages the repository never took.
 */
// Read HERE rather than borrowing section [6]'s $src — that variable is
// assigned further down the file, so this ran against an empty string and
// reported a working page as broken. Second time this session a section has
// used a helper its predecessor had not defined yet; sections are not
// independent just because they look it.
$pageSrc = (string) file_get_contents($PAGE);
is_(str_contains($pageSrc, "action: 'chat_read'"),
    'after a send the chat REFETCHES the committed store');

/**
 * NEAR-LIVE (parity phase 4). Ian used this chat for the first time on
 * 2026-08-16 and keeper answered in place; without polling he would have to
 * guess when to refresh, which is the difference between a chat and a form.
 *
 * The two properties that keep it honest and cheap: it polls the SAME committed
 * read (so polling cannot introduce a message the store does not hold), and it
 * PAUSES WHEN THE TAB IS HIDDEN — two cores and a fleet on this box, and a
 * background tab polling forever shows up later as unexplained load.
 */
is_((bool) preg_match('/setInterval\(\s*poll/', $pageSrc),
    'the chat polls for keeper\'s replies rather than waiting for a refresh');
is_((bool) preg_match('/if \(polling \|\| document\.hidden\)/', $pageSrc),
    '...and stops while the tab is hidden, so it cannot become background load');
is_((bool) preg_match('/chat\.length !== lastSeen/', $pageSrc),
    '...repainting only on CHANGE, so it cannot fight his cursor mid-sentence');

/**
 * THE WHOLE BOARD REFRESHES ON THE SAME TICK — Ian's ruling, 2026-08-16:
 * "It doesn't seem like that keeper chat on there is live."
 *
 * ONE request per tick, not four: on a two-core box four polls every eight
 * seconds is four times the work for the same answer. And the live update must
 * be the SAME HTML as the first paint, or the empty and absent states — the ones
 * carrying the honesty — are the first thing to drift.
 */
is_(str_contains($pageSrc, "action: 'board_state'"),
    'the tick refreshes the whole board, not only the chat');
is_(substr_count($pageSrc, 'setInterval(poll') === 1,
    '...on ONE timer, so the regions cannot drift out of step with each other');
is_(str_contains($pageSrc, 'lgb_render_desk_items') && str_contains($pageSrc, 'lgb_render_questions'),
    '...serving the SAME renderers the first paint uses, so live and initial cannot drift');
is_(str_contains($pageSrc, 'el.contains(document.activeElement)'),
    '...and never repainting a region he is typing in');

// The ranked accordions must NOT be in the refresh: they are what he DRAGS.
is_(!preg_match("/swapIfChanged\('lgb-(proj|rank)/", $pageSrc),
    'the ranked list is deliberately NOT live-repainted — it would fight the drag');

/**
 * IMAGE PASTE (parity phase 1). Ian's screenshots are the fleet's best bug
 * reports and every one currently arrives by a side channel.
 *
 * The properties gated here are the ones that would hurt if wrong: where the
 * bytes go, what is trusted about them, and what a missing file looks like.
 */
is_(str_contains($pageSrc, "action: 'media_upload'"), 'an image can be pasted into the chat');
is_(str_contains($pageSrc, "LGB_MEDIA_DIR    = '/srv/board-media'"),
    '...stored OUTSIDE the WP media library, where a board screenshot cannot become member-reachable');
is_(!preg_match('/wp_insert_attachment|wp_upload_bits|media_handle/', $pageSrc),
    '...and never through the WP media library, which would give it a public URL');

// A CLIENT-SUPPLIED FILENAME IS A PATH. The name is derived server-side.
is_((bool) preg_match("/gmdate\('Ymd-His'\) \. '-' \. bin2hex\(random_bytes/", $pageSrc),
    'the stored filename is DERIVED, never taken from the client');
is_(str_contains($pageSrc, 'FILEINFO_MIME_TYPE'),
    'the type is SNIFFED from the bytes — an extension is a claim, the magic bytes are the file');

// THE CAPS. This box is at 92% disk; a paste feature without a ceiling is a slow
// outage, and the budget must be a decision made now rather than discovered.
// Asserted on the COMPARISONS, not the constant names. The first version
// checked that the names appeared — so deleting the budget's DEFINITION left
// every usage in place, the string still present, and the gate still green on a
// file that would fatal at runtime. A name is not an enforcement.
is_((bool) preg_match('/strlen\(\$bin\) > LGB_MEDIA_MAX/', $pageSrc),
    'the per-image cap is actually COMPARED against, not merely named');
is_((bool) preg_match('/\$used \+ strlen\(\$bin\) > LGB_MEDIA_BUDGET/', $pageSrc),
    '...and so is the total budget, measured against what is already stored');
is_((bool) preg_match("/const LGB_MEDIA_BUDGET\s*=\s*\d+/", $pageSrc),
    '...with the budget actually defined, so the check cannot fatal instead of refusing');
is_(str_contains($pageSrc, 'nothing was deleted to make room'),
    '...and a full store REFUSES rather than quietly evicting something of his');

is_(str_contains($pageSrc, 'image no longer stored'),
    'a deleted image reads as gone — never a broken icon, never a silent gap');

/**
 * PASTE WORKS IN EVERY BOX THAT TAKES A MESSAGE, not only the chat. The spec
 * said "threads/chat" and the first cut wired the chat alone — the wrong half,
 * because an item's thread is where a screenshot belongs PERMANENTLY, beside
 * the decision it caused, while the chat scrolls away.
 */
is_(substr_count($pageSrc, 'function bindPaste(') === 1,
    'one paste binder, so every box behaves the same way');
is_(substr_count($pageSrc, 'bindPaste(') >= 4,
    '...bound to the chat, the item threads and the note box, not just one of them');

/**
 * AND THE HELPERS THEY SHARE MUST BE IN THE SAME SCOPE. `withMedia` began life
 * inside the chat's closure while `fillWrite` sits outside it, so an item modal
 * calling it would have thrown a ReferenceError the moment it opened — a break
 * a syntax check cannot see, because the syntax is fine. Asserted by brace
 * depth: both must be at the top level of the page's IIFE.
 */
$scriptStart = strrpos($pageSrc, '<script>');
$depthOf = static function (string $needle) use ($pageSrc, $scriptStart): int {
    $i = strpos($pageSrc, $needle, $scriptStart);
    if ($i === false) { return -1; }
    $seg = substr($pageSrc, $scriptStart, $i - $scriptStart);
    return substr_count($seg, '{') - substr_count($seg, '}');
};
is_($depthOf('function withMedia(') === $depthOf('function fillWrite(')
    && $depthOf('function withMedia(') > 0,
    'withMedia and fillWrite share a scope — an item modal can actually call it');

/**
 * THE FIRST PAINT AND THE REPAINT MUST AGREE ABOUT IMAGES.
 *
 * The first paint is PHP; every repaint is JavaScript. Without a server-side
 * equivalent, a pasted screenshot rendered as a RAW PATH until the first poll
 * eight seconds later and then silently became a picture — the same
 * two-renderers drift the region renderers were extracted to prevent, found only
 * by rendering every surface together instead of one at a time.
 */
$mediaFx = $tmp . '-media';
@mkdir($mediaFx . '/board-chat', 0755, true);
copy($BACK, $mediaFx . '/BACKLOG.md');
file_put_contents($mediaFx . '/board-chat/keeper.md',
    "# chat\n\n### 2026-08-16 19:00 — ian-via-board\n\n> look\n> /board-media/20260816-1-abc.png\n");
$mediaHtml = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/m.md LGB_THREADS=/nonexistent/t.json LGB_BACKLOG='
    . escapeshellarg($mediaFx . '/BACKLOG.md') . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');
is_((bool) preg_match('#<img class="msg__img" src="/board-media/20260816-1-abc\.png"#', $mediaHtml),
    'the SERVER paint renders a pasted image, not a raw path he has to wait out');
is_(str_contains($mediaHtml, 'image no longer stored'),
    '...carrying the same gone-file wording the client uses');

// BOTH server paints, not just the chat. There are two call sites — the chat and
// the thread box — and a mutation aimed at "the server paint" hit only one of
// them while this gate stayed green, which is how half a fix ships.
$mediaSrc = (string) file_get_contents($PAGE);
is_(substr_count($mediaSrc, 'lgb_with_media($m[\'text\'])') === 2,
    'BOTH server paints use it — the chat AND the item/lane threads');
sh2('rm -rf ' . escapeshellarg($mediaFx));
is_(!preg_match('/chat_send[\s\S]{0,900}?innerHTML\s*\+=/', $pageSrc),
    '...and never appends a message it made up from the typed text');
is_(str_contains($openBox, 'Something else'),
    "...and always an Other field — two buttons assert those are the only two answers, and often they are not");

sh2('rm -rf ' . escapeshellarg($fx));

section("[6f] BRANCHES ON CARDS — the link is stored, the state is DERIVED");

/**
 * Backlog 39, Ian: "So I can track branches better."
 *
 * The card→branch LINK is committed. The branch's STATE is not: whether it still
 * exists and whether it has merged change without anyone editing a file, so a
 * state stored beside the link would be a fact that ROTS — and a stale badge is
 * worse than no badge, because it gets trusted.
 */
$bfx = $tmp . '-br';
@mkdir($bfx . '/board-branches', 0755, true);
copy($BACK, $bfx . '/BACKLOG.md');
file_put_contents($bfx . '/board-branches/29.md',
    "# Branches — item 29\n- stripe-membership — 2026-08-16 18:00 — ian-via-board\n- dark-board — 2026-08-16 18:01 — ian-via-board\n");
file_put_contents($bfx . '/snap.json', json_encode(['ts' => 1, 'lanes' => [], 'branches' => [
    'stripe-membership' => ['exists' => true,  'merged' => false, 'ahead' => 10],
    'dark-board'        => ['exists' => false, 'merged' => false, 'ahead' => 0],
]]));
$brHtml = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/m.md LGB_THREADS=' . escapeshellarg($bfx . '/snap.json')
    . ' LGB_BACKLOG=' . escapeshellarg($bfx . '/BACKLOG.md') . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');

is_(str_contains($brHtml, 'stripe-membership') && str_contains($brHtml, 'dark-board'),
    "a card's attached branches reach the modal payload");
is_(str_contains($brHtml, 'id="lgb-branchstate"'), 'the derived state travels with the page');
is_(str_contains($brHtml, '"exists":false'),
    '...including that a branch is GONE from origin — the state the link cannot know');

// THE STALE-BADGE GUARD. An unmeasured branch must say so, not inherit a
// confident status from whatever was rendered last.
is_((bool) preg_match("/if \(!st\)\s*\{\s*return \['unknown'/", $brHtml),
    'a branch the snapshot has not measured renders UNKNOWN, never a guess');

// And the page must STILL be unable to reach git — the whole reason the state
// arrives through a snapshot instead of being computed here.
$brCode = (string) preg_replace('!/\*.*?\*/!s', '', (string) file_get_contents($PAGE));
$brCode = (string) preg_replace('!^\s*//.*$!m', '', $brCode);
is_(!preg_match('/shell_exec|proc_open|\bexec\(|passthru|popen/', $brCode),
    '...and the page still runs no command, so branches did not teach it git');
sh2('rm -rf ' . escapeshellarg($bfx));

section("[6a] THE BOARD SAYS WHICH COPY IT IS SHOWING");

/**
 * The page prefers the committer's copy of the backlog when the served one is
 * behind main, because the committer commits to main and nothing on this box
 * pulls the serve on a timer — so without this, Ian's drag lands and then
 * appears to vanish on his next reload. That is the failure this whole build
 * was told to design against, so the preference is asserted in all three
 * states rather than trusted.
 *
 * Note the SAME-CONTENT case matters as much as the differing one: a board that
 * announced "showing main" on every render would train him to ignore the line.
 */
$mainCopy = $tmp . '-maincopy.md';
$served   = (string) file_get_contents($BACK);

// (i) main's copy differs → the board reads MAIN and says so.
file_put_contents($mainCopy, str_replace('## PRIORITY INDEX', "## PRIORITY INDEX\n\n**PX — only in main**\n77 An item only main knows about", $served));
$aheadHtml = (string) shell_exec(
    'LGB_MAIN_COPY=' . escapeshellarg($mainCopy) . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');
is_(str_contains($aheadHtml, 'class="ahead"'), 'when main is ahead, the board says so on the page');
is_(str_contains($aheadHtml, 'An item only main knows about'),
    '...and really is rendering main\'s copy, not just captioning the old one');

// (ii) identical → no claim at all.
file_put_contents($mainCopy, $served);
$sameHtml = (string) shell_exec(
    'LGB_MAIN_COPY=' . escapeshellarg($mainCopy) . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');
is_(!str_contains($sameHtml, 'class="ahead"'),
    'when the two agree it says nothing — a notice on every render is a notice nobody reads');

// (iii) A REORDER IS THE CASE THIS EXISTS FOR, and it changes no bytes at all —
// the same lines in a different sequence. A size comparison would be blind to
// exactly the one operation the board offers.
$lines = explode("\n", $served);
$idx = [];
foreach ($lines as $i => $l) { if (preg_match('/^[A-Z]?\d+(\.\d+)?[ .)]/', $l)) { $idx[] = $i; } }
if (count($idx) >= 2) {
    $swap = $lines;
    [$swap[$idx[0]], $swap[$idx[1]]] = [$swap[$idx[1]], $swap[$idx[0]]];
    file_put_contents($mainCopy, implode("\n", $swap));
    is_(filesize($mainCopy) === strlen($served),
        'a reorder in main changes NO bytes — so size cannot be the comparator');
    $reHtml = (string) shell_exec(
        'LGB_MAIN_COPY=' . escapeshellarg($mainCopy) . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');
    is_(str_contains($reHtml, 'class="ahead"'),
        '...and the board still notices it, because it compares content');
} else {
    bad('the backlog has too few items to test the reorder case');
}
@unlink($mainCopy);

section("[6b] THE WRITE ENDPOINT, DRIVEN FOR REAL");

/**
 * Driven over HTTP, not by including the file, because the properties under
 * test ARE the HTTP ones: a header that must be present, a status code that
 * must not read as success, and a body the page's own JavaScript has to be able
 * to tell apart from a save.
 *
 * THE PORT IS KEYED TO THIS PROCESS. One fixed port means any concurrent gate
 * run produces false REDs on a healthy feature — five of them, once, blocking a
 * merge train. Keyed to the pid, two gates can run at the same time.
 *
 * LGB_SOCKET points at a socket that does not exist ON PURPOSE. Everything
 * asserted here happens BEFORE the committer is reached, so a refusal that
 * arrives anyway proves the page refused it — not the service. And a request
 * that gets as far as "not answering" proves the opposite: it passed every
 * check the page makes and was on its way out the door.
 */
$port = 8000 + (getmypid() % 900);
$deadSock = sys_get_temp_dir() . '/lgb-no-such-' . getmypid() . '.sock';
$srvCmd = sprintf(
    'LGB_BACKLOG=%s LGB_SOCKET=%s %s -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $!',
    escapeshellarg($BACK), escapeshellarg($deadSock), escapeshellarg(PHP_BINARY),
    $port, escapeshellarg(dirname($PAGE))
);
$srvPid = (int) trim((string) shell_exec($srvCmd));

$up = false;
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $e, $es, 0.2);
    if ($probe) { fclose($probe); $up = true; break; }
    usleep(100000);
}

/** POST a JSON body and hand back the status and the decoded reply. */
$call = function (array $body, bool $withHeader = true) use ($port): array {
    $hdr = "Content-Type: application/json\r\n" . ($withHeader ? "X-LGB-Write: 1\r\n" : '');
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $hdr, 'content' => json_encode($body),
        'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $raw  = @file_get_contents("http://127.0.0.1:$port/wip-board.php", false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
    }
    return ['code' => $code, 'json' => json_decode((string) $raw, true) ?: [], 'raw' => (string) $raw];
};

if (!$up) {
    bad('the gate could not start a server to drive the write endpoint');
} else {
    $r = $call(['action' => 'note', 'id' => '27', 'text' => 'x'], false);
    is_($r['code'] === 403, 'a write without the board\'s header is refused (403)');
    is_(($r['json']['ok'] ?? null) === false, '...and says so in the body, not just the status');

    $r = $call(['action' => 'burn_it_down']);
    is_($r['code'] === 400, 'an action the board does not offer is refused (400)');

    $r = $call(['action' => 'note', 'id' => '27', 'text' => '   ']);
    is_($r['code'] === 400, 'an empty note is refused before it reaches the committer');

    // The real ids of one project, taken from the page's own render — so this
    // asserts against the board Ian is looking at, not against a fixture.
    $html = render($PAGE, $BACK);
    preg_match_all('/data-id="([^"]*)"\s+data-project="([^"]*)"\s+draggable/', $html, $m, PREG_SET_ORDER);
    $byProj = [];
    foreach ($m as $row) { $byProj[$row[2]][] = $row[1]; }
    $pick = null;
    foreach ($byProj as $k => $ids) { if (count($ids) >= 2) { $pick = [$k, $ids]; break; } }

    if ($pick === null) {
        bad('no project on the board has two draggable rows — nothing to reorder');
    } else {
        [$projKey, $ids] = $pick;
        $swapped = $ids; [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];

        $r = $call(['action' => 'reorder', 'project' => $projKey, 'order' => $swapped]);
        is_(str_contains(strtolower((string) ($r['json']['error'] ?? '')), 'not answering'),
            'a VALID drag passes every check the page makes and reaches the committer');

        // A drag that drops an item must die on the page, without the committer
        // ever being asked — the committer would refuse it too, but a board that
        // forwards obvious nonsense is a board that has stopped checking.
        $short = $swapped; array_pop($short);
        $r = $call(['action' => 'reorder', 'project' => $projKey, 'order' => $short]);
        is_($r['code'] === 409, 'a drag that DROPS an item is refused by the page (409)');
        is_(!str_contains(strtolower((string) ($r['json']['error'] ?? '')), 'not answering'),
            '...before the committer is ever contacted');

        // And one that reaches outside its own project — the property that keeps
        // a drag in Membership from disturbing Guitardle.
        $foreign = null;
        foreach ($byProj as $k => $other) { if ($k !== $projKey && $other !== []) { $foreign = $other[0]; break; } }
        if ($foreign === null) { bad('only one project on the board — cannot test cross-project smuggling'); }
        else {
            $smuggled = $swapped; $smuggled[0] = $foreign;
            $r = $call(['action' => 'reorder', 'project' => $projKey, 'order' => $smuggled]);
            is_($r['code'] === 409, 'a drag carrying another project\'s item is refused (409)');
        }
    }

    /* ---- ADDING AND PROMOTING, from the page ---------------------------
     *
     * Ian: "Could I add things. Add headers and sub items. Or promote sub items
     * to headers." The property that matters here is NOT that the controls
     * render — it is that the page never decides the NUMBER. A number computed
     * on this side would come from whatever copy of the backlog the page is
     * showing, which is exactly how two adds at once collide on one id.
     */
    $r = $call(['action' => 'item_add', 'title' => '   ']);
    is_($r['code'] === 400, 'an item with no title is refused before the committer');

    $r = $call(['action' => 'item_add', 'title' => 'a real new item']);
    is_(str_contains(strtolower((string) ($r['json']['error'] ?? '')), 'not answering'),
        'a titled item passes the page\'s checks and reaches the committer');

    $r = $call(['action' => 'item_promote', 'id' => '4.2']);
    is_(str_contains(strtolower((string) ($r['json']['error'] ?? '')), 'not answering'),
        'a promotion reaches the committer too');

    /* Ian's lane threads share this server — driven here rather than in their
     * own section because [6b] kills the port on its way out, and a section
     * that calls a dead port reports the page as broken when it is not. */
    $r = $call(['action' => 'lane_message', 'lane' => 'keeper', 'text' => '   ']);
    is_($r['code'] === 400, 'an empty lane message is refused before the committer');

    // The page must not be the only thing checking the name — but it must also
    // not forward an obviously hostile one. Either way this must never come
    // back ok, because that name becomes a filename AND a tmux session.
    $r = $call(['action' => 'lane_message', 'lane' => '../../etc/cron.d/x', 'text' => 'x']);
    is_(($r['json']['ok'] ?? null) !== true, 'a path-traversal lane name never comes back ok');
    is_(str_contains((string) ($r['json']['why'] ?? $r['json']['error'] ?? ''), 'not a lane name')
        || str_contains(strtolower((string) ($r['json']['error'] ?? '')), 'not answering'),
        '...and is refused by the NAME fence, not by luck downstream');
}
if ($srvPid > 0) { shell_exec('kill ' . $srvPid . ' 2>/dev/null'); }

section("[6d] TALKING TO A LANE FROM THE BOARD");

/**
 * Ian, 2026-08-16: "I would like to be able to interact with the lanes through
 * the workboard." His half is committed; the lanes' replies come from a relay
 * snapshot. The properties that matter are not that it renders — it is that a
 * message he could not get delivered NEVER reads as sent, and that an absent
 * relay reads as absent rather than as a quiet lane.
 */
/**
 * THE FIXTURE NAMES LANES THE SENTINEL ACTUALLY REPORTS, read at gate time.
 *
 * It used to hardcode two seat names. The fleet then changed — one of them
 * stopped being a seat — so the page rendered no thread for it, and a gate
 * asserting on that thread went RED against a page that was working perfectly.
 * A gate that fails because a lane parked is measuring the BOX, not the page.
 */
$seats = array_values(array_filter(array_column(
    (array) (json_decode((string) @file_get_contents('/home/ubuntu/.sentinel-status.json'), true)['lanes'] ?? []),
    'name')));
if (count($seats) < 2) {
    // Not enough seats to exercise both states — say so rather than assert into a
    // fleet that cannot answer.
    echo "  .. fewer than two seats reported; thread-state legs need two\n";
    $seats = array_pad($seats, 2, $seats[0] ?? 'stripe-membership');
}
[$seatOk, $seatBad] = [$seats[0], $seats[1]];

$thr = $tmp . '-threads.json';
file_put_contents($thr, json_encode(['lanes' => [
    $seatOk  => ['replies' => [['when' => '2026-08-16 11:20', 'text' => 'RELAY FIXTURE REPLY']],
                 'delivery' => ['ok' => true, 'why' => 'delivered', 'when' => '2026-08-16 11:20']],
    $seatBad => ['replies' => [],
                 'delivery' => ['ok' => false, 'why' => 'lane not running — no tmux session', 'when' => '2026-08-16 11:21']],
]]));
$thrHtml = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/main-copy.md LGB_THREADS=' . escapeshellarg($thr)
    . ' LGB_BACKLOG=' . escapeshellarg($BACK) . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');

$boxes = substr_count($thrHtml, 'class="thrbox"');
is_($boxes > 0, sprintf('every named seat carries a thread (%d)', $boxes));
is_(str_contains($thrHtml, 'RELAY FIXTURE REPLY'), 'a lane\'s reply reaches the thread from the relay snapshot');

// THE ONE THAT MATTERS. lane-say exiting non-zero means a lane did not hear
// him; if that renders as anything other than a failure, the board is lying
// about the only thing it was built to tell him.
// Matched on the ELEMENT plus the reason, not on the words. The page's own
// JavaScript comment contains the phrase "NOT DELIVERED", so a bare-string
// check was true on a render with no such banner — the third time tonight this
// gate has caught itself matching prose instead of markup (see `f--bad` in the
// footer, and `hist--none`). If a string can appear in a comment, assert the
// class that can only appear in the output.
is_(str_contains($thrHtml, 'class="thrbox__bad">NOT DELIVERED — lane not running'),
    'a delivery the relay could NOT make is shown as NOT DELIVERED, with the reason');

/**
 * THE GENERAL CHAT — and the reason it needs its own assertions.
 *
 * Ian asked for two chats and was explicit that the general one is "a full
 * surface on the page, not a demoted control". Keeper's extension settled the
 * mechanism: the same thread, aimed at `keeper`.
 *
 * KEEPER IS NOT A LANE. It does not appear in the sentinel's seat list, so if
 * the general chat were rendered by the per-seat loop it would not exist at all
 * — which is exactly how it was built the first time. This asserts it is
 * reachable independently of the seat list.
 */
is_(str_contains($thrHtml, 'class="askk"'), 'the general "Ask keeper" surface is on the page');
// REPOINTED, and this assertion follows it. Ian ruled the general chat ships
// with NO terminal delivery, so the panel no longer routes through the
// lane-thread shape — it addresses the commit-only chat store instead. Keeping
// the old assertion would have held the page to a mechanism its own ruling
// removed.
is_(str_contains($thrHtml, 'data-chat="1"'),
    '...and it is the COMMIT-ONLY chat surface, not a lane thread with a delivery step');

$sentinelLanes = json_decode((string) @file_get_contents('/home/ubuntu/.sentinel-status.json'), true);
$seatNames = array_column((array) ($sentinelLanes['lanes'] ?? []), 'name');
is_(!in_array('keeper', $seatNames, true),
    '...and keeper is NOT a seat, so this cannot have come from the lane loop (' . count($seatNames) . ' seats)');

// SAME RENDERER, SAME FAILURE STATES. Two renderers would drift, and the first
// thing to drift would be the failure states — the whole point of the feature.
$kThr = $tmp . '-threads-k.json';
file_put_contents($kThr, json_encode(['lanes' => ['keeper' => ['replies' => [],
    'delivery' => ['ok' => false, 'why' => 'keeper is not answering', 'when' => '2026-08-16 13:00']]]]));
$kHtml = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/main-copy.md LGB_THREADS=' . escapeshellarg($kThr)
    . ' LGB_BACKLOG=' . escapeshellarg($BACK) . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');
// There is no delivery to fail: a chat message IS a commit. So the general
// chat must carry NO delivery banner at all — its absence is the property now,
// and its presence would mean the relay had crept back into a surface Ian
// ruled must not have one.
is_(!str_contains($kHtml, 'class="thrbox__bad"'),
    'the general chat shows no delivery status — being committed IS being delivered');
@unlink($kThr);

/**
 * WHITESPACE SURVIVES THE ROUND TRIP — Ian's paste-back requirement, gated
 * before the phase that needs it is built.
 *
 * He will paste terminal output into these boxes: stack traces, systemctl
 * status, diffs. All of it is INDENTATION. The store quotes each line with
 * "> ", and the reader must strip exactly that — not a character class.
 * `ltrim($l, '> ')` removes every leading '>' AND every leading space, which
 * deleted 4- and 6-space indents outright. Measured, then fixed.
 */
$wsLane = $tmp . '-ws';
@mkdir($wsLane . '/board-lanes', 0755, true);
copy($BACK, $wsLane . '/BACKLOG.md');
file_put_contents($wsLane . '/board-lanes/stripe-membership.md',
    "# Messages\n\n### 2026-08-16 14:00 — ian-via-board\n\n> \$ systemctl status foo\n>     Active: failed\n>       Loaded: bad\n");
$wsHtml = (string) shell_exec('LGB_MAIN_COPY=/nonexistent/main-copy.md LGB_THREADS=/nonexistent/t.json LGB_BACKLOG='
    . escapeshellarg($wsLane . '/BACKLOG.md') . ' ' . PHP_BINARY . ' ' . escapeshellarg($PAGE) . ' 2>/dev/null');
is_(str_contains($wsHtml, '    Active: failed'),
    'a pasted 4-space indent survives the store round trip');
is_(str_contains($wsHtml, '      Loaded: bad'),
    '...and a 6-space one, so terminal output is not flattened');
// shell_exec, not sh() — that helper lives in the COMMITTER's gate, not this
// one. Copying a line between two gates that look alike is how a gate acquires
// a fatal that only fires after the assertions have already printed ok.
shell_exec('rm -rf ' . escapeshellarg($wsLane));

// An absent relay must read as absent, not as a lane with nothing to say.
$noThrHtml = render($PAGE, $BACK);
is_(str_contains($noThrHtml, 'the relay has not written a snapshot yet'),
    'with no snapshot the board says so, rather than implying a quiet lane');
is_(!str_contains($noThrHtml, 'class="thrbox__bad"'), '...and invents no failures either');
@unlink($thr);



section("[6] THE PAGE WRITES ONLY THROUGH THE COMMITTER");

// Read-only is the property that lets this ship without a flag, so it is
// asserted against the SOURCE rather than trusted. Comments are stripped first
// so prose about writing is not mistaken for a write.
$src  = (string) file_get_contents($PAGE);

// Strip comments, then the INLINE JAVASCRIPT — because this assertion is about
// SERVER-side writes, and a client-side helper called copy() is not one. The
// first version matched the JS and reported a healthy page as writing, which is
// a false positive of exactly the kind that gets an assertion deleted rather
// than fixed. Any <script> containing PHP is left in place and still checked,
// so nothing can hide a write inside one.
$code = (string) preg_replace('!/\*.*?\*/!s', '', $src);
$code = (string) preg_replace('!^\s*//.*$!m', '', $code);
$code = (string) preg_replace_callback(
    '!<script\b[^>]*>(.*?)</script>!s',
    static fn (array $m): string => str_contains($m[1], '<?') ? $m[0] : '<script></script>',
    $code
);

/**
 * PHASE 2 CHANGED WHAT MUST BE TRUE HERE, so this asserts the new property
 * rather than the old one. The page is no longer read-only — it takes a drag, a
 * note and a decision. What makes that safe is not that it writes nothing, but
 * that it writes NOTHING ITSELF: it cannot touch a file, cannot run a command,
 * cannot reach git, and has exactly one way out — the committer's socket.
 */
$fileWrites = [];
foreach ([
    'file_put_contents(', 'unlink(', 'rename(', 'mkdir(', 'touch(', 'copy(',
] as $needle) {
    if (str_contains($code, $needle)) { $fileWrites[] = rtrim($needle, '('); }
}
is_($fileWrites === [], sprintf('the page writes no file itself (%s)',
    $fileWrites === [] ? 'clean' : 'FOUND: ' . implode(', ', $fileWrites)));

$shells = [];
foreach (['shell_exec(', 'exec(', 'system(', 'proc_open(', 'passthru(', 'popen('] as $needle) {
    if (str_contains($code, $needle)) { $shells[] = rtrim($needle, '('); }
}
is_($shells === [], sprintf('...and runs no command — so it can never reach git (%s)',
    $shells === [] ? 'clean' : 'FOUND: ' . implode(', ', $shells)));

// The one door out, named explicitly. A second transport (an HTTP client, a
// second socket) would be a second thing to fence, and nobody would be looking.
is_(substr_count($code, 'stream_socket_client(') === 1,
    'it has exactly one way out — the committer socket');
is_(!preg_match('/curl_exec|curl_init|file_get_contents\s*\(\s*[\'"]https?:/', $code),
    '...and no HTTP client beside it');

// FENCE 2 IS ONLY REAL IF THE ACTOR CANNOT BE POSTED. An actor the page accepts
// from the request is not an identity, it is a text field — and the committer
// would stamp a forged name into the commit and believe it.
is_(str_contains($code, "const LGB_ACTOR"), 'the actor is a server-side constant');
is_(!preg_match('/\$req\[\s*[\'"]actor[\'"]\s*\]/', $code),
    '...and is never read from the request, so it cannot be forged');

is_(!preg_match('/\$_GET/', $code), "it still takes no query input at all — nothing to fuzz");

/**
 * THE PAGE NEVER MINTS A NUMBER. The committer computes the next free id from
 * the file, inside the same read-and-write that commits it. If this page ever
 * computed one, it would be derived from whatever copy of the backlog it
 * happens to be rendering — and two people adding at once would be handed the
 * same id. Asserted against the source because it is a property of the code,
 * not of any one response.
 */
// Scoped to the ADD CALL ITSELF. The first version matched from the string
// "item_add" to the next "'id' =>" anywhere after it — and sailed straight past
// the case boundary into item_promote, which legitimately sends an id. A
// non-greedy match with /s does not respect the boundary you had in mind.
$addCall = preg_match('/\'intent\'\s*=>\s*\'item_add\'.*?\], \$LGB_SOCKET\)/s', $code, $am) ? $am[0] : '';
is_($addCall !== '' && !preg_match('/[\'"]id[\'"]\s*=>/', $addCall),
    'the page sends no id when adding — the committer mints it from the file');
is_(str_contains($src, "action: 'item_add'") && !preg_match('/action:\s*[\'"]item_add[\'"][^}]*\bid:/', $src),
    '...and the client sends none either, only a title and an optional parent');

// The structure controls must appear only where they MEAN something — a
// letter-id item (E1, S2) can be neither promoted nor given sub-items.
is_(str_contains($src, "/^\\d+\\.\\d+$/.test(id)") && str_contains($src, "/^\\d+$/.test(id)"),
    'promote and sub-add are offered by ID SHAPE, so a letter id gets neither');
is_(str_contains($code, 'HTTP_X_LGB_WRITE'), 'a write must carry the board\'s own header');
// The client DOES post now. What must still hold is that it can only post to
// this page — the JS is checked against the raw source here (not the
// comment-stripped copy) so a second endpoint cannot hide in the part §6
// deliberately strips out.
is_(substr_count($src, 'fetch(') === 1 && str_contains($src, 'fetch(location.pathname'),
    "the client posts to this page and nowhere else — checked against the raw source, not the stripped copy");
is_(!preg_match('/XMLHttpRequest|sendBeacon/', $src),
    "...with no second channel hidden in the JS the write check strips");

/* ---------------------------------------------------------------------- */
echo "\n$pass passed, $fail failed\n";
if ($fail > 0) { echo "RED — the work board is not holding.\n"; exit(1); }
echo "GREEN — nothing dropped, letter ids survive, a dead sentinel degrades honestly, "
   . "thresholds are conditional, the shipped archive reaches the board, it says which copy "
   . "it is showing, and every write leaves this page through the committer and nowhere else.\n";
exit(0);

/* ======================================================================= *
 * RED-FIRST RECORD — measured, not asserted. Baseline: 27 passed, 0 failed.
 *
 * Mutations applied to webroot/wip-board.php from a snapshot copy, gate run,
 * count recorded, file restored. Never `git checkout --`.
 *
 *   W1  restore `preg_split('/\R/', …)` — THE BUG AS FIRST WRITTEN, and the
 *       only mutation here that reproduces a real past state      -> 3 RED
 *       Ticked items vanish. §1 names every missing id rather than just
 *       reporting a wrong total, because "5 items became 3" is useless without
 *       knowing WHICH three.
 *   W2  numeric-only item ids                                     -> 3 RED
 *       E1…E5 and S1…S3 disappear, including a security item marked awaiting
 *       Ian. This was also a real bug, caught before shipping.
 *   W3  missing sentinel renders zeros instead of saying so        -> 3 RED
 *       The page invents a load, a disk figure and empty lane lights — the
 *       comforting-zero failure the capacity strip exists to avoid.
 *   W4  disk warning shows unconditionally                         -> 1 RED
 *       A warning that always fires is decoration, and red stops meaning
 *       anything.
 *   W5  add a file_put_contents() to the page                      -> 1 RED
 *   W6  make the missing-backlog path return no error, so the page shows an
 *       empty list instead of saying why                          -> 2 RED
 *       §5b was added AFTER an accidental serve-test rendered "0 items" from a
 *       tree with no docs/. The behaviour was already correct; it simply was
 *       not asserted, so it could have regressed in silence. Same family as the
 *       tick-byte bug: a board showing nothing looks exactly like a board with
 *       nothing to show.
 *       Phase 1's read-only property is what lets it ship without a flag, so
 *       it is asserted rather than assumed.
 *
 * ONE MUTATION FOUND A HOLE IN THIS GATE RATHER THAN THE PAGE. The first cut of
 * §5 matched the bare string "f--bad" — which is also in the STYLESHEET, on
 * every render. So "load is drawn red" and "swap is drawn red" passed on pages
 * with no red bar at all. Only the healthy-box case exposed it, by failing when
 * it should have passed. The assertions now count `class="bar__f f--bad"`, the
 * usage form. An assertion that cannot fail is not an assertion.
 * ======================================================================= */
