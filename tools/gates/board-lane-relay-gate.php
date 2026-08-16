#!/usr/bin/env php
<?php
/**
 * GATE — the board→lane relay.
 *
 * Ian, 2026-08-16: "I would like to be able to interact with the lanes through
 * the workboard." This gate exists for the four properties that decide whether
 * that is safe, and every one of them is a lesson this box has already paid for:
 *
 *   1. THE MESSAGE NEVER TOUCHES A SHELL. Backticks in a board message have been
 *      command-substituted away twice here, once eating a redis-cli recovery
 *      command. Ian pastes commands into chats constantly.
 *   2. DELIVERY IS IDEMPOTENT ACROSS A CRASH (keeper's ruling): a receipted
 *      message is never sent twice, and a crash re-delivers at most ONCE.
 *   3. ONE BAD MESSAGE CANNOT WEDGE THE QUEUE — the shape that stopped
 *      bb-mirror-reconcile for 11 days on a single poisoned row.
 *   4. A FAILURE IS VISIBLE. lane-say exiting non-zero means a lane did not
 *      hear him, and that must never render as sent.
 *
 * Everything runs against a THROWAWAY clone with a local bare origin, a FAKE
 * lane-say that records exactly what it was handed, and a temp snapshot path.
 * It never touches the real clone, the real fleet, or the real board.
 *
 * WHAT THE FAKE lane-say DOES NOT COVER, stated so nobody mistakes this gate for
 * proof of the whole chain: it proves the relay hands the message over as a
 * FILE and never on a command line, but it cannot prove what the real lane-say
 * then does with those bytes. That last link was verified BY HAND on
 * 2026-08-16, against the real script and a throwaway tmux session: a message
 * reading "run `redis-cli ping` and $(whoami) then paste it back" arrived in the
 * pane verbatim, backticks intact and $() unexecuted, exit 0. The mechanism is
 * `send-keys -l` (literal) with the variable quoted, and `printf '%s'`
 * throughout — no eval, no unquoted expansion. Re-verify by hand if lane-say's
 * delivery mechanics ever change; a gate cannot spawn a real seat.
 *
 * Exit 0 green · 1 a property is broken · 3 cannot run.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function section(string $t): void { echo "\n$t\n"; }
function cannot(string $w): void { echo "CANNOT RUN: $w\n"; exit(3); }

$ROOT  = dirname(__DIR__, 2);
$RELAY = $ROOT . '/tools/keeper/board-lane-relay.php';
$SVC   = $ROOT . '/tools/keeper/board-committer.php';
foreach ([$RELAY, $SVC] as $f) { if (!is_readable($f)) cannot("missing $f"); }

function sh(string $cmd, ?string $cwd = null): array
{
    $full = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd : $cmd;
    $out = []; $rc = 0; exec($full . ' 2>&1', $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
}

/* ---- the sandbox ------------------------------------------------------- */
$tmp   = sys_get_temp_dir() . '/blr-gate-' . getmypid();
$bare  = $tmp . '/origin.git';
$clone = $tmp . '/clone';
$ccl   = $tmp . '/committer-clone';
$log   = $tmp . '/lane-say.log';
$snap  = $tmp . '/threads.json';
$fake  = $tmp . '/lane-say.sh';
sh('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0755, true);

sh('git init -q --bare ' . escapeshellarg($bare));
sh('git init -q ' . escapeshellarg($clone));
@mkdir($clone . '/docs/board-lanes', 0755, true);
@mkdir($clone . '/tools/gates', 0755, true);
// The committer runs a buck fence before every commit; give the sandbox one
// that passes, so this gate measures the relay and not that.
file_put_contents($clone . '/tools/gates/buck-surface-guard.sh', "#!/usr/bin/env bash\nexit 0\n");
file_put_contents($clone . '/docs/BACKLOG.md', "## PRIORITY INDEX\n\n**P0 — now**\n1 a thing\n---\n");
sh('git add -A && git -c user.name=t -c user.email=t@t commit -q -m init && git remote add origin '
   . escapeshellarg($bare) . ' && git push -q origin HEAD:main', $clone);
sh('git clone -q ' . escapeshellarg($bare) . ' ' . escapeshellarg($ccl));

/**
 * A FAKE lane-say that records EXACTLY what it was handed — its whole command
 * line, and the bytes of the file it was pointed at. That is what makes the
 * "never touches a shell" property measurable rather than asserted.
 * LANE_SAY_FAIL makes it exit 1, so the failure paths are exercised for real.
 */
file_put_contents($fake, <<<'SH'
#!/usr/bin/env bash
LOG="${LANE_SAY_LOG:?}"
{ echo "ARGV: $*"; } >> "$LOG"
f=""; while [ $# -gt 0 ]; do case "$1" in -f) f="$2"; shift 2;; *) shift;; esac; done
if [ -n "$f" ]; then { echo "FILE<<"; cat "$f"; echo; echo ">>FILE"; } >> "$LOG"; fi
[ -n "${LANE_SAY_FAIL:-}" ] && { echo "lane-say: no session"; exit 1; }
exit 0
SH);
chmod($fake, 0755);

/** Run the relay with every path pointed at the sandbox. */
function relay(string $relaySrc, array $paths, bool $dry = false, bool $failMode = false): array
{
    $src = (string) file_get_contents($relaySrc);
    foreach ($paths as $const => $val) {
        $src = preg_replace('/const\s+' . $const . '\s*=\s*[^;]+;/',
                            'const ' . $const . " = '" . $val . "';", $src, 1);
    }
    $f = tempnam(sys_get_temp_dir(), 'blrrun') . '.php';
    file_put_contents($f, $src);
    $env = 'LANE_SAY_LOG=' . escapeshellarg($paths['__log']) . ' ' . ($failMode ? 'LANE_SAY_FAIL=1 ' : '');
    $r = sh($env . PHP_BINARY . ' ' . escapeshellarg($f) . ($dry ? ' --dry-run' : ''));
    @unlink($f);
    return $r;
}

$paths = ['CLONE_DIR' => $clone, 'COMMITTER' => $SVC, 'LANE_SAY' => $fake,
          'SNAPSHOT' => $snap, 'DEVMSG_DB' => '/nonexistent/devmsg.db', '__log' => $log];

/**
 * The committer writes to ITS clone, so point a copy of it at the sandbox's —
 * the same technique the committer's own gate uses.
 */
$svcSrc = str_replace(
    ["const CLONE_DIR = '/home/ubuntu/board-committer-clone';",
     "const AUDIT     = '/home/ubuntu/.board-committer-audit.log';"],
    ["const CLONE_DIR = '" . $ccl . "';",
     "const AUDIT     = '" . $tmp . "/audit.log';"],
    (string) file_get_contents($SVC));
$svcTmp = $tmp . '/committer.php';
file_put_contents($svcTmp, $svcSrc);
$paths['COMMITTER'] = $svcTmp;

/** Put a message in the lane's file, as the committer would have. */
function postMessage(string $clone, string $bare, string $lane, string $text): void
{
    $f = $clone . '/docs/board-lanes/' . $lane . '.md';
    if (!is_file($f)) { file_put_contents($f, "# Messages to $lane\n"); }
    file_put_contents($f, sprintf("\n### %s — ian-via-board\n\n> %s\n",
        gmdate('Y-m-d H:i:s'), str_replace("\n", "\n> ", trim($text))), FILE_APPEND);
    sh('git add -A && git -c user.name=t -c user.email=t@t commit -q -m msg && git push -q origin HEAD:main', $clone);
}

echo "GATE — the board→lane relay\n";

/* ---------------------------------------------------------------------- */
section("[1] THE MESSAGE NEVER TOUCHES A SHELL");

$danger = 'run `redis-cli ping` and $(whoami) then paste it back';
postMessage($clone, $bare, 'stripe-membership', $danger);
$r = relay($RELAY, $paths);
$logged = (string) @file_get_contents($log);

is_(str_contains($logged, 'ARGV:'), 'the relay called lane-say at all');
is_(!str_contains((string) preg_replace('/^FILE<<.*?>>FILE$/ms', '', $logged), 'redis-cli'),
    'the message text is NOT on the command line');
is_(str_contains($logged, '-f '), '...it was handed over with -f, from a file');
is_(str_contains($logged, $danger),
    '...and arrived VERBATIM — backticks and $() intact, exactly as he typed them');

/* ---------------------------------------------------------------------- */
section("[2] DELIVERY IS IDEMPOTENT — a receipted message is never sent twice");

sh('git -C ' . escapeshellarg($clone) . ' fetch -q origin && git -C ' . escapeshellarg($clone) . ' reset -q --hard origin/main');
$before = substr_count((string) file_get_contents($log), 'ARGV:');
$r = relay($RELAY, $paths);
$after = substr_count((string) file_get_contents($log), 'ARGV:');
is_($after === $before, sprintf('a second pass delivers NOTHING new (%d calls before, %d after)', $before, $after));
is_(str_contains($r['out'], 'already receipted'), '...and says it skipped an already-receipted message');

$receipts = (string) @file_get_contents($ccl . '/docs/board-lanes/stripe-membership.receipts.md');
is_(str_contains($receipts, 'delivered'), 'the receipt was COMMITTED, not kept in local state');

/* ---------------------------------------------------------------------- */
section("[3] A CRASH BETWEEN SEND AND RECEIPT RE-DELIVERS ONCE, NEVER LOOPS");

/**
 * Simulated by deleting the receipt — which is exactly the state a crash
 * between `lane-say` returning and the receipt commit would leave behind.
 */
sh('rm -f docs/board-lanes/stripe-membership.receipts.md && git add -A && '
 . 'git -c user.name=t -c user.email=t@t commit -q -m drop && git push -q origin HEAD:main', $ccl);
sh('git fetch -q origin && git reset -q --hard origin/main', $clone);

$before = substr_count((string) file_get_contents($log), 'ARGV:');
relay($RELAY, $paths);
$mid = substr_count((string) file_get_contents($log), 'ARGV:');
is_($mid === $before + 1, sprintf('the lost receipt causes exactly ONE re-delivery (%d → %d)', $before, $mid));

sh('git fetch -q origin && git reset -q --hard origin/main', $clone);
relay($RELAY, $paths);
$end = substr_count((string) file_get_contents($log), 'ARGV:');
is_($end === $mid, 'and the pass after that delivers nothing — it does not loop');

/* ---------------------------------------------------------------------- */
section("[4] AN UNDELIVERABLE MESSAGE CANNOT WEDGE THE QUEUE");

postMessage($clone, $bare, 'guitardle-fairness', 'this one cannot be delivered');
sh('git fetch -q origin && git reset -q --hard origin/main', $clone);

for ($i = 1; $i <= 4; $i++) {
    relay($RELAY, $paths, false, true);   // lane-say fails every time
    sh('git fetch -q origin && git reset -q --hard origin/main', $clone);
}
$gr = (string) @file_get_contents($ccl . '/docs/board-lanes/guitardle-fairness.receipts.md');
$failures = substr_count($gr, 'failed');
is_($failures > 0, sprintf('a failed delivery is RECEIPTED, not silently retried forever (%d)', $failures));
is_($failures <= 3, sprintf('...and attempts are CAPPED — it gives up rather than wedging (%d ≤ 3)', $failures));

// The queue must still move for everyone else.
postMessage($clone, $bare, 'stripe-membership', 'a later message behind the stuck one');
sh('git fetch -q origin && git reset -q --hard origin/main', $clone);
$before = substr_count((string) file_get_contents($log), 'ARGV:');
relay($RELAY, $paths);
$after = substr_count((string) file_get_contents($log), 'ARGV:');
is_($after > $before, 'a message BEHIND the undeliverable one still goes out');

/* ---------------------------------------------------------------------- */
section("[5] A FAILURE IS VISIBLE ON THE BOARD, NEVER SILENT");

$snapRaw = json_decode((string) @file_get_contents($snap), true);
is_(is_array($snapRaw) && isset($snapRaw['lanes']), 'the relay wrote a snapshot the board can read');
$d = $snapRaw['lanes']['guitardle-fairness']['delivery'] ?? null;
is_(is_array($d) && ($d['ok'] ?? true) === false,
    'the lane that could not be reached is marked NOT ok in the snapshot');
is_(is_array($d) && trim((string) ($d['why'] ?? '')) !== '',
    '...with a reason, so the board can say WHY rather than just "failed"');
is_((fileperms($snap) & 0044) === 0044,
    'the snapshot is readable by the web user — the board is served by a pool user that is NOT in the devmsg group');

/* ---------------------------------------------------------------------- */
section("[6] DRY RUN TOUCHES NOTHING");

postMessage($clone, $bare, 'stripe-membership', 'dry run must not send this');
sh('git fetch -q origin && git reset -q --hard origin/main', $clone);
$before  = substr_count((string) file_get_contents($log), 'ARGV:');
$rBefore = (string) @file_get_contents($ccl . '/docs/board-lanes/stripe-membership.receipts.md');
$r = relay($RELAY, $paths, true);
$after   = substr_count((string) file_get_contents($log), 'ARGV:');
sh('git fetch -q origin && git reset -q --hard origin/main', $ccl);
$rAfter  = (string) @file_get_contents($ccl . '/docs/board-lanes/stripe-membership.receipts.md');

is_($after === $before, 'a dry run delivers NOTHING');
is_($rBefore === $rAfter, '...and commits no receipt');
is_(str_contains($r['out'], '[dry run]'), '...and says so, so a rehearsal is never mistaken for a pass');

/* ---------------------------------------------------------------------- */
section("[8] IF IT CANNOT RECEIPT, IT DELIVERS NOTHING");

/**
 * The hole this closes was found by driving the REAL socket, not this fake one:
 * the deployed committer did not yet allow `lane_receipt`, and this relay
 * delivers first and receipts second. A receipt that can never commit leaves the
 * message with no record, so the next pass sends it again — and the next.
 * Keeper's requirement is "at worst re-deliver ONCE, never loop", and without a
 * preflight a committer that refuses receipts turns every message into an
 * unbounded repeat.
 */
$oldSvc = (string) file_get_contents($svcTmp);
file_put_contents($svcTmp, str_replace("'lane_receipt'", "'lane_receipt_DISABLED'", $oldSvc));

postMessage($clone, $bare, 'stripe-membership', 'must not be delivered without a receipt');
sh('git fetch -q origin && git reset -q --hard origin/main', $clone);
$before = substr_count((string) file_get_contents($log), 'ARGV:');
$r = relay($RELAY, $paths);
$after = substr_count((string) file_get_contents($log), 'ARGV:');

is_($after === $before, sprintf('with receipts refused, NOTHING is delivered (%d calls before, %d after)', $before, $after));
is_($r['rc'] === 3, '...and it reports CANNOT RUN rather than pretending it worked');
is_(str_contains($r['out'] . $r['out'], 'receipt') || str_contains($r['out'], 'repeats'),
    '...naming receipts as the reason, so the operator knows what to fix');

file_put_contents($svcTmp, $oldSvc);

/**
 * AND THE PROBE ITSELF MUST COMMIT NOTHING. It did: `dry_run` in the request
 * body is the LISTENER's contract, and this relay calls the committer directly,
 * so the flag was ignored and every pass pushed a `preflight` receipt to main.
 * At the proposed 30s cadence that is ~2,880 junk commits a day.
 */
sh('git fetch -q origin && git reset -q --hard origin/main', $ccl);
$r = relay($RELAY, $paths);
sh('git fetch -q origin && git reset -q --hard origin/main', $ccl);
is_(!is_file($ccl . '/docs/board-lanes/preflight.receipts.md'),
    'the capability probe commits NOTHING — no preflight receipt is ever pushed');

/* ---------------------------------------------------------------------- */
section("[9] IAN'S DESK IS DERIVED FROM THE STORE, NOT FROM ANYONE'S DILIGENCE");

/**
 * Ian: "are you hand populating my desk? Is there a way to do it mechanically?"
 * Two of featured-members' posts to him went missing the same day purely
 * because a hand lagged. The desk should BE the store.
 *
 * The board cannot read the message database itself — it is served by a pool
 * user that is not in the `devmsg` group and must not be, since that group has
 * WRITE. So desk items ride the snapshot that already carries the replies.
 */
$deskDb = $tmp . '/desk.db';
$pdo = new PDO('sqlite:' . $deskDb);
$pdo->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, sender TEXT, recipient TEXT, body TEXT, ts INTEGER, read_ts INTEGER)');
$ins = $pdo->prepare('INSERT INTO messages (sender,recipient,body,ts) VALUES (?,?,?,?)');
$ins->execute(['ubuntu', 'ubuntu', 'featured-members -> Ian: one ruling needed on the digest floor', 1000]);
$ins->execute(['ubuntu', 'ubuntu', 'stripe-membership -> keeper: a status post, NOT for his desk', 1001]);
$ins->execute(['ubuntu', 'ubuntu', 'guitardle-fairness -> keeper: mentions Ian but is addressed to keeper', 1002]);
$ins->execute(['ubuntu', 'ubuntu', 'dark-anon-sweep -> Ian: the white search panel in dark mode', 1003]);

$dpaths = $paths; $dpaths['DEVMSG_DB'] = $deskDb;
relay($RELAY, $dpaths);
$snapD = json_decode((string) @file_get_contents($snap), true);
$desk  = (array) ($snapD['desk'] ?? []);

is_(count($desk) === 2, sprintf('a lane\'s "-> Ian" post reaches the desk (%d of 2)', count($desk)));
$who = array_column($desk, 'who');
is_(in_array('featured-members', $who, true),
    '...including the one that went missing when a hand lagged');
is_(!in_array('stripe-membership', $who, true),
    'a post addressed to KEEPER is not a desk item — his desk is what waits on HIM');
is_(!in_array('guitardle-fairness', $who, true),
    '...and merely MENTIONING him does not put it there, or the desk becomes noise he skims');
$txt = implode(' | ', array_column($desk, 'text'));
is_(str_contains($txt, 'digest floor') && !str_contains($txt, '-> Ian:'),
    '...and the item carries his message, not the addressing preamble');

/* ---------------------------------------------------------------------- */
section("[10] DESK ITEMS RETIRE MECHANICALLY — nothing waits to be noticed");

/**
 * Backlog 41(b), Ian: "completed work still listed on my desk."
 *
 * An item leaves when a FACT says so — its decision was answered, its work
 * landed, or he dismissed it — never when somebody remembers to remove it.
 * Backlog 18 is the cautionary case: a day marked UNOWNED after he had used the
 * finished feature, because noticing was the only mechanism.
 */
$rdb = $tmp . '/retire.db';
$rp = new PDO('sqlite:' . $rdb);
$rp->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, sender TEXT, recipient TEXT, body TEXT, ts INTEGER, read_ts INTEGER)');
$ri = $rp->prepare('INSERT INTO messages (sender,recipient,body,ts) VALUES (?,?,?,?)');
$posted = 1000000;
$ri->execute(['ubuntu', 'ubuntu', 'seat-decided -> Ian: which way?',   $posted]);
$ri->execute(['ubuntu', 'ubuntu', 'seat-open -> Ian: still waiting',   $posted]);

