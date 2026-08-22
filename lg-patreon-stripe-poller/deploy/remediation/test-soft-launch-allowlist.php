<?php
/**
 * RED-FIRST GATE for the Stripe soft-launch allowlist
 * (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md; Ian 2026-08-11: "generate a
 * whitelist that we can soft launch on live").
 *
 *   php test-soft-launch-allowlist.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Exercises the REAL code — LGMS\StripeLifecycle + LGMS\CohortAllowlist —
 * over the same SQLite lg_membership rig as test-stripe-lifecycle.php.
 *
 * What must hold, in order of what it costs to get wrong:
 *
 *   1. EMPTY / ABSENT / MALFORMED cohort = CLOSED FOR EVERYONE. Flipping
 *      the lifecycle ON with no cohort grants NOBODY. This is the fail-safe
 *      the design doc demands be GATED, not just coded — the OFF-state-
 *      must-be-asserted rule.
 *   2. A skip is an ACKNOWLEDGED 200, marked processed — Stripe must not
 *      retry-storm a deliberate skip — and it is JOURNALED with
 *      'skipped: not in soft-launch cohort (uid=N)' so the audit trail
 *      shows every event the cohort turned away.
 *   3. The cohort is the ONLY discriminator: a byte-identical event grants
 *      when the member is on the list and skips when they are not.
 *   4. NOT-IN-COHORT MEANS NO MEMBERSHIP CHANGE IN EITHER DIRECTION —
 *      no entitlement write, no opinion write, no Arbiter call; a member
 *      pulled from the cohort is frozen, not half-retracted.
 *   5. The dash save path (CohortAllowlist) writes EXACTLY the option
 *      shape the lifecycle gate reads — a zero-indexed list of positive
 *      ints — so the Admin surface and the gate cannot drift.
 *   6. The allowlist arms nothing on its own: lifecycle OFF stays a total
 *      no-op with a populated cohort, and the identity-gate interlock
 *      still refuses regardless of the cohort.
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
foreach ( [ '/src/StripeLifecycle.php', '/src/CohortAllowlist.php', '/src/Wp/IdentityMatcher.php',
            '/src/RoleSourceWriter.php', '/src/Patreon/PatreonSourceReader.php',
            '/src/Repos/CustomerRepo.php', '/src/Repos/SubscriptionRepo.php', '/src/Repos/EntitlementRepo.php',
            '/src/Uuid.php', '/src/Wp/WebhookRestController.php',
            '/vendor/stripe/stripe-php/lib/WebhookSignature.php' ] as $f ) {
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
$GLOBALS['USERDATA_CALLS'] = [];   // #193 — the no-op spy

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
/**
 * #193 — the read-side union asks for the user behind an id. THE SPY IS THE
 * POINT: with no addresses listed this must NEVER be called, which is what
 * stands in for a flag on this issue (keeper ruling D2).
 */
function get_userdata( $id ) {
    $GLOBALS['USERDATA_CALLS'][] = (int) $id;
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
require_once $BASE . '/src/Repos/CustomerRepo.php';
require_once $BASE . '/src/Repos/SubscriptionRepo.php';
require_once $BASE . '/src/Repos/EntitlementRepo.php';
require_once $BASE . '/src/Uuid.php';
require_once $BASE . '/src/Wp/IdentityMatcher.php';
require_once $BASE . '/src/StripeLifecycle.php';
require_once $BASE . '/src/CohortAllowlist.php';
require_once $BASE . '/src/Wp/WebhookRestController.php';

use LGMS\CohortAllowlist;
use LGMS\StripeLifecycle;

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

    $GLOBALS['PDO'] = $pdo;
    $GLOBALS['OPTS'] = []; $GLOBALS['AUTOLOAD'] = []; $GLOBALS['LOG'] = [];
    $GLOBALS['ROUTES'] = []; $GLOBALS['MINTED'] = []; $GLOBALS['NOTIFIED'] = [];
    $GLOBALS['TRANSIENTS'] = [];
    $GLOBALS['FIX'] = [
        501 => [ 'uid' => 501, 'email' => 'bridged@x.test',  'roles' => [ 'looth1' ], 'pat_id' => '' ],
        502 => [ 'uid' => 502, 'email' => 'claimed@x.test',  'roles' => [ 'looth1' ], 'pat_id' => '' ],
    ];
    \LGMS\Db::$calls = 0;
    \LGMS\Arbiter::$calls = [];
    StripeLifecycle::$confirmFactory = null;
    StripeLifecycle::_resetForTests();
}

