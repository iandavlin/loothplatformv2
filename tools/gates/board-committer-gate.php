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
        // MATCHES THE FILE, not a stricter idea of it. This helper required a
        // SPACE straight after the id, but the real backlog writes "6. Front-end
        // COMPOSE" with a dot — so it silently missed every dotted row. It
        // reported a newly added item as "no row appeared" and a promoted id as
        // "renumbered", two false failures against working code. An independent
        // parse is only worth having if it is also a CORRECT one.
        if ($band && preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+/u', $l, $m)) { $ids[] = $m[1]; }
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
section("[10] MESSAGES TO A LANE — Ian's half, and the name that becomes two dangerous things");

/**
 * Ian, 2026-08-16: "I would like to be able to interact with the lanes through
 * the workboard." Only his half is committed; the lanes' replies are rendered
 * from a snapshot and never enter git.
 *
 * The lane name is checked hard because it is used TWICE and both uses are
 * dangerous: it becomes a filename here, and downstream the relay hands it to
 * lane-say as a TMUX SESSION NAME.
 */
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'lane_message',
                        'lane' => 'stripe-membership', 'text' => 'how is the committer going?']);
is_(($r['json']['ok'] ?? false) === true, 'a message to a real lane is committed');
is_(in_array('docs/board-lanes/stripe-membership.md', (array) ($r['json']['changed'] ?? []), true),
    '...into that lane\'s own file, inside the fence');
$laneFile = (string) @file_get_contents($clone . '/docs/board-lanes/stripe-membership.md');
is_(str_contains($laneFile, '> how is the committer going?'), '...with the body QUOTED, not merged into the document');
is_(str_contains($laneFile, 'ian-via-board'), '...and stamped with who said it');

// The name is the attack surface. Each of these becomes a filename AND a tmux
// session name if it gets through.
foreach ([
    '../../etc/cron.d/x' => 'a path traversal',
    'lane; rm -rf /'     => 'a shell metacharacter',
    'Lane With Caps'     => 'spaces and capitals',
    ''                   => 'an empty name',
] as $bad => $why) {
    $r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'lane_message',
                            'lane' => $bad, 'text' => 'x']);
    // ASSERT THE REASON, NOT MERELY THE REFUSAL. Measured: with the name
    // pattern relaxed to "not empty", the traversal and the metacharacter were
    // STILL refused — by accident, downstream, because the write landed
    // nowhere and the change-enumeration then reported nothing changed. Both
    // assertions stayed green while the fence they name was gone. A red that
    // is red for the wrong reason is a green in disguise.
    is_(($r['json']['refused'] ?? false) === true
        && str_contains((string) ($r['json']['why'] ?? ''), 'not a lane name'),
        'lane name refused BY THE NAME FENCE — ' . $why);
}

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'lane_message',
                        'lane' => 'stripe-membership', 'text' => '   ']);
is_(($r['json']['refused'] ?? false) === true, 'an empty message is refused, not committed as a blank');

// A message carrying backticks must survive INTACT in the store — the relay is
// what keeps it away from a shell, and it cannot do that if the committer has
// already mangled or dropped it.
$tick = 'run `redis-cli ping` and paste the output';
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'lane_message',
                        'lane' => 'keeper', 'text' => $tick]);
is_(($r['json']['ok'] ?? false) === true, 'a message containing backticks is accepted');
$kf = (string) @file_get_contents($clone . '/docs/board-lanes/keeper.md');
is_(str_contains($kf, '`redis-cli ping`'),
    '...and stored VERBATIM — backticks intact, so the relay delivers what he typed');

/* ---------------------------------------------------------------------- */
section("[13] THE CHAT, THE QUESTIONS RAIL, AND THE ONE-ANSWER RULE");

/* --- the general chat: both directions, actor-stamped ------------------- */
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'keeper_message',
                        'text' => "look at this\n    indented line\n      deeper"]);
is_(($r['json']['ok'] ?? false) === true, 'Ian can send a chat message');
$r = svc($SVC, $clone, ['actor' => 'keeper', 'intent' => 'keeper_message', 'text' => 'looking now']);
is_(($r['json']['ok'] ?? false) === true, 'keeper replies through the SAME shape and the same path');
$chat = (string) @file_get_contents($clone . '/docs/board-chat/keeper.md');
is_(str_contains($chat, 'ian-via-board') && str_contains($chat, '— keeper'),
    'both speakers are stamped, so who said what is a property of the repo');
is_(str_contains($chat, ">     indented line"),
    'a pasted indent is stored quoted, one marker deep — terminal output survives');

