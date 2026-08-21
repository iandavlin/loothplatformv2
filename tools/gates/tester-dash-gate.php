<?php
/**
 * GATE 90 — THE TESTER LINK IS RECOVERABLE, AND ROTATING IT REALLY KILLS THE OLD ONE.
 *
 *   php tools/gates/tester-dash-gate.php
 *
 * Exit 0 green, 1 a real defect, 2 cannot run (run-all.sh's convention).
 *
 * THE HOLE THIS EXISTS FOR (#190; Ian 2026-08-21: "Can we put the token link in
 * there with the whitlist ?"). #180 shipped the anonymous tester's unlock link
 * and stored sha256(token) — deliberately, so that reading the config hands
 * nobody a working link. The consequence nobody had said out loud: the WORKING
 * LINK EXISTED ONLY IN A CHAT MESSAGE. Lose it and the link is gone, because a
 * hash cannot be turned back into a URL, for a feature about to be handed to
 * real testers.
 *
 * ⚠️ THE ASSERTION THAT LOOKS RIGHT AND MEASURES NOTHING.
 * "Rotate produces a new token" passes on an implementation that generates a
 * token and never writes it anywhere — random_bytes is not the part that can
 * break. It also passes on one that writes the token but not the hash, and on
 * one that writes both while the old hash keeps working. The assertion that
 * BITES is the refusal: §A9, AFTER ROTATING, THE OLD LINK STOPS WORKING,
 * measured in a subprocess so no cached config can flatter it. Same family as
 * #148's "a PRO purchase grants looth3" passing on a constant, and #181's "a
 * cohort member can buy" passing when everybody could.
 *
 * ⚠️ THE SECOND ONE, AND IT IS THE REASON THE PANEL IS TESTED AT ALL.
 * "The panel shows a link" is satisfied by a panel that ALWAYS prints
 * TesterUnlock::url(). On a box armed by something else — a hand-placed
 * platform/config/tester-unlock.local.php, which is exactly what dev2 carries
 * today — that prints a link built from a token whose hash is NOT the armed
 * one. It looks completely live and it does not work. §D6 asserts that in the
 * 'foreign' state NO lgtester= URL appears anywhere in the markup.
 *
 * ⚠️ THE THIRD: "TURNING IT OFF" MUST SURVIVE A BOX FILE.
 * An absent state file applies NOTHING, so a Clear implemented as "delete the
 * file" leaves a box with an armed .local.php still armed while the dash says
 * it is off. §B6 arms a .local.php, clears through the real code, and requires
 * the token to stop matching. This is not hypothetical: dev2 is in that state.
 *
 * WHAT IS REAL AND WHAT IS STUBBED. TesterUnlock, TesterUnlockPanel,
 * CohortAllowlist and the SHARED READER (lg-shared/tester-unlock.php, loaded
 * through site-header.php so both come from one tree) are the real code — every
 * decision under test is real. WordPress's option store, escaping and nonce
 * helpers are observable stand-ins. There is no browser, no database, no FPM,
 * no WordPress and no network, so this gate cannot go vacuously green behind a
 * locked-out browser; and every store it touches is under a PER-RUN temp
 * directory keyed to the PID, so two suites running at once cannot collide
 * (feedback-gate-probe-must-be-per-run).
 *
 * ⚠️ IT DELIBERATELY DOES NOT LOAD Admin.php. That file reaches
 * StripeLifecycle, StripePrice, Invites and CompExpiry, and its neighbouring
 * test file has died at exit 255 with NO FAIL LINE three separate times because
 * the door gained a dependency nobody added to a require list. The panel lives
 * in its own class so this gate can render it with two collaborators instead of
 * a dozen. §E therefore asserts the HANDLERS by source rather than by call —
 * stated plainly rather than counted as behavioural coverage.
 *
 * ⚠️ IT MUST LOAD THE BRANCH'S READER FIRST, ON PURPOSE.
 * TesterUnlock::loadReader() prefers /srv/lg-shared, which is the SERVING
 * CHECKOUT — main, sitting beside dev2's armed tester-unlock.local.php. The
 * first run of this code measured that instead of the branch and reported a
 * disarmed box as armed (trap-harness-and-serve-answer-from-main). Loading the
 * worktree's copy before anything else is what makes the gate measure the
 * branch; §H asserts the loaded reader really is the branch's one, because
 * every other assertion here is meaningless if it is not.
 *
 * RED-FIRST: see tools/gates/tester-dash-redfirst.py.
 */

declare(strict_types=1);

