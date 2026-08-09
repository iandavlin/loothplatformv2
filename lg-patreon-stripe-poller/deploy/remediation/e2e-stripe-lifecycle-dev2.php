<?php
/**
 * DEV2 END-TO-END PROOF for the Stripe webhook lifecycle.
 *
 *   sudo -u looth-dev wp eval-file --path=/var/www/dev \
 *       /home/ubuntu/worktrees/stripe-build/lg-patreon-stripe-poller/deploy/remediation/e2e-stripe-lifecycle-dev2.php
 *   (append `keep` to skip cleanup for inspection)
 *
 * What this proves that the SQLite gate cannot: the same pipeline over the
 * REAL stack — real WP bootstrap, real lg_membership MySQL, the real
 * vendor \Stripe\Webhook signature verification, the real IdentityMatcher
 * against real usermeta, the real Arbiter writing real wp_capabilities.
 * Payloads are hand-signed TEST fixtures (the whsec pattern); the ONLY
 * seam is StripeLifecycle::$confirmFactory, because no Stripe key is
 * assumed on this box.
 *
 * SAFETY: all flags are injected via pre_option filters — NOTHING is
 * written to wp_options, so the box's real flag state (lifecycle OFF)
 * is untouched even if this dies mid-run. All fixtures are scratch rows
 * created here and torn down in cleanup. Live is never touched.
 *
 * The new classes are required from THIS worktree — the serving checkout
 * does not carry them yet (that is the point: flag-OFF merge first, then
 * this proof re-runs against the serve).
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$BASE = dirname( __DIR__, 2 );   // the worktree's lg-patreon-stripe-poller/

// --- load the new build out of the worktree -------------------------------
// CustomerRepo gained mergeMetadata in this branch; if the serving copy is
// already loaded in this process we cannot swap it and the absorb leg is
// skipped honestly rather than faked.
$absorb_testable = true;
if ( ! class_exists( '\\LGMS\\Repos\\CustomerRepo', false ) ) {
    require_once $BASE . '/src/Repos/CustomerRepo.php';
} elseif ( ! method_exists( '\\LGMS\\Repos\\CustomerRepo', 'mergeMetadata' ) ) {
    $absorb_testable = false;
}
require_once $BASE . '/src/StripeLifecycle.php';
require_once $BASE . '/src/Wp/WebhookRestController.php';

use LGMS\Db;
use LGMS\StripeLifecycle;

$pdo = Db::pdo();

// The journal table ships in Schema.php on this branch; the serving install
// has not run that migration yet. Same DDL, idempotent.
$pdo->exec( "CREATE TABLE IF NOT EXISTS lg_lifecycle_journal (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id     VARCHAR(64)     NULL,
    event_type   VARCHAR(64)     NOT NULL,
    wp_user_id   BIGINT UNSIGNED NOT NULL,
    customer_id  BIGINT UNSIGNED NULL,
    source       VARCHAR(32)     NOT NULL DEFAULT 'stripe',
    tier_before  VARCHAR(32)     NULL,
    had_row      TINYINT(1)      NOT NULL DEFAULT 0,
    tier_after   VARCHAR(32)     NULL,
    state        VARCHAR(32)     NOT NULL,
    note         VARCHAR(255)    NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (wp_user_id), KEY idx_event (event_id), KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

// --- in-process flags (never persisted) -----------------------------------
$WHSEC = 'whsec_e2e_' . bin2hex( random_bytes( 12 ) );
$GLOBALS['e2e_identity_gate'] = '1';
add_filter( 'pre_option_lgms_stripe_lifecycle',    static fn() => '1' );
add_filter( 'pre_option_lgms_identity_gate',       static fn() => $GLOBALS['e2e_identity_gate'] );
add_filter( 'pre_option_lgms_stripe_webhook_secret', static fn() => $WHSEC );

// --- helpers ---------------------------------------------------------------
$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $d !== '' ? "  ({$d})" : '' ); }
};
$sign = static function ( string $payload, string $secret, ?int $t = null ): string {
    $t = $t ?? time();
    return "t={$t},v1=" . hash_hmac( 'sha256', "{$t}.{$payload}", $secret );
};
$rand    = bin2hex( random_bytes( 4 ) );
$cusId   = "cus_e2e_{$rand}";
$subId   = "sub_e2e_{$rand}";
$email   = "e2e-{$rand}@lifecycle-e2e.test";

$sub_obj = static function ( string $status ) use ( $subId, $cusId ): array {
    return [
        'id' => $subId, 'object' => 'subscription', 'customer' => $cusId, 'status' => $status,
        'items' => [ 'data' => [ [ 'price' => [ 'id' => 'price_e2e_any' ] ] ] ],
        'cancel_at_period_end' => false,
        'current_period_start' => time() - 86400, 'current_period_end' => time() + 86400 * 29,
        'canceled_at' => $status === 'canceled' ? time() : null,
    ];
};
$evt = static function ( string $id, string $type, array $object ): string {
    return (string) json_encode( [ 'id' => $id, 'object' => 'event', 'type' => $type, 'data' => [ 'object' => $object ] ] );
};
$confirm = static function ( string $status ) use ( $sub_obj ): void {
    StripeLifecycle::$confirmFactory = static fn(): object => new class( $sub_obj( $status ) ) {
        public function __construct( private array $sub ) {}
        public function retrieveSubscription( string $id, array $expand = [] ): object {
            if ( $id !== $this->sub['id'] ) { throw new RuntimeException( "no such sub {$id}" ); }
            return json_decode( (string) json_encode( $this->sub ) );
        }
    };
};

$uid = 0; $localCustomer = 0;
$cleanup = function () use ( $pdo, &$uid, &$localCustomer, $cusId, $subId, $rand ) {
    // Rows first (so the deleted_user fan-out finds nothing), user last.
    if ( $localCustomer > 0 ) {
        $pdo->prepare( 'DELETE FROM entitlements WHERE customer_id = ?' )->execute( [ $localCustomer ] );
        $pdo->prepare( 'DELETE FROM subscriptions WHERE customer_id = ?' )->execute( [ $localCustomer ] );
        $pdo->prepare( 'DELETE FROM wp_user_bridge WHERE customer_id = ?' )->execute( [ $localCustomer ] );
        $pdo->prepare( 'DELETE FROM customers WHERE id = ?' )->execute( [ $localCustomer ] );
    }
    if ( $uid > 0 ) {
        $pdo->prepare( 'DELETE FROM lg_role_sources WHERE wp_user_id = ?' )->execute( [ $uid ] );
        $pdo->prepare( 'DELETE FROM lg_lifecycle_journal WHERE wp_user_id = ?' )->execute( [ $uid ] );
    }
    $pdo->prepare( "DELETE FROM lg_processed_events WHERE event_id LIKE ?" )->execute( [ "evt_e2e_{$rand}%" ] );
    if ( $uid > 0 ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $uid );
    }
};

echo "=== stripe lifecycle — dev2 end-to-end (real WP + MySQL + \\Stripe\\Webhook) ===\n";
if ( ! $absorb_testable ) {
    echo "  note: serving CustomerRepo already loaded without mergeMetadata — absorb leg SKIPPED\n";
}

try {

// --- fixtures --------------------------------------------------------------
$uid = wp_insert_user( [
    'user_login' => "e2e_lc_{$rand}",
    'user_email' => $email,
    'user_pass'  => wp_generate_password( 24, true, true ),
    'role'       => 'looth1',
] );
if ( is_wp_error( $uid ) ) { throw new RuntimeException( 'fixture user failed: ' . $uid->get_error_message() ); }
$uid = (int) $uid;

// Local customer mirrored with the checkout-asserted identity already
// absorbed (metadata.wp_user_id) — i.e. the state the completed-session
// ingest produces. NO bridge row: resolution must go through
// IdentityMatcher branch 2 against the real DB.
$pdo->prepare( 'INSERT INTO customers (uuid, stripe_customer_id, email, name, metadata) VALUES (?,?,?,?,?)' )
    ->execute( [ wp_generate_uuid4(), $cusId, $email, 'E2E Scratch', json_encode( [ 'wp_user_id' => (string) $uid ] ) ] );
$localCustomer = (int) $pdo->lastInsertId();

// --- 1. signature is the auth, over the REAL vendor lib --------------------
echo "\n[1] signature (real \\Stripe\\Webhook)\n";
$p   = $evt( "evt_e2e_{$rand}_1", 'customer.subscription.updated', $sub_obj( 'active' ) );
$res = StripeLifecycle::ingest( $p, $sign( $p, 'whsec_wrong' ) );
$note( $res['status'] === 400, 'wrong secret -> 400', (string) $res['status'] );
$res = StripeLifecycle::ingest( $p, $sign( $p, $WHSEC, time() - 3600 ) );
$note( $res['status'] === 400, 'stale timestamp -> 400 (replay window enforced by the real lib)' );
$st = $pdo->prepare( 'SELECT COUNT(*) FROM lg_role_sources WHERE wp_user_id = ? AND source = \'stripe\'' );
$st->execute( [ $uid ] );
$note( (int) $st->fetchColumn() === 0, 'nothing applied by the rejects' );

// --- 2. interlock ----------------------------------------------------------
echo "\n[2] identity-gate interlock\n";
$GLOBALS['e2e_identity_gate'] = '';
$res = StripeLifecycle::ingest( $p, $sign( $p, $WHSEC ) );
$note( $res['status'] === 503, 'identity gate dark -> 503, defer', (string) $res['status'] );
$GLOBALS['e2e_identity_gate'] = '1';

// --- 3. active -> looth3 through the REAL Arbiter --------------------------
echo "\n[3] active subscription -> looth3 (real roles, real journal)\n";
$confirm( 'active' );
$res = StripeLifecycle::ingest( $p, $sign( $p, $WHSEC ) );
$note( $res['status'] === 200, 'signed event accepted', json_encode( $res ) );
$st = $pdo->prepare( 'SELECT wp_user_id FROM wp_user_bridge WHERE customer_id = ?' );
$st->execute( [ $localCustomer ] );
$note( (int) $st->fetchColumn() === $uid, 'bridge written via IdentityMatcher branch 2 (metadata wp_user_id)' );
$st = $pdo->prepare( "SELECT tier FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
$st->execute( [ $uid ] );
$note( $st->fetchColumn() === 'looth3', 'lg_role_sources says looth3' );
clean_user_cache( $uid );
$u = get_user_by( 'id', $uid );
$note( in_array( 'looth3', (array) $u->roles, true ), 'REAL wp_capabilities carries looth3 (the Arbiter ran)', implode( ',', (array) $u->roles ) );
$note( ! in_array( 'looth1', (array) $u->roles, true ), 'single-tier: looth1 was shed' );
$st = $pdo->prepare( "SELECT COUNT(*) FROM entitlements WHERE customer_id = ? AND ref = 'looth3' AND revoked_at IS NULL" );
$st->execute( [ $localCustomer ] );
$note( (int) $st->fetchColumn() === 1, 'live looth3 entitlement backs the opinion' );
$st = $pdo->prepare( 'SELECT COUNT(*) FROM lg_lifecycle_journal WHERE wp_user_id = ? AND tier_after = \'looth3\'' );
$st->execute( [ $uid ] );
$note( (int) $st->fetchColumn() === 1, 'journal row recorded' );

// --- 4. dedupe -------------------------------------------------------------
echo "\n[4] dedupe against the real lg_processed_events\n";
$res = StripeLifecycle::ingest( $p, $sign( $p, $WHSEC ) );
$note( $res['status'] === 200 && ( $res['body']['result'] ?? '' ) === 'duplicate', 'replay -> duplicate, not re-applied' );
$st = $pdo->prepare( 'SELECT dup_count FROM lg_processed_events WHERE event_id = ?' );
$st->execute( [ "evt_e2e_{$rand}_1" ] );
$note( (int) $st->fetchColumn() >= 1, 'dup_count bumped for visibility' );

// --- 5. cancellation -> NULL, member falls back ----------------------------
echo "\n[5] canceled -> retraction (NULL row kept), member falls to looth1\n";
$confirm( 'canceled' );
$p2  = $evt( "evt_e2e_{$rand}_2", 'customer.subscription.deleted', $sub_obj( 'canceled' ) );
$res = StripeLifecycle::ingest( $p2, $sign( $p2, $WHSEC ) );
$note( $res['status'] === 200, 'cancellation accepted', json_encode( $res ) );
$st = $pdo->prepare( "SELECT tier, COUNT(*) AS n FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
$st->execute( [ $uid ] );
$r = $st->fetch( PDO::FETCH_ASSOC );
$note( (int) $r['n'] === 1 && $r['tier'] === null, 'opinion row KEPT with tier=NULL' );
clean_user_cache( $uid );
$u = get_user_by( 'id', $uid );
$note( in_array( 'looth1', (array) $u->roles, true ) && ! in_array( 'looth3', (array) $u->roles, true ),
       'real roles: looth3 gone, looth1 floor kept', implode( ',', (array) $u->roles ) );
$st = $pdo->prepare( 'SELECT COUNT(*) FROM entitlements WHERE customer_id = ? AND revoked_at IS NULL' );
$st->execute( [ $localCustomer ] );
$note( (int) $st->fetchColumn() === 0, 'entitlement revoked (row kept for audit)' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
echo $fail === 0
    ? "E2E GREEN — the full path holds on the real stack: sign, verify, dedupe, match, journal, arbitrate, retract.\n"
    : "E2E RED\n";

} catch ( \Throwable $e ) {
    printf( "\nE2E RED — threw: %s\n%s\n", $e->getMessage(), $e->getTraceAsString() );
    $fail++;
} finally {
    if ( in_array( 'keep', $args ?? [], true ) ) {
        echo "keep: fixtures left in place (uid={$uid}, customer={$localCustomer})\n";
    } else {
        try { $cleanup(); echo "cleanup: scratch user, customer, rows removed\n"; }
        catch ( \Throwable $e ) { printf( "cleanup FAILED: %s — remove uid=%d customer=%d by hand\n", $e->getMessage(), $uid, $localCustomer ); }
    }
}
