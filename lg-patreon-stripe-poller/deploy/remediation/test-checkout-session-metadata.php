<?php
/**
 * RED-FIRST GATE for the checkout-session creation side (design doc
 * STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md §3.2 — the step that makes
 * IdentityMatcher branch 2 the normal case).
 *
 *   php test-checkout-session-metadata.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * The defect being priced out: a checkout that completes with no resolvable
 * WP identity is the exact case that used to mint a duplicate account.
 * Identity must be asserted BY THE MEMBER'S OWN SESSION at creation time —
 * stamped into the Checkout Session metadata server-side — never guessed
 * later from a string they typed into Stripe.
 *
 * What must hold:
 *
 *   1. OFF (absent AND explicit false) registers no route — total no-op.
 *   2. The endpoint is logged-in-member-only, and the wp_user_id it stamps
 *      comes from the SESSION, never from the request body.
 *   3. metadata.wp_user_id + subscription_data.metadata.wp_user_id are both
 *      stamped (the subscription copy survives onto the subscription object).
 *   4. lgpo_patreon_user_id is stamped when the member has one, absent when
 *      not — never an empty string that would read as a real claim.
 *   5. SINGLE PRICE: the session sells exactly the configured looth3 price.
 *      A client-supplied price id is IGNORED — there is no second Stripe
 *      tier, so there is nothing for a client to choose.
 *   6. A bridged member checks out as their existing Stripe customer;
 *      an unbridged member gets customer_email from their WP account.
 *   7. No configured price -> 503 and NO Stripe call.
 */

declare(strict_types=1);

namespace LGMS {
    class Db {
        public static int $calls = 0;
        public static function pdo() { self::$calls++; return $GLOBALS['PDO']; }
    }
    class Log {
        public static function line( string $m, string $n = 'tick.log' ): void { $GLOBALS['LOG'][] = rtrim( $m, "\n" ); }
    }
}

namespace {

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "CANNOT RUN: pdo_sqlite missing\n" ); exit( 3 ); }

$BASE = __DIR__ . '/../..';
foreach ( [ '/src/StripeLifecycle.php', '/src/Wp/CheckoutRestController.php',
            '/src/StripePrice.php', '/src/Membership/MultiTier.php',
            '/src/Membership/PatreonStanding.php' ] as $f ) {
    if ( ! is_readable( $BASE . $f ) ) { fwrite( STDERR, "CANNOT RUN (RED: build absent): missing {$f}\n" ); exit( 3 ); }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/stub/' ); }

$GLOBALS['OPTS'] = []; $GLOBALS['LOG'] = []; $GLOBALS['ROUTES'] = [];
$GLOBALS['OPTS'][ 'lgms_checkout_audience' ] = 'off';   // #181: this file's subject is not the audience
$GLOBALS['CUR_UID'] = 0; $GLOBALS['USERMETA'] = []; $GLOBALS['CREATED'] = [];

function get_option( $n, $d = false ) {
    return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d;
}
function register_rest_route( $ns, $route, $args = [] ) { $GLOBALS['ROUTES'][ "{$ns}{$route}" ] = $args; return true; }
function get_current_user_id() { return $GLOBALS['CUR_UID']; }
function is_user_logged_in() { return $GLOBALS['CUR_UID'] > 0; }
function get_user_by( $field, $id ) {
    if ( (int) $id === 502 ) { return (object) [ 'ID' => 502, 'user_email' => 'member@x.test', 'display_name' => 'Member Two' ]; }
    return false;
}
function get_user_meta( $id, $key, $single = false ) {
    return $GLOBALS['USERMETA'][ (int) $id ][ $key ] ?? '';
}
function home_url( $p = '' ) { return 'https://dev.test' . $p; }
function wp_verify_nonce( $n, $a ) { return 1; }

// Minimal WP REST shims
class WP_REST_Request {
    private array $params; private array $headers;
    public function __construct( array $params = [], array $headers = [] ) { $this->params = $params; $this->headers = $headers; }
    public function get_param( $k ) { return $this->params[ $k ] ?? null; }
    public function get_json_params() { return $this->params; }
    public function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? ''; }
    public function get_body() { return ''; }
}
class WP_REST_Response {
    public function __construct( public $data = null, public int $status = 200 ) {}
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
}

