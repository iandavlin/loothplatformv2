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
// MUST be required from the worktree, and this is the FOURTH time this shape
// has bitten (#148): the mu-plugin's composer autoloader maps LGMS\ into the
// SERVING CHECKOUT, which does not carry a class added on a branch. So
// StripeLifecycle loads from here while MultiTier would resolve over there and
// not be found — every event reaching applyConfirmed then fatals into a 503
// "processing failed", which reads like a lifecycle defect rather than a
// missing require. (Production deploy is unaffected: a pull brings src/ with
// it and PSR-4 finds the class.)
require_once $BASE . '/src/Membership/MultiTier.php';
require_once $BASE . '/src/Repos/ProductRepo.php';
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
$GLOBALS['e2e_allowlist']     = [];   // armed with the fixture uid once it exists
add_filter( 'pre_option_lgms_stripe_lifecycle',    static fn() => '1' );
add_filter( 'pre_option_lgms_identity_gate',       static fn() => $GLOBALS['e2e_identity_gate'] );
add_filter( 'pre_option_lgms_stripe_webhook_secret', static fn() => $WHSEC );
// MULTI-TIER (#148). Default OFF for sections 1-6, which therefore keep
// asserting the pre-multi-tier constant exactly as they always did; section 7
// flips this global, never wp_options.
$GLOBALS['e2e_multi_tier'] = false;
add_filter( 'pre_option_lgms_multi_tier', static fn() => $GLOBALS['e2e_multi_tier'] ? '1' : false );
// Soft-launch cohort, shadowed in-process like every other flag here (an
// array short-circuits the pre_option filter fine; wp_options untouched).
add_filter( 'pre_option_' . StripeLifecycle::ALLOWLIST_OPT, static fn() => $GLOBALS['e2e_allowlist'] );

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

