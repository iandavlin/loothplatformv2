<?php
/**
 * GATE 76 — the Stripe rail sells MORE THAN ONE tier, and the tier a member
 * receives is the tier they PAID for.
 *
 *   php tools/gates/stripe-multi-tier-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Ian, 2026-08-19: *"I've decided I want to be able to have multiple tiers."*
 * That ruling supersedes the 8/08 one — *"move to ONE tier for the stripe
 * memberships and have ALL tiered content open to the one tier through
 * stripe"* — which is quoted verbatim in StripeLifecycle's docblock and was
 * implemented faithfully as a hardcoded constant. This gate holds the new
 * ruling in place.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * READ THIS BEFORE ADDING AN ASSERTION HERE.
 *
 * The obvious assertion — "a PRO purchase grants looth3" — is a VACUOUS
 * GREEN. `StripeLifecycle::TIER` was already the literal string 'looth3', so
 * that assertion passed on the very defect it was meant to catch: it could
 * not tell a RESOLVED looth3 from a CONSTANT looth3. It is kept below only
 * as the companion half of a pair, never on its own.
 *
 * The assertion that actually bites is **a LITE purchase grants looth2**,
 * because the constant can only ever produce looth3. Every tier assertion in
 * this file is therefore written against the tier the constant is NOT.
 * (feedback-red-first-that-stays-green: an assertion that cannot distinguish
 * the fixed state from the broken one is decoration.)
 *
 * The direction of the defect is worth stating plainly, because it is the
 * opposite of what was expected: nobody was UNDER-granted. A member buying
 * Looth LITE at $5 was granted looth3 — Pro — and it is not additive.
 * `EntitlementRepo::grantMembershipFromSubscription` revokes by source and
 * re-inserts whenever the ref changes, so the constant OVERWRITES a
 * correctly-resolved looth2 entitlement written by the Slim return path.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * WHAT MUST HOLD, in order of what it costs to get wrong:
 *
 *   1. THE GRANT FOLLOWS THE PRICE. A subscription on the LITE price grants
 *      looth2, in the entitlement AND in the lg_role_sources opinion the
 *      Arbiter reads. Paired with the PRO leg so "resolved" is provable.
 *   2. AN UNMAPPED PRICE NEVER RETRACTS. A price our catalogue does not know
 *      falls back to the documented constant and says so LOUDLY in the log.
 *      Over-granting is recoverable; demoting somebody who paid is not.
 *   3. FLAG OFF IS A TOTAL NO-OP. With lgms_multi_tier off the constant is
 *      applied and NO price lookup happens at all — proven by running that
 *      leg against a database with no products/prices tables, so any lookup
 *      is a loud SQL error rather than a silent pass.
 *   4. PER-TIER PRICES DO NOT CROSS-WRITE. Setting LITE's monthly price
 *      leaves PRO's options, the per-cadence legacy options and the original
 *      single legacy option all untouched.
 *   5. THE LEGACY FALLBACK CHAIN SURVIVES. A box configured before tiers
 *      existed keeps selling what it was selling — and a NON-default tier
 *      never borrows the legacy value.
 *   6. THE TIER LIST COMES FROM THE CATALOGUE, not from code. Tier CREATION
 *      stays the catalogue file + import command (Ian's 8/19 scope ruling);
 *      the dash gains pricing only. Regional variants are never offered as
 *      separate tiers.
 *   7. THE DOOR NAMES A TIER, NEVER A PRICE. The checkout body may choose
 *      which tier and cadence, looked up against configured prices. A body
 *      that names a Stripe price id is still ignored entirely.
 *   8. SUPERSEDING A PRICE RETIRES THE OLD ONE — without hiding the members
 *      still billing on it. Two active monthly prices for one tier is two
 *      different prices on the join page for the same thing.
 *
 * Red-first record with measured counts is at the foot of this file.
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
    class Arbiter {
        public static array $calls = [];
        public static function sync( int $uid ): array { self::$calls[] = $uid; return [ 'ok' => true, 'winning_tier' => null ]; }
    }
}

namespace {

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "CANNOT RUN: pdo_sqlite missing\n" ); exit( 3 ); }

$BASE = __DIR__ . '/../../lg-patreon-stripe-poller';
foreach ( [ '/src/StripeLifecycle.php', '/src/StripePrice.php', '/src/Repos/ProductRepo.php',
            '/src/Repos/CustomerRepo.php', '/src/Repos/SubscriptionRepo.php',
            '/src/Repos/EntitlementRepo.php', '/src/Uuid.php', '/src/Wp/IdentityMatcher.php',
            '/src/RoleSourceWriter.php', '/src/Patreon/PatreonSourceReader.php' ] as $f ) {
    if ( ! is_readable( $BASE . $f ) ) { fwrite( STDERR, "CANNOT RUN (RED: build absent): missing {$f}\n" ); exit( 3 ); }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/stub/' ); }

$pass = 0; $fail = 0;
function ok( string $m ): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m, string $d = '' ): void { global $fail; $fail++; echo "  FAIL $m" . ( $d !== '' ? "  ({$d})" : '' ) . "\n"; }
function is_( bool $c, string $m, string $d = '' ): void { $c ? ok( $m ) : bad( $m, $d ); }
function section( string $t ): void { echo "\n$t\n"; }

/* ---- the WordPress surface the code under test actually touches -------- */
$GLOBALS['OPTS'] = []; $GLOBALS['LOG'] = []; $GLOBALS['FIX'] = []; $GLOBALS['TRANSIENTS'] = [];
$GLOBALS['ROUTES'] = []; $GLOBALS['MINTED'] = []; $GLOBALS['NOTIFIED'] = [];

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function update_option( $n, $v, $autoload = null ) { $GLOBALS['OPTS'][ $n ] = $v; return true; }
function get_transient( $n ) { return $GLOBALS['TRANSIENTS'][ $n ] ?? false; }
function set_transient( $n, $v, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $n ] = $v; return true; }
function register_rest_route( $ns, $route, $args = [] ) { $GLOBALS['ROUTES'][] = "{$ns}{$route}"; return true; }
function get_user_by( $field, $id ) {
    if ( $field === 'email' ) {
        foreach ( $GLOBALS['FIX'] as $r ) { if ( $r['email'] === $id ) { return (object) [ 'ID' => $r['uid'], 'roles' => $r['roles'], 'user_email' => $r['email'] ]; } }
        return false;
    }
    $r = $GLOBALS['FIX'][ (int) $id ] ?? null;
    return $r ? (object) [ 'ID' => $r['uid'], 'roles' => $r['roles'], 'user_email' => $r['email'] ] : false;
}
function get_users( $q ) { return []; }
function get_user_meta( $id, $key, $single = false ) {
    $r = $GLOBALS['FIX'][ (int) $id ] ?? null;
    if ( ! $r ) { return ''; }
    if ( $key === 'payment_source' )       { return $r['pay_src'] ?? ''; }
    if ( $key === 'lgpo_patreon_user_id' ) { return $r['pat_id'] ?? ''; }
    return '';
}
function wp_insert_user( $arr ) { $GLOBALS['MINTED'][] = $arr; return 90001; }
function lgpo_notify_failure( $e, $n, $k, $d ) { $GLOBALS['NOTIFIED'][] = [ $k, $e ]; }
function get_bloginfo( $k = '' ) { return 'Test'; }
function home_url( $p = '' ) { return 'https://dev.test' . $p; }
function get_current_user_id() { return $GLOBALS['CURRENT_UID'] ?? 0; }

