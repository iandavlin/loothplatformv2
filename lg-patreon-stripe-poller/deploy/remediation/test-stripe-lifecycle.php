<?php
/**
 * RED-FIRST GATE for the Stripe webhook lifecycle core (charter Phase 1
 * item 1; design doc STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md §3–§4).
 *
 *   php test-stripe-lifecycle.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Exercises the REAL code — LGMS\StripeLifecycle + the REAL Stripe
 * signature library out of vendor/ — over a SQLite lg_membership shaped
 * like live. The only seam is the live-API confirm client
 * (StripeLifecycle::$confirmFactory), which is exactly the seam the dev2
 * e2e harness uses.
 *
 * What must hold, in order of what it costs to get wrong:
 *
 *   1. OFF (absent AND explicit false) is a TOTAL no-op — no route
 *      registered, no DB read, no option write, no log line. This is the
 *      gated OFF state; the missing OFF assertion is the failure class
 *      behind every phone-vs-suite miss.
 *   2. The identity-gate INTERLOCK: lifecycle ingest refuses to process
 *      while lgms_identity_gate is dark, because Sync::all() still runs the
 *      email-first minter every 5 minutes for any customer row this path
 *      creates. Flag ON + gate OFF must apply NOTHING.
 *   3. Signature is the auth. A bad signature is 400 and applies nothing —
 *      verified against the REAL \Stripe\Webhook code path, not a re-
 *      implementation (the audited-correct property from lg-stripe-billing).
 *   4. The CONFIRM wins over both the event and the mirror, in both
 *      directions. The audit found the mirror asserting `active` a month
 *      after the period ended; an event snapshot can be just as stale
 *      (out-of-order redelivery). State is applied from a fresh API read
 *      or not at all — confirm unavailable = defer (5xx, NOT marked
 *      processed) so Stripe redelivers.
 *   5. Stripe's single membership ALWAYS resolves looth3 (Ian's tier
 *      ruling, quote-grade). No price mapping is consulted — the gate
 *      proves it by never creating a products/prices table at all.
 *   6. Cancellation writes tier=NULL and KEEPS the row (the retracted
 *      state RetractionSweep treats as correct) — never row deletion.
 *   7. Dedupe against lg_processed_events GATES re-processing; a FAILED
 *      event is not marked processed, so redelivery gets a second chance.
 *   8. Identity resolves through the bridge, then IdentityMatcher, and
 *      NOTHING ELSE. No wp_insert_user, ever. Unmatched = refuse + defer
 *      + notify (throttled).
 *   9. Every applied state change is journaled BEFORE the mutation (the
 *      ross journal property), with had_row distinguishing a NULL-tier row
 *      from an absent one (the trap from the 8/08 handoff §6).
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

$BASE = __DIR__ . '/../..';
foreach ( [ '/src/StripeLifecycle.php', '/src/Wp/WebhookRestController.php', '/src/Wp/IdentityMatcher.php',
            '/src/RoleSourceWriter.php', '/src/RetractionSweep.php', '/src/Patreon/PatreonSourceReader.php',
            '/src/Repos/CustomerRepo.php', '/src/Repos/SubscriptionRepo.php', '/src/Repos/EntitlementRepo.php',
            '/src/Uuid.php', '/vendor/stripe/stripe-php/lib/WebhookSignature.php' ] as $f ) {
    if ( ! is_readable( $BASE . $f ) ) { fwrite( STDERR, "CANNOT RUN (RED: build absent): missing {$f}\n" ); exit( 3 ); }
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/stub/' ); }

$GLOBALS['OPTS']      = [];
$GLOBALS['AUTOLOAD']  = [];
$GLOBALS['LOG']       = [];
$GLOBALS['FIX']       = [];
$GLOBALS['ROUTES']    = [];
$GLOBALS['MINTED']    = [];
$GLOBALS['NOTIFIED']  = [];
$GLOBALS['TRANSIENTS'] = [];

function get_option( $n, $d = false ) {
    return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d;
}
function update_option( $n, $v, $autoload = null ) {
    $GLOBALS['OPTS'][ $n ] = $v;
    $GLOBALS['AUTOLOAD'][ $n ] = $autoload;
    return true;
}
function get_transient( $n ) { return $GLOBALS['TRANSIENTS'][ $n ] ?? false; }
function set_transient( $n, $v, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $n ] = $v; return true; }
function register_rest_route( $ns, $route, $args = [] ) { $GLOBALS['ROUTES'][] = "{$ns}{$route}"; return true; }
function get_user_by( $field, $id ) {
    if ( $field === 'email' ) {
        foreach ( $GLOBALS['FIX'] as $r ) { if ( $r['email'] === $id ) { return (object) [ 'ID' => $r['uid'], 'roles' => $r['roles'], 'user_email' => $r['email'] ]; } }
        return false;
    }
    $r = $GLOBALS['FIX'][ (int) $id ] ?? null;
    if ( ! $r ) { return false; }
    return (object) [ 'ID' => $r['uid'], 'roles' => $r['roles'], 'user_email' => $r['email'] ];
}
function get_users( $q ) {
    $out = [];
    foreach ( $GLOBALS['FIX'] as $r ) {
        if ( ( $r['pat_id'] ?? '' ) !== '' && $r['pat_id'] === ( $q['meta_value'] ?? null ) ) { $out[] = $r['uid']; }
    }
    return $out;
}
function get_user_meta( $id, $key, $single = false ) {
    $r = $GLOBALS['FIX'][ (int) $id ] ?? null;
    if ( ! $r ) { return ''; }
    if ( $key === 'payment_source' )       { return $r['pay_src'] ?? ''; }
    if ( $key === 'lgpo_patreon_user_id' ) { return $r['pat_id'] ?? ''; }
    return '';
}
function wp_insert_user( $arr ) { $GLOBALS['MINTED'][] = $arr; return count( $GLOBALS['MINTED'] ) + 90000; }
function lgpo_notify_failure( $email, $name, $kind, $detail ) { $GLOBALS['NOTIFIED'][] = [ $kind, $email ]; }
function get_bloginfo( $k = '' ) { return 'Test'; }
function home_url( $p = '' ) { return 'https://dev.test' . $p; }

require_once $BASE . '/vendor/autoload.php';
require_once $BASE . '/src/Patreon/PatreonSourceReader.php';
require_once $BASE . '/src/RoleSourceWriter.php';
require_once $BASE . '/src/RetractionSweep.php';
require_once $BASE . '/src/Repos/CustomerRepo.php';
require_once $BASE . '/src/Repos/SubscriptionRepo.php';
require_once $BASE . '/src/Repos/EntitlementRepo.php';
require_once $BASE . '/src/Uuid.php';
require_once $BASE . '/src/Wp/IdentityMatcher.php';
require_once $BASE . '/src/StripeLifecycle.php';
require_once $BASE . '/src/Wp/WebhookRestController.php';

use LGMS\StripeLifecycle;
use LGMS\RetractionSweep;

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
    public function exec( $q ): int|false {
        $q = preg_replace( '/NOW\(\)/i', 'CURRENT_TIMESTAMP', $q );
        return parent::exec( $q );
    }
}

const WHSEC = 'whsec_gate_test_secret_0000000000';

function scenario(): void {
    $pdo = new TestPdo( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->exec( 'CREATE TABLE lg_role_sources (wp_user_id INTEGER, source TEXT, tier TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (wp_user_id, source))' );
    $pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE, synced_at TEXT)' );
    $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, stripe_customer_id TEXT, email TEXT UNIQUE, name TEXT, country TEXT, metadata TEXT, deleted_at TEXT)' );
    $pdo->exec( 'CREATE TABLE subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, stripe_subscription_id TEXT UNIQUE, stripe_price_id TEXT, status TEXT, cancel_at_period_end INTEGER, current_period_start TEXT, current_period_end TEXT, canceled_at TEXT)' );
    $pdo->exec( 'CREATE TABLE entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, customer_id INTEGER, kind TEXT, ref TEXT, source_type TEXT, source_id INTEGER, revoked_at TEXT, starts_at TEXT DEFAULT CURRENT_TIMESTAMP, expires_at TEXT)' );
    $pdo->exec( 'CREATE TABLE lg_processed_events (event_id TEXT PRIMARY KEY, first_seen_at TEXT, last_seen_at TEXT, dup_count INTEGER DEFAULT 0)' );
    $pdo->exec( 'CREATE TABLE lg_lifecycle_journal (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT, event_type TEXT, wp_user_id INTEGER, customer_id INTEGER, source TEXT DEFAULT \'stripe\', tier_before TEXT, had_row INTEGER, tier_after TEXT, state TEXT, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)' );
    // NO products / prices tables — on purpose. If any tier mapping is ever
    // consulted the SQL fails loudly and this gate goes red. looth3 is a ruling,
    // not a lookup.

    $GLOBALS['PDO'] = $pdo;
    $GLOBALS['OPTS'] = []; $GLOBALS['AUTOLOAD'] = []; $GLOBALS['LOG'] = [];
    $GLOBALS['ROUTES'] = []; $GLOBALS['MINTED'] = []; $GLOBALS['NOTIFIED'] = [];
    $GLOBALS['TRANSIENTS'] = [];
    $GLOBALS['FIX'] = [
        501 => [ 'uid' => 501, 'email' => 'bridged@x.test',  'roles' => [ 'looth1' ], 'pat_id' => '' ],
        502 => [ 'uid' => 502, 'email' => 'claimed@x.test',  'roles' => [ 'looth1' ], 'pat_id' => '' ],
        503 => [ 'uid' => 503, 'email' => 'patron@x.test',   'roles' => [ 'looth2' ], 'pat_id' => '777001' ],
    ];
    \LGMS\Db::$calls = 0;
    \LGMS\Arbiter::$calls = [];
    StripeLifecycle::$confirmFactory = null;
    StripeLifecycle::_resetForTests();
}

function flag_on(): void {
    $GLOBALS['OPTS']['lgms_stripe_lifecycle']    = true;
    $GLOBALS['OPTS']['lgms_identity_gate']       = true;
    $GLOBALS['OPTS']['lgms_stripe_webhook_secret'] = WHSEC;
    // Soft-launch cohort armed for every fixture member: THIS gate proves
    // the lifecycle CORE. The cohort's own semantics (empty=closed, skip
    // journaling, dash write shape) are test-soft-launch-allowlist.php.
    $GLOBALS['OPTS'][ StripeLifecycle::ALLOWLIST_OPT ] = [ 501, 502, 503 ];
}

function sign( string $payload, ?string $secret = null, ?int $t = null ): string {
    $t = $t ?? time();
    $v1 = hash_hmac( 'sha256', "{$t}.{$payload}", $secret ?? WHSEC );
    return "t={$t},v1={$v1}";
}

function evt( string $id, string $type, array $object ): string {
    return json_encode( [
        'id'   => $id,
        'object' => 'event',
        'type' => $type,
        'data' => [ 'object' => $object ],
    ] );
}

function sub_obj( string $subId, string $cus, string $status, string $price = 'price_gate_1' ): array {
    return [
        'id' => $subId, 'object' => 'subscription', 'customer' => $cus, 'status' => $status,
        'items' => [ 'data' => [ [ 'price' => [ 'id' => $price ] ] ] ],
        'cancel_at_period_end' => false,
        'current_period_start' => 1754000000, 'current_period_end' => 1756700000,
        'canceled_at' => null,
    ];
}

/** Confirm stub: a map of sub id -> confirmed subscription (or a Throwable). */
function confirm_with( array $map ): void {
    StripeLifecycle::$confirmFactory = static function () use ( $map ): object {
        return new class( $map ) {
            public function __construct( private array $map ) {}
            public function retrieveSubscription( string $id, array $expand = [] ): object {
                $hit = $this->map[ $id ] ?? null;
                if ( $hit instanceof Throwable ) { throw $hit; }
                if ( $hit === null ) { throw new RuntimeException( "no such subscription {$id}" ); }
                return json_decode( json_encode( $hit ) );
            }
        };
    };
}

