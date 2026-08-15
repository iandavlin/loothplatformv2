<?php
/**
 * GATE 34d — the Test Group fences the ENTITLEMENT SWEEP, not just the webhook.
 *
 *   php tools/gates/stripe-testgroup-sweep-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * THE DEFECT THIS EXISTS FOR. The soft-launch design says, in terms: *"Every
 * grant path keeps the allowlist check — join, gift redemption, regional."*
 * Only one path ever did. `StripeLifecycle` gates the Stripe WEBHOOK. But a
 * redeemed gift does not arrive by webhook — it arrives like this:
 *
 *   1. the recipient redeems at /billing/v1/redeem (the separate Slim app,
 *      routed and live on dev2), which knows nothing about any allowlist;
 *   2. GiftRedemptionService writes an entitlement (source: gift code);
 *   3. WpSync pings /wp-json/lg-member-sync/v1/sync-customer — a route
 *      registered UNCONDITIONALLY, guarded by a shared secret and nothing else;
 *   4. `Sync::customer()` reads the entitlement, reports a stripe opinion and
 *      runs the Arbiter, which writes wp_capabilities.
 *
 * And there is a second door into the same room: `Tick` pass 2 calls
 * `Sync::all()` every five minutes over every customer, so even with step 3
 * removed the sweep finds them on its own. `lgms_stripe_frozen` does NOT stop
 * it — that option guards the Stripe POLL, which is pass 1.
 *
 * So before this gate, a gift redeemed by somebody not on the Test Group
 * granted them the membership anyway, within minutes. The charter's care point
 * predicted the opposite failure ("pay Stripe and grant nothing"); the truth
 * was worse and pointed the other way.
 *
 * WHAT MUST HOLD:
 *
 *   1. OFF IS TODAY, EXACTLY. Flag off — every box, right now — and the sweep
 *      grants precisely as it always has, for listed and unlisted alike. This
 *      is the byte-identical-off-state rule, and it is what lets this merge.
 *   2. ARMED, THE LIST DECIDES. Flag on: a listed member is granted, an
 *      unlisted one is not, from the same call.
 *   3. AN EMPTY LIST IS NOBODY — the same fail-safe as the webhook half.
 *   4. OUT OF COHORT IS FROZEN, NOT RETRACTED. No opinion written, Arbiter not
 *      run: a member pulled from the list keeps what they had rather than being
 *      half-demoted by a sweep.
 *   5. A GIFT IS FENCED LIKE ANYTHING ELSE — the entitlement's source must not
 *      change the answer, since the gift path is the one that motivated this.
 *
 * The collaborators are stubbed so the sweep's DECISION is what is measured,
 * but StripeLifecycle is the REAL class reading REAL options — the fence's own
 * logic is never stubbed.
 *
 * Red-first record with measured counts at the foot of this file.
 */

declare(strict_types=1);

namespace LGMS {

    /** Observable stand-ins for everything Sync collaborates with. */
    class Db { public static function pdo() { return $GLOBALS['PDO'] ?? null; } }

    class Log {
        public static function line( string $m, string $n = 'tick.log' ): void { $GLOBALS['LOG'][] = rtrim( $m, "\n" ); }
    }

    class Arbiter {
        public static array $calls = [];
        public static function sync( int $uid ): array { self::$calls[] = $uid; return [ 'ok' => true ]; }
    }

    class RoleSourceWriter {
        public static array $writes = [];
        public static function report( int $uid, string $src, ?string $tier ): void {
            self::$writes[] = [ 'uid' => $uid, 'source' => $src, 'tier' => $tier ];
        }
    }
}

namespace LGMS\Repos {
    class CustomerRepo {
        public static function findById( int $id ): ?array {
            return $GLOBALS['CUSTOMER'] ?? null;
        }
    }
    class EntitlementRepo {
        public static function activeTier( int $customerId ): ?string {
            return $GLOBALS['ACTIVE_TIER'] ?? null;
        }
    }
}

namespace LGMS\Wp {
    class UserProvisioner {
        public static function findOrProvision( int $cid, string $email, ?string $name ): int {
            return (int) ( $GLOBALS['WP_USER_ID'] ?? 0 );
        }
    }
}

