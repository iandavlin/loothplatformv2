<?php
/**
 * GATE 91 — THE HEALTH PANEL TELLS THE TRUTH WHEN THINGS ARE BROKEN.
 *
 *   php tools/gates/membership-health-gate.php
 *
 * Exit 0 green, 1 a real defect, 2 cannot run (run-all.sh's convention).
 *
 * WHAT THIS EXISTS FOR (#192, split out of #190). On 2026-08-21 three failures
 * cost roughly an hour each and NOT ONE ANNOUNCED ITSELF: the billing app
 * pointed at a host that does not exist, `lgms_shared_secret` was present in one
 * half and absent in the other, and BuddyBoss 401'd every `lg-member-sync/v1`
 * route before any permission callback ran. Each was a SILENCE.
 *
 * ⚠️ THE ASSERTION THAT LOOKS RIGHT AND MEASURES NOTHING.
 * "The panel renders and says everything is fine" passes on a healthy box, and
 * a health panel is worthless on a healthy box — the only run that matters is
 * the broken one. Keeper's instruction, 2026-08-21: "prove every answer against
 * a DELIBERATELY BROKEN state, not just a healthy one ... a panel that only
 * ever goes green is worth nothing." So every section below drives the REAL
 * reader with a REAL broken fixture — mismatched secrets, an absent half, a
 * sync URL on a host that does not exist, an emptied cohort, a product with no
 * tier ref, a webhook that never came, a database that will not answer — and
 * asserts the panel NAMES it. Same family as #148's "a PRO purchase grants
 * looth3" passing on a constant.
 *
 * ⚠️ THE SECOND ONE, WHICH IS KEEPER'S OTHER STANDING REQUIREMENT.
 * "The last webhook is shown" is satisfied by a panel that renders an empty
 * cell when none has ever arrived — and a blank cell reads like health. §G
 * therefore asserts the WORDS: "Never" must appear, "never" with money already
 * on the box must be a FAIL and not a shrug, and a stale receipt must print its
 * AGE rather than a date nobody subtracts in their head.
 *
 * ⚠️ THE THIRD: UNKNOWN MUST NOT COLLAPSE INTO OK.
 * The whole lane is about silence, so the failure mode of a health panel is a
 * green tick for a question it could not answer. §A5, §F7 and §G6 take away the
 * env file, the loopback probe and the database respectively, and require
 * `unknown` — not `ok`, and not a blank.
 *
 * WHAT IS REAL AND WHAT IS STUBBED. Membership\Health, HealthPanel,
 * Membership\CheckoutAudience, CohortAllowlist, StripeLifecycle and StripePrice
 * are the REAL code — every decision under test is real, and the catalogue and
 * webhook questions run REAL SQL against an in-memory SQLite database rather
 * than against a stubbed return value, so the queries themselves are exercised.
 * WordPress's option store, escaping, HTTP client and filter registry are
 * observable stand-ins. There is no browser, no FPM, no WordPress and no
 * network, so this gate cannot go vacuously green behind a locked-out browser;
 * every file it touches is under a per-run temp directory keyed to the PID, so
 * two suites running at once cannot collide.
 *
 * ⚠️ IT DELIBERATELY DOES NOT LOAD Admin.php. That file reaches Invites,
 * CompExpiry, MemberTools and more, and the neighbouring test file has died at
 * exit 255 with NO FAIL LINE three separate times because the door gained a
 * dependency nobody added to a require list. §H asserts Admin.php's wiring by
 * SOURCE, through PHP's tokenizer — not a regex, because gate 90's equivalent
 * assertions matched their own explanatory prose twice — and it asserts
 * UNCONDITIONALLY. #190's §G only checked placement when the page already
 * looked correct, so a revert flipped it into report mode and it said nothing.
 * A gate that stops watching the moment the thing it watches breaks is not a
 * gate.
 *
 * RED-FIRST: see tools/gates/membership-health-redfirst.py.
 */

declare(strict_types=1);

namespace {

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$GATE_ROOT = dirname( __DIR__, 2 );
$RUN       = sys_get_temp_dir() . '/lg-gate91-' . getmypid();

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

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
    cannot( 'pdo_sqlite is not available; the catalogue and webhook questions run real SQL' );
}

if ( ! @mkdir( $RUN, 0755, true ) && ! is_dir( $RUN ) ) {
    cannot( "could not create the per-run directory $RUN" );
}
register_shutdown_function( static function () use ( $RUN ) {
    if ( is_dir( $RUN ) ) { @exec( 'rm -rf ' . escapeshellarg( $RUN ) ); }
} );

foreach ( [
    '/lg-patreon-stripe-poller/src/Membership/Health.php',
    '/lg-patreon-stripe-poller/src/HealthPanel.php',
    '/lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php',
    '/lg-patreon-stripe-poller/src/CohortAllowlist.php',
    '/lg-patreon-stripe-poller/src/StripeLifecycle.php',
    '/lg-patreon-stripe-poller/src/StripePrice.php',
    '/lg-patreon-stripe-poller/src/Admin.php',
    '/lg-stripe-billing/src/Core/WebhookReceipts.php',
    '/lg-stripe-billing/src/Http/Controllers/WebhookController.php',
] as $need ) {
    if ( ! is_readable( $GATE_ROOT . $need ) ) { cannot( "missing $need" ); }
}

// ---- WordPress stand-ins ---------------------------------------------------

$OPTS    = [];
$FILTERS = [];
/* What the exclusion hook returns, per scenario. The default below is the SIX
   routes the three controllers' filters name on main after #203 — never a
   transcription kept in step by hand: §J asserts it against the filters
   themselves, so this constant cannot quietly disagree with the code. */
$FILTER_VALUES = [];
$PROBE   = [ 'code' => 200, 'body' => '{"state":"allowlist","allowed":false}' ];