function row_tier( int $uid, string $source ) {
    $st = $GLOBALS['PDO']->prepare( 'SELECT tier, COUNT(*) AS n FROM lg_role_sources WHERE wp_user_id = ? AND source = ?' );
    $st->execute( [ $uid, $source ] );
    $r = $st->fetch( PDO::FETCH_ASSOC );
    return (int) $r['n'] === 0 ? 'ABSENT' : $r['tier'];
}
function journal_rows(): array {
    return $GLOBALS['PDO']->query( 'SELECT * FROM lg_lifecycle_journal ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
}
function processed( string $eventId ): bool {
    $st = $GLOBALS['PDO']->prepare( 'SELECT COUNT(*) FROM lg_processed_events WHERE event_id = ?' );
    $st->execute( [ $eventId ] );
    return (int) $st->fetchColumn() > 0;
}
function seed_customer( string $email, string $cus, ?array $meta = null ): int {
    $GLOBALS['PDO']->prepare( 'INSERT INTO customers (uuid, stripe_customer_id, email, metadata) VALUES (?,?,?,?)' )
        ->execute( [ bin2hex( random_bytes( 8 ) ), $cus, $email, $meta === null ? null : json_encode( $meta ) ] );
    return (int) $GLOBALS['PDO']->lastInsertId();
}
function bridge( int $customerId, int $uid ): void {
    $GLOBALS['PDO']->prepare( 'INSERT INTO wp_user_bridge (customer_id, wp_user_id) VALUES (?,?)' )->execute( [ $customerId, $uid ] );
}

$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $d ? "  ({$d})" : '' ); }
};

