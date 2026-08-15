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
    $env = 'LGB_MAIN_COPY=/nonexistent/main-copy.md ';
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
$unsortedRows = preg_match('/proj--unsorted.*?<\/details>/s', $orphaned, $um) ? preg_match_all('/data-item="/', $um[0]) : 0;
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
}
if ($srvPid > 0) { shell_exec('kill ' . $srvPid . ' 2>/dev/null'); }

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
   . "thresholds are conditional, the board says which copy it is showing, and every write "
   . "leaves this page through the committer and nowhere else.\n";
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