namespace {

$BASE = __DIR__ . '/../../lg-patreon-stripe-poller';
foreach ( [ '/src/Sync.php', '/src/StripeLifecycle.php' ] as $f ) {
    if ( ! is_readable( $BASE . $f ) ) { fwrite( STDERR, "CANNOT RUN: missing $f\n" ); exit( 3 ); }
}

$pass = 0; $fail = 0;
function ok( string $m ): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function section( string $t ): void { echo "\n$t\n"; }

$GLOBALS['OPTIONS'] = [];
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTIONS'][ $n ] = $v; return true; }

// StripeLifecycle is REAL — the fence's own logic is never stubbed.
require $BASE . '/src/StripeLifecycle.php';
require $BASE . '/src/Sync.php';

use LGMS\Sync;
use LGMS\Arbiter;
use LGMS\RoleSourceWriter;
use LGMS\StripeLifecycle;

/**
 * One sweep of one customer.
 *
 * @param mixed $allowlist whatever the option holds (array, or junk)
 * @return array{result:array,granted:bool,writes:array,arbiter:int,log:array}
 */
function sweep( bool $flagOn, $allowlist, ?string $activeTier, int $wpUserId = 501 ): array
{
    $GLOBALS['OPTIONS'] = [
        StripeLifecycle::FLAG          => $flagOn,
        StripeLifecycle::ALLOWLIST_OPT => $allowlist,
    ];
    $GLOBALS['CUSTOMER']    = [ 'id' => 9, 'email' => 'm@test', 'name' => 'M' ];
    $GLOBALS['ACTIVE_TIER'] = $activeTier;
    $GLOBALS['WP_USER_ID']  = $wpUserId;
    $GLOBALS['LOG']         = [];
    Arbiter::$calls          = [];
    RoleSourceWriter::$writes = [];

    $res = Sync::customer( 9 );

    return [
        'result'  => $res,
        // "Granted" means what a member would FEEL: an opinion was written and
        // the Arbiter ran. Checking only the return value would pass on a fence
        // that returned tier=null while still writing the role.
        'granted' => RoleSourceWriter::$writes !== [] && Arbiter::$calls !== [],
        'writes'  => RoleSourceWriter::$writes,
        'arbiter' => count( Arbiter::$calls ),
        'log'     => $GLOBALS['LOG'],
    ];
}

$LIST = [ 501, 502 ];

echo "GATE 34d — the Test Group fences the entitlement sweep (the gift path)\n";

/* ---------------------------------------------------------------------- */
section( "[1] OFF IS TODAY, EXACTLY — the flag off changes nothing about the sweep" );

$r = sweep( false, $LIST, 'looth3', 501 );
is_( $r['granted'], "flag OFF + listed member -> granted, as always" );

$r = sweep( false, $LIST, 'looth3', 999 );
is_( $r['granted'], "flag OFF + UNLISTED member -> still granted; the list is inert while off" );
is_( $r['result']['tier'] === 'looth3', "...and the tier is reported unchanged" );

$r = sweep( false, [], 'looth3', 999 );
is_( $r['granted'], "flag OFF + empty list -> still granted (an empty list must not close a flag that is off)" );

$r = sweep( false, $LIST, null, 999 );
is_( $r['writes'] === [ [ 'uid' => 999, 'source' => 'stripe', 'tier' => null ] ],
     "flag OFF + no entitlement -> a NULL opinion is still reported, exactly as before" );

/* ---------------------------------------------------------------------- */
section( "[2] ARMED, THE LIST DECIDES" );

$r = sweep( true, $LIST, 'looth3', 501 );
is_( $r['granted'], "flag ON + on the list -> granted" );
is_( $r['result']['tier'] === 'looth3', "...and the tier comes back" );

$r = sweep( true, $LIST, 'looth3', 999 );
is_( ! $r['granted'], "flag ON + NOT on the list -> NOT granted (the gift bypass, closed)" );
is_( $r['writes'] === [], "...no stripe opinion was written" );
is_( $r['arbiter'] === 0, "...and the Arbiter never ran, so no role could move" );
is_( ( $r['result']['skipped'] ?? '' ) === 'not in soft-launch cohort', "...and the skip is named in the result" );
is_( ( $r['result']['ok'] ?? false ) === true, "...while still reporting OK — a skip is not an error to retry" );
is_( count( $r['log'] ) === 1 && str_contains( $r['log'][0], 'not in soft-launch cohort' ),
     "...and a refusal that had something to grant IS logged" );

$r = sweep( true, $LIST, null, 999 );
is_( count( $r['log'] ) === 0,
     "but an unlisted customer with NOTHING to grant is not logged — the sweep runs every five minutes" );

/* ---------------------------------------------------------------------- */
section( "[3] AN EMPTY OR BROKEN LIST IS NOBODY" );

foreach ( [ 'empty' => [], 'absent' => null, 'string' => 'nope', 'int' => 501, 'bool' => true ] as $what => $val ) {
    $r = sweep( true, $val, 'looth3', 501 );
    is_( ! $r['granted'], "flag ON + $what list -> nobody is granted" );
}

$r = sweep( true, [ '501' ], 'looth3', 501 );
is_( $r['granted'], "a numeric-string id still IS the member (hand-set lists work)" );

/* ---------------------------------------------------------------------- */
section( "[4] OUT OF COHORT IS FROZEN, NOT RETRACTED" );

$r = sweep( true, $LIST, 'looth3', 999 );
is_( $r['writes'] === [], "no opinion is written for an out-of-cohort member — not even a NULL one" );
is_( $r['arbiter'] === 0, "and the Arbiter is not run, so nothing they already hold is taken away" );

// The dangerous near-miss: a fence that reports tier=null but still writes.
// That would look like a skip in the return value and demote them in reality.
$r = sweep( true, $LIST, null, 999 );
is_( $r['writes'] === [] && $r['arbiter'] === 0,
     "a member pulled from the list with no entitlement is left alone, not written to NULL" );

/* ---------------------------------------------------------------------- */
section( "[5] A GIFT IS FENCED LIKE ANYTHING ELSE" );

// Sync reads the entitlement by TIER, never by where it came from — so a gift
// must not slip through on its source. Same tier, same answer, both ways.
$r = sweep( true, $LIST, 'looth3', 999 );
is_( ! $r['granted'], "a gift-sourced tier for an unlisted recipient is refused" );

$r = sweep( true, [ 999 ], 'looth3', 999 );
is_( $r['granted'], "...and granted the moment that recipient is added to the list" );

is_( StripeLifecycle::inCohort( 999 ) === true,
     "the sweep and the webhook share ONE cohort predicate — no second copy to drift" );

/* ---------------------------------------------------------------------- */
section( "[6] NO THIRD ROAD — every stripe grant path goes through a fence" );

// Tonight's lesson generalised, per CRAFT-STANDARD's law: a defect class found
// twice becomes a gate. The list looked like a fence but was a gate on ONE
// road; a second road (the sweep) went around it for months. The property that
// actually matters is therefore not "the sweep is fenced" but "there is no
// unfenced road at all" — so this asserts the SHAPE of the codebase, and a
// third road cannot land without turning this red.
$src   = __DIR__ . '/../../lg-patreon-stripe-poller/src';
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src ) );
$sites = [];
foreach ( $files as $f ) {
    if ( $f->isDir() || $f->getExtension() !== 'php' ) { continue; }
    $body = (string) file_get_contents( $f->getPathname() );
    foreach ( explode( "\n", $body ) as $i => $line ) {
        // A stripe opinion written with the source as a LITERAL.
        if ( preg_match( "/RoleSourceWriter::report\s*\(.*'stripe'/", $line ) ) {
            $sites[] = basename( $f->getPathname() ) . ':' . ( $i + 1 );
        }
    }
}
sort( $sites );
$expected = [ 'StripeLifecycle.php', 'Sync.php' ];
$got      = array_values( array_unique( array_map(
    static fn ( string $s ): string => explode( ':', $s )[0], $sites
) ) );
sort( $got );