// its decision is answered AFTER the post
@mkdir($clone . '/docs/board-decisions', 0755, true);
file_put_contents($clone . '/docs/board-decisions/seat-decided.md',
    "# Decision seat-decided\n\n> which way?\n\n- A\n\n#### answered "
    . gmdate('Y-m-d H:i:s', $posted + 60) . " — ian-via-board — via desk\n\n> A\n");
sh('git add -A && git -c user.name=t -c user.email=t@t commit -q -m dec && git push -q origin HEAD:main', $clone);
sh('git fetch -q origin && git reset -q --hard origin/main', $clone);

$rpaths = $paths; $rpaths['DEVMSG_DB'] = $rdb;
relay($RELAY, $rpaths);
$snapR = json_decode((string) @file_get_contents($snap), true);
$liveWho    = array_column((array) ($snapR['desk'] ?? []), 'who');
$retiredWho = array_column((array) ($snapR['deskRetired'] ?? []), 'who');

is_(in_array('seat-open', $liveWho, true), 'an ask with nothing resolved STAYS on the desk');
is_(in_array('seat-decided', $retiredWho, true),
    'an ask whose decision has been ANSWERED retires — no hand-removal');
is_(!in_array('seat-decided', $liveWho, true), '...and is gone from the live desk');

/**
 * RETIRED IS MARKED, NOT DELETED. A desk where things vanish is a desk nobody
 * trusts, and the history view needs to show what was dealt with.
 */