$uid = 0; $localCustomer = 0; $uidB = 0; $localCustomerB = 0; $uidC = 0; $localCustomerC = 0;
$cleanup = function () use ( $pdo, &$uid, &$localCustomer, &$uidB, &$localCustomerB, &$uidC, &$localCustomerC, $cusId, $subId, $rand ) {
    // Rows first (so the deleted_user fan-out finds nothing), users last.
    foreach ( [ $localCustomer, $localCustomerB, $localCustomerC ] as $c ) {
        if ( $c > 0 ) {
            $pdo->prepare( 'DELETE FROM entitlements WHERE customer_id = ?' )->execute( [ $c ] );
            $pdo->prepare( 'DELETE FROM subscriptions WHERE customer_id = ?' )->execute( [ $c ] );
            $pdo->prepare( 'DELETE FROM wp_user_bridge WHERE customer_id = ?' )->execute( [ $c ] );
            $pdo->prepare( 'DELETE FROM customers WHERE id = ?' )->execute( [ $c ] );
        }
    }
    foreach ( [ $uid, $uidB, $uidC ] as $u ) {
        if ( $u > 0 ) {
            $pdo->prepare( 'DELETE FROM lg_role_sources WHERE wp_user_id = ?' )->execute( [ $u ] );
            $pdo->prepare( 'DELETE FROM lg_lifecycle_journal WHERE wp_user_id = ?' )->execute( [ $u ] );
        }
    }
    $pdo->prepare( "DELETE FROM lg_processed_events WHERE event_id LIKE ?" )->execute( [ "evt_e2e_{$rand}%" ] );
    foreach ( [ $uid, $uidB, $uidC ] as $u ) {
        if ( $u > 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $u );
        }
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

// Soft-launch cohort armed for the fixture member: sections 1–5 are the
// IN-cohort path (spec side (a): a cohort member's active event grants
// looth3). Section 6 exercises the out-of-cohort and empty-cohort sides.
$GLOBALS['e2e_allowlist'] = [ $uid ];

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

// --- 6. soft-launch allowlist governs WHO ----------------------------------
echo "\n[6] soft-launch allowlist (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md) — cohort in, everyone else acknowledged + skipped\n";

// Second member, identical in every way, NOT in the cohort.
$cusIdB = "cus_e2f_{$rand}";
$subIdB = "sub_e2f_{$rand}";
$emailB = "e2e2-{$rand}@lifecycle-e2e.test";
$uidB   = wp_insert_user( [
    'user_login' => "e2e_lc2_{$rand}",
    'user_email' => $emailB,
    'user_pass'  => wp_generate_password( 24, true, true ),
    'role'       => 'looth1',
] );
if ( is_wp_error( $uidB ) ) { throw new RuntimeException( 'fixture user B failed: ' . $uidB->get_error_message() ); }
$uidB = (int) $uidB;
$pdo->prepare( 'INSERT INTO customers (uuid, stripe_customer_id, email, name, metadata) VALUES (?,?,?,?,?)' )
    ->execute( [ wp_generate_uuid4(), $cusIdB, $emailB, 'E2E Scratch B', json_encode( [ 'wp_user_id' => (string) $uidB ] ) ] );
$localCustomerB = (int) $pdo->lastInsertId();

$sub_objB = static function ( string $status ) use ( $subIdB, $cusIdB ): array {
    return [
        'id' => $subIdB, 'object' => 'subscription', 'customer' => $cusIdB, 'status' => $status,
        'items' => [ 'data' => [ [ 'price' => [ 'id' => 'price_e2e_any' ] ] ] ],
        'cancel_at_period_end' => false,
        'current_period_start' => time() - 86400, 'current_period_end' => time() + 86400 * 29,
        'canceled_at' => null,
    ];
};
$confirmB = static function ( string $status ) use ( $sub_objB ): void {
    StripeLifecycle::$confirmFactory = static fn(): object => new class( $sub_objB( $status ) ) {
        public function __construct( private array $sub ) {}
        public function retrieveSubscription( string $id, array $expand = [] ): object {
            if ( $id !== $this->sub['id'] ) { throw new RuntimeException( "no such sub {$id}" ); }
            return json_decode( (string) json_encode( $this->sub ) );
        }
    };
};

// (b) NON-cohort member, structurally identical active event: acknowledged,
// skipped, journaled — and the REAL wp_capabilities never move.
$confirmB( 'active' );
$p6  = $evt( "evt_e2e_{$rand}_6a", 'customer.subscription.updated', $sub_objB( 'active' ) );
$res = StripeLifecycle::ingest( $p6, $sign( $p6, $WHSEC ) );
$note( $res['status'] === 200, 'non-cohort active event -> 200 acknowledged (no Stripe retry-storm)', json_encode( $res ) );
$note( str_contains( (string) ( $res['body']['result'] ?? '' ), "skipped: not in soft-launch cohort (uid={$uidB})" ),
       'and the result names the skip + uid', json_encode( $res['body'] ?? [] ) );
$st = $pdo->prepare( "SELECT COUNT(*) FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
$st->execute( [ $uidB ] );
$note( (int) $st->fetchColumn() === 0, 'NO stripe opinion row for the non-cohort member' );
clean_user_cache( $uidB );
$uB = get_user_by( 'id', $uidB );
$note( in_array( 'looth1', (array) $uB->roles, true ) && ! in_array( 'looth3', (array) $uB->roles, true ),
       'REAL wp_capabilities untouched: still looth1, no looth3', implode( ',', (array) $uB->roles ) );
$st = $pdo->prepare( 'SELECT COUNT(*) FROM entitlements WHERE customer_id = ? AND revoked_at IS NULL' );
$st->execute( [ $localCustomerB ] );
$note( (int) $st->fetchColumn() === 0, 'NO entitlement granted behind the skip' );
$st = $pdo->prepare( "SELECT COUNT(*) FROM lg_lifecycle_journal WHERE wp_user_id = ? AND state = 'skipped' AND note LIKE '%not in soft-launch cohort%'" );
$st->execute( [ $uidB ] );
$note( (int) $st->fetchColumn() === 1, 'the skip is JOURNALED (state=skipped, note names the cohort)' );
$st = $pdo->prepare( 'SELECT COUNT(*) FROM lg_processed_events WHERE event_id = ?' );
$st->execute( [ "evt_e2e_{$rand}_6a" ] );
$note( (int) $st->fetchColumn() === 1, 'the skipped event is marked processed — a deliberate skip, not a defer' );

// (c) EMPTY cohort + lifecycle ON = no-op for EVERYONE, including the
// member who was in the cohort for sections 1–5.
$GLOBALS['e2e_allowlist'] = [];
$p6b = $evt( "evt_e2e_{$rand}_6b", 'customer.subscription.updated', $sub_objB( 'active' ) );
$res = StripeLifecycle::ingest( $p6b, $sign( $p6b, $WHSEC ) );
$st = $pdo->prepare( "SELECT COUNT(*) FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
$st->execute( [ $uidB ] );
$note( $res['status'] === 200 && (int) $st->fetchColumn() === 0,
       'empty cohort: member B still skipped', json_encode( $res['body'] ?? [] ) );
$confirm( 'active' );
$p6c = $evt( "evt_e2e_{$rand}_6c", 'customer.subscription.updated', $sub_obj( 'active' ) );
$res = StripeLifecycle::ingest( $p6c, $sign( $p6c, $WHSEC ) );
$st = $pdo->prepare( "SELECT tier FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
$st->execute( [ $uid ] );
clean_user_cache( $uid );
$uA = get_user_by( 'id', $uid );
$note( $res['status'] === 200
       && str_contains( (string) ( $res['body']['result'] ?? '' ), 'skipped: not in soft-launch cohort' )
       && $st->fetchColumn() === null
       && ! in_array( 'looth3', (array) $uA->roles, true ),
       'empty cohort: even the section-1–5 member is skipped — tier stays NULL, no looth3 back',
       json_encode( $res['body'] ?? [] ) );

// (a′) adding the member = editing the option, no redeploy: the SAME shape
// of event then grants.
$GLOBALS['e2e_allowlist'] = [ $uidB ];
$confirmB( 'active' );
$p6d = $evt( "evt_e2e_{$rand}_6d", 'customer.subscription.updated', $sub_objB( 'active' ) );
$res = StripeLifecycle::ingest( $p6d, $sign( $p6d, $WHSEC ) );
$note( $res['status'] === 200, 'after ADDING member B to the cohort the same-shaped event is accepted', json_encode( $res ) );
$st = $pdo->prepare( "SELECT tier FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
$st->execute( [ $uidB ] );
$note( $st->fetchColumn() === 'looth3', 'and grants looth3 — add-to-cohort is an option edit, not a redeploy' );
clean_user_cache( $uidB );
$uB = get_user_by( 'id', $uidB );
$note( in_array( 'looth3', (array) $uB->roles, true ), 'REAL wp_capabilities carries looth3 for the added member', implode( ',', (array) $uB->roles ) );

// --- 7. MULTI-TIER: the tier granted is the tier PAID for (#148) ------------
echo "\n[7] multi-tier — the REAL catalogue decides the tier, on the real stack\n";

// Prices come from the REAL dev2 catalogue, resolved at runtime. A hardcoded
// price id would rot the first time the dash sets a new one, and would then
// fail as if the feature were broken.
$liteRow = $pdo->query(
    "SELECT pr.stripe_price_id FROM prices pr
       JOIN products p ON p.id = pr.product_id
      WHERE p.ref = 'looth2' AND p.kind = 'membership' AND p.active = 1
        AND p.region_tag IS NULL AND pr.type = 'recurring' AND pr.`interval` = 'month'
      ORDER BY pr.id DESC LIMIT 1"
)->fetchColumn();

if ( $liteRow === false ) {
    $note( false, 'the catalogue holds an active looth2 monthly price to test with',
           'no looth2 price on this box — import the catalogue' );
} else {
    $cusIdC = "cus_e2g_{$rand}";
    $subIdC = "sub_e2g_{$rand}";
    $emailC = "e2e3-{$rand}@lifecycle-e2e.test";
    $uidC   = wp_insert_user( [
        'user_login' => "e2e_lc3_{$rand}",
        'user_email' => $emailC,
        'user_pass'  => wp_generate_password( 24, true, true ),
        'role'       => 'looth1',
    ] );
    if ( is_wp_error( $uidC ) ) { throw new RuntimeException( 'fixture user C failed: ' . $uidC->get_error_message() ); }
    $uidC = (int) $uidC;
    $pdo->prepare( 'INSERT INTO customers (uuid, stripe_customer_id, email, name, metadata) VALUES (?,?,?,?,?)' )
        ->execute( [ wp_generate_uuid4(), $cusIdC, $emailC, 'E2E Scratch C', json_encode( [ 'wp_user_id' => (string) $uidC ] ) ] );
    $localCustomerC = (int) $pdo->lastInsertId();
    // A LIST of ids, which is what StripeLifecycle::allowlist() normalizes: it
    // iterates VALUES, so the [id => true] shape silently reads as an EMPTY
    // cohort and every event below is skipped with a 200 that looks like a pass.
    $GLOBALS['e2e_allowlist'] = [ $uid, $uidB, $uidC ];

    $sub_objC = static function ( string $status, string $price ) use ( $subIdC, $cusIdC ): array {
        return [
            'id' => $subIdC, 'object' => 'subscription', 'customer' => $cusIdC, 'status' => $status,
            'items' => [ 'data' => [ [ 'price' => [ 'id' => $price ] ] ] ],
            'cancel_at_period_end' => false,
            'current_period_start' => time() - 86400, 'current_period_end' => time() + 86400 * 29,
            'canceled_at' => null,
        ];
    };
    $confirmC = static function ( string $status, string $price ) use ( $sub_objC ): void {
        StripeLifecycle::$confirmFactory = static fn(): object => new class( $sub_objC( $status, $price ) ) {
            public function __construct( private array $sub ) {}
            public function retrieveSubscription( string $id, array $expand = [] ): object {
                if ( $id !== $this->sub['id'] ) { throw new RuntimeException( "no such sub {$id}" ); }
                return json_decode( (string) json_encode( $this->sub ) );
            }
        };
    };

    // (a) FLAG OFF on a LITE price — the pre-#148 behaviour, reproduced on the
    // real stack. This is the DEFECT: $5 Lite buys Pro. Asserting it here is
    // what makes the ON leg below meaningful rather than merely green.
    $GLOBALS['e2e_multi_tier'] = false;
    $confirmC( 'active', (string) $liteRow );
    $p7a = $evt( "evt_e2e_{$rand}_7a", 'customer.subscription.created', $sub_objC( 'active', (string) $liteRow ) );
    $r7a = StripeLifecycle::ingest( $p7a, $sign( $p7a, $WHSEC ) );
    $note( $r7a['status'] === 200, 'flag OFF: a LITE subscription is accepted', json_encode( $r7a ) );
    clean_user_cache( $uidC );
    $uC = get_user_by( 'id', $uidC );
    $note( in_array( 'looth3', (array) $uC->roles, true ),
           'flag OFF reproduces the defect: the LITE buyer really is granted looth3 (Pro)',
           implode( ',', (array) $uC->roles ) );

    // (b) FLAG ON, same member, same price — the tier follows the money.
    $GLOBALS['e2e_multi_tier'] = true;
    $confirmC( 'active', (string) $liteRow );
    $p7b = $evt( "evt_e2e_{$rand}_7b", 'customer.subscription.updated', $sub_objC( 'active', (string) $liteRow ) );
    $r7b = StripeLifecycle::ingest( $p7b, $sign( $p7b, $WHSEC ) );
    $note( $r7b['status'] === 200, 'flag ON: the same event is accepted', json_encode( $r7b ) );

    $st = $pdo->prepare( "SELECT tier FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'" );
    $st->execute( [ $uidC ] );
    $note( $st->fetchColumn() === 'looth2', 'lg_role_sources now says looth2 — resolved from the real catalogue' );

    $st = $pdo->prepare( "SELECT ref FROM entitlements WHERE customer_id = ? AND kind = 'membership_tier' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1" );
    $st->execute( [ $localCustomerC ] );
    $note( $st->fetchColumn() === 'looth2', 'the live entitlement is looth2, and the looth3 one was revoked' );

    clean_user_cache( $uidC );
    $uC = get_user_by( 'id', $uidC );
    $note( in_array( 'looth2', (array) $uC->roles, true ),
           'REAL wp_capabilities carries looth2 (the Arbiter ran on the real stack)',
           implode( ',', (array) $uC->roles ) );
    $note( ! in_array( 'looth3', (array) $uC->roles, true ),
           '...and looth3 is GONE — the over-grant is corrected, not merely added to',
           implode( ',', (array) $uC->roles ) );

    // (c) An unmapped price must NEVER retract a paying member.
    $confirmC( 'active', 'price_not_in_this_catalogue' );
    $p7c = $evt( "evt_e2e_{$rand}_7c", 'customer.subscription.updated', $sub_objC( 'active', 'price_not_in_this_catalogue' ) );
    $r7c = StripeLifecycle::ingest( $p7c, $sign( $p7c, $WHSEC ) );
    $note( $r7c['status'] === 200, 'an unmapped price is still accepted', json_encode( $r7c ) );
    clean_user_cache( $uidC );
    $uC = get_user_by( 'id', $uidC );
    $note( in_array( StripeLifecycle::TIER, (array) $uC->roles, true ),
           'an unmapped price falls back to the constant — a payer is never demoted to looth1',
           implode( ',', (array) $uC->roles ) );

    $GLOBALS['e2e_multi_tier'] = false;
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
echo $fail === 0
    ? "E2E GREEN — the full path holds on the real stack: sign, verify, dedupe, match, journal, arbitrate, retract — and the soft-launch cohort alone decides who.\n"
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
