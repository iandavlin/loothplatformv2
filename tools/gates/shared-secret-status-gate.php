<?php
/**
 * GATE 98 — THE SHARED SECRET'S STATUS IS TRUE, CURRENT, AND SAYS NOTHING ELSE.
 *
 *   php tools/gates/shared-secret-status-gate.php
 *
 * Exit 0 green, 1 a real defect, 2 cannot run (run-all.sh's convention).
 *
 * WHAT THIS EXISTS FOR (#201). Ian, 2026-08-22, reshaping his own issue:
 * *"Should just be a refresh button or something with a status check."*
 *
 * `lgms_shared_secret` authenticates the billing app's server-to-server calls
 * into WordPress. It is ABSENT ON LIVE. #181's checkout guard is fail-open by
 * design — a route that cannot answer produces UNKNOWN and UNKNOWN waves every
 * checkout through — so the guard reads as armed on the dash and refuses
 * nobody, including the listed tester who actively pays Patreon. Nothing on any
 * screen said so. That silence is the defect this section ends.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR ASSERTIONS THAT LOOK RIGHT AND MEASURE NOTHING
 * ---------------------------------------------------------------------------
 *
 * ⚠️ (1) "THE PANEL RENDERS AND SAYS OK" passes on a healthy box, and a status
 * panel is worthless on a healthy box. Every verdict below is posed against a
 * DELIBERATELY BROKEN state — mismatched halves, each half absent in turn, and
 * all four states of a settings file that cannot be read — and the panel must
 * NAME it. Same family as #148's "a PRO purchase grants looth3" passing on a
 * constant.
 *
 * ⚠️ (2) "NO SECRET IS PRINTED" tested on the happy path only. §C tests the
 * REFRESH RESPONSE and the ERROR PATH as well, and the error path is the one
 * that matters: a Throwable out of a file read or a database handle can carry a
 * value in its message. §C3 throws an exception whose message CONTAINS the
 * secret and requires that none of it — value, fragment, prefix or sha256 —
 * reaches the response.
 *
 * ⚠️ (3) "THE REFRESH BUTTON EXISTS" is satisfied by a button that re-renders a
 * cached answer, which is a lie told convincingly. §G proves the read is
 * uncached BEHAVIOURALLY: a stale value is seeded in the option cache and a
 * different one in the store, and the panel must report the store's.
 *
 * ⚠️ (4) "THE FIELD WAS REMOVED FROM THE SETTINGS TAB" is the dangerous half of
 * #201's ruling, because removing the FIELD while leaving the REGISTRATION does
 * not leave the option alone — wp-admin/options.php walks the registered
 * options of the submitted group and calls `update_option( $option, null )` for
 * every one absent from POST. The secret would be blanked by anyone pressing
 * Save, silently. §I2 asserts the registration is gone, not just the input.
 *
 * WHAT IS REAL AND WHAT IS STUBBED. Membership\Health, SharedSecretPanel and
 * HealthPanel are the REAL code; every decision under test is real, and the
 * settings file is a REAL FILE on disk under a per-run directory keyed to the
 * PID, so two suites at once cannot collide. WordPress's option store, its
 * object cache, its escaping, its nonce and its JSON responders are observable
 * stand-ins. No browser, no FPM, no WordPress, no network — so this gate cannot
 * go vacuously green behind a locked-out browser.
 *
 * ⚠️ IT DELIBERATELY DOES NOT LOAD Admin.php, and §I asserts that file by
 * SOURCE through PHP's TOKENIZER rather than a regex. That is not fastidiousness:
 * this lane's own change left a long comment in Admin.php that NAMES the option
 * it removed, so a grep for `lgms_shared_secret` matches the explanation of its
 * absence and reports the defect as fixed either way. Gate 90's equivalents
 * matched their own explanatory prose twice.
 *
 * RED-FIRST: see tools/gates/shared-secret-redfirst.py.
 */

declare(strict_types=1);

