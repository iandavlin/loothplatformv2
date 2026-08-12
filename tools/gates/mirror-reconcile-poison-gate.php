<?php
/**
 * mirror-reconcile-poison-gate.php — one bad row must never wedge the mirror sweep.
 *
 * THE DEFECT CLASS (docs/CRAFT-STANDARD.md: found twice => encode it as a gate).
 *
 * Live, 2026-07-29 23:20 UTC -> 2026-08-09 (11 days). bin/reconcile.php ran its
 * delta walk as a bare `foreach { upsert($id); }` and wrote the last_reconcile_at
 * bookmark AFTER the walk. The bookmark got rewound to 2026-06-01, which widened
 * the walk to include reply 71720 — whose _bbp_topic_id points at an ATTACHMENT.
 * The foreign key threw, nothing caught it, the process died at row 109 of 385,
 * and the bookmark write never ran. Next run: same window, same row, same death.
 * Every 10 minutes, for 11 days, while `systemctl status` said "failed" and no
 * human was looking at that unit.
 *
 * The cost was not the reconcile. Reconcile is the ONLY safety net under a
 * fire-and-forget realtime dispatch, so once it wedged, a dropped sync POST became
 * PERMANENT: 11 of the 70 replies posted in that window (16%) never appeared on
 * the hub, and every one of them was rescued only by its author happening to EDIT
 * the post — up to 2d22h later. Members posted; the hub showed nothing.
 *
 * Second instance, same class, reproduced on dev2 2026-08-09 while fixing the
 * first: with the bookmark rewound the walk died again on a DIFFERENT foreign key
 * (reply_parent_reply_id_fkey, parent 71422 absent). One poisoned row, whole sweep
 * dead — the FK it happens to violate is incidental. That is what makes it a class.
 *
 * WHAT THIS GATE ASSERTS
 *   1. bb_mirror_walk_ids() survives a throwing row: the rest of the list still
 *      runs, and the bad row is RECORDED rather than swallowed.
 *      + negative control: the bare loop the defect shipped dies on the same
 *        input, so a green here means the guard works, not that the fixture is
 *        harmless.
 *   2. reconcile.php's delta walk actually goes THROUGH that helper — no bare
 *      foreach around a materializer. Lexed with token_get_all(), not grepped:
 *      a comment or a docblock quoting the old shape must not satisfy it, and
 *      renaming the loop variable must not evade it.
 *   3. Nothing between the walk and the bookmark write can skip the bookmark:
 *      the ghost sweep, the reply_count rollup and both forum rollups must each
 *      sit inside a try block. Otherwise the wedge simply moves one section over
 *      — the window stops advancing and every later run rewalks a growing set.
 *
 * Exit: 0 green, 1 RED (real finding), 2 CANNOT RUN (no verdict).
 * Run: php tools/gates/mirror-reconcile-poison-gate.php
 */

$root = dirname(__DIR__, 2);
$materializers = "$root/bb-mirror/lib/materializers.php";
$reconcile     = "$root/bb-mirror/bin/reconcile.php";

foreach ([$materializers, $reconcile] as $f) {
    if (!is_file($f) || !is_readable($f)) {
        fwrite(STDERR, "CANNOT RUN: missing or unreadable: $f\n");
        exit(2);
    }
}

$red = [];
function finding(string $m): void { $GLOBALS['red'][] = $m; echo "  RED: $m\n"; }
function ok(string $m): void      { echo "  ok:  $m\n"; }

// ---------------------------------------------------------------------------
// 1. Behaviour: the helper survives a poisoned row.
// ---------------------------------------------------------------------------
echo "1. bb_mirror_walk_ids() survives a throwing row\n";

// materializers.php calls no WP function at load time (it only assumes WP at
// CALL time), so it can be included standalone. It self-guards against a double
// include with LG_BB_MIRROR_MATERIALIZERS_LOADED.
require_once $materializers;

if (!function_exists('bb_mirror_walk_ids')) {
    finding("bb_mirror_walk_ids() is not defined — the poison guard is gone entirely");
    echo "\n############ GATE RED ############\n";
    exit(1);
}

$ids    = [1, 2, 3, 4, 5];
$poison = 3;
$seen   = [];