$retRow = null;
foreach ((array) ($snapR['deskRetired'] ?? []) as $r) { if (($r['who'] ?? '') === 'seat-decided') { $retRow = $r; } }
is_(is_array($retRow) && ($retRow['retired'] ?? '') !== '',
    '...carrying WHY it retired, so the history says what happened rather than just hiding it');
is_(is_array($retRow) && str_contains((string) ($retRow['retired'] ?? ''), 'decision'),
    '...and the reason names the decision, not a generic "done"');

/* An answer that PREDATES the ask must not retire it. */
file_put_contents($clone . '/docs/board-decisions/seat-open.md',
    "# Decision seat-open\n\n> old one\n\n- A\n\n#### answered "
    . gmdate('Y-m-d H:i:s', $posted - 3600) . " — ian-via-board — via desk\n\n> A\n");
sh('git add -A && git -c user.name=t -c user.email=t@t commit -q -m old && git push -q origin HEAD:main', $clone);
sh('git fetch -q origin && git reset -q --hard origin/main', $clone);
relay($RELAY, $rpaths);
$snapR2 = json_decode((string) @file_get_contents($snap), true);
is_(in_array('seat-open', array_column((array) ($snapR2['desk'] ?? []), 'who'), true),
    'a decision answered BEFORE the ask does not retire it — an old ruling cannot close a fresh question');

