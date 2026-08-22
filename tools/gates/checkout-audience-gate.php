<?php
/**
 * GATE 86 — the soft-launch cohort is REAL IN THE CHECKOUT PATH, not a page rule.
 *
 *   php tools/gates/checkout-audience-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * THE DEFECT THIS EXISTS FOR (#181; Ian 2026-08-21, decision box: "Fix before
 * go-live"). Lane 180 shipped the anonymous tester's unlock link on the honest
 * argument that viewing is not signing up, and then measured what actually
 * stopped a stranger from signing up. The answer was: nothing that anybody had
 * written. Three unrelated accidents were doing the refusing —
 *
 *   1. page gating, which #180 deliberately opens;
 *   2. BuddyBoss's global `bb-enable-private-rest-apis`, which 401s the
 *      logged-out sign-in call lgjoin's JS insists on — a setting re-armed by
 *      every DB reload, not a membership control;
 *   3. LIVE ONLY, an EMPTY Stripe catalogue, so every checkout call refused
 *      "not mapped to a membership tier" — AND THAT PROP IS REMOVED ON PURPOSE
 *      AT GO-LIVE.
 *
 * Underneath them: `POST /billing/v1/checkout` carries no auth at all,
 * `/billing/v1/products` publishes the real price ids to anyone, and a real
 * price id minted a Stripe session with no account and no list. Paying it ran
 * `Sync::customer` -> `UserProvisioner::findOrProvision`, which CREATES A
 * WORDPRESS USER BY EMAIL and grants the tier.
 *
 * WHAT MUST HOLD. These are the four things keeper required the build to prove,
 * in the order they were required, plus the structure that keeps them true:
 *
 *   1. THE EXACT REQUEST REFUSES. Anon, no session, a REAL price id from the
 *      public catalogue: 403, not 200. (§B, and §H over real HTTP.)
 *   2. A COHORT MEMBER STILL COMPLETES CHECKOUT. A fence that also stops the
 *      buyer is not a fix, it is an outage with better paperwork. (§B4, §C2.)
 *   3. A SESSION MINTED BEFORE THE LIST CHANGED CANNOT PROVISION — no user, no
 *      bridge, no grant. This is the half that cannot be routed around. (§C1.)
 *   4. AN ALREADY-BRIDGED MEMBER'S SWEEP STILL WORKS, GRANTS AND RETRACTIONS.
 *      (§C3, driven through the real `Sync::customer`.)
 *
 * ⚠️ THE ASSERTION THAT LOOKS RIGHT AND MEASURES NOTHING. "A cohort member can
 * buy" passes on the defect — before this lane, EVERYBODY could buy, so that
 * assertion was true of the broken code and stays true of the fixed code. It is
 * kept (it is proof 2, and a fence that blocks the cohort is a real failure)
 * but it is not evidence that anything was fixed. The assertions that bite are
 * the refusals: §B2, §B3, §C1. This is the same family as #148's "a PRO
 * purchase grants looth3", which passed on a constant.
 *
 * ⚠️ THE SECOND VACUOUS GREEN, and it is subtler. `off` and `on` both answer
 * "yes" to every caller, so ANY assertion phrased as "this person may buy"
 * passes in two of the three states without distinguishing them. Every
 * permission assertion here therefore states the STATE it was measured in, and
 * §A proves the states are actually different by asserting the refusals only
 * `allowlist` produces.
 *
 * HOW THE COLLABORATORS ARE HANDLED. `CheckoutAudience`, `UserProvisioner`,
 * `Sync`, `CheckoutRestController` and `CheckoutAudienceGuard` are the REAL
 * classes — the decisions under test are never stubbed. Everything they lean on
 * (the PDO, Stripe, the Arbiter, the entitlement repo) is an observable
 * stand-in, so what the gate measures is the decision and not the plumbing.
 * `StripeLifecycle` is stubbed ONLY to supply a fixed cohort; its
 * `inCohort()`/`allowlist()` normalization is the real class's job and gate 34d
 * owns it.
 *
 * Red-first record with measured counts at the foot of this file.
 */

declare(strict_types=1);

/* ─── observable stand-ins, declared before the real classes are loaded ───── */

namespace LGMS {

    /** Records every statement so §C can assert that NOTHING was written. */
    class FakeStatement {
        public function __construct(private string $sql, private array $rows) {}
        public array $bound = [];
        public function execute( array $p = [] ): bool { $this->bound = $p; $GLOBALS['SQL'][] = [ $this->sql, $p ]; return true; }
        public function fetchColumn( int $i = 0 ) {
            foreach ( $GLOBALS['BRIDGE'] as $cid => $uid ) {
                if ( stripos( $this->sql, 'wp_user_bridge' ) !== false
                     && stripos( $this->sql, 'SELECT' ) !== false
                     && (int) ( $this->bound[0] ?? 0 ) === (int) $cid ) { return $uid; }
            }
            return false;
        }
        public function fetchAll( $m = null ): array { return $this->rows; }
        public function fetch( $m = null ) { return $this->rows[0] ?? false; }
    }
    class FakePdo {
        public function prepare( string $sql ) { return new FakeStatement( $sql, [] ); }
        public function query( string $sql ) { $GLOBALS['SQL'][] = [ $sql, [] ]; return new FakeStatement( $sql, [] ); }
    }
    class Db { public static function pdo() { return $GLOBALS['PDO']; } }

    class Log {
        public static function line( string $m, string $n = 'tick.log' ): void { $GLOBALS['LOG'][] = rtrim( $m, "\n" ); }
    }

    /**
     * The cohort, fixed. Deliberately NOT the real class: what is under test is
     * whether the checkout path ASKS, not whether the option normalizer works
     * (gate 34d owns that). 101-103 are in; 900 is a real member who is not.
     */
    class StripeLifecycle {
        public const ALLOWLIST_OPT = 'lgms_stripe_lifecycle_allowlist';
        public const TIER          = 'looth3';
        public static function flagOn(): bool { return (bool) ( $GLOBALS['OPTS']['lgms_stripe_lifecycle'] ?? false ); }
        public static function allowlist(): array { return $GLOBALS['COHORT']; }
        public static function allowlistEmails(): array { return $GLOBALS['COHORT_EMAILS']; }

        /* THE ADDRESS HALF, stubbed to the SAME contract the real class
           publishes (#193): trimmed, lower-cased, and '' widens nothing. The
           real normalizer — and the read-side union inside inCohort() — belong
           to gate 34's test-soft-launch-allowlist.php, which drives the actual
           class; what THIS gate measures is whether the checkout path asks. */
        public static function inCohortEmail( ?string $e ): bool {
            $e = strtolower( trim( (string) $e ) );
            $GLOBALS['EMAIL_ASKS'][] = $e;
            return $e !== '' && isset( $GLOBALS['COHORT_EMAILS'][ $e ] );
        }

        /* Read-side union, same shape as the real one, so a listed ADDRESS that
           has grown an account is recognised by every id-keyed fence too. */
        public static function inCohort( int $id ): bool {
            if ( isset( $GLOBALS['COHORT'][ $id ] ) ) { return true; }
            if ( $id <= 0 || $GLOBALS['COHORT_EMAILS'] === [] ) { return false; }
            $u = $GLOBALS['USERS'][ $id ] ?? null;
            return $u && self::inCohortEmail( (string) $u->user_email );
        }
    }

    class StripePrice {
        public const CADENCES = [ 'month' => 'Monthly', 'year' => 'Yearly' ];
        public static function tiers(): array { return [ 'looth2', 'looth3' ]; }
        public static function configuredCadences( ?string $t = null ): array { return [ 'month' ]; }
        public static function currentPriceId( string $c, ?string $t = null ): string { return 'price_real_' . $c; }
    }

    /**
     * Observable, but it also FEEDS THE REAL ARBITER: `report()` records the
     * opinion for assertions and `readAllForUser()` hands it straight back, so
     * the Arbiter arbitrates over opinions this gate actually caused.
     */
    class RoleSourceWriter {
        public static function report( int $uid, string $src, ?string $tier ): void {
            $GLOBALS['REPORTED'][] = [ $uid, $src, $tier ];
            $GLOBALS['SOURCES'][ $uid ][ $src ] = $tier;
        }
        /**
         * ⚠️ SHAPE MIRRORS THE REAL METHOD: a map of source => tier, NOT a list
         * of rows. The first cut of this stub returned rows and the Arbiter
         * quietly computed `looth1` for every user, because computeWinningTier
         * iterates VALUES and an array value is not a tier name. It failed one
         * assertion and passed the neighbouring one by coincidence — which is
         * the whole argument for mirroring a real signature rather than
         * inventing a convenient one.
         */
        public static function readAllForUser( int $uid ): array {
            return $GLOBALS['SOURCES'][ $uid ] ?? [];
        }
    }

    // ⚠️ LGMS\Arbiter IS NOT STUBBED. It is the only writer of wp_capabilities
    // on this rail, so it is the only thing that could strip a comp member's
    // looth4 — and Ian ruled 2026-08-21 that "looth4 is the everything bypass
    // the stripe side of membership needs to respect". A stub would have made
    // §I assert the stub's manners. The real class is loaded below.
}

namespace LGMS\Repos {
    class CustomerRepo {
        public static function findById( int $id ) { return $GLOBALS['CUSTOMERS'][ $id ] ?? null; }
    }
    class EntitlementRepo {
        public static function activeTier( int $id ): ?string { return $GLOBALS['TIERS'][ $id ] ?? null; }
    }
}

namespace LGMS\Membership {
    /** #150's guard, stubbed OFF — a different flag, and not what this measures. */
    class PatreonStanding {
        public const FLAG = 'lgms_double_pay_block';
        public static function flagOn(): bool { return false; }
        public static function forUser( int $u ): array { return [ 'active' => false, 'reason' => 'stub' ]; }
        public static function refusalMessage( array $s ): string { return 'stub'; }
        public static function manageUrl(): string { return 'https://patreon.com/stub'; }
    }
    class MultiTier { public static function flagOn(): bool { return false; } }
}