require_once $BASE . '/src/StripeLifecycle.php';
// BOTH OF THESE WERE MISSING, AND THIS FILE HAS BEEN DEAD SINCE (found on
// main, #148). Twice now a feature gave the door a new dependency and did not
// update this require list: StripePrice when cadences arrived, then
// PatreonStanding with the double-pay block (#150). Each time the run fataled —
// "Class ... not found", exit 255, NO FAIL line printed — so the sections after
// the fatal never executed and the silence read like nothing to report. The
// file lives in deploy/remediation rather than tools/gates, so run-all.sh never
// called it and nothing reported the silence either.
require_once $BASE . '/src/StripePrice.php';
require_once $BASE . '/src/Membership/PatreonStanding.php';
// The door consults the multi-tier flag (#148). This test asserts the
// SINGLE-tier behaviour, which is the flag's OFF state and stays the default —
// so nothing below arms it, and every assertion here is unchanged.
require_once $BASE . '/src/Membership/MultiTier.php';
// THE THIRD TIME (#181). The door now asks CheckoutAudience who may buy, and
// that option DEFAULTS TO `allowlist` — enforcing — so this file needs the
// class AND needs the audience pinned OFF: its subject is what the session
// metadata carries, not who is invited. Gate 86 owns the audience, including
// the 403 at this door.
require_once $BASE . '/src/Membership/CheckoutAudience.php';
require_once $BASE . '/src/Wp/CheckoutRestController.php';

use LGMS\StripeLifecycle;
use LGMS\Wp\CheckoutRestController;

function scenario(): void {
    $pdo = new PDO( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, stripe_customer_id TEXT, email TEXT UNIQUE, name TEXT, country TEXT, metadata TEXT, deleted_at TEXT)' );
    $pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE, synced_at TEXT)' );
    $GLOBALS['PDO'] = $pdo;
    $GLOBALS['OPTS'] = []; $GLOBALS['LOG'] = []; $GLOBALS['ROUTES'] = [];
$GLOBALS['OPTS'][ 'lgms_checkout_audience' ] = 'off';   // #181: this file's subject is not the audience
    $GLOBALS['CUR_UID'] = 0; $GLOBALS['USERMETA'] = []; $GLOBALS['CREATED'] = [];
    \LGMS\Db::$calls = 0;
    StripeLifecycle::$confirmFactory = null;
    StripeLifecycle::_resetForTests();
    // The session-creation client seam: capture params, return a fake session.
    CheckoutRestController::$clientFactory = static function (): object {
        return new class {
            public function createCheckoutSession( array $params ): object {
                $GLOBALS['CREATED'][] = $params;
                return (object) [ 'id' => 'cs_test_gate', 'client_secret' => 'cs_secret_gate' ];
            }
        };
    };
}

function call( array $body = [] ): WP_REST_Response {
    return CheckoutRestController::createSession( new WP_REST_Request( $body ) );
}

$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $d ? "  ({$d})" : '' ); }
};

echo "=== checkout-session metadata — red-first gate ===\n";

// ------------------------------------------------ 1. OFF registers nothing
echo "\n[1] flag ABSENT and explicit false — no route, no touch\n";
scenario();
CheckoutRestController::maybeRegister();
$note( $GLOBALS['ROUTES'] === [], 'absent: no checkout route registered' );
$note( \LGMS\Db::$calls === 0 && $GLOBALS['LOG'] === [], 'absent: no DB read, no log line' );
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = false;
CheckoutRestController::maybeRegister();
$note( $GLOBALS['ROUTES'] === [], 'explicit OFF: identical' );

$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
CheckoutRestController::maybeRegister();
$note( isset( $GLOBALS['ROUTES']['lg-member-sync/v1/me/checkout-session'] ), 'ON: the route exists',
       implode( ',', array_keys( $GLOBALS['ROUTES'] ) ) );
$perm = $GLOBALS['ROUTES']['lg-member-sync/v1/me/checkout-session']['permission_callback'] ?? null;
$note( is_array( $perm ) && ( $perm[1] ?? '' ) === 'authLoggedInUser',
       'and it is member-auth (logged-in + nonce), not public' );

// ------------------------------------------------ 2. no price configured -> 503, no Stripe call
echo "\n[2] unconfigured price refuses BEFORE any Stripe call\n";
scenario();
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$GLOBALS['CUR_UID'] = 502;
$res = call();
$note( $res->get_status() === 503, 'no lgms_stripe_price_id -> 503', (string) $res->get_status() );
$note( $GLOBALS['CREATED'] === [], 'and Stripe was never called' );

