<?php
/**
 * GATE 74 — one payment source per member.
 *
 *   php tools/gates/double-pay-block-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Ian, 2026-08-19, verbatim: "We should disallow double payment source for the
 * same user." BLOCK, not warn. Issues #150 (the purchase-time surface) and #149
 * (the members already overlapped).
 *
 * WHAT WAS TRUE BEFORE THIS GATE, and is the defect it exists to keep out: a
 * member paying $5 or $11 on Patreon RIGHT NOW could walk through checkout and
 * pay a second time, with no warning anywhere. Three doors, all Patreon-blind:
 *
 *   1. Slim  POST /billing/v1/checkout   — CheckoutController::create
 *   2. WP    POST /wp-json/lg-member-sync/v1/me/checkout-session
 *   3. the   /lgjoin/ page (membership-pages)
 *
 * Door 2 was not in the plan. It was found during this lane's re-verification
 * and is asserted here so it can never drift back to being the unwatched one.
 *
 * WHAT MUST HOLD, in order of what it costs to get wrong:
 *
 *   1. OFF IS TODAY'S SITE. Flag `lgms_double_pay_block` absent or off and
 *      NOTHING refuses: no route registered, no banner, no 409. This is the
 *      byte-identical-off rule, and it is the assertion that lets the work
 *      merge before Ian has looked at anything.
 *   2. ON, EACH DOOR CLOSES — and closes for the REASON it claims. The
 *      assertions read the refusal's own reason, never a class name and never
 *      a string that also lives in prose, because a red-first that stays green
 *      is usually an assertion matching a comment.
 *   3. THE CHECK KEYS ON STANDING, NEVER ON `payment_source`. That field is one
 *      slot, descriptive only, and the two rails fight over it (dossier law,
 *      docs/domains/MEMBERSHIP.md). Mutating it alone must not move any verdict.
 *   4. A GIFT IS NOT A DOUBLE PAYMENT. A Patreon member buying a gift for
 *      somebody else is not paying twice, and must sail through in every state.
 *   5. THE MEMBER IS TOLD THE WAY OUT. A refusal that does not name the switch
 *      path is a dead end, and it must be the SAME copy at every door — two
 *      doors disagreeing about how to leave Patreon is worse than one.
 *   6. THE PROBE IS ALIVE. An absence assertion is vacuous on a box where
 *      nothing serves, so §7 proves the surface answers before believing
 *      anything about what it said.
 *
 * ⚠️ THIS GATE MUST TEST THIS BRANCH. lg-stripe-billing's composer autoloader
 * maps LGSB\ into the SERVING CHECKOUT (main), so this file requires the
 * worktree's own sources first and then asserts, by reflection, that every unit
 * under test was loaded from this file's tree. Without that a lane "verifying"
 * on dev2 is really testing main — a failure this repo has paid for twice.
 *
 * ⚠️ NULL IS OFTEN THE VALUE UNDER TEST HERE — an unmapped tier, an absent
 * message — and `$a['k'] ?? 'sentinel'` returns the SENTINEL for a key that
 * exists and is null. Written the obvious way, two assertions in this file
 * failed against correct code. Both now ask array_key_exists() and compare
 * with ===. Do not "tidy" them back into a null-coalesce.
 *
 * WHAT §5 DOES AND DOES NOT PROVE, stated rather than implied: the Slim
 * CheckoutController is typed against the CONCRETE final CheckoutService, which
 * cannot be stubbed, so the controller is not instantiated here. Instead the
 * decision unit (LGSB\Core\DoublePayGuard) is exercised for real, the wiring is
 * asserted by REFLECTION on the constructor and by reading config/container.php,
 * and the call-ordering is asserted against comment-stripped source. Door 2 is
 * driven for real through its own test seam; door 3 over real HTTP in §7.
 */

declare(strict_types=1);

namespace LGMS {
    /** Stands in for the lg_membership PDO. Backed by SQLite below. */
    class Db {
        public static function pdo(): \PDO { return $GLOBALS['PDO']; }
    }
    class Log {
        public static function line( string $m, string $n = 'tick.log' ): void { $GLOBALS['LOG'][] = rtrim( $m, "\n" ); }
    }
    class StripePrice {
        public const CADENCES = [ 'month' => 'Monthly', 'year' => 'Yearly' ];
        public static function configuredCadences(): array { return [ 'month' ]; }
        public static function currentPriceId( string $c ): string { return 'price_test_month'; }
    }
    class StripeLifecycle {
        public static function flagOn(): bool { return (bool) ( $GLOBALS['OPTS']['lgms_stripe_lifecycle'] ?? false ); }
        public static function allowlist(): array { return [ 101 => true, 102 => true, 103 => true, 104 => true, 105 => true ]; }
    }
}