/* --- open questions: append-only, and that is the whole design ---------- */
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'question_ask',
                        'text' => 'why did we point the archive door at the Map?']);
is_(($r['json']['ok'] ?? false) === true, 'Ian can drop a question the moment it occurs to him');
$q1 = (string) ($r['json']['id'] ?? '');
is_($q1 === 'q1', 'it takes a number from the file (' . $q1 . ')');

$r = svc($SVC, $clone, ['actor' => 'keeper', 'intent' => 'question_ask',
                        'text' => 'filed on his behalf from the VS chat']);
is_(($r['json']['id'] ?? '') === 'q2', 'keeper can FILE a question too, so VS-chat questions stop evaporating');

/**
 * KEEPER'S NAMED ASSERTION: an open question cannot be removed except by
 * gaining an answer. It is enforced by there being NO VERB that removes one —
 * so this checks the whole surface, not just the happy path.
 */
$before = (string) file_get_contents($clone . '/docs/board-questions/questions.md');
foreach ([
    ['intent' => 'question_ask',    'text' => ''],
    ['intent' => 'question_answer', 'id' => 'q99', 'text' => 'answer to nothing'],
    ['intent' => 'question_answer', 'id' => 'not-an-id', 'text' => 'x'],
] as $bad) {
    $r = svc($SVC, $clone, ['actor' => 'keeper'] + $bad);
    is_(($r['json']['refused'] ?? false) === true, 'refused: ' . json_encode($bad['id'] ?? $bad['intent']));
}
$after = (string) file_get_contents($clone . '/docs/board-questions/questions.md');
is_($before === $after, 'and NOTHING those refusals touched changed the store');

$r = svc($SVC, $clone, ['actor' => 'keeper', 'intent' => 'question_answer',
                        'id' => 'q1', 'text' => 'because the Map already had the index']);
is_(($r['json']['ok'] ?? false) === true, 'keeper answers a question');
$qs = (string) file_get_contents($clone . '/docs/board-questions/questions.md');
is_(str_contains($qs, 'why did we point the archive door at the Map?'),
    'THE QUESTION IS STILL THERE after being answered — it gains an answer, it is not consumed');
is_(str_contains($qs, 'answer to q1'), '...and the answer names which question it answers');

/* --- decisions: one store, two doors, FIRST ANSWER WINS ----------------- */
$r = svc($SVC, $clone, ['actor' => 'keeper', 'intent' => 'decision_pose', 'id' => 'aron',
                        'question' => 'What happens to Aron Bach?',
                        'options' => ['Retract to free', '- Give him a grace period', '   ']]);
is_(($r['json']['ok'] ?? false) === true, 'keeper poses a decision with options');
$dec = (string) @file_get_contents($clone . '/docs/board-decisions/aron.md');
is_(substr_count($dec, "\n- ") === 2, 'an option that begins with a dash cannot invent a third option');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'decision_answer',
                        'id' => 'aron', 'choice' => 'Give him a grace period', 'door' => 'desk']);
is_(($r['json']['ok'] ?? false) === true, 'Ian answers it from the desk door');
$dec = (string) file_get_contents($clone . '/docs/board-decisions/aron.md');
is_(str_contains($dec, 'via desk'), '...and the DOOR is recorded, not just the choice');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'decision_answer',
                        'id' => 'aron', 'choice' => 'Retract to free', 'door' => 'vs']);
is_(($r['json']['refused'] ?? false) === true,
    'THE SECOND DOOR IS REFUSED — first answer wins, so the two surfaces cannot disagree');
is_(str_contains((string) file_get_contents($clone . '/docs/board-decisions/aron.md'), 'grace period')
    && !str_contains((string) file_get_contents($clone . '/docs/board-decisions/aron.md'), '> Retract to free'),
    '...and the ruling that stands is the FIRST one, unchanged');

$r = svc($SVC, $clone, ['actor' => 'keeper', 'intent' => 'decision_pose', 'id' => 'aron',
                        'question' => 'asking again', 'options' => ['x']]);
is_(($r['json']['refused'] ?? false) === true, 're-posing an answered decision is refused — it would erase a ruling');

section("[14] THE DOORBELL — it rings once, and never for keeper's own hand");

/**
 * In 56's space deliberately: the doorbell READS the stores this committer
 * writes, so these assertions are the round trip of that contract. If the
 * committer's format and the doorbell's reader ever drift, Ian's questions stop
 * waking anyone and nothing else fails.
 *
 * The store here is the one section [13] has already written through the real
 * committer, so this is not a hand-made fixture pretending to be the format.
 */