require_once $BASE . '/vendor/autoload.php';
require_once $BASE . '/src/Uuid.php';
require_once $BASE . '/src/Patreon/PatreonSourceReader.php';
require_once $BASE . '/src/RoleSourceWriter.php';
require_once $BASE . '/src/Repos/CustomerRepo.php';
require_once $BASE . '/src/Repos/SubscriptionRepo.php';
require_once $BASE . '/src/Repos/EntitlementRepo.php';
require_once $BASE . '/src/Repos/ProductRepo.php';
require_once $BASE . '/src/Wp/IdentityMatcher.php';
require_once $BASE . '/src/StripeLifecycle.php';
require_once $BASE . '/src/StripePrice.php';

use LGMS\StripeLifecycle;
use LGMS\StripePrice;

/** SQLite shim for the MySQL-isms the repos issue. */
final class TestPdo extends PDO {
    public function prepare( string $q, array $o = [] ): PDOStatement|false {
        $q = preg_replace( '/NOW\(\)/i', 'CURRENT_TIMESTAMP', $q );
        if ( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false ) {
            if ( stripos( $q, 'lg_processed_events' ) !== false ) {
                $q = preg_replace( '/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(event_id) DO UPDATE SET', $q );
            } elseif ( stripos( $q, 'subscriptions' ) !== false ) {
                $q = preg_replace( '/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(stripe_subscription_id) DO UPDATE SET', $q );
            } else {
                $q = preg_replace( '/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(wp_user_id, source) DO UPDATE SET', $q );
            }
            $q = preg_replace( '/VALUES\s*\(\s*(\w+)\s*\)/i', 'excluded.$1', $q );
        }
        return parent::prepare( $q, $o );
    }
    public function exec( $q ): int|false { return parent::exec( preg_replace( '/NOW\(\)/i', 'CURRENT_TIMESTAMP', $q ) ); }
}

const LITE_MONTH = 'price_LITE_MONTH';
const LITE_YEAR  = 'price_LITE_YEAR';
const PRO_MONTH  = 'price_PRO_MONTH';
const PRO_YEAR   = 'price_PRO_YEAR';

/**
 * The lifecycle rig. $withCatalogue = false builds a database with NO
 * products/prices tables at all — that is how §3 proves the OFF path performs
 * no price lookup: any lookup is a hard SQL error, not a silent pass.
 */