echo "=== stripe lifecycle — red-first gate ===\n";

// ------------------------------------------------ 1. OFF is a total no-op
echo "\n[1] flag ABSENT (live's state at merge) and explicit false — total no-op\n";
scenario();
$note( get_option( StripeLifecycle::FLAG, false ) === false, 'option is genuinely absent, not seeded' );
$note( StripeLifecycle::flagOn() === false, 'flagOn() reads absent as OFF' );
\LGMS\Wp\WebhookRestController::maybeRegister();
$note( $GLOBALS['ROUTES'] === [], 'absent: no webhook route is registered' );
$note( \LGMS\Db::$calls === 0, 'absent: the database is never touched', (string) \LGMS\Db::$calls );
$note( $GLOBALS['OPTS'] === [], 'absent: no option is written' );
$note( $GLOBALS['LOG'] === [], 'absent: not a single log line' );

$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = false;
\LGMS\Wp\WebhookRestController::maybeRegister();
$note( $GLOBALS['ROUTES'] === [] && \LGMS\Db::$calls === 0 && $GLOBALS['LOG'] === [],
       'explicit OFF: identical — no route, no read, no line' );

// ------------------------------------------------ 2. the identity-gate interlock
echo "\n[2] INTERLOCK — lifecycle ON while lgms_identity_gate is dark applies NOTHING\n";
scenario();
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$GLOBALS['OPTS']['lgms_stripe_webhook_secret'] = WHSEC;
// identity gate deliberately ABSENT
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$payload = evt( 'evt_interlock', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $payload, sign( $payload ) );
$note( $res['status'] === 503, 'ingest refuses with 503 while the identity gate is dark', (string) $res['status'] );
$note( row_tier( 501, 'stripe' ) === 'ABSENT' && \LGMS\Arbiter::$calls === [] && journal_rows() === [],
       'and applies nothing — no opinion, no Arbiter, no journal' );