$bell = $ROOT . '/tools/keeper/board-doorbell.php';
if (!is_readable($bell)) {
    bad('the doorbell is missing at ' . $bell);
} else {
    $seen = $tmp . '/doorbell-seen';
    @unlink($seen);
    $bsrc = str_replace(
        ["const CLONE_DIR = '/home/ubuntu/board-lane-relay-clone';",
         "const SEEN      = '/home/ubuntu/.board-doorbell-seen';"],
        ["const CLONE_DIR = '" . $clone . "';",
         "const SEEN      = '" . $seen . "';"],
        (string) file_get_contents($bell));
    $btmp = $tmp . '/doorbell.php';
    file_put_contents($btmp, $bsrc);
    sh('git push -q origin HEAD:main', $clone);
    sh('git fetch -q origin', $clone);

    $rings = [];
    for ($i = 0; $i < 5; $i++) {
        $r = sh(PHP_BINARY . ' ' . escapeshellarg($btmp) . ' --once');
        $first = trim(explode("\n", $r['out'])[0] ?? '');
        $rings[] = $first;
        if (str_starts_with($first, 'nothing waiting')) { break; }
    }

    $alerts = array_values(array_filter($rings, static fn (string $l): bool => str_starts_with($l, 'ALERT')));
    is_($alerts !== [], sprintf('the doorbell rings for what the committer wrote (%d alert(s))', count($alerts)));
    is_(count($alerts) === count(array_unique($alerts)),
        'each thing rings ONCE — it remembers, so keeper relaunching does not re-ring the same item');
    is_(str_starts_with((string) end($rings), 'nothing waiting'),
        '...and once everything is rung it goes QUIET, instead of ringing forever');

    $joined = implode(' | ', $alerts);
    is_(str_contains($joined, 'board-question q'),
        '...an unanswered question wakes keeper — the point of the rail');
    is_(str_contains($joined, 'RULED via desk'),
        '...and so does a ruling from the desk door, so keeper acts mid-work rather than idling');

    // KEEPER'S OWN WRITES ARE NOT A DOORBELL. Without this, keeper answering a
    // question wakes keeper, which wakes keeper.
    $r = svc($SVC, $clone, ['actor' => 'keeper', 'intent' => 'decision_pose', 'id' => 'kself',
                            'question' => 'a keeper-posed one', 'options' => ['yes']]);
    $r = svc($SVC, $clone, ['actor' => 'keeper', 'intent' => 'decision_answer',
                            'id' => 'kself', 'choice' => 'yes', 'door' => 'chat']);
    sh('git fetch -q origin', $clone);
    $r = sh(PHP_BINARY . ' ' . escapeshellarg($btmp) . ' --once');
    is_(str_starts_with(trim(explode("\n", $r['out'])[0] ?? ''), 'nothing waiting'),
        "keeper's OWN answer does not ring keeper — no self-wake loop");

    is_(str_contains($r['out'], 'RELAUNCH NOW'),
        'every exit carries the relaunch order, so a wake-up cannot be separated from re-arming');

    // It must never write the repo — it fetches and reads blobs, nothing else.
    // COMMENTS STRIPPED FIRST. The doorbell's own docblock says "no checkout, no
    // reset" and "safe beside the committer mid-write" — so a raw match found
    // every word it was looking for and called a read-only tool a writer. This
    // is the SEVENTH time this session an assertion has matched prose instead of
    // code; gate 50 already strips comments before its write check, and this one
    // now does the same.
    $bcode = (string) preg_replace('!/\*.*?\*/!s', '', $bsrc);
    $bcode = (string) preg_replace('!^\s*//.*$!m', '', $bcode);
    is_(!preg_match('/reset --hard|git checkout|git commit|git push/', $bcode),
        'the doorbell never writes the repo — fetch and read only, safe beside a working clone');
}

section("[12] ADDING AND PROMOTING — position is rank, number is a permanent name");

/**
 * Ian, 2026-08-16: "Could I add things. Add headers and sub items. Or promote
 * sub items to headers." Keeper's design note is the invariant everything here
 * checks: POSITION IS RANK, NUMBER IS A PERMANENT NAME — so an add or a
 * promotion must NEVER renumber an existing item.
 */
$idsBefore = indexIds($clone);
$bodiesBefore = [];
foreach (explode("\n", (string) file_get_contents($clone . '/docs/BACKLOG.md')) as $l) {
    if (preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+(.*)$/u', $l, $m)) { $bodiesBefore[$m[1]] = $m[2]; }
}

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_add',
                        'title' => 'A brand new thing Ian typed']);
is_(($r['json']['ok'] ?? false) === true, 'a new top-level item is added');
is_(($r['json']['id'] ?? '') === '28',
    'it takes the next free number FROM THE FILE (got ' . (string) ($r['json']['id'] ?? '-') . ', expected 28)');