function rig( bool $withCatalogue = true ): PDO {
    $pdo = new TestPdo( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->exec( 'CREATE TABLE lg_role_sources (wp_user_id INTEGER, source TEXT, tier TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (wp_user_id, source))' );
    $pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE, synced_at TEXT)' );
    $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, stripe_customer_id TEXT, email TEXT UNIQUE, name TEXT, country TEXT, metadata TEXT, deleted_at TEXT)' );
    $pdo->exec( 'CREATE TABLE subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, stripe_subscription_id TEXT UNIQUE, stripe_price_id TEXT, status TEXT, cancel_at_period_end INTEGER, current_period_start TEXT, current_period_end TEXT, canceled_at TEXT)' );
    $pdo->exec( 'CREATE TABLE entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, customer_id INTEGER, kind TEXT, ref TEXT, source_type TEXT, source_id INTEGER, revoked_at TEXT, starts_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT)' );
    $pdo->exec( 'CREATE TABLE lg_processed_events (event_id TEXT PRIMARY KEY, first_seen_at TEXT, last_seen_at TEXT, dup_count INTEGER DEFAULT 0)' );
    $pdo->exec( 'CREATE TABLE lg_lifecycle_journal (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT, event_type TEXT, wp_user_id INTEGER, customer_id INTEGER, source TEXT DEFAULT \'stripe\', tier_before TEXT, had_row INTEGER, tier_after TEXT, state TEXT, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)' );

    if ( $withCatalogue ) { seed_catalogue( $pdo ); }

    $GLOBALS['PDO'] = $pdo;
    $GLOBALS['OPTS'] = []; $GLOBALS['LOG'] = []; $GLOBALS['TRANSIENTS'] = [];
    $GLOBALS['FIX'] = [
        501 => [ 'uid' => 501, 'email' => 'bridged@x.test', 'roles' => [ 'looth1' ], 'pat_id' => '' ],
    ];
    \LGMS\Db::$calls = 0;
    \LGMS\Arbiter::$calls = [];
    StripeLifecycle::_resetForTests();
    return $pdo;
}