namespace LGMS\Wp {
    /** The real Arbiter asks this for the provenance string on a tier change. */
    class InternalRestController {
        public static function deriveProvenance( ?string $winning, array $sources ): string { return 'stub'; }
    }
    /** The identity gate is a different flag (#audit R1) and stays out of the way. */
    class IdentityMatcher {
        public static function match( int $cid, string $email ) { return null; }
        public static function describeConflict( int $cid, string $email ): string { return 'stub'; }
    }
}

namespace LGMS\Stripe {
    class Client {
        public function createCheckoutSession( array $p ) {
            $GLOBALS['STRIPE_CALLS'][] = $p;
            return (object) [ 'id' => 'cs_test_stub', 'client_secret' => 'cs_secret_stub' ];
        }
    }
}

/* ─── the harness ─────────────────────────────────────────────────────────── */

namespace {

$ROOT = dirname( __DIR__, 2 );

$pass = 0; $fail = 0;
function ok( string $m ): void   { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void  { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function section( string $t ): void { echo "\n$t\n"; }
function note( string $t ): void { echo "  ..   $t\n"; }
function cannot( string $why ): void { echo "CANNOT RUN: $why\n"; exit( 3 ); }

/** Source with comments stripped, so prose can never satisfy an assertion. */
/**
 * THE BODY OF ONE FUNCTION, BRACE-MATCHED — never a fixed-width window.
 *
 * #190 paid for this: a window read past one handler into its NEIGHBOUR, so
 * deleting a guard stayed green on the guard next door. A `[^}]*` regex has the
 * same defect one step earlier — it stops at the first `}` it meets, which in
 * any function with an `if` is the `if`'s, not the function's.
 */
function fn_body( string $src, string $name ): string {
    $at = strpos( $src, 'function ' . $name );
    if ( $at === false ) { return ''; }
    $open = strpos( $src, '{', $at );
    if ( $open === false ) { return ''; }
    $depth = 0;
    for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
        if ( $src[ $i ] === '{' ) { $depth++; }
        elseif ( $src[ $i ] === '}' ) {
            $depth--;
            if ( $depth === 0 ) { return substr( $src, $open + 1, $i - $open - 1 ); }
        }
    }
    return '';
}

function bare( string $file ): string {
    if ( ! is_readable( $file ) ) { cannot( "unreadable: $file" ); }
    $t = token_get_all( (string) file_get_contents( $file ) );
    $out = '';
    foreach ( $t as $tok ) {
        if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) { continue; }
        $out .= is_array( $tok ) ? $tok[1] : $tok;
    }
    return $out;
}

/* ─── the WordPress surface ───────────────────────────────────────────────── */

const DAY_IN_SECONDS = 86400;

$GLOBALS['OPTS']      = [];
$GLOBALS['USERS']     = [];
$GLOBALS['COHORT']    = [ 101 => true, 102 => true, 103 => true ];
$GLOBALS['COHORT_EMAILS'] = [];   // #193 — module scope too, or the stub reads an undefined index
$GLOBALS['EMAIL_ASKS']    = [];
$GLOBALS['BRIDGE']    = [];
$GLOBALS['SQL']       = [];
$GLOBALS['LOG']       = [];
$GLOBALS['MINTED']    = [];
$GLOBALS['TRANSIENT'] = [];
$GLOBALS['NOTIFIED']  = [];
$GLOBALS['REPORTED']  = [];
$GLOBALS['ARBITER']   = [];
$GLOBALS['SOURCES']   = [];
$GLOBALS['ROLE_OPS']  = [];
$GLOBALS['USERMETA']  = [];
$GLOBALS['CUSTOMERS'] = [];
$GLOBALS['TIERS']     = [];
$GLOBALS['STRIPE_CALLS'] = [];
$GLOBALS['CURRENT']   = 0;
$GLOBALS['PDO']       = new LGMS\FakePdo();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTS'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['OPTS'][ $n ] ); return true; }
/** A WP user the REAL Arbiter can add to and remove roles from. */
class FakeUser {
    public function __construct( public int $ID, public string $user_email, public array $roles ) {}
    public function add_role( string $r ): void {
        if ( ! in_array( $r, $this->roles, true ) ) { $this->roles[] = $r; }
        $GLOBALS['ROLE_OPS'][] = "+$r#{$this->ID}";
    }
    public function remove_role( string $r ): void {
        $this->roles = array_values( array_filter( $this->roles, fn( $x ) => $x !== $r ) );
        $GLOBALS['ROLE_OPS'][] = "-$r#{$this->ID}";
    }
}
function get_user_by( $field, $value ) {
    foreach ( $GLOBALS['USERS'] as $id => $u ) {
        if ( $field === 'id' && (int) $value === (int) $id ) { return $u; }
        if ( $field === 'email' && strcasecmp( (string) $value, (string) $u->user_email ) === 0 ) { return $u; }
    }
    return false;
}
function update_user_meta( $uid, $k, $v ) { $GLOBALS['USERMETA'][ $uid ][ $k ] = $v; return true; }
function delete_user_meta( $uid, $k ) { unset( $GLOBALS['USERMETA'][ $uid ][ $k ] ); return true; }
function get_current_user_id() { return (int) $GLOBALS['CURRENT']; }
function get_user_meta( $uid, $key, $single = false ) {
    $v = $GLOBALS['USERMETA'][ $uid ][ $key ] ?? '';
    return $single ? $v : ( $v === '' ? [] : [ $v ] );
}
function get_transient( $k ) { return $GLOBALS['TRANSIENT'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['TRANSIENT'][ $k ] = $v; return true; }
function lgpo_notify_failure( $email, $name, $code, $detail ) { $GLOBALS['NOTIFIED'][] = [ $email, $code ]; }
function lgpo_notify_onboard( $uid, $name, $email, $tier, $src ) { $GLOBALS['ONBOARD'][] = $uid; }
function wp_insert_user( $a ) { $id = 5000 + count( $GLOBALS['MINTED'] ); $GLOBALS['MINTED'][] = $a; return $id; }
function is_wp_error( $t ) { return false; }
function wp_generate_password( $n = 12, $s = true, $x = false ) { return 'pw'; }
function sanitize_user( $s, $strict = false ) { return preg_replace( '/[^a-z0-9_]/i', '', (string) $s ); }
function username_exists( $u ) { return false; }
function do_action( $h, ...$a ) { $GLOBALS['ACTIONS'][] = $h; }
function add_action( $h, $c, $p = 10, $n = 1 ) { $GLOBALS['HOOKS'][ $h ][] = $c; return true; }
function add_filter( $h, $c, $p = 10, $n = 1 ) { $GLOBALS['HOOKS'][ $h ][] = $c; return true; }
function register_rest_route( $ns, $route, $args = [] ) { $GLOBALS['ROUTES'][ $ns . $route ] = $args; return true; }
function home_url( $p = '' ) { return 'https://dev2.loothgroup.com' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }

class WP_REST_Response {
    public function __construct( public $data = null, public int $status = 200 ) {}
    public function get_data() { return $this->data; }
    public function get_status(): int { return $this->status; }
}
class WP_REST_Request {
    public function __construct( private array $params = [], private array $headers = [] ) {}
    public function get_param( $k ) { return $this->params[ $k ] ?? null; }
    public function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? ''; }
    public function get_route(): string { return '/lg-member-sync/v1/checkout-audience'; }
}

/* ─── load the real units ─────────────────────────────────────────────────── */

$FILES = [
    'audience'  => "$ROOT/lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php",
    'comp'      => "$ROOT/lg-patreon-stripe-poller/src/Membership/CompStanding.php",
    'compexp'   => "$ROOT/lg-patreon-stripe-poller/src/Membership/CompExpiry.php",
    'rest'      => "$ROOT/lg-patreon-stripe-poller/src/Wp/CheckoutAudienceRestController.php",
    'prov'      => "$ROOT/lg-patreon-stripe-poller/src/Wp/UserProvisioner.php",
    'sync'      => "$ROOT/lg-patreon-stripe-poller/src/Sync.php",
    'arbiter'   => "$ROOT/lg-patreon-stripe-poller/src/Arbiter.php",
    'wpdoor'    => "$ROOT/lg-patreon-stripe-poller/src/Wp/CheckoutRestController.php",
    'plugin'    => "$ROOT/lg-patreon-stripe-poller/src/Plugin.php",
    'restctl'   => "$ROOT/lg-patreon-stripe-poller/src/Wp/RestController.php",
    'guard'     => "$ROOT/lg-stripe-billing/src/Core/CheckoutAudienceGuard.php",
    'probeI'    => "$ROOT/lg-stripe-billing/src/Contracts/CheckoutAudienceProbe.php",
    'probe'     => "$ROOT/lg-stripe-billing/src/Adapters/HttpCheckoutAudienceProbe.php",
    'slimdoor'  => "$ROOT/lg-stripe-billing/src/Http/Controllers/CheckoutController.php",
    'container' => "$ROOT/lg-stripe-billing/config/container.php",
    'settingsI' => "$ROOT/lg-stripe-billing/src/Contracts/SettingsStore.php",
    'settings'  => "$ROOT/lg-stripe-billing/src/Adapters/EnvSettingsStore.php",
];
foreach ( $FILES as $k => $f ) { if ( ! is_readable( $f ) ) { cannot( "missing $k: $f" ); } }

require_once $FILES['arbiter'];
require_once $FILES['audience'];
require_once $FILES['comp'];
require_once $FILES['compexp'];
require_once $FILES['rest'];
require_once $FILES['prov'];
require_once $FILES['sync'];
require_once $FILES['wpdoor'];
require_once $FILES['restctl'];   // #193 §K — the /auth exemption lives here
require_once $FILES['probeI'];
require_once $FILES['guard'];

use LGMS\Membership\CheckoutAudience as CA;
use LGMS\Wp\UserProvisioner;
use LGMS\Wp\CheckoutAudienceRestController as CARest;
use LGMS\Wp\CheckoutRestController;
use LGSB\Core\CheckoutAudienceGuard;

/** A probe whose answer the test dictates. */
final class FakeProbe implements LGSB\Contracts\CheckoutAudienceProbe {
    public function __construct(private $answer) {}
    public function decide(?string $email): ?array { return $this->answer; }
}

/** Reset the world between cases so no case can be carried by another's state. */
function reset_world(): void {
    $GLOBALS['OPTS']      = [];
    $GLOBALS['BRIDGE']    = [];
    $GLOBALS['SQL']       = [];
    $GLOBALS['LOG']       = [];
    $GLOBALS['MINTED']    = [];
    $GLOBALS['TRANSIENT'] = [];
    $GLOBALS['NOTIFIED']  = [];
    $GLOBALS['REPORTED']  = [];
    $GLOBALS['ARBITER']   = [];
    $GLOBALS['ROLE_OPS']  = [];
    $GLOBALS['ONBOARD']   = [];
    $GLOBALS['ACTIONS']   = [];
    $GLOBALS['STRIPE_CALLS'] = [];
    $GLOBALS['CURRENT']   = 0;
    $GLOBALS['SOURCES']   = [];
    $GLOBALS['USERMETA']  = [];
    $GLOBALS['COHORT_EMAILS'] = [];   // #193 — no addresses listed is the DEFAULT world
    $GLOBALS['EMAIL_ASKS']    = [];   // every address the decider was asked about
    $GLOBALS['USERS']     = [
        101 => new FakeUser( 101, 'tester1@example.com', [ 'looth1' ] ),               // in cohort
        102 => new FakeUser( 102, 'tester2@example.com', [ 'looth1' ] ),               // in cohort
        900 => new FakeUser( 900, 'member@example.com',  [ 'looth3' ] ),               // member, NOT in cohort
        1   => new FakeUser( 1,   'admin@example.com',   [ 'administrator' ] ),        // administrator
        400 => new FakeUser( 400, 'comp@example.com',    [ 'looth4' ] ),               // COMP / staff
        401 => new FakeUser( 401, 'comp2@example.com',   [ 'looth4', 'looth1' ] ),     // comp + stale lower tier
        402 => new FakeUser( 402, 'compexp@example.com', [ 'looth4' ] ),               // comp, EXPIRED
    ];
    // Mirrors the real shape measured on both boxes 8/21: bare 'Y-m-d H:i:s',
    // and present on only a minority of holders (2 of 14 on live, both past).
    $GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-07-11 15:25:00';
    // #183 armed comp expiry. This gate is not about that, so the flag is
    // pinned OFF here — at EVERY reset, never once at module scope, because a
    // pin set once is silently gone by the first assertion. §I9b arms it
    // deliberately and puts it back.
    \LGMS\Membership\CompExpiry::$override = [ 'enabled' => false, 'effective_from' => '' ];
}

/** Did a bridge row get written? Keeper's proof 3 turns on this being false. */
function bridge_written(): bool {
    foreach ( $GLOBALS['SQL'] as [ $sql, $p ] ) {
        if ( stripos( $sql, 'INSERT' ) !== false && stripos( $sql, 'wp_user_bridge' ) !== false ) { return true; }
    }
    return false;
}

echo "GATE 86 — the soft-launch cohort is real in the CHECKOUT PATH (#181)\n";
note( 'the four proofs keeper required: §B/§H the exact request refuses; §B4/§C2 a cohort' );
note( 'member still buys; §C1 a pre-minted session cannot provision; §C3 a bridged member' );
note( 'still sweeps, grants AND retractions.' );

/* ═══ §A — three states, and they are genuinely different ══════════════════ */
section( '§A  the audience states' );

reset_world();
is_( CA::state() === 'allowlist',
     'A1  ABSENT option reads `allowlist` — the ruled default is ENFORCING, not dark' );
is_( CA::DEFAULT_STATE === 'allowlist',
     'A1b the declared default constant is `allowlist` (keeper ruling (a), 8/21)' );

foreach ( [ 'off', 'allowlist', 'on' ] as $s ) {
    $GLOBALS['OPTS'][ CA::OPT ] = $s;
    is_( CA::state() === $s, "A2  `$s` is read back as `$s`" );
}

// FAIL-SAFE CLOSED. Every one of these is a way the option can be wrong, and
// not one of them may be the thing that opens the doors.
foreach ( [ 'ON', ' AllowList ', 'yes', '1', 'enabled', '', 'future-state-5' ] as $junk ) {
    $GLOBALS['OPTS'][ CA::OPT ] = $junk;
    $got = CA::state();
    $expect = in_array( strtolower( trim( $junk ) ), [ 'off', 'allowlist', 'on' ], true )
        ? strtolower( trim( $junk ) ) : 'allowlist';
    is_( $got === $expect, "A3  junk option " . var_export( $junk, true ) . " reads `$expect`, never permissive" );
}
$GLOBALS['OPTS'][ CA::OPT ] = [ 'not', 'a', 'string' ];
is_( CA::state() === 'allowlist', 'A3b a stray ARRAY reads `allowlist`' );
$GLOBALS['OPTS'][ CA::OPT ] = true;
is_( CA::state() === 'allowlist', 'A3c a stray BOOL reads `allowlist`' );

$GLOBALS['OPTS'][ CA::OPT ] = 'off';
is_( CA::enforcing() === false, 'A4  `off` is the only state that is not enforcing' );
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
is_( CA::enforcing() === true,  'A4b `allowlist` enforces' );
$GLOBALS['OPTS'][ CA::OPT ] = 'on';
is_( CA::enforcing() === true,  'A4c `on` still consults (it answers yes, which is not the same thing)' );

// LIVENESS FOR THE ABSENCE ASSERTIONS BELOW. "nobody is refused in `off`" is
// trivially true of a fence that never refuses anyone; prove it can refuse.
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
is_( CA::allowsEmail( 'member@example.com' ) === false,
     'A5  LIVENESS: in `allowlist` this fence really does refuse somebody' );

/* ═══ §B — the mint half ═══════════════════════════════════════════════════ */
section( '§B  minting a checkout session' );

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';

// PROOF 1, at the unit: the anonymous poster from the charter.
is_( CA::allowsEmail( null ) === false,
     'B1  ANON with NO email is refused in `allowlist` — the exact charter case' );
is_( CA::allowsEmail( '' ) === false,   'B1b empty-string email is refused' );
is_( CA::allowsEmail( '   ' ) === false, 'B1c whitespace-only email is refused' );
note( 'B1 is the inversion of DoublePayGuard, which passes a no-email checkout on purpose.' );

is_( CA::allowsEmail( 'stranger@example.com' ) === false,
     'B2  an email with NO WordPress account is refused (cannot be on a list of user ids)' );
is_( CA::allowsEmail( 'member@example.com' ) === false,
     'B3  a REAL MEMBER outside the cohort is refused — membership is not an invitation' );
is_( CA::allowsEmail( 'tester1@example.com' ) === true,
     'B4  a COHORT member is allowed in `allowlist` (keeper proof 2)' );
is_( CA::allowsEmail( 'TESTER1@EXAMPLE.COM' ) === true,
     'B4b the cohort lookup is case-insensitive on the address' );

// NO ADMIN BYPASS (keeper ruling (b)). User 1 is an administrator and is NOT
// in $COHORT. The header's predicate would let them through; this must not.
is_( CA::allowsUser( 1 ) === false,
     'B5  NO ADMIN BYPASS — an administrator outside the cohort is refused' );
is_( CA::allowsEmail( 'admin@example.com' ) === false,
     'B5b ...by email as well as by id' );
$audSrc = bare( $FILES['audience'] );
is_( strpos( $audSrc, 'manage_options' ) === false,
     'B5c the decision class does not mention manage_options at all' );
is_( strpos( $audSrc, 'stripe_testgroup' ) === false,
     'B5d ...nor the header capability that DOES widen to admins' );

// The other two states.
$GLOBALS['OPTS'][ CA::OPT ] = 'on';
is_( CA::allowsEmail( null ) === true && CA::allowsEmail( 'stranger@example.com' ) === true,
     'B6  `on` is general availability — everybody, including anon' );
$GLOBALS['OPTS'][ CA::OPT ] = 'off';
is_( CA::allowsEmail( null ) === true && CA::allowsEmail( 'stranger@example.com' ) === true,
     'B6b `off` consults nobody — today exactly' );

/* ─── the Slim guard: which refusal, and does it use a different sentence ─── */
section( '§B7 the Slim guard — two refusals, two sentences' );

$g = new CheckoutAudienceGuard( new FakeProbe( [ 'state' => 'allowlist', 'allowed' => false, 'message' => 'not yet' ] ) );
$r = $g->refusalFor( 'stranger@example.com' );
is_( is_array( $r ) && $r['status'] === 403, 'B7  allowlist + not allowed ⇒ 403' );
$refusal403 = $r['error'] ?? '';

$g = new CheckoutAudienceGuard( new FakeProbe( null ) );
$r = $g->refusalFor( 'tester1@example.com' );
is_( is_array( $r ) && $r['status'] === 503, 'B8  UNKNOWN REFUSES, and does it with 503 not 403' );
is_( is_array( $r ) && ( $r['audience'] ?? '' ) === 'unknown', 'B8b ...and says so in `audience`' );
$refusal503 = $r['error'] ?? '';
is_( $refusal503 !== '' && $refusal503 !== $refusal403,
     'B8c the 503 sentence DIFFERS from the 403 sentence (keeper: "saves an hour later")' );

// ⚠️ B8c ALONE WAS A BLIND SPOT, found by the red-first run and kept as M8.
// The 403 above carried a message WordPress supplied ('not yet'), so the two
// sentences differed even when the guard's own two constants were made
// identical. Drive the fallback path — no message from WordPress — so the
// comparison is between the constants this file actually owns.
$g = new CheckoutAudienceGuard( new FakeProbe( [ 'state' => 'allowlist', 'allowed' => false, 'message' => null ] ) );
$fallback403 = ( $g->refusalFor( 'stranger@example.com' ) )['error'] ?? '';
is_( $fallback403 !== '' && $fallback403 !== $refusal503,
     'B8d ...and they differ when WordPress supplies NO message, so the guard\'s own two constants differ' );
note( '403 says "not open yet"; 503 says "could not verify" — opposite fixes, so opposite words.' );

$g = new CheckoutAudienceGuard( new FakeProbe( [ 'state' => 'allowlist', 'allowed' => true, 'message' => 'x' ] ) );
is_( $g->refusalFor( 'tester1@example.com' ) === null, 'B9  a cohort member is not refused (keeper proof 2)' );
foreach ( [ 'off', 'on' ] as $s ) {
    $g = new CheckoutAudienceGuard( new FakeProbe( [ 'state' => $s, 'allowed' => false, 'message' => 'x' ] ) );
    is_( $g->refusalFor( 'anyone@example.com' ) === null, "B9b `$s` refuses nobody even when allowed=false" );
}

// The guard must not have grown a gift exemption.
$guardSrc = bare( $FILES['guard'] );
is_( strpos( $guardSrc, 'isGift' ) === false && strpos( $guardSrc, 'gift' ) === false,
     'B10 the guard takes NO gift argument — gifts are fenced, with no exception to forget' );

/* ═══ §C — the provision half, the one that cannot be routed around ════════ */
section( '§C  provisioning (the backstop)' );

// PROOF 3: a session minted before the list changed cannot provision.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$threw = false;
try { UserProvisioner::findOrProvision( 7001, 'stranger@example.com', 'A Stranger' ); }
catch ( \RuntimeException $e ) { $threw = true; }
is_( $threw, 'C1  a NON-COHORT email is refused provisioning (keeper proof 3)' );
is_( $GLOBALS['MINTED'] === [], 'C1b NO WordPress user was minted' );
is_( ! bridge_written(),        'C1c NO bridge row was written' );
is_( $GLOBALS['REPORTED'] === [] && $GLOBALS['ROLE_OPS'] === [],
     'C1d NO opinion reported and NO role was written ⇒ no grant' );
is_( $GLOBALS['ACTIONS'] === [], 'C1e no looth_tier_changed, so no cache priming for a ghost' );
is_( count( $GLOBALS['LOG'] ) > 0, 'C1f the refusal is LOGGED (Ian asked to see blocked attempts)' );
is_( count( $GLOBALS['NOTIFIED'] ) === 1, 'C1g an operator was notified once' );

// A member who exists but is not invited: same answer, and it is the case a
// naive email-exists check would wave through.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$threw = false;
try { UserProvisioner::findOrProvision( 7002, 'member@example.com', 'A Member' ); }
catch ( \RuntimeException $e ) { $threw = true; }
is_( $threw && ! bridge_written(),
     'C1h an EXISTING member outside the cohort is not bridged either' );

// PROOF 2 (the other half): a cohort member provisions normally.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$uid = UserProvisioner::findOrProvision( 7003, 'tester1@example.com', 'Tester One' );
is_( $uid === 101, 'C2  a COHORT member is provisioned — resolves to their real account (proof 2)' );
is_( bridge_written(), 'C2b ...and IS bridged' );
is_( $GLOBALS['MINTED'] === [], 'C2c ...without minting a duplicate account' );

// PROOF 4: an already-bridged member is untouched in EVERY state, and their
// sweep still grants AND retracts. This is the assertion that stops the fence
// from freezing the members it was never aimed at.
foreach ( [ 'off', 'allowlist', 'on' ] as $state ) {
    reset_world();
    $GLOBALS['OPTS'][ CA::OPT ] = $state;
    $GLOBALS['BRIDGE'] = [ 7100 => 900 ];   // customer 7100 -> member 900, NOT in cohort
    $got = UserProvisioner::findOrProvision( 7100, 'member@example.com', 'A Member' );
    is_( $got === 900, "C3  already-bridged member returns normally in `$state` (proof 4)" );
    is_( $GLOBALS['LOG'] === [], "C3b ...silently — no refusal logged in `$state`" );
}

// ...and through the REAL Sync::customer, both directions.
require_once $FILES['sync'];
foreach ( [ [ 'looth3', 'a GRANT' ], [ null, 'a RETRACTION' ] ] as [ $tier, $label ] ) {
    reset_world();
    $GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
    $GLOBALS['BRIDGE']    = [ 7100 => 900 ];
    $GLOBALS['CUSTOMERS'] = [ 7100 => [ 'email' => 'member@example.com', 'name' => 'A Member' ] ];
    $GLOBALS['TIERS']     = [ 7100 => $tier ];
    $res = LGMS\Sync::customer( 7100 );
    is_( ( $res['ok'] ?? false ) === true, "C4  Sync::customer succeeds for a bridged member — $label" );
    is_( $GLOBALS['REPORTED'] === [ [ 900, 'stripe', $tier ] ],
         "C4b ...and reports " . var_export( $tier, true ) . " to lg_role_sources — $label survives the fence" );
    $roles = $GLOBALS['USERS'][900]->roles;
    is_( $tier === 'looth3'
            ? in_array( 'looth3', $roles, true )
            : ! array_intersect( [ 'looth2', 'looth3' ], $roles ),
         "C4c ...and the REAL Arbiter wrote the outcome — $label landed on the role" );
}

// A stranger arriving through Sync::customer (the gift-redemption / sweep door)
// is stopped, and stopped as `provision failed` rather than half-done.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['CUSTOMERS'] = [ 7200 => [ 'email' => 'stranger@example.com', 'name' => 'Nobody' ] ];
$GLOBALS['TIERS']     = [ 7200 => 'looth3' ];
$res = LGMS\Sync::customer( 7200 );
is_( ( $res['ok'] ?? true ) === false && str_contains( (string) ( $res['message'] ?? '' ), 'provision failed' ),
     'C5  a stranger reaching Sync::customer (gift redeem / 5-min sweep) is refused' );
is_( $GLOBALS['REPORTED'] === [] && $GLOBALS['ROLE_OPS'] === [] && $GLOBALS['MINTED'] === [],
     'C5b ...with no account, no opinion and no role written — gifts are fenced too' );

// `off` is today exactly: a stranger provisions as they always did.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'off';
$uid = UserProvisioner::findOrProvision( 7300, 'stranger@example.com', 'A Stranger' );
is_( $uid >= 5000 && count( $GLOBALS['MINTED'] ) === 1,
     'C6  `off` mints exactly as before — the OFF state is genuinely untouched' );

/* ═══ §I — looth4 is the everything bypass, and this fence respects it ════ */
section( '§I  looth4 (comp/staff) is never harmed — Ian 8/21' );

note( 'Ian, verbatim: "looth4 is the everything bypass the stripe side of membeship' );
note( 'needs to respect what we have built there." 15 holders on live, staff among them.' );

// I1 — THE LIVE-HARM CASE. A comp member with NO Stripe customer and NO
// subscription, swept by the very machinery that computes roles. The real
// Arbiter runs; the role must survive. This is asserted against the REAL
// class because a stub would only prove the stub's manners.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['SOURCES'][400] = [ 'stripe' => null ];   // a stripe RETRACTION opinion
$res = LGMS\Arbiter::sync( 400 );
is_( in_array( 'looth4', $GLOBALS['USERS'][400]->roles, true ),
     'I1  a looth4 holder with NO Stripe customer SURVIVES an Arbiter sync carrying a stripe retraction' );
is_( str_contains( (string) ( $res['reason'] ?? '' ), 'looth4 protected' ),
     'I1b ...and the Arbiter says so — it is a protection, not an accident of ordering' );

// I1c — the same, with NO sources at all (the plain nightly sweep shape).
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
LGMS\Arbiter::sync( 400 );
is_( in_array( 'looth4', $GLOBALS['USERS'][400]->roles, true ),
     'I1c ...and survives a sync with ZERO source rows, which is what a comp member has' );

// I1d — LIVENESS. "looth4 survived" is worthless if this Arbiter never demotes
// anyone. Prove the same call demotes a NON-comp member on the same input.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['SOURCES'][900] = [ 'stripe' => null ];
LGMS\Arbiter::sync( 900 );
is_( ! in_array( 'looth3', $GLOBALS['USERS'][900]->roles, true ),
     'I1d LIVENESS: the same sweep DOES strip looth3 from a non-comp member — so I1 measured something' );

// I2 — the comp de-dupe still works (looth4 + a stale lower tier).
reset_world();
LGMS\Arbiter::sync( 401 );
is_( in_array( 'looth4', $GLOBALS['USERS'][401]->roles, true )
     && ! in_array( 'looth1', $GLOBALS['USERS'][401]->roles, true ),
     'I2  looth4 + a stale looth1 de-dupes DOWN TO looth4 — the comp grant wins' );

// I3 — MY FENCE CANNOT DEMOTE ANYONE, comp or otherwise. It only ever refuses
// to CREATE. Asserted on the source so it cannot regress into a role write.
$provSrcI = bare( $FILES['prov'] );
$audSrcI  = bare( $FILES['audience'] );
foreach ( [ 'remove_role', 'add_role', 'wp_update_user', 'set_role' ] as $writer ) {
    is_( strpos( $audSrcI, $writer ) === false && strpos( $provSrcI, $writer ) === false,
         "I3  neither the decider nor the fence calls $writer() — this fence cannot demote anybody" );
}

// I4 — a comp member is never REACHED by the fence, because the fence only
// runs on the provisioning path and a comp member has no Stripe customer.
// Where they DO have one and are already bridged, they return before it.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['BRIDGE'] = [ 7400 => 400 ];
$got = UserProvisioner::findOrProvision( 7400, 'comp@example.com', 'Comp Member' );
is_( $got === 400 && $GLOBALS['LOG'] === [],
     'I4  a BRIDGED looth4 holder provisions silently — the fence is never reached' );
is_( in_array( 'looth4', $GLOBALS['USERS'][400]->roles, true ),
     'I4b ...and still holds looth4 afterwards' );

// I5 — and their sweep still completes rather than erroring out.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['BRIDGE']    = [ 7400 => 400 ];
$GLOBALS['CUSTOMERS'] = [ 7400 => [ 'email' => 'comp@example.com', 'name' => 'Comp' ] ];
$GLOBALS['TIERS']     = [ 7400 => null ];          // no Stripe entitlement at all
$res = LGMS\Sync::customer( 7400 );
is_( ( $res['ok'] ?? false ) === true,
     'I5  Sync::customer succeeds for a comp member with NO Stripe entitlement' );
is_( in_array( 'looth4', $GLOBALS['USERS'][400]->roles, true ),
     'I5b ...and looth4 SURVIVES the full sweep (keeper proof: no sweep may strip looth4)' );

// I6 — the UNBRIDGED comp member. This is the one case where my fence does
// answer "no" about a looth4 holder, so it is stated rather than glossed: the
// refusal PREVENTS a bridge being written and PREVENTS the Arbiter running.
// Both are strictly safer for a comp member than the old behaviour, which
// would have provisioned them and then run a sweep over them.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$threw = false;
try { UserProvisioner::findOrProvision( 7401, 'comp@example.com', 'Comp' ); }
catch ( \RuntimeException $e ) { $threw = true; }
is_( $threw, 'I6  an UNBRIDGED comp member outside the cohort is refused provisioning...' );
is_( in_array( 'looth4', $GLOBALS['USERS'][400]->roles, true ),
     'I6b ...and STILL HOLDS looth4 — the refusal costs them nothing' );
is_( $GLOBALS['ROLE_OPS'] === [] && $GLOBALS['REPORTED'] === [],
     'I6c ...and no role or opinion was written about them at all' );
note( 'I6 is the honest edge: a comp member who somehow reaches Stripe checkout is' );
note( 'refused like anyone else. They lose NOTHING — see the handoff, boarded for Ian.' );

// I8 — UNEXPIRED looth4, not looth4 (keeper's sharpening, 8/21). Asserting on
// the bare role would encode "comped forever", which is the very reading #183
// exists to correct — and this gate would then have to be fought to fix it.
$CS = 'LGMS\\Membership\\CompStanding';
reset_world();
is_( $CS::isActiveComp( 400 ) === true,
     'I8  a looth4 holder with NO expiry meta is an ACTIVE comp (12 of 14 live holders)' );
is_( $CS::expiresAt( 400 ) === null,
     'I8b ...and a missing expiry reads NULL — "never expires", NOT "expired"' );
is_( $CS::isActiveComp( 402 ) === false,
     'I8c a looth4 holder whose date has PASSED is not an active comp' );
is_( $CS::isExpiredComp( 402 ) === true, 'I8d ...it is the expired state, named' );
is_( $CS::isActiveComp( 900 ) === false && $CS::isExpiredComp( 900 ) === false,
     'I8e a non-comp member is neither' );

// A future date must read ACTIVE. Without this the class could return false for
// everyone carrying the meta and I8c would still pass.
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + 86400 * 30 );
is_( $CS::isActiveComp( 402 ) === true,
     'I8f LIVENESS: a FUTURE expiry reads ACTIVE — so I8c measured the date, not the meta key' );