$idsAfter = indexIds($clone);
$bodiesAfter = [];
foreach (explode("\n", (string) file_get_contents($clone . '/docs/BACKLOG.md')) as $l) {
    if (preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+(.*)$/u', $l, $m)) { $bodiesAfter[$m[1]] = $m[2]; }
}

// THE INVARIANT, stated as a comparison rather than a hope.
$lost = array_diff($idsBefore, $idsAfter);
is_($lost === [], 'NOTHING was renumbered or dropped — every id that existed still does'
    . ($lost === [] ? '' : ' (lost: ' . implode(',', $lost) . ')'));
$changed = [];
foreach ($bodiesBefore as $id => $body) {
    if (($bodiesAfter[$id] ?? null) !== $body) { $changed[] = $id; }
}
is_($changed === [], 'ADDITIVE ONLY — not one existing line was edited'
    . ($changed === [] ? '' : ' (changed: ' . implode(',', $changed) . ')'));
is_(count($idsAfter) === count($idsBefore) + 1, 'exactly one row appeared');
is_(str_contains((string) ($bodiesAfter['28'] ?? ''), 'A brand new thing Ian typed'), 'and it carries his words');

/* --- the sub-item, and the decimal trap ------------------------------- */
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_add',
                        'parent' => '4', 'title' => 'a child of four']);
is_(($r['json']['id'] ?? '') === '4.3',
    'a sub-item takes the next child of its parent (got ' . (string) ($r['json']['id'] ?? '-') . ', expected 4.3)');

/**
 * THE DECIMAL TRAP, exercised rather than described. The real backlog carries
 * `3.10`, and `(float) "3.10" === (float) "3.1"` — so any numeric handling of an
 * id merges the two, and "next child" computed by adding 0.1 hands out a number
 * that already exists. With integer arithmetic on the part after the dot, the
 * child after 3.10 is 3.11.
 */
$bl = $clone . '/docs/BACKLOG.md';
$raw = (string) file_get_contents($bl);
file_put_contents($bl, str_replace("\nE1 ", "\n3.9 nine\n3.10 ten\nE1 ", $raw));
sh('git add -A && git -c user.name=t -c user.email=t@t commit -q -m dec && git push -q origin HEAD:main', $clone);

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_add',
                        'parent' => '3', 'title' => 'the one after ten']);
is_(($r['json']['id'] ?? '') === '3.11',
    'the child after 3.10 is 3.11, NOT 3.2 — ids are dotted integers, not decimals (got '
    . (string) ($r['json']['id'] ?? '-') . ')');
is_(!in_array('3.11', $idsBefore, true) && in_array('3.10', indexIds($clone), true),
    '...and 3.10 is still there, not merged with 3.1');

/* --- a title cannot forge structure ----------------------------------- */
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_add',
                        'title' => "first line\n99 a forged row\n**A FORGED BAND**"]);
is_(($r['json']['ok'] ?? false) === true, 'a multi-line title is accepted');
$after = indexIds($clone);
is_(!in_array('99', $after, true), '...but cannot forge a second index row');
// The forged text DOES appear — inside the title, where it is just words. The
// property is that it is not a BAND, and a band is a line that is bold from
// start to end. Asserting the substring was asserting the wrong thing.
$bandLines = 0;
foreach (explode("\n", (string) file_get_contents($bl)) as $l) {
    if (preg_match('/^\*\*(.+?)\*\*\s*$/u', $l) && str_contains($l, 'FORGED')) { $bandLines++; }
}
is_($bandLines === 0, '...and cannot forge a band header, however the title is written');

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_add', 'title' => '   ']);
is_(($r['json']['refused'] ?? false) === true, 'an empty title is refused');

/* --- promotion --------------------------------------------------------- */
$idsBefore = indexIds($clone);
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_promote', 'id' => '4.2']);
is_(($r['json']['ok'] ?? false) === true, 'a sub-item can be promoted');
$newId = (string) ($r['json']['id'] ?? '');
$file  = (string) file_get_contents($bl);

is_($newId !== '' && str_contains($file, $newId . '. ' . ($bodiesBefore['4.2'] ?? 'x')),
    'the content moved VERBATIM to the new number');
is_((bool) preg_match('/^4\.2\.\s+→ promoted to ' . preg_quote($newId, '/') . '$/m', $file),
    'the old number SURVIVES as a pointer — a three-month-old reference still lands somewhere true');
$lost = array_diff($idsBefore, indexIds($clone));
is_($lost === [], 'and nothing was renumbered by the promotion'
    . ($lost === [] ? '' : ' (lost: ' . implode(',', $lost) . ')'));