// The call is wrapped because "the guard stopped guarding" is the defect itself:
// if the exception escapes, that is a FINDING to report at exit 1, not a fatal
// that leaves the gate exiting 255 with a stack trace and no verdict.
$result = ['done' => -1, 'skipped' => []];
try {
    $result = bb_mirror_walk_ids($ids, function (int $id) use ($poison, &$seen) {
        if ($id === $poison) {
            // The real shape: PDO throws a PDOException on an FK violation.
            throw new PDOException('SQLSTATE[23503]: Foreign key violation (simulated)');
        }
        $seen[] = $id;
    });
} catch (Throwable $e) {
    finding("bb_mirror_walk_ids() let the exception ESCAPE (" . get_class($e) . ") — this is the exact shape that wedged live for 11 days");
}

if ($result['done'] !== 4)          finding("walk reported done={$result['done']}, expected 4 (it stopped early)");
else                                ok("walked all 4 healthy rows past the poisoned one");
if ($seen !== [1, 2, 4, 5])         finding("rows actually processed were [" . implode(',', $seen) . "], expected [1,2,4,5]");
else                                ok("every row AFTER the poison still ran");
if (!array_key_exists($poison, $result['skipped']))
                                    finding("the poisoned row was swallowed silently — not in skipped[]");
else                                ok("the poisoned row was recorded, not swallowed");
if (count($result['skipped']) !== 1) finding("skipped[] holds " . count($result['skipped']) . " rows, expected exactly 1");

// NEGATIVE CONTROL. If the bare loop the defect shipped does NOT die on this same
// input, the fixture proves nothing and a green above is decoration.
$control_died = false;
try {
    foreach ($ids as $id) {
        if ($id === $poison) throw new PDOException('SQLSTATE[23503]: Foreign key violation (simulated)');
    }
} catch (Throwable) {
    $control_died = true;
}
if (!$control_died) finding("NEGATIVE CONTROL FAILED: the unguarded loop survived the poison, so this fixture cannot detect the defect");
else                ok("negative control: the unguarded loop dies on the same input");

// ---------------------------------------------------------------------------
// 2. reconcile.php's delta walk goes through the helper (lexed, not grepped).
// ---------------------------------------------------------------------------
echo "\n2. reconcile.php's delta walk uses the guard\n";

$src = file_get_contents($reconcile);
$tokens = token_get_all($src);

/** Code-only token stream: comments and docblocks are dropped, so prose that
 *  quotes the old shape can never satisfy an assertion below. */
$code = [];
foreach ($tokens as $t) {
    if (is_array($t)) {
        if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML], true)) continue;
        $code[] = ['type' => $t[0], 'text' => $t[1], 'line' => $t[2]];
    } else {
        $code[] = ['type' => null, 'text' => $t, 'line' => 0];
    }
}

$call_names = [];   // name => [lines]
foreach ($code as $i => $t) {
    if ($t['type'] === T_STRING && ($code[$i + 1]['text'] ?? '') === '(') {
        $call_names[$t['text']][] = $t['line'];
    }
}

$materializer_calls = ['bb_mirror_upsert_forum', 'bb_mirror_upsert_topic', 'bb_mirror_upsert_reply'];

if (!isset($call_names['bb_mirror_walk_ids'])) {
    finding("reconcile.php never calls bb_mirror_walk_ids() — the delta walk is unguarded again");
} else {
    ok("reconcile.php calls bb_mirror_walk_ids() (line " . implode(', ', $call_names['bb_mirror_walk_ids']) . ")");
}

/** True when token $i sits inside a `foreach (...) { ... }` body. Tracks brace
 *  depth from each foreach's opening brace, so a renamed loop variable or a
 *  reformatted body cannot evade it. */
$foreach_spans = [];
foreach ($code as $i => $t) {
    if ($t['type'] !== T_FOREACH) continue;
    $depth = 0; $j = $i; $started = false;
    for (; $j < count($code); $j++) {
        $x = $code[$j]['text'];
        if ($x === '{') { $depth++; $started = true; }
        elseif ($x === '}') { $depth--; if ($started && $depth === 0) break; }
        elseif ($x === ';' && !$started) break;   // single-statement foreach, no braces
    }
    $foreach_spans[] = [$i, $j];
}