// Garbage must not demote anybody.
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = 'not a date at all';
is_( $CS::expiresAt( 402 ) === null && $CS::isActiveComp( 402 ) === true,
     'I8g an UNPARSEABLE date is not an expiry — a fat-fingered field cannot lapse a comp member' );

// ⚠️ THIS ASSERTION WAS INVERTED BY #183, NOT DELETED. It used to record the
// gap — "an EXPIRED comp is STILL protected, recorded not fixed" — because
// nothing enforced the date: the expiry plugin was not installed, not in
// mu-plugins, not in active_plugins, and no cron event mentioned it (measured
// 8/21, both boxes). #183 re-armed it, so the honest form of the same question
// is now about STATE, and both halves are asserted so it cannot quietly drift
// back to "comped forever" in either direction.
//
// Gate 89 owns comp expiry. What is asserted HERE is only the part #181 leans
// on: that an expired comp is still protected in the state these boxes
// actually run, and that this is a flag rather than an accident.
reset_world();
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-07-11 15:25:00';
$GLOBALS['SOURCES'][402] = [ 'stripe' => null ];
LGMS\Arbiter::sync( 402 );
is_( in_array( 'looth4', $GLOBALS['USERS'][402]->roles, true ),
     'I9  an EXPIRED comp is still protected while comp expiry is OFF — the shipped default, and #181 relies on it' );