$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_promote', 'id' => '4.2']);
is_(($r['json']['refused'] ?? false) === true, 'promoting the same sub-item twice is refused');
$r = svc($SVC, $clone, ['actor' => 'ian-via-board', 'intent' => 'item_promote', 'id' => '27']);
is_(($r['json']['refused'] ?? false) === true, 'a top-level item cannot be "promoted"');

section("[11] DELIVERY RECEIPTS — the shape that makes the relay idempotent");

/**
 * Keeper's ruling, 2026-08-16. The receipt is what lets the relay survive a
 * crash without duplicating or looping, so its fences matter as much as the
 * message's: a forged or malformed receipt would either suppress a real
 * delivery or let one repeat forever.
 */
$r = svc($SVC, $clone, ['actor' => 'board-relay', 'intent' => 'lane_receipt',
                        'lane' => 'stripe-membership', 'id' => '001-a1b2c3d4',
                        'outcome' => 'delivered', 'why' => 'delivered']);
is_(($r['json']['ok'] ?? false) === true, 'a well-formed receipt is committed');
is_(in_array('docs/board-lanes/stripe-membership.receipts.md', (array) ($r['json']['changed'] ?? []), true),
    '...into the lane\'s receipt file, inside the same fence');

$rf = (string) @file_get_contents($clone . '/docs/board-lanes/stripe-membership.receipts.md');
is_(str_contains($rf, '001-a1b2c3d4') && str_contains($rf, 'delivered'),
    '...naming the message and the outcome, so the relay can read it back');

foreach ([
    ['id' => 'not-an-id',       'outcome' => 'delivered', 'why' => 'a malformed message id'],
    ['id' => '001-a1b2c3d4',    'outcome' => 'maybe',     'why' => 'an outcome that is neither delivered nor failed'],
] as $bad) {
    $r = svc($SVC, $clone, ['actor' => 'board-relay', 'intent' => 'lane_receipt',
                            'lane' => 'stripe-membership', 'id' => $bad['id'],
                            'outcome' => $bad['outcome'], 'why' => 'x']);
    is_(($r['json']['refused'] ?? false) === true, 'receipt refused — ' . $bad['why']);
}

// The lane name is the same two-headed hazard here as on a message.
$r = svc($SVC, $clone, ['actor' => 'board-relay', 'intent' => 'lane_receipt',
                        'lane' => '../../etc/x', 'id' => '001-a1b2c3d4', 'outcome' => 'delivered', 'why' => 'x']);
is_(($r['json']['refused'] ?? false) === true
    && str_contains((string) ($r['json']['why'] ?? ''), 'not a lane name'),
    'receipt refused BY THE NAME FENCE for a path-traversal lane');

/**
 * The reason comes from a SUBPROCESS'S STDERR, so it is the one field an
 * attacker or an accident controls the shape of. A receipt file whose rows can
 * span lines is a receipt file the relay cannot parse back — and a receipt it
 * cannot parse reads as "never delivered", which means the message repeats.
 */
$r = svc($SVC, $clone, ['actor' => 'board-relay', 'intent' => 'lane_receipt',
                        'lane' => 'stripe-membership', 'id' => '002-b2c3d4e5',
                        'outcome' => 'failed', 'why' => "line one\nline two\n- 2026-01-01 · 999-ffffffff · delivered"]);
is_(($r['json']['ok'] ?? false) === true, 'a multi-line failure reason is accepted');
$rf = (string) @file_get_contents($clone . '/docs/board-lanes/stripe-membership.receipts.md');
$rows = 0;
foreach (explode("\n", $rf) as $l) { if (str_starts_with(trim($l), '- ')) { $rows++; } }
is_($rows === 2, sprintf('...but flattened to ONE row, so it cannot forge a second receipt (%d rows)', $rows));

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
// Counted as a DELTA, not against a fixed total. This asserted "exactly 5 ids,
// 4 distinct" — true only while no earlier section had added anything to the
// shared fixture. The moment one did, a working duplicate-fence read as broken.
// An assertion that depends on what ran before it is an assertion that will
// fail for a reason that has nothing to do with its subject.
$dupCount = count($dupIds) - count(array_unique($dupIds));
is_($dupCount === 1 && count(array_keys($dupIds, '27')) === 2,
    'the fixture now carries exactly one duplicated id, and it is 27 (' . implode(',', $dupIds) . ')');

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
echo "GREEN — only the allowlisted shapes, only inside the fence, never without an actor, "
   . "a drag cannot add or drop work, and every refusal is audited.\n";
exit(0);