/* ---------------------------------------------------------------------- */
section("[7] IT REFUSES TO RUN HALF-CONFIGURED");

$r = relay($RELAY, ['CLONE_DIR' => $tmp . '/nope', 'COMMITTER' => $svcTmp, 'LANE_SAY' => $fake,
                    'SNAPSHOT' => $snap, 'DEVMSG_DB' => '/nonexistent/x.db', '__log' => $log]);
is_($r['rc'] === 3, 'a missing clone is CANNOT RUN (3), not a silent success');

/* ---------------------------------------------------------------------- */
// Cleanup is the DEFAULT. The first cut had this condition inverted and left a
// sandbox behind on every run — on a box that was at 91% disk when this feature
// was specced, a gate that litters is a gate that eventually takes the box down.
if (getenv('BLR_KEEP')) { echo "\n[kept: $tmp]\n"; }
else { sh('rm -rf ' . escapeshellarg($tmp)); }
echo "\n$pass passed, $fail failed\n";
if ($fail > 0) { echo "RED — the relay is not holding.\n"; exit(1); }
echo "GREEN — the message never meets a shell, a receipted message is never sent twice, "
   . "a crash re-delivers once and never loops, one bad message cannot wedge the queue, "
   . "and a failure is visible on the board.\n";
exit(0);
