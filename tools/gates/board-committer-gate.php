<?php
/**
 * GATE (number pending — keeper allocates) — THE BOARD COMMITTER'S FENCES.
 *
 *   php tools/gates/board-committer-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Backlog 29, phase 2. This is the gate keeper named as the condition of the
 * service existing at all: *"an attempted out-of-shape write must be REFUSED
 * and the refusal asserted — that is the gate that makes the service safe to
 * exist."*
 *
 * WHAT IS ACTUALLY BEING PROTECTED. The board is a web page. Phase 2 lets it
 * change files in the monorepo. Everything between those two facts is this
 * service, so the assertions that matter are the REFUSALS — an allowlist is
 * only worth the things it turns away. A gate that only proved the happy path
 * would be certifying that the door opens, having never checked it locks.
 *
 * Runs against a THROWAWAY CLONE built here, so it never touches the real one
 * and never pushes anywhere. Its "origin" is a local bare repo.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$SVC  = $ROOT . '/tools/keeper/board-committer.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function section(string $t): void { echo "\n$t\n"; }
function cannot(string $w): void { echo "CANNOT RUN: $w\n"; exit(3); }

if (!is_readable($SVC)) { cannot("missing $SVC"); }
if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') { cannot('git not available'); }

/* ---------------------------------------------------------------------- *
 * A disposable world: a bare "origin" and a clone the service will write to.
 * ---------------------------------------------------------------------- */
$tmp   = sys_get_temp_dir() . '/bcm-gate-' . getmypid();
$bare  = $tmp . '/origin.git';
$clone = $tmp . '/clone';
@mkdir($tmp, 0755, true);

function sh(string $cmd, ?string $cwd = null): array
{
    $full = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd : $cmd;
    $o = []; $rc = 0; exec($full . ' 2>&1', $o, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $o)];
}

sh('git init -q --bare ' . escapeshellarg($bare));
sh('git init -q ' . escapeshellarg($clone));
@mkdir($clone . '/docs', 0755, true);
@mkdir($clone . '/tools/gates', 0755, true);

// A miniature backlog with the shape the real one has.
file_put_contents($clone . '/docs/BACKLOG.md', <<<MD
# Backlog

## PRIORITY INDEX (the order)

**P0 — live bugs**
4.2 First item — something
4.1 Second item — something else

**P1 — wanted now**
27 Third item — a third thing
E1 Fourth item — a lettered one

---

*details below*
MD);
// The real buck fence, so fence 3 is exercised rather than simulated.
copy($ROOT . '/tools/gates/buck-surface-guard.sh', $clone . '/tools/gates/buck-surface-guard.sh');

sh('git -c user.email=t@t -c user.name=t add -A && git -c user.email=t@t -c user.name=t commit -q -m init', $clone);
sh('git branch -M main', $clone);
sh('git remote add origin ' . escapeshellarg($bare) . ' && git push -q origin main', $clone);

/** Run the service against the throwaway clone. */
function svc(string $svcPath, string $clone, array $payload, bool $dry = false): array
{
    $src  = (string) file_get_contents($svcPath);
    $src  = str_replace("const CLONE_DIR = '/home/ubuntu/board-committer-clone';",
                        "const CLONE_DIR = '" . $clone . "';", $src);
    $src  = str_replace("const AUDIT     = '/home/ubuntu/.board-committer-audit.log';",
                        "const AUDIT     = '" . $clone . "/../audit.log';", $src);
    $tmpf = tempnam(sys_get_temp_dir(), 'bcmrun') . '.php';
    file_put_contents($tmpf, $src);

    $cmd = PHP_BINARY . ' ' . escapeshellarg($tmpf) . ($dry ? ' --dry-run' : '');
    $d = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $p = proc_open($cmd, $d, $pipes);
    fwrite($pipes[0], json_encode($payload)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    @unlink($tmpf);
    return ['rc' => $rc, 'json' => json_decode($out, true) ?: [], 'raw' => $out];
}

function indexIds(string $clone): array
{
    $raw = (string) file_get_contents($clone . '/docs/BACKLOG.md');
    $ids = []; $in = false; $band = false;
    foreach (explode("\n", $raw) as $l) {
        if (!$in) { if (str_starts_with($l, '## PRIORITY INDEX')) { $in = true; } continue; }
        if (str_starts_with($l, '---')) { break; }
        if (preg_match('/^\*\*(.+?)\*\*\s*$/u', $l)) { $band = true; continue; }
        if ($band && preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s/u', $l, $m)) { $ids[] = $m[1]; }
    }
    return $ids;
}

echo "GATE — the board committer's fences\n";
$before = indexIds($clone);
is_($before === ['4.2','4.1','27','E1'], 'the throwaway backlog parses as expected (' . implode(',', $before) . ')');

/* ---------------------------------------------------------------------- */
section("[1] FENCE 1 — ONLY THE THREE SHAPES, AND ONLY INSIDE THE FENCE");

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'write_file',
                        'path' => 'wp-config.php', 'text' => 'x']);
