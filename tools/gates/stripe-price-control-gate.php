<?php
/**
 * GATE 34c — setting the membership price is ONE action, or it is no action.
 *
 *   php tools/gates/stripe-price-control-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Ian, 2026-08-15: *"I'd like to be able to set the price. In the dash."* This
 * gates the control that does it (LGMS\StripePrice + the Stripe Price tab).
 *
 * THE DEFECT THIS EXISTS FOR, because it is not the obvious one. Setting a
 * price is three writes: create it in Stripe, record it in our own `prices`
 * table, and point new joins at it. Only the middle one looks optional, and it
 * is the one that costs money:
 *
 *   `membership-pages/web/lgjoin.php` answers "do you already have a
 *   subscription?" with an INNER JOIN through `prices`. A price Stripe knows
 *   about and we do not makes an existing subscriber's subscription VANISH
 *   from that query — so an already-paying member is shown the join flow
 *   again. Measured on dev2 against the real database: the same lookup
 *   returns 4 rows with the price present and 0 without. Nothing back-fills
 *   it, either — the lifecycle webhook handles checkout and subscription
 *   events only, never price.created.
 *
 * So §3 does not check that a row was written. It runs the JOIN PAGE'S OWN
 * QUERY SHAPE against a subscription on the new price and asserts the member is
 * still found — the defect as the member would meet it.
 *
 * WHAT MUST HOLD, in order of what it costs to get wrong:
 *
 *   1. SANDBOX ONLY (lane charter rule 1) — a live secret key REFUSES. Ian
 *      takes the account out of sandbox himself, at cutover.
 *   2. ALL THREE WRITES OR NONE — if our own row cannot be written, new joins
 *      are NOT repointed and the failure is loud. A half-applied price change
 *      is the double-charge shape above.
 *   3. THE MEMBER STAYS VISIBLE — the join page's query still finds a
 *      subscriber on the newly set price.
 *   4. EXISTING SUBSCRIBERS ARE GRANDFATHERED — setting a price changes what
 *      the NEXT person pays and touches no existing subscription row.
 *   5. IT SHIPS WITH NO PRICE SET — the decision is Ian's, and until he makes
 *      it the option is unset and checkout refuses rather than guessing.
 *   6. THE AMOUNT IS WHAT WAS TYPED — junk, silly-small and silly-large are
 *      refused before anything reaches Stripe, because a Stripe price cannot
 *      be deleted once created.
 *
 * Red-first record with measured counts is at the foot of this file.
 */

declare(strict_types=1);

namespace LGMS {
    class Db {
        public static function pdo() { return $GLOBALS['PDO']; }
    }
    class Log {
        public static function line( string $m, string $n = 'tick.log' ): void { $GLOBALS['LOG'][] = rtrim( $m, "\n" ); }
    }
}

namespace {

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "CANNOT RUN: pdo_sqlite missing\n" ); exit( 3 ); }

$BASE = __DIR__ . '/../../lg-patreon-stripe-poller';
foreach ( [ '/src/StripeLifecycle.php', '/src/StripePrice.php', '/src/Repos/ProductRepo.php' ] as $f ) {
    if ( ! is_readable( $BASE . $f ) ) { fwrite( STDERR, "CANNOT RUN: missing $f\n" ); exit( 3 ); }
}

$pass = 0; $fail = 0;
function ok( string $m ): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function section( string $t ): void { echo "\n$t\n"; }

/* ---- the WordPress surface StripePrice actually touches ---------------- */
$GLOBALS['OPTIONS'] = [];
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $n ] : $d; }
function update_option( $n, $v, $autoload = null ) { $GLOBALS['OPTIONS'][ $n ] = $v; return true; }

require $BASE . '/src/StripeLifecycle.php';
require $BASE . '/src/StripePrice.php';
require $BASE . '/src/Repos/ProductRepo.php';

use LGMS\StripePrice;