// I9b — LIVENESS. Without this, I9 is satisfied by comp expiry being broken
// rather than by it being off, which is exactly the state it used to record.
reset_world();
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-07-11 15:25:00';
$GLOBALS['SOURCES'][402] = [ 'stripe' => null ];
\LGMS\Membership\CompExpiry::$override = [ 'enabled' => true, 'effective_from' => '2026-07-01' ];
LGMS\Arbiter::sync( 402 );
is_( ! in_array( 'looth4', $GLOBALS['USERS'][402]->roles, true ),
     'I9b ARMED past the cutover, the same comp DOES lose looth4 — #183 closed the gap this line used to record' );
is_( ! empty( array_intersect( $GLOBALS['USERS'][402]->roles, [ 'looth1', 'looth2', 'looth3' ] ) ),
     'I9c ...and lands on a real tier rather than nothing — an expiry is not a deletion' );

// I9d — the ruling that outranks the mechanism. Both live timers ran out before
// any cutover we would set, so an armed sweep must still leave them alone.
reset_world();
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-07-11 15:25:00';
\LGMS\Membership\CompExpiry::$override = [ 'enabled' => true, 'effective_from' => '2026-08-21' ];
LGMS\Arbiter::sync( 402 );
is_( in_array( 'looth4', $GLOBALS['USERS'][402]->roles, true ),
     'I9d a timer that ran out BEFORE the cutover is held even when armed — Ian 8/21, the two overdue accounts are left alone' );