/** Both tiers, both cadences, plus a regional variant that must never be offered. */
function seed_catalogue( PDO $pdo ): void {
    $pdo->exec( 'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, stripe_product_id TEXT UNIQUE, kind TEXT, ref TEXT, region_tag TEXT, name TEXT, active INTEGER DEFAULT 1)' );
    $pdo->exec( 'CREATE TABLE prices (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, stripe_price_id TEXT UNIQUE, type TEXT, "interval" TEXT, unit_amount_cents INTEGER, currency TEXT, active INTEGER DEFAULT 1)' );
    $pdo->exec( "INSERT INTO products (stripe_product_id, kind, ref, region_tag, name, active) VALUES
        ('prod_LITE', 'membership', 'looth2', NULL, 'Looth LITE', 1),
        ('prod_PRO',  'membership', 'looth3', NULL, 'Looth PRO',  1),
        ('prod_PRO_RA','membership','looth3','regional_a','Looth PRO — Regional A', 1),
        ('prod_RONLY','membership', 'looth5', 'regional_a', 'Regional-only tier', 1),
        ('prod_OLD',  'membership', 'looth2', NULL, 'Retired LITE', 0)" );
    $pdo->exec( "INSERT INTO prices (product_id, stripe_price_id, type, \"interval\", unit_amount_cents, currency, active) VALUES
        (1, '" . LITE_MONTH . "', 'recurring', 'month',  500, 'usd', 1),
        (1, '" . LITE_YEAR  . "', 'recurring', 'year',  6000, 'usd', 1),
        (2, '" . PRO_MONTH  . "', 'recurring', 'month', 1100, 'usd', 1),
        (2, '" . PRO_YEAR   . "', 'recurring', 'year', 13200, 'usd', 1)" );
}

function lifecycle_on( bool $multiTier ): void {
    $GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
    $GLOBALS['OPTS']['lgms_identity_gate']    = true;
    $GLOBALS['OPTS'][ StripeLifecycle::ALLOWLIST_OPT ] = [ 501 ];
    if ( $multiTier ) { $GLOBALS['OPTS']['lgms_multi_tier'] = '1'; }
}

function sub_obj( string $subId, string $cus, string $status, string $price ): array {
    return [
        'id' => $subId, 'object' => 'subscription', 'customer' => $cus, 'status' => $status,
        'items' => [ 'data' => [ [ 'price' => [ 'id' => $price ] ] ] ],
        'cancel_at_period_end' => false,
        'current_period_start' => 1754000000, 'current_period_end' => 1756700000,
        'canceled_at' => null,
    ];
}

function confirm_with( array $map ): void {
    StripeLifecycle::$confirmFactory = static function () use ( $map ): object {
        return new class( $map ) {
            public function __construct( private array $map ) {}
            public function retrieveSubscription( string $id, array $expand = [] ): object {
                $hit = $this->map[ $id ] ?? null;
                if ( $hit === null ) { throw new RuntimeException( "no such subscription {$id}" ); }
                return json_decode( json_encode( $hit ) );
            }
        };
    };
}

function seed_member( string $email, string $cus ): int {
    $GLOBALS['PDO']->prepare( 'INSERT INTO customers (uuid, stripe_customer_id, email) VALUES (?,?,?)' )
        ->execute( [ bin2hex( random_bytes( 8 ) ), $cus, $email ] );
    $cid = (int) $GLOBALS['PDO']->lastInsertId();
    $GLOBALS['PDO']->prepare( 'INSERT INTO wp_user_bridge (customer_id, wp_user_id) VALUES (?,?)' )->execute( [ $cid, 501 ] );
    return $cid;
}

/** Drive one subscription event all the way through the lifecycle. */
function buy( string $price, string $subId = 'sub_gate' ): array {
    $cus = 'cus_gate';
    seed_member( 'bridged@x.test', $cus );
    $obj = sub_obj( $subId, $cus, 'active', $price );
    confirm_with( [ $subId => $obj ] );
    $event = json_decode( json_encode( [
        'id' => 'evt_' . $subId, 'object' => 'event', 'type' => 'customer.subscription.created',
        'data' => [ 'object' => $obj ],
    ] ) );
    return StripeLifecycle::handleEvent( $event );
}

function entitlement_ref(): ?string {
    $v = $GLOBALS['PDO']->query( "SELECT ref FROM entitlements WHERE kind='membership_tier' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1" )->fetchColumn();
    return $v === false ? null : (string) $v;
}
function opinion_tier( int $uid = 501 ): string {
    $st = $GLOBALS['PDO']->prepare( "SELECT tier, COUNT(*) AS n FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
    $st->execute( [ $uid ] );
    $r = $st->fetch( PDO::FETCH_ASSOC );
    return (int) $r['n'] === 0 ? 'ABSENT' : (string) ( $r['tier'] ?? 'NULL' );
}
function log_mentions( string $needle ): bool {
    foreach ( $GLOBALS['LOG'] as $l ) { if ( stripos( $l, $needle ) !== false ) { return true; } }
    return false;
}

echo "=== GATE 76 — Stripe multi-tier: the tier granted is the tier paid for ===\n";

/* ══ §1 THE GRANT FOLLOWS THE PRICE ═══════════════════════════════════════ */
section( '§1 the grant follows the price (the constant can only ever say looth3)' );

rig(); lifecycle_on( true );
buy( LITE_MONTH );
is_( entitlement_ref() === 'looth2',
     'a LITE subscription grants a looth2 ENTITLEMENT',
     'got ' . var_export( entitlement_ref(), true ) );
is_( opinion_tier() === 'looth2',
     "...and writes looth2 as the 'stripe' opinion the Arbiter reads",
     'got ' . opinion_tier() );

rig(); lifecycle_on( true );
buy( LITE_YEAR );
is_( entitlement_ref() === 'looth2', 'the YEARLY LITE price grants looth2 too — cadence is not tier' );

// The companion half. On its own this is vacuous (see the header); it earns its
// place only next to the looth2 legs above, where together they prove the tier
// is RESOLVED rather than constant.
rig(); lifecycle_on( true );
buy( PRO_MONTH );
is_( entitlement_ref() === 'looth3', 'a PRO subscription still grants looth3' );
is_( opinion_tier() === 'looth3', "...with the matching 'stripe' opinion" );

/* ══ §2 AN UNMAPPED PRICE NEVER RETRACTS ═════════════════════════════════ */
section( '§2 an unmapped price falls back to the constant, loudly — never to nothing' );

rig(); lifecycle_on( true );
buy( 'price_NOT_IN_OUR_CATALOGUE' );
is_( entitlement_ref() === StripeLifecycle::TIER,
     'an unknown price grants the documented constant rather than nothing',
     'got ' . var_export( entitlement_ref(), true ) );
is_( opinion_tier() === StripeLifecycle::TIER, '...and the opinion is written, not withheld' );
is_( log_mentions( 'price_NOT_IN_OUR_CATALOGUE' ),
     '...and the unmapped price id is NAMED in the log' );

/* ══ §3 FLAG OFF IS A TOTAL NO-OP ════════════════════════════════════════ */
section( '§3 lgms_multi_tier OFF: the constant, and NO price lookup at all' );

// No products/prices tables exist in this rig on purpose. If the OFF path ever
// consults a price map the SQL fails loudly instead of passing quietly.
rig( false ); lifecycle_on( false );
$threw = '';
try { buy( LITE_MONTH ); } catch ( Throwable $e ) { $threw = $e->getMessage(); }
is_( $threw === '', 'the OFF path touches no products/prices table', $threw );
is_( entitlement_ref() === StripeLifecycle::TIER,
     '...and grants exactly the constant it always did',
     'got ' . var_export( entitlement_ref(), true ) );
// THE ASSERTION THAT ACTUALLY CATCHES A LOOKUP, and it is not the one above.
// tierFor() wraps the catalogue read in a try/catch — deliberately, so an
// unreadable catalogue keeps the membership instead of retracting it — which
// means a lookup on the OFF path is SWALLOWED and "nothing threw" stays true.
// Mutation M2 (flag guard removed) came back GREEN against exactly that.
// The catch is not silent though: it logs. So the observable proof that the
// OFF path never even TRIED is that it says nothing about multi-tier at all.
is_( ! log_mentions( 'multi-tier' ),
     '...and says nothing about multi-tier — it never even tried the lookup',
     implode( ' | ', $GLOBALS['LOG'] ) );

rig( false );
$GLOBALS['OPTS']['lgms_multi_tier'] = '';   // present but empty reads OFF
lifecycle_on( false );
$threw = '';
try { buy( LITE_MONTH ); } catch ( Throwable $e ) { $threw = $e->getMessage(); }
is_( $threw === '' && entitlement_ref() === StripeLifecycle::TIER,
     'an empty flag value reads OFF, not ON', $threw );

/* ══ §4 PER-TIER PRICES DO NOT CROSS-WRITE ═══════════════════════════════ */
section( '§4 setting one tier’s price leaves every other option untouched' );

final class FakeStripe {
    public static string $nextId = 'price_NEW';
    public static array $lastParams = [];
    public function createPrice( array $p ): object { self::$lastParams = $p; return (object) [ 'id' => self::$nextId ]; }
}

function price_rig(): PDO {
    $pdo = new TestPdo( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
    $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, stripe_customer_id TEXT, email TEXT, name TEXT, deleted_at TEXT)' );
    $pdo->exec( 'CREATE TABLE subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, stripe_subscription_id TEXT, stripe_price_id TEXT, status TEXT)' );
    $pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE, synced_at TEXT)' );
    $pdo->exec( 'CREATE TABLE pending_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT, wp_user_id INTEGER, created_at TEXT)' );
    seed_catalogue( $pdo );
    $GLOBALS['PDO'] = $pdo;
    $GLOBALS['LOG'] = [];
    $GLOBALS['OPTS'] = [ 'lgms_stripe_secret_key' => 'sk_test_abc', 'lgms_multi_tier' => '1' ];
    FakeStripe::$nextId = 'price_NEW';
    StripePrice::$clientFactory = static fn (): object => new FakeStripe();
    return $pdo;
}

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] = 'price_PRO_KEEP';
$GLOBALS['OPTS']['lgms_stripe_price_month']        = 'price_LEGACY_CADENCE';
$GLOBALS['OPTS']['lgms_stripe_price_id']           = 'price_LEGACY_SINGLE';
FakeStripe::$nextId = 'price_LITE_NEW';
$err = '';
try { StripePrice::setPrice( 500, 'month', 'looth2' ); } catch ( Throwable $e ) { $err = $e->getMessage(); }
is_( $err === '', 'setPrice accepts a tier', $err );
is_( ( $GLOBALS['OPTS']['lgms_stripe_price_looth2_month'] ?? '' ) === 'price_LITE_NEW',
     "LITE's monthly option is the one that moved" );
is_( ( $GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] ?? '' ) === 'price_PRO_KEEP',
     "...PRO's monthly option did NOT move" );