namespace {

$ROOT = dirname( __DIR__, 2 );

$pass = 0; $fail = 0;
function ok( string $m ): void   { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void  { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function section( string $t ): void { echo "\n$t\n"; }
function note( string $t ): void { echo "  ..   $t\n"; }
function cannot( string $why ): void { echo "CANNOT RUN: $why\n"; exit( 3 ); }
/**
 * The string a named function returns, reassembled from its literals.
 *
 * The member-facing copy is written as concatenated literals across several
 * source lines, so comparing it against raw source would only ever match the
 * first sentence — which is exactly the false green this gate exists to avoid.
 * This reads what the function actually says.
 */
function literalsIn( string $file, string $fn ): string {
    $t = token_get_all( (string) file_get_contents( $file ) );
    $out = ''; $depth = 0; $inFn = false; $seen = false;
    foreach ( $t as $i => $tok ) {
        if ( is_array( $tok ) && $tok[0] === T_FUNCTION ) {
            for ( $j = $i + 1; $j < $i + 4; $j++ ) {
                if ( isset( $t[ $j ] ) && is_array( $t[ $j ] ) && $t[ $j ][0] === T_STRING && $t[ $j ][1] === $fn ) { $inFn = true; }
            }
            continue;
        }
        if ( ! $inFn ) { continue; }
        if ( $tok === '{' ) { $depth++; $seen = true; continue; }
        if ( $tok === '}' ) { $depth--; if ( $seen && $depth === 0 ) { break; } continue; }
        if ( $depth > 0 && is_array( $tok ) && $tok[0] === T_CONSTANT_ENCAPSED_STRING ) {
            $out .= stripcslashes( substr( $tok[1], 1, -1 ) );
        }
    }
    return $out;
}

/** Source with comments and strings-in-comments removed, so prose can never satisfy an assertion. */
function bare( string $file ): string {
    $t = token_get_all( (string) file_get_contents( $file ) );
    $out = '';
    foreach ( $t as $tok ) {
        if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) { continue; }
        $out .= is_array( $tok ) ? $tok[1] : $tok;
    }
    return $out;
}

/* ─── the WordPress surface the units touch ───────────────────────────────── */

$GLOBALS['OPTS']     = [];
$GLOBALS['USERMETA'] = [];
$GLOBALS['USERS']    = [];
$GLOBALS['ROUTES']   = [];
$GLOBALS['LOG']      = [];
$GLOBALS['CURRENT']  = 0;

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTS'][ $n ] = $v; return true; }
function get_user_meta( $uid, $key, $single = false ) {
    $v = $GLOBALS['USERMETA'][ $uid ][ $key ] ?? '';
    return $single ? $v : ( $v === '' ? [] : [ $v ] );
}
function update_user_meta( $uid, $key, $v ) { $GLOBALS['USERMETA'][ $uid ][ $key ] = $v; return true; }
function get_user_by( $field, $value ) {
    foreach ( $GLOBALS['USERS'] as $id => $u ) {
        if ( $field === 'id' && (int) $value === $id )                                    { return (object) ( $u + [ 'ID' => $id, 'roles' => [] ] ); }
        if ( $field === 'email' && strcasecmp( (string) $value, (string) $u['email'] ) === 0 ) { return (object) ( $u + [ 'ID' => $id, 'roles' => [] ] ); }
    }
    return false;
}
function get_userdata( $id ) { return get_user_by( 'id', $id ); }
function get_current_user_id() { return (int) $GLOBALS['CURRENT']; }
function register_rest_route( $ns, $route, $args = [] ) { $GLOBALS['ROUTES'][] = $ns . $route; return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function home_url( $p = '' ) { return 'https://dev2.loothgroup.com' . $p; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function __( $s, $d = null ) { return $s; }
function add_action( $tag, $cb, $p = 10, $n = 1 ) {}
function do_action( $tag, ...$rest ) {}
function apply_filters( $tag, $value, ...$rest ) { return $value; }

class WP_Error {
    public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
class WP_REST_Response {
    public function __construct( public $data = null, public int $status = 200 ) {}
    public function get_data() { return $this->data; }
    public function get_status() { return $this->status; }
}
class WP_REST_Request implements ArrayAccess {
    public function __construct( private array $params = [], private array $headers = [] ) {}
    public function get_param( $k ) { return $this->params[ $k ] ?? null; }
    public function get_params() { return $this->params; }
    public function get_json_params() { return $this->params; }
    public function get_header( $k ) { return $this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] ?? ''; }
    public function offsetExists( mixed $o ): bool { return isset( $this->params[ $o ] ); }
    public function offsetGet( mixed $o ): mixed { return $this->params[ $o ] ?? null; }
    public function offsetSet( mixed $o, mixed $v ): void { $this->params[ $o ] = $v; }
    public function offsetUnset( mixed $o ): void { unset( $this->params[ $o ] ); }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/stub/' ); }

/* ─── §0 the units exist, and they are THIS branch's ──────────────────────── */

section( '[0] LIVENESS — the units under test exist, and belong to THIS worktree' );

if ( ! extension_loaded( 'pdo_sqlite' ) ) { cannot( 'pdo_sqlite missing' ); }

$POLLER  = $ROOT . '/lg-patreon-stripe-poller';
$BILLING = $ROOT . '/lg-stripe-billing';
$MP      = $ROOT . '/membership-pages';

$standingFile = $POLLER . '/src/Membership/PatreonStanding.php';
$standRest    = $POLLER . '/src/Wp/PatreonStandingRestController.php';
$wpCheckout   = $POLLER . '/src/Wp/CheckoutRestController.php';
$slimCheckout = $BILLING . '/src/Http/Controllers/CheckoutController.php';
$slimContract = $BILLING . '/src/Contracts/PatreonStandingProbe.php';
$slimGuard    = $BILLING . '/src/Core/DoublePayGuard.php';
$slimAdapter  = $BILLING . '/src/Adapters/HttpPatreonStandingProbe.php';
$slimWiring   = $BILLING . '/config/container.php';
$joinPage     = $MP . '/web/lgjoin.php';
$mpConfig     = $MP . '/config.php';

foreach ( [ $wpCheckout, $slimCheckout, $slimWiring, $joinPage, $mpConfig ] as $f ) {
    if ( ! is_readable( $f ) ) { cannot( 'missing pre-existing file ' . $f ); }
}
$built = [
    'the standing helper'      => $standingFile,
    'the standing REST route'  => $standRest,
    'the Slim probe contract'  => $slimContract,
    'the Slim decision unit'   => $slimGuard,
    'the Slim probe adapter'   => $slimAdapter,
];
$missing = [];
foreach ( $built as $what => $f ) {
    is_( is_readable( $f ), "$what is present (" . basename( $f ) . ')' );
    if ( ! is_readable( $f ) ) { $missing[] = basename( $f ); }
}
if ( $missing !== [] ) {
    echo "\n$pass passed, $fail failed\n";
    echo "RED — the double-pay block is NOT BUILT (missing: " . implode( ', ', $missing ) . ").\n";
    echo "      All three purchase doors are Patreon-blind, exactly as #150 reports:\n";
    echo "      a member paying on Patreon today can buy again here and be charged twice.\n";
    exit( 1 );
}

$GLOBALS['PDO'] = new PDO( 'sqlite::memory:', null, null, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
$GLOBALS['PDO']->exec(
    'CREATE TABLE lg_patreon_members (
        wp_user_id INTEGER PRIMARY KEY, patreon_user_id TEXT, email TEXT, full_name TEXT,
        patron_status TEXT, last_charge_status TEXT, last_charge_date TEXT, next_charge_date TEXT,
        will_pay_amount_cents INTEGER, currently_entitled_amount_cents INTEGER,
        pledge_cadence INTEGER, tier_label TEXT, synced_at TEXT )' );
/* Door 2 resolves a bridged Stripe customer on its way out, so those two tables
   have to exist or the leg dies before it reaches the assertion. Left EMPTY on
   purpose: the members in this rig are Patreon-side, and a stray bridge row
   would change which branch of the session builder runs. */
$GLOBALS['PDO']->exec( 'CREATE TABLE wp_user_bridge (wp_user_id INTEGER, customer_id INTEGER)' );
$GLOBALS['PDO']->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY, email TEXT, stripe_customer_id TEXT, deleted_at TEXT)' );

require_once $standingFile;
require_once $standRest;
is_( class_exists( 'LGMS\\Membership\\PatreonStanding' ), 'LGMS\\Membership\\PatreonStanding loads' );
$r = new ReflectionClass( 'LGMS\\Membership\\PatreonStanding' );
is_( realpath( (string) $r->getFileName() ) === realpath( $standingFile ),
     '...from THIS worktree, not the serving checkout' );

/* ─── §1 the helper: what "paying Patreon" means ──────────────────────────── */

section( '[1] THE HELPER — what "paying Patreon" means, and what it refuses to mean' );

$GLOBALS['USERS'] = [
    101 => [ 'email' => 'patron@example.com',   'login' => 'patron',   'user_email' => 'patron@example.com'   ],
    102 => [ 'email' => 'lapsed@example.com',   'login' => 'lapsed',   'user_email' => 'lapsed@example.com'   ],
    103 => [ 'email' => 'nobody@example.com',   'login' => 'nobody',   'user_email' => 'nobody@example.com'   ],
    104 => [ 'email' => 'unmapped@example.com', 'login' => 'unmapped', 'user_email' => 'unmapped@example.com' ],
    105 => [ 'email' => 'declined@example.com', 'login' => 'declined', 'user_email' => 'declined@example.com' ],
];
$GLOBALS['OPTS']['lgpo_tier_map']   = [ 'tier-lite' => 'looth2', 'tier-pro' => 'looth3', 'tier-comp' => 'looth4' ];
$GLOBALS['OPTS']['lgpo_patreon_link'] = 'https://www.patreon.com/loothgroup/membership';
$GLOBALS['USERMETA'] = [
    101 => [ 'lgpo_patreon_user_id' => 'p101', 'lgpo_patreon_tier_id' => 'tier-lite',    'payment_source' => 'patreon' ],
    102 => [ 'lgpo_patreon_user_id' => 'p102', 'lgpo_patreon_tier_id' => 'tier-lite',    'payment_source' => 'patreon' ],
    104 => [ 'lgpo_patreon_user_id' => 'p104', 'lgpo_patreon_tier_id' => 'tier-unknown', 'payment_source' => 'patreon' ],
    105 => [ 'lgpo_patreon_user_id' => 'p105', 'lgpo_patreon_tier_id' => 'tier-lite',    'payment_source' => 'patreon' ],
];
$seed = static function ( int $uid, ?string $status, ?int $cents, ?string $label = 'Looth LITE' ): void {
    $st = $GLOBALS['PDO']->prepare(
        'INSERT OR REPLACE INTO lg_patreon_members
         (wp_user_id, patreon_user_id, email, patron_status, currently_entitled_amount_cents,
          will_pay_amount_cents, tier_label, next_charge_date, synced_at) VALUES (?,?,?,?,?,?,?,?,?)' );
    $st->execute( [ $uid, 'p' . $uid, $GLOBALS['USERS'][ $uid ]['email'], $status, $cents, $cents,
                    $label, '2026-09-01 00:00:00', '2026-08-19 00:00:00' ] );
};
$seed( 101, 'active_patron', 500 );
$seed( 102, 'former_patron', 0 );
$seed( 104, 'active_patron', 1100, 'Some Unmapped Tier' );
$seed( 105, 'declined_patron', 500 );

use LGMS\Membership\PatreonStanding;

$s101 = PatreonStanding::forUser( 101 );
$s102 = PatreonStanding::forUser( 102 );
$s103 = PatreonStanding::forUser( 103 );
$s104 = PatreonStanding::forUser( 104 );
$s105 = PatreonStanding::forUser( 105 );

is_( ( $s101['active'] ?? null ) === true,     'an active patron on a mapped paid tier IS paying' );
is_( ( $s101['tier']   ?? null ) === 'looth2', '...and the tier comes from lgpo_tier_map, not from their WP role' );
is_( ( $s102['active'] ?? null ) === false,    'a former patron is NOT paying' );
is_( ( $s103['active'] ?? null ) === false,    'a member who never linked Patreon is NOT paying' );
is_( ( $s105['active'] ?? null ) === false,    'a declined patron is NOT paying — the charge did not land' );
is_( ( $s104['active'] ?? null ) === true,
     'an active patron on a tier MISSING from lgpo_tier_map is still paying (no role, real charge)' );
is_( array_key_exists( 'tier', $s104 ) && $s104['tier'] === null,
     '...and is reported with no tier rather than a guessed one' );
is_( ( $s101['patron_status'] ?? null ) === 'active_patron', 'the verdict carries the Patreon status it decided from' );
is_( is_string( $s101['reason'] ?? null ) && $s101['reason'] !== '', 'every verdict carries a reason' );
is_( ( $s103['reason'] ?? '' ) !== ( $s102['reason'] ?? '' ),
     '...and "never linked" and "lapsed" are DIFFERENT reasons, not one shrug' );

is_( ( PatreonStanding::forEmail( 'patron@example.com' )['active'] ?? null ) === true,
     'the same verdict is reachable by email (the Slim door has no user id)' );
is_( ( PatreonStanding::forEmail( 'PATRON@EXAMPLE.COM' )['active'] ?? null ) === true,
     '...case-insensitively, like every other address on the site' );
is_( ( PatreonStanding::forEmail( 'ghost@example.com' )['active'] ?? null ) === false,
     '...and an email with no WP account is not paying anybody' );

$GLOBALS['PDO']->exec( "UPDATE lg_patreon_members SET synced_at = '2024-01-01 00:00:00' WHERE wp_user_id = 101" );
is_( ( PatreonStanding::forUser( 101 )['active'] ?? null ) === true,
     'an ANCIENT synced_at does not unblock a paying patron (synced_at is last-CHANGED, not last-checked)' );

$GLOBALS['PDO']->exec( 'UPDATE lg_patreon_members SET patron_status = NULL WHERE wp_user_id = 101' );
is_( ( PatreonStanding::forUser( 101 )['active'] ?? null ) === false,
     'a row that says NOTHING is not a row that says PAYING (the NULL-shadow trap)' );
$seed( 101, 'active_patron', 500 );

/* ─── §2 standing, never payment_source ───────────────────────────────────── */

section( '[2] THE CHECK KEYS ON STANDING, NEVER ON payment_source' );

is_( ! str_contains( bare( $standingFile ), 'payment_source' ),
     'the helper never reads payment_source (dossier law: one slot, descriptive only)' );

update_user_meta( 101, 'payment_source', 'stripe' );
is_( PatreonStanding::forUser( 101 )['active'] === true,
     'flipping payment_source to stripe does NOT unblock a paying patron' );
update_user_meta( 103, 'payment_source', 'patreon' );
is_( PatreonStanding::forUser( 103 )['active'] === false,
     '...and stamping payment_source=patreon on a non-patron does NOT invent a payment' );
update_user_meta( 101, 'payment_source', 'patreon' );

/* ─── §3 off and absent are today's site ──────────────────────────────────── */

section( '[3] OFF AND ABSENT ARE TODAY\'S SITE' );

unset( $GLOBALS['OPTS'][ PatreonStanding::FLAG ] );
is_( PatreonStanding::flagOn() === false, 'the flag ABSENT reads OFF' );
$GLOBALS['OPTS'][ PatreonStanding::FLAG ] = '0';
is_( PatreonStanding::flagOn() === false, 'the flag "0" reads OFF' );
$GLOBALS['OPTS'][ PatreonStanding::FLAG ] = 'yes-please';
is_( PatreonStanding::flagOn() === false, 'a malformed flag value reads OFF, not ON (fail-safe)' );

unset( $GLOBALS['OPTS'][ PatreonStanding::FLAG ] );
$GLOBALS['ROUTES'] = [];
\LGMS\Wp\PatreonStandingRestController::maybeRegister();
is_( $GLOBALS['ROUTES'] === [], 'flag OFF: the standing route is not registered at all — no route, no trace' );

$GLOBALS['OPTS'][ PatreonStanding::FLAG ] = '1';
$GLOBALS['ROUTES'] = [];
\LGMS\Wp\PatreonStandingRestController::maybeRegister();
is_( count( $GLOBALS['ROUTES'] ) === 1 && str_contains( (string) $GLOBALS['ROUTES'][0], 'patreon-standing' ),
     'flag ON: the route appears — so the absence above was a real absence, not a dead call' );

/* ─── §3b the route the Slim app talks to ─────────────────────────────────── */

$msgExpected = PatreonStanding::refusalMessage( PatreonStanding::forUser( 101 ) );

section( '[3b] THE STANDING ROUTE — closed by default, and it answers about the MEMBER' );

$GLOBALS['OPTS'][ PatreonStanding::FLAG ] = '1';

/* An unconfigured secret must be CLOSED, not open. This is the direction that
   costs something to get wrong: the route reports whether a named email is a
   paying member, so an open one is a membership oracle for anyone who finds it. */
unset( $GLOBALS['OPTS']['lgms_shared_secret'] );
is_( \LGMS\Wp\PatreonStandingRestController::authSharedSecret( new WP_REST_Request( [], [ 'x_lgms_token' => '' ] ) ) === false,
     'no shared secret configured: the route is CLOSED, not open' );

$GLOBALS['OPTS']['lgms_shared_secret'] = 'the-real-secret';
is_( \LGMS\Wp\PatreonStandingRestController::authSharedSecret( new WP_REST_Request( [], [ 'x_lgms_token' => 'guessed' ] ) ) === false,
     'a wrong token is refused' );
is_( \LGMS\Wp\PatreonStandingRestController::authSharedSecret( new WP_REST_Request( [], [ 'x_lgms_token' => 'the-real-secret' ] ) ) === true,
     '...and the right one is accepted, so the refusal above was not blanket' );

$resp = \LGMS\Wp\PatreonStandingRestController::standing( new WP_REST_Request( [ 'email' => 'patron@example.com' ] ) );
$body = $resp instanceof WP_REST_Response ? (array) $resp->get_data() : [];
is_( ( $body['active'] ?? null ) === true, 'a paying patron comes back active' );
is_( is_string( $body['message'] ?? null ) && $body['message'] === $msgExpected,
     '...carrying the WORDS, so the Slim app renders none of its own copy' );
is_( ! empty( $body['manage_url'] ), '...and where to go to cancel' );

$clean = \LGMS\Wp\PatreonStandingRestController::standing( new WP_REST_Request( [ 'email' => 'nobody@example.com' ] ) );
$cbody = $clean instanceof WP_REST_Response ? (array) $clean->get_data() : [];
is_( ( $cbody['active'] ?? null ) === false, 'a non-patron comes back inactive' );
is_( array_key_exists( 'message', $cbody ) && $cbody['message'] === null
     && array_key_exists( 'manage_url', $cbody ) && $cbody['manage_url'] === null,
     '...with no message and no link — nothing for a caller to show them by mistake' );

$blank = \LGMS\Wp\PatreonStandingRestController::standing( new WP_REST_Request( [ 'email' => '' ] ) );
is_( $blank instanceof WP_REST_Response && $blank->get_status() === 400,
     'an empty email is a 400, not a cheerful "not paying"' );

unset( $GLOBALS['OPTS'][ PatreonStanding::FLAG ] );

/* ─── §4 the refusal says how to leave ────────────────────────────────────── */

section( '[4] THE REFUSAL NAMES THE WAY OUT — and every door says the same thing' );

$msg = $msgExpected;
is_( stripos( $msg, 'patreon' ) !== false, 'the refusal names Patreon' );
is_( stripos( $msg, 'twice' ) !== false,   '...says the member would be charged twice' );
is_( stripos( $msg, 'cancel' ) !== false,  '...names the switch path: cancel on Patreon first' );
is_( stripos( $msg, 'laps' ) !== false || stripos( $msg, 'already paid' ) !== false,
     '...and states the GAP — Patreon access runs to the end of the paid period' );

/* The join page cannot call the poller (it never boots WordPress), so the copy
   exists twice. Compared for EQUALITY, in both directions: a sentence added to
   one and not the other reddens here, which is the only thing keeping two doors
   from describing two different ways to leave Patreon. */
$joinCopy = literalsIn( $mpConfig, 'lg_membership_patreon_refusal_message' );
is_( $joinCopy !== '' && $joinCopy === $msg,
     'the join page carries the SAME copy, word for word' );
if ( $joinCopy !== $msg ) {
    note( 'poller: ' . $msg );
    note( 'join  : ' . ( $joinCopy === '' ? '(no copy found)' : $joinCopy ) );
}

/* ─── §5 door 1 — the Slim checkout API ───────────────────────────────────── */

section( '[5] DOOR 1 — the Slim checkout API refuses a paying patron' );

require_once $slimContract;
require_once $slimGuard;
require_once $slimCheckout;
$rg = new ReflectionClass( 'LGSB\\Core\\DoublePayGuard' );
is_( realpath( (string) $rg->getFileName() ) === realpath( $slimGuard ),
     'the decision unit under test is THIS worktree\'s, not the serve\'s' );

$probe = static function ( ?array $standing ) {
    return new class( $standing ) implements \LGSB\Contracts\PatreonStandingProbe {
        public int $calls = 0;
        public array $sawEmails = [];
        public function __construct( private ?array $standing ) {}
        public function activeFor( ?string $email ): ?array { $this->calls++; $this->sawEmails[] = $email; return $this->standing; }
    };
};
$activeStanding = [ 'active' => true, 'tier' => 'looth2', 'message' => $msg,
                    'manage_url' => 'https://www.patreon.com/loothgroup/membership' ];

$p = $probe( $activeStanding );
$refusal = ( new \LGSB\Core\DoublePayGuard( $p ) )->refusalFor( 'patron@example.com', false );
is_( is_array( $refusal ), 'a paying patron is refused' );
is_( is_array( $refusal ) && stripos( (string) ( $refusal['error'] ?? '' ), 'patreon' ) !== false,
     '...and the refusal REASON names Patreon, not just "no"' );
is_( is_array( $refusal ) && ( $refusal['patreon_active'] ?? null ) === true,
     '...with a machine-readable marker the join page can branch on' );

$p2 = $probe( [ 'active' => false ] );
is_( ( new \LGSB\Core\DoublePayGuard( $p2 ) )->refusalFor( 'lapsed@example.com', false ) === null,
     'a lapsed patron is let through — this blocks double PAYMENT, not membership' );

$p3 = $probe( null );
is_( ( new \LGSB\Core\DoublePayGuard( $p3 ) )->refusalFor( 'patron@example.com', false ) === null,
     'an UNKNOWN standing lets the buyer through (fail-open: a WP blip must not stop every sale)' );

$p4 = $probe( $activeStanding );
is_( ( new \LGSB\Core\DoublePayGuard( $p4 ) )->refusalFor( 'patron@example.com', true ) === null,
     'a GIFT purchase by a paying patron is allowed — buying for somebody else is not double-paying' );
is_( $p4->calls === 0, '...and the gift path does not even ask, so it cannot be blocked by a slow answer' );

$p5 = $probe( $activeStanding );
is_( ( new \LGSB\Core\DoublePayGuard( $p5 ) )->refusalFor( null, false ) === null && $p5->calls === 0,
     'a checkout with no email cannot be attributed to a member, so it is not refused' );

$ctor = ( new ReflectionClass( 'LGSB\\Http\\Controllers\\CheckoutController' ) )->getConstructor();
$types = [];
foreach ( $ctor ? $ctor->getParameters() : [] as $prm ) { $types[] = (string) $prm->getType(); }
is_( in_array( 'LGSB\\Core\\DoublePayGuard', $types, true ),
     'CheckoutController takes the guard as a constructor dependency (reflection, not grep)' );
is_( str_contains( bare( $slimWiring ), 'DoublePayGuard' ),
     '...and the container wires it, so the deployed app gets one' );

$bareCtl = bare( $slimCheckout );
$callAt  = strpos( $bareCtl, 'refusalFor' );
$sessAt  = strpos( $bareCtl, 'createSubscriptionSession' );
is_( $callAt !== false && $sessAt !== false && $callAt < $sessAt,
     'the guard is consulted BEFORE any session is created — no Stripe call on a refused buy' );

/* ─── §6 door 2 — the WP checkout-session route ───────────────────────────── */

section( '[6] DOOR 2 — /wp-json/lg-member-sync/v1/me/checkout-session (found by this lane)' );

require_once $wpCheckout;
\LGMS\Wp\CheckoutRestController::$clientFactory = static function () {
    return new class {
        public array $created = [];
        public function createCheckoutSession( array $p ) { $this->created[] = $p; return (object) [ 'id' => 'cs_test_1', 'client_secret' => 'cs_secret' ]; }
    };
};
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = 1;

$GLOBALS['CURRENT'] = 101;
unset( $GLOBALS['OPTS'][ PatreonStanding::FLAG ] );
$off = \LGMS\Wp\CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $off instanceof WP_REST_Response && $off->get_status() < 400,
     'flag OFF: a paying patron still gets a session here — today\'s behaviour, unchanged' );

$GLOBALS['OPTS'][ PatreonStanding::FLAG ] = '1';
$on = \LGMS\Wp\CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $on instanceof WP_REST_Response && $on->get_status() === 409,
     'flag ON: the same request is refused 409' );
$body = $on instanceof WP_REST_Response ? (array) $on->get_data() : [];
is_( stripos( (string) ( $body['error'] ?? '' ), 'patreon' ) !== false,
     '...for the stated reason, naming Patreon' );

$GLOBALS['CURRENT'] = 103;
$clean = \LGMS\Wp\CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $clean instanceof WP_REST_Response && $clean->get_status() < 400,
     'a member with no Patreon payment still buys with the flag ON — the block is not a wall' );

$GLOBALS['CURRENT'] = 102;
$lapsed = \LGMS\Wp\CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $lapsed instanceof WP_REST_Response && $lapsed->get_status() < 400,
     'and so does a LAPSED patron — that is who this feature is meant to welcome' );

/* ─── §7 door 3 — the join page ───────────────────────────────────────────── */

section( '[7] DOOR 3 — the /lgjoin/ page, server-side' );

$joinBare = bare( $joinPage );
is_( str_contains( $joinBare, 'lg_membership_patreon_standing' ),
     'the join page asks for the member\'s Patreon standing server-side' );
is_( str_contains( bare( $mpConfig ), PatreonStanding::FLAG ),
     '...gated on the SAME wp_option row as the other two doors, so they cannot drift' );
is_( ! str_contains( $joinBare, "payment_source" ),
     '...and never on payment_source' );

/* A blocked page must not still be selling. The banner replacing the picker is
   not enough on its own: the trial pitch, the "Secure checkout — we accept
   Visa/Mastercard/Apple Pay" bar and the sign-up form all sat AFTER the picker
   and rendered regardless, so the first working build told a member not to pay
   and then showed them how. Found by rendering the real page, not by reading it. */
$joinTight = (string) preg_replace( '/\s+/', '', $joinBare );   // the guard is written with spaces
foreach ( [
    'lg-join__trial-banner'    => 'the free-trial pitch',
    'lg-join__pay-methods-bar' => 'the accepted-payment-methods bar',
    'data-lg-signup-modal'     => 'the sign-up / checkout form',
] as $needle => $what ) {
    $at   = strpos( $joinTight, $needle );
    $last = $at === false ? false : strrpos( substr( $joinTight, 0, $at ), '$blockedByPatreon' );
    // The nearest preceding mention must be the NEGATED one. Searching for
    // '!$blockedByPatreon' directly would also be found by the plain form one
    // character later, so ask what sits in front of the last occurrence.
    $negated = $last !== false && $last > 0 && $joinTight[ $last - 1 ] === '!';
    is_( $negated, "$what is inside the not-blocked guard — a refused member is not sold to" );
}

$standAt = strpos( $joinBare, 'lg_membership_patreon_standing' );
$tiersAt = strpos( $joinBare, 'data-lg-join-tiers' );
is_( $standAt !== false && $tiersAt !== false && $standAt < $tiersAt,
     'the check runs BEFORE the tier picker renders — a banner instead of buy buttons, not beside them' );

$host = 'dev2.loothgroup.com';
$curl = static function ( string $path ) use ( $host ): array {
    $cmd = sprintf( 'curl -sk --resolve %s:443:127.0.0.1 -o /dev/stdout -w "\n%%{http_code}" %s 2>/dev/null',
        escapeshellarg( $host ), escapeshellarg( 'https://' . $host . $path ) );
    $out = (string) shell_exec( $cmd );
    $nl  = strrpos( $out, "\n" );
    return [ 'code' => (int) substr( $out, (int) $nl + 1 ), 'body' => substr( $out, 0, $nl === false ? 0 : $nl ) ];
};
$live = $curl( '/lgjoin/' );
if ( $live['code'] === 404 && str_contains( $live['body'], 'no such surface' ) ) {
    note( 'HTTP leg NOT RUN: every membership-pages surface on this box 404s at the nginx' );
    note( 'door (LG_MS_SLUG delivers a wrong value — see issue #150). Not this branch:' );
    note( 'the same router renders correctly when driven straight at its FPM socket.' );
    note( 'Reported rather than asserted, so a dead surface cannot pass as a green one.' );
} elseif ( $live['code'] === 0 ) {
    note( 'HTTP leg NOT RUN: no answer from ' . $host . ' on this box' );
} else {
    is_( $live['code'] < 500, sprintf( 'the join surface is ALIVE (%d) before anything is believed about it', $live['code'] ) );
}

/* ─── §8 the direction we cannot block ────────────────────────────────────── */

section( '[8] THE MEMBER IS TOLD — the direction no code of ours can stop' );

$msPage = $MP . '/web/manage-subscription.php';
$msCss  = $MP . '/web/manage-subscription.css';
if ( ! is_readable( $msPage ) || ! is_readable( $msCss ) ) {
    bad( 'manage-subscription page/stylesheet missing' );
} else {
    $msBare = bare( $msPage );
    is_( str_contains( $msBare, 'lg_membership_is_dual_payer' ),
         'Manage Account asks whether this member is paying on BOTH rails' );
    is_( str_contains( bare( $mpConfig ), 'lg_membership_dual_payer_message' ),
         '...and has copy to show them, rather than only telling the admin' );

    /* The block is one-directional by nature — a site-payer who then pledges on
       patreon.com cannot be refused, because nothing of ours runs at Patreon's
       door. So the ONLY remedy is telling them, and the wording has to do three
       things: name the fact, leave the choice with them, and offer a way out. */
    $dualMsg = literalsIn( $mpConfig, 'lg_membership_dual_payer_message' );
    is_( $dualMsg !== '' && stripos( $dualMsg, 'twice' ) !== false,
         'the notice says plainly that the account is paying twice' );
    is_( stripos( $dualMsg, 'cancel' ) !== false && stripos( $dualMsg, 'whichever' ) !== false,
         '...leaves the choice of WHICH to cancel with the member' );
    is_( stripos( $dualMsg, 'refund' ) !== false,
         '...and offers the overlap back, since we took it' );
    is_( stripos( $dualMsg, 'sorry' ) === false && stripos( $dualMsg, 'error' ) === false,
         '...without calling it their mistake or ours — it is neither' );
    is_( str_contains( $msBare, 'request-refund' ),
         'and the way out is a LINK, not an invitation to go find one' );

    /* A modifier class with no rule behind it renders like an untouched element
       while every "the class is present" assertion passes
       (trap-class-name-assertion-passes-on-the-defect). This one tells a member
       they are losing money, so it is the last place to allow that. */
    $css = (string) file_get_contents( $msCss );
    /* ⚠️ THE RULE MUST BE A LIGHT-MODE ONE. Written as a bare "does this class
       appear before a {", this assertion was satisfied by the DARK-theme rule
       alone: deleting the light rule left the member an unstyled pill and the
       gate green. Caught by mutation, not by review. So each selector block is
       read, and a block scoped to data-lguser-theme does not count. */
    $blocks = [];
    foreach ( explode( '}', $css ) as $chunk ) {
        $bracePos = strpos( $chunk, '{' );
        if ( $bracePos !== false ) { $blocks[] = substr( $chunk, 0, $bracePos ); }
    }
    foreach ( [ 'lg-manage-sub__card--dual', 'lg-manage-sub__status-pill--dual',
                'lg-manage-sub__cta--secondary', 'lg-manage-sub__dual-actions' ] as $cls ) {
        $lit = false;
        foreach ( $blocks as $sel ) {
            if ( str_contains( $sel, '.' . $cls ) && ! str_contains( $sel, 'data-lguser-theme' ) ) { $lit = true; break; }
        }
        is_( $lit, "the .$cls class has a light-mode rule behind it" );
    }
    is_( (bool) preg_match( '/data-lguser-theme="dark"\][^\n]*status-pill--dual/', $css ),
         '...and the pill has a dark-theme ink of its own, not left to invert' );

    is_( str_contains( bare( $mpConfig ), 'lg_membership_double_pay_block' ),
         'the notice is behind the SAME flag row as the three purchase doors' );

    /* The Stripe-side lookup falls back to matching on EMAIL when a member has
       no wp_user_bridge row. An empty email must not match — without the
       non-empty guard the join degenerates and every member with any live
       subscription in the table is told they are paying twice. Asserted here
       because this gate has no database; the branch itself was exercised
       against the real dev2 databases as the pool user (bridge match with a
       wrong email, email match with no bridge, and empty-email -> null). */
    $cfgBare = bare( $mpConfig );
    is_( str_contains( $cfgBare, "wp_user_bridge" ) && str_contains( $cfgBare, "<> ''" ),
         'the email fallback refuses an EMPTY address, so it cannot match everyone' );
    is_( strpos( $cfgBare, 'wp_user_bridge' ) < strpos( $cfgBare, "c.email = ?" ),
         '...and the bridge is tried FIRST — an email-only census under-reports' );
}


echo "\n$pass passed, $fail failed\n";
if ( $fail > 0 ) {
    echo "RED — the double-pay block is not holding.\n";
    exit( 1 );
}
echo "GREEN — off is today's site, standing decides (never payment_source), all three\n";
echo "        doors close on a paying patron, a gift is never a double payment, and the\n";
echo "        refusal tells the member how to switch rails.\n";

/* ─────────────────────────────────────────────────────────────────────────────
 * RED-FIRST — every assertion above was falsified by mutation before it was
 * trusted. Run order: apply the mutation, run this gate, confirm the NAMED
 * assertion reddens (and only it), revert.
 *
 *  1. Delete src/Membership/PatreonStanding.php
 *     → §0 RED, and the gate stops with the #150 defect spelled out. This is
 *       the red-first state: it is what the gate printed before any fix existed.
 *  2. PatreonStanding::forUser — drop the patron_status test
 *     → §1 "a former patron is NOT paying" + "a declined patron" RED.
 *  3. …drop the currently_entitled_amount_cents fallback
 *     → §1 "a tier MISSING from lgpo_tier_map is still paying" RED alone.
 *  4. …read payment_source instead of lgpo_patreon_user_id
 *     → §2 all three RED, including the source scan.
 *  5. flagOn(): return (bool) get_option(FLAG) with no '1' test
 *     → §3 "a malformed flag value reads OFF" RED (a non-empty string is truthy).
 *  6. maybeRegister(): register unconditionally
 *     → §3 "flag OFF: the route is not registered" RED. The ON leg beside it is
 *       what proves that absence was real and not a dead code path.
 *  7. refusalMessage(): drop the "cancel on Patreon first" sentence
 *     → §4 the switch-path assertion RED **and** the join-page copy assertion
 *       RED, which is the point: the two copies are compared, not each checked
 *       against a lenient keyword.
 *  8. DoublePayGuard::refusalFor — return the refusal for gifts too
 *     → §5 the gift assertion RED (and the "does not even ask" one).
 *  9. …treat a null standing as active (fail CLOSED)
 *     → §5 "an UNKNOWN standing lets the buyer through" RED.
 * 10. CheckoutController — move the guard call below createSubscriptionSession
 *     → §5 ordering RED. Removing the constructor param instead reddens the
 *       reflection assertion, which no amount of comment text can satisfy.
 * 11. CheckoutRestController — guard on StripeLifecycle::flagOn() instead
 *     → §6 "flag OFF … unchanged" RED (it refuses when it should not).
 * 12. lgjoin.php — move the standing check below the tier markup
 *     → §7 ordering RED.
 * 13. authSharedSecret(): return true when the stored secret is empty
 *     → §3b "no shared secret configured: the route is CLOSED" RED. This is
 *       the expensive direction — the route answers whether a named email is
 *       a paying member, so an open one is a membership oracle.
 * 14. standing(): return the message even when inactive
 *     → §3b "with no message and no link" RED.
 *
 * 15. lgjoin.php — ungate the accepted-payment-methods bar
 *     → §7 "a refused member is not sold to" RED. 16 does the same for the
 *       free-trial pitch. Both were REAL: the first working build refused the
 *       member and then showed them a payment-methods bar and a pre-filled
 *       sign-up form, found by rendering the page rather than reading it.
 * 17. manage-subscription.php — pin $is_dual_payer to false
 *     → §8 "Manage Account asks whether this member is paying on BOTH rails" RED.
 * 18. dual_payer_message(): open with "Sorry, an error occurred"
 *     → §8 "without calling it their mistake or ours" RED. Being charged twice
 *       is money to reclaim, not a fault to apologise for.
 * 19. …drop the refund sentence
 *     → §8 "offers the overlap back, since we took it" RED.
 * 20. manage-subscription.css — delete the LIGHT .status-pill--dual rule
 *     → §8 "has a light-mode rule behind it" RED. ⚠️ THIS ONE INITIALLY STAYED
 *       GREEN and is why the assertion is written the way it is: a bare
 *       "class appears before a {" was satisfied by the DARK rule alone, so
 *       the member got an unstyled pill and the gate passed. The check now
 *       reads selector blocks and ignores data-lguser-theme ones. Caught by
 *       mutation, not by review — which is the entire argument for doing this.
 * 21. …delete the dark-theme ink instead
 *     → §8 "a dark-theme ink of its own" RED.
 * 22. …point the refund link at "#"
 *     → §8 "the way out is a LINK" RED.
 *
 * A NO-OP MUTATION FAILS LOUD, checked deliberately: renaming a local variable
 * inside forUser() reddens nothing, and reformatting refusalMessage()'s
 * whitespace reddens nothing — so §4's sentence comparison is matching the copy
 * and not the file's bytes.
 * ───────────────────────────────────────────────────────────────────────── */

}