namespace {

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$GATE_ROOT = dirname( __DIR__, 2 );
$RUN       = sys_get_temp_dir() . '/lg-gate98-' . getmypid();

$pass = 0; $fail = 0; $reports = [];

function ok( string $m ): void      { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void     { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function report( string $m ): void  { global $reports; $reports[] = $m; }
/* EXIT 2, NOT 3. run-all.sh reads 0 green / 2 CANNOT RUN / anything else RED,
   so a cannot-run that exits 3 reports a missing environment as a finding and
   blocks every lane behind it. */
function cannot( string $why ): void { echo "CANNOT RUN: $why\n"; exit( 2 ); }
function section( string $t ): void { echo "\n$t\n"; }

/* ⚠️ A FATAL IS A FINDING, NOT A MISSING ENVIRONMENT. The membership plugin's
   test files have died at exit 255 with NO FAIL LINE FOUR separate times now,
   each because a door gained a dependency nobody added to a require list —
   #181's two, #194's two mutations. run-all reads a bare 255 as "red, culprit
   unknown"; this names it. */
set_exception_handler( static function ( \Throwable $e ): void {
    echo "  FAIL FATAL: uncaught " . get_class( $e ) . ' — ' . $e->getMessage()
       . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
    echo "############ GATE 98 RED (fatal) ############\n";
    exit( 1 );
} );
register_shutdown_function( static function (): void {
    $e = error_get_last();
    if ( $e !== null && in_array( $e['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ], true ) ) {
        echo "  FAIL FATAL: " . $e['message'] . ' at ' . basename( $e['file'] ) . ':' . $e['line'] . "\n";
        echo "############ GATE 98 RED (fatal) ############\n";
        exit( 1 );
    }
} );

if ( ! @mkdir( $RUN, 0755, true ) && ! is_dir( $RUN ) ) {
    cannot( "could not create the per-run directory $RUN" );
}
register_shutdown_function( static function () use ( $RUN ) {
    if ( is_dir( $RUN ) ) { @exec( 'rm -rf ' . escapeshellarg( $RUN ) ); }
} );

const HEALTH_REL = '/lg-patreon-stripe-poller/src/Membership/Health.php';
const PANEL_REL  = '/lg-patreon-stripe-poller/src/SharedSecretPanel.php';
const HPANEL_REL = '/lg-patreon-stripe-poller/src/HealthPanel.php';
const ADMIN_REL  = '/lg-patreon-stripe-poller/src/Admin.php';

foreach ( [ HEALTH_REL, PANEL_REL, HPANEL_REL, ADMIN_REL ] as $need ) {
    if ( ! is_readable( $GATE_ROOT . $need ) ) { cannot( "missing $need" ); }
}

// ---- WordPress stand-ins ---------------------------------------------------

/* THE OPTION STORE AND THE OBJECT CACHE ARE SEPARATE HERE ON PURPOSE — that
   separation is what makes §G a measurement instead of a source grep. dev2 runs
   a persistent object cache (wp-content/object-cache.php, 105,926 bytes) and
   `lgms_shared_secret` is autoloaded, so the two really can disagree. */
$OPTS      = [];
$OCACHE    = [];
$JSON      = null;     // the last wp_send_json_* payload
$NONCE_OK  = true;
$THROW_ON_OPTION = '';  // set to a message to make get_option throw
$THROW_ON_ESC_TEXT = ''; // makes the escaper throw when it is handed this text — see esc_html()
$ENV_SEQ = 0;           // one settings file per fixture — see write_env()

final class NonceDied extends \RuntimeException {}

/* ⚠️ THE TWO CACHE LAYERS ARE MODELLED SEPARATELY, AND THE FIRST DRAFT DID NOT.
   It let a delete of `alloptions` wipe the whole stub cache, which made the
   single-key delete carry no weight — removing it from the panel changed
   nothing and §G1 sat green on a real regression. WordPress serves an AUTOLOADED
   option out of the `alloptions` blob and a non-autoloaded one from its own key,
   and dropping one does not drop the other. Both are posed, because
   `lgms_shared_secret` is autoloaded on dev2 (measured) and need not stay so. */
function get_option( $k, $d = false ) {
    global $OPTS, $OCACHE, $THROW_ON_OPTION;
    if ( $THROW_ON_OPTION !== '' ) {
        throw new \RuntimeException( $THROW_ON_OPTION );
    }
    if ( isset( $OCACHE['alloptions'] ) && array_key_exists( $k, $OCACHE['alloptions'] ) ) {
        return $OCACHE['alloptions'][ $k ];
    }
    if ( array_key_exists( $k, $OCACHE ) ) { return $OCACHE[ $k ]; }
    return array_key_exists( $k, $OPTS ) ? $OPTS[ $k ] : $d;
}
function update_option( $k, $v, $a = null ) { global $OPTS; $OPTS[ $k ] = $v; return true; }
function wp_cache_delete( $k, $group = '' ) {
    global $OCACHE;
    unset( $OCACHE[ $k ] );
    return true;
}
function current_user_can( $cap ) { global $CAP; return (bool) ( $CAP ?? true ); }
function check_ajax_referer( $action, $q = false, $die = true ) {
    global $NONCE_OK;
    if ( ! $NONCE_OK ) { throw new NonceDied( 'bad nonce' ); }
    return true;
}
function wp_create_nonce( $a ) { return 'nonce-' . substr( md5( (string) $a ), 0, 10 ); }
function wp_send_json_success( $d = null, $code = 200 ) { global $JSON; $JSON = [ 'ok' => true,  'code' => $code, 'data' => $d ]; }
function wp_send_json_error( $d = null, $code = 500 )   { global $JSON; $JSON = [ 'ok' => false, 'code' => $code, 'data' => $d ]; }
function add_action( ...$a ) { global $ACTIONS; $ACTIONS[] = $a; return true; }
/* ⚠️ C3e WAS VACUOUS ON ITS FIRST DRAFT and this is the fix. The only throw the
   gate could pose came from `refreshRead()`, which runs BEFORE `renderBody()`,
   so the output buffer was always empty and "no partial markup shipped" was
   true of every build including a broken one. A renderer that dies half way
   through is the case that matters, so the escaper can be made to throw on its
   Nth call and the buffer really does hold a fragment. */
function esc_html( $s )  {
    global $THROW_ON_ESC_TEXT;
    /* ⚠️ KEYED ON THE TEXT, NOT ON A CALL COUNT. The first version counted calls
       and fired one too early — before the table was emitted — so the buffer was
       empty and C3e passed on a build that shipped fragments. A count is also
       silently invalidated by any new escape added to the renderer, which is the
       shape that leaves an assertion alive-looking and dead. */
    if ( $THROW_ON_ESC_TEXT !== '' && (string) $s === $THROW_ON_ESC_TEXT ) {
        throw new \RuntimeException( 'render died holding ' . WP_SHARED );
    }
    return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s )  { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )   { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function __( $s, $d = null ) { return $s; }
function delete_option( $k ) { global $OPTS; unset( $OPTS[ $k ] ); return true; }
function home_url( $p = '' ) { return 'https://dev2.loothgroup.com' . $p; }
function admin_url( $p = '' ) { return 'https://dev2.loothgroup.com/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://dev2.loothgroup.com/wp-json/' . ltrim( (string) $p, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return $c === -1 ? parse_url( (string) $u ) : parse_url( (string) $u, $c ); }
function apply_filters( $tag, $value ) { return $value; }
function has_filter( $tag, $fn = false ) { return false; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_users( $a = [] ) { return []; }
function absint( $v ) { return abs( (int) $v ); }
$ACTIONS = [];
$CAP     = true;

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/var/www/dev/' ); }

/* ⚠️ §I6 drives the REAL `Health::describe()`, which reaches CheckoutAudience,
   CohortAllowlist, StripeLifecycle, StripePrice and Db. The first run of this
   gate died at that line with a bare fatal — the exact failure this file's own
   docblock warns about, four times over in this plugin's test files, caught here
   by the exception handler installed above rather than by a person. The list is
   explicit for that reason: a gate must FAIL, never DIE. */
} // end global namespace

namespace LGMS {

    use PDO;
    use RuntimeException;

    /* The billing database is not this gate's subject — the shared secret is —
       so this stands in just far enough for describe()'s other checks to reach
       their own honest `unknown` branches instead of fataling. Their SQL is
       gate 91's subject and is exercised there against real SQLite. */
    final class Db
    {
        public static function pdo(): PDO
        {
            throw new RuntimeException( 'SQLSTATE[HY000] [2002] Connection refused' );
        }
    }
}

namespace {

/* The loopback probe is swapped at Health::$transport rather than at the
   WordPress layer, because it is NOT a WordPress call: this plugin's CLAUDE.md
   requires raw curl with CURLOPT_RESOLVE for every server-to-server hop. Without
   this the gate would make a real HTTPS connection on every run. */
function install_probe_transport(): void {
    \LGMS\Membership\Health::$transport = static function ( string $url, array $opts ): array {
        return [ 'error' => '', 'code' => 200, 'body' => '{"state":"allowlist","allowed":false}' ];
    };
}

require_once $GATE_ROOT . '/lg-patreon-stripe-poller/src/Log.php';
require_once $GATE_ROOT . '/lg-patreon-stripe-poller/src/StripeLifecycle.php';
require_once $GATE_ROOT . '/lg-patreon-stripe-poller/src/CohortAllowlist.php';
require_once $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php';
require_once $GATE_ROOT . '/lg-patreon-stripe-poller/src/StripePrice.php';
require_once $GATE_ROOT . HEALTH_REL;
require_once $GATE_ROOT . PANEL_REL;

use LGMS\Membership\Health;
use LGMS\SharedSecretPanel;

install_probe_transport();

// ---- Fixtures --------------------------------------------------------------

/* REAL-LOOKING SECRETS. A fixture of "x" proves nothing about leaking: the
   assertion has to be able to FIND the value if it escapes, and a short or
   repetitive value hides inside ordinary markup. */
const WP_SHARED  = 'b7f4c1a9e2d6083b5c4e7a1f9d2b6e8c3a5f7d9b1e4c6a8f2d5b7e9c1a3f6d8b';
const APP_SHARED = 'b7f4c1a9e2d6083b5c4e7a1f9d2b6e8c3a5f7d9b1e4c6a8f2d5b7e9c1a3f6d8b';
const OTHER_SEC  = '9c2e5a8d1f4b7e0c3a6d9f2b5e8c1a4d7f0b3e6c9a2d5f8b1e4c7a0d3f6b9e2c';
const APP_WHSEC  = 'whsec_9K2mQ7xR4tL8vN1pS6yB3dF5gH0jZ';

/**
 * ⚠️ EVERY FIXTURE GETS ITS OWN FILE, AND §C4 IS WHY. The first draft wrote one
 * path, so the six `healthy_env(...)` calls in that section's array literal —
 * all evaluated BEFORE the loop body runs — each overwrote the last, and the
 * loop then rendered the SAME file six times under six different names. It
 * reported "all six verdicts checked" having checked one. A named path is
 * available for the one case that genuinely needs the same file to change
 * underneath the reader (§G3).
 */
function write_env( array $kv, ?string $name = null ): string {
    global $RUN, $ENV_SEQ;
    $p = $RUN . '/' . ( $name ?? 'app-' . ( ++$ENV_SEQ ) . '.env' );
    $s = '';
    foreach ( $kv as $k => $v ) { if ( $v !== null ) { $s .= $k . '=' . $v . "\n"; } }
    file_put_contents( $p, $s );
    return $p;
}

function healthy_env( array $over = [] ): string {
    return write_env( array_merge( [
        'APP_ENV'               => 'dev',
        'STRIPE_MODE'           => 'test',
        'STRIPE_SECRET_KEY'     => 'sk_test_51ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        'STRIPE_WEBHOOK_SECRET' => APP_WHSEC,
        'LGMS_SHARED_SECRET'    => APP_SHARED,
        'LGMS_SYNC_URL'         => 'https://dev2.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer',
    ], $over ) );
}

/**
 * Point the reader at a settings file and a WordPress option store, then take a
 * reading through the REAL Health.
 */
function scenario( ?string $envPath, array $opts = [], array $cache = [] ): array {
    global $OPTS, $OCACHE, $THROW_ON_OPTION, $THROW_ON_ESC_TEXT;
    $THROW_ON_OPTION = '';
    $THROW_ON_ESC_TEXT = '';
    $_SERVER['LG_HEALTH_APP_ENV'] = (string) $envPath;
    $OPTS   = array_merge( [ 'lgms_shared_secret' => WP_SHARED, 'lgms_stripe_webhook_secret' => APP_WHSEC ], $opts );
    $OCACHE = $cache;
    Health::reset();
    return Health::sharedSecret();
}

function render_section( ?array $s = null ): string {
    ob_start();
    SharedSecretPanel::render( $s );
    return (string) ob_get_clean();
}

function render_body( ?array $s = null ): string {
    ob_start();
    SharedSecretPanel::renderBody( $s );
    return (string) ob_get_clean();
}

/** Every word the section says, chips included. */
function words( array $s ): string {
    $w = $s['headline'] . ' ' . $s['summary'];
    foreach ( $s['lines'] as $l ) { $w .= ' ' . $l['label'] . ' ' . $l['value']; }
    return $w;
}

/** The leak test, in one place: value, fragment, prefix, sha256. */
function leaks( string $hay, array $secrets ): array {
    $found = [];
    foreach ( $secrets as $name => $sec ) {
        if ( $sec === '' ) { continue; }
        if ( str_contains( $hay, $sec ) )                     { $found[] = "$name value"; }
        if ( strlen( $sec ) > 32 && str_contains( $hay, substr( $sec, 8, 24 ) ) ) { $found[] = "$name fragment"; }
        if ( str_contains( $hay, substr( $sec, 0, 10 ) ) )    { $found[] = "$name prefix"; }
        if ( str_contains( $hay, hash( 'sha256', $sec ) ) )   { $found[] = "$name sha256"; }
    }
    return $found;
}

const SECRETS = [ 'wp' => WP_SHARED, 'app' => APP_SHARED, 'other' => OTHER_SEC, 'whsec' => APP_WHSEC ];

// ---- Source reading, through the tokenizer ---------------------------------

/**
 * ⚠️ TOKENS, NEVER A REGEX. This lane's own Admin.php change left a comment
 * that NAMES the option it removed, so a grep for the option name matches the
 * explanation of its absence and passes whether or not the field is gone.
 *
 * @return array{strings:list<string>,calls:list<string>,code:string}
 */
function tokens_of( string $abs ): array {
    $src     = (string) file_get_contents( $abs );
    $tok     = token_get_all( $src );
    $strings = [];
    $code    = '';
    foreach ( $tok as $t ) {
        if ( is_array( $t ) ) {
            if ( in_array( $t[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) { continue; }
            if ( in_array( $t[0], [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ], true ) ) {
                $strings[] = trim( $t[1], "'\"" );
            }
            $code .= $t[1];
        } else {
            $code .= $t;
        }
    }
    return [ 'strings' => $strings, 'code' => $code ];
}

/** The brace-matched body of one method — NOT a fixed-width window. */
function method_body( string $abs, string $name ): string {
    $src = (string) file_get_contents( $abs );
    $tok = token_get_all( $src );
    $n   = count( $tok );
    for ( $i = 0; $i < $n; $i++ ) {
        if ( ! is_array( $tok[ $i ] ) || $tok[ $i ][0] !== T_FUNCTION ) { continue; }
        $j = $i + 1;
        while ( $j < $n && ( ! is_array( $tok[ $j ] ) || $tok[ $j ][0] !== T_STRING ) ) { $j++; }
        if ( $j >= $n || $tok[ $j ][1] !== $name ) { continue; }
        while ( $j < $n && $tok[ $j ] !== '{' ) { $j++; }
        $depth = 0; $out = '';
        for ( ; $j < $n; $j++ ) {
            $t = is_array( $tok[ $j ] ) ? $tok[ $j ][1] : $tok[ $j ];
            if ( is_array( $tok[ $j ] ) && in_array( $tok[ $j ][0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) { continue; }
            if ( $t === '{' ) { $depth++; }
            if ( $t === '}' ) { $depth--; if ( $depth === 0 ) { return $out; } }
            $out .= $t;
        }
    }
    return '';
}

$GLOBALS['OB_BASE'] = ob_get_level();

echo "GATE 98 — the shared secret's status is true, current, and says nothing else (#201)\n";

// ===========================================================================
section( 'A. THE FOUR STATES OF THE OTHER HALF — never conflated' );
// ===========================================================================

/* "There is no settings file" and "this user may not read the settings file"
   send whoever reads this to different boxes and different fixes. #192's panel
   learned it; this section inherits it rather than re-deciding it, and a
   collapse into one word would be a silent regression. */
$s = scenario( $RUN . '/no-such-file' );
is_( $s['env']['state'] === 'missing' && str_contains( strtolower( words( $s ) ), 'no settings file' ),
     'A1 with NO settings file the billing half says there is no file' );

$dir = $RUN . '/adir';
@mkdir( $dir );
$s = scenario( $dir );
is_( $s['env']['state'] === 'unreadable',
     'A2 a DIRECTORY at that path is `unreadable`, not a truncated file' );

$euid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : (int) trim( (string) shell_exec( 'id -u' ) );
$locked = $RUN . '/locked.env';
file_put_contents( $locked, 'LGMS_SHARED_SECRET=' . APP_SHARED . "\n" );
@chmod( $locked, 0000 );
if ( $euid === 0 ) {
    /* Reported, not failed: root can read a 0000 file, so the case cannot be
       posed here. A false RED blocks every lane behind it as hard as a false
       green hides a defect. */
    report( 'A3 skipped: running as root, so a permissions case cannot be posed' );
} else {
    $s = scenario( $locked );
    is_( $s['env']['state'] === 'unreadable' && stripos( words( $s ), 'may not read' ) !== false,
         'A3 a file this user may not read says SO, and does not claim the key is absent' );
}
@chmod( $locked, 0644 );

$s = scenario( write_env( [] ) );
is_( $s['env']['state'] === 'empty' && stripos( words( $s ), 'parsed to nothing' ) !== false,
     'A4 a settings file that parses to nothing says that, not "NOT SET"' );

$s = scenario( $RUN . '/no-such-file' );
is_( $s['status'] === 'unknown' && $s['verdict'] === 'cannot_compare',
     'A5 an unreadable half makes the verdict `unknown` — NOT `ok`, and not a blank' );
is_( str_contains( strtoupper( $s['headline'] ), 'CANNOT COMPARE' ),
     'A5b and the headline says CANNOT COMPARE in words' );

/* THE HALF WE CAN SEE IS STILL REPORTED. A panel that goes silent on both
   halves because one is unreadable throws away the fact the reader came for. */
$s = scenario( $RUN . '/no-such-file' );
is_( str_contains( words( $s ), '64 characters' ),
     'A5c the WordPress half is still itemised when the billing half cannot be read' );

// ===========================================================================
section( 'B. THE VERDICTS — each against a deliberately broken state' );
// ===========================================================================

$s = scenario( healthy_env() );
is_( $s['verdict'] === 'match' && $s['status'] === 'ok' && str_contains( $s['headline'], 'MATCH' ),
     'B1 two halves holding the same value read as MATCH' );

$s = scenario( healthy_env( [ 'LGMS_SHARED_SECRET' => OTHER_SEC ] ) );
is_( $s['verdict'] === 'differ' && $s['status'] === 'fail' && str_contains( $s['headline'], 'DIFFER' ),
     'B2 two DIFFERENT values are a FAIL that says DIFFER' );

/* LIVE'S EXACT SHAPE, MEASURED: the app has one, WordPress does not. The panel
   must name WHICH half — "not configured" sends the reader to the wrong file. */
$s = scenario( healthy_env(), [ 'lgms_shared_secret' => '' ] );
is_( $s['verdict'] === 'wp_missing' && $s['status'] === 'fail'
     && stripos( $s['headline'], 'NOT SET in WordPress' ) !== false,
     'B3 set in the billing app and absent in WordPress is a FAIL naming WordPress — live today' );
is_( stripos( $s['summary'], 'unknown' ) !== false,
     'B3b and it says why that matters: the checkout guard answers UNKNOWN and refuses nobody' );

$s = scenario( healthy_env( [ 'LGMS_SHARED_SECRET' => null ] ) );
is_( $s['verdict'] === 'app_missing' && $s['status'] === 'fail'
     && stripos( $s['headline'], 'NOT SET in the billing app' ) !== false,
     'B4 set in WordPress and absent in the billing app is a FAIL naming the billing app' );

$s = scenario( healthy_env( [ 'LGMS_SHARED_SECRET' => null ] ), [ 'lgms_shared_secret' => '' ] );
is_( $s['verdict'] === 'both_missing' && $s['status'] === 'fail'
     && stripos( $s['headline'], 'NOT SET anywhere' ) !== false,
     'B5 absent on BOTH sides is still a FAIL, not a shrug' );

/* LENGTH IS THE MOST A SECRET MAY EVER SAY ABOUT ITSELF, and it is what tells a
   rotation that landed from one that landed truncated. */
/* ⚠️ PER LINE, NOT ACROSS THE WHOLE RENDER. The first draft searched every word
   the section says for "64 characters" and was satisfied by the BILLING APP's
   row alone — so dropping the length from the WordPress row left it green.
   "Both halves report X" has to be asked of each half. */
$s     = scenario( healthy_env() );
$byLbl = [];
foreach ( $s['lines'] as $l ) { $byLbl[ $l['label'] ] = $l['value']; }
is_( str_contains( (string) ( $byLbl['WordPress'] ?? '' ), strlen( WP_SHARED ) . ' characters' ),
     'B6 the WordPress row reports its LENGTH' );
is_( str_contains( (string) ( $byLbl['Billing app'] ?? '' ), strlen( APP_SHARED ) . ' characters' ),
     'B6b and so does the billing app row' );

$s = scenario( healthy_env( [ 'LGMS_SHARED_SECRET' => substr( APP_SHARED, 0, 32 ) ] ) );
$w = words( $s );
is_( $s['verdict'] === 'differ' && str_contains( $w, '64 characters' ) && str_contains( $w, '32 characters' ),
     'B7 a TRUNCATED half is DIFFER and both lengths are shown side by side' );

/* PER-HALF, IN EVERY BRANCH — including the healthy one. "AGREE — both set, 64
   characters" is a true sentence answering a different question, and a panel
   that only itemises once something is broken cannot confirm a rotation. */
$s = scenario( healthy_env() );
$labels = array_column( $s['lines'], 'label' );
is_( in_array( 'WordPress', $labels, true ) && in_array( 'Billing app', $labels, true )
     && in_array( 'Do they match?', $labels, true ),
     'B8 the healthy render still itemises WordPress, Billing app and the verdict separately' );

// ===========================================================================
section( 'C. THE VALUE REACHES NO SCREEN, NO RESPONSE AND NO ERROR PATH' );
// ===========================================================================

$s   = scenario( healthy_env() );
$out = render_section( $s );
is_( leaks( $out, SECRETS ) === [], 'C1 the rendered section carries no value, fragment, prefix or sha256' );

/* THE BOUNDARY IS STRUCTURAL: the array the renderer is handed is the whole
   surface it can see, so if nothing derived from a secret is in there, no
   renderer can leak one however carelessly it is written. */
is_( leaks( (string) json_encode( $s ), SECRETS ) === [],
     'C1b the reading itself carries no value and no sha256 — the renderer cannot leak what it never gets' );

/* THE REFRESH RESPONSE IS A SECOND SURFACE and has to be tested as one. */
$GLOBALS['JSON'] = null;
$GLOBALS['CAP']  = true;
$GLOBALS['NONCE_OK'] = true;
scenario( healthy_env() );
SharedSecretPanel::handleStatus();
$j = $GLOBALS['JSON'];
is_( is_array( $j ) && $j['ok'] === true && isset( $j['data']['html'] ),
     'C2 the refresh answers with rendered markup' );
is_( leaks( (string) json_encode( $j ), SECRETS ) === [],
     'C2b and that response carries no value, fragment, prefix or sha256' );

/* ⚠️ THE ERROR PATH IS WHERE A SECRET ESCAPES. A Throwable out of a file read
   or a database handle can carry a value in its message, and nobody is looking
   at an error path. This poses exactly that. */
$GLOBALS['JSON'] = null;
scenario( healthy_env() );
$GLOBALS['THROW_ON_OPTION'] = 'SQLSTATE[HY000] connection failed using ' . WP_SHARED . ' — check the config';
SharedSecretPanel::handleStatus();
$GLOBALS['THROW_ON_OPTION'] = '';
$j = $GLOBALS['JSON'];
is_( is_array( $j ) && $j['ok'] === false,
     'C3 an exception while reading answers as a failure rather than a blank panel' );
is_( is_array( $j ) && leaks( (string) json_encode( $j ), SECRETS ) === [],
     'C3b THE ERROR RESPONSE CARRIES NO PART OF THE SECRET — the exception message is discarded' );
is_( is_array( $j ) && ( $j['data']['message'] ?? '' ) === SharedSecretPanel::ERROR_SENTENCE,
     'C3c and it is the fixed sentence, never the exception\'s own message' );
is_( is_array( $j ) && stripos( (string) ( $j['data']['message'] ?? '' ), 'SQLSTATE' ) === false,
     'C3d nothing of the underlying error is echoed, not even the harmless-looking part' );

/* A HALF-WRITTEN PANEL MUST NOT BE SHIPPED EITHER. The renderer prints as it
   goes, so a throw part-way through leaves a real fragment in the buffer — and
   that fragment is markup nobody checked. Posed by making the escaper throw
   once the table is open; see esc_html(). */
$GLOBALS['JSON'] = null;
scenario( healthy_env() );
/* Die on the FIRST ROW LABEL, which is emitted after the table's opening tag —
   so the buffer provably holds markup at the moment of the throw. */
$GLOBALS['THROW_ON_ESC_TEXT'] = 'WordPress';
SharedSecretPanel::handleStatus();
$GLOBALS['THROW_ON_ESC_TEXT'] = '';
$j = $GLOBALS['JSON'];
is_( is_array( $j ) && $j['ok'] === false && ! str_contains( (string) json_encode( $j ), 'lgms-ss-tbl' ),
     'C3e a throw PART-WAY THROUGH THE RENDER ships no partial markup' );
/* THE CASE IS POSED, PROVEN. Rendering to the same point outside the handler
   must really produce a fragment containing the table — otherwise C3e is true
   of every build and watching nothing. */
$frag  = '';
/* The reading is taken BEFORE the trap is armed — scenario() clears the trap,
   so taking it inside the try disarmed the very throw under test and the buffer
   was left open, spilling the whole panel into this gate's own output. */
$sFrag = scenario( healthy_env() );
$lvl2  = ob_get_level();
$GLOBALS['THROW_ON_ESC_TEXT'] = 'WordPress';
try { ob_start(); SharedSecretPanel::renderBody( $sFrag ); }
catch ( \Throwable $e ) { /* expected */ }
finally {
    while ( ob_get_level() > $lvl2 ) { $frag .= (string) ob_get_clean(); }
    $GLOBALS['THROW_ON_ESC_TEXT'] = '';
}
is_( str_contains( $frag, 'lgms-ss-tbl' ),
     'C3e2 liveness: the render really WAS mid-table when it died (C3e is not vacuous)' );
is_( ob_get_level() === $GLOBALS['OB_BASE'],
     'C3f and the output buffer is unwound, so the admin page after it is not swallowed' );

/* EVERY BROKEN STATE, NOT JUST THE HEALTHY ONE — a leak that only happens when
   something is wrong is a leak that happens on the day it is worst. */
$dirty = [];
foreach ( [
    'match'         => [ healthy_env(), [] ],
    'differ'        => [ healthy_env( [ 'LGMS_SHARED_SECRET' => OTHER_SEC ] ), [] ],
    'wp_missing'    => [ healthy_env(), [ 'lgms_shared_secret' => '' ] ],
    'app_missing'   => [ healthy_env( [ 'LGMS_SHARED_SECRET' => null ] ), [] ],
    'both_missing'  => [ healthy_env( [ 'LGMS_SHARED_SECRET' => null ] ), [ 'lgms_shared_secret' => '' ] ],
    'unreadable'    => [ $RUN . '/no-such-file', [] ],
] as $name => [ $env, $opts ] ) {
    $r = render_section( scenario( $env, $opts ) );
    foreach ( leaks( $r, SECRETS ) as $l ) { $dirty[] = "$name: $l"; }
}
is_( $dirty === [], 'C4 no state leaks — all six verdicts rendered and checked (' . count( $dirty ) . ' leaks)' );

// ===========================================================================
section( 'D. NO INPUT FIELD — Ian\'s shape, asserted as a COUNT of zero' );
// ===========================================================================

/* A COUNT, NOT THE ABSENCE OF A PARTICULAR NAME. "There is no field called
   lgms_shared_secret" is satisfied by renaming it. Zero inputs cannot be
   argued with, and it is the literal reading of the issue: a status check and
   a refresh button, and no way to type a value. */
$out = render_section( scenario( healthy_env() ) );
is_( substr_count( strtolower( $out ), '<input' ) === 0,
     'D1 the section contains ZERO <input> elements' );
is_( stripos( $out, 'type="password"' ) === false && stripos( $out, "type='password'" ) === false,
     'D2 and no password field of any name' );
is_( stripos( $out, '<form' ) === false && stripos( $out, '<textarea' ) === false,
     'D3 and no form and no textarea — nothing that could post a value' );
is_( stripos( $out, 'lgms_shared_secret' ) !== false,
     'D3b liveness: the option IS named on screen, so D1-D3 are not passing on an empty render' );

/* The copy targets are <code>, which is why D1 can be a flat zero. */
is_( str_contains( $out, 'lgms-ss-cmd-wp' ) && str_contains( $out, 'lgms-ss-cmd-app' ),
     'D4 the two commands are copyable as <code>, not as readonly inputs' );

// ===========================================================================
section( 'E. ONE RENDERER SERVES THE PAGE LOAD AND THE REFRESH' );
// ===========================================================================

/* Two renderers would be two places a secret can leak and two things to keep
   gated. §C only has to be written once because of this. */
$panelAbs = $GATE_ROOT . PANEL_REL;
$bodyRender  = method_body( $panelAbs, 'render' );
$bodyHandler = method_body( $panelAbs, 'handleStatus' );
$bodyBody    = method_body( $panelAbs, 'renderBody' );

is_( $bodyRender !== '' && $bodyHandler !== '' && $bodyBody !== '',
     'E0 the three methods are found (a rename must fail loudly, not silently pass)' );
is_( str_contains( str_replace( ' ', '', $bodyRender ), 'self::renderBody' ),
     'E1 the page-load path renders through renderBody()' );
is_( str_contains( str_replace( ' ', '', $bodyHandler ), 'self::renderBody' ),
     'E2 the refresh path renders through THE SAME renderBody()' );

/* THE STRUCTURAL HALF: only one method may emit the status table. A second
   emitter is a second thing to gate, and it is how the two paths drift. */
/* ⚠️ THE MARKUP, NOT THE CLASS NAME. This assertion's first run reported TWO
   emitters and the second was the `<style>` block in render() — the same class
   name in a CSS rule. That is the "an assertion matching a string that also
   lives in a stylesheet" family, and it is why it matches the attribute. */
$emitters = 0;
foreach ( [ 'render', 'renderBody', 'handleStatus', 'refreshRead' ] as $m ) {
    if ( str_contains( method_body( $panelAbs, $m ), 'class="lgms-ss-tbl"' ) ) { $emitters++; }
}
is_( $emitters === 1, 'E3 exactly ONE method emits the status table (found ' . $emitters . ')' );

/* AND THE TWO PATHS AGREE IN FACT, not only in structure. */
$sameEnv = healthy_env( [ 'LGMS_SHARED_SECRET' => OTHER_SEC ] );
$s = scenario( $sameEnv );
$direct = render_body( $s );
$GLOBALS['JSON'] = null;
scenario( $sameEnv );
SharedSecretPanel::handleStatus();
$viaAjax = (string) ( $GLOBALS['JSON']['data']['html'] ?? '' );
$strip = static fn( string $h ): string => (string) preg_replace( '/Checked at [^<]*/', 'Checked at X', $h );
is_( $strip( $direct ) === $strip( $viaAjax ) && $viaAjax !== '',
     'E4 the refresh markup is byte-identical to the page-load markup, timestamp aside' );

// ===========================================================================
section( 'F. BOTH LOCKS ON THE REFRESH DOOR, NEVER ONE' );
// ===========================================================================

/* ⚠️ ASSERTED AS DECISIONS, NOT AS THE PRESENCE OF A STRING. #193 found three
   gate defects of exactly that shape: a section looked for `wp_check_password`
   and stayed green when the throttle around it became `if ( false )`. */
$GLOBALS['JSON'] = null;
$GLOBALS['CAP']  = false;
$GLOBALS['NONCE_OK'] = true;
scenario( healthy_env() );
SharedSecretPanel::handleStatus();
$j = $GLOBALS['JSON'];
is_( is_array( $j ) && $j['ok'] === false && $j['code'] === 403,
     'F1 a caller WITHOUT manage_options is refused 403' );
is_( is_array( $j ) && ! str_contains( (string) json_encode( $j ), 'lgms-ss-tbl' ),
     'F1b and is handed no markup at all — a refusal that renders is not a refusal' );

$GLOBALS['CAP'] = true;
$GLOBALS['NONCE_OK'] = false;
$GLOBALS['JSON'] = null;
scenario( healthy_env() );
$died = false;
try { SharedSecretPanel::handleStatus(); } catch ( NonceDied $e ) { $died = true; }
is_( $died && $GLOBALS['JSON'] === null,
     'F2 a caller with the capability but a BAD NONCE is stopped before any reading is taken' );
$GLOBALS['NONCE_OK'] = true;

/* THE LIVENESS PARTNER. Without it F1 and F2 both pass on a handler that
   refuses everybody, which is a broken feature wearing a green tick. */
$GLOBALS['JSON'] = null;
scenario( healthy_env() );
SharedSecretPanel::handleStatus();
is_( ( $GLOBALS['JSON']['ok'] ?? false ) === true,
     'F3 liveness: an administrator with a good nonce DOES get the reading' );

/* NO ANONYMOUS DOOR. `wp_ajax_nopriv_` would open this to the world. */
$GLOBALS['ACTIONS'] = [];
SharedSecretPanel::boot();
$hooks = array_map( static fn( $a ) => (string) $a[0], $GLOBALS['ACTIONS'] );
is_( in_array( 'wp_ajax_' . SharedSecretPanel::ACTION, $hooks, true ),
     'F4 boot() registers the logged-in AJAX action' );
is_( ! in_array( 'wp_ajax_nopriv_' . SharedSecretPanel::ACTION, $hooks, true ),
     'F5 and registers NO nopriv twin — there is no anonymous door' );

// ===========================================================================
section( 'G. THE REFRESH IS A REAL RE-READ, NOT A RE-RENDER' );
// ===========================================================================

/* ⚠️ MEASURED, NOT GREPPED. A stale value sits in the option cache and a
   different one in the store; a refresh that does not drop the cache reports
   the stale one and the button becomes a lie told convincingly. */
/* LAYER ONE: the option cached under its own key. */
$OPTS   = [ 'lgms_shared_secret' => WP_SHARED, 'lgms_stripe_webhook_secret' => APP_WHSEC ];
$OCACHE = [ 'lgms_shared_secret' => OTHER_SEC ];
$_SERVER['LG_HEALTH_APP_ENV'] = healthy_env();
Health::reset();

$before = Health::sharedSecret();
is_( $before['verdict'] === 'differ',
     'G0 liveness: with the STALE value cached the reading really IS wrong (the case is posed)' );

$after = SharedSecretPanel::refreshRead();
is_( $after['verdict'] === 'match',
     'G1 refreshRead() drops the option\'s own cache entry and reports what is actually stored' );

/* LAYER TWO, AND THE ONE THAT ACTUALLY APPLIES HERE: an AUTOLOADED option is
   served out of the `alloptions` blob, not from its own key — and
   `lgms_shared_secret` is autoloaded on dev2, measured. Dropping the single key
   does not touch the blob, so a panel that dropped only that would answer stale
   on the very box it was written for. */
$OPTS   = [ 'lgms_shared_secret' => WP_SHARED, 'lgms_stripe_webhook_secret' => APP_WHSEC ];
$OCACHE = [ 'alloptions' => [ 'lgms_shared_secret' => OTHER_SEC ] ];
$_SERVER['LG_HEALTH_APP_ENV'] = healthy_env();
Health::reset();

is_( Health::sharedSecret()['verdict'] === 'differ',
     'G2 liveness: a stale value in the AUTOLOAD BLOB is served too (the second case is posed)' );
is_( SharedSecretPanel::refreshRead()['verdict'] === 'match',
     'G2b refreshRead() drops the `alloptions` blob as well — the layer this option actually uses' );

/* THE OTHER HALF OF "RE-READ": the settings file is memoised per request, so a
   refresh that does not reset it answers about the file as it was. */
$stable = write_env( [ 'LGMS_SHARED_SECRET' => APP_SHARED ], 'stable.env' );
$_SERVER['LG_HEALTH_APP_ENV'] = $stable;
$OPTS   = [ 'lgms_shared_secret' => WP_SHARED ];
$OCACHE = [];
Health::reset();
Health::sharedSecret();                                            // memoise the good file
write_env( [ 'LGMS_SHARED_SECRET' => OTHER_SEC ], 'stable.env' );  // SAME path, new content
$after = SharedSecretPanel::refreshRead();
is_( $after['verdict'] === 'differ',
     'G3 refreshRead() re-parses the settings file rather than answering from the memoised copy' );

/* THE STAMP IS WHAT MAKES A REFRESH LEGIBLE. Without it a re-rendered section
   is indistinguishable from a stale one and the button is decoration. */
$s = scenario( healthy_env() );
is_( preg_match( '/^\d{2}:\d{2}:\d{2} UTC$/', (string) $s['checked_at'] ) === 1,
     'G4 the reading carries a `checked at` stamp, in UTC' );
is_( str_contains( render_section( $s ), 'Checked at' ),
     'G4b and the section prints it' );

/* UTC BECAUSE BOTH BOXES DISAGREE WITH IT. wp_date/site-zone reads are how
   #183 put every comp expiry four hours out; a health screen read across two
   boxes at 3am needs one clock. */
$tzWas = date_default_timezone_get();
date_default_timezone_set( 'America/New_York' );
$s = scenario( healthy_env() );
$hour = (int) substr( (string) $s['checked_at'], 0, 2 );
date_default_timezone_set( $tzWas );
is_( $hour === (int) gmdate( 'H' ),
     'G5 the stamp is UTC even under a hostile process timezone' );

// ===========================================================================
section( 'H. IT SAYS THAT SETTING IT IS A COMMAND-LINE ACT, AND HOW' );
// ===========================================================================

/* Without this the screen reports a problem and leaves the reader hunting a
   control that #201 deliberately removed. */
$hEnv = healthy_env();
$hS   = scenario( $hEnv, [ 'lgms_shared_secret' => '' ] );
$out  = render_section( $hS );
/* ⚠️ THE PINNED SENTENCE, NOT THE PHRASE. The first draft accepted either
   "command-line act" or "command line" anywhere in the render — and the closing
   note says "a value set on the command line a moment ago", so deleting the
   whole ruling paragraph left H1 green off an unrelated sentence. */
is_( str_contains( $out, 'Setting this is a command-line act' ),
     'H1 the section says, in those words, that setting it is a command-line act' );
is_( stripos( $out, 'no field for it here or' ) !== false,
     'H2 and says there is no field for it on this tab or any other' );
is_( str_contains( $out, 'option update lgms_shared_secret' ),
     'H3 the WordPress half\'s command is on screen' );
is_( str_contains( $out, 'LGMS_SHARED_SECRET' ) && str_contains( $out, (string) $hS['env']['path'] ),
     'H4 the billing app half names the KEY and the FILE that holds it' );
is_( str_contains( $out, '--path=' ),
     'H5 the wp command carries --path, so it is correct on a box with several sites' );

/* ⚠️ AND IT MUST NOT OFFER A VALUE. A generated suggestion on this screen would
   put a secret in the markup by the front door. */
is_( ! preg_match( '/[0-9a-f]{32,}/i', $out ),
     'H6 no long hex run of any kind reaches the screen — nothing that could BE a secret' );

/* THE SAME TWO LINES ARE ON SCREEN IN EVERY STATE, healthy included: this is
   how a rotation is performed, not only how a fault is repaired. */
$missing = [];
foreach ( [ 'match' => [ healthy_env(), [] ], 'unreadable' => [ $RUN . '/no-such-file', [] ] ] as $name => [ $e, $o ] ) {
    $r = render_section( scenario( $e, $o ) );
    if ( ! str_contains( $r, 'option update lgms_shared_secret' ) ) { $missing[] = $name; }
}
is_( $missing === [], 'H7 the runbook lines are shown in every state, healthy included' );

// ===========================================================================
section( 'I. THE WIRING — asserted by TOKENS, and unconditionally' );
// ===========================================================================

/* ⚠️ UNCONDITIONALLY. #190's §G only checked placement when the page already
   looked correct, so a revert flipped it into report mode and it said nothing.
   A gate that stops watching the moment the thing it watches breaks is not a
   gate. */
$adminAbs = $GATE_ROOT . ADMIN_REL;
$adminBoot = method_body( $adminAbs, 'boot' );
is_( str_contains( str_replace( ' ', '', $adminBoot ), 'SharedSecretPanel::boot()' ),
     'I1 Admin::boot() boots the panel, so the refresh door exists at all' );

/* ⚠️ THE DANGEROUS HALF OF #201's RULING. Removing the FIELD while leaving the
   REGISTRATION does not leave the option alone: wp-admin/options.php walks the
   registered options of the submitted group and calls
   `update_option( $option, null )` for every one absent from POST — verified in
   the running WordPress at options.php:336-345. The secret would be BLANKED by
   anyone pressing Save on the Settings tab, silently, and server-to-server auth
   would fail closed from that moment. The two must move together.

   TOKENS, NOT A GREP: this lane's own comment in that file NAMES the option, so
   a regex matches the explanation of its absence and passes either way. */
$adminTok = tokens_of( $adminAbs );
$named    = array_filter( $adminTok['strings'], static fn( $x ) => str_contains( $x, 'lgms_shared_secret' ) );
is_( $named === [],
     'I2 Admin.php names the option in NO string, inline HTML or registration — only in a comment' );

$reg = method_body( $adminAbs, 'registerSettings' );
is_( ! str_contains( $reg, 'lgms_shared_secret' ),
     'I2b registerSettings() does not register it — or Save would blank it on every press' );

/* LIVENESS FOR I2/I2b: the rest of that form is still registered, so the two
   assertions above are not passing on an emptied file. */
is_( str_contains( $reg, 'lgms_stripe_secret_key' ) && str_contains( $reg, 'lgms_db_pass' ),
     'I2c liveness: the tab\'s other settings ARE still registered' );

/* THE POINTER MOVES WITH THE CONTROL. A field that vanishes with nothing where
   it stood reads as a broken dash — and this one is named in ENV-AND-SECRETS.md
   and in the handoffs. */
$adminSrc = (string) file_get_contents( $adminAbs );
is_( str_contains( $adminSrc, 'shared secret' ) && str_contains( $adminSrc, "'tab' => 'health'" ),
     'I3 the Settings tab still explains where the control went, and links to the Health tab' );

/* AND THE SECTION IS ACTUALLY ON THE TAB. */
$hpanelAbs = $GATE_ROOT . HPANEL_REL;
$hpRender  = method_body( $hpanelAbs, 'render' );
is_( str_contains( str_replace( ' ', '', $hpRender ), 'SharedSecretPanel::render' ),
     'I4 HealthPanel renders the section' );

/* ⚠️ AND ITS STATUS REACHES THE TAB'S HEADLINE. A screen whose chip says
   everything is healthy while its first card says DIFFER is "a blank cell reads
   like health" wearing a different hat. */
is_( str_contains( str_replace( ' ', '', $hpRender ), 'array_merge($checks,[$shared])' )
     || str_contains( str_replace( ' ', '', $hpRender ), 'array_merge($checks,[$h[\'shared_secret\']])' ),
     'I5 the shared secret is folded into the tab headline, not left beside it' );

/* THE BEHAVIOURAL PROOF OF I5 — the structural one above can be satisfied and
   still be wrong. */
require_once $GATE_ROOT . HPANEL_REL;
$OPTS   = [ 'lgms_shared_secret' => '', 'lgms_stripe_webhook_secret' => APP_WHSEC ];
$OCACHE = [];
$_SERVER['LG_HEALTH_APP_ENV'] = healthy_env();
Health::reset();
$d = Health::describe();
is_( isset( $d['shared_secret'] ) && $d['shared_secret']['status'] === 'fail',
     'I6 describe() carries the shared-secret reading, and it is FAIL in live\'s shape' );
is_( Health::worst( array_merge( $d['checks'], [ $d['shared_secret'] ] ) ) === 'fail',
     'I7 a broken shared secret makes the whole tab read BROKEN' );

/* ⚠️ THE DUPLICATE THAT #199 PAID FOR. It is reported ONCE. The webhook card
   used to carry this pair too, and one fact in two presentations on one screen
   is the two-stacked-panels shape. */
$secretsCard = null;
foreach ( $d['checks'] as $c ) { if ( $c['key'] === 'secrets' ) { $secretsCard = $c; } }
is_( $secretsCard !== null, 'I8 the webhook-secret card still exists' );
$cardWords = $secretsCard === null ? '' : $secretsCard['title'] . ' ' . $secretsCard['summary']
    . ' ' . implode( ' ', array_column( $secretsCard['lines'], 'label' ) );
is_( stripos( $cardWords, 'Shared secret' ) === false,
     'I8b and it no longer reports the shared secret — one fact, one place' );
is_( stripos( $cardWords, 'webhook' ) !== false,
     'I8c liveness: that card still reports the webhook secret it does own' );

// ===========================================================================
section( 'J. THE PANEL CANNOT BE DRIVEN WITHOUT ITS DEPENDENCIES' );
// ===========================================================================

/* This gate loads Health and SharedSecretPanel and NOT Admin.php — the pattern
   HealthPanel, TesterUnlockPanel and ProductsPanel all follow, because that
   file's neighbours have died at exit 255 with no FAIL line four times over a
   missing require. If the panel ever reaches into Admin, this gate stops being
   cheap to run and starts dying instead of failing. */
$panelTok = tokens_of( $panelAbs );
is_( ! str_contains( $panelTok['code'], 'LGMS\\Admin' ) && ! str_contains( $panelTok['code'], 'Admin::' ),
     'J1 the panel does not reach into Admin.php' );
is_( str_contains( $panelTok['code'], 'Health::' ),
     'J2 every decision comes from Membership\\Health — the panel decides nothing' );

/* ⚠️ AND IT HOLDS NO SECRET-SHAPED LOGIC OF ITS OWN. A second comparison here
   would be a second definition of "do the halves agree", and this dash exists
   because two halves disagreed. */
is_( ! str_contains( $panelTok['code'], 'hash_equals' ) && ! str_contains( $panelTok['code'], "hash('sha256'" ),
     'J3 the panel does no comparing of its own — one definition, in Health::secretPair()' );

// ---------------------------------------------------------------------------

echo "\n";
foreach ( $reports as $r ) { echo "  note $r\n"; }
echo "\n$pass passed, $fail failed\n";
if ( $fail > 0 ) { echo "############ GATE 98 RED ############\n"; exit( 1 ); }
echo "############ GATE 98 GREEN ############\n";
exit( 0 );

}