is_( ( $GLOBALS['OPTS']['lgms_stripe_price_month'] ?? '' ) === 'price_LEGACY_CADENCE',
     '...the per-cadence legacy option did NOT move' );
is_( ( $GLOBALS['OPTS']['lgms_stripe_price_id'] ?? '' ) === 'price_LEGACY_SINGLE',
     '...the original single legacy option did NOT move' );
is_( ( FakeStripe::$lastParams['product'] ?? '' ) === 'prod_LITE',
     "...and the price was created under LITE's product, not PRO's",
     (string) ( FakeStripe::$lastParams['product'] ?? 'none' ) );

/* ══ §5 THE LEGACY FALLBACK CHAIN ════════════════════════════════════════ */
section( '§5 a box configured before tiers existed keeps selling what it sold' );

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_month'] = 'price_LEGACY_CADENCE';
is_( StripePrice::currentPriceId( 'month', 'looth3' ) === 'price_LEGACY_CADENCE',
     'the DEFAULT tier falls back to the per-cadence legacy option' );
is_( StripePrice::currentPriceId( 'month', 'looth2' ) === '',
     '...and a NON-default tier never borrows it' );

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_id'] = 'price_LEGACY_SINGLE';
is_( StripePrice::currentPriceId( 'month', 'looth3' ) === 'price_LEGACY_SINGLE',
     'the default tier still falls all the way back to the original single option' );
