<?php
/**
 * GATE 34 (pages half) — the Stripe soft launch unlocks the EXISTING member
 * pages for a hand-picked list, and for NOBODY else.
 *
 *   php tools/gates/stripe-testgroup-pages-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Gate 34's other half (lg-patreon-stripe-poller/deploy/remediation/
 * test-soft-launch-allowlist.php, 39 assertions) covers the MEMBERSHIP GRANT —
 * who Stripe's webhook is allowed to transition. This half covers the PAGES —
 * who can reach the join / gift / refund / regional surfaces at all. They are
 * two ends of the same list and share its gate number.
 *
 * WHY THIS EXISTS AS A SEPARATE FILE: the grant lives in the WordPress plugin;
 * the pages are a standalone PHP app that never boots WordPress
 * (membership-pages/, served by its own FPM pool). Nothing the lifecycle gate
 * asserts says anything about what a URL serves.
 *
 * WHAT MUST HOLD, in order of what it costs to get wrong:
 *
 *   1. OFF IS TODAY'S SITE. Flag off or absent — the default — and the Test
 *      Group unlocks nothing: a listed member is refused exactly as they are
 *      refused today. This is the byte-identical-off-state rule, and it is the
 *      assertion that lets this merge before Ian has looked at anything.
 *   2. AN EMPTY LIST IS NOBODY. Flag ON with the list absent, empty, or
 *      malformed still refuses everyone. Fail-safe, same as the grant side.
 *   3. THE LIST IS THE ONLY DISCRIMINATOR. Same flag, same page, same request:
 *      in the list serves, out of the list gets the stub.
 *   4. AN ADMIN IS NEVER LOCKED OUT. Ian builds the Stripe op privately as an
 *      administrator and is not necessarily on his own list. If adding this
 *      gate could ever take HIS access away, he loses the surface he is
 *      building on and the soft launch stalls.
 *   5. THE STORED SHAPE IS THE SHAPE THE DASH WRITES. WordPress stores an
 *      array option PHP-SERIALIZED. A JSON reader finds nothing in it, which
 *      reads as "empty list" — safe here only because it fails CLOSED, and the
 *      reverse mistake (a JSON list read as populated) must never serve.
 *   6. THE ROUTER TABLE SAYS WHAT WE THINK IT SAYS. The soft-launch surfaces
 *      carry 'testgroup' in the pre-launch column; the QA surface stays
 *      administrator-only in BOTH columns; and no page's GO-LIVE column was
 *      touched by this change.
 *
 * Every assertion below was falsified by mutation before it was trusted — see
 * the RED-FIRST block at the foot of this file for the exact mutations and
 * what each one reddened.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$MP   = $ROOT . '/membership-pages';

$pass = 0; $fail = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_(bool $c, string $m): void { $c ? ok($m) : bad($m); }
function section(string $t): void { echo "\n$t\n"; }
function cannot(string $why): void { echo "CANNOT RUN: $why\n"; exit(3); }

foreach (["$MP/config.php", "$MP/web/_admin-gate.php", "$MP/web/router.php", "$MP/lib/whoami.php"] as $f) {
    if (!is_readable($f)) cannot("missing $f");
}

/* ---------------------------------------------------------------------- *
 * The scenario runner.
 *
 * The gate's refusal is an exit() that prints a stub page, so it cannot be
 * observed in-process — each scenario runs in its own PHP subprocess and is
 * judged by what it actually DID, not by re-reading the logic that decided it.
 * The WP-option reader is stubbed (every function in config.php is
 * function_exists-guarded precisely so a caller can pre-empt one), which is
 * what lets this run with no database at all.
 *
 * Returns 'ALLOWED' (the gate returned, i.e. the real page would render) or
 * 'REFUSED' (the stub rendered and the request ended).
 * ---------------------------------------------------------------------- */