is_(($r['json']['refused'] ?? false) === true, 'an unknown intent is REFUSED');
is_($r['rc'] === 1, '...with a non-zero exit, so a caller cannot read it as success');
is_(str_contains((string) ($r['json']['why'] ?? ''), 'unknown intent'), '...and says which intent it refused');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'note_append',
                        'id' => '../../../etc/passwd', 'text' => 'x']);
is_(($r['json']['refused'] ?? false) === true, 'a traversal dressed as an item id is REFUSED');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'media_ref',
                        'id' => '27', 'ref' => '../../secrets.txt']);
is_(($r['json']['refused'] ?? false) === true, 'a traversal in a media reference is REFUSED');

is_(!is_file($clone . '/wp-config.php'), '...and none of that wrote anything');

/* ---------------------------------------------------------------------- */
section("[2] FENCE 2 — NO WRITE WITHOUT A NAMED ACTOR");

$r = svc($SVC, $clone, ['intent' => 'reorder', 'order' => ['4.1','4.2','27','E1']]);
is_(($r['json']['refused'] ?? false) === true, 'a write with no actor is REFUSED');
$r = svc($SVC, $clone, ['actor' => 'Robert"); DROP', 'intent' => 'reorder', 'order' => ['4.1']]);
is_(($r['json']['refused'] ?? false) === true, 'a junk actor string is REFUSED');

/* ---------------------------------------------------------------------- */
section("[3] REORDER IS A PERMUTATION — a drag cannot add, drop or rename");

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'reorder',
                        'order' => ['4.1','4.2','27']]);          // one short
is_(($r['json']['refused'] ?? false) === true, 'an order MISSING an item is refused');
is_(($r['json']['missing'] ?? []) === ['E1'], '...and names what went missing');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'reorder',
                        'order' => ['4.1','4.2','27','E1','99']]); // one extra
is_(($r['json']['refused'] ?? false) === true, 'an order with an INVENTED item is refused');
is_(($r['json']['unknown'] ?? []) === ['99'], '...and names the invention');

is_(indexIds($clone) === $before, 'after three refusals the file is untouched');

/* ---------------------------------------------------------------------- */
section("[4] THE HAPPY PATH — and it really does reorder");

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'reorder',
                        'order' => ['27','E1','4.2','4.1']]);
is_(($r['json']['ok'] ?? false) === true, 'a valid reorder is applied');
is_(indexIds($clone) === ['27','E1','4.2','4.1'], '...and the file now reads in the new order');
is_(($r['json']['commit'] ?? '') !== '', '...with a commit');

$log = sh('git log -1 --format=%B', $clone)['out'];
is_(str_contains($log, 'ian-via-board'), 'FENCE 2: the actor is stamped IN THE COMMIT, not only the log');
is_(str_contains($log, 'Fences:'), '...and the commit says which fences it passed');

// The line BODIES must be the file's own — a reorder may not rewrite text.
$body = (string) file_get_contents($clone . '/docs/BACKLOG.md');
is_(str_contains($body, '27 Third item — a third thing'),
    'the moved line keeps the FILE\'s text — nothing from the request reaches the document');

/* ---------------------------------------------------------------------- */
section("[5] NOTES AND MEDIA LAND WHERE THEY SHOULD, QUOTED");

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'note_append',
                        'id' => '27', 'text' => "point at the Map for now\n## not a heading"]);
is_(($r['json']['ok'] ?? false) === true, 'a note is appended');
$note = (string) @file_get_contents($clone . '/docs/board-notes/27.md');
is_(str_contains($note, 'point at the Map for now'), '...and contains what was said');
is_(!preg_match('/^## not a heading/m', $note),
    '...but a "## heading" typed into a note cannot forge one — the body is quoted');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'media_ref',
                        'id' => '27', 'ref' => 'board-media/shot-1.png']);