/** A Stripe client that never reaches Stripe. */
final class FakeStripe
{
    public static int $created = 0;
    public static array $lastParams = [];
    public static ?string $throw = null;
    public static string $nextId = 'price_TEST_NEW';

    public function createPrice( array $params ): object
    {
        if ( self::$throw !== null ) { throw new RuntimeException( self::$throw ); }
        self::$created++;
        self::$lastParams = $params;
        return (object) [ 'id' => self::$nextId ];
    }
}

/** A fresh catalogue + a paying member, for every scenario. */
function rig( array $opts = [] ): PDO
{
    $pdo = new PDO( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
    $pdo->exec( 'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, stripe_product_id TEXT UNIQUE, kind TEXT, ref TEXT, region_tag TEXT, name TEXT, active INTEGER DEFAULT 1)' );
    $pdo->exec( 'CREATE TABLE prices (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, stripe_price_id TEXT UNIQUE, type TEXT, "interval" TEXT, unit_amount_cents INTEGER, currency TEXT, active INTEGER DEFAULT 1)' );
    $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, deleted_at TEXT)' );
    $pdo->exec( 'CREATE TABLE subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, stripe_subscription_id TEXT, stripe_price_id TEXT, status TEXT)' );

    // The single tier's standard product — what dev2 really holds.
    $pdo->exec( "INSERT INTO products (stripe_product_id, kind, ref, region_tag, name, active)
                 VALUES ('prod_LOOTH3', 'membership', 'looth3', NULL, 'Looth PRO', 1)" );
    // Regional variants of the SAME tier must never be picked by the resolver.
    $pdo->exec( "INSERT INTO products (stripe_product_id, kind, ref, region_tag, name, active)
                 VALUES ('prod_LOOTH3_RA', 'membership', 'looth3', 'regional_a', 'Looth PRO — Regional A', 1)" );

    $GLOBALS['PDO']     = $pdo;
    $GLOBALS['LOG']     = [];
    $GLOBALS['OPTIONS'] = array_merge( [ 'lgms_stripe_secret_key' => 'sk_test_abc' ], $opts );
    FakeStripe::$created = 0; FakeStripe::$throw = null; FakeStripe::$nextId = 'price_TEST_NEW';
    StripePrice::$clientFactory = static fn (): object => new FakeStripe();
    return $pdo;
}

/** The join page's own query shape (membership-pages/web/lgjoin.php:49). */
function joinPageSeesSubscription( PDO $pdo, int $customerId ): bool
{
    $st = $pdo->prepare(
        'SELECT s.stripe_price_id, p.ref AS tier, pr.unit_amount_cents
           FROM subscriptions s
           JOIN prices   pr ON pr.stripe_price_id = s.stripe_price_id
           JOIN products  p ON p.id = pr.product_id
          WHERE s.customer_id = ? AND s.status IN (\'active\',\'trialing\',\'past_due\')
          ORDER BY s.id DESC LIMIT 1'
    );
    $st->execute( [ $customerId ] );
    return $st->fetch() !== false;
}

/**
 * setPrice() where success is EXPECTED.
 *
 * An unexpected throw here must be a counted FAIL, not a fatal: a gate that
 * dies mid-run prints no FAIL line at all, so the suite reports "something went
 * wrong" without ever naming the assertion — the failure mode already on record
 * for gate 2. Proven by mutation P7, which threw from tierProduct() and killed
 * the run instead of reddening a line.
 *
 * @return array{}|array{stripe_price_id:string,unit_amount_cents:int,interval:string,product_name:string}
 */
function mustSet( int $cents, string $interval, string $why ): array
{
    try {
        return \LGMS\StripePrice::setPrice( $cents, $interval );
    } catch ( Throwable $e ) {
        bad( "$why — setting the price threw unexpectedly: " . $e->getMessage() );
        return [];
    }
}

echo "GATE 34c — the membership price is set as ONE action, or not at all\n";

/* ---------------------------------------------------------------------- */
section( "[1] SANDBOX ONLY — a live key refuses before anything is created" );

rig( [ 'lgms_stripe_secret_key' => 'sk_live_REAL' ] );
try { StripePrice::setPrice( 1200, 'month' ); bad( "a LIVE secret key was accepted" ); }
catch ( Throwable $e ) { is_( str_contains( $e->getMessage(), 'LIVE' ), "a live secret key REFUSES the whole operation" ); }
is_( FakeStripe::$created === 0, "...and nothing was created in Stripe" );
is_( ( $GLOBALS['OPTIONS'][ StripePrice::PRICE_OPT ] ?? '' ) === '', "...and new joins were not repointed" );

rig( [ 'lgms_stripe_secret_key' => 'rk_live_RESTRICTED' ] );
try { StripePrice::setPrice( 1200, 'month' ); bad( "a live RESTRICTED key was accepted" ); }
catch ( Throwable $e ) { ok( "a live restricted key refuses too" ); }

rig( [ 'lgms_stripe_secret_key' => '' ] );
try { StripePrice::setPrice( 1200, 'month' ); bad( "no key at all was accepted" ); }
catch ( Throwable $e ) { ok( "no secret key configured refuses rather than guessing" ); }

rig();
try { StripePrice::assertTestMode(); ok( "a test key is allowed through" ); }
catch ( Throwable $e ) { bad( "a test key was refused: " . $e->getMessage() ); }

/* ---------------------------------------------------------------------- */
section( "[2] ALL THREE WRITES, OR NONE" );

$pdo = rig();
$set = mustSet( 1200, 'month', 'the happy path' );
is_( FakeStripe::$created === 1, "the price is created in Stripe" );
is_( (string) $pdo->query( "SELECT COUNT(*) FROM prices WHERE stripe_price_id='price_TEST_NEW'" )->fetchColumn() === '1',
     "the price is recorded in OUR table — the step whose absence costs money" );
is_( StripePrice::currentPriceId( 'month' ) === 'price_TEST_NEW',
     "new joins on that cadence are pointed at it" );
is_( (string) $pdo->query( "SELECT product_id FROM prices WHERE stripe_price_id='price_TEST_NEW'" )->fetchColumn() === '1',
     "and it hangs off the STANDARD product, never the regional one" );
is_( ( FakeStripe::$lastParams['product'] ?? '' ) === 'prod_LOOTH3',
     "Stripe was told the same product our row names" );
is_( ( FakeStripe::$lastParams['unit_amount'] ?? 0 ) === 1200, "Stripe was told the amount that was asked for" );

// Stripe succeeds, our own write fails: new joins must NOT move.
$pdo = rig( [ StripePrice::PRICE_OPT => 'price_PREVIOUS_GOOD' ] );
$pdo->exec( 'DROP TABLE prices' );
try {
    StripePrice::setPrice( 1500, 'month' );
    bad( "a failed local write was reported as success" );
} catch ( Throwable $e ) {
    is_( str_contains( $e->getMessage(), 'could not be recorded' ), "a failed local write is reported, in plain English" );
}
is_( ( $GLOBALS['OPTIONS'][ StripePrice::PRICE_OPT ] ?? '' ) === 'price_PREVIOUS_GOOD',
     "...and new joins still point at the PREVIOUS working price, not the half-made one" );

// Stripe itself fails: nothing local changes at all.
$pdo = rig( [ StripePrice::PRICE_OPT => 'price_PREVIOUS_GOOD' ] );
FakeStripe::$throw = 'card_declined-ish api error';
try { StripePrice::setPrice( 1500, 'month' ); bad( "a Stripe failure was swallowed" ); }
catch ( Throwable $e ) { ok( "a Stripe failure is reported" ); }
is_( (string) $pdo->query( 'SELECT COUNT(*) FROM prices' )->fetchColumn() === '0', "...no local row was written" );
is_( ( $GLOBALS['OPTIONS'][ StripePrice::PRICE_OPT ] ?? '' ) === 'price_PREVIOUS_GOOD', "...and new joins did not move" );

/* ---------------------------------------------------------------------- */
section( "[3] THE JOIN PAGE STILL FINDS AN EXISTING MEMBER — the double-charge shape" );

$pdo = rig();
mustSet( 1200, 'month', 'join-page visibility setup' );
$pdo->exec( "INSERT INTO customers (id, email) VALUES (7, 'member@test')" );
$pdo->exec( "INSERT INTO subscriptions (customer_id, stripe_subscription_id, stripe_price_id, status)
             VALUES (7, 'sub_1', 'price_TEST_NEW', 'active')" );
is_( joinPageSeesSubscription( $pdo, 7 ),
     "a member subscribed on the newly set price is FOUND by the join page's own query" );

// The counter-proof: the same member, with the price missing from our table —
// this is what a price made in the Stripe dashboard would look like.
$pdo->exec( "DELETE FROM prices WHERE stripe_price_id='price_TEST_NEW'" );
is_( ! joinPageSeesSubscription( $pdo, 7 ),
     "...and VANISHES when that row is absent — which is why §2's middle write is load-bearing" );

/* ---------------------------------------------------------------------- */
section( "[4] EXISTING SUBSCRIBERS ARE GRANDFATHERED" );

$pdo = rig();
mustSet( 1200, 'month', 'grandfathering setup' );
$pdo->exec( "INSERT INTO customers (id, email) VALUES (7, 'old@test')" );
$pdo->exec( "INSERT INTO subscriptions (customer_id, stripe_subscription_id, stripe_price_id, status)
             VALUES (7, 'sub_old', 'price_TEST_NEW', 'active')" );

FakeStripe::$nextId = 'price_TEST_DEARER';
mustSet( 9900, 'year', 'the second, dearer price' );

is_( (string) $pdo->query( "SELECT stripe_price_id FROM subscriptions WHERE stripe_subscription_id='sub_old'" )->fetchColumn()
     === 'price_TEST_NEW', "an existing subscription still names the price it joined on" );
// Note the shape change cadences brought: setting the YEARLY price moves new
// YEARLY joins and leaves monthly untouched. The old single-slot assertion
// ("new joins move to the new price") was the right claim when there was one
// slot; it is now per-cadence, and §6b holds the independence claim.
is_( StripePrice::currentPriceId( 'year' ) === 'price_TEST_DEARER',
     "while NEW joins on the changed cadence move to the new price" );
is_( StripePrice::currentPriceId( 'month' ) === 'price_TEST_NEW',
     "...and the OTHER cadence is untouched by that change" );
is_( (string) $pdo->query( "SELECT COUNT(*) FROM prices WHERE stripe_price_id='price_TEST_NEW' AND active=1" )->fetchColumn() === '1',
     "the old price stays ACTIVE in our table — a grandfathered member's page must still resolve it" );
is_( joinPageSeesSubscription( $pdo, 7 ), "and the grandfathered member is still found by the join page" );

/* ---------------------------------------------------------------------- */
section( "[5] IT SHIPS WITH NO PRICE SET — the number is Ian's" );

rig();
is_( StripePrice::currentPriceId() === '', "with nothing configured, no price is set" );
is_( StripePrice::currentPrice() === null, "...and the dash has nothing to show" );
is_( ! StripePrice::currentPriceIsOrphaned(), "...which is NOT the same as a broken pointer" );

rig( [ StripePrice::PRICE_OPT => 'price_NOT_IN_OUR_TABLE' ] );
is_( StripePrice::currentPrice() === null, "a pointer at an unknown price shows nothing rather than a blank row" );
is_( StripePrice::currentPriceIsOrphaned(), "...and is reported as the broken state it is, so the dash can warn" );

/* ---------------------------------------------------------------------- */
section( "[6] THE AMOUNT IS WHAT WAS TYPED — nothing junk reaches Stripe" );

rig();
foreach ( [ '12' => 1200, '12.50' => 1250, '12,50' => 1250, '0.50' => 50, '8.05' => 805 ] as $in => $want ) {
    try { is_( StripePrice::parseAmount( (string) $in ) === $want, "'$in' reads as $want cents" ); }
    catch ( Throwable $e ) { bad( "'$in' was refused: " . $e->getMessage() ); }
}
foreach ( [ '', 'twelve', '12abc', '12.', '1.234', '-5', '0', '0.49', '1e3' ] as $junk ) {
    try { StripePrice::parseAmount( $junk ); bad( "junk amount '$junk' was ACCEPTED" ); }
    catch ( Throwable $e ) { ok( "'$junk' is refused before anything reaches Stripe" ); }
}
try { StripePrice::parseAmount( '2000.00' ); bad( "an implausible 2000.00 was accepted" ); }
catch ( Throwable $e ) { ok( "an implausible amount is refused (a Stripe price cannot be deleted)" ); }

try { StripePrice::assertInterval( 'week' ); bad( "'week' was accepted as an interval" ); }
catch ( Throwable $e ) { ok( "only monthly and yearly are offered, and only those are accepted" ); }
is_( StripePrice::assertInterval( 'year' ) === 'year', "'year' is accepted" );

is_( FakeStripe::$created === 0, "none of the above reached Stripe at all" );

/* ---------------------------------------------------------------------- */
section( "[6b] TWO CADENCES, ONE TIER — and they must not overwrite each other" );

// Ian, 2026-08-15: "We need a monthly and a yearly price etc." — his Patreon
// shape. Still ONE membership: both prices hang off the same product and grant
// the same tier, so the poller still needs no price logic. What changes is that
// a member picks how often they pay, and the two slots must be independent —
// the obvious bug here is a second setPrice() quietly replacing the first.

$pdo = rig();
mustSet( 500, 'month', 'the monthly price' );
FakeStripe::$nextId = 'price_TEST_YEAR';
mustSet( 6000, 'year', 'the yearly price' );

is_( StripePrice::currentPriceId( 'month' ) === 'price_TEST_NEW',
     "setting the YEARLY price leaves the monthly one alone" );
is_( StripePrice::currentPriceId( 'year' ) === 'price_TEST_YEAR',
     "...and the yearly slot holds its own price" );
is_( StripePrice::configuredCadences() === [ 'month', 'year' ],
     "both cadences are offered once both are set" );

$m = StripePrice::currentPrice( 'month' ); $y = StripePrice::currentPrice( 'year' );
is_( ( $m['unit_amount_cents'] ?? 0 ) === 500 && ( $y['unit_amount_cents'] ?? 0 ) === 6000,
     "each cadence reports its own amount (5.00 monthly, 60.00 yearly — Ian's Patreon shape)" );
is_( (string) $pdo->query( "SELECT COUNT(DISTINCT product_id) FROM prices WHERE stripe_price_id IN ('price_TEST_NEW','price_TEST_YEAR')" )->fetchColumn() === '1',
     "BOTH prices hang off the SAME product — one tier, two rhythms, so the grant stays a constant" );

// One cadence configured must not imply the other is offered.
rig();
mustSet( 500, 'month', 'monthly only' );
is_( StripePrice::configuredCadences() === [ 'month' ], "a single configured cadence offers only itself" );
is_( StripePrice::currentPriceId( 'year' ) === '', "...and the unset one stays empty rather than borrowing" );
is_( StripePrice::currentPrice( 'year' ) === null, "...with nothing to show for it" );

// The legacy single option still answers for MONTHLY, and only when unset.
rig( [ StripePrice::PRICE_OPT => 'price_LEGACY' ] );
is_( StripePrice::currentPriceId( 'month' ) === 'price_LEGACY',
     "a box configured before cadences existed keeps selling its monthly price" );
is_( StripePrice::currentPriceId( 'year' ) === '', "...and the legacy option never answers for yearly" );
mustSet( 700, 'month', 'monthly over legacy' );
is_( StripePrice::currentPriceId( 'month' ) === 'price_TEST_NEW',
     "...and a real monthly price WINS over the legacy fallback once set" );

/* ---------------------------------------------------------------------- */
section( "[7] THE PRODUCT IS RESOLVED, NEVER GUESSED" );

$pdo = rig();
try {
    $p = StripePrice::tierProduct();
    is_( $p['stripe_product_id'] === 'prod_LOOTH3', "the standard product is chosen over the regional one" );
} catch ( Throwable $e ) {
    // Wrapped for the same reason as mustSet(): mutation P7 (dropping the
    // region_tag filter) makes the standard and regional products BOTH match,
    // so this throws — and an unwrapped throw kills the run with no FAIL line.
    bad( "resolving the tier product threw: " . $e->getMessage() );
}

$pdo = rig();
$pdo->exec( "UPDATE products SET active=0 WHERE stripe_product_id='prod_LOOTH3'" );
try { StripePrice::tierProduct(); bad( "no active product still resolved to something" ); }
catch ( Throwable $e ) { ok( "no active product refuses rather than inventing one" ); }

$pdo = rig();
$pdo->exec( "INSERT INTO products (stripe_product_id, kind, ref, region_tag, name, active)
             VALUES ('prod_LOOTH3_DUPE', 'membership', 'looth3', NULL, 'Looth PRO again', 1)" );
try { StripePrice::tierProduct(); bad( "an ambiguous catalogue picked a product anyway" ); }
catch ( Throwable $e ) { ok( "two candidate products REFUSE — never guess where money lands" ); }

/* ---------------------------------------------------------------------- */
echo "\n$pass passed, $fail failed\n";
if ( $fail > 0 ) { echo "RED — the price control is not holding.\n"; exit( 1 ); }
echo "GREEN — sandbox only, all three writes or none, the member stays visible, "
   . "existing subscribers grandfathered, and it ships with no price set.\n";
exit( 0 );

/* ======================================================================= *
 * RED-FIRST RECORD — measured, not asserted. Baseline: 49 passed, 0 failed.
 *
 * Each mutation was applied to src/StripePrice.php from a snapshot copy, the
 * gate run, the count recorded, and the file restored. Never `git checkout --`:
 * that would destroy the uncommitted work under test.
 *
 *   P1  delete the assertTestMode() call at the top of setPrice()   -> 5 RED
 *       A live key creates a real, chargeable price from the dash. The lane
 *       charter's first rule, enforced rather than documented.
 *   P2  move update_option() ABOVE the local row write              -> 1 RED
 *       New joins point at a price our table may not hold — the double-charge
 *       shape, caught at the ORDERING rather than at the symptom.
 *   P3  drop the local row write entirely                           -> 7 RED
 *       Including §3, where the join page's own query loses the member.
 *   P4  swallow the local-write failure and continue                -> 2 RED
 *       A half-applied price change reported as success.
 *   P5  drop the amount format check                                -> 4 RED
 *       '12abc' becomes 12.00 and '' becomes free.
 *   P6  drop the MIN_CENTS floor                                    -> 2 RED
 *   P7  tierProduct(): drop `region_tag IS NULL`                    -> 17 RED
 *       The standard and regional products both match, so nothing can be set
 *       at all. The widest blast radius of any mutation here.
 *   P8  tierProduct(): return $rows[0] rather than refusing when
 *       the catalogue is ambiguous                                  -> 1 RED
 *       Guessing which product a member's money lands against.
 *   P9  deactivate every other price when a new one is set          -> 1 RED
 *       The plausible "tidy up the old price" change — it breaks §4's
 *       grandfathered member, whose page can no longer resolve what they pay.
 *
 * TWO OF THESE FOUND A HOLE IN THE GATE RATHER THAN THE CODE, and both were
 * the same hole: an unexpected throw KILLED THE RUN instead of reddening a
 * line, printing no FAIL at all — the failure mode already on record for gate
 * 2. P7 fatally exited twice before `mustSet()` and the §7 wrapper were added;
 * it now reports 17 named failures. A gate that dies is a gate that cannot
 * tell you what broke.
 * ======================================================================= */

}