function scenario(string $MP, ?string $flag, ?string $listSerialized, array $ctx): string
{
    $opts = [
        'lgms_stripe_testgroup_pages'     => $flag,
        'lgms_stripe_lifecycle_allowlist' => $listSerialized,
    ];
    $harness = '<?php
declare(strict_types=1);
$OPTS = ' . var_export($opts, true) . ';
$CTX  = ' . var_export($ctx, true) . ';

// Pre-empt the real option reader: no DB, exact control of both locks.
function lg_membership_wp_option(string $name, ?string $default = null): ?string {
    global $OPTS;
    return array_key_exists($name, $OPTS) && $OPTS[$name] !== null ? $OPTS[$name] : $default;
}
// The stub page renders site chrome from /srv; stub it so this runs anywhere.
function lg_shared_render_site_header(array $c = []): void {}
function lg_shared_render_site_footer(array $c = []): void {}

require ' . var_export($MP . '/config.php', true) . ';
require ' . var_export($MP . '/web/_admin-gate.php', true) . ';

ob_start();
lg_membership_testgroup_gate_or_exit($CTX);
ob_end_clean();
fwrite(STDERR, "ALLOWED");   // only reached when the gate RETURNS
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgtg') . '.php';
    file_put_contents($tmp, $harness);
    $d = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $p = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), $d, $pipes);
    if (!is_resource($p)) { @unlink($tmp); cannot('could not spawn php'); }
    stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $pp) fclose($pp);
    proc_close($p); @unlink($tmp);
    return str_contains($err, 'ALLOWED') ? 'ALLOWED' : 'REFUSED';
}

/**
 * The READER, in isolation: what ids does this option actually resolve to?
 *
 * The behavioural check above is not enough on its own, and a mutation proved
 * it: making the malformed-option branch return a NON-EMPTY list left every
 * scenario green, because the invented ids simply were not the ids the test
 * viewers carried. "This viewer is refused" is a much weaker claim than "the
 * list is empty", so both are asserted.
 *
 * @return int[] whatever lg_membership_stripe_test_group_ids() returns
 */
function idsFor(string $MP, ?string $flag, ?string $listSerialized): array
{
    $opts = [
        'lgms_stripe_testgroup_pages'     => $flag,
        'lgms_stripe_lifecycle_allowlist' => $listSerialized,
    ];
    $harness = '<?php
declare(strict_types=1);
$OPTS = ' . var_export($opts, true) . ';
function lg_membership_wp_option(string $name, ?string $default = null): ?string {
    global $OPTS;
    return array_key_exists($name, $OPTS) && $OPTS[$name] !== null ? $OPTS[$name] : $default;
}
require ' . var_export($MP . '/config.php', true) . ';
echo json_encode(array_values(lg_membership_stripe_test_group_ids()));
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgids') . '.php';
    file_put_contents($tmp, $harness);
    $d = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $p = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), $d, $pipes);
    if (!is_resource($p)) { @unlink($tmp); cannot('could not spawn php'); }
    $out = stream_get_contents($pipes[1]);
    foreach ($pipes as $pp) fclose($pp);
    proc_close($p); @unlink($tmp);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : [-1];   // -1 = unreadable, never a valid id
}

/**
 * The real context builder, with /whoami stubbed: does the authenticated user
 * id actually survive the trip into the page context?
 *
 * Without this the gate could only assert the passthrough textually, and a
 * mutation proved that mattered: deleting the id from
 * lg_membership_header_ctx() reddened ONLY the source-text check, because
 * every scenario above hands the gate a context array it built itself. This
 * exercises the function the router really calls.
 *
 * @return int the wp_user_id the page context ends up carrying
 */
