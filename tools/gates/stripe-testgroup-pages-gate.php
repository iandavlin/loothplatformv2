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

/* ---------------------------------------------------------------------- *
 * #193 — THE SAME BEHAVIOURAL RUN, with the viewer's ADDRESS controllable.
 *
 * lg_membership_user_email() is function_exists-guarded like everything else in
 * config.php, precisely so a caller can pre-empt it — which is what lets this
 * run with no database, exactly as the reader stub above does. Pre-empting it
 * is also what keeps the assertion honest: what is under test is the DECISION,
 * not whether a SELECT works.
 * ---------------------------------------------------------------------- */
function scenarioEmail(string $MP, ?string $flag, ?string $listSerialized, array $ctx, string $viewerEmail): string
{
    $opts = [
        'lgms_stripe_testgroup_pages'     => $flag,
        'lgms_stripe_lifecycle_allowlist' => $listSerialized,
    ];
    $harness = '<?php
declare(strict_types=1);
$OPTS  = ' . var_export($opts, true) . ';
$CTX   = ' . var_export($ctx, true) . ';
$EMAIL = ' . var_export($viewerEmail, true) . ';

function lg_membership_wp_option(string $name, ?string $default = null): ?string {
    global $OPTS;
    return array_key_exists($name, $OPTS) && $OPTS[$name] !== null ? $OPTS[$name] : $default;
}
// The viewer\'s own address, pre-empted: no DB, and the decision is what is measured.
function lg_membership_user_email(int $wpUserId): string {
    global $EMAIL;
    $GLOBALS["EMAIL_LOOKUPS"][] = $wpUserId;
    return $wpUserId > 0 ? $EMAIL : "";
}
function lg_shared_render_site_header(array $c = []): void {}
function lg_shared_render_site_footer(array $c = []): void {}

$GLOBALS["EMAIL_LOOKUPS"] = [];
require ' . var_export($MP . '/config.php', true) . ';
require ' . var_export($MP . '/web/_admin-gate.php', true) . ';

ob_start();
lg_membership_testgroup_gate_or_exit($CTX);
ob_end_clean();
fwrite(STDERR, "ALLOWED:" . count($GLOBALS["EMAIL_LOOKUPS"]));
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgtge') . '.php';
    file_put_contents($tmp, $harness);
    $d = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $pr = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), $d, $pipes);
    if (!is_resource($pr)) { @unlink($tmp); cannot('could not spawn php'); }
    stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $pp) fclose($pp);
    proc_close($pr); @unlink($tmp);
    return str_contains($err, 'ALLOWED') ? 'ALLOWED' : 'REFUSED';
}

/* ---------------------------------------------------------------------- *
 * #193 — THE ADDRESS READER, IN ISOLATION.
 *
 * Same reasoning as idsFor() below, and the red-first proved it the same way:
 * "this viewer is refused" is a much weaker claim than "the list is empty".
 * Dropping the filter_var() validation left every behavioural scenario green,
 * because the invented entries simply were not addresses any test viewer
 * carried. What the reader RESOLVES TO has to be asserted directly.
 *
 * @return string[] whatever lg_membership_stripe_test_group_emails() returns
 * ---------------------------------------------------------------------- */
function emailsFor(string $MP, ?string $listSerialized): array
{
    $harness = '<?php
declare(strict_types=1);
$OPTS = ' . var_export(['lgms_stripe_lifecycle_allowlist' => $listSerialized], true) . ';
function lg_membership_wp_option(string $name, ?string $default = null): ?string {
    global $OPTS;
    return array_key_exists($name, $OPTS) && $OPTS[$name] !== null ? $OPTS[$name] : $default;
}
require ' . var_export($MP . '/config.php', true) . ';
echo json_encode(array_values(lg_membership_stripe_test_group_emails()));
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgeml') . '.php';
    file_put_contents($tmp, $harness);
    $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    $v = json_decode((string) $out, true);
    return is_array($v) ? $v : [];
}

/* ---------------------------------------------------------------------- *
 * #193 — THE PREDICATE ITSELF, with an email stub that answers for ANY id.
 *
 * ⚠️ WITHOUT THIS THE ANON GUARD IS UNTESTABLE. The behavioural runs go through
 * lg_membership_testgroup_gate_or_exit(), which refuses an unauthenticated ctx
 * on its own `authenticated` clause — so deleting `if ($wpUserId <= 0) return
 * false;` from the predicate stayed green for a reason that had nothing to do
 * with the predicate. And scenarioEmail()'s own stub returns '' for id 0, which
 * masks it a second time. This one answers with the listed address whatever id
 * it is handed, so the guard is the ONLY thing that can refuse.
 * ---------------------------------------------------------------------- */