// And #181 changes nothing for either kind of comp holder.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-07-11 15:25:00';
foreach ( [ 400 => 'an ACTIVE comp', 402 => 'an EXPIRED comp' ] as $uid => $label ) {
    $before = $GLOBALS['USERS'][ $uid ]->roles;
    try { UserProvisioner::findOrProvision( 7500 + $uid, $GLOBALS['USERS'][ $uid ]->user_email, 'C' ); }
    catch ( \RuntimeException $e ) { /* refused, as any non-cohort address is */ }
    is_( $GLOBALS['USERS'][ $uid ]->roles === $before,
         "I10 #181 leaves $label byte-identical — the fence refuses, it never demotes" );
}

// The refusal NAMES the comp standing, so an operator can tell a staff member
// apart from a stranger. Two opposite support actions, one alert channel.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
try { UserProvisioner::findOrProvision( 7601, 'comp@example.com', 'Comp' ); }
catch ( \RuntimeException $e ) {}
is_( str_contains( implode( "\n", $GLOBALS['LOG'] ), 'looth4' ),
     'I11 the refusal names the comp standing — a comped member is not logged as a stranger' );

// I7 — the double-pay guard cannot misfire on a comp member: it reads Patreon
// facts only and never looks at a role.
$psSrc = bare( "$ROOT/lg-patreon-stripe-poller/src/Membership/PatreonStanding.php" );
foreach ( [ 'looth4', 'roles', 'user_can' ] as $needle ) {
    is_( strpos( $psSrc, $needle ) === false,
         "I7  PatreonStanding never reads `$needle` — a comp member's ROLE cannot trigger a double-pay block" );
}

/* ═══ §D — one list, and no second one ════════════════════════════════════ */
section( '§D  no second list' );

$provSrc = bare( $FILES['prov'] );
$doorSrc = bare( $FILES['wpdoor'] );

is_( strpos( $audSrc, 'StripeLifecycle::inCohort' ) !== false,
     'D1  the decision goes through StripeLifecycle::inCohort()' );
is_( substr_count( $audSrc, 'lgms_stripe_lifecycle_allowlist' ) === 0,
     'D2  the decision class names NO allowlist option of its own' );
foreach ( [ 'prov' => $provSrc, 'wpdoor' => $doorSrc, 'guard' => $guardSrc ] as $k => $src ) {
    is_( strpos( $src, 'lgms_stripe_lifecycle_allowlist' ) === false,
         "D3  $k does not name the cohort option directly — it asks CheckoutAudience" );
}
is_( substr_count( $audSrc, 'get_option' ) === 1,
     'D4  exactly ONE option is read by the decision class (the audience state)' );
is_( strpos( $audSrc, "'" . CA::OPT . "'" ) !== false || strpos( $audSrc, 'OPT' ) !== false,
     'D5  ...and it is lgms_checkout_audience' );

/* ═══ §E — refusals are honest and logged, notices are rate-limited ═══════ */
section( '§E  the signal Ian asked for' );

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
CA::logRefusal( CA::D_SLIM_CHECKOUT, 'stranger@example.com', 0, 'test' );
$line = $GLOBALS['LOG'][0] ?? '';
is_( str_contains( $line, 'REFUSED' ),               'E1  the log line says REFUSED' );
is_( str_contains( $line, 'stranger@example.com' ),  'E2  ...names who' );
is_( str_contains( $line, 'allowlist' ),             'E3  ...and the state it was refused in' );
is_( str_contains( $line, CA::D_SLIM_CHECKOUT ),     'E4  ...and which door' );

reset_world();
is_( CA::notifyRefusalOnce( 'x@example.com', 'd' ) === true,  'E5  the first refusal notifies' );
is_( CA::notifyRefusalOnce( 'x@example.com', 'd' ) === false, 'E6  the second does NOT — the 5-min sweep cannot flood the alert channel' );
is_( count( $GLOBALS['NOTIFIED'] ) === 1, 'E6b ...measured at the notifier, not just the return value' );
is_( CA::notifyRefusalOnce( 'y@example.com', 'd' ) === true,  'E7  a DIFFERENT address still notifies (the limit is per-address)' );

is_( CA::refusalMessage() !== CA::unknownMessage(), 'E8  the two member-facing sentences differ' );
is_( stripos( CA::refusalMessage(), 'allowlist' ) === false
     && stripos( CA::refusalMessage(), 'whitelist' ) === false,
     'E9  the refusal does not invite a stranger to go hunting for a list' );

/* ═══ §F — the WordPress checkout door ════════════════════════════════════ */
section( '§F  the WordPress door (/me/checkout-session)' );