// ------------------------------------------------ 3. the stamp
echo "\n[3] identity is stamped from the SESSION, into session AND subscription metadata\n";
scenario();
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$GLOBALS['OPTS']['lgms_stripe_price_id'] = 'price_pro_monthly';
$GLOBALS['CUR_UID'] = 502;
$res = call();
$note( $res->get_status() === 200, 'creation succeeds', (string) $res->get_status() );
$p = $GLOBALS['CREATED'][0] ?? [];
$note( ( $p['metadata']['wp_user_id'] ?? null ) === '502', 'session metadata.wp_user_id = the logged-in member' );
$note( ( $p['subscription_data']['metadata']['wp_user_id'] ?? null ) === '502',
       'subscription_data.metadata.wp_user_id mirrors it (survives onto the subscription object)' );
$note( ! array_key_exists( 'patreon_user_id', $p['metadata'] ?? [] ),
       'no patreon id on this member -> the key is ABSENT, not empty' );
$note( ( $p['mode'] ?? '' ) === 'subscription', 'mode=subscription' );
$note( ( $p['line_items'][0]['price'] ?? '' ) === 'price_pro_monthly', 'sells the configured price' );
$note( ( $p['customer_email'] ?? '' ) === 'member@x.test', 'unbridged member: customer_email from the WP account' );
$d = (array) $res->get_data();
$note( ( $d['client_secret'] ?? '' ) === 'cs_secret_gate' && ( $d['session_id'] ?? '' ) === 'cs_test_gate',
       'returns client_secret + session_id' );

// with a patreon linkage
scenario();
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$GLOBALS['OPTS']['lgms_stripe_price_id'] = 'price_pro_monthly';
$GLOBALS['CUR_UID'] = 502;
$GLOBALS['USERMETA'][502]['lgpo_patreon_user_id'] = '777001';
call();
$p = $GLOBALS['CREATED'][0] ?? [];
$note( ( $p['metadata']['patreon_user_id'] ?? null ) === '777001'
       && ( $p['subscription_data']['metadata']['patreon_user_id'] ?? null ) === '777001',
       'a linked member also stamps patreon_user_id (chain 3 backstop)' );

// ------------------------------------------------ 4. the body cannot choose
echo "\n[4] single tier means single price — the request body chooses NOTHING\n";
scenario();
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$GLOBALS['OPTS']['lgms_stripe_price_id'] = 'price_pro_monthly';
$GLOBALS['CUR_UID'] = 502;
call( [ 'price_id' => 'price_evil_cheap', 'wp_user_id' => 9999, 'metadata' => [ 'wp_user_id' => '9999' ] ] );
$p = $GLOBALS['CREATED'][0] ?? [];
$note( ( $p['line_items'][0]['price'] ?? '' ) === 'price_pro_monthly',
       'client-supplied price_id is ignored — the option decides', (string) ( $p['line_items'][0]['price'] ?? '?' ) );
$note( ( $p['metadata']['wp_user_id'] ?? null ) === '502',
       'client-supplied wp_user_id is ignored — the session decides' );

// ------------------------------------------------ 5. bridged member reuses their customer
echo "\n[5] a bridged member checks out as their existing Stripe customer\n";
scenario();
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$GLOBALS['OPTS']['lgms_stripe_price_id'] = 'price_pro_monthly';
$GLOBALS['CUR_UID'] = 502;
$GLOBALS['PDO']->exec( "INSERT INTO customers (id, uuid, stripe_customer_id, email) VALUES (7, 'u', 'cus_LIVE7', 'member@x.test')" );
$GLOBALS['PDO']->exec( 'INSERT INTO wp_user_bridge (customer_id, wp_user_id) VALUES (7, 502)' );
call();
$p = $GLOBALS['CREATED'][0] ?? [];
$note( ( $p['customer'] ?? '' ) === 'cus_LIVE7', 'customer param = the bridged Stripe customer id' );
$note( ! isset( $p['customer_email'] ), 'and customer_email is not also sent (Stripe rejects both)' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — OFF is silence, identity is stamped by the session, the body chooses nothing, one price, one tier.\n";
exit( 0 );

}