function get_option( $k, $d = false ) { global $OPTS; return array_key_exists( $k, $OPTS ) ? $OPTS[ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $OPTS; $OPTS[ $k ] = $v; return true; }
function delete_option( $k ) { global $OPTS; unset( $OPTS[ $k ] ); return true; }
function home_url( $p = '' ) { return 'https://dev2.loothgroup.com' . $p; }
function admin_url( $p = '' ) { return 'https://dev2.loothgroup.com/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://dev2.loothgroup.com/wp-json/' . ltrim( (string) $p, '/' ); }
function wp_parse_url( $u, $c = -1 ) { return $c === -1 ? parse_url( (string) $u ) : parse_url( (string) $u, $c ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )  { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_kses_post( $s ) { return (string) $s; }
/* ⚠️ apply_filters USED TO RETURN $value UNCHANGED, WHICH MADE THE HOOK
   UNMEASURABLE (#203). Health now ASKS the exclusion hook which routes are
   exempted rather than carrying a sentence about it, so a stub that always
   answers "nothing" would have the panel report zero exempted routes on a
   healthy box and no assertion could tell that apart from the real thing.
   $FILTER_VALUES lets each scenario say what the hook actually returns. */
function apply_filters( $tag, $value ) {
    global $FILTER_VALUES;
    return array_key_exists( $tag, $FILTER_VALUES ) ? $FILTER_VALUES[ $tag ] : $value;
}
function has_filter( $tag, $fn = false ) { global $FILTERS; return in_array( $tag, $FILTERS, true ) ? 10 : false; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }

/* The probe's transport is swapped through Health::$transport rather than
   stubbed at the WordPress layer, because it is NOT a WordPress call: this
   plugin's own CLAUDE.md requires raw curl with CURLOPT_RESOLVE for every
   server-to-server hop ("wp_remote_post does NOT work for these"). The seam
   records exactly what the probe asked for, so §F9 can assert the loopback pin
   and the timeout instead of taking them on trust. */
function install_probe_transport(): void {
    \LGMS\Membership\Health::$transport = static function ( string $url, array $opts ): array {
        global $PROBE;
        $GLOBALS['PROBE_URL']  = $url;
        $GLOBALS['PROBE_OPTS'] = $opts;
        if ( isset( $PROBE['error'] ) ) {
            return [ 'error' => (string) $PROBE['error'], 'code' => 0, 'body' => '' ];
        }
        return [ 'error' => '', 'code' => (int) $PROBE['code'], 'body' => (string) $PROBE['body'] ];
    };
}

} // end global namespace

// ---------------------------------------------------------------------------
// The database stand-in: a REAL PDO over in-memory SQLite, so the readers'
// actual SQL is exercised rather than a canned array. $GLOBALS['DB_BROKEN']
// makes it throw, which is how §G6 proves `unknown` is reachable.
// ---------------------------------------------------------------------------
namespace LGMS {

    use PDO;
    use RuntimeException;

    final class Db
    {
        private static ?PDO $pdo = null;

        public static function pdo(): PDO
        {
            if ( ! empty( $GLOBALS['DB_BROKEN'] ) ) {
                throw new RuntimeException( 'SQLSTATE[HY000] [2002] Connection refused' );
            }
            if ( self::$pdo instanceof PDO ) { return self::$pdo; }
            $pdo = new PDO( 'sqlite::memory:', null, null, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
            $pdo->exec( 'CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_ref TEXT,
                          subject_type TEXT, subject_id INTEGER, action TEXT, details TEXT, created_at TEXT)' );
            $pdo->exec( 'CREATE TABLE products (id INTEGER PRIMARY KEY, kind TEXT, active INTEGER, ref TEXT, region_tag TEXT)' );
            $pdo->exec( 'CREATE TABLE prices (id INTEGER PRIMARY KEY, product_id INTEGER, active INTEGER)' );
            $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY, created_at TEXT)' );
            $pdo->exec( 'CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, created_at TEXT)' );
            return self::$pdo = $pdo;
        }

        /** Empty every table; the fixtures rebuild what they need. */
        public static function wipe(): void
        {
            $p = self::pdo();
            foreach ( [ 'audit_log', 'products', 'prices', 'customers', 'subscriptions' ] as $t ) {
                $p->exec( "DELETE FROM $t" );
            }
        }
    }
}

namespace {

require $GATE_ROOT . '/lg-patreon-stripe-poller/src/StripeLifecycle.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/CohortAllowlist.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Log.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/StripePrice.php';
/* #203 — Health's channel check now asks RestController which shared-secret
   routes exist, instead of carrying a hand-kept sentence that was two lanes out
   of date. Without this require that call is a FATAL, and this plugin's test
   files have died at exit 255 with no FAIL line three times already. */
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Wp/RestController.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Wp/CheckoutAudienceRestController.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Wp/PatreonStandingRestController.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/Health.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/HealthPanel.php';

use LGMS\Membership\Health;
use LGMS\HealthPanel;
use LGMS\Db;

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

/** Distinctive fixture secrets: long enough to be unmistakable in markup. */
const APP_SHARED  = 'APPSHAREDSECRET_0123456789abcdef0123456789abcdef0123456789ab';
const APP_WHSEC   = 'whsec_APPWEBHOOKSECRET_0123456789abcdefghij';
const APP_SK_TEST = 'sk_test_APPSTRIPEKEY_0123456789abcdefghijklmnopqrstuvwxyz';
const APP_SK_LIVE = 'sk_live_APPSTRIPEKEY_0123456789abcdefghijklmnopqrstuvwxyz';

/**
 * Write a billing-app .env fixture. Keys not given are omitted entirely, which
 * is how the "absent in the app" half of §B is produced — an empty value and a
 * missing line are different files and must not be conflated.
 */
function write_env( array $kv ): string {
    global $RUN;
    static $n = 0;
    $path = $RUN . '/env-' . ( ++$n );
    $out  = "# fixture\n";
    foreach ( $kv as $k => $v ) { $out .= $k . '=' . $v . "\n"; }
    file_put_contents( $path, $out );
    return $path;
}

/** A healthy .env, overridable key by key. */
function healthy_env( array $over = [] ): string {
    return write_env( array_merge( [
        'APP_ENV'               => 'dev',
        'APP_DEBUG'             => 'false',
        'STRIPE_MODE'           => 'test',
        'STRIPE_SECRET_KEY'     => APP_SK_TEST,
        'STRIPE_WEBHOOK_SECRET' => APP_WHSEC,
        'LGMS_SHARED_SECRET'    => APP_SHARED,
        'LGMS_SYNC_URL'         => 'https://dev2.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer',
    ], $over ) );
}

/**
 * Reset EVERY piece of shared state and re-pin the options each scenario needs.
 *
 * ⚠️ THE PINS GO HERE, INSIDE THE RESET, NOT ONCE AT MODULE SCOPE. A pin set
 * once is silently gone by the first scenario that clears $OPTS, and the
 * assertions after it then measure a default nobody chose — the exact shape
 * that reddened four neighbouring gates on #181.
 */
/**
 * Run the REAL exemption filters, exactly as WordPress would, and return what
 * they collectively produce.
 *
 * ⚠️ NOT A LIST TYPED OUT HERE. A hand-kept copy is a second place to be wrong,
 * and being wrong in exactly this way — a sentence about which routes are open,
 * left behind by two lanes — is the defect #203 found in the panel this gate
 * measures. Calling the filters means the fixture moves when the code moves.
 *
 * @return list<string>
 */
function real_exemptions(): array {
    $out = [];
    foreach ( [
        [ LGMS\Wp\CheckoutAudienceRestController::class, 'exemptFromBuddyBossRestriction' ],
        [ LGMS\Wp\PatreonStandingRestController::class,  'exemptFromBuddyBossRestriction' ],
        [ LGMS\Wp\RestController::class, 'exemptAuthFromBuddyBossRestriction' ],
        [ LGMS\Wp\RestController::class, 'exemptSyncCustomerFromBuddyBossRestriction' ],
        [ LGMS\Wp\RestController::class, 'exemptGiftCodesFromBuddyBossRestriction' ],
        [ LGMS\Wp\RestController::class, 'exemptGiftRecipientFromBuddyBossRestriction' ],
    ] as $cb ) {
        $out = $cb( $out );
    }
    return $out;
}

function scenario( string $envPath, array $opts = [], array $probe = [], array $filters = [ 'bb_exclude_endpoints_from_restriction' ], ?array $exempted = null ): array {
    global $OPTS, $PROBE, $FILTERS, $FILTER_VALUES;
    $GLOBALS['DB_BROKEN'] = false;
    Db::wipe();
    Health::reset();
    $OPTS = array_merge( [
        'lgms_shared_secret'         => APP_SHARED,
        'lgms_stripe_webhook_secret' => APP_WHSEC,
        'lgms_stripe_secret_key'     => APP_SK_TEST,
        'lgms_checkout_audience'     => 'allowlist',
        'lgms_stripe_lifecycle_allowlist' => [ 854, 1887 ],
        'lgms_stripe_testgroup_pages'=> '1',
        'lgms_stripe_pages_live'     => '0',
        'bb-enable-private-rest-apis'=> '1',
    ], $opts );
    $PROBE   = $probe === [] ? [ 'code' => 200, 'body' => '{"state":"allowlist","allowed":false}' ] : $probe;
    $FILTERS = $filters;
    /* The real filters, run for real — the union of what the three controllers
       actually append, so this can never drift from the code under test. */
    $FILTER_VALUES = [ 'bb_exclude_endpoints_from_restriction' => $exempted ?? real_exemptions() ];
    $_SERVER['LG_HEALTH_APP_ENV'] = $envPath;
    putenv( 'LG_HEALTH_APP_ENV=' . $envPath );
    recording_started( gmdate( 'Y-m-d H:i:s', time() - 86400 * 14 ) );
    install_probe_transport();
    return Health::describe();
}

/** One check out of a describe() result. */
function chk( array $d, string $key ): array {
    foreach ( $d['checks'] as $c ) { if ( $c['key'] === $key ) { return $c; } }
    return [ 'key' => $key, 'status' => '(absent)', 'summary' => '', 'lines' => [], 'note' => '' ];
}

/** Every rendered word of a check, so an assertion can look for a phrase. */
function words( array $c ): string {
    $s = $c['summary'];
    foreach ( $c['lines'] as $l ) { $s .= ' | ' . $l['label'] . ': ' . $l['value']; }
    return $s;
}

function render_panel(): string {
    ob_start();
    HealthPanel::render();
    return (string) ob_get_clean();
}

/** A catalogue row set. */
function seed_products( array $rows ): void {
    $p = Db::pdo();
    foreach ( $rows as $i => $r ) {
        $st = $p->prepare( 'INSERT INTO products (id, kind, active, ref, region_tag) VALUES (?,?,?,?,?)' );
        $st->execute( [ $i + 1, $r['kind'] ?? 'membership', $r['active'] ?? 1, $r['ref'] ?? null, $r['region'] ?? null ] );
        $pr = $p->prepare( 'INSERT INTO prices (id, product_id, active) VALUES (?,?,1)' );
        $pr->execute( [ $i + 1, $i + 1 ] );
    }
}

function seed_receipt( string $action, string $whenUtc ): void {
    $st = Db::pdo()->prepare(
        "INSERT INTO audit_log (actor_type, actor_ref, subject_type, subject_id, action, details, created_at)
         VALUES ('webhook','stripe','webhook',0,?,'{}',?)"
    );
    $st->execute( [ $action, $whenUtc ] );
}

/**
 * Sales, dated. The DATE is the point: a sale from before webhook recording
 * existed is history, and a sale after it with no receipt is a finding — and
 * the first real run of this panel proved the difference matters, reporting
 * "a payment completed with no webhook recorded" against 109 dev2 rows that
 * all predated the recorder.
 */
function seed_money( int $customers, int $subs, string $whenUtc ): void {
    $p = Db::pdo();
    for ( $i = 1; $i <= $customers; $i++ ) { $p->exec( "INSERT INTO customers (id, created_at) VALUES ($i, '$whenUtc')" ); }
    for ( $i = 1; $i <= $subs; $i++ )      { $p->exec( "INSERT INTO subscriptions (id, created_at) VALUES ($i, '$whenUtc')" ); }
}

/** Pretend webhook recording landed on this box at $whenUtc. */
function recording_started( string $whenUtc ): void {
    global $RUN;
    $f = $RUN . '/WebhookReceipts.php';
    file_put_contents( $f, "<?php // stand-in for the recorder's presence on this box\n" );
    touch( $f, (int) strtotime( $whenUtc . ' UTC' ) );
    $_SERVER['LG_HEALTH_RECORDER'] = $f;
    putenv( 'LG_HEALTH_RECORDER=' . $f );
}

/** Pretend the recorder is not deployed here at all. */
function recording_absent(): void {
    $_SERVER['LG_HEALTH_RECORDER'] = '/nonexistent/WebhookReceipts.php';
    putenv( 'LG_HEALTH_RECORDER=/nonexistent/WebhookReceipts.php' );
}

/** A file's CODE ONLY — PHP's tokenizer, never a regex. See the header. */
function php_code_only( string $path ): string {
    $out = '';
    foreach ( token_get_all( (string) file_get_contents( $path ) ) as $t ) {
        if ( is_array( $t ) ) {
            if ( in_array( $t[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

echo "GATE 91 — the membership health panel tells the truth when things are broken\n";

// ===========================================================================
section( 'A. THE BILLING APP\'S SETTINGS FILE — four states, never conflated' );
// ===========================================================================

/* Each of these needs a DIFFERENT fix, which is the whole reason they are not
   one "not configured" branch: a missing file is the wrong box or a failed
   deploy, an unreadable one is a permissions job for root, and an empty one is
   a truncated write. ⚠️ dev2 and live are not the same shape here — dev2's
   /srv path is a symlink with a world-readable .env, live's is a real directory
   owned by www-data at mode 0640. */

$d = scenario( $RUN . '/no-such-file' );
is_( $d['app_env']['state'] === 'missing', 'A1 an absent settings file reads as `missing`, not as an empty value' );

/* TWO WAYS TO BE UNREADABLE, AND THE FIRST ONE FOUND A REAL DEFECT.
   Pointing at a DIRECTORY is deterministic for every user including root —
   and PHP's file_get_contents() on a directory returns the EMPTY STRING, not
   false, so the first implementation reported it as `empty` ("a truncated
   deploy") and would have sent whoever read it hunting a broken write. */
@mkdir( $RUN . '/a-directory' );
$d = scenario( $RUN . '/a-directory' );
is_( $d['app_env']['state'] === 'unreadable', 'A2 a settings file that cannot be read reads as `unreadable`, distinct from `missing`' );
is_( stripos( $d['app_env']['reason'], 'not a file' ) !== false,
     'A2b a DIRECTORY at that path says so — it is not reported as an empty file' );

/* The permissions case, which is the one live may actually be in. ⚠️ ROOT
   BYPASSES DAC, so as root is_readable() is true on a 0000 file and this
   assertion would go RED against correct code. Reported rather than asserted in
   that case — a false RED blocks every lane behind it just as hard as a false
   green hides a defect. */
$euid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : (int) trim( (string) shell_exec( 'id -u' ) );
$locked = $RUN . '/locked.env';
file_put_contents( $locked, "LGMS_SHARED_SECRET=" . APP_SHARED . "\n" );
@chmod( $locked, 0000 );
if ( $euid === 0 ) {
    report( 'A2c skipped: running as root, which can read a 0000 file, so the permissions case cannot be posed here' );
} else {
    $d = scenario( $locked );
    is_( $d['app_env']['state'] === 'unreadable',
         'A2c a file this user may not read is `unreadable`, and the panel does not pretend the key is absent' );
}
@chmod( $locked, 0644 );

$d = scenario( write_env( [] ) );
is_( $d['app_env']['state'] === 'empty', 'A3 a settings file that parses to nothing reads as `empty`' );

$d = scenario( healthy_env() );
is_( $d['app_env']['state'] === 'ok', 'A4 a good settings file parses' );

/* THE VACUOUS-GREEN KILLER. With no file, the two-halves question cannot be
   answered — and the failure mode of a health panel is a green tick for a
   question it could not answer. */
$d = scenario( $RUN . '/no-such-file' );
is_( chk( $d, 'secrets' )['status'] === 'unknown',
     'A5 with no settings file the secrets question is `unknown`, NOT `ok`' );
is_( chk( $d, 'mode' )['status'] === 'unknown',
     'A5b with no settings file the mode question is `unknown`, NOT `ok`' );
is_( str_contains( strtolower( words( chk( $d, 'secrets' ) ) ), 'cannot see' ),
     'A5c and it says in words that it cannot see it' );

/* NEVER PRINT A SECRET (Ian's rule, #192). This is asserted against the
   RENDERED MARKUP with real-looking values in the fixture, and it covers the
   sha256 too — a fingerprint of a secret is still derived from the secret and
   has no business on a screen. */
$d   = scenario( healthy_env() );
$out = render_panel();
$leaks = [];
foreach ( [ APP_SHARED, APP_WHSEC, APP_SK_TEST ] as $secret ) {
    if ( str_contains( $out, $secret ) )                       { $leaks[] = 'value'; }
    if ( str_contains( $out, substr( $secret, 8, 24 ) ) )       { $leaks[] = 'fragment'; }
    if ( str_contains( $out, hash( 'sha256', $secret ) ) )      { $leaks[] = 'sha256'; }
    if ( str_contains( $out, substr( $secret, 0, 8 ) ) )        { $leaks[] = 'prefix'; }
}
is_( $leaks === [], 'A6 no secret value, fragment, prefix or sha256 reaches the rendered markup' );

/* THE BOUNDARY IS STRUCTURAL, NOT EDITORIAL: describe() is the whole surface
   the renderer sees, so if nothing derived from a secret is in there, no
   renderer can leak one however carelessly it is written. */
$json  = (string) json_encode( $d );
$dirty = false;
foreach ( [ APP_SHARED, APP_WHSEC, APP_SK_TEST ] as $secret ) {
    if ( str_contains( $json, $secret ) )                  { $dirty = true; }
    if ( str_contains( $json, substr( $secret, 8, 24 ) ) )  { $dirty = true; }
    if ( str_contains( $json, hash( 'sha256', $secret ) ) ) { $dirty = true; }
}
is_( ! $dirty, 'A6b describe() itself carries no secret value and no sha256 of one' );

// ===========================================================================
section( 'B. DO THE TWO HALVES AGREE — failure #2, and live\'s shape today' );
// ===========================================================================

$d = scenario( healthy_env() );
$c = chk( $d, 'secrets' );
is_( $c['status'] === 'ok' && str_contains( words( $c ), 'AGREE' ),
     'B1 matching secrets read as AGREE' );

/* THE BROKEN STATE. One value in two homes with nothing comparing them is how
   a rotation breaks verification silently. */
$d = scenario( healthy_env(), [ 'lgms_shared_secret' => 'A-DIFFERENT-SECRET-ENTIRELY-0123456789' ] );
$c = chk( $d, 'secrets' );
is_( $c['status'] === 'fail' && str_contains( words( $c ), 'DISAGREE' ),
     'B2 a MISMATCHED shared secret is a FAIL and says DISAGREE' );

/* LIVE'S EXACT SHAPE, MEASURED 2026-08-21: the app has one, WordPress does not.
   The panel must name WHICH half is missing — "not configured" would send
   whoever reads it to the wrong file. */
$d = scenario( healthy_env(), [ 'lgms_shared_secret' => '' ] );
$c = chk( $d, 'secrets' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'WordPress: NOT SET' ) !== false,
     'B3 set in the billing app and absent in WordPress is a FAIL that names WordPress' );

$d = scenario( healthy_env( [ 'LGMS_SHARED_SECRET' => null ] ) );
$d = scenario( write_env( [
        'STRIPE_MODE' => 'test', 'STRIPE_SECRET_KEY' => APP_SK_TEST,
        'STRIPE_WEBHOOK_SECRET' => APP_WHSEC,
        'LGMS_SYNC_URL' => 'https://dev2.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer',
     ] ) );
$c = chk( $d, 'secrets' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'billing app: NOT SET' ) !== false,
     'B4 set in WordPress and absent in the billing app is a FAIL that names the billing app' );

$d = scenario( write_env( [ 'STRIPE_MODE' => 'test', 'STRIPE_SECRET_KEY' => APP_SK_TEST ] ),
               [ 'lgms_shared_secret' => '', 'lgms_stripe_webhook_secret' => '' ] );
$c = chk( $d, 'secrets' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'NOT SET on either side' ) !== false,
     'B5 absent on BOTH sides is still a FAIL, not a shrug' );

/* dev2's shape today: shared secret agrees, webhook secret absent in WordPress.
   Two pairs, independently judged — a panel that reported only the worst would
   hide the healthy one and make the fix look bigger than it is. */
$d = scenario( healthy_env(), [ 'lgms_stripe_webhook_secret' => '' ] );
$c = chk( $d, 'secrets' );
$w = words( $c );
is_( $c['status'] === 'fail' && str_contains( $w, 'AGREE' ) && stripos( $w, 'WordPress: NOT SET' ) !== false,
     'B6 the two pairs are judged independently — one can AGREE while the other is absent' );

is_( str_contains( $w, (string) strlen( APP_SHARED ) ),
     'B7 lengths are reported, which is the most a secret may ever say about itself' );

// ===========================================================================
section( 'C. TEST OR LIVE MODE' );
// ===========================================================================

$d = scenario( healthy_env() );
$c = chk( $d, 'mode' );
is_( $c['status'] === 'ok' && str_contains( words( $c ), 'TEST mode' ),
     'C1 two test keys read as TEST mode and are healthy' );

/* Not a defect, and deliberately not reported as one: two different keys in the
   same mode is normal — one of them may be restricted. dev2 is in this state. */
$d = scenario( healthy_env(), [ 'lgms_stripe_secret_key' => 'sk_test_ADIFFERENTTESTKEY_abcdefghijklmnop' ] );
is_( chk( $d, 'mode' )['status'] === 'ok',
     'C2 two DIFFERENT keys in the same mode is not reported as a problem' );

$d = scenario( healthy_env( [ 'STRIPE_SECRET_KEY' => APP_SK_LIVE, 'STRIPE_MODE' => 'live' ] ) );
$c = chk( $d, 'mode' );
is_( $c['status'] === 'fail' && str_contains( words( $c ), 'WordPress holds a test key' ),
     'C3 WordPress in test while the billing app is LIVE is a FAIL naming both' );

$d = scenario( healthy_env( [ 'STRIPE_MODE' => 'live' ] ) );
is_( chk( $d, 'mode' )['status'] === 'fail',
     'C4 a STRIPE_MODE that disagrees with the key it holds is a FAIL' );

$d = scenario( healthy_env( [ 'APP_DEBUG' => 'true' ] ) );
$c = chk( $d, 'mode' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'APP_DEBUG' ) !== false,
     'C5 APP_DEBUG left on is a FAIL — it displays errors to visitors' );

/* APP_ENV is a LABEL and is reported as one. Live's billing app says
   "env":"dev" on /billing/health, and it gates nothing (only APP_DEBUG does),
   so a panel that scored it would send somebody chasing a string. */
$d = scenario( healthy_env( [ 'APP_ENV' => 'dev' ] ) );
is_( chk( $d, 'mode' )['status'] === 'ok',
     'C6 an APP_ENV of "dev" alone is NOT scored as a defect — it is a label' );

// ===========================================================================
section( 'D. DOES THE CATALOGUE RESOLVE TO TIERS' );
// ===========================================================================

$d = scenario( healthy_env() );   // no products seeded
is_( chk( $d, 'catalogue' )['status'] === 'warn' && stripos( words( chk( $d, 'catalogue' ) ), 'EMPTY' ) !== false,
     'D1 an empty catalogue is a WARN that says so — it is what live looks like before go-live' );

$d = scenario( healthy_env(), [ 'lgms_stripe_price_looth2_month' => 'price_1', 'lgms_stripe_price_looth3_month' => 'price_2' ] );
seed_products( [ [ 'ref' => 'looth2' ], [ 'ref' => 'looth3' ] ] );
Health::reset();
$c = chk( Health::describe(), 'catalogue' );
is_( $c['status'] === 'ok' && str_contains( words( $c ), 'looth2, looth3' ),
     'D2 a healthy catalogue names the tiers it resolves to' );

/* THE BROKEN STATE. A product with no ref grants nothing, and checkout refuses
   it with "not mapped to a membership tier". */
$d = scenario( healthy_env(), [ 'lgms_stripe_price_looth3_month' => 'price_2' ] );
seed_products( [ [ 'ref' => null ], [ 'ref' => 'looth3' ] ] );
Health::reset();
$c = chk( Health::describe(), 'catalogue' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'not mapped to a membership tier' ) !== false,
     'D3 an active product with NO tier ref is a FAIL that quotes the refusal a buyer sees' );

/* Registered but unpriced: reachable in the catalogue, not offered anywhere. */
$d = scenario( healthy_env(), [ 'lgms_stripe_price_looth3_month' => 'price_2' ] );
seed_products( [ [ 'ref' => 'looth2' ], [ 'ref' => 'looth3' ] ] );
Health::reset();
$c = chk( Health::describe(), 'catalogue' );
is_( $c['status'] === 'warn' && str_contains( words( $c ), 'looth2' ),
     'D4 a tier registered with no price is a WARN that NAMES the tier' );

$GLOBALS['DB_BROKEN'] = true;
Health::reset();
is_( chk( Health::describe(), 'catalogue' )['status'] === 'unknown',
     'D5 an unreachable billing database makes the catalogue question `unknown`, not `ok`' );
$GLOBALS['DB_BROKEN'] = false;

// ===========================================================================
section( 'E. WHO MAY BUY — the audience, the cohort, and the doors they need' );
// ===========================================================================

$d = scenario( healthy_env() );
is_( chk( $d, 'audience' )['status'] === 'ok',
     'E1 `allowlist` with a populated cohort and an open tester page is healthy' );

/* LIVE'S SHAPE TODAY, measured: the option is absent (so `allowlist` by
   default) and the cohort list does not exist at all. */
$d = scenario( healthy_env(), [ 'lgms_stripe_lifecycle_allowlist' => [] ] );
$c = chk( $d, 'audience' );
is_( $c['status'] === 'warn' && stripos( words( $c ), 'EMPTY' ) !== false,
     'E2 `allowlist` with an EMPTY cohort says nobody at all can buy' );

/* #193 — A COHORT OF ADDRESSES IS NOT AN EMPTY COHORT.
   Ian 8/22 ruled the list takes plain email addresses for testers who have no
   account yet. Counting only the ids here would print "the audience is
   `allowlist` and the cohort is EMPTY, so nobody at all can buy" over a cohort
   that is working — the panel crying wolf on its own deployment day, which is
   the exact failure #192 spent a rewrite removing. */
$d = scenario( healthy_env(), [ 'lgms_stripe_lifecycle_allowlist' => [ 'tester@example.com', 'second@example.com' ] ] );
$c = chk( $d, 'audience' );
is_( $c['status'] === 'ok',
     'E2b a cohort of ADDRESSES ONLY is healthy — it admits people, so it is not empty' );
is_( stripos( words( $c ), 'EMPTY' ) === false,
     'E2c ...and is never described as EMPTY' );
is_( stripos( words( $c ), 'address' ) !== false,
     'E2d ...and the panel says they are ADDRESSES, which is a different situation '
   . 'from a member who has already joined' );

$d = scenario( healthy_env(), [ 'lgms_stripe_lifecycle_allowlist' => [ 854, 1887, 'tester@example.com' ] ] );
$c = chk( $d, 'audience' );
is_( $c['status'] === 'ok' && stripos( words( $c ), '2 member(s)' ) !== false
     && stripos( words( $c ), '1 address(es)' ) !== false,
     'E2e a MIXED cohort counts both halves separately, so neither hides the other' );

/* THE LIVENESS PARTNER: E2 above must still fire on a genuinely empty list, or
   E2b has simply broken the empty check rather than taught it to count. */
$d = scenario( healthy_env(), [ 'lgms_stripe_lifecycle_allowlist' => [ 'not-an-email', '' ] ] );
$c = chk( $d, 'audience' );
is_( $c['status'] === 'warn' && stripos( words( $c ), 'EMPTY' ) !== false,
     'E2f a list of MALFORMED entries is still EMPTY — a junk entry admits nobody and is counted as nobody' );

/* #165 and #170's shape: a switch on, and the door it needs shut. */
$d = scenario( healthy_env(), [ 'lgms_stripe_testgroup_pages' => '0', 'lgms_stripe_pages_live' => '0' ] );
$c = chk( $d, 'audience' );
is_( $c['status'] === 'warn' && stripos( words( $c ), "isn't available yet" ) !== false,
     'E3 a cohort that may buy but cannot SEE the join page is named, not silently healthy' );

$d = scenario( healthy_env(), [ 'lgms_checkout_audience' => 'on', 'lgms_stripe_pages_live' => '0' ] );
$c = chk( $d, 'audience' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'pre-launch' ) !== false,
     'E4 audience `on` with a pre-launch join page is a FAIL' );

$d = scenario( healthy_env(), [ 'lgms_checkout_audience' => 'off' ] );
is_( chk( $d, 'audience' )['status'] === 'warn',
     'E5 audience `off` is surfaced — nobody is fenced in that state' );

/* An absent option must read as the DEFAULT and say so, never as "off". #181's
   default is `allowlist` and is the one enforcing flag on this rail. */
$d = scenario( healthy_env(), [ 'lgms_checkout_audience' => null ] );
unset( $GLOBALS['OPTS']['lgms_checkout_audience'] );
Health::reset();
$c = chk( Health::describe(), 'audience' );
is_( str_contains( words( $c ), 'allowlist' ) && stripos( words( $c ), 'default' ) !== false,
     'E6 an unset audience option reads as `allowlist` and says it is the default' );

/* ONE LIST, AND THERE MUST NEVER BE A SECOND. The health panel must not grow
   its own idea of the cohort. */
$code = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/Health.php' );
is_( ! str_contains( $code, 'lgms_stripe_lifecycle_allowlist' ),
     'E7 Health names no cohort option of its own — it asks CohortAllowlist' );

// ===========================================================================
section( 'F. CAN THE TWO HALVES REACH EACH OTHER — failures #1 and #3' );
// ===========================================================================

$d = scenario( healthy_env() );
is_( chk( $d, 'channel' )['status'] === 'ok',
     'F1 a matching sync URL and an answering route is healthy' );

/* FAILURE #1, EXACTLY. The billing app pointed at dev.loothgroup.com, a host
   that does not exist, and nothing said so for an unknown length of time.
   `.invalid` is reserved by RFC 2606 and cannot resolve, so this is
   deterministic on any box. */
$d = scenario( healthy_env( [ 'LGMS_SYNC_URL' => 'https://gone.invalid/wp-json/lg-member-sync/v1/sync-customer' ] ) );
$c = chk( $d, 'channel' );
$w = words( $c );
is_( $c['status'] === 'fail' && str_contains( $w, 'gone.invalid' ) && str_contains( $w, 'dev2.loothgroup.com' ),
     'F2 a sync URL on another host is a FAIL that names BOTH hosts' );
is_( stripos( $w, 'does not resolve' ) !== false,
     'F2b and when that host does not exist at all, it says so — a different fix from a typo' );

$d = scenario( healthy_env( [ 'LGMS_SYNC_URL' => '' ] ) );
is_( chk( $d, 'channel' )['status'] === 'fail',
     'F3 a billing app with no sync URL at all is a FAIL' );

/* FAILURE #3. BuddyBoss pre-empts the REST stack before any permission
   callback runs, and the only visible symptom is a 401 nobody is looking at. */
$d = scenario( healthy_env(), [], [], [] /* no exemption filter registered */ );
$c = chk( $d, 'channel' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'exemption' ) !== false,
     'F4 BuddyBoss restricting REST with NO exemption registered is a FAIL' );

$d = scenario( healthy_env(), [], [ 'code' => 401, 'body' => '{"code":"bb_rest_authorization_required"}' ] );
$c = chk( $d, 'channel' );
is_( $c['status'] === 'fail' && str_contains( words( $c ), 'bb_rest_authorization_required' ),
     'F5 a probe that BuddyBoss answers instead of us is a FAIL that names it' );

$d = scenario( healthy_env(), [], [ 'code' => 403, 'body' => '{"code":"forbidden"}' ] );
is_( chk( $d, 'channel' )['status'] === 'fail',
     'F6 a probe rejected on the shared secret is a FAIL' );

/* UNKNOWN MUST NOT COLLAPSE INTO OK. */
$d = scenario( healthy_env(), [], [ 'error' => 'cURL error 7: Connection refused' ] );
$c = chk( $d, 'channel' );
is_( $c['status'] === 'unknown' && stripos( words( $c ), 'could not reach' ) !== false,
     'F7 a probe that cannot run is `unknown`, NOT `ok`, and says so' );

$d = scenario( healthy_env(), [ 'lgms_shared_secret' => '' ] );
is_( str_contains( words( chk( $d, 'channel' ) ), 'not attempted' ),
     'F8 with no shared secret the probe is not attempted and says why' );

/* ⚠️ THE TCP CONNECTION MUST BE PINNED TO THE BOX. A plain request to the public
   name on live is bot-challenged by Cloudflare into a 403 that reads exactly
   like an outage — a trap this repo has paid for more than once. The URL keeps
   the real hostname so SNI, the certificate and nginx's server_name still
   match; only the connection is pinned. */
$d = scenario( healthy_env() );
is_( ( $GLOBALS['PROBE_OPTS']['resolve'] ?? [] ) === [ 'dev2.loothgroup.com:443:127.0.0.1' ],
     'F9 the probe pins the connection to 127.0.0.1 with CURLOPT_RESOLVE' );
is_( str_starts_with( (string) ( $GLOBALS['PROBE_URL'] ?? '' ), 'https://dev2.loothgroup.com/wp-json/' ),
     'F9b and keeps the real hostname in the URL, so Cloudflare is never in the path' );
is_( ( $GLOBALS['PROBE_OPTS']['timeout'] ?? 99 ) <= 5,
     'F9c and it is capped at a few seconds so a dead channel cannot hang the admin page' );
is_( in_array( 'X-LGMS-Token: ' . APP_SHARED, (array) ( $GLOBALS['PROBE_OPTS']['headers'] ?? [] ), true ),
     'F9d and it authenticates with the shared secret, which is what makes a 200 meaningful' );

/* ── #203: THE ROLL-CALL, because the sentence it replaced was a lane behind ──
   This panel said "checkout-audience is exempted" from #181 until #203, by
   which time #193 and its rider had opened three more routes. An operator
   reading it would have concluded the rest were shut while two of them were
   open — the health panel failing its one job, quietly, with every assertion
   above it still green. What follows measures the roll-call against the REAL
   filters, and the still-shut line against RestController's own list. */
$d = scenario( healthy_env() );
$w = words( chk( $d, 'channel' ) );
foreach ( real_exemptions() as $open ) {
    is_( str_contains( $w, $open ),
         'F10 the roll-call names ' . $open . ' — every route the filters actually open' );
}
is_( str_contains( $w, count( real_exemptions() ) . ' route(s) exempted' ),
     'F10b ...and counts them, so "an exemption is registered" stops meaning "the one I remember"' );

/* ⚠️ THE ASSERTION THAT BITES IS THE STILL-SHUT ONE. "It names the open routes"
   would pass on a panel that names ALL of them, which is the pre-#203 failure
   inverted; what an operator needs is the DIFFERENCE. /run-now is what #203
   deliberately left shut, so it must appear here and nowhere in the open list. */
is_( str_contains( $w, '/lg-member-sync/v1/run-now' ),
     'F10c the still-shut line names /run-now — #203 shut it on purpose, so it must stay visible' );
is_( ! in_array( '/lg-member-sync/v1/run-now', real_exemptions(), true ),
     'F10d ...and nothing exempted it behind the panel\'s back' );
foreach ( [ '/lg-member-sync/v1/sync-customer',
            '/lg-member-sync/v1/send-gift-codes',
            '/lg-member-sync/v1/send-gift-recipient' ] as $opened ) {
    is_( in_array( $opened, real_exemptions(), true ),
         'F10e ' . $opened . ' is OPEN after #203 — so it is not on the still-shut line' );
}
$shutCount = count( array_diff( LGMS\Wp\RestController::SECRET_ROUTES, real_exemptions() ) );
is_( $shutCount === 1,
     'F10f exactly ONE shared-secret route is still behind the 401, and it is a decision, not a leftover' );

/* AND IT IS ASKED, NOT TRANSCRIBED. A panel holding its own copy of the route
   list is the same defect one indirection along. */
$healthSrcRoll = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/Health.php' );
is_( str_contains( $healthSrcRoll, "apply_filters( 'bb_exclude_endpoints_from_restriction'" ),
     'F10g the panel RUNS the hook — it does not carry a sentence about it' );
is_( str_contains( $healthSrcRoll, 'RestController::SECRET_ROUTES' ),
     'F10h and takes the route list from RestController, beside the routes themselves' );
is_( ! preg_match( '#[\'"]/lg-member-sync/v1/(run-now|sync-customer|send-gift)#', $healthSrcRoll ),
     'F10i and hardcodes no route of its own, so a new one cannot go unmentioned' );

/* THE CONVENTION IT FOLLOWS, asserted by source: this plugin requires raw curl
   with CURLOPT_RESOLVE for every server-to-server hop — wp_remote_post is
   documented as not working for these. */
$healthSrc = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/Health.php' );
is_( str_contains( $healthSrc, 'CURLOPT_RESOLVE' ) && ! str_contains( $healthSrc, 'wp_remote_post' ),
     'F9e the probe uses raw curl with CURLOPT_RESOLVE, as this plugin requires' );

// ===========================================================================
section( 'G. ARE WEBHOOKS ARRIVING — never, long ago, and quiet are three answers' );
// ===========================================================================

/* Keeper's standing requirement, 2026-08-21: "The webhook answer must
   distinguish 'never arrived' from 'arrived long ago'. Silence is the failure
   mode, and a blank cell reads like health." */

$d = scenario( healthy_env() );   // nothing sold, no receipts
$c = chk( $d, 'webhooks' );
$w = words( $c );
is_( $c['status'] === 'warn' && str_contains( $w, 'Never' ),
     'G1 never, on a box that has sold nothing, is a WARN that says NEVER in words' );
is_( stripos( $w, 'expected' ) !== false,
     'G1b and it says why that is expected, so nobody chases it' );

/* THE ONE THAT MATTERS. A sale landed AFTER recording started and no webhook
   was recorded for it. */
$d = scenario( healthy_env() );
seed_money( 3, 2, gmdate( 'Y-m-d H:i:s', time() - 86400 ) );   // yesterday: after recording started
Health::reset();
$c = chk( Health::describe(), 'webhooks' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'no webhook recorded' ) !== false,
     'G2 never, with a sale SINCE recording started, is a FAIL — money moved unaccounted for' );

/* ⚠️ AND THE CASE THAT MAKES THAT VERDICT TRUSTWORTHY, WHICH THE FIRST REAL RUN
   FOUND: sales that ALL predate the recorder are history, not a finding. dev2
   has 109 of them, and a panel that shouts about its own deployment day is a
   panel nobody reads twice. */
$d = scenario( healthy_env() );
seed_money( 60, 49, gmdate( 'Y-m-d H:i:s', time() - 86400 * 90 ) );   // long before recording started
Health::reset();
$c = chk( Health::describe(), 'webhooks' );
$w = words( $c );
is_( $c['status'] === 'warn' && stripos( $w, 'predates' ) !== false,
     'G2b never, with every sale PREDATING the recorder, is a WARN that says so — not a false alarm' );
is_( str_contains( $w, 'Customers + subscriptions on this box: 109' ),
     'G2c and it still reports how many sales there were, so nothing is hidden' );

/* Cannot find the recorder ⇒ cannot tell the two apart ⇒ say so. */
$d = scenario( healthy_env() );
seed_money( 3, 2, gmdate( 'Y-m-d H:i:s', time() - 86400 ) );
recording_absent();
Health::reset();
$c = chk( Health::describe(), 'webhooks' );
is_( $c['status'] === 'unknown' && stripos( words( $c ), 'cannot tell when webhook recording started' ) !== false,
     'G2d with no recorder on the box the verdict is `unknown`, not a guess in either direction' );

$d = scenario( healthy_env() );
seed_money( 3, 2, gmdate( 'Y-m-d H:i:s', time() - 86400 ) );
seed_receipt( Health::ACT_RECEIVED, gmdate( 'Y-m-d H:i:s', time() - 600 ) );
Health::reset();
$c = chk( Health::describe(), 'webhooks' );
is_( $c['status'] === 'ok' && stripos( words( $c ), 'minutes ago' ) !== false,
     'G3 a recent webhook is OK and reports its AGE, not a raw timestamp alone' );

/* ARRIVED LONG AGO — the answer a blank cell destroys. */
$d = scenario( healthy_env() );
seed_money( 3, 2, gmdate( 'Y-m-d H:i:s', time() - 86400 ) );
seed_receipt( Health::ACT_RECEIVED, gmdate( 'Y-m-d H:i:s', time() - 86400 * 30 ) );
Health::reset();
$c = chk( Health::describe(), 'webhooks' );
$w = words( $c );
is_( $c['status'] === 'warn' && str_contains( $w, '30 days ago' ),
     'G4 a webhook that arrived long ago is a WARN that spells out HOW long' );
is_( str_contains( $w, 'nothing since' ),
     'G4b and says nothing has come since, which is the finding' );

/* Stripe is reaching us and we are throwing it away — the outside view of a
   mismatched webhook secret, and the only place it is ever visible. */
$d = scenario( healthy_env() );
seed_receipt( Health::ACT_RECEIVED, gmdate( 'Y-m-d H:i:s', time() - 600 ) );
seed_receipt( Health::ACT_SIG_FAIL, gmdate( 'Y-m-d H:i:s', time() - 120 ) );
Health::reset();
$c = chk( Health::describe(), 'webhooks' );
is_( $c['status'] === 'fail' && stripos( words( $c ), 'REJECTING' ) !== false,
     'G5 a signature failure is a FAIL and OUTRANKS a healthy recent success' );
is_( stripos( words( $c ), 'secret does not match' ) !== false,
     'G5b and it names the cause rather than leaving the reader to infer it' );

$GLOBALS['DB_BROKEN'] = true;
Health::reset();
$c = chk( Health::describe(), 'webhooks' );
is_( $c['status'] === 'unknown' && stripos( words( $c ), 'reason' ) !== false,
     'G6 an unreachable billing database makes the webhook question `unknown` WITH a reason' );
$GLOBALS['DB_BROKEN'] = false;

/* A BLANK CELL READS LIKE HEALTH. Asserted on the MARKUP, because that is where
   a blank would actually appear. */
$d   = scenario( healthy_env() );
$out = render_panel();
is_( str_contains( $out, '>Never<' ) || str_contains( $out, 'Never' ),
     'G7 the rendered panel prints the WORD Never — never an empty cell' );
is_( ! preg_match( '/<span class="lgms-h-chip[^"]*">\s*<\/span>/', $out ),
     'G7b no chip in the rendered panel is empty' );

// ===========================================================================
section( 'H. THE WIRING, AND THE RULES THAT MUST NOT ROT' );
// ===========================================================================

/* ⚠️ ASSERTED UNCONDITIONALLY. #190's equivalent only checked placement when
   the page already looked right and reported otherwise, so a revert flipped it
   into report mode and it said nothing at all. */
$adminCode = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/Admin.php' );
/* THE PAIR, not the two halves separately: red-first M40 renamed the tab slug
   to 'healthz' and this assertion stayed green, because the slug it was looking
   for still existed one line away in the dispatch. H1 and H2 were the same
   assertion wearing two names. */
is_( preg_match( "/'health'\s*=>\s*'Health'/", $adminCode ) === 1,
     'H1 Admin.php registers a Health tab' );
is_( str_contains( $adminCode, 'HealthPanel::render()' ),
     'H2 and dispatches it to HealthPanel, not to a copy of the markup' );
is_( str_contains( $adminCode, "'settings'      => 'Settings'," ) &&
     strpos( $adminCode, "'settings'" ) < strpos( $adminCode, "'health'" ),
     'H3 Settings still comes first, so the default tab and every #190 redirect are unchanged' );

/* READ-ONLY, per Ian's ruling: server-file settings are read-only with a copy
   button. A form on this screen is a different risk class from a screen that
   describes one. */
$panelCode = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/HealthPanel.php' );
foreach ( [ '<form', 'admin_post', 'update_option', 'delete_option', 'wp_nonce_field' ] as $forbidden ) {
    is_( ! str_contains( $panelCode, $forbidden ),
         'H4 the panel contains no ' . $forbidden . ' — it reads and never writes' );
}
$healthCode = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/Health.php' );
is_( ! str_contains( $healthCode, 'update_option' ) && ! str_contains( $healthCode, 'delete_option' ),
     'H5 the reader writes no option either' );
/* ASSERTED ON THE MARKUP, NOT THE SOURCE: red-first M51 relabelled the button
   and the source check stayed green, because the old label also lives in the
   panel's own clipboard JS. The ruling is about a control on a screen, so the
   screen is where it is measured. */
scenario( healthy_env() );
$panelOut = render_panel();
is_( preg_match( '/<input[^>]*id="lgms-health-envpath"[^>]*\breadonly\b/', $panelOut ) === 1
     && str_contains( $panelOut, esc_attr( Health::appEnvPath() ) ),
     'H6 the settings file path is shown in a READ-ONLY field' );
is_( preg_match( '/<button[^>]*id="lgms-health-copy"[^>]*>\s*Copy/', $panelOut ) === 1,
     'H6b with a copy button beside it, as ruled — read-only with a copy button' );

/* THE RECEIPT SIDE. Question one has no data source without it. */
$whCode = php_code_only( $GATE_ROOT . '/lg-stripe-billing/src/Http/Controllers/WebhookController.php' );
is_( str_contains( $whCode, 'WebhookReceipts::recordVerified' ),
     'H7 the webhook controller records every VERIFIED event' );
is_( substr_count( $whCode, 'WebhookReceipts::recordSignatureFailure' ) === 2,
     'H8 and records BOTH signature-failure paths — a bad signature and no secret at all' );
is_( strpos( $whCode, 'WebhookReceipts::recordVerified' ) < strpos( $whCode, '$obj = $event->data->object' ),
     'H9 the receipt is written BEFORE dispatch, so a throwing handler cannot erase the evidence' );

$recCode = php_code_only( $GATE_ROOT . '/lg-stripe-billing/src/Core/WebhookReceipts.php' );
/* The USE site, not the declaration: a constant that exists and is never
   compared against throttles nothing. */
is_( str_contains( $recCode, 'self::FAIL_THROTTLE_SECONDS' ),
     'H10 signature-failure records are throttled — that endpoint is unauthenticated' );
is_( substr_count( $recCode, 'catch (Throwable' ) >= 2,
     'H11 every receipt path swallows Throwable — bookkeeping cannot break the thing it records' );
is_( ! str_contains( $recCode, '$payload' ),
     'H12 no webhook payload is stored — only the event id and type' );

/* worst() must rank a thing we CANNOT SEE above a thing we can see is untidy,
   or the headline reassures on the exact question that went unanswered. */
is_( Health::worst( [ [ 'status' => 'warn' ], [ 'status' => 'unknown' ] ] ) === 'unknown',
     'H13 `unknown` outranks `warn` in the headline' );
is_( Health::worst( [ [ 'status' => 'unknown' ], [ 'status' => 'fail' ] ] ) === 'fail',
     'H14 `fail` outranks `unknown`' );
is_( Health::worst( [ [ 'status' => 'ok' ], [ 'status' => 'ok' ] ] ) === 'ok',
     'H15 all-healthy is ok' );

/* The override must be read from BOTH $_SERVER and getenv: a value delivered by
   fastcgi_param lands in $_SERVER only, and reading one of the two is how a
   flag serves the wrong state on the very URL built to exercise it. */
/* NAMED, not generic: red-first M50 deleted the settings-file override's
   $_SERVER read and this assertion stayed green off an unrelated $_SERVER read
   in the neighbouring method. */
is_( preg_match( "/\\\$_SERVER\[\s*'LG_HEALTH_APP_ENV'/", $healthCode ) === 1
     && preg_match( "/getenv\(\s*'LG_HEALTH_APP_ENV'/", $healthCode ) === 1,
     'H16 the settings-file override is read from both $_SERVER and getenv' );
is_( preg_match( "/\\\$_SERVER\[\s*'LG_HEALTH_RECORDER'/", $healthCode ) === 1
     && preg_match( "/getenv\(\s*'LG_HEALTH_RECORDER'/", $healthCode ) === 1,
     'H16b and so is the recorder override' );

// ===========================================================================
section( 'I. THE PANEL RENDERS EVERY STATE WITHOUT DYING' );
// ===========================================================================

$states = [
    'healthy'            => [ healthy_env(), [] ],
    'no settings file'   => [ $RUN . '/no-such-file', [] ],
    'mismatched secrets' => [ healthy_env(), [ 'lgms_shared_secret' => 'OTHER-VALUE-ENTIRELY' ] ],
    'empty cohort'       => [ healthy_env(), [ 'lgms_stripe_lifecycle_allowlist' => [] ] ],
    'dead sync host'     => [ healthy_env( [ 'LGMS_SYNC_URL' => 'https://gone.invalid/x/sync-customer' ] ), [] ],
];
$allOut = '';
foreach ( $states as $name => [ $envp, $opts ] ) {
    scenario( $envp, $opts );
    $m = render_panel();
    $allOut .= $m;
    is_( strlen( $m ) > 500 && str_contains( $m, 'lgms-h-card' ),
         'I1 the panel renders in the "' . $name . '" state' );
}
is_( ! str_contains( $allOut, 'Fatal error' ) && ! str_contains( $allOut, 'Warning:' ),
     'I2 no state produces a PHP notice, warning or fatal in the markup' );

scenario( healthy_env(), [ 'lgms_shared_secret' => 'OTHER-VALUE-ENTIRELY' ] );
$m = render_panel();
is_( str_contains( $m, 'BROKEN' ),
     'I3 a broken state renders the word BROKEN, not a neutral chip' );
scenario( $RUN . '/no-such-file' );
$m = render_panel();
is_( str_contains( $m, 'CANNOT SEE' ),
     'I4 an unanswerable question renders CANNOT SEE, not a blank and not OK' );
is_( str_contains( $m, 'permissions job for root' ) || str_contains( $m, 'no billing-app settings file' ),
     'I5 and the banner says which of the four states it is in' );

// ---------------------------------------------------------------------------

echo "\n";
foreach ( $reports as $r ) { echo "  note $r\n"; }
if ( $fail > 0 ) {
    echo "\nGATE 91 RED — $fail failing, $pass passing\n";
    exit( 1 );
}
echo "\nGATE 91 GREEN — $pass assertions\n";
exit( 0 );

} // end global namespace