CheckoutRestController::$clientFactory = fn() => new LGMS\Stripe\Client();

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['CURRENT'] = 900;                      // a real member, not in the cohort
$resp = CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $resp->get_status() === 403, 'F1  a SIGNED-IN member outside the cohort gets 403' );
is_( $GLOBALS['STRIPE_CALLS'] === [], 'F1b ...and Stripe was never called — a refusal costs nothing' );
is_( count( $GLOBALS['LOG'] ) > 0,    'F1c ...and it was logged' );

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['CURRENT'] = 1;                        // an ADMINISTRATOR, not in the cohort
$resp = CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $resp->get_status() === 403, 'F2  NO ADMIN BYPASS at the WordPress door either (ruling (b))' );

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['CURRENT'] = 101;                      // in the cohort
$resp = CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $resp->get_status() === 200, 'F3  a COHORT member mints a session end to end (keeper proof 2)' );
is_( count( $GLOBALS['STRIPE_CALLS'] ) === 1, 'F3b ...and Stripe WAS called' );

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'off';
$GLOBALS['CURRENT'] = 900;
$resp = CheckoutRestController::createSession( new WP_REST_Request( [ 'cadence' => 'month' ] ) );
is_( $resp->get_status() === 200, 'F4  `off` leaves the door exactly as it was' );

/* ═══ §G — wiring, ordering, and the route that must always exist ═════════ */
section( '§G  wiring' );

$slimSrc = bare( $FILES['slimdoor'] );
is_( strpos( $slimSrc, 'audience->refusalFor' ) !== false,
     'G1  the Slim controller actually calls the guard' );
$posAud  = strpos( $slimSrc, 'audience->refusalFor' );
$posBan  = strpos( $slimSrc, 'bannedEmails->isBanned' );
$posDbl  = strpos( $slimSrc, 'doublePay->refusalFor' );
$posCust = strpos( $slimSrc, 'customers->findByEmail' );
is_( $posAud !== false && $posBan !== false && $posAud < $posBan,
     'G2  ...BEFORE the email-ban lookup' );
is_( $posAud !== false && $posDbl !== false && $posAud < $posDbl,
     'G2b ...and before the double-pay probe' );
is_( $posAud !== false && $posCust !== false && $posAud < $posCust,
     'G2c ...and before any customer lookup, so a refusal creates nothing' );

$ctorSrc = (string) file_get_contents( $FILES['slimdoor'] );
is_( strpos( $ctorSrc, 'CheckoutAudienceGuard  $audience' ) !== false
     || strpos( $ctorSrc, 'CheckoutAudienceGuard $audience' ) !== false,
     'G3  the guard is a constructor dependency — the controller cannot construct without it' );

$cont = bare( $FILES['container'] );
is_( strpos( $cont, 'CheckoutAudienceGuard::class' ) !== false
     && strpos( $cont, 'HttpCheckoutAudienceProbe' ) !== false,
     'G4  the container binds the guard to the HTTP probe' );

// THE ROUTE MUST EXIST IN EVERY STATE. This is the deliberate difference from
// /patreon-standing, and getting it wrong would mean a flushed rewrite reads as
// permission.
foreach ( [ 'off', 'allowlist', 'on' ] as $s ) {
    reset_world();
    $GLOBALS['OPTS'][ CA::OPT ] = $s;
    $GLOBALS['ROUTES'] = [];
    CARest::register();
    is_( isset( $GLOBALS['ROUTES']['lg-member-sync/v1/checkout-audience'] ),
         "G5  the route is registered in `$s` — absence must never be mistakable for `off`" );
}
$restSrc = bare( $FILES['rest'] );
is_( strpos( $restSrc, 'maybeRegister' ) === false,
     'G5b ...and there is no flag-keyed registration to regress into' );

// The route reports the state rather than implying it.
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'off';
$d = CARest::decide( new WP_REST_Request( [ 'email' => 'stranger@example.com' ] ) )->get_data();
is_( ( $d['state'] ?? '' ) === 'off' && ( $d['allowed'] ?? null ) === true,
     'G6  the route ANSWERS `off` (a 404 would have been indistinguishable from a broken route)' );
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$d = CARest::decide( new WP_REST_Request( [ 'email' => 'stranger@example.com' ] ) )->get_data();
is_( ( $d['state'] ?? '' ) === 'allowlist' && ( $d['allowed'] ?? null ) === false,
     'G6b ...and refuses a stranger in `allowlist`' );
$d = CARest::decide( new WP_REST_Request( [ 'email' => 'tester1@example.com' ] ) )->get_data();
is_( ( $d['allowed'] ?? null ) === true, 'G6c ...and admits a cohort member' );

// The shared secret still guards it. The BuddyBoss exemption is not a bypass.
reset_world();
$GLOBALS['OPTS']['lgms_shared_secret'] = 's3cret';
is_( CARest::authSharedSecret( new WP_REST_Request( [], [ 'x-lgms-token' => 's3cret' ] ) ) === true,
     'G7  the correct shared secret is accepted' );
is_( CARest::authSharedSecret( new WP_REST_Request( [], [ 'x-lgms-token' => 'wrong' ] ) ) === false,
     'G7b a wrong secret is refused' );
is_( CARest::authSharedSecret( new WP_REST_Request( [], [] ) ) === false,
     'G7c no secret at all is refused' );
unset( $GLOBALS['OPTS']['lgms_shared_secret'] );
is_( CARest::authSharedSecret( new WP_REST_Request( [], [ 'x-lgms-token' => 'anything' ] ) ) === false,
     'G7d an UNCONFIGURED secret is closed, never open' );

// The BuddyBoss exemption names exactly one route and preserves the list.
$ex = CARest::exemptFromBuddyBossRestriction( [ '/buddyboss/v1/signup' ] );
is_( in_array( '/lg-member-sync/v1/checkout-audience', $ex, true ),
     'G8  the BuddyBoss exemption adds this route' );
is_( in_array( '/buddyboss/v1/signup', $ex, true ),
     'G8b ...without dropping what was already there' );
is_( count( $ex ) === 2, 'G8c ...and adds nothing else — the repair is one route wide' );
is_( CARest::exemptFromBuddyBossRestriction( 'not-an-array' ) === 'not-an-array',
     'G8d a non-array from another plugin is returned untouched' );
$ex2 = CARest::exemptFromBuddyBossRestriction( $ex );
is_( count( $ex2 ) === 2, 'G8e applying it twice does not duplicate' );

$plug = bare( $FILES['plugin'] );
is_( strpos( $plug, 'CheckoutAudienceRestController' ) !== false
     && strpos( $plug, 'bb_exclude_endpoints_from_restriction' ) !== false,
     'G9  Plugin.php registers both the route and the exemption' );

// The derived URL, so no box needs an env edit.
require_once $FILES['settingsI'];
require_once $FILES['settings'];
putenv( 'LGMS_SYNC_URL=https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer' );
putenv( 'LGMS_CHECKOUT_AUDIENCE_URL=' );
$_ENV['LGMS_SYNC_URL'] = 'https://dev.loothgroup.com/wp-json/lg-member-sync/v1/sync-customer';
$_ENV['LGMS_CHECKOUT_AUDIENCE_URL'] = '';
$store = new LGSB\Adapters\EnvSettingsStore();
is_( $store->getCheckoutAudienceUrl() === 'https://dev.loothgroup.com/wp-json/lg-member-sync/v1/checkout-audience',
     'G10 the audience URL DERIVES from LGMS_SYNC_URL — no box needs an env edit' );

$setSrc = bare( $FILES['settings'] );
$fnPos  = strpos( $setSrc, 'getCheckoutAudienceUrl' );
$seg    = $fnPos !== false ? substr( $setSrc, $fnPos, 700 ) : '';
is_( stripos( $seg, "'off'" ) === false,
     'G11 there is NO `off` valve on this URL — blanking it would refuse everyone, not relax' );

/* ═══ §H — over real HTTP: the adapter fails closed for real ══════════════ */
section( '§H  the probe over real HTTP' );

require_once $FILES['probe'];

$port = 8100 + ( getmypid() % 400 );
$docroot = sys_get_temp_dir() . '/lg-ca-gate-' . getmypid();
@mkdir( $docroot, 0777, true );
file_put_contents( "$docroot/router.php", <<<'PHP'
<?php
$mode = $_GET['mode'] ?? 'allow';
if ($mode === 'notfound') { http_response_code(404); echo '{"code":"rest_no_route"}'; exit; }
if ($mode === 'error')    { http_response_code(500); echo 'boom'; exit; }
if ($mode === 'bb401')    { http_response_code(401); echo '{"code":"bb_rest_authorization_required"}'; exit; }
if ($mode === 'garbage')  { echo 'not json'; exit; }
if ($mode === 'future')   { echo '{"state":"quarantine","allowed":true}'; exit; }
if ($mode === 'noallow')  { echo '{"state":"allowlist"}'; exit; }
if ($mode === 'deny')     { echo '{"state":"allowlist","allowed":false,"message":"not yet"}'; exit; }
if ($mode === 'off')      { echo '{"state":"off","allowed":true}'; exit; }
echo '{"state":"allowlist","allowed":true,"message":"ok"}';
PHP );

// ⚠️ REGISTERED BEFORE THE SERVER STARTS. The tidy-up at the foot of §H only
// runs on the happy path, and during this gate's own development three stub
// servers leaked from runs that fataled mid-section — on a 2-core box that is
// a real cost, and they collide with the next run's port. A shutdown function
// fires on a fatal and on every exit() path alike.
register_shutdown_function( static function () {
    if ( isset( $GLOBALS['CA_SRV'] ) && is_resource( $GLOBALS['CA_SRV'] ) ) {
        @proc_terminate( $GLOBALS['CA_SRV'] );
        @proc_close( $GLOBALS['CA_SRV'] );
        $GLOBALS['CA_SRV'] = null;
    }
} );