$note( ! processed( 'evt_interlock' ), 'and the event is NOT marked processed (redelivery gets a chance)' );
$note( (bool) preg_grep( '/identity gate/i', $GLOBALS['LOG'] ), 'and says why, in the log' );

// ------------------------------------------------ 3. signature is the auth
echo "\n[3] signature — the REAL \\Stripe\\Webhook path; bad sig applies nothing\n";
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$payload = evt( 'evt_sig1', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );

$res = StripeLifecycle::ingest( $payload, sign( $payload, 'whsec_WRONG' ) );
$note( $res['status'] === 400, 'wrong secret -> 400', (string) $res['status'] );
$res = StripeLifecycle::ingest( $payload, 't=1,v1=deadbeef' );
$note( $res['status'] === 400, 'garbage header -> 400' );
$res = StripeLifecycle::ingest( $payload, sign( $payload, null, time() - 3600 ) );
$note( $res['status'] === 400, 'stale timestamp (replay, > tolerance) -> 400' );
$note( row_tier( 501, 'stripe' ) === 'ABSENT' && journal_rows() === [] && ! processed( 'evt_sig1' ),
       'nothing was applied by any of the rejects' );

$GLOBALS['OPTS']['lgms_stripe_webhook_secret'] = '';
$res = StripeLifecycle::ingest( $payload, sign( $payload ) );
$note( $res['status'] === 500, 'missing webhook secret -> 500, never "verified"' );
$GLOBALS['OPTS']['lgms_stripe_webhook_secret'] = WHSEC;