/** Lifecycle + interlock + secret armed. The cohort is DELIBERATELY not set here. */
function flag_on(): void {
    $GLOBALS['OPTS']['lgms_stripe_lifecycle']    = true;
    $GLOBALS['OPTS']['lgms_identity_gate']       = true;
    $GLOBALS['OPTS']['lgms_stripe_webhook_secret'] = WHSEC;
}

function cohort( $v ): void {
    $GLOBALS['OPTS'][ StripeLifecycle::ALLOWLIST_OPT ] = $v;
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
function live_entitlements( int $customerId ): int {
    $st = $GLOBALS['PDO']->prepare( 'SELECT COUNT(*) FROM entitlements WHERE customer_id = ? AND revoked_at IS NULL' );
    $st->execute( [ $customerId ] );
    return (int) $st->fetchColumn();
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

echo "=== stripe soft-launch allowlist — red-first gate ===\n";

// -------------------------------------- 1. empty/absent cohort = CLOSED for everyone
echo "\n[1] fail-safe — lifecycle ON + cohort ABSENT (and explicit []) grants NOBODY\n";
scenario(); flag_on();
$note( get_option( StripeLifecycle::ALLOWLIST_OPT, false ) === false, 'allowlist option is genuinely absent, not seeded' );
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$payload = evt( 'evt_closed1', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $payload, sign( $payload ) );
$note( $res['status'] === 200, 'absent cohort: skip is ACKNOWLEDGED 200 (no Stripe retry-storm)', (string) $res['status'] );
$note( str_contains( (string) ( $res['body']['result'] ?? '' ), 'skipped: not in soft-launch cohort (uid=501)' ),
       'and the result names the skip and the uid', json_encode( $res['body'] ?? [] ) );
$note( row_tier( 501, 'stripe' ) === 'ABSENT', 'NO opinion row was written' );
$note( \LGMS\Arbiter::$calls === [], 'the Arbiter never ran', implode( ',', \LGMS\Arbiter::$calls ) );
$note( live_entitlements( $cid ) === 0, 'NO entitlement was granted' );
$j = journal_rows();
$note( count( $j ) === 1 && $j[0]['state'] === 'skipped'
       && str_contains( (string) $j[0]['note'], 'not in soft-launch cohort (uid=501)' )
       && $j[0]['event_id'] === 'evt_closed1',
       'journal shows the skip: state=skipped, note names the cohort + uid, tied to the event id' );
$note( processed( 'evt_closed1' ), 'the skipped event IS marked processed (deliberate skip, not a defer)' );

cohort( [] );
$p2  = evt( 'evt_closed2', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $p2, sign( $p2 ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'ABSENT' && live_entitlements( $cid ) === 0,
       'explicit [] cohort: identical — acknowledged, nothing granted' );

// -------------------------------------- 2. the cohort is the ONLY discriminator
echo "\n[2] byte-identical event — grants IN cohort, skips OUT of cohort\n";
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$payload = evt( 'evt_disc1', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
cohort( [ 501 ] );
$res = StripeLifecycle::ingest( $payload, sign( $payload ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'looth3',
       'IN cohort: the active event grants looth3 (the normal lifecycle)' );
$note( \LGMS\Arbiter::$calls === [ 501 ], 'through the Arbiter' );

scenario(); flag_on();   // fresh world, SAME payload bytes — only the cohort differs
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
cohort( [ 502 ] );   // somebody else's soft launch
$res = StripeLifecycle::ingest( $payload, sign( $payload ) );
$note( $res['status'] === 200 && str_contains( (string) ( $res['body']['result'] ?? '' ), 'skipped: not in soft-launch cohort (uid=501)' ),
       'OUT of cohort: the byte-identical event is acknowledged and skipped', json_encode( $res['body'] ?? [] ) );
$note( row_tier( 501, 'stripe' ) === 'ABSENT' && \LGMS\Arbiter::$calls === [] && live_entitlements( $cid ) === 0,
       'and NOTHING moved — no opinion, no Arbiter, no entitlement' );

// 502 in the same world DOES transition while 501 stays skipped.
$cid2 = seed_customer( 'claimed@x.test', 'cus_B' );
bridge( $cid2, 502 );
confirm_with( [ 'sub_B' => sub_obj( 'sub_B', 'cus_B', 'active' ) ] );
$pB  = evt( 'evt_disc2', 'customer.subscription.updated', sub_obj( 'sub_B', 'cus_B', 'active' ) );
$res = StripeLifecycle::ingest( $pB, sign( $pB ) );
$note( $res['status'] === 200 && row_tier( 502, 'stripe' ) === 'looth3' && row_tier( 501, 'stripe' ) === 'ABSENT',
       'the cohort member (502) transitions in the same world the non-member (501) was skipped in' );

// -------------------------------------- 3. no membership change in EITHER direction
echo "\n[3] a member pulled from the cohort is FROZEN — cancels are skipped too, never half-retracted\n";
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
cohort( [ 501 ] );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$p = evt( 'evt_frz1', 'customer.subscription.created', sub_obj( 'sub_A', 'cus_A', 'active' ) );
StripeLifecycle::ingest( $p, sign( $p ) );
$note( row_tier( 501, 'stripe' ) === 'looth3', 'setup: granted while in the cohort' );

cohort( [] );   // pulled from the cohort mid-test
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'canceled' ) ] );
$p = evt( 'evt_frz2', 'customer.subscription.deleted', sub_obj( 'sub_A', 'cus_A', 'canceled' ) );
$before = live_entitlements( $cid );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && str_contains( (string) ( $res['body']['result'] ?? '' ), 'skipped' ),
       'the cancel event is acknowledged and skipped' );
$note( row_tier( 501, 'stripe' ) === 'looth3' && live_entitlements( $cid ) === $before,
       'opinion AND entitlement untouched — frozen, not half-retracted (retract by hand if access must end)' );
$j = journal_rows();
$last = end( $j );
$note( $last['state'] === 'skipped' && $last['tier_before'] === 'looth3'
       && (int) $last['had_row'] === 1 && $last['tier_after'] === 'looth3',
       'the skip journal row records the untouched state: looth3 -> looth3, had_row=1' );

// -------------------------------------- 4. malformed cohorts fail CLOSED
echo "\n[4] malformed option values fail CLOSED; numeric strings (wp-cli JSON) are accepted\n";
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$n = 0;
foreach ( [ 'a string, not an array', [ 'abc', -3, 0 ], 501, true ] as $bad ) {
    $n++;
    cohort( $bad );
    $p   = evt( "evt_bad{$n}", 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
    $res = StripeLifecycle::ingest( $p, sign( $p ) );
    $note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'ABSENT',
           'malformed cohort (' . gettype( $bad ) . ') -> CLOSED, acknowledged, nothing granted' );
}
cohort( [ '501' ] );   // wp option update ... --format=json can land string ids
$p   = evt( 'evt_str1', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'looth3',
       "a numeric-string id ('501') IS the member — hand-set JSON cohorts work" );

// -------------------------------------- 5. the dash save path writes the read shape
echo "\n[5] CohortAllowlist (the Admin dash's writer) — write shape === read shape, always\n";
scenario(); flag_on();
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );

$note( CohortAllowlist::add( 501 ) === true, 'add() reports the id landed' );
$note( get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 501 ],
       'the stored option is EXACTLY [501] — zero-indexed list of ints, nothing else',
       var_export( get_option( StripeLifecycle::ALLOWLIST_OPT ), true ) );
$p   = evt( 'evt_dash1', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 501, 'stripe' ) === 'looth3',
       'and the lifecycle READS that exact write: the dash-added member transitions' );

$note( CohortAllowlist::add( 501 ) === false && get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 501 ],
       're-adding is a no-op — no duplicate ids ever stored' );
$note( CohortAllowlist::add( 0 ) === false && CohortAllowlist::add( -7 ) === false
       && get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 501 ],
       'non-positive ids are refused, never stored' );

CohortAllowlist::add( 502 );
$note( get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 501, 502 ], 'second add: sorted int list [501,502]' );
$note( CohortAllowlist::ids() === [ 501, 502 ], 'ids() reads back through the SAME normalization the gate uses' );
$note( is_string( CohortAllowlist::addedAt( 501 ) ), 'date-added bookkeeping recorded for the dash table' );

$note( CohortAllowlist::remove( 501 ) === true && get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 502 ],
       'remove() takes the id back out, shape intact' );
$note( CohortAllowlist::remove( 501 ) === false, 'removing an absent id reports false' );
$note( CohortAllowlist::addedAt( 501 ) === null, 'and its date-added bookkeeping is cleared' );
$p   = evt( 'evt_dash2', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( str_contains( (string) ( $res['body']['result'] ?? '' ), 'skipped' ),
       'the removed member is skipped on the next event — remove really means out' );

// The bookkeeping option must never influence the gate.
$GLOBALS['OPTS'][ CohortAllowlist::ADDED_OPT ] = 'corrupted garbage';
$p   = evt( 'evt_dash3', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( str_contains( (string) ( $res['body']['result'] ?? '' ), 'skipped' ),
       'corrupting the date-added bookkeeping changes NOTHING about who transitions' );

// -------------------------------------- 6. the allowlist arms nothing on its own
echo "\n[6] a populated cohort cannot switch anything ON, and cannot bypass the interlock\n";
scenario();
cohort( [ 501 ] );   // cohort set, lifecycle flag ABSENT
\LGMS\Wp\WebhookRestController::maybeRegister();
$note( $GLOBALS['ROUTES'] === [], 'lifecycle OFF + cohort set: no route registered' );
$note( \LGMS\Db::$calls === 0 && $GLOBALS['LOG'] === [], 'no DB read, no log line — OFF is still silence' );

scenario();
$GLOBALS['OPTS']['lgms_stripe_lifecycle'] = true;
$GLOBALS['OPTS']['lgms_stripe_webhook_secret'] = WHSEC;
cohort( [ 501 ] );   // identity gate deliberately DARK
$cid = seed_customer( 'bridged@x.test', 'cus_A' );
bridge( $cid, 501 );
confirm_with( [ 'sub_A' => sub_obj( 'sub_A', 'cus_A', 'active' ) ] );
$p   = evt( 'evt_ilk1', 'customer.subscription.updated', sub_obj( 'sub_A', 'cus_A', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 503 && row_tier( 501, 'stripe' ) === 'ABSENT',
       'identity gate dark + member in cohort: still 503, still nothing — the interlock outranks the cohort' );

// -------------------------------------- 7. the list also takes ADDRESSES (#193)
//
// Ian, 2026-08-22: "I thought the whitelist would have them generating a wp-user
// like a normal new member join." THIS is where the real normalizer and the real
// read-side union are driven — gate 86 stubs StripeLifecycle deliberately, so if
// these assertions do not exist the class's own behaviour is untested.
echo "\n[7] the SAME option also takes email addresses — real normalizer, real union\n";

scenario();
cohort( [ 501, 'Tester@Example.COM ', '  second@example.test', 'not-an-email', '', 42, '77', [ 'nope' ], null, true ] );

$note( StripeLifecycle::allowlist() === [ 501 => true, 42 => true, 77 => true ],
       'allowlist() is UNCHANGED by address entries — ids and digit-strings only',
       var_export( StripeLifecycle::allowlist(), true ) );
$note( StripeLifecycle::allowlistEmails() === [ 'tester@example.com' => true, 'second@example.test' => true ],
       'allowlistEmails() trims, lower-cases, and drops everything that is not an address',
       var_export( StripeLifecycle::allowlistEmails(), true ) );
$note( StripeLifecycle::inCohortEmail( 'TESTER@EXAMPLE.COM' ) === true,
       'inCohortEmail() is case-insensitive' );
$note( StripeLifecycle::inCohortEmail( '  tester@example.com  ' ) === true, 'and trimmed' );
$note( StripeLifecycle::inCohortEmail( 'not-an-email' ) === false
       && StripeLifecycle::inCohortEmail( '' ) === false
       && StripeLifecycle::inCohortEmail( null ) === false,
       'a malformed, empty or absent address widens NOTHING' );
$note( StripeLifecycle::inCohortEmail( 'tester@example.com.evil.test' ) === false,
       'a near miss is not a match — the compare is exact' );

// ── the read-side union, through inCohort(), with the REAL class ──
scenario();
$GLOBALS['FIX'][ 601 ] = [ 'uid' => 601, 'email' => 'listed@example.test', 'roles' => [ 'looth1' ] ];
$GLOBALS['FIX'][ 602 ] = [ 'uid' => 602, 'email' => 'unlisted@example.test', 'roles' => [ 'looth1' ] ];
cohort( [ 'LISTED@example.test' ] );
$note( StripeLifecycle::inCohort( 601 ) === true,
       'inCohort() recognises a member whose ADDRESS is listed — without this the grant never lands' );
$note( StripeLifecycle::inCohort( 602 ) === false, 'and still refuses one whose address is not' );
$note( StripeLifecycle::inCohort( 0 ) === false && StripeLifecycle::inCohort( -1 ) === false,
       'a non-positive id is refused before anything is looked up' );

// ── THE NO-OP PROOF (keeper ruling D2 — this replaces a flag) ──
scenario();
$GLOBALS['FIX'][ 601 ] = [ 'uid' => 601, 'email' => 'listed@example.test', 'roles' => [ 'looth1' ] ];
cohort( [ 501, 502 ] );                       // plain ids: the world before #193
$GLOBALS['USERDATA_CALLS'] = [];
StripeLifecycle::inCohort( 501 );
StripeLifecycle::inCohort( 601 );
StripeLifecycle::inCohort( 999 );
$note( $GLOBALS['USERDATA_CALLS'] === [],
       'WITH NO ADDRESSES LISTED nothing resolves a user at all — the empty state IS the off state',
       var_export( $GLOBALS['USERDATA_CALLS'], true ) );

cohort( [ 501, 'listed@example.test' ] );      // the liveness partner
$GLOBALS['USERDATA_CALLS'] = [];
StripeLifecycle::inCohort( 601 );
$note( $GLOBALS['USERDATA_CALLS'] === [ 601 ],
       'and with ONE address listed it DOES resolve — or the assertion above is vacuous',
       var_export( $GLOBALS['USERDATA_CALLS'], true ) );

$GLOBALS['USERDATA_CALLS'] = [];
StripeLifecycle::inCohort( 501 );
$note( $GLOBALS['USERDATA_CALLS'] === [],
       'a member already on the ID list short-circuits — no lookup on the hot path either' );

// ── the whole point, end to end: a listed ADDRESS transitions ──
scenario(); flag_on();
$GLOBALS['FIX'][ 601 ] = [ 'uid' => 601, 'email' => 'newtester@example.test', 'roles' => [ 'looth1' ] ];
$cid = seed_customer( 'newtester@example.test', 'cus_E' );
bridge( $cid, 601 );
confirm_with( [ 'sub_E' => sub_obj( 'sub_E', 'cus_E', 'active' ) ] );
cohort( [ 'newtester@example.test' ] );        // listed by ADDRESS ONLY — no id anywhere
$p   = evt( 'evt_addr1', 'customer.subscription.updated', sub_obj( 'sub_E', 'cus_E', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( $res['status'] === 200 && row_tier( 601, 'stripe' ) === 'looth3',
       'a member listed ONLY by address transitions through the real webhook — the grant lands',
       'status=' . $res['status'] . ' tier=' . var_export( row_tier( 601, 'stripe' ), true ) );

// ...and striking the address freezes them again, in the same instant
scenario(); flag_on();
$GLOBALS['FIX'][ 601 ] = [ 'uid' => 601, 'email' => 'newtester@example.test', 'roles' => [ 'looth1' ] ];
$cid = seed_customer( 'newtester@example.test', 'cus_E' );
bridge( $cid, 601 );
confirm_with( [ 'sub_E' => sub_obj( 'sub_E', 'cus_E', 'active' ) ] );
cohort( [] );
$p   = evt( 'evt_addr2', 'customer.subscription.updated', sub_obj( 'sub_E', 'cus_E', 'active' ) );
$res = StripeLifecycle::ingest( $p, sign( $p ) );
$note( str_contains( (string) ( $res['body']['result'] ?? '' ), 'skipped' ) && row_tier( 601, 'stripe' ) === 'ABSENT',
       'removing the address freezes them again — the fence is read fresh, never cached across a change' );

// ── the dash writer: BOTH halves survive every edit (the silent-loss trap) ──
echo "\n[7b] CohortAllowlist keeps BOTH halves — the write() that would have eaten every address\n";
scenario();
$GLOBALS['FIX'][ 601 ] = [ 'uid' => 601, 'email' => 'kept@example.test', 'roles' => [ 'looth1' ] ];

$note( CohortAllowlist::addEmail( '  KEPT@example.test ' ) === true, 'addEmail() normalizes on the way in' );
$note( get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 'kept@example.test' ],
       'and stores the normalized form, so the reader cannot silently ignore it',
       var_export( get_option( StripeLifecycle::ALLOWLIST_OPT ), true ) );
$note( CohortAllowlist::addEmail( 'kept@example.test' ) === false, 're-adding an address is a no-op' );
$note( CohortAllowlist::addEmail( 'not-an-email' ) === false
       && CohortAllowlist::addEmail( '' ) === false
       && get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 'kept@example.test' ],
       'a malformed address is refused, never stored — the dash cannot show an entry that admits nobody' );

CohortAllowlist::add( 501 );
$note( get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 501, 'kept@example.test' ],
       'ADDING A MEMBER DOES NOT EAT THE ADDRESSES — ints first, then addresses',
       var_export( get_option( StripeLifecycle::ALLOWLIST_OPT ), true ) );
CohortAllowlist::add( 502 );
CohortAllowlist::remove( 501 );
$note( get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 502, 'kept@example.test' ],
       'and REMOVING one does not either — the trap that would have failed silently' );
$note( CohortAllowlist::emails() === [ 'kept@example.test' ] && CohortAllowlist::ids() === [ 502 ],
       'both halves read back through the SAME normalization the gate uses' );
$note( CohortAllowlist::count() === 2, 'count() is everyone on the list, so nothing can report EMPTY on a full one' );
$note( is_string( CohortAllowlist::addedAt( 'kept@example.test' ) ),
       'date-added bookkeeping survives a STRING key — an int-only cast would have dropped it' );
$note( is_string( CohortAllowlist::addedAt( 502 ) ), 'and still records ids' );

$note( CohortAllowlist::removeEmail( 'KEPT@example.test' ) === true,
       'removeEmail() matches however it is typed' );
$note( get_option( StripeLifecycle::ALLOWLIST_OPT ) === [ 502 ], 'the address is gone, the member untouched' );
$note( CohortAllowlist::removeEmail( 'kept@example.test' ) === false, 'removing an absent address reports false' );
$note( CohortAllowlist::addedAt( 'kept@example.test' ) === null, 'and its bookkeeping is cleared' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — empty is closed, a skip is a journaled 200, the cohort alone discriminates, out-of-cohort is frozen both directions, the dash writes what the gate reads, addresses admit people who have no account yet, and nothing arms by itself.\n";
exit( 0 );

}