function ctxUserId(string $MP, array $whoami): int
{
    $harness = '<?php
declare(strict_types=1);
$WHO = ' . var_export($whoami, true) . ';
// Pre-empt the loopback: no curl, no /dev/shm cache, no network.
function lg_membership_whoami(): ?array { global $WHO; return $WHO; }
function lg_membership_wp_option(string $n, ?string $d = null): ?string { return $d; }
require ' . var_export($MP . '/config.php', true) . ';
require ' . var_export($MP . '/lib/whoami.php', true) . ';
$ctx = lg_membership_header_ctx(\'\');
echo (int) ($ctx[\'wp_user_id\'] ?? -1);
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgctx') . '.php';
    file_put_contents($tmp, $harness);
    $d = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $p = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), $d, $pipes);
    if (!is_resource($p)) { @unlink($tmp); cannot('could not spawn php'); }
    $out = stream_get_contents($pipes[1]);
    foreach ($pipes as $pp) fclose($pp);
    proc_close($p); @unlink($tmp);
    return (int) trim($out);
}

/* Viewer shapes. capabilities.manage_options is what the app calls an admin. */
$admin    = ['authenticated' => true,  'wp_user_id' => 1,   'capabilities' => ['manage_options' => true]];
$listed   = ['authenticated' => true,  'wp_user_id' => 501, 'capabilities' => []];
$unlisted = ['authenticated' => true,  'wp_user_id' => 777, 'capabilities' => []];
$anon     = ['authenticated' => false, 'wp_user_id' => 0,   'capabilities' => []];

$LIST = serialize([501, 502]);   // exactly what the poller's dash writes

echo "GATE 34 (pages half) — Stripe Test Group unlocks the existing member pages\n";

/* ---------------------------------------------------------------------- */
section("[1] OFF IS TODAY'S SITE — the flag absent or off unlocks NOTHING");

is_(scenario($MP, null, $LIST, $listed)   === 'REFUSED', "flag ABSENT + member on the list -> refused, exactly as today");
is_(scenario($MP, '0',  $LIST, $listed)   === 'REFUSED', "flag OFF + member on the list -> refused, exactly as today");
is_(scenario($MP, '',   $LIST, $listed)   === 'REFUSED', "flag empty-string + on the list -> refused");
is_(scenario($MP, 'yes',$LIST, $listed)   === 'REFUSED', "only the literal '1' arms it — 'yes' does not");
is_(scenario($MP, null, $LIST, $anon)     === 'REFUSED', "flag absent + anon -> refused");
is_(scenario($MP, null, $LIST, $admin)    === 'ALLOWED', "flag absent + ADMIN -> still in (the pre-launch build surface is untouched)");

/* ---------------------------------------------------------------------- */
section("[2] AN EMPTY OR BROKEN LIST IS NOBODY, even with the flag ON");

is_(scenario($MP, '1', null,                 $listed) === 'REFUSED', "list ABSENT -> nobody");
is_(scenario($MP, '1', serialize([]),        $listed) === 'REFUSED', "list EMPTY -> nobody");
is_(scenario($MP, '1', 'not-serialized-at-all', $listed) === 'REFUSED', "list GARBAGE -> nobody (fails closed, never open)");
is_(scenario($MP, '1', serialize('501'),     $listed) === 'REFUSED', "list is a STRING not an array -> nobody");
is_(scenario($MP, '1', serialize(501),       $listed) === 'REFUSED', "list is an INT -> nobody");
is_(scenario($MP, '1', serialize(true),      $listed) === 'REFUSED', "list is a BOOL -> nobody");
is_(scenario($MP, '1', '[501,502]',          $listed) === 'REFUSED', "a JSON list is NOT read as populated — WordPress stores arrays serialized");

// ...and the same shapes at the READER, which is the claim that actually bites:
// "nobody got in" can be true by accident; "the list is empty" cannot.
is_(idsFor($MP, '1', null)                    === [], "reader: absent option resolves to NO ids");
is_(idsFor($MP, '1', serialize([]))           === [], "reader: empty option resolves to NO ids");
is_(idsFor($MP, '1', 'not-serialized-at-all') === [], "reader: garbage resolves to NO ids — never invents one");
is_(idsFor($MP, '1', serialize('501'))        === [], "reader: a string option resolves to NO ids");
is_(idsFor($MP, '1', serialize(501))          === [], "reader: an int option resolves to NO ids");
is_(idsFor($MP, '1', serialize(true))         === [], "reader: a bool option resolves to NO ids");
is_(idsFor($MP, '1', '[501,502]')             === [], "reader: a JSON list resolves to NO ids");
is_(idsFor($MP, '1', serialize([501, 502]))   === [501, 502], "reader: the dash's real shape resolves to exactly its ids");
is_(idsFor($MP, '1', serialize([502, 501, 502])) === [502, 501], "reader: duplicates collapse, and nothing else is invented");
is_(idsFor($MP, '1', serialize([501, 'x', 0, -3, 502])) === [501, 502], "reader: junk entries are dropped, valid ones survive");

/* ---------------------------------------------------------------------- */
section("[3] THE LIST IS THE ONLY DISCRIMINATOR");

is_(scenario($MP, '1', $LIST, $listed)   === 'ALLOWED', "flag ON + on the list -> the real page (the point of the soft launch)");
is_(scenario($MP, '1', $LIST, $unlisted) === 'REFUSED', "flag ON + NOT on the list -> the same stub everyone else sees");
is_(scenario($MP, '1', $LIST, $anon)     === 'REFUSED', "flag ON + signed OUT -> refused; being on a list is not being signed in");
is_(scenario($MP, '1', serialize([777]), $unlisted) === 'ALLOWED', "and it really is the ID that decides — same viewer, listed instead");
is_(scenario($MP, '1', serialize([777]), $listed)   === 'REFUSED', "...and the previously-listed member is now out. Removal means out.");

$anonWithId = ['authenticated' => false, 'wp_user_id' => 501, 'capabilities' => []];
is_(scenario($MP, '1', $LIST, $anonWithId) === 'REFUSED', "an id WITHOUT a signed-in session is refused (identity comes from the session)");

/* ---------------------------------------------------------------------- */
section("[4] AN ADMINISTRATOR IS NEVER LOCKED OUT BY THIS CHANGE");

is_(scenario($MP, '1', serialize([]),   $admin) === 'ALLOWED', "flag ON, list EMPTY, admin -> in (Ian need not add himself)");
is_(scenario($MP, '1', $LIST,           $admin) === 'ALLOWED', "flag ON, admin not on the list -> in");
is_(scenario($MP, '0', null,            $admin) === 'ALLOWED', "flag OFF, no list, admin -> in");

/* ---------------------------------------------------------------------- */
section("[5] THE READER READS THE SHAPE THE DASH WRITES");

// CohortAllowlist::write() stores a zero-indexed, ascending, unique int list.
is_(scenario($MP, '1', serialize([501, 502]), $listed) === 'ALLOWED', "the dash's canonical shape is understood");
is_(scenario($MP, '1', serialize(['501']),    $listed) === 'ALLOWED', "numeric STRINGS count — a hand-set wp-cli JSON list still works");
is_(scenario($MP, '1', serialize([0]),        ['authenticated'=>true,'wp_user_id'=>0,'capabilities'=>[]]) === 'REFUSED',
    "id 0 is never a member, even if 0 is somehow in the list");
is_(scenario($MP, '1', serialize([-501]),     ['authenticated'=>true,'wp_user_id'=>-501,'capabilities'=>[]]) === 'REFUSED',
    "negative ids are refused, never matched");

/* ---------------------------------------------------------------------- */
section("[6] THE ROUTER TABLE SAYS WHAT WE THINK IT SAYS");

$router = (string) file_get_contents("$MP/web/router.php");

/** @return array{0:string,1:string}|null [prelaunch, live] for a slug */
function rowFor(string $router, string $slug): ?array {
    $q = preg_quote($slug, '/');
    if (!preg_match("/'$q'\s*=>\s*\[[^\]]*?'[^']*\.php'\s*,\s*'([a-z]+)'\s*,\s*'([a-z]+)'\s*\]/", $router, $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

// The surfaces Ian named for the soft launch: join, gifts, regional, refunds,
// plus the two transient landings the paid journey ends on.
foreach (['lgjoin', 'lggift-buy', 'lggift', 'my-gifts', 'request-refund', 'welcome', 'regional-pricing-not-available'] as $slug) {
    $row = rowFor($router, $slug);
    if ($row === null) { bad("router row for '$slug' not found"); continue; }
    is_($row[0] === 'testgroup', "'$slug' pre-launch column is 'testgroup'");
}

$tc = rowFor($router, 'test-checklist');
is_($tc !== null && $tc[0] === 'admin' && $tc[1] === 'admin',
    "'test-checklist' stays ADMIN in BOTH columns — an internal QA surface never joins a soft launch");

// A soft launch must not quietly change what happens at GO-LIVE.
$liveExpected = [
    'lgjoin' => 'public', 'lggift-buy' => 'public', 'lggift' => 'public',
    'my-gifts' => 'member', 'request-refund' => 'member',
    'welcome' => 'public', 'regional-pricing-not-available' => 'public',
    'manage-subscription' => 'member', 'join' => 'public',
    'connect-your-patreon' => 'public', 'membership-guide' => 'public',
];
$liveDrift = [];
foreach ($liveExpected as $slug => $want) {
    $row = rowFor($router, $slug);
    if ($row === null || $row[1] !== $want) $liveDrift[] = $slug;
}
is_($liveDrift === [], "no page's GO-LIVE audience was changed by the soft launch (" . count($liveExpected) . " checked)");

// Pages that were already live to members/public must not have been narrowed.
foreach (['manage-subscription' => 'member', 'join' => 'public', 'connect-your-patreon' => 'public'] as $slug => $want) {
    $row = rowFor($router, $slug);
    is_($row !== null && $row[0] === $want, "'$slug' pre-launch column untouched ('$want') — already-live pages are not pulled behind the list");
}

is_(str_contains($router, "lg_membership_testgroup_gate_or_exit"), "the router actually dispatches to the Test Group gate");

/* ---------------------------------------------------------------------- */
section("[7] THE VIEWER'S ID IS AN AUTHENTICATED ONE");

$whoami = (string) file_get_contents("$MP/lib/whoami.php");
is_(preg_match("/'wp_user_id'\s*=>\s*\(int\)\(\\\$who\['wp_user_id'\]/", $whoami) === 1,
    "the id in the page context comes from /whoami, which resolves it with wp_validate_auth_cookie upstream");
is_(!preg_match('/wordpress_logged_in_/', (string) file_get_contents("$MP/web/_admin-gate.php")),
    "the gate never parses a login cookie itself — a visitor-editable string must not decide access");

// The passthrough, exercised rather than read: the router calls this builder,
// so if the id stops arriving here the whole soft launch silently serves
// nobody — the safe direction, but a dead feature nobody would notice.
is_(ctxUserId($MP, ['authenticated' => true,  'wp_user_id' => 501, 'tier' => 'looth3']) === 501,
    "a signed-in viewer's id reaches the page context intact");
is_(ctxUserId($MP, ['authenticated' => false, 'tier' => 'public']) === 0,
    "an anonymous viewer's id is 0 — which no list can ever match");
is_(ctxUserId($MP, ['authenticated' => true,  'tier' => 'looth3']) === 0,
    "a whoami response with no id at all yields 0, never a stray truthy value");

/* ---------------------------------------------------------------------- */
echo "\n$pass passed, $fail failed\n";
if ($fail > 0) {
    echo "RED — the Stripe Test Group page gate is not holding.\n";
    exit(1);
}
echo "GREEN — off is today's site, an empty list is nobody, the list alone decides, "
   . "an admin is never locked out, and go-live audiences are untouched.\n";
exit(0);

/* ======================================================================= *
 * RED-FIRST RECORD — measured, not asserted.
 *
 * Baseline: 54 passed, 0 failed. Each mutation below was applied to the real
 * source (from a snapshot copy, never `git checkout --`, so uncommitted work
 * under test is not destroyed), the gate was run, the failure count recorded,
 * and the file restored. Counts are what actually happened.
 *
 *   M1  drop the flag check in lg_membership_in_stripe_test_group()
 *       -> 4 RED. A listed member gets in with the flag OFF. This is the
 *          mutation that matters most: it is what shipping the pages to
 *          members on merge would look like.
 *   M2  malformed option returns a POPULATED list instead of []
 *       -> 5 RED. Fails open.
 *   M3  json_decode() instead of unserialize()
 *       -> 9 RED. The dash's real list stops resolving entirely.
 *   M4  drop the `authenticated` clause from the gate
 *       -> 1 RED. An unauthenticated caller carrying a listed id gets in.
 *   M5b lock an administrator out (remove BOTH the Test Group gate's admin
 *       early-return AND the admin bypass in the stub gate it falls through to)
 *       -> 4 RED.
 *   M6  revert one slug's pre-launch column to 'admin'
 *       -> 1 RED. The table check is not vacuous.
 *   M7  quietly change a GO-LIVE column
 *       -> 1 RED. A soft-launch change cannot silently move go-live.
 *   M8b delete 'wp_user_id' from lg_membership_header_ctx()
 *       -> 4 RED.
 *   M9  ids() accepts any truthy value instead of int/ctype_digit
 *       -> 1 RED. 0 and negative ids start matching.
 *
 * TWO MUTATIONS FOUND HOLES IN THIS GATE RATHER THAN IN THE CODE, which is
 * the whole reason for running them, and both are worth remembering:
 *
 *   - M2 STAYED GREEN at first. The section only asserted that a particular
 *     test viewer was refused, and the fail-open branch happened to invent an
 *     id that viewer did not carry. "Nobody got in" can be true by accident;
 *     "the list is empty" cannot. Fixed by asserting the READER's output
 *     directly (idsFor) alongside the behaviour.
 *   - M5 STAYED GREEN because the mutation was a NO-OP, not because the gate
 *     was blind: lg_membership_testgroup_gate_or_exit() falls through to
 *     lg_membership_admin_gate_or_exit(), which returns for administrators
 *     anyway, so deleting one early-return changed no behaviour. A mutation
 *     that changes nothing proves nothing — M5b removes both.
 *
 * Likewise M8 originally reddened only a source-text assertion, because every
 * scenario hands the gate a context array the gate itself built. ctxUserId()
 * now exercises the real builder, and M8b reddens 4.
 * ======================================================================= */