// ------------------------------------------------ 4. the happy path: active -> looth3
echo "\n[4] active subscription -> looth3, through the Arbiter, journaled\n";
$res = StripeLifecycle::ingest( $payload, sign( $payload ) );
$note( $res['status'] === 200, 'good signature -> 200', json_encode( $res ) );
$note( row_tier( 501, 'stripe' ) === 'looth3', 'lg_role_sources stripe opinion is looth3' );
$note( \LGMS\Arbiter::$calls === [ 501 ], 'the role write went THROUGH the Arbiter', implode( ',', \LGMS\Arbiter::$calls ) );
$j = journal_rows();
$note( count( $j ) === 1 && $j[0]['tier_before'] === null && (int) $j[0]['had_row'] === 0
       && $j[0]['tier_after'] === 'looth3' && $j[0]['event_id'] === 'evt_sig1',
       'journal: one row, before=absent, after=looth3, tied to the event id' );
$note( processed( 'evt_sig1' ), 'the event is marked processed' );
$st = $GLOBALS['PDO']->prepare( 'SELECT status FROM subscriptions WHERE stripe_subscription_id = ?' );
$st->execute( [ 'sub_A' ] );
$note( $st->fetchColumn() === 'active', 'mirror subscription row upserted from the CONFIRMED state' );
$st = $GLOBALS['PDO']->prepare( "SELECT COUNT(*) FROM entitlements WHERE customer_id = ? AND ref = 'looth3' AND revoked_at IS NULL" );
$st->execute( [ $cid ] );
$note( (int) $st->fetchColumn() === 1, 'a live looth3 entitlement backs the opinion (RetractionSweep justification)' );
$note( RetractionSweep::detect() === [], 'and the retraction sweep agrees: the opinion is justified' );

// ------------------------------------------------ 5. dedupe gates; replay is a no-op
echo "\n[5] dedupe — a replayed event id is skipped, not re-applied\n";
$before = journal_rows();
$res = StripeLifecycle::ingest( $payload, sign( $payload ) );
$note( $res['status'] === 200 && ( $res['body']['result'] ?? '' ) === 'duplicate', 'replay -> 200 duplicate', json_encode( $res['body'] ?? [] ) );
$note( journal_rows() === $before && \LGMS\Arbiter::$calls === [ 501 ], 'no second journal row, no second Arbiter call' );

// ------------------------------------------------ 6. looth3 is a RULING, not a lookup
echo "\n[6] any price id resolves looth3 — no products/prices table even exists here\n";
foreach ( [ [ 'evt_p2', 'sub_P2', 'price_something_else' ], [ 'evt_p3', 'sub_P3', 'price_lite_lookalike' ] ] as $case ) {
    confirm_with( [ $case[1] => sub_obj( $case[1], 'cus_A', 'active', $case[2] ) ] );
    $p = evt( $case[0], 'customer.subscription.updated', sub_obj( $case[1], 'cus_A', 'active', $case[2] ) );
    $res = StripeLifecycle::ingest( $p, sign( $p ) );
    $note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'looth3',
           "price {$case[2]} -> looth3 (status {$res['status']})" );
}

// ------------------------------------------------ 7. the confirm wins, both directions
echo "\n[7] confirm-wins — a stale event or a stale mirror never decides state\n";
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
// Mirror poisoned exactly like live customer 7: says active, period long over.
$GLOBALS['PDO']->prepare( 'INSERT INTO subscriptions (customer_id, stripe_subscription_id, stripe_price_id, status, cancel_at_period_end, current_period_end) VALUES (?,?,?,?,0,?)' )
    ->execute( [ $cid, 'sub_A', 'price_gate_1', 'active', '2026-06-29 00:00:00' ] );
// Event snapshot ALSO says active. Stripe's fresh answer: canceled.
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'canceled' ) ] );
$p = evt( 'evt_stale1', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === null,
       'event=active + mirror=active + CONFIRM=canceled -> opinion retracted to NULL' );
$st = $GLOBALS['PDO']->prepare( 'SELECT status FROM subscriptions WHERE stripe_subscription_id = ?' );
$st->execute( [ 'sub_A' ] );
$note( $st->fetchColumn() === 'canceled', 'the poisoned mirror row is corrected from the confirm' );