$srv = proc_open(
    sprintf( 'exec php -S 127.0.0.1:%d %s/router.php', $port, escapeshellarg( $docroot ) === "'$docroot'" ? $docroot : $docroot ),
    [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes
);
$GLOBALS['CA_SRV'] = $srv;
$up = false;
for ( $i = 0; $i < 60; $i++ ) {
    $c = @fsockopen( '127.0.0.1', $port, $e1, $e2, 0.2 );
    if ( $c ) { fclose( $c ); $up = true; break; }
    usleep( 100000 );
}

if ( ! $up ) {
    note( 'local PHP server did not come up — §H skipped, and that is reported, not passed' );
    $GLOBALS['H_SKIPPED'] = true;
} else {
    // A settings store pointed at the local stub. It implements the interface
    // in full and DELIBERATELY names every member: EnvSettingsStore is final so
    // it cannot be extended, and a partial double would not compile. If a new
    // setting is added upstream this stops compiling, which is the correct
    // failure — a gate that silently kept running against a stale contract
    // would be asserting about a shape that no longer exists.
    $mk = function ( string $mode ) use ( $port ) {
        return new class( "http://127.0.0.1:$port/?mode=$mode" ) implements LGSB\Contracts\SettingsStore {
            public function __construct( private string $u ) {}
            public function getCheckoutAudienceUrl(): string { return $this->u; }
            public function getSyncSharedSecret(): string { return 'secret'; }
            public function getSecretKey(): string { return ''; }
            public function getPublishableKey(): string { return ''; }
            public function getCheckoutReturnUrl(): string { return ''; }
            public function getHomeUrl(): string { return ''; }
            public function getSyncEndpointUrl(): string { return ''; }
            public function getGiftMailUrl(): string { return ''; }
            public function getPatreonStandingUrl(): string { return ''; }
            public function getWebhookSecret(): string { return ''; }
            public function getBulkDiscountTiers(): array { return []; }
            public function getRegionalFailUrl(): string { return ''; }
            public function getReturnSuccessUrl(): string { return ''; }
        };
    };

    $probe = fn( string $mode ) => ( new LGSB\Adapters\HttpCheckoutAudienceProbe( $mk( $mode ) ) )->decide( 'x@example.com' );

    $d = $probe( 'allow' );
    is_( is_array( $d ) && $d['state'] === 'allowlist' && $d['allowed'] === true,
         'H1  a real 200 allow is decoded' );
    $d = $probe( 'deny' );
    is_( is_array( $d ) && $d['allowed'] === false, 'H2  a real 200 deny is decoded' );

    // Every way WordPress can fail to answer. All of them must refuse.
    foreach ( [ 'notfound' => '404 (flushed rewrite / deactivated plugin)',
                'error'    => '500',
                'bb401'    => '401 bb_rest_authorization_required (the measured dev2 failure)',
                'garbage'  => 'a non-JSON body',
                'future'   => 'a state this app does not recognise',
                'noallow'  => 'a body with no `allowed` key' ] as $mode => $label ) {
        $d = $probe( $mode );
        is_( $d === null, "H3  $label ⇒ UNKNOWN (null), which the guard turns into a 503" );
    }

    $d = $probe( 'off' );
    is_( is_array( $d ) && $d['state'] === 'off', 'H4  `off` arrives as a positive answer, not as a 404' );

    // End to end through the guard: the charter's exact anonymous request.
    $g = new CheckoutAudienceGuard( new LGSB\Adapters\HttpCheckoutAudienceProbe( $mk( 'deny' ) ) );
    $r = $g->refusalFor( null );
    is_( is_array( $r ) && $r['status'] === 403,
         'H5  ANON + a real price id ⇒ 403 through the real HTTP probe (keeper proof 1)' );

    $g = new CheckoutAudienceGuard( new LGSB\Adapters\HttpCheckoutAudienceProbe( $mk( 'allow' ) ) );
    is_( $g->refusalFor( 'tester1@example.com' ) === null,
         'H6  a cohort member passes through the real HTTP probe (keeper proof 2)' );

    if ( is_resource( $srv ) ) { proc_terminate( $srv ); proc_close( $srv ); $GLOBALS['CA_SRV'] = null; }
    @unlink( "$docroot/router.php" ); @rmdir( $docroot );
}

/* ═══ §J — THE LIST TAKES ADDRESSES (#193) ════════════════════════════════ *
 *
 * Ian, 2026-08-22: *"I thought the whitelist would have them generating a
 * wp-user like a normal new member join. Is that not possible?"*
 *
 * ⚠️ THE ASSERTION THAT BITES IS J1, AND THE OBVIOUS ONE IS VACUOUS. "A listed
 * MEMBER still buys" passes on the defect — it passed for the whole life of
 * #181, because that path never changed. What could not happen before this
 * issue is a listed ADDRESS WITH NO ACCOUNT reaching checkout at all, so that
 * is the leg every mutation here is aimed at. (Same trap #148 recorded: an
 * assertion that cannot distinguish the fixed state from the broken one is not
 * an assertion.)
 */
section( '§J  the list takes ADDRESSES, not only existing accounts' );

/* ── J1/J2: the two halves of the fence, for somebody WordPress has never
      heard of. `stranger@example.com` is in $USERS nowhere. ── */
reset_world();
$GLOBALS['OPTS'][ CA::OPT ]   = 'allowlist';
$GLOBALS['COHORT_EMAILS']     = [ 'newtester@example.com' => true ];
is_( CA::allowsEmail( 'newtester@example.com' ) === true,
     'J1  a LISTED ADDRESS WITH NO ACCOUNT may proceed — the whole of #193' );
is_( get_user_by( 'email', 'newtester@example.com' ) === false,
     'J1b ...and it really has no account, so J1 cannot be passing by the id path' );
is_( CA::allowsEmail( 'stranger@example.com' ) === false,
     'J2  an UNLISTED address with no account is still refused (#181 §B3 holds)' );

/* ── J3: the line #181 was opened for. An anonymous poster naming nobody. ── */
is_( CA::allowsEmail( null ) === false, 'J3  a checkout naming NOBODY is still refused' );
is_( CA::allowsEmail( '' ) === false,   'J3b ...and so is an empty address' );
is_( CA::allowsEmail( '   ' ) === false, 'J3c ...and so is whitespace' );

/* ── J4: normalization. A listed address must not depend on how it is typed,
      and a malformed entry must widen NOTHING. ── */
is_( CA::allowsEmail( 'NewTester@Example.com' ) === true, 'J4  case-insensitive' );
is_( CA::allowsEmail( '  newtester@example.com  ' ) === true, 'J4b trimmed' );
is_( CA::allowsEmail( 'newtester@example.com.evil.test' ) === false,
     'J4c a NEAR MISS is not a match — the compare is exact, not a prefix' );

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [];        // the real class drops malformed entries
is_( CA::allowsEmail( 'not-an-email' ) === false,
     'J4d a malformed entry cannot admit anybody — an empty set admits nobody' );

/* ── J5: a listed address that HAS an account behaves exactly as today —
      admitted, and BRIDGED to the account rather than minting a second one.
      (Keeper proof 3.) ── */
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [ 'member@example.com' => true ];   // user 900, NOT in the id cohort
is_( CA::allowsEmail( 'member@example.com' ) === true,
     'J5  a listed ADDRESS whose account exists is admitted' );
$uid = UserProvisioner::findOrProvision( 7700, 'member@example.com', 'A Member' );
is_( $uid === 900,          'J5b ...and provisioning returns the EXISTING account' );
is_( $GLOBALS['MINTED'] === [], 'J5c ...having minted NO duplicate user' );

/* ── J6: the journey Ian asked for, end to end. A listed address, no account,
      and the account is created BY the provisioning the payment triggers. ── */
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [ 'newtester@example.com' => true ];
$uid = UserProvisioner::findOrProvision( 7701, 'newtester@example.com', 'New Tester' );
is_( $uid > 0,                     'J6  a listed address with NO account PROVISIONS (keeper proof 1)' );
is_( count( $GLOBALS['MINTED'] ) === 1, 'J6b ...exactly one WordPress account was created' );
is_( ( $GLOBALS['MINTED'][0]['user_email'] ?? '' ) === 'newtester@example.com',
     'J6c ...for the address that was listed' );
is_( ( $GLOBALS['MINTED'][0]['role'] ?? '' ) === 'looth1',
     'J6d ...at the starter tier, exactly as a real new member (the Arbiter promotes)' );

/* ── J7: THE REMOVAL PROOF, and the reason the union is READ-side. A session
      minted while the address was listed must still fail to provision once it
      is struck. A write-side promotion (id added when the account appears)
      would pass J6 and FAIL here — this is the assertion that chose the
      design. ── */
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [];        // Ian removed the address after the session was minted
$threw = false;
try { UserProvisioner::findOrProvision( 7702, 'newtester@example.com', 'New Tester' ); }
catch ( \RuntimeException $e ) { $threw = true; }
is_( $threw,                        'J7  a session minted for a SINCE-REMOVED address cannot provision (#181 proof 3)' );
is_( $GLOBALS['MINTED'] === [],     'J7b ...nothing minted' );
is_( $GLOBALS['SQL'] === [] || ! in_array( 'INSERT', array_map( fn( $r ) => strtoupper( substr( trim( $r[0] ), 0, 6 ) ), $GLOBALS['SQL'] ), true ),
     'J7c ...and no bridge row written' );

/* ── J8: an ALREADY-BRIDGED member is untouched in every state. #181's
      placement ruling, re-asserted with an address world in play. ── */
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [];
$GLOBALS['BRIDGE']          = [ 7703 => 900 ];
is_( UserProvisioner::findOrProvision( 7703, 'member@example.com', 'A Member' ) === 900,
     'J8  an already-bridged member still resolves — the fence stays BELOW that return' );

/* ── J9: `off` and `on` are untouched by any of this. ── */
foreach ( [ 'off', 'on' ] as $st ) {
    reset_world();
    $GLOBALS['OPTS'][ CA::OPT ] = $st;
    $GLOBALS['COHORT_EMAILS']   = [];
    is_( CA::allowsEmail( 'stranger@example.com' ) === true,
         "J9  `$st` is unchanged — an address world does not alter the other two states" );
}

/* ── J10: THE EMPTY STATE IS THE OFF STATE (keeper ruling D2, 8/22). #193
      ships with no flag; what stands in for one is that a list of plain ids
      never consults the address half at all. Asserted, not argued — and
      measured at the decider, so a refactor that "helpfully" always resolves
      the address would turn this red. ── */
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [];
CA::allowsUser( 900 );
CA::allowsUser( 101 );
is_( $GLOBALS['EMAIL_ASKS'] === [],
     'J10 with NO addresses listed the signed-in path never consults the address half' );

reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [ 'someone@example.com' => true ];
CA::allowsUser( 900 );
is_( $GLOBALS['EMAIL_ASKS'] !== [],
     'J10b ...and with one listed it DOES — the liveness partner, or J10 is vacuous' );

/* ── J11: allowsUser() is textually unchanged, and its widening is the ONE
      predicate's, not a second rule of its own. ── */
$audSrc2 = bare( $FILES['audience'] );
is_( strpos( $audSrc2, 'StripeLifecycle::inCohortEmail' ) !== false,
     'J11 the address question goes through StripeLifecycle, like the id question' );
is_( substr_count( $audSrc2, 'lgms_stripe_lifecycle_allowlist' ) === 0,
     'J11b the decision class STILL names no cohort option of its own (§D2 under #193)' );
is_( substr_count( $audSrc2, 'get_option' ) === 1,
     'J11c ...and still reads exactly ONE option (§D4 under #193)' );
$allowsUserBody = fn_body( $audSrc2, 'allowsUser' );
is_( $allowsUserBody !== '' && strpos( $allowsUserBody, 'StripeLifecycle::inCohort(' ) !== false,
     'J11d allowsUser() still asks inCohort() — the signed-in path is unchanged' );
is_( $allowsUserBody !== '' && strpos( $allowsUserBody, 'inCohortEmail' ) === false,
     'J11e ...and does NOT grow a second rule of its own; its widening is the ONE predicate\'s' );
is_( $allowsUserBody !== '' && strpos( $allowsUserBody, 'manage_options' ) === false,
     'J11f ...and STILL has no admin bypass (ruling (b) survives #193)' );

/* ── J12: the refusal notice tells the operator the RIGHT thing. "no
      WordPress account" stopped being the reason the moment addresses could be
      listed, and an alert naming the wrong cause sends somebody to make an
      account that was never needed. ── */
reset_world();
$GLOBALS['OPTS'][ CA::OPT ] = 'allowlist';
$GLOBALS['COHORT_EMAILS']   = [];
try { UserProvisioner::findOrProvision( 7704, 'stranger@example.com', 'S' ); }
catch ( \RuntimeException $e ) { $msg = $e->getMessage(); }
is_( isset( $msg ) && stripos( $msg, 'neither the address nor any account' ) !== false,
     'J12 the refusal says the ADDRESS is not listed, not merely that there is no account' );
is_( isset( $msg ) && stripos( $msg, 'Testers' ) !== false,
     'J12b ...and names the tab where the address can be listed on its own' );

/* ═══ §K — THE PASSWORD DOOR ANSWERS FOR ITSELF (#193 / D3) ═══════════════ *
 *
 * Approved by keeper 2026-08-22 with three conditions. Measured on dev2 over
 * loopback before the change:
 *   POST /wp-json/lg-member-sync/v1/auth -> 401 bb_rest_authorization_required
 *
 * The exemption must be SURGICAL — condition 3 — because the other three
 * shared-secret routes in this namespace are deliberately still restricted
 * (#181 reported them and did not open them).
 */
section( '§K  the /auth exemption is surgical' );

$restSrc = bare( $FILES['restctl'] );
$exempt  = LGMS\Wp\RestController::exemptAuthFromBuddyBossRestriction( [] );

is_( in_array( '/lg-member-sync/v1/auth', $exempt, true ),
     'K1  /auth is exempted — the route that creates a listed tester\'s account' );
is_( in_array( '/lg-member-sync/v1/gift-auth', $exempt, true ),
     'K2  ...and its alias, which is the same handler and the gift redemption door' );

foreach ( [ '/lg-member-sync/v1/sync-customer',
            '/lg-member-sync/v1/patreon-standing',
            '/lg-member-sync/v1/send-gift-codes' ] as $shut ) {
    is_( ! in_array( $shut, $exempt, true ),
         "K3  $shut is NOT opened — the exemption names two routes, never the namespace" );
}
is_( count( $exempt ) === 2, 'K3b ...and exactly two, so a widening cannot hide among them' );

$pre = [ '/buddyboss/v1/members' ];
is_( LGMS\Wp\RestController::exemptAuthFromBuddyBossRestriction( $pre ) === array_merge( $pre, [ '/lg-member-sync/v1/auth', '/lg-member-sync/v1/gift-auth' ] ),
     'K4  another plugin\'s entries are preserved, never replaced' );
is_( LGMS\Wp\RestController::exemptAuthFromBuddyBossRestriction( 'not-an-array' ) === 'not-an-array',
     'K5  a non-array filter value is handed back untouched' );
$twice = LGMS\Wp\RestController::exemptAuthFromBuddyBossRestriction(
         LGMS\Wp\RestController::exemptAuthFromBuddyBossRestriction( [] ) );
is_( count( $twice ) === 2, 'K6  idempotent — a double-registered filter does not duplicate' );

/* CONDITION 1: the route's OWN hardening is untouched. The exemption removes a
   blanket pre-emption; it must not remove a single one of this door's checks. */
is_( strpos( $restSrc, "'permission_callback' => '__return_true'" ) !== false,
     'K7  the route is still the public sign-in it was designed to be' );
foreach ( [ 'lgms_ga_ip_'  => 'K8  the per-IP throttle is still there',
            'lgms_ga_em_'  => 'K8b the per-email throttle is still there',
            'wp_check_password' => 'K8c the password is still actually checked',
            'rate_limited' => 'K8d the throttle still refuses with 429' ] as $needle => $label ) {
    is_( strpos( $restSrc, $needle ) !== false, $label );
}
is_( strpos( $restSrc, 'strlen( $password ) < 8' ) !== false,
     'K8e the 8-character minimum is still enforced' );

$plugSrc2 = bare( $FILES['plugin'] );
is_( strpos( $plugSrc2, 'exemptAuthFromBuddyBossRestriction' ) !== false,
     'K9  Plugin.php actually registers it — an unwired filter is a comment' );
is_( substr_count( $plugSrc2, "'bb_exclude_endpoints_from_restriction'" ) === 2,
     'K9b ...as a SECOND filter beside #181\'s, not by widening the first' );

/* ═══ verdict ═════════════════════════════════════════════════════════════ */

echo "\n$pass passed, $fail failed\n";

if ( ! empty( $GLOBALS['H_SKIPPED'] ) ) {
    echo "INCOMPLETE — §H could not run (no local PHP server). Not a pass.\n";
    exit( 3 );
}
if ( $fail > 0 ) {
    echo "RED — the checkout path is not enforcing the cohort. Do not push.\n";
    exit( 1 );
}
echo "GREEN — anon is refused at the API, a cohort member still buys, a pre-minted session\n";
echo "cannot provision, and an already-bridged member still grants AND retracts.\n";
exit( 0 );

}