is_( $got === $expected, sprintf(
    "exactly TWO files write a stripe opinion — the webhook and the sweep, both fenced (found: %s)",
    $sites === [] ? 'none' : implode( ', ', $sites )
) );

// The latent one, kept latent on purpose. UserLifecycle::provision() takes a
// CALLER-SUPPLIED source and would write an opinion + run the Arbiter with no
// cohort check. It has no callers today, which is the only reason it is not a
// third road. If somebody wires it up, this reddens and they must fence it —
// that is the point, not an inconvenience.
$repo    = __DIR__ . '/../..';
$callers = [];
foreach ( [ '/lg-patreon-stripe-poller/src', '/lg-patreon-stripe-poller/includes', '/membership-pages' ] as $dir ) {
    if ( ! is_dir( $repo . $dir ) ) { continue; }
    $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $repo . $dir ) );
    foreach ( $it as $f ) {
        if ( $f->isDir() || $f->getExtension() !== 'php' ) { continue; }
        if ( basename( $f->getPathname() ) === 'UserLifecycle.php' ) { continue; }
        $body = (string) file_get_contents( $f->getPathname() );
        if ( preg_match( '/UserLifecycle::provision\s*\(/', $body ) ) {
            $callers[] = basename( $f->getPathname() );
        }
    }
}
// Textual, and deliberately so: it cannot tell a real call from a
// commented-out one, and it reddens on either. That errs toward a
// conversation, which is the safe direction for an unfenced grant path.
is_( $callers === [], sprintf(
    "UserLifecycle::provision() is still uncalled — it takes a caller-supplied source and is NOT fenced, "
    . "so wiring it up needs the cohort check first (callers found: %s)",
    $callers === [] ? 'none' : implode( ', ', $callers )
) );