// Inverse: event says canceled (out-of-order delivery), confirm says active.
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$p = evt( 'evt_stale2', 'customer.subscription.deleted', sub_obj( 'sub_A', 'cus_A', 'canceled' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'looth3',
       'event=canceled + CONFIRM=active -> looth3 stands (out-of-order redelivery is harmless)' );

// Confirm unavailable: defer, don't guess.
confirm_with( [ 'sub_A' => new RuntimeException( 'api down' ) ] );
$p = evt( 'evt_defer', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'canceled' ) );
$before = journal_rows();
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 503 && row_tier( 501, 'stripe' ) === 'looth3' && journal_rows() === $before,
       'confirm unreachable -> 503, nothing applied', (string) $res['status'] );
$note( ! processed( 'evt_defer' ), 'a deferred event is NOT marked processed' );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'canceled' ) ] );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === null,
       'the redelivery then lands: canceled applied on the second try' );

// ------------------------------------------------ 8. retraction shape
echo "\n[8] cancellation is tier=NULL, row kept — the RetractionSweep-correct state\n";
$note( row_tier( 501, 'stripe' ) === null && row_tier( 501, 'stripe' ) !== 'ABSENT',
       'the opinion row survives with tier=NULL (never deleted)' );
$st = $GLOBALS['PDO']->prepare( "SELECT COUNT(*) FROM entitlements WHERE customer_id = ? AND revoked_at IS NULL" );
$st->execute( [ $cid ] );
$note( (int) $st->fetchColumn() === 0, 'no live entitlement remains behind it' );
$note( RetractionSweep::detect() === [], 'the sweep reads the NULL opinion as JUSTIFIED retracted state, not a finding' );
$j = journal_rows();
$last = end( $j );
$note( $last['tier_before'] === 'looth3' && (int) $last['had_row'] === 1 && $last['tier_after'] === null,
       'journal captures looth3 -> NULL with had_row=1 (NULL row vs absent row is not conflated)' );

// past_due keeps access (Stripe retry window), unpaid does not.
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$p = evt( 'evt_a', 'customer.subscription.created', sub_obj( 'sub_A', 'cus_A', 'active' ) );
StripeLifecycle::ingest( $p, sign( $p ) );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'past_due' ) ] );
$p = evt( 'evt_pd', 'invoice.payment_failed', [ 'object' => 'invoice', 'customer' => 'cus_A', 'subscription' => 'sub_A' ] );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'looth3',
       'past_due (grace window) KEEPS looth3' );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'unpaid' ) ] );
$p = evt( 'evt_up', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'unpaid' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === null,
       'unpaid (past grace) retracts to NULL' );