/*
 * ─── RED-FIRST RECORD ──────────────────────────────────────────────────────
 *
 * Each mutation applied ALONE to correct code, gate run, mutation reverted.
 * Counts are MEASURED, not predicted:
 *
 *     23/23 mutations caught, 2/2 no-op controls stayed green.
 *     Run: python3 tools/gates/checkout-audience-redfirst.py [--only M7]
 *
 * ⚠️ THE RUN EARNED ITS KEEP TWICE, and both findings are worth knowing before
 * editing this file:
 *
 *   - §B8c WAS A REAL BLIND SPOT. It compared the 503 sentence against a 403
 *     whose message WordPress had supplied, so the guard's own two constants
 *     could be made identical and it still passed. §B8d now drives the
 *     fallback path, where the constants are what is actually compared.
 *   - ONE "MUTATION" CHANGED NO DECISION AT ALL. Flipping `if ( ! $user )` to
 *     `if ( false )` still refuses: `$user->ID` on a bool is null, (int)null is
 *     0, and allowsUser(0) is false. The code was right twice over and the gate
 *     was innocent. A mutation that expresses the actual wrong DECISION is the
 *     only kind worth counting — see M5's note in the harness.
 *
 *  M1  CheckoutAudience::state() defaults to 'on' instead of 'allowlist'
 *  M2  ...defaults to 'off'
 *  M3  junk option values fall through to 'on' instead of the safe default
 *  M4  allowsEmail('') returns true (the DoublePayGuard copy-paste)
 *  M5  allowsEmail() falls back to true when no WP user is found
 *  M6  allowsUser() adds `|| current_user_can('manage_options')` (the admin bypass)
 *  M7  the guard returns null on an unknown answer (fail-open)
 *  M8  the guard's 503 reuses the 403 sentence
 *  M9  the provision fence moved ABOVE the existing-bridge early return
 * M10  the provision fence deleted entirely
 * M11  the provision fence warns instead of throwing
 * M12  the Slim guard call removed from CheckoutController
 * M13  the Slim guard call moved BELOW the customer lookup
 * M14  the REST route made conditional on the state (maybeRegister shape)
 * M15  the probe treats 404 as `off` (the sibling's rule, wrong here)
 * M16  the probe accepts an unrecognised state as `on`
 * M17  the BuddyBoss exemption removed
 * M18  the BuddyBoss exemption widened to the whole namespace
 * M19  notifyRefusalOnce loses its transient guard (alert-channel flood)
 * M20  the audience URL gains an 'off' valve
 * M21  a missing comp expiry reads EXPIRED (lapses 12 of 14 live holders)
 * M22  a PAST comp expiry still reads active ("comped forever")
 * M23  an unparseable comp date lapses the member
 *
 * NO-OP CONTROLS (must stay GREEN, or the gate is measuring the wrong thing):
 * N1  reword a comment in CheckoutAudience.php
 * N2  reorder two unrelated assertions in this gate
 */