is_( StripePrice::currentPriceId( 'year', 'looth3' ) === '',
     '...which never answers for yearly' );

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] = 'price_EXPLICIT';
$GLOBALS['OPTS']['lgms_stripe_price_month']        = 'price_LEGACY_CADENCE';
is_( StripePrice::currentPriceId( 'month', 'looth3' ) === 'price_EXPLICIT',
     'an explicit per-tier price wins over both legacy options' );

/* ══ §6 THE TIER LIST COMES FROM THE CATALOGUE ═══════════════════════════ */
section( '§6 the dash offers the tiers the CATALOGUE holds — creation is not a dash job' );

price_rig();
$tiers = StripePrice::tiers();
is_( $tiers === [ 'looth2', 'looth3' ],
     'both active membership tiers are offered, in order',
     json_encode( $tiers ) );
is_( count( $tiers ) === count( array_unique( $tiers ) ),
     '...a regional variant does not appear as a SECOND looth3' );
// THE ONE THAT ACTUALLY CATCHES A DROPPED region_tag FILTER. The query selects
// DISTINCT ref, so a regional variant of a tier that ALSO has a standard
// product collapses into the same row and the two assertions above stay green
// with the filter removed — caught by mutation M10, which came back GREEN
// against an earlier version of this section. `looth5` in the rig exists ONLY
// as a regional product, so it can only appear if the filter is gone.
is_( ! in_array( 'looth5', $tiers, true ),
     '...and a tier that exists ONLY as a regional product is NOT offered',
     json_encode( $tiers ) );
// The sharper consequence of the same filter: tierProduct() must name exactly
// one product, and including regional variants makes looth3 ambiguous.
$tpErr = '';
try { $tp = StripePrice::tierProduct( 'looth3' ); } catch ( Throwable $e ) { $tpErr = $e->getMessage(); $tp = []; }
is_( $tpErr === '' && ( $tp['stripe_product_id'] ?? '' ) === 'prod_PRO',
     '...and the STANDARD product is the one a price hangs off, unambiguously',
     $tpErr !== '' ? $tpErr : (string) ( $tp['stripe_product_id'] ?? 'none' ) );

price_rig();
$GLOBALS['PDO']->exec( "INSERT INTO products (stripe_product_id, kind, ref, region_tag, name, active)
                        VALUES ('prod_ELITE','membership','looth4',NULL,'Looth ELITE',1)" );
is_( StripePrice::tiers() === [ 'looth2', 'looth3', 'looth4' ],
     'a tier added to the catalogue is offered with NO code change' );

/* ══ §7 THE DOOR NAMES A TIER, NEVER A PRICE ═════════════════════════════ */
section( '§7 the checkout door chooses a tier + cadence, never a price id' );

require_once $BASE . '/src/Membership/PatreonStanding.php';
require_once $BASE . '/src/Wp/RestController.php';
require_once $BASE . '/src/Wp/CheckoutRestController.php';
$CA_FILE = dirname( __DIR__, 2 ) . '/lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php';
// ⚠️ #181 COUPLING, DECLARED RATHER THAN WORKED AROUND. `CheckoutRestController`
// now asks LGMS\Membership\CheckoutAudience whether the signed-in member may
// buy at all, and that option DEFAULTS TO `allowlist` — enforcing — so without
// the two lines below every assertion in this section would get a 403 from a
// fence this gate is not about. The real class is loaded (not stubbed) so the
// coupling cannot drift silently, and pinned to `off` because THIS gate's
// subject is which TIER a price sells, not who is invited. Gate 86 owns the audience
// in all three states, including the 403 at this very door.
require_once $CA_FILE;


class WP_REST_Request {
    public function __construct( private array $body = [] ) {}
    public function get_param( $k ) { return $this->body[ $k ] ?? null; }
    public function get_json_params() { return $this->body; }
}
class WP_REST_Response {
    public function __construct( public $data = null, public int $status = 200 ) {}
    public function get_status(): int { return $this->status; }
    public function get_data() { return $this->data; }
}

final class FakeCheckoutStripe {
    public static array $last = [];
    public function createCheckoutSession( array $p ): object {
        self::$last = $p;
        return (object) [ 'id' => 'cs_test_1', 'url' => 'https://stripe.test/cs_test_1' ];
    }
}

function door( array $body ): WP_REST_Response {
    // Pinned HERE, not at module scope: the per-case reset above blanks
    // $GLOBALS['OPTS'], so a pin set once is silently gone by the first
    // assertion — which is exactly how this read as four unrelated failures.
    $GLOBALS['OPTS'][ \LGMS\Membership\CheckoutAudience::OPT ] = 'off';
    $GLOBALS['CURRENT_UID'] = 501;
    \LGMS\Wp\CheckoutRestController::$clientFactory = static fn (): object => new FakeCheckoutStripe();
    return \LGMS\Wp\CheckoutRestController::createSession( new WP_REST_Request( $body ) );
}

/** The price id the door actually put in front of the member. */
function door_price(): string {
    $items = FakeCheckoutStripe::$last['line_items'] ?? [];
    return (string) ( $items[0]['price'] ?? '' );
}

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_looth2_month'] = LITE_MONTH;
$GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] = PRO_MONTH;
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
door( [ 'tier' => 'looth2', 'cadence' => 'month' ] );
is_( door_price() === LITE_MONTH,
     'asking for looth2 sells the LITE price', door_price() );

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_looth2_month'] = LITE_MONTH;
$GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] = PRO_MONTH;
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
door( [ 'tier' => 'looth3', 'cadence' => 'month' ] );
is_( door_price() === PRO_MONTH, 'asking for looth3 sells the PRO price', door_price() );

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] = PRO_MONTH;
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
door( [ 'tier' => 'looth3', 'cadence' => 'month', 'price_id' => 'price_CHOSEN_BY_THE_CLIENT' ] );
is_( door_price() === PRO_MONTH,
     'a price id in the BODY is still ignored entirely', door_price() );