// limbo: incomplete never grants
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_L' => sub_obj( 'sub_L', 'cus_A', 'incomplete' ) ] );
$p = evt( 'evt_lim', 'customer.subscription.created', sub_obj( 'sub_L', 'cus_A', 'incomplete' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'ABSENT' && \LGMS\Arbiter::$calls === [],
       'incomplete: mirror updated, NO opinion row, NO Arbiter — a checkout that never paid grants nothing' );

// ------------------------------------------------ 9. identity — matcher only, never mint
echo "\n[9] identity — bridge, then IdentityMatcher, then REFUSE. Never wp_insert_user\n";
scenario(); flag_on();
// customers.metadata.wp_user_id (the explicit bridge asserted at checkout)
$cid = seed_customer( 'other-address@relay.test', 'cus_B', [ 'wp_user_id' => 502 ] );
confirm_with( [ 'sub_B' => sub_obj( 'sub_B', 'cus_B', 'active' ) ] );
$p = evt( 'evt_meta', 'customer.subscription.updated', sub_obj( 'sub_B', 'cus_B', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 502, 'stripe' ) === 'looth3',
       'metadata wp_user_id resolves via IdentityMatcher branch 2 — email NEVER matched here' );
$st = $GLOBALS['PDO']->prepare( 'SELECT wp_user_id FROM wp_user_bridge WHERE customer_id = ?' );
$st->execute( [ $cid ] );
$note( (int) $st->fetchColumn() === 502, 'and the bridge row is written for next time' );

// patreon id chain
scenario(); flag_on();
$cid = seed_customer( 'private-relay@apple.test', 'cus_C', [ 'patreon_user_id' => '777001' ] );
confirm_with( [ 'sub_C' => sub_obj( 'sub_C', 'cus_C', 'active' ) ] );
$p = evt( 'evt_pat', 'customer.subscription.updated', sub_obj( 'sub_C', 'cus_C', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 503, 'stripe' ) === 'looth3',
       'patreon_user_id metadata resolves via lgpo_patreon_user_id' );

// unmatched: refuse + defer + notify, throttled
scenario(); flag_on();
$cid = seed_customer( 'stranger@nowhere.test', 'cus_D' );
confirm_with( [ 'sub_D' => sub_obj( 'sub_D', 'cus_D', 'active' ) ] );
$p = evt( 'evt_no1', 'customer.subscription.updated', sub_obj( 'sub_D', 'cus_D', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 503, 'unmatched customer -> 503 (defer; a bridge fix makes the retry land)' );
$note( $GLOBALS['MINTED'] === [], 'NO user was minted', json_encode( $GLOBALS['MINTED'] ) );
$note( count( $GLOBALS['NOTIFIED'] ) === 1, 'the refusal notified an operator', (string) count( $GLOBALS['NOTIFIED'] ) );
$note( ! processed( 'evt_no1' ), 'and is not marked processed' );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( count( $GLOBALS['NOTIFIED'] ) === 1, 'a redelivery within the throttle window does NOT re-notify' );
// ...and once the identity is fixed, the retry lands.
bridge( $cid, 501 );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'looth3',
       'after the operator bridges the member, the SAME event redelivery applies cleanly' );

// ------------------------------------------------ 10. checkout.session.completed closes the loop
echo "\n[10] checkout.session.completed — session metadata makes branch 2 live-able\n";
scenario(); flag_on();
// No local customer at all yet. The session carries the identity the member
// asserted at checkout (the creation side stamps it — see the companion gate).
confirm_with( [ 'sub_E' => sub_obj( 'sub_E', 'cus_E', 'active' ) ] );
$session = [
    'id' => 'cs_test_1', 'object' => 'checkout.session', 'mode' => 'subscription',
    'customer' => 'cus_E', 'subscription' => 'sub_E',
    'customer_details' => [ 'email' => 'relay-alias@privaterelay.test', 'name' => 'Member Two' ],
    'metadata' => [ 'wp_user_id' => '502' ],
];
$p = evt( 'evt_cs', 'checkout.session.completed', $session );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200, 'completed session ingests', json_encode( $res['body'] ?? [] ) );
$row = $GLOBALS['PDO']->query( "SELECT * FROM customers WHERE stripe_customer_id = 'cus_E'" )->fetch( PDO::FETCH_ASSOC );
$note( $row !== false, 'a local customer row was mirrored' );
$meta = json_decode( (string) ( $row['metadata'] ?? '' ), true ) ?: [];
$note( ( $meta['wp_user_id'] ?? null ) === '502',
       'session metadata wp_user_id was absorbed into customers.metadata — branch 2 now has data' );
$note( row_tier( 502, 'stripe' ) === 'looth3', 'and the member resolved through it: looth3, no email match, no mint' );
$note( $GLOBALS['MINTED'] === [], 'still nothing minted' );

// ------------------------------------------------ 11. journal-before-mutation (ross property)
echo "\n[11] the journal is written BEFORE the mutation, and no-ops are not journaled\n";
$jn = journal_rows();
$note( count( $jn ) === 1, 'one journal row for one applied change' );
// Re-confirming the same state must not journal again (idempotent redelivery
// under a NEW event id — dedupe doesn't catch it, the no-op check must).
confirm_with( [ 'sub_E' => sub_obj( 'sub_E', 'cus_E', 'active' ) ] );
$p = evt( 'evt_cs2', 'customer.subscription.updated', sub_obj( 'sub_E', 'cus_E', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && count( journal_rows() ) === 1,
       'same-state redelivery under a new event id: applied as a no-op, NOT re-journaled' );
$note( count( \LGMS\Arbiter::$calls ) === 1, 'and the Arbiter is not re-run for a no-op' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — OFF is silence, the interlock holds, signature is the auth, the confirm decides, looth3 is a ruling, retraction is NULL-not-delete, identity never mints, the journal precedes the write.\n";
exit( 0 );

}