is_(($r['json']['ok'] ?? false) === true, 'a media reference is recorded');
is_(is_file($clone . '/docs/board-media/27.md'), '...in the media file for that item');

/* ---------------------------------------------------------------------- */
section("[6] DRY RUN CHANGES NOTHING");

$sha = trim(sh('git rev-parse HEAD', $clone)['out']);
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'reorder',
                        'order' => ['4.1','4.2','27','E1']], true);
is_(($r['json']['dry_run'] ?? false) === true, 'a dry run reports what it would do');
is_(trim(sh('git rev-parse HEAD', $clone)['out']) === $sha, '...and commits nothing');
is_(trim(sh('git status --porcelain', $clone)['out']) === '', '...and leaves the clone clean');

/* ---------------------------------------------------------------------- */
section("[7] IT REFUSES TO WORK ON A DIRTY OR UNPREPARED CLONE");

file_put_contents($clone . '/docs/BACKLOG.md', "tampered\n", FILE_APPEND);
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'reorder',
                        'order' => ['4.1','4.2','27','E1']]);
is_(($r['json']['ok'] ?? null) !== true, 'a DIRTY clone is refused — no commit is built on unknown changes');
sh('git checkout -- .', $clone);

$r = svc($SVC, sys_get_temp_dir() . '/bcm-nope-' . getmypid(),
         ['actor' => 'ian-via-board', 'intent' => 'reorder', 'order' => ['4.1']]);
is_($r['rc'] === 3, 'a missing clone is CANNOT RUN (3), not a silent success');

/* ---------------------------------------------------------------------- */
section("[8] EVERY OUTCOME IS AUDITED, REFUSALS INCLUDED");

$audit = (string) @file_get_contents($tmp . '/audit.log');
is_(str_contains($audit, 'REFUSED'), 'refusals are audited — they are the interesting ones');
is_(str_contains($audit, 'actor=ian-via-board'), 'the actor is in the audit line');
is_(substr_count($audit, "\n") >= 10, sprintf('every call left a line (%d)', substr_count($audit, "\n")));

/* ---------------------------------------------------------------------- */
section("[9] AN AMBIGUOUS INDEX IS REFUSED, NOT RESOLVED");

/**
 * The regression this exists for, and it is not hypothetical: the index really
 * did carry "9" twice until 2026-08-15. Measured before the fix — the duplicate
 * makes `$rows` keep only the SECOND line while `$slots` keeps both positions,
 * so the permutation check PASSES and the rewrite silently DELETES one item and
 * writes the other twice. A drag that deletes work while reporting success is
 * the worst outcome this service can have, so it is refused outright.
 *
 * Red-first: with the fence removed, assertions 2-4 below go red and the file
 * comes back with the duplicate line doubled and the other item gone.
 */
$bl  = $clone . '/docs/BACKLOG.md';
$was = (string) file_get_contents($bl);
file_put_contents($bl, str_replace("\nE1 ", "\n27 A SECOND ITEM WEARING 27\nE1 ", $was));
sh('git add -A && git -c user.name=t -c user.email=t@t commit -q -m dupe', $clone);
sh('git push -q origin HEAD:main', $clone);

$dupIds = indexIds($clone);
is_(count($dupIds) === 5 && count(array_unique($dupIds)) === 4,
    'the fixture now carries a duplicate id (' . implode(',', $dupIds) . ')');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'reorder',
                        'order' => ['4.1', '4.2', '27', '27', 'E1']]);
is_(($r['json']['refused'] ?? false) === true, 'a reorder against a duplicated id is REFUSED');
is_(in_array('27', (array) ($r['json']['duplicate_ids'] ?? []), true),
    '...and NAMES the duplicate, so it can be renumbered');
$after = indexIds($clone);
is_($after === $dupIds, '...and the index is untouched — nothing was deleted or doubled');

/* Put the fixture back so the audit tallies below are not skewed. */
file_put_contents($bl, $was);
sh('git add -A && git -c user.name=t -c user.email=t@t commit -q -m undupe', $clone);
sh('git push -q origin HEAD:main', $clone);

/* ---------------------------------------------------------------------- */
sh('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
if ($fail > 0) { echo "RED — the committer's fences are not holding.\n"; exit(1); }
echo "GREEN — only three shapes, only inside the fence, never without an actor, "
   . "a drag cannot add or drop work, and every refusal is audited.\n";
exit(0);