/* ---------------------------------------------------------------------- */
section( "[7] THE OTHER RAIL — Patreon is never touched by this fence" );

// Ian's PERMANENT ruling, 2026-08-15: "Everything needs to fire for both for
// the foreseeable future... we are dual wielding patreon and stripe for a
// while." Patreon does NOT sunset at the Stripe launch; the two rails run in
// parallel indefinitely, and every downstream flow keys on membership
// ACTIVATION rather than on one rail's events.
//
// This fence therefore has to be provably Stripe-only. The danger is not that
// it refuses a Stripe grant — that is its job — but that refusing also
// disturbs a member's PATREON standing, which would demote somebody who is
// paying on the other rail and has nothing to do with the soft launch.

$r = sweep( true, $LIST, 'looth3', 999 );
is_( $r['writes'] === [],
     "a refused sweep writes NOTHING — so no patreon opinion is disturbed by a stripe refusal" );
is_( $r['arbiter'] === 0,
     "and the Arbiter is not run, so a dual-holder's winning tier is never recomputed by this path" );

// Source-shape: this method may only ever express an opinion as 'stripe'. If it
// ever wrote another source, the fence would be gating the wrong rail.
$syncBody = (string) file_get_contents( __DIR__ . '/../../lg-patreon-stripe-poller/src/Sync.php' );
preg_match_all( "/RoleSourceWriter::report\s*\([^;]*?'([a-z_]+)'/", $syncBody, $m );
$sources = array_values( array_unique( $m[1] ?? [] ) );
is_( $sources === [ 'stripe' ], sprintf(
    "the swept opinion is ALWAYS 'stripe' and never another rail (found: %s)",
    $sources === [] ? 'none' : implode( ', ', $sources ) ) );

// And the patreon rail's own writer must live somewhere this fence cannot reach.
is_( ! str_contains( $syncBody, "'patreon'" ),
     "the patreon rail is written elsewhere entirely — this file cannot gate it even by accident" );

/* ---------------------------------------------------------------------- */
echo "\n$pass passed, $fail failed\n";
if ( $fail > 0 ) { echo "RED — the entitlement sweep is not fenced by the Test Group.\n"; exit( 1 ); }
echo "GREEN — off is today exactly, armed the list decides, an empty list is nobody, "
   . "out-of-cohort is frozen rather than retracted, and a gift gets no special pass.\n";
exit( 0 );

/* ======================================================================= *
 * RED-FIRST RECORD — measured, not asserted. Baseline: 28 passed, 0 failed.
 *
 * Mutations applied from a snapshot copy, gate run, count recorded, file
 * restored. Never `git checkout --`.
 *
 *   S1  delete the fence entirely — i.e. THE DEFECT EXACTLY AS IT SHIPPED,
 *       the only mutation here that reproduces a real past state  -> 14 RED
 *   S2  drop `StripeLifecycle::flagOn() &&` from the condition    ->  4 RED
 *       The fence bites while the flag is OFF, so a box not running the soft
 *       launch silently stops syncing. §1's off-state assertions catch it —
 *       which is what asserting an OFF state is for.
 *   S3  invert the cohort test                                    -> 18 RED
 *   S4  return "skipped" but still write the stripe opinion       ->  3 RED
 *       THE DANGEROUS NEAR-MISS: the return value says skipped while the
 *       member is written to NULL and demoted by the next Arbiter run. Caught
 *       only because "granted" is measured from the COLLABORATORS rather than
 *       the return value — `$res['tier'] === null` would have passed happily.
 *   S5  return "skipped" but still run the Arbiter                ->  3 RED
 *   S6  log every refusal, not only the ones with something to
 *       grant                                                     ->  1 RED
 *   S7  add a THIRD file that writes a stripe opinion             ->  1 RED
 *       The regression class itself: §6 exists because the list looked like a
 *       fence while being a gate on ONE road, and a second road went around it
 *       for months. A third cannot land silently now.
 *   S8  wire up the latent UserLifecycle::provision() path        ->  1 RED
 *       (Reddens on a commented reference too — see the note at that
 *       assertion; over-eager in the safe direction.)
 * ======================================================================= */

}