$bare = [];
foreach ($code as $i => $t) {
    if ($t['type'] !== T_STRING || !in_array($t['text'], $materializer_calls, true)) continue;
    if (($code[$i + 1]['text'] ?? '') !== '(') continue;
    foreach ($foreach_spans as [$s, $e]) {
        if ($i > $s && $i < $e) {
            // Inside a foreach is only OK if the call is a closure/arrow-fn
            // ARGUMENT to bb_mirror_walk_ids, never the loop body itself.
            $in_walk = false;
            for ($k = $i; $k > $s; $k--) {
                if (($code[$k]['type'] ?? null) === T_STRING && $code[$k]['text'] === 'bb_mirror_walk_ids') { $in_walk = true; break; }
            }
            if (!$in_walk) $bare[] = $t['text'] . '() at line ' . $t['line'];
        }
    }
}
if ($bare) finding("materializer called from a BARE foreach — one bad row wedges the walk again: " . implode('; ', $bare));
else       ok("no materializer is called from a bare foreach body");

// ---------------------------------------------------------------------------
// 3. Nothing after the walk can skip the bookmark write.
// ---------------------------------------------------------------------------
echo "\n3. the tail steps cannot skip the last_reconcile_at bookmark\n";

/** Depth of enclosing `try` blocks at each token index. */
$try_depth = array_fill(0, count($code), 0);
$open_tries = [];   // stack of brace-depths at which a try body opened
$depth = 0;
foreach ($code as $i => $t) {
    $x = $t['text'];
    if (($t['type'] ?? null) === T_TRY) { $open_tries[] = $depth; }
    if ($x === '{') $depth++;
    if ($x === '}') {
        $depth--;
        while ($open_tries && end($open_tries) >= $depth) { array_pop($open_tries); }
    }
    $try_depth[$i] = count($open_tries);
}

$must_be_guarded = [
    'bb_mirror_sweep_ghosts'             => 'ghost sweep',
    'bb_mirror_refresh_all_reply_counts' => 'reply_count rollup',
];
foreach ($must_be_guarded as $fn => $label) {
    $found = false; $unguarded = [];
    foreach ($code as $i => $t) {
        if (($t['type'] ?? null) !== T_STRING || $t['text'] !== $fn) continue;
        if (($code[$i + 1]['text'] ?? '') !== '(') continue;
        $found = true;
        if ($try_depth[$i] === 0) $unguarded[] = 'line ' . $t['line'];
    }
    if (!$found)          echo "  --   $label ($fn) not present — nothing to guard\n";
    elseif ($unguarded)   finding("$label runs OUTSIDE any try — a throw there skips the bookmark and the window stops advancing (" . implode(', ', $unguarded) . ")");
    else                  ok("$label is inside a try block");
}

// The two recursive rollups are $db->exec() calls, not named functions.
$unguarded_exec = [];
foreach ($code as $i => $t) {
    if (($t['type'] ?? null) !== T_STRING || $t['text'] !== 'exec') continue;
    if (($code[$i - 1]['text'] ?? '') !== '->') continue;
    if ($try_depth[$i] === 0) $unguarded_exec[] = 'line ' . $t['line'];
}
if ($unguarded_exec) finding("a raw ->exec() rollup runs outside any try — same wedge, one section over (" . implode(', ', $unguarded_exec) . ")");
else                 ok("every raw ->exec() rollup is inside a try block");

// And the bookmark write itself must still be there. Lexed for the EXACT string
// literal, because a substring test passes on 'last_reconcile_at_DISABLED' and on
// the SELECT that merely READS the bookmark — both leave the window frozen while
// the gate says fine. (Caught by the red-first harness, which is the point of it.)
$bookmark_literal = false;
foreach ($code as $t) {
    if (($t['type'] ?? null) === T_CONSTANT_ENCAPSED_STRING
        && in_array(trim($t['text'], "'\""), ['last_reconcile_at'], true)) {
        $bookmark_literal = true;
        break;
    }
}
if (!$bookmark_literal) {
    finding("reconcile.php has no 'last_reconcile_at' string literal — the bookmark write is gone and the window can never advance");
} else {
    ok("the last_reconcile_at bookmark write is present");
}

echo "\n";
if ($red) {
    echo "############ GATE RED — " . count($red) . " finding(s) ############\n";
    exit(1);
}
echo "############ GATE GREEN ############\n";
exit(0);