namespace {

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$GATE_ROOT = dirname( __DIR__, 2 );
$RUN       = sys_get_temp_dir() . '/lg-gate90-' . getmypid();

$pass = 0; $fail = 0; $reports = [];

function ok( string $m ): void      { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void     { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function report( string $m ): void  { global $reports; $reports[] = $m; }
/* EXIT 2, NOT 3. run-all.sh reads 0 green / 2 CANNOT RUN / anything else RED,
   so a cannot-run that exits 3 reports a missing environment as a finding and
   blocks every lane behind it. (Gate 86 exits 3; that is the known trap, not a
   convention to copy.) */
function cannot( string $why ): void { echo "CANNOT RUN: $why\n"; exit( 2 ); }
function section( string $t ): void { echo "\n$t\n"; }

/** Per-run scratch, keyed to the PID so concurrent suites cannot collide. */
if ( ! @mkdir( $RUN, 0755, true ) && ! is_dir( $RUN ) ) {
    cannot( "could not create the per-run directory $RUN" );
}
register_shutdown_function( static function () use ( $RUN ) {
    // Fires on a fatal and on every exit() path alike.
    if ( is_dir( $RUN ) ) { @exec( 'rm -rf ' . escapeshellarg( $RUN ) ); }
} );

$STATE = $RUN . '/tester-unlock.json';
putenv( 'LG_TESTER_UNLOCK_STATE=' . $STATE );
$_SERVER['LG_TESTER_UNLOCK_STATE'] = $STATE;

foreach ( [
    '/lg-shared/site-header.php',
    '/lg-shared/tester-unlock.php',
    '/lg-patreon-stripe-poller/src/TesterUnlock.php',
    '/lg-patreon-stripe-poller/src/TesterUnlockPanel.php',
    '/lg-patreon-stripe-poller/src/CohortAllowlist.php',
    '/lg-patreon-stripe-poller/src/StripeLifecycle.php',
    '/lg-patreon-stripe-poller/src/Admin.php',
] as $need ) {
    if ( ! is_readable( $GATE_ROOT . $need ) ) { cannot( "missing $need" ); }
}

/**
 * THE BRANCH'S SHARED PARTIAL, LOADED BEFORE ANYTHING ELSE.
 * site-header.php requires tester-unlock.php from its own __DIR__, so both
 * arrive from this worktree and TesterUnlock::loadReader() early-returns
 * instead of reaching /srv (the serving checkout, i.e. main).
 */
require $GATE_ROOT . '/lg-shared/site-header.php';

// ---- WordPress stand-ins ---------------------------------------------------

$OPTS = [];
function get_option( $k, $d = false ) { global $OPTS; return array_key_exists( $k, $OPTS ) ? $OPTS[ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $OPTS; $OPTS[ $k ] = $v; return true; }
function delete_option( $k ) { global $OPTS; unset( $OPTS[ $k ] ); return true; }
function home_url( $p = '' ) { return 'https://dev2.loothgroup.com' . $p; }
function admin_url( $p = '' ) { return 'https://dev2.loothgroup.com/wp-admin/' . $p; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )  { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" data-action="' . esc_attr( $a ) . '" value="stub">'; }

/* The REAL StripeLifecycle: CohortAllowlist::OPT is a compile-time reference to
   its ALLOWLIST_OPT const and ids() delegates to its allowlist(), so a stub here
   would be a second definition of the cohort — the exact drift #190 is about. It
   loads on the option stand-ins alone, measured. */
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/StripeLifecycle.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/CohortAllowlist.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/TesterUnlock.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/TesterUnlockPanel.php';

/**
 * Ask a FRESH PHP PROCESS whether a raw token opens the door.
 *
 * A subprocess rather than an in-process call, deliberately: the shared reader
 * caches its resolved config in a static, and this gate has just written the
 * store, so an in-process answer could be the one from before the write. A new
 * process has no cache to flatter it and is the closest thing here to what an
 * actual visitor's request does.
 */
function door_opens( string $token, ?string $stateFile = null, array $env = [] ): bool {
    global $GATE_ROOT, $STATE;
    $env = array_merge( [
        'LG_TESTER_UNLOCK_STATE' => $stateFile ?? $STATE,
        'TOK'                    => $token,
    ], $env );
    $prefix = '';
    foreach ( $env as $k => $v ) { $prefix .= $k . '=' . escapeshellarg( (string) $v ) . ' '; }
    $code = 'require ' . var_export( $GATE_ROOT . '/lg-shared/tester-unlock.php', true ) . ';'
          . 'echo lg_tester_unlock_token_matches((string)getenv("TOK")) ? "YES" : "NO";';
    return trim( (string) shell_exec( $prefix . 'php -r ' . escapeshellarg( $code ) . ' 2>&1' ) ) === 'YES';
}

/** The resolved config a fresh process sees, as a comparable string. */
function resolved_config( string $readerPath, array $env = [] ): string {
    $prefix = '';
    foreach ( $env as $k => $v ) { $prefix .= $k . '=' . escapeshellarg( (string) $v ) . ' '; }
    $code = 'require ' . var_export( $readerPath, true ) . ';'
          . '$c = lg_tester_unlock_config();'
          . 'echo json_encode(["enabled"=>$c["enabled"],"hash"=>$c["hash"]]);';
    return trim( (string) shell_exec( $prefix . 'php -r ' . escapeshellarg( $code ) . ' 2>&1' ) );
}

/**
 * A file's CODE ONLY, with every comment and docblock removed.
 *
 * ⚠️ THIS EXISTS BECAUSE THIS GATE'S OWN ASSERTIONS FAILED ON THEIR OWN
 * WARNINGS. F4 resolved the reader's source order by strpos and found the
 * FIRST mention of each source — which is in the docblock that EXPLAINS the
 * order, not the code that implements it. F5 asserted the panel does not reach
 * StripeLifecycle and matched the panel's own comment saying exactly that.
 * Both went RED on correct code (feedback-red-first-that-stays-green, and the
 * same fix gate 83 needed).
 *
 * PHP's own tokenizer, not a regex: these files contain '//' inside strings and
 * URLs, which a naive comment-strip would eat.
 */
function php_code_only( string $path ): string {
    $out = '';
    foreach ( token_get_all( (string) file_get_contents( $path ) ) as $t ) {
        if ( is_array( $t ) ) {
            if ( $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT ) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/**
 * One PHP function's body, brace-matched.
 *
 * ⚠️ THE FIXED-WIDTH WINDOW THIS REPLACES WAS A REAL GATE DEFECT, found by the
 * red-first run: substr(..., 2200) starting at handleTesterRotate ran past the
 * end of that function and into handleTesterClear, so deleting rotate's
 * check_admin_referer left the gate GREEN — it was reading the NEIGHBOUR's
 * guard. Same family as trap-class-name-assertion-passes-on-the-defect.
 */
function php_function_body( string $code, string $name ): string {
    $at = strpos( $code, "function $name(" );
    if ( $at === false ) { return ''; }
    $open = strpos( $code, '{', $at );
    if ( $open === false ) { return ''; }
    $depth = 0;
    for ( $i = $open, $n = strlen( $code ); $i < $n; $i++ ) {
        if ( $code[ $i ] === '{' ) { $depth++; }
        elseif ( $code[ $i ] === '}' ) {
            $depth--;
            if ( $depth === 0 ) { return substr( $code, $at, $i - $at + 1 ); }
        }
    }
    return substr( $code, $at );
}

/** Render the panel and hand back its markup. */
function render_panel(): string {
    ob_start();
    \LGMS\TesterUnlockPanel::render();
    return (string) ob_get_clean();
}

/** Forget everything between scenarios: options, state file, reader cache. */
function reset_all(): void {
    global $OPTS, $STATE;
    $OPTS = [];
    @unlink( $STATE );
    $_GET = [];
    if ( function_exists( 'lg_tester_unlock_forget' ) ) { lg_tester_unlock_forget(); }
    clearstatcache();
}

echo "========================================================================\n";
echo "GATE 90 — the tester link is recoverable, and rotating kills the old one\n";
echo "========================================================================\n";

use LGMS\TesterUnlock;
use LGMS\TesterUnlockPanel;
use LGMS\CohortAllowlist;

// ---------------------------------------------------------------------------
section( '§H  LIVENESS — is this measuring the BRANCH, or the serving checkout?' );
// Every other assertion is meaningless if this one is wrong, so it runs first.
// ---------------------------------------------------------------------------

is_( function_exists( 'lg_tester_unlock_state' ),
     'H1  the loaded reader is the BRANCH one (it has the operator-store source)' );
is_( function_exists( 'lg_tester_unlock_forget' ),
     'H2  the loaded reader can drop its cache, so a write can be confirmed' );
is_( TesterUnlock::statePath() === $STATE,
     'H3  the store under test is this run\'s temp file, not the box\'s real one' );
is_( class_exists( 'LGMS\\TesterUnlock' ) && class_exists( 'LGMS\\TesterUnlockPanel' ),
     'H4  the real classes loaded (no stub is standing in for the code under test)' );

// A control: a token nothing has ever armed must NOT open the door. If this
// passed, `door_opens` would be answering "no" to everything and every refusal
// below would be vacuous.
reset_all();
is_( door_opens( bin2hex( random_bytes( 24 ) ) ) === false,
     'H5  CONTROL — with no store at all, no token opens the door' );

// ---------------------------------------------------------------------------
section( '§A  THE STORE — mint, rotate, clear, and where the token actually is' );
// ---------------------------------------------------------------------------

reset_all();
$d = TesterUnlock::describe();
is_( $d['mode'] === 'off',   'A1  with nothing minted the tab reads "off"' );
is_( $d['url'] === '',       'A2  and there is no link to show' );
is_( ! file_exists( $STATE ), 'A3  ABSENT BY DEFAULT — minting nothing writes nothing' );

$m = TesterUnlock::mint();
is_( $m['ok'] === true, 'A4  mint succeeds' . ( $m['ok'] ? '' : ' — ' . $m['error'] ) );
is_( preg_match( '/^[a-f0-9]{48}$/', (string) $m['token'] ) === 1,
     'A5  the token is 48 hex from random_bytes, not a guessable string' );
is_( $m['token'] !== '' && str_contains( (string) $m['url'], $m['token'] ),
     'A6  the link carries the token' );
is_( str_starts_with( (string) $m['url'], 'https://dev2.loothgroup.com/lgjoin/?lgtester=' ),
     'A7  the link names THIS box and the join page (home_url, not a baked-in host)' );

$first = (string) $m['token'];
is_( door_opens( $first ), 'A8  the minted link actually opens the door' );

// ---- the assertion that bites ---------------------------------------------
$m2 = TesterUnlock::mint();
is_( $m2['ok'] === true && $m2['token'] !== $first, 'A9a rotating mints a different token' );
is_( door_opens( $first ) === false,
     'A9  *** AFTER ROTATING, THE OLD LINK STOPS WORKING *** (the refusal, not the grant)' );
is_( door_opens( (string) $m2['token'] ),
     'A10 and the new link works — a rotation that broke both would satisfy A9 alone' );

// ---- what is stored where --------------------------------------------------
$raw = (string) file_get_contents( $STATE );
$j   = json_decode( $raw, true );
is_( is_array( $j ), 'A11 the shared store is valid JSON' );
is_( ( $j['enabled'] ?? null ) === true, 'A12 it says enabled' );
is_( preg_match( '/^[a-f0-9]{64}$/', (string) ( $j['token_sha256'] ?? '' ) ) === 1,
     'A13 it carries a sha256, not a token' );
is_( hash_equals( (string) $j['token_sha256'], hash( 'sha256', (string) $m2['token'] ) ),
     'A14 and that hash is the current token\'s' );
/* NO 48-hex string AT ALL, rather than "not the current token". The red-first
   run found that difference the hard way: a mutation writing the token into the
   file wrote the PREVIOUS one (the option is updated after the state file), and
   an assertion naming only the current token passed on a real leak. */
/* MAXIMAL hex runs, and every one of them must be the 64-char digest. A plain
   /[a-f0-9]{48}/ matches INSIDE the sha256 itself — 64 hex characters contain a
   48-hex substring — so the naive version fails on a correct file. The token is
   48; the hash is 64; nothing else belongs. */
preg_match_all( '/[a-f0-9]{40,}/', $raw, $runs );
$stray = array_values( array_filter( $runs[0], static fn( string $h ): bool => strlen( $h ) !== 64 ) );
is_( $stray === [],
     'A15 *** NO RAW TOKEN OF ANY AGE IS IN THE WORLD-READABLE FILE *** — seven unix users read it'
     . ( $stray ? ' — found ' . count( $stray ) . ' non-digest hex run(s)' : '' ) );
is_( ( fileperms( $STATE ) & 0777 ) === 0644,
     'A16 the store is 0644 — the seven app users share no group with looth-dev' );

// ---- clear -----------------------------------------------------------------
$c = TesterUnlock::clear();
is_( $c['ok'] === true, 'A17 clear succeeds' );
$j2 = json_decode( (string) file_get_contents( $STATE ), true );
is_( file_exists( $STATE ) && ( $j2['enabled'] ?? null ) === false,
     'A18 *** CLEAR WRITES enabled:false — IT DOES NOT DELETE THE FILE ***' );
is_( ( $j2['token_sha256'] ?? 'x' ) === '', 'A19 and it empties the hash' );
is_( TesterUnlock::token() === '', 'A20 the dash forgets the token too' );
is_( door_opens( (string) $m2['token'] ) === false, 'A21 the cleared link is dead' );

// ---------------------------------------------------------------------------
section( '§B  THE READER — precedence, and every way the store can be wrong' );
// ---------------------------------------------------------------------------

reset_all();
$tok  = bin2hex( random_bytes( 24 ) );
$hash = hash( 'sha256', $tok );

/** Write an arbitrary shape into the operator store. */
$putState = static function ( $content ) use ( $STATE ): void {
    file_put_contents( $STATE, is_string( $content ) ? $content : (string) json_encode( $content ) );
};

$putState( [ 'enabled' => true, 'token_sha256' => $hash ] );
is_( door_opens( $tok ), 'B1  an armed operator store opens the door' );

// FAIL CLOSED — every one of these is a way the file can be wrong.
$putState( [ 'enabled' => false, 'token_sha256' => $hash ] );
is_( door_opens( $tok ) === false, 'B2  enabled:false disarms even with a good hash' );

$putState( [ 'enabled' => true, 'token_sha256' => '' ] );
is_( door_opens( $tok ) === false, 'B3  enabled with an empty hash is DEAD, not permissive' );

$putState( [ 'enabled' => true, 'token_sha256' => 'not-a-hash' ] );
is_( door_opens( $tok ) === false, 'B4  a malformed hash fails to "nothing matches", never a looser compare' );
/* B4 alone passes WITHOUT the 64-hex validation, because garbage never
   hash_equals a real digest either — so it is not evidence the validation
   works. What the validation buys is that the box reads UNARMED rather than
   "armed on something that can never match", which is what the panel reports
   to whoever is looking. Found by red-first M10. */
lg_tester_unlock_forget();
is_( lg_tester_unlock_armed() === false,
     'B4b and the box reads UNARMED, not "armed on a hash that cannot match"' );

$putState( '{ this is not json' );
is_( door_opens( $tok ) === false, 'B5a a truncated / non-JSON file arms nothing and does not fatal' );

$putState( '"a string, not an object"' );
is_( door_opens( $tok ) === false, 'B5b a non-array JSON document arms nothing' );

$putState( [ 'enabled' => true, 'token_sha256' => $hash ] );
is_( door_opens( 'wrong-token' ) === false, 'B5c an armed store still refuses the WRONG token' );
is_( door_opens( '' ) === false,            'B5d and refuses an empty one' );

// ---- THE PRECEDENCE THAT MATTERS ------------------------------------------
// dev2 carries an armed platform/config/tester-unlock.local.php right now. If
// the operator store lost to it, Clear would appear to work in the dash while
// the site stayed armed — the button would lie.
$boxTok  = bin2hex( random_bytes( 24 ) );
$boxHash = hash( 'sha256', $boxTok );
$fakeRepo = $RUN . '/tree';
@mkdir( $fakeRepo . '/lg-shared', 0755, true );
@mkdir( $fakeRepo . '/platform/config', 0755, true );
copy( $GATE_ROOT . '/lg-shared/tester-unlock.php', $fakeRepo . '/lg-shared/tester-unlock.php' );
copy( $GATE_ROOT . '/platform/config/tester-unlock.php', $fakeRepo . '/platform/config/tester-unlock.php' );
file_put_contents( $fakeRepo . '/platform/config/tester-unlock.local.php',
    "<?php return array('enabled' => true, 'token_sha256' => '" . $boxHash . "');\n" );
$boxReader = $fakeRepo . '/lg-shared/tester-unlock.php';
$boxState  = $RUN . '/box-state.json';

// baseline: the box file alone arms the box
@unlink( $boxState );
$code = 'require ' . var_export( $boxReader, true ) . '; echo lg_tester_unlock_token_matches((string)getenv("TOK")) ? "YES":"NO";';
$boxOnly = trim( (string) shell_exec( 'LG_TESTER_UNLOCK_STATE=' . escapeshellarg( $boxState ) . ' TOK=' . escapeshellarg( $boxTok ) . ' php -r ' . escapeshellarg( $code ) . ' 2>&1' ) );
is_( $boxOnly === 'YES', 'B6  LIVENESS — a hand-placed .local.php really does arm a box' );

// now the operator store says OFF. The box file must lose.
file_put_contents( $boxState, (string) json_encode( [ 'enabled' => false, 'token_sha256' => '' ] ) );
$afterClear = trim( (string) shell_exec( 'LG_TESTER_UNLOCK_STATE=' . escapeshellarg( $boxState ) . ' TOK=' . escapeshellarg( $boxTok ) . ' php -r ' . escapeshellarg( $code ) . ' 2>&1' ) );
is_( $afterClear === 'NO',
     'B7  *** TURNING IT OFF BEATS AN ARMED .local.php *** — the state dev2 is in today' );

/* DISABLE WITHOUT FORGETTING THE TOKEN. The store says enabled:false while
   still carrying the box file's own VALID hash — so only the `enabled` half can
   refuse here. Neither B2 nor B7 covers it: both empty the hash as well, and a
   build where enabled:false could no longer turn anything OFF passed both.
   Found by red-first M11. */
file_put_contents( $boxState, (string) json_encode( [ 'enabled' => false, 'token_sha256' => $boxHash ] ) );
$disabledOnly = trim( (string) shell_exec( 'LG_TESTER_UNLOCK_STATE=' . escapeshellarg( $boxState ) . ' TOK=' . escapeshellarg( $boxTok ) . ' php -r ' . escapeshellarg( $code ) . ' 2>&1' ) );
is_( $disabledOnly === 'NO',
     'B7b enabled:false disarms ON ITS OWN, with a valid hash still in the file' );

// and an ABSENT operator store must leave the box file standing, or every box
// running on a hand-placed file would silently disarm on the merge.
@unlink( $boxState );
$absent = trim( (string) shell_exec( 'LG_TESTER_UNLOCK_STATE=' . escapeshellarg( $boxState ) . ' TOK=' . escapeshellarg( $boxTok ) . ' php -r ' . escapeshellarg( $code ) . ' 2>&1' ) );
is_( $absent === 'YES',
     'B8  an ABSENT operator store changes nothing — a merge cannot disarm a box' );

// ---- the new source is invisible when it does not exist --------------------
$mainReader = $RUN . '/main-tester-unlock.php';
$got = shell_exec( 'cd ' . escapeshellarg( $GATE_ROOT ) . ' && git show origin/main:lg-shared/tester-unlock.php 2>/dev/null' );
if ( is_string( $got ) && $got !== '' ) {
    file_put_contents( $mainReader, $got );
    $shapes = [
        'absent config'  => [],
        'env armed'      => [ 'LG_TESTER_UNLOCK' => '1', 'LG_TESTER_UNLOCK_SHA256' => $hash ],
        'env disabled'   => [ 'LG_TESTER_UNLOCK' => '0' ],
    ];
    $identical = true;
    foreach ( $shapes as $name => $env ) {
        $env['LG_TESTER_UNLOCK_STATE'] = $RUN . '/does-not-exist.json';
        if ( resolved_config( $GATE_ROOT . '/lg-shared/tester-unlock.php', $env ) !== resolved_config( $mainReader, $env ) ) {
            $identical = false;
            bad( "B9  branch and main disagree with NO operator store, shape: $name" );
        }
    }
    if ( $identical ) {
        ok( 'B9  WITH NO OPERATOR STORE THE BRANCH RESOLVES EXACTLY WHAT origin/main RESOLVES (3 shapes)' );
    }
} else {
    report( 'B9 SKIPPED — origin/main not reachable from this checkout, so branch-vs-main could not be compared.' );
}

// ---------------------------------------------------------------------------
section( '§D  THE PANEL — what is actually on the page, in each state' );
// ---------------------------------------------------------------------------

// ---- 'off' -----------------------------------------------------------------
reset_all();
putenv( 'LG_HEADER_JOIN_STRIPE=allowlist' ); $_SERVER['LG_HEADER_JOIN_STRIPE'] = 'allowlist';
$html = render_panel();
is_( str_contains( $html, 'There is no tester link on this box' ), 'D1  "off" says so in plain words' );
is_( ! preg_match( '/lgtester=[a-f0-9]{8}/', $html ), 'D2  "off" prints no link' );
is_( str_contains( $html, 'Create the tester link' ), 'D3  and offers to make one' );
is_( ! str_contains( $html, 'Turn it off' ), 'D4  with nothing to turn off, no Turn-it-off button' );

// ---- 'dash' — the normal state ---------------------------------------------
reset_all();
$m = TesterUnlock::mint();
$html = render_panel();
is_( str_contains( $html, (string) $m['token'] ), 'D5  *** THE LIVE LINK IS ON THE PAGE, IN FULL ***' );
is_( str_contains( $html, 'id="lgms-tester-copy"' ), 'D5b and there is a Copy button beside it' );
is_( str_contains( $html, 'unlock ARMED' ), 'D5c the state chip reads ARMED' );
is_( str_contains( $html, 'Rotate — mint a new link' ), 'D5d Rotate names what it does' );

// ---- 'foreign' — armed by something else. THE ANTI-VACUOUS CASE ------------
reset_all();
/* THE FIXTURE MUST HOLD A TOKEN, or there is nothing for a broken panel to
   print and D6 passes on any build. This is the realistic shape: the dash
   minted once, then somebody hand-placed a tester-unlock.local.php with a
   different token — dev2's exact future. Found by red-first M6 and M13, both
   of which produced a live-looking dead link and left D6 green. */
update_option( TesterUnlock::OPT_TOKEN, bin2hex( random_bytes( 24 ) ) );
file_put_contents( $STATE, (string) json_encode( [ 'enabled' => true, 'token_sha256' => hash( 'sha256', 'someone-elses-token' ) ] ) );
lg_tester_unlock_forget();
$html = render_panel();
is_( ! preg_match( '/lgtester=[a-f0-9]{8}/', $html ),
     'D6  *** ARMED BY SOMETHING ELSE ⇒ NO LINK IS PRINTED *** (a dead link that looks live is the defect)' );
is_( str_contains( $html, 'not by this dash' ), 'D7  and it says why, rather than showing nothing' );
is_( str_contains( $html, 'unlock ARMED' ), 'D8  while still reporting the box IS armed' );

// ---- 'stale' — a token here, but the site is not armed ---------------------
reset_all();
update_option( TesterUnlock::OPT_TOKEN, bin2hex( random_bytes( 24 ) ) );
$html = render_panel();
is_( str_contains( $html, 'does not read as armed' ), 'D9  a stored token on a disarmed box is called out' );
is_( ! preg_match( '/value="https:[^"]*lgtester=/', $html ), 'D10 and the dead link is not offered for sending' );

// ---- the #170 pairing ------------------------------------------------------
reset_all();
putenv( 'LG_HEADER_JOIN_STRIPE=off' ); $_SERVER['LG_HEADER_JOIN_STRIPE'] = 'off';
TesterUnlock::mint();
$html = render_panel();
is_( str_contains( $html, 'the header is <code>off</code>, so it currently does nothing' ),
     'D11 armed + header off ⇒ the panel says the link is inert (#170: off means NOBODY)' );
putenv( 'LG_HEADER_JOIN_STRIPE=allowlist' ); $_SERVER['LG_HEADER_JOIN_STRIPE'] = 'allowlist';

// ---- both forms are nonced -------------------------------------------------
reset_all();
TesterUnlock::mint();
$html = render_panel();
is_( str_contains( $html, 'data-action="lgms_tester_rotate"' ), 'D12 the rotate form carries a nonce' );
is_( str_contains( $html, 'data-action="lgms_tester_clear"' ),  'D13 the clear form carries a nonce' );
is_( substr_count( $html, 'onclick="return confirm(' ) === 2,
     'D14 both destructive buttons confirm; creating the FIRST link does not' );

reset_all();
$html = render_panel();
is_( substr_count( $html, 'onclick="return confirm(' ) === 0,
     'D15 CONTROL — with nothing to break there is no confirm to train people to dismiss' );

// ---- the store is unwritable ----------------------------------------------
reset_all();
putenv( 'LG_TESTER_UNLOCK_STATE=/proc/definitely-not-writable/x.json' );
$_SERVER['LG_TESTER_UNLOCK_STATE'] = '/proc/definitely-not-writable/x.json';
lg_tester_unlock_forget();
$html = render_panel();
is_( str_contains( $html, 'cannot store a tester link yet' ), 'D16 an unwritable store is reported, not hidden' );
is_( str_contains( $html, 'install -d -o looth-dev' ), 'D17 and the panel names the one-time fix' );
$r = TesterUnlock::mint();
is_( $r['ok'] === false, 'D18 and Rotate REFUSES rather than half-working' );
putenv( 'LG_TESTER_UNLOCK_STATE=' . $STATE );
$_SERVER['LG_TESTER_UNLOCK_STATE'] = $STATE;
lg_tester_unlock_forget();

// ---------------------------------------------------------------------------
section( '§E  THE HANDLERS — asserted by SOURCE (see the header: Admin.php is not loaded)' );
// ---------------------------------------------------------------------------

// Comment-blind for the same reason as §F: this file's docblocks discuss
// add_options_page and options-general.php at length.
$admin = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/Admin.php' );

foreach ( [ 'handleTesterRotate' => 'a', 'handleTesterClear' => 'b' ] as $fn => $sfx ) {
    $body = php_function_body( $admin, $fn );
    is_( $body !== '', "E1$sfx $fn exists" );
    is_( str_contains( $body, "current_user_can( 'manage_options' )" ), "E2$sfx $fn requires manage_options" );
    is_( str_contains( $body, 'check_admin_referer' ), "E3$sfx $fn requires a nonce" );
}

is_( str_contains( $admin, "add_action( 'admin_post_lgms_tester_rotate'" )
     && str_contains( $admin, "add_action( 'admin_post_lgms_tester_clear'" ),
     'E4  both handlers are actually registered (an unregistered one is a dead button)' );

// THE RAW TOKEN MUST NOT RIDE A REDIRECT. The invite panel next door does
// exactly that, so this is a live shape in this very file, not a hypothetical.
$rotateBody = php_function_body( $admin, 'handleTesterRotate' );
is_( ! preg_match( "/'lgms_tester(_link|_token)'/", $rotateBody )
     && ! str_contains( $rotateBody, "rawurlencode( \$r['token'] )" )
     && ! str_contains( $rotateBody, "\$r['url']" ),
     'E5  *** THE RAW TOKEN NEVER RIDES A REDIRECT *** (no admin URL, history, or Referer)' );
is_( str_contains( $rotateBody, 'siteArmed()' ),
     'E6  rotate CONFIRMS the box is armed rather than assuming its own write worked' );

// ---------------------------------------------------------------------------
section( '§F  STRUCTURE — the token cannot leak through the repo' );
// ---------------------------------------------------------------------------

$tracked = (string) shell_exec( 'cd ' . escapeshellarg( $GATE_ROOT ) . ' && git ls-files 2>/dev/null' );
$leaks = [];
foreach ( array_filter( explode( "\n", trim( $tracked ) ) ) as $f ) {
    if ( ! is_readable( $GATE_ROOT . '/' . $f ) || is_dir( $GATE_ROOT . '/' . $f ) ) { continue; }
    if ( filesize( $GATE_ROOT . '/' . $f ) > 400000 ) { continue; }
    $c = (string) file_get_contents( $GATE_ROOT . '/' . $f );
    // a stored token beside the option name is the shape that would matter
    if ( str_contains( $c, 'lgms_tester_unlock_token' ) && preg_match( '/[a-f0-9]{48}/', $c ) ) {
        $leaks[] = $f;
    }
}
is_( $leaks === [], 'F1  no tracked file pairs the token option with a 48-hex value' . ( $leaks ? ' — ' . implode( ', ', $leaks ) : '' ) );

$store = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/TesterUnlock.php' );
is_( str_contains( $store, '/srv/lg-shared-state' ),
     'F2  the operator store lives OUTSIDE the serving checkout, which only ever pulls' );

// writeState receives a HASH and nothing else. Asserted on the function's own
// code so a future edit cannot start handing it the token "just for logging".
$wsStart = strpos( $store, 'function writeState(' );
$wsBody  = $wsStart === false ? '' : substr( $store, $wsStart, 1600 );
is_( $wsStart !== false && ! str_contains( $wsBody, '$token' ),
     'F3  writeState never even sees a raw token — only the hash' );

$reader = php_code_only( $GATE_ROOT . '/lg-shared/tester-unlock.php' );
/* The CALL SITES, not the declarations — `function lg_tester_unlock_state()` is
   declared above the resolver that uses it, so searching for the bare name finds
   the definition and reports the order backwards. (It did.) */
$statePos = strpos( $reader, '$apply(lg_tester_unlock_state())' );
$localPos = strpos( $reader, "tester-unlock.local.php'" );
$envPos   = strpos( $reader, "getenv('LG_TESTER_UNLOCK')," );
$ordered  = $localPos !== false && $statePos !== false && $envPos !== false
            && $localPos < $statePos && $statePos < $envPos;
is_( $ordered, 'F4  ORDER: tracked → box file → operator store → env (B7 is what this buys)'
     . ( $ordered ? '' : sprintf( ' — local=%s state=%s env=%s',
         var_export( $localPos, true ), var_export( $statePos, true ), var_export( $envPos, true ) ) ) );

// The panel is separable from Admin.php — the property that makes §D possible.
$panelCode = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/TesterUnlockPanel.php' );
$heavy = array_values( array_filter( [ 'StripeLifecycle', 'StripePrice', 'Invites', 'CompExpiry' ],
    static fn( string $c ): bool => str_contains( $panelCode, $c ) ) );
is_( $heavy === [],
     'F5  the panel drags in none of the dash\'s heavy collaborators' . ( $heavy ? ' — ' . implode( ', ', $heavy ) : '' ) );

// ---------------------------------------------------------------------------
section( '§G  PLACEMENT — Ian ruled top-level; this records where it actually is' );
// ---------------------------------------------------------------------------

$topLevel = (bool) preg_match( '/add_menu_page\(\s*\n\s*\'LG Member Sync\'/', $admin );
$underSettings = (bool) preg_match( '/add_options_page\(\s*\n\s*\'LG Member Sync\'/', $admin );

if ( $topLevel && ! $underSettings ) {
    ok( 'G1  LG Member Sync is a TOP-LEVEL menu (Ian: "I want it in main dash, not in settings or tool")' );
    is_( str_contains( $admin, "private const PARENT_FILE = 'admin.php'" ),
         'G2  and PARENT_FILE agrees, so every redirect lands on the page that exists' );
    is_( str_contains( $admin, "'toplevel_page_' . self::OPT_PAGE" ) || str_contains( $admin, 'toplevel_page_' ),
         'G3  and the enqueue hook is toplevel_page_ — the silent one (media uploader)' );
    $tools = (string) file_get_contents( $GATE_ROOT . '/platform/mu-plugins/lg-admin-tools.php' );
    is_( str_contains( $tools, "admin.php?page=lg-member-sync" ),
         'G4  lg-admin-tools\' link to this page is no longer dead' );
} else {
    report( 'G1 NOT YET TOP-LEVEL. Admin.php still registers LG Member Sync with add_options_page, so it '
          . 'sits under Settings — the one place Ian ruled against ("I want it in main dash, not in settings '
          . 'or tool"). #190 says it is already add_menu_page; measured 2026-08-21, it is not. Reported rather '
          . 'than asserted so this gate does not redden a lane that has not reached the move yet; it turns '
          . 'into four hard assertions the moment it does. Corollary, live right now: '
          . 'platform/mu-plugins/lg-admin-tools.php:67 links to admin.php?page=lg-member-sync, which 404s.' );
    is_( str_contains( $admin, 'private const PARENT_FILE' ),
         'G2  the parent file is a single constant, so the move is one line and cannot half-land' );
    is_( substr_count( $admin, "admin_url( 'options-general.php' )" ) === 0,
         'G3  no redirect hardcodes the parent file behind that constant\'s back' );
}

// ---------------------------------------------------------------------------
section( '§C  COUPLING — reported, never asserted' );
// ---------------------------------------------------------------------------

$serving = '/srv/lg-shared/site-header.php';
if ( is_readable( $serving ) ) {
    $st = trim( (string) shell_exec( 'php -r ' . escapeshellarg(
        'require ' . var_export( $serving, true ) . '; echo lg_shared_header_join_stripe_state();'
    ) . ' 2>&1' ) );
    report( "C1 THE SERVING CHECKOUT's header-join-stripe is '$st'."
          . ( in_array( $st, [ 'allowlist', 'on' ], true )
              ? ' The unlock is live on this box once armed.'
              : " In '$st' the unlock is INERT by ruling — arming it changes nothing until the header moves." ) );
}
$boxStore = '/srv/lg-shared-state/tester-unlock.json';
report( 'C2 the box\'s real operator store ' . ( file_exists( $boxStore ) ? 'EXISTS' : 'does not exist yet' )
      . " at $boxStore" . ( is_dir( dirname( $boxStore ) ) ? '' : ' — and neither does its directory, so the dash cannot mint until keeper runs the one-time install -d.' ) );
if ( is_readable( $GATE_ROOT . '/../loothplatformv2-clean/platform/config/tester-unlock.local.php' ) ) {
    report( 'C3 the serving checkout carries a hand-placed tester-unlock.local.php — so this box is in the '
          . '"foreign" state until the dash rotates, and §B7 is the assertion that covers it.' );
}

// ---------------------------------------------------------------------------
foreach ( $reports as $r ) { echo "\n  REPORT  $r\n"; }
echo "\n";
if ( $fail > 0 ) {
    echo "GATE 90 RED — $fail failed, $pass passed\n";
    exit( 1 );
}
echo "GATE 90 GREEN — $pass assertions\n";
exit( 0 );

}