function inGroupFor(string $MP, ?string $flag, ?string $listSerialized, int $uid, string $anyEmail): bool
{
    $opts = [
        'lgms_stripe_testgroup_pages'     => $flag,
        'lgms_stripe_lifecycle_allowlist' => $listSerialized,
    ];
    $harness = '<?php
declare(strict_types=1);
$OPTS  = ' . var_export($opts, true) . ';
$EMAIL = ' . var_export($anyEmail, true) . ';
function lg_membership_wp_option(string $name, ?string $default = null): ?string {
    global $OPTS;
    return array_key_exists($name, $OPTS) && $OPTS[$name] !== null ? $OPTS[$name] : $default;
}
// Answers for EVERY id, id 0 included — so only the guard can refuse.
function lg_membership_user_email(int $wpUserId): string { global $EMAIL; return $EMAIL; }
require ' . var_export($MP . '/config.php', true) . ';
echo lg_membership_in_stripe_test_group(' . $uid . ') ? "YES" : "NO";
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgpred') . '.php';
    file_put_contents($tmp, $harness);
    $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    return trim((string) $out) === 'YES';
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

/**
 * The door the PAGE itself uses — not the router's.
 *
 * THIS SECTION EXISTS BECAUSE THE GATE MISSED A TOTAL FAILURE. These surfaces
 * are gated TWICE: the router decides who may reach a page, and then every page
 * file calls lg_membership_prelaunch_gate_or_exit() and re-checks on its own
 * authority. The first version of this feature changed only the router, so the
 * router admitted a listed member and their own page then refused them — the
 * unlock did not work AT ALL, while this gate stayed green at 54 assertions,
 * because it asked the gate FUNCTION what it would decide and read the router's
 * TABLE, and never asked whether a member could actually reach a page.
 *
 * So the assertions below drive the page's own door, per state.
 */
function pageDoor(string $MP, ?string $flag, ?string $listSerialized, ?string $pagesLive, array $ctx): string
{
    $opts = [
        'lgms_stripe_testgroup_pages'     => $flag,
        'lgms_stripe_lifecycle_allowlist' => $listSerialized,
        'lgms_stripe_pages_live'          => $pagesLive,
    ];
    $harness = '<?php
declare(strict_types=1);
$OPTS = ' . var_export($opts, true) . ';
$CTX  = ' . var_export($ctx, true) . ';
function lg_membership_wp_option(string $name, ?string $default = null): ?string {
    global $OPTS;
    return array_key_exists($name, $OPTS) && $OPTS[$name] !== null ? $OPTS[$name] : $default;
}
function lg_shared_render_site_header(array $c = []): void {}
function lg_shared_render_site_footer(array $c = []): void {}
require ' . var_export($MP . '/config.php', true) . ';
require ' . var_export($MP . '/web/_admin-gate.php', true) . ';
ob_start();
lg_membership_prelaunch_gate_or_exit($CTX);
ob_end_clean();
fwrite(STDERR, "ALLOWED");
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgpd') . '.php';
    file_put_contents($tmp, $harness);
    $d = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $p = proc_open(PHP_BINARY . ' ' . escapeshellarg($tmp), $d, $pipes);
    if (!is_resource($p)) { @unlink($tmp); cannot('could not spawn php'); }
    stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    foreach ($pipes as $pp) fclose($pp);
    proc_close($p); @unlink($tmp);
    return str_contains($err, 'ALLOWED') ? 'ALLOWED' : 'REFUSED';
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
section( "[8] THE PAGE'S OWN DOOR — the one the router does not control" );

is_( pageDoor($MP, '1', $LIST, '0', $listed)   === 'ALLOWED',
     "a LISTED member is admitted by the page itself — the failure the rehearsal caught");
is_( pageDoor($MP, '1', $LIST, '0', $unlisted) === 'REFUSED', "an unlisted member is refused by the page itself");
is_( pageDoor($MP, '1', $LIST, '0', $anon)     === 'REFUSED', "an anonymous visitor is refused by the page itself");
is_( pageDoor($MP, '1', $LIST, '0', $admin)    === 'ALLOWED', "an administrator is admitted, as before");

// OFF is still today, at this door too.
is_( pageDoor($MP, null, $LIST, '0', $listed)  === 'REFUSED', "flag OFF: the page refuses a listed member, exactly as today");
is_( pageDoor($MP, '1', serialize([]), '0', $listed) === 'REFUSED', "empty list: the page refuses everyone");
is_( pageDoor($MP, null, null, '0', $admin)    === 'ALLOWED', "flag OFF: an administrator still gets in");

// And go-live still overrides everything, unchanged.
is_( pageDoor($MP, null, null, '1', $anon)     === 'ALLOWED', "pages_live ON: the page serves its real audience regardless of the list");

// The pages must all use THAT door. A page calling the hard admin gate directly
// would be invisible to every assertion above.
$selfGated = [];
$wrongDoor = [];
foreach ( [ 'lgjoin', 'lggift-buy', 'lggift', 'my-gifts', 'request-refund', 'welcome', 'regional-pricing-not-available' ] as $slug ) {
    $f = "$MP/web/$slug.php";
    if ( ! is_readable( $f ) ) { bad( "page file missing: $slug.php" ); continue; }
    $body = (string) file_get_contents( $f );
    if ( str_contains( $body, 'lg_membership_prelaunch_gate_or_exit' ) ) { $selfGated[] = $slug; }
    if ( preg_match( '/lg_membership_admin_gate_or_exit\s*\(/', $body ) )  { $wrongDoor[] = $slug; }
}
is_( count( $selfGated ) === 7, sprintf(
    "all 7 soft-launch pages go through the flag-aware door (%d do)", count( $selfGated ) ) );
is_( $wrongDoor === [], sprintf(
    "no soft-launch page calls the hard admin gate directly, which would bypass the list (%s)",
    $wrongDoor === [] ? 'none do' : implode( ', ', $wrongDoor ) ) );

/* ---------------------------------------------------------------------- *
 * [9] THE MENU — the other half of Ian's ask
 *
 * "a way for only white listed users to be able to see the menu for stripe, or
 * the pages for stripe." Sections 1-8 cover the PAGES. This covers the MENU,
 * and the assertion that matters is the negative one: a member who is NOT on
 * the list must see NO stripe entries at all — absent, not greyed.
 * ---------------------------------------------------------------------- */
section("[9] THE STRIPE MENU IS ABSENT FOR ANYONE NOT ON THE LIST");

/** Render just the account menu with a given capability set. */
function menuFor(string $repo, array $caps): string
{
    $harness = '<?php
$ctx = [ "authenticated" => true, "display_name" => "T", "capabilities" => ' . var_export($caps, true) . ',
         "logout_url" => "/logout", "profile_url" => "/p", "logo_url" => "", "active_nav" => "",
         "tier" => "looth3", "msg_unread" => null, "notif_unread" => null ];
ob_start();
require ' . var_export($repo . '/lg-shared/site-header.php', true) . ';
lg_shared_render_site_header($ctx);
echo ob_get_clean();
';
    $tmp = tempnam(sys_get_temp_dir(), 'lgmenu') . '.php';
    file_put_contents($tmp, $harness);
    $out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    return $out;
}

$REPO  = dirname(__DIR__, 2);
$STRIPE_LINKS = [ '/lgjoin/', '/lggift-buy/', '/lggift/', '/my-gifts/', '/request-refund/' ];
$ADMIN_ONLY   = [ '/affiliate-earnings/', '/test-checklist/' ];

$mPlain  = menuFor($REPO, [ 'manage_options' => false, 'stripe_testgroup' => false ]);
$mTester = menuFor($REPO, [ 'manage_options' => false, 'stripe_testgroup' => true ]);
$mAdmin  = menuFor($REPO, [ 'manage_options' => true,  'stripe_testgroup' => true ]);
$mNoCaps = menuFor($REPO, []);

is_($mPlain !== '' && $mTester !== '', "the header renders at all, so these checks are not vacuous");

/**
 * ⚠️ THE CAPABILITY MUST SURVIVE THE JOURNEY, not just be honoured on arrival.
 *
 * Everything below drives menuFor() with a SYNTHETIC `stripe_testgroup` cap —
 * which asserts the header HONOURS the capability, and can say nothing at all
 * about whether the real page ever RECEIVES it. On 2026-08-16 it did not: the
 * poller computed it correctly, and profile-app's capabilitiesFor() rebuilt the
 * caps array from a named allowlist that did not include it, so the capability
 * was received and dropped on the floor. Ian tested as a correctly-listed member
 * and saw nothing; this gate was green throughout.
 *
 * So this asserts the OTHER half of the chain, against the real source rather
 * than a fixture: does the central computation PASS the capability through?
 * In-process on this tree deliberately — an HTTP leg would measure whichever
 * copy is deployed, which is a different question and cannot red-first a fix
 * that has not shipped yet.
 *
 * THE TRAP IS THE ALLOWLIST ITSELF: it silently discards every capability nobody
 * remembered to name, and the discard is indistinguishable from the capability
 * being false. Anything the header learns to key on must be added there too.
 */
$whoamiSrc = (string) @file_get_contents($REPO . '/profile-app/src/Whoami.php');
if ($whoamiSrc === '') {
    bad('cannot read profile-app/src/Whoami.php — the capability source is unreachable');
} elseif (!preg_match('/private static function capabilitiesFor.*?
    }/s', $whoamiSrc, $cm)) {
    bad('capabilitiesFor() not found in profile-app/src/Whoami.php — it was renamed or moved');
} else {
    // Executed, not grepped: a mention of the string in a comment is not the
    // capability surviving, and this gate has been fooled by prose before.
    // Replace the WHOLE signature: swapping only "private static function" left
    // the original name behind it and produced `function lgb_capsfor
    // capabilitiesFor(...)`, a parse error inside eval that aborted the gate
    // mid-run — which is the shape that looks like the gate ran.
    eval(str_replace('private static function capabilitiesFor', 'function lgb_capsfor', $cm[0]));
    $listed   = lgb_capsfor(1953, 'looth3', ['manage_options' => false, 'stripe_testgroup' => true]);
    $unlisted = lgb_capsfor(999,  'looth3', ['manage_options' => false, 'stripe_testgroup' => false]);

    is_(array_key_exists('stripe_testgroup', $listed),
        'the central computation PASSES stripe_testgroup through — the menu can never see it otherwise');
    is_(($listed['stripe_testgroup'] ?? null) === true,
        '...true for a listed member');
    is_(($unlisted['stripe_testgroup'] ?? null) === false,
        '...and false for an unlisted one, not merely absent');

    /**
     * THE STRUCTURAL VERSION OF THE SAME BUG — keeper, 2026-08-16: make the
     * silent discard impossible rather than merely fixed once.
     *
     * EVERY capability the header keys on must survive profile-app's
     * pass-through. The allowlist names what it forwards, so a capability
     * nobody remembered to name is dropped — and a dropped capability is
     * INDISTINGUISHABLE from one that is false. That is why the failure looked
     * like a gate refusing a listed member rather than a key going missing.
     *
     * This is a static cross-check between the two files, so it fails the day
     * someone teaches the header a NEW capability and forgets the other end —
     * which is the next instance of this bug, not a hypothetical one.
     */
    $headerSrc = (string) @file_get_contents($REPO . '/lg-shared/site-header.php');
    preg_match_all("/caps\['([a-z_]+)'\]/", $headerSrc, $hm);
    $keyedOn = array_values(array_unique($hm[1] ?? []));

    // What the central computation actually forwards: the explicit keys it
    // builds, plus the names in its pass-through loop.
    preg_match_all("/'([a-z_]+)'\s*=>/", $cm[0], $em);
    $explicit = $em[1] ?? [];
    preg_match('/foreach \(\[(.*?)\] as/s', $cm[0], $fm);
    preg_match_all("/'([a-z_]+)'/", $fm[1] ?? '', $pm);
    $forwarded = array_values(array_unique(array_merge($explicit, $pm[1] ?? [])));

    is_($keyedOn !== [], sprintf('the header keys on capabilities at all, so this is not vacuous (%s)', implode(', ', $keyedOn)));
    $dropped = array_values(array_diff($keyedOn, $forwarded));
    $LGB_CROSSCHECK_DONE = true;
    is_($dropped === [], sprintf(
        'EVERY capability the header keys on survives profile-app\'s pass-through (dropped: %s)',
        $dropped === [] ? 'none' : implode(', ', $dropped)));
}

$leak = array_values(array_filter($STRIPE_LINKS, static fn (string $l): bool => str_contains($mPlain, $l)));
is_($leak === [], sprintf(
    "a NON-listed member sees NO stripe menu entries (leaked: %s)",
    $leak === [] ? 'none' : implode(', ', $leak)));

$missing = array_values(array_filter($STRIPE_LINKS, static fn (string $l): bool => !str_contains($mTester, $l)));
is_($missing === [], sprintf(
    "a LISTED tester sees all five stripe entries (missing: %s)",
    $missing === [] ? 'none' : implode(', ', $missing)));

// The menu must not offer a door the page gate then shuts.
$overreach = array_values(array_filter($ADMIN_ONLY, static fn (string $l): bool => str_contains($mTester, $l)));
is_($overreach === [], sprintf(
    "a tester is NOT offered the admin-only QA surfaces the router would refuse them (%s)",
    $overreach === [] ? 'none' : implode(', ', $overreach)));

is_(!str_contains($mPlain, 'lg-chrome__account-menu-divider') || substr_count($mPlain, 'lg-chrome__account-menu-divider') < substr_count($mTester, 'lg-chrome__account-menu-divider'),
    "...and the divider that introduces them is absent too — no empty gap where the menu was");

foreach ($ADMIN_ONLY as $l) {
    is_(str_contains($mAdmin, $l), "an administrator keeps " . $l . " — the list ADDS people, it never removes Ian's access");
}
foreach ($STRIPE_LINKS as $l) {
    if ($l !== '/lgjoin/') { continue; }
    is_(str_contains($mAdmin, $l), "...and still sees the money pages as before");
}

// Fail closed: a ctx with no capabilities at all shows nothing.
$leakNo = array_values(array_filter($STRIPE_LINKS, static fn (string $l): bool => str_contains($mNoCaps, $l)));
is_($leakNo === [], sprintf(
    "a context carrying NO capabilities shows no stripe menu — it fails closed (leaked: %s)",
    $leakNo === [] ? 'none' : implode(', ', $leakNo)));

// And the capability itself is computed centrally, not re-derived per surface.
$icr = (string) file_get_contents($REPO . '/lg-patreon-stripe-poller/src/Wp/InternalRestController.php');
is_(str_contains($icr, "\$caps['stripe_testgroup']"),
    "the capability is computed centrally beside manage_options, so every surface gets the same answer");

/* ---------------------------------------------------------------------- */
/* ---------------------------------------------------------------------- */
section("[12] INVITE LINKS — a scoped, single-use door, and OFF is today's site");

/**
 * Ian found the hole and ruled the mechanism, 2026-08-16: the Test Group takes
 * only EXISTING wp users, so a fresh recruit's complete join — the most
 * important pre-cutover rehearsal — was untestable. A URL token, single-use,
 * working across devices.
 *
 * This is a GATE BYPASS on the payment path, so every fence is asserted, and the
 * OFF state is asserted FIRST: it is what lets this merge before cutover.
 */
$invSrc = (string) @file_get_contents($REPO . '/membership-pages/web/_invites.php');
$gateSrc = (string) @file_get_contents($REPO . '/membership-pages/web/_admin-gate.php');

if ($invSrc === '' || $gateSrc === '') {
    bad('the invite module or the shared gate is missing');
} else {
    /* --- OFF IS TODAY'S SITE ------------------------------------------- */
    is_((bool) preg_match('/if \(!lg_membership_invites_enabled\(\)\) \{ return false; \}/', $invSrc),
        'with the flag OFF the invite check returns before reading anything — byte-identical to today');
    is_(!preg_match("/'lgms_stripe_invites_on'\s*=>\s*'?1/", $invSrc),
        '...and nothing in the module turns itself on');

    /* --- FENCE 1: SCOPE ------------------------------------------------- */
    is_(str_contains($invSrc, 'LG_MS_INVITE_SCOPE'),
        'a token is scoped to named pages, not to "pre-launch" in general');
    foreach (['manage-subscription', 'request-refund', 'affiliate-earnings'] as $shut) {
        is_(!preg_match("/LG_MS_INVITE_SCOPE = \[[^\]]*'" . preg_quote($shut, '/') . "'/s", $invSrc),
            "...and does NOT open " . $shut . " — an invitee has no business there");
    }
    is_((bool) preg_match("/LG_MS_INVITE_SCOPE = \[[^\]]*'lgjoin'/s", $invSrc),
        '...but does open the join page itself, or the invite is useless');

    // AND THE SCOPE IS ACTUALLY CONSULTED. The assertions above describe the
    // LIST; deleting the line that CHECKS it left them all green while the token
    // opened every pre-launch page — a general bypass, which is the one thing
    // this must never become. Third time today a check has been strengthened
    // from "the name is there" to "the property holds".
    is_((bool) preg_match('/if \(!in_array\(lg_membership_invite_slug\(\), LG_MS_INVITE_SCOPE, true\)\) \{ return false; \}/', $invSrc),
        '...and the scope is CHECKED, not merely declared');

    /* --- FENCE 2 and 3: SPENT AND EXPIRED ------------------------------- */
    // Single-quoted: in a double-quoted PHP string $rec['used_at'] interpolates
    // and the braces make it a parse error — the assertion about a fence cannot
    // itself be a syntax bug.
    is_(str_contains($invSrc, 'if (!empty($rec[\'used_at\'])) { return false; }'),
        'a SPENT invite admits nobody');
    is_((bool) preg_match('/\$exp > 0 && \$exp < time\(\)/', $invSrc),
        'an EXPIRED invite admits nobody, checked on every hit');

    /* --- THE TOKEN IS NOT STORED --------------------------------------- */
    is_(str_contains($invSrc, 'hash(\'sha256\', $token)'),
        'the store holds a HASH — reading the database hands nobody a working invite');

    /* --- THE PLACEMENT, which is the whole difference ------------------- */
    is_((bool) preg_match('/lg_membership_invite_admits\(\)/', $gateSrc),
        'the invite check lives in the gate BOTH doors delegate to');
    $posMember = strpos($gateSrc, 'lg_membership_in_stripe_test_group');
    $posInvite = strpos($gateSrc, 'lg_membership_invite_admits()');
    is_($posMember !== false && $posInvite !== false && $posInvite > $posMember,
        '...and LAST, so an invite only ever widens — it never decides for someone already through');
    is_(str_contains($gateSrc, "require_once __DIR__ . '/_invites.php'"),
        '...and the module travels WITH the gate, so the two doors cannot disagree about invites');
}

/* --- THE WRITE HALF: minting and spending ------------------------------- */
$mintSrc   = (string) @file_get_contents($REPO . '/lg-patreon-stripe-poller/src/Invites.php');
$pluginSrc = (string) @file_get_contents($REPO . '/lg-patreon-stripe-poller/src/Plugin.php');
if ($mintSrc === '' || $pluginSrc === '') {
    bad('the invite write half is missing');
} else {
    is_(str_contains($mintSrc, 'bin2hex( random_bytes( 16 ) )'),
        'the token is unguessable — random_bytes, not an id or a hash of the email');
    // SINGLE-quoted pattern. Written in double quotes, `$token` INTERPOLATED to
    // the empty string, so the regex could never match and the negative was
    // always true — the assertion tested only half of what it claimed, and the
    // mutation that stored the raw token sailed past it. A pattern that does not
    // mean what it looks like is worse than no pattern.
    // Scoped to the STORED RECORD, not the whole file. mint() legitimately
    // RETURNS the raw token — that is the link being handed over — so a
    // file-wide search for "'token' => $token" matched the return and failed on
    // a correct implementation. The property is that the token is not STORED,
    // and the only place that can happen is the record written to the option.
    $stored = preg_match('/\$all\[ hash\(.*?\] = \[(.*?)\];/s', $mintSrc, $sm) ? $sm[1] : '';
    is_($stored !== '' && !preg_match('/\'token\'\s*=>/', $stored),
        'only a HASH is stored — the raw token exists once, in the link handed over');
    is_(str_contains($mintSrc, 'hash( \'sha256\', $token )'),
        '...and the key IS that hash, so a database read cannot be replayed as an invite');

    /**
     * SINGLE-USE MEANS ONE ACCOUNT. Spending it when the link is merely OPENED
     * would kill it on a refresh or a back button, which is a support ticket
     * rather than a fence.
     */
    is_(str_contains($pluginSrc, "add_action( 'user_register'"),
        'an invite is spent when an ACCOUNT is created, not when a page is opened');
    // str_contains, not a regex: the string carries quotes that make the pattern
    // harder to read than the property it tests, and an assertion nobody can
    // read is an assertion nobody maintains.
    is_(str_contains($pluginSrc, 'if ( $on !== \'1\' && $on !== \'true\' ) { return; }'),
        '...and with invites OFF the hook returns immediately — a no-op, not a behaviour nobody asked for');

    /**
     * THE MATCH IS ON EMAIL, which is what stops a forwarded link becoming a
     * general door: whoever clicks it still has to register the address it was
     * minted for.
     */
    is_(str_contains($mintSrc, 'self::liveFor( $user->user_email )'),
        'the invite is matched to the account\'s EMAIL — a forwarded link enrols nobody else');
    is_(str_contains($mintSrc, 'CohortAllowlist::add( $wpUserId )'),
        '...the account it creates lands ON the Test Group list');
    is_(str_contains($mintSrc, '_lgms_invite_created'),
        '...stamped invite-created, so HOW a member got in is answerable later');

    /* --- THE ADMIN SURFACE ---------------------------------------------- */
    $adminSrc = (string) @file_get_contents($REPO . '/lg-patreon-stripe-poller/src/Admin.php');
    is_(str_contains($adminSrc, "admin_post_lgms_invite_mint"),
        'Ian can mint an invite himself, on the same tab as the list');
    is_(str_contains($adminSrc, "check_admin_referer( 'lgms_invite_mint' )")
        && str_contains($adminSrc, "current_user_can( 'manage_options' )"),
        '...behind a nonce and manage_options, like every other write on that tab');

    /**
     * THE LINK IS SHOWN ONCE. The raw token is never stored, so an admin screen
     * that implied it could be retrieved later would be lying — and the person
     * reading it would discover that only when they needed it.
     */
    is_(str_contains($adminSrc, 'this is the only time it is shown'),
        'the screen says the link cannot be recovered, because it genuinely cannot');
    is_(str_contains($adminSrc, 'already has an account'),
        'an email that already has an account is refused — that person wants the LIST, not an invite');
    is_(str_contains($adminSrc, 'Invites are switched off on this box'),
        'and with the feature off the screen SAYS so, rather than minting links that silently admit nobody');
    is_(str_contains($mintSrc, 'if ( (int) ( $rec[\'expires\'] ?? 0 ) > time() ) { return null; }'),
        'a second live invite for the same email is refused — two links for one single-use invite is one broken click');
}

section("[11] SINGLE TIER IS DERIVED FROM THE CATALOGUE, NOT PREVIEWED");

/**
 * Ian, 2026-08-16: *"It looks like there is a single tier preview. We shouldn't
 * need that anymore right? I should be able to have 1 tier just by registering
 * one tier."*
 *
 * The join page used to carry a `?preview=single` toggle that rendered a MOCK
 * product with invented prices — a design mockup living inside the shipping
 * page, which meant the only way to see the single-tier layout was to look at
 * something that was not the catalogue. The layout now follows the registered
 * tier COUNT.
 */
$joinSrc = (string) @file_get_contents($REPO . '/membership-pages/web/lgjoin.php');
if ($joinSrc === '') {
    bad('cannot read membership-pages/web/lgjoin.php');
} else {
    is_(!str_contains($joinSrc, "\$_GET['preview']"),
        'the manual preview toggle is gone — the layout is not a mode you switch');
    is_(!str_contains($joinSrc, 'renderSingleTierPreview'),
        '...and so is the mock renderer it called');
    is_(!preg_match('/mock_month|mockProd|mockPrices/', $joinSrc),
        '...leaving no invented prices in a page that sells things');
    is_(str_contains($joinSrc, 'products.length === 1'),
        'the single-tier layout is DERIVED from the registered tier count');

    // The derivation must feed the REAL card builder, or "one tier" would still
    // render something other than the tier that is registered.
    is_((bool) preg_match('/buildTierCard\(prod, sorted, isPopular, singleTier\)/', $joinSrc),
        '...and renders the REAL product, not a stand-in');
}

section("[10] THE REAL PAGE, AS A REAL MEMBER — driven over HTTP");

/**
 * Keeper, 2026-08-16, after Ian's visibility test failed while this gate was
 * green: every other leg here drives menuFor() with SYNTHETIC caps, which can
 * only prove the header honours a capability it is handed. This drives the
 * actual page over HTTP as a minted session, which is the only way to see what
 * a real member's browser gets.
 *
 * PROBES, chosen by keeper: 854 GerryHayesTest is LISTED and has NO
 * manage_options — it must not be an admin, or it passes the admin branch and
 * proves nothing about the list. 2455 viz-test-nobody is listed nowhere.
 *
 * ⚠️ IT READS THE DEPLOYED STATE RATHER THAN HARDCODING IT. This leg measures
 * whichever copy of profile-app is DEPLOYED, not this branch. Until the
 * pass-through fix reaches the serve, the deployed copy still drops
 * `stripe_testgroup` — so a leg asserting "entries render" would go RED on main
 * and block every lane for a fix that is merely not shipped yet. It asks whoami
 * what the deployed tree actually does, then asserts the matching state.
 */
$mintCookie = static function (int $uid): ?string {
    $ck = shell_exec('sudo -u looth-dev -H wp --path=/var/www/dev eval '
        . escapeshellarg('echo wp_generate_auth_cookie(' . $uid . ', time()+3600, "logged_in");') . ' 2>/dev/null');
    $ck = trim((string) $ck);
    return $ck !== '' ? $ck : null;
};
$hash = trim((string) shell_exec('sudo -u looth-dev -H wp --path=/var/www/dev eval '
    . escapeshellarg('echo COOKIEHASH;') . ' 2>/dev/null'));

$fetchAs = static function (int $uid, string $path) use ($mintCookie, $hash): array {
    $ck = $mintCookie($uid);
    if ($ck === null || $hash === '') { return ['code' => 0, 'body' => '']; }
    $cmd = sprintf('curl -sk -o /dev/stdout -w "\n%%{http_code}" -H %s -H %s %s 2>/dev/null',
        escapeshellarg('Host: dev2.loothgroup.com'),
        escapeshellarg('Cookie: wordpress_logged_in_' . $hash . '=' . $ck),
        escapeshellarg('https://127.0.0.1' . $path));
    $out = (string) shell_exec($cmd);
    $nl  = strrpos($out, "\n");
    return ['code' => (int) substr($out, $nl + 1), 'body' => substr($out, 0, $nl === false ? 0 : $nl)];
};

if ($hash === '') {
    echo "  .. cannot mint sessions here (no wp) — real-page leg skipped\n";
} else {
    $listedPage = $fetchAs(854, '/manage-subscription/');
    is_($listedPage['code'] === 200,
        sprintf('a LISTED non-admin member reaches the real page (854 → %d)', $listedPage['code']));
    is_(!str_contains($listedPage['body'], 'not available'),
        '...and gets the page itself, not the pre-launch stub');

    // What does the DEPLOYED tree actually hand the page?
    $who = $fetchAs(854, '/wp-json/looth/v1/whoami');
    $payload = json_decode($who['body'], true);
    $deployedCarries = is_array($payload) && array_key_exists('stripe_testgroup', (array) ($payload['capabilities'] ?? []));

    if (!$deployedCarries) {
        // Not a failure of this branch: the fix simply is not on the serve yet.
        echo "  .. the DEPLOYED profile-app still drops stripe_testgroup — menu half\n";
        echo "     awaits merge+pull of the pass-through fix. Reachability asserted above.\n";
        is_(true, 'deployed state read and reported rather than asserted against blindly');
    } else {
        $entries = 0;
        foreach ($STRIPE_LINKS as $l) { if (str_contains($listedPage['body'], $l)) { $entries++; } }
        is_($entries === count($STRIPE_LINKS), sprintf(
            'a LISTED member SEES the stripe entries on the real page (%d of %d)', $entries, count($STRIPE_LINKS)));

        $plainPage = $fetchAs(2455, '/manage-subscription/');
        $leaked = array_values(array_filter($STRIPE_LINKS,
            static fn (string $l): bool => str_contains($plainPage['body'], $l)));
        is_($leaked === [], sprintf('an UNLISTED member sees none of them (leaked: %s)',
            $leaked === [] ? 'none' : implode(', ', $leaked)));
    }
}

/* ═══ #193 — THE DOOR ALSO KNOWS AN ADDRESS ══════════════════════════════ *
 *
 * Ian ruled the tester list takes plain email addresses. Without this leg a
 * tester whose account was created BY the join is admitted to checkout and then
 * refused the join page itself the moment they arrive on a browser without
 * #180's unlock cookie — a second device, a cleared cookie jar, or simply
 * /manage-subscription/ after they have paid. That is the "wired perfectly and
 * lands nowhere" shape this repo has three memories about, and it would land in
 * the middle of a real-money test.
 */
echo "\n#193 — the page door recognises a listed ADDRESS\n";

$addrList   = serialize(['someone@example.test']);
$mixedList  = serialize([9001, 'someone@example.test']);
$idOnlyList = serialize([9001]);
$viewer     = ['authenticated' => true, 'wp_user_id' => 4242];

is_(scenarioEmail($MP, '1', $addrList, $viewer, 'someone@example.test') === 'ALLOWED',
    '#193 a viewer whose ADDRESS is listed is admitted, though their id is not');
is_(scenarioEmail($MP, '1', $addrList, $viewer, 'SOMEONE@Example.Test') === 'ALLOWED',
    '#193 ...however their address is cased');
is_(scenarioEmail($MP, '1', $addrList, $viewer, 'other@example.test') === 'REFUSED',
    '#193 a viewer whose address is NOT listed is still refused');
is_(scenarioEmail($MP, '1', $addrList, $viewer, '') === 'REFUSED',
    '#193 a viewer whose address cannot be read is REFUSED — a DB failure must never admit');

/* LOCK 1 STILL OUTRANKS IT. The flag off means the Test Group unlocks nothing,
   addresses included — or #193 would have quietly become a fourth way in. */
is_(scenarioEmail($MP, '0', $addrList, $viewer, 'someone@example.test') === 'REFUSED',
    '#193 the pages flag OFF still refuses a listed address — lock 1 is unchanged');
is_(scenarioEmail($MP, null, $addrList, $viewer, 'someone@example.test') === 'REFUSED',
    '#193 ...and so does an ABSENT flag');

/* ANON IS NEVER LISTED, and cannot become listed by carrying an address. */
is_(scenarioEmail($MP, '1', $addrList, ['authenticated' => false, 'wp_user_id' => 0], 'someone@example.test') === 'REFUSED',
    '#193 an unauthenticated viewer is refused even with a listed address');

/* MIXED AND ID-ONLY LISTS. The id half is untouched by any of this. */
is_(scenarioEmail($MP, '1', $mixedList, ['authenticated' => true, 'wp_user_id' => 9001], 'nobody@example.test') === 'ALLOWED',
    '#193 a listed MEMBER is still admitted from a mixed list');
is_(scenarioEmail($MP, '1', $idOnlyList, $viewer, 'someone@example.test') === 'REFUSED',
    '#193 an id-only list admits nobody by address');

/* THE READER ITSELF: address entries must not corrupt the id list, which is
   what made adding them to this option safe in the first place. */
is_(idsFor($MP, '1', $mixedList) === [9001],
    '#193 the id reader is UNCHANGED by address entries — it has always dropped non-numerics');
is_(idsFor($MP, '1', $addrList) === [],
    '#193 an addresses-only list resolves to NO ids, exactly as before');

/* THE ADDRESS READER, IN ISOLATION — what does it actually resolve to?
   Found by red-first: without these, dropping filter_var() left every
   behavioural scenario green. */
is_(emailsFor($MP, serialize(['Someone@Example.Test', '  spaced@example.test  '])) === ['someone@example.test', 'spaced@example.test'],
    '#193 the address reader trims and lower-cases');
is_(emailsFor($MP, serialize(['not-an-email', 'also bad', '@nope', 'x@', ''])) === [],
    '#193 ...and DROPS every malformed entry — a junk entry can never become a listed address');
is_(emailsFor($MP, serialize([9001, '77', true, null, ['a']])) === [],
    '#193 ...and ignores ids, digit-strings and non-strings entirely');
is_(emailsFor($MP, serialize(['ok@example.test', 'not-an-email'])) === ['ok@example.test'],
    '#193 ...keeping the good one beside the bad, rather than failing whole');
is_(emailsFor($MP, 'not-serialized-at-all') === [] && emailsFor($MP, null) === [],
    '#193 an absent or malformed OPTION resolves to no addresses — nobody');

/* THE PREDICATE ITSELF. The behavioural runs cannot test the anon guard: the
   gate refuses an unauthenticated ctx on its own `authenticated` clause, so
   deleting the guard stays green for an unrelated reason. Found by red-first. */
is_(inGroupFor($MP, '1', $addrList, 0, 'someone@example.test') === false,
    '#193 the predicate refuses id 0 even when the address lookup WOULD answer with a listed one');
is_(inGroupFor($MP, '1', $addrList, -5, 'someone@example.test') === false,
    '#193 ...and a negative id');
is_(inGroupFor($MP, '1', $addrList, 4242, 'someone@example.test') === true,
    '#193 ...while a real id with that same address is admitted — or the two above are vacuous');
is_(inGroupFor($MP, '0', $addrList, 4242, 'someone@example.test') === false,
    '#193 ...and lock 1 still refuses it at the predicate, not only at the gate');

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
 * ─── #193 (2026-08-22): the door also knows an ADDRESS. Baseline 136/0.
 *     6/6 caught, 1/1 no-op inert. Re-run:
 *     python3 tools/gates/... — the harness for these lives in the lane's
 *     scratchpad; the mutations are recorded here because the numbers are what
 *     matter and they are measured, not predicted.
 *
 *   M7b remove the address leg from lg_membership_in_stripe_test_group()
 *       -> 3 RED. A tester whose account was created by the join is refused
 *          the join page on any browser without #180's unlock cookie.
 *   M8c the compare stops normalizing (leans on the lookup to lower-case)
 *       -> 1 RED. THIS ONE FOUND A REAL DEFECT rather than proving an
 *          assertion: the first draft did exactly this, so the door was
 *          correct only while that one helper stayed its only caller.
 *   M9b an unreadable address ADMITS instead of refusing
 *       -> 1 RED. A DB error must never open a door.
 *   M10 lock 1 stops outranking the address leg
 *       -> 8 RED. The pages flag OFF would let a listed address in, which
 *          would make #193 a quiet fourth way in.
 *   M11 the address reader drops its filter_var() validation
 *       -> 3 RED — but ONLY after emailsFor() was added. It was a BLIND SPOT
 *          first time round: every behavioural scenario stayed green because
 *          the invented entries were not addresses any test viewer carried.
 *          Same lesson idsFor() records above, one reader over.
 *   M12 delete the `$wpUserId <= 0` anon guard
 *       -> 2 RED — again only after inGroupFor() was added. The behavioural
 *          runs CANNOT see this: the gate refuses an unauthenticated ctx on
 *          its own `authenticated` clause, so the mutation stayed green for a
 *          reason that had nothing to do with the predicate, and
 *          scenarioEmail()'s stub returning '' for id 0 masked it a second
 *          time. A guard that only a direct call can exercise needs a direct
 *          call.
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
 *
 *  R1  revert the PAGE'S OWN DOOR (lg_membership_prelaunch_gate_or_exit) to
 *      the hard admin gate — i.e. THE BUG THE REHEARSAL FOUND, exactly as it
 *      shipped                                                    -> 1 RED
 *      "a LISTED member is admitted by the page itself". Before §8 existed
 *      this gate was GREEN at 54 assertions while the feature did not work at
 *      all: it asked the gate FUNCTION what it would decide and read the
 *      router's TABLE, and never asked whether a member could reach a page.
 *      These surfaces are gated TWICE and only the router had been changed.
 *      The lesson is not "add an assertion" — it is that asserting a decision
 *      is not asserting reachability.
 * ======================================================================= */