price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] = PRO_MONTH;
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$r = door( [ 'tier' => 'looth9', 'cadence' => 'month' ] );
is_( $r->get_status() === 400, 'an unknown tier is refused, not guessed', (string) $r->get_status() );

// Flag OFF: the tier param is inert and the default tier is sold.
price_rig();
unset( $GLOBALS['OPTS']['lgms_multi_tier'] );
$GLOBALS['OPTS']['lgms_stripe_price_looth2_month'] = LITE_MONTH;
$GLOBALS['OPTS']['lgms_stripe_price_month']        = PRO_MONTH;
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
door( [ 'tier' => 'looth2', 'cadence' => 'month' ] );
is_( door_price() === PRO_MONTH,
     'with the flag OFF a tier in the body changes nothing', door_price() );

/* ══ §8 SUPERSEDING A PRICE RETIRES THE OLD ONE ══════════════════════════ */
section( '§8 a superseded price stops being offered — without hiding its members' );

$pdo = price_rig();
$GLOBALS['OPTS']['lgms_stripe_price_looth3_month'] = PRO_MONTH;
// AN UNPOINTED COMPETING PRICE — the case that makes this section bite.
// Retiring only the price the OPTION names looks correct and is not: dev2
// carried exactly this shape (options naming prices 30/31 while 11/12 sat
// active and unpointed from an earlier round), so a pointer-only retirement
// would have left a second monthly button standing.
$pdo->exec( "INSERT INTO prices (product_id, stripe_price_id, type, \"interval\", unit_amount_cents, currency, active)
             VALUES (2, 'price_PRO_MONTH_ORPHAN', 'recurring', 'month', 1500, 'usd', 1)" );
// A one-time price must NOT be swept — a different product shape, not a rhythm.
$pdo->exec( "INSERT INTO prices (product_id, stripe_price_id, type, \"interval\", unit_amount_cents, currency, active)
             VALUES (2, 'price_PRO_LIFETIME', 'one_time', NULL, 14500, 'usd', 1)" );
// A member already billing on the price that is about to be superseded.
$pdo->exec( "INSERT INTO customers (email) VALUES ('payer@x.test')" );
$cid = (int) $pdo->lastInsertId();
$pdo->prepare( "INSERT INTO subscriptions (customer_id, stripe_subscription_id, stripe_price_id, status) VALUES (?,?,?,'active')" )
    ->execute( [ $cid, 'sub_existing', PRO_MONTH ] );

FakeStripe::$nextId = 'price_PRO_CHEAPER';
$err = '';
try { StripePrice::setPrice( 900, 'month', 'looth3' ); } catch ( Throwable $e ) { $err = $e->getMessage(); }
is_( $err === '', 'a replacement price is set', $err );

$stillActive = $pdo->query( "SELECT COUNT(*) AS n FROM prices WHERE product_id = 2 AND \"interval\" = 'month' AND type = 'recurring' AND active = 1" )->fetch()['n'] ?? 0;
is_( (int) $stillActive === 1,
     'exactly ONE monthly price remains active for the tier',
     "got {$stillActive}" );
$orphanGone = $pdo->query( "SELECT active FROM prices WHERE stripe_price_id = 'price_PRO_MONTH_ORPHAN'" )->fetch()['active'] ?? 1;
is_( (int) $orphanGone === 0,
     '...including one no option ever pointed at — the sweep is by rhythm, not by pointer' );
$lifetime = $pdo->query( "SELECT active FROM prices WHERE stripe_price_id = 'price_PRO_LIFETIME'" )->fetch()['active'] ?? 0;
is_( (int) $lifetime === 1,
     '...while a ONE-TIME price is left alone — a different shape, not a competing rhythm' );
$yearly = $pdo->query( "SELECT active FROM prices WHERE stripe_price_id = '" . PRO_YEAR . "'" )->fetch()['active'] ?? 0;
is_( (int) $yearly === 1,
     "...and the tier's YEARLY price is untouched by a monthly change" );

// The join page's own query shape (membership-pages/web/lgjoin.php:49) — it
// joins `prices` with NO active filter, so a retired price must not make an
// existing subscriber vanish. That vanishing IS the double-charge shape.
$st = $pdo->prepare(
    "SELECT s.stripe_price_id, p.ref AS tier
       FROM subscriptions s
       JOIN prices   pr ON pr.stripe_price_id = s.stripe_price_id
       JOIN products  p ON p.id = pr.product_id
      WHERE s.customer_id = ? AND s.status IN ('active','trialing','past_due')
      ORDER BY s.id DESC LIMIT 1"
);
$st->execute( [ $cid ] );
is_( ( $st->fetch()['tier'] ?? '' ) === 'looth3',
     '...and the member still billing on the retired price is STILL FOUND' );

is_( \LGMS\Repos\ProductRepo::tierForPrice( PRO_MONTH ) === 'looth3',
     '...and their tier still resolves from the retired price' );

/* ─────────────────────────────────────────────────────────────────────── */
echo "\n";
printf( "%d passed, %d failed\n", $pass, $fail );
if ( $fail > 0 ) { echo "GATE 76 RED\n"; exit( 1 ); }
echo "GATE 76 GREEN\n";
exit( 0 );

}

/* ═══════════════════════════════════════════════════════════════════════════
 * RED-FIRST RECORD
 *
 * FIRST RUN, before any fix (the whole reason this file was written first):
 *
 *   FAIL a LITE subscription grants a looth2 ENTITLEMENT      (got 'looth3')
 *   FAIL ...and writes looth2 as the 'stripe' opinion          (got  looth3)
 *   FAIL the YEARLY LITE price grants looth2 too
 *   ok   a PRO subscription still grants looth3       <-- THE VACUOUS GREEN
 *   FAIL ...and the unmapped price id is NAMED in the log
 *   FAIL LITE's monthly option is the one that moved
 *   FAIL ...and the price was created under LITE's product     (got prod_PRO)
 *   FAIL ...a NON-default tier never borrows the legacy option
 *   Fatal: StripePrice::tiers() does not exist
 *
 * That `ok` line is the finding, not a pass: StripeLifecycle::TIER was already
 * the literal 'looth3', so the obvious assertion could not tell a RESOLVED
 * looth3 from a CONSTANT one and sailed through the defect. Keep every tier
 * assertion pointed at the tier the constant is NOT.
 *
 * MUTATION RUN — 10 mutations, each applied to a fresh SNAPSHOT COPY of the
 * tree, never to the working tree and never reverted with `git checkout --`
 * (feedback-mutation-harness-must-snapshot-not-checkout). Each reddened its
 * NAMED assertion:
 *
 *   M1  grant reverted to the constant .......... §1 LITE legs
 *   M2  flag guard removed from tierFor ......... §3 "says nothing about multi-tier"
 *   M3  unmapped-price log line deleted ......... §2 "the price id is NAMED"
 *   M4  unmapped price retracts to looth1 ....... §2 "grants the constant, not nothing"
 *   M5  fallback chain opened to other tiers .... §5 "a NON-default tier never borrows it"
 *   M6  per-tier option key collapsed ........... §4 cross-write legs
 *   M7  superseded price no longer retired ...... §8 "exactly ONE monthly price" (got 2)
 *   M8  door stops refusing an unknown tier ..... §7 "refused, not guessed"    (got 503)
 *   M9  door reads tier with the flag OFF ....... §7 "flag OFF changes nothing"
 *   M10 tiers() stops excluding regional ........ §6 regional-only tier leg
 *
 * TWO OF THOSE CAME BACK GREEN THE FIRST TIME AND WERE GATE HOLES, NOT
 * HARNESS NOISE. Both are now closed, and both are worth knowing about
 * before editing this file:
 *
 *   M10 — tiers() selects DISTINCT ref, so a regional variant of a tier that
 *   ALSO has a standard product collapses into the same row: dropping the
 *   `region_tag IS NULL` filter changed nothing the assertions could see. The
 *   rig now carries `looth5`, which exists ONLY as a regional product, so the
 *   filter's removal has somewhere to show up.
 *
 *   M2 — tierFor() wraps the catalogue read in a try/catch on purpose (an
 *   unreadable catalogue must keep the membership, not retract it), which
 *   means a lookup on the OFF path is SWALLOWED and "nothing threw" stays
 *   true. The absence assertion was therefore unfalsifiable. The catch is not
 *   silent though — it logs — so the OFF leg now asserts the log says nothing
 *   about multi-tier at all: proof it never even tried.
 *
 * TWO NO-OP MUTATIONS confirmed to redden NOTHING, so the gate is neither
 * always-red nor keyed on prose: a reworded comment (N1) and a renamed local
 * variable (N2) both stay GREEN.
 * ═══════════════════════════════════════════════════════════════════════════ */
