<?php
/**
 * GATE 93 — A STRIPE PRODUCT'S TIER IS SET FROM THE DASH, AND CHECKOUT AGREES.
 *
 *   php tools/gates/products-tab-gate.php
 *
 * Exit 0 green, 1 a real defect, 2 cannot run (run-all.sh's convention).
 *
 * WHAT THIS EXISTS FOR (#194). Ian, 2026-08-22: "Do we have a spot in the dash
 * where we register the stripe products. Like looth-lite regional A ?" There
 * was none. `Membership\Health` REPORTED unmapped products and nothing anywhere
 * could SET one, so registering the live catalogue meant hand-run UPDATE
 * statements against the live database on launch night.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ THE ASSERTION THAT LOOKS RIGHT AND MEASURES NOTHING
 * ---------------------------------------------------------------------------
 * "Mapping a product to Pro makes checkout accept it" passes on a writer that
 * ignores its argument and hardcodes `looth3` — which is EXACTLY the defect
 * #148 shipped and #148's gate missed, at the cost of a rewrite. So §A maps one
 * product to **looth2** and a second to **looth3** and requires the REAL
 * `PdoProductRepository::tierForPrice()` to return each one back. A constant
 * cannot satisfy both.
 *
 * ⚠️ THE SECOND ONE: A REFUSAL MUST BE A REFUSAL, NOT A FALLBACK. §B refuses
 * three tiers — one that is not a role at all, and looth1 and looth4, which ARE
 * real roles and must still never be sold — and asserts after each that the row
 * is byte-identical and that NO audit line was written. A validator that
 * quietly fell back to the default tier would pass a test that only checked
 * "an exception was thrown somewhere".
 *
 * ⚠️ THE THIRD: THE TWO SCREENS MAY NEVER DISAGREE. The issue asks for red rows
 * "matching what Health already reports". §C runs the REAL `Health` catalogue
 * check and the REAL `ProductCatalog` over the SAME database across five
 * fixtures and fails if the numbers differ by one. A shared constant would not
 * catch a divergent WHERE clause; running both does.
 *
 * WHAT IS REAL AND WHAT IS STUBBED. `Membership\ProductCatalog`,
 * `ProductsPanel`, `Membership\Health`, `StripePrice`, the billing app's
 * `PdoProductRepository` and its `ProductSyncHandler` are ALL the real code,
 * running real SQL against a real (in-memory SQLite) database — including the
 * transaction, the rollback and the audit insert. WordPress's option store,
 * escaping, nonce and redirect helpers are observable stand-ins. There is no
 * browser, no FPM, no WordPress, no Stripe and no network, so this gate cannot
 * go vacuously green behind a locked-out browser.
 *
 * ⚠️ ONE BEHAVIOUR IS DELIBERATELY ASSERTED BY SOURCE AND SAYS SO. The real
 * `upsertProduct` is MySQL-only (`ON DUPLICATE KEY UPDATE`), so it cannot be
 * executed against SQLite. §D therefore does BOTH halves honestly: it drives
 * the REAL `ProductSyncHandler` through the real interface and proves the
 * handler never supplies a tier, and it reads `upsertProduct`'s own SQL through
 * PHP's tokenizer and proves the update clause names neither `ref` nor `kind`.
 * Neither half alone would be enough, and pretending to run the upsert would be
 * worse than saying which half is which.
 *
 * ⚠️ IT DELIBERATELY DOES NOT LOAD Admin.php for behaviour. That file reaches
 * StripeLifecycle, StripePrice, Invites, CompExpiry and MemberTools, and its
 * neighbouring test file has died at exit 255 with NO FAIL LINE three separate
 * times because the door gained a dependency nobody added to a require list.
 * §G reads its wiring through the tokenizer instead — never a regex, because
 * gate 90's equivalent assertions twice matched their own explanatory prose.
 *
 * RED-FIRST: see tools/gates/products-tab-redfirst.py.
 */

declare(strict_types=1);

namespace {

$GATE_ROOT = dirname( __DIR__, 2 );

$pass = 0; $fail = 0; $reports = [];

function ok( string $m ): void      { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void     { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function report( string $m ): void  { global $reports; $reports[] = $m; }
/* EXIT 2, NOT 3. run-all.sh reads 0 green / 2 CANNOT RUN / anything else RED,
   so a cannot-run that exits 3 reports a missing environment as a finding and
   blocks every lane behind it. */
function cannot( string $why ): void { echo "CANNOT RUN: $why\n"; exit( 2 ); }
function section( string $t ): void { echo "\n$t\n"; }

/**
 * ⚠️ A FATAL MUST STILL PRINT A FAIL LINE. The test file next door has died at
 * exit 255 with NO FAIL LINE three separate times, and a red nobody can
 * attribute costs a lane an hour. An uncaught throw is a finding like any
 * other, so it is reported as one and named for where it happened.
 */
set_exception_handler( static function ( \Throwable $e ): void {
    echo '  FAIL Z.fatal uncaught ' . get_class( $e ) . ' — ' . $e->getMessage()
       . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
    echo "\nGATE 93 RED — the run did not finish\n";
    exit( 1 );
} );

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
    cannot( 'pdo_sqlite is not available; every question here runs real SQL' );
}

$NEED = [
    '/lg-patreon-stripe-poller/src/Membership/ProductCatalog.php',
    '/lg-patreon-stripe-poller/src/ProductsPanel.php',
    '/lg-patreon-stripe-poller/src/Membership/Health.php',
    '/lg-patreon-stripe-poller/src/StripePrice.php',
    '/lg-patreon-stripe-poller/src/StripeLifecycle.php',
    '/lg-patreon-stripe-poller/src/Admin.php',
    '/lg-stripe-billing/src/Domain/Repositories/ProductRepository.php',
    '/lg-stripe-billing/src/Adapters/PdoProductRepository.php',
    '/lg-stripe-billing/src/Core/ProductSyncHandler.php',
    '/lg-stripe-billing/src/Core/CheckoutService.php',
    '/lg-stripe-billing/bin/stripe-import-catalog.php',
];
foreach ( $NEED as $need ) {
    if ( ! is_readable( $GATE_ROOT . $need ) ) { cannot( "missing $need" ); }
}

// ---- WordPress stand-ins ---------------------------------------------------

$OPTS     = [];
$CAPS     = true;          // current_user_can
$NONCE_OK = true;          // check_admin_referer
$UID      = 7;
$REDIRECT = '';
$DIED     = '';

final class GateRedirect extends \Exception {}
final class GateDied extends \Exception {}

function get_option( $k, $d = false ) { global $OPTS; return array_key_exists( $k, $OPTS ) ? $OPTS[ $k ] : $d; }
function update_option( $k, $v, $a = null ) { global $OPTS; $OPTS[ $k ] = $v; return true; }
function delete_option( $k ) { global $OPTS; unset( $OPTS[ $k ] ); return true; }

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )  { return (string) $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) {
    $out = '<input type="hidden" name="' . esc_attr( $n ) . '" value="gate-nonce">';
    if ( $e ) { echo $out; }
    return $out;
}
function selected( $a, $b = true, $e = true ) {
    $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
    if ( $e ) { echo $out; }
    return $out;
}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ) ?? ''; }
function current_user_can( $c ) { global $CAPS; return (bool) $CAPS; }
function get_current_user_id() { global $UID; return (int) $UID; }
function check_admin_referer( $a = -1, $q = '_wpnonce' ) {
    global $NONCE_OK;
    if ( ! $NONCE_OK ) { throw new GateDied( 'nonce' ); }
    return 1;
}
function wp_die( $m = '' ) { throw new GateDied( (string) $m ); }
function wp_safe_redirect( $u, $s = 302 ) { global $REDIRECT; $REDIRECT = (string) $u; throw new GateRedirect( (string) $u ); }
function add_query_arg( ...$a ) {
    $args = $a[0]; $url = $a[1] ?? '';
    $q = [];
    foreach ( $args as $k => $v ) { $q[] = rawurlencode( (string) $k ) . '=' . ( is_string( $v ) ? $v : rawurlencode( (string) $v ) ); }
    return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . implode( '&', $q );
}
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function has_filter( ...$a ) { return false; }
function wp_remote_post( ...$a ) { return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ]; }
function is_wp_error( $x ) { return false; }

/**
 * WP roles. docs/TIER-TAXONOMY.md names these the system of record for user
 * tier, and ProductCatalog's second lock reads them: a tier this box cannot
 * grant is not a tier. All four exist on dev2 and live.
 */
$ROLES = [ 'looth1' => true, 'looth2' => true, 'looth3' => true, 'looth4' => true, 'administrator' => true ];
function get_role( $r ) { global $ROLES; return isset( $ROLES[ (string) $r ] ) ? (object) [ 'name' => $r ] : null; }

} // namespace

// ---------------------------------------------------------------------------
// A REAL database. Every query below actually runs.
// ---------------------------------------------------------------------------

namespace LGMS {

use PDO;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if ( ! empty( $GLOBALS['DB_BROKEN'] ) ) {
            throw new RuntimeException( 'SQLSTATE[HY000] [2002] Connection refused' );
        }
        if ( self::$pdo instanceof PDO ) { return self::$pdo; }
        $pdo = new PDO( 'sqlite::memory:', null, null, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
        self::schema( $pdo );
        return self::$pdo = $pdo;
    }

    public static function schema( PDO $pdo ): void
    {
        $pdo->exec( 'CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, stripe_product_id TEXT UNIQUE, kind TEXT,
            ref TEXT, region_tag TEXT, name TEXT, active INTEGER)' );
        $pdo->exec( 'CREATE TABLE IF NOT EXISTS prices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, stripe_price_id TEXT UNIQUE,
            type TEXT, "interval" TEXT, unit_amount_cents INTEGER, currency TEXT,
            region_tag TEXT, priority INTEGER, active INTEGER)' );
        $pdo->exec( 'CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT, actor_ref TEXT, subject_type TEXT,
            subject_id INTEGER, action TEXT, details TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)' );
        $pdo->exec( 'CREATE TABLE IF NOT EXISTS customers (id INTEGER PRIMARY KEY, created_at TEXT)' );
        $pdo->exec( 'CREATE TABLE IF NOT EXISTS subscriptions (id INTEGER PRIMARY KEY, created_at TEXT)' );
    }

    public static function wipe(): void
    {
        $p = self::pdo();
        foreach ( [ 'products', 'prices', 'audit_log', 'customers', 'subscriptions' ] as $t ) {
            $p->exec( "DELETE FROM $t" );
        }
    }
}

} // namespace LGMS

namespace {

require $GATE_ROOT . '/lg-patreon-stripe-poller/src/StripeLifecycle.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/StripePrice.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/ProductCatalog.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/Health.php';
require $GATE_ROOT . '/lg-patreon-stripe-poller/src/ProductsPanel.php';
require $GATE_ROOT . '/lg-stripe-billing/src/Domain/Repositories/ProductRepository.php';
require $GATE_ROOT . '/lg-stripe-billing/src/Adapters/PdoProductRepository.php';
require $GATE_ROOT . '/lg-stripe-billing/src/Core/ProductSyncHandler.php';

use LGMS\Db;
use LGMS\Membership\ProductCatalog;
use LGMS\ProductsPanel;

/* Exercise the test seam too, so the seam itself cannot rot. */
ProductCatalog::$pdoFactory = static fn() => Db::pdo();

/* ------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* ------------------------------------------------------------------------- */

/** A file's CODE ONLY — PHP's own tokenizer, never a regex. See the header. */
function php_code_only( string $path ): string {
    $out = '';
    foreach ( token_get_all( (string) file_get_contents( $path ) ) as $t ) {
        if ( is_array( $t ) ) {
            if ( $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT ) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

/** Every string LITERAL in a file, comments already gone. */
function php_string_literals( string $path ): array {
    $out = [];
    foreach ( token_get_all( (string) file_get_contents( $path ) ) as $t ) {
        if ( is_array( $t ) && ( $t[0] === T_CONSTANT_ENCAPSED_STRING || $t[0] === T_ENCAPSED_AND_WHITESPACE ) ) {
            $out[] = trim( $t[1], "'\"" );
        }
    }
    return $out;
}

/**
 * One PHP function's body, brace-matched.
 *
 * ⚠️ A FIXED-WIDTH WINDOW IS A REAL GATE DEFECT, not a style preference: gate
 * 90's substr(..., 2200) ran past one handler into its neighbour, so deleting
 * the first one's check_admin_referer stayed GREEN on the NEIGHBOUR's guard.
 */
function php_function_body( string $code, string $name ): string {
    $at = strpos( $code, "function $name(" );
    if ( $at === false ) { return ''; }
    $open = strpos( $code, '{', $at );
    if ( $open === false ) { return ''; }
    $depth = 0;
    for ( $i = $open, $n = strlen( $code ); $i < $n; $i++ ) {
        if ( $code[ $i ] === '{' ) { $depth++; }
        elseif ( $code[ $i ] === '}' ) {
            $depth--;
            if ( $depth === 0 ) { return substr( $code, $at, $i - $at + 1 ); }
        }
    }
    return substr( $code, $at );
}

/**
 * The `products` columns a file's SQL actually WRITES, read out of its string
 * literals. This is the drift check that keeps the dash and the import script
 * meaning the same thing by "registering a product".
 */
function written_product_columns( string $path ): array {
    $cols = [];
    foreach ( php_string_literals( $path ) as $lit ) {
        if ( preg_match_all( '/\b(ref|kind|region_tag)\s*=/', $lit, $m ) ) {
            foreach ( $m[1] as $c ) { $cols[ $c ] = true; }
        }
    }
    $out = array_keys( $cols );
    sort( $out );
    return $out;
}

function seed_product( array $p ): int {
    $pdo = Db::pdo();
    $st  = $pdo->prepare( 'INSERT INTO products (stripe_product_id, kind, ref, region_tag, name, active)
                           VALUES (?, ?, ?, ?, ?, ?)' );
    $st->execute( [
        $p['sid'], $p['kind'] ?? 'membership', $p['ref'] ?? null,
        $p['region'] ?? null, $p['name'] ?? 'A product', (int) ( $p['active'] ?? 1 ),
    ] );
    return (int) $pdo->lastInsertId();
}

function seed_price( int $productId, string $priceId, array $o = [] ): void {
    $st = Db::pdo()->prepare( 'INSERT INTO prices (product_id, stripe_price_id, type, "interval",
                                unit_amount_cents, currency, region_tag, priority, active)
                               VALUES (?, ?, ?, ?, ?, ?, NULL, 100, ?)' );
    $st->execute( [
        $productId, $priceId, $o['type'] ?? 'recurring', $o['interval'] ?? 'month',
        $o['cents'] ?? 500, $o['currency'] ?? 'usd', (int) ( $o['active'] ?? 1 ),
    ] );
}

function row_for( string $sid ): ?array {
    $st = Db::pdo()->prepare( 'SELECT * FROM products WHERE stripe_product_id = ?' );
    $st->execute( [ $sid ] );
    $r = $st->fetch( PDO::FETCH_ASSOC );
    return $r ?: null;
}

function audit_rows(): array {
    return Db::pdo()->query( 'SELECT * FROM audit_log ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );
}

/** The REAL billing-app predicate that decides whether checkout refuses. */
function checkout_tier_for( string $priceId ): ?string {
    $repo = new \LGSB\Adapters\PdoProductRepository( Db::pdo() );
    return $repo->tierForPrice( $priceId );
}

function render_panel(): string {
    ob_start();
    ProductsPanel::render();
    return (string) ob_get_clean();
}

/** Health's catalogue answer, driven for real. */
function health_catalogue(): array {
    $m = new ReflectionMethod( \LGMS\Membership\Health::class, 'checkCatalogue' );
    $m->setAccessible( true );
    return $m->invoke( null );
}

function health_unmapped_count(): int {
    foreach ( health_catalogue()['lines'] as $l ) {
        if ( stripos( (string) $l['label'], 'NO tier ref' ) !== false ) { return (int) $l['value']; }
    }
    return -1;
}

function reset_all(): void {
    global $OPTS, $CAPS, $NONCE_OK, $REDIRECT;
    Db::wipe();
    $OPTS = []; $CAPS = true; $NONCE_OK = true; $REDIRECT = '';
    $_GET = []; $_POST = [];
    unset( $GLOBALS['DB_BROKEN'] );
}

/** Run the real admin_post handler, catching its redirect. */
function run_handler( array $post ): string {
    global $REDIRECT;
    $_POST = $post; $REDIRECT = '';
    try {
        ProductsPanel::handleSet();
    } catch ( GateRedirect $e ) {
        return $e->getMessage();
    } catch ( GateDied $e ) {
        return 'DIED:' . $e->getMessage();
    }
    return $REDIRECT;
}

echo "GATE 93 — a Stripe product's tier is set from the dash, and checkout agrees\n";

/* ========================================================================= */
section( 'A. THE REFUSAL ACTUALLY MOVES — and it resolves, it does not guess' );
/* ========================================================================= */

reset_all();
$lite = seed_product( [ 'sid' => 'prod_LITE', 'name' => 'Looth LITE', 'ref' => null, 'active' => 1 ] );
$pro  = seed_product( [ 'sid' => 'prod_PRO',  'name' => 'Looth PRO',  'ref' => null, 'active' => 1 ] );
seed_price( $lite, 'price_lite_month', [ 'cents' => 500 ] );
seed_price( $pro,  'price_pro_month',  [ 'cents' => 1100 ] );

is_( checkout_tier_for( 'price_lite_month' ) === null,
     'A1 BEFORE: an unmapped product\'s price resolves to NO tier, so checkout refuses it' );
is_( checkout_tier_for( 'price_pro_month' ) === null,
     'A2 BEFORE: the second unmapped product is refused too' );

ProductCatalog::apply( 'prod_LITE', 'looth2', '', 7 );
ProductCatalog::apply( 'prod_PRO',  'looth3', '', 7 );

/* ⚠️ THE PAIR IS THE POINT. "A PRO purchase grants looth3" passes on a writer
   that hardcodes looth3 — #148's vacuous green. Only a writer that READS its
   argument can answer both of these. */
is_( checkout_tier_for( 'price_lite_month' ) === 'looth2',
     'A3 AFTER: the LITE price resolves to looth2 — the tier that was chosen, not a constant' );
is_( checkout_tier_for( 'price_pro_month' ) === 'looth3',
     'A4 AFTER: the PRO price resolves to looth3, from the same writer, in the same run' );

$svc = php_code_only( $GATE_ROOT . '/lg-stripe-billing/src/Core/CheckoutService.php' );
$body = php_function_body( $svc, 'createSubscriptionSession' );
is_( $body !== '' && str_contains( $body, 'tierForPrice' ) && str_contains( $body, 'not mapped to a membership tier' ),
     'A5 checkout still refuses on tierForPrice() being null — so A1-A4 are about the real door' );

/* An ARCHIVED product is not sellable however it is mapped: tierForPrice
   filters on the product's active flag. Mapping one must not open a door. */
$old = seed_product( [ 'sid' => 'prod_OLD', 'name' => 'Retired', 'ref' => null, 'active' => 0 ] );
seed_price( $old, 'price_old', [] );
ProductCatalog::apply( 'prod_OLD', 'looth3', '', 7 );
is_( checkout_tier_for( 'price_old' ) === null,
     'A6 mapping an ARCHIVED product does not make it buyable — the active filter still holds' );

/* The un-map direction, which is how a mistake is undone. */
ProductCatalog::apply( 'prod_LITE', '', '', 7 );
is_( checkout_tier_for( 'price_lite_month' ) === null,
     'A7 setting a product back to "no tier" closes the door again' );
is_( row_for( 'prod_LITE' )['ref'] === null,
     'A8 and it stores NULL, which is what Health reads — not the empty string' );

/* ========================================================================= */
section( 'B. A TIER THAT DOES NOT EXIST IS REFUSED — and a refusal writes NOTHING' );
/* ========================================================================= */

reset_all();
/* ⚠️ SEEDED looth3, NOT looth2, AND THAT IS LOAD-BEARING. A validator that
   silently fell back to the DEFAULT tier would leave a looth2 row unchanged —
   a no-op — and B6 would go green on exactly the defect it exists to catch.
   Starting on the other tier makes any fallback a real write. (Red-first M10.) */
seed_product( [ 'sid' => 'prod_X', 'name' => 'Looth LITE', 'ref' => 'looth3', 'region' => 'regional_a', 'active' => 1 ] );
$before = row_for( 'prod_X' );

$refusals = [
    [ 'looth9', 'B1 a tier that is not a role at all is refused' ],
    [ 'looth1', 'B2 looth1 is refused — it is the unpaid resting tier, not something a card may buy' ],
    [ 'looth4', 'B3 looth4 is refused — the permanent comp bypass is not for sale' ],
    [ 'LOOTH2', 'B4 a wrong-case tier is refused rather than quietly corrected' ],
];
foreach ( $refusals as [ $tier, $msg ] ) {
    $threw = false;
    try { ProductCatalog::apply( 'prod_X', $tier, 'regional_a', 7 ); }
    catch ( Throwable $e ) { $threw = true; }
    is_( $threw, $msg );
}

is_( row_for( 'prod_X' ) === $before,
     'B5 after four refusals the row is byte-identical — nothing was half-written' );
is_( audit_rows() === [],
     'B6 and not one audit line was written for a refusal' );

$threw = false; $why = '';
try { ProductCatalog::apply( 'prod_X', 'looth9', '', 7 ); }
catch ( Throwable $e ) { $threw = true; $why = $e->getMessage(); }
is_( $threw && str_contains( $why, 'looth9' ) && str_contains( $why, 'looth2' ) && str_contains( $why, 'looth3' ),
     'B7 the refusal names what was refused AND what is allowed' );

$threw = false;
try { ProductCatalog::apply( 'prod_X', 'looth2', 'regional_z', 7 ); }
catch ( Throwable $e ) { $threw = true; }
is_( $threw, 'B8 an unknown region is refused too' );

$threw = false; $why = '';
try { ProductCatalog::apply( 'prod_NOPE', 'looth2', '', 7 ); }
catch ( Throwable $e ) { $threw = true; $why = $e->getMessage(); }
is_( $threw && str_contains( $why, 'prod_NOPE' ),
     'B9 a product that was never synced is refused by name, not silently created' );

/* ⚠️ THE SECOND LOCK, and it is not the same as the first. A tier can be
   sellable in principle and ungrantable on THIS box. */
$GLOBALS['ROLES'] = [ 'looth1' => true, 'looth3' => true ];   // looth2 role missing
$threw = false; $why = '';
try { ProductCatalog::apply( 'prod_X', 'looth2', '', 7 ); }
catch ( Throwable $e ) { $threw = true; $why = $e->getMessage(); }
is_( $threw && stripos( $why, 'role' ) !== false,
     'B10 a tier this site has no ROLE for is refused, and says so' );
$GLOBALS['ROLES'] = [ 'looth1' => true, 'looth2' => true, 'looth3' => true, 'looth4' => true, 'administrator' => true ];

/* ========================================================================= */
section( 'C. THE TAB AND HEALTH CAN NEVER DISAGREE' );
/* ========================================================================= */

$fixtures = [
    [ 'C1a', 'an empty catalogue', [] ],
    [ 'C1b', 'everything mapped', [
        [ 'sid' => 'p1', 'ref' => 'looth2', 'active' => 1 ],
        [ 'sid' => 'p2', 'ref' => 'looth3', 'active' => 1 ],
    ] ],
    [ 'C1c', 'one active product with no tier', [
        [ 'sid' => 'p1', 'ref' => 'looth2', 'active' => 1 ],
        [ 'sid' => 'p2', 'ref' => null,     'active' => 1 ],
    ] ],
    /* ⚠️ THE ARCHIVED ROW IS THE ONE THAT BITES. Both surfaces must EXCLUDE it,
       and a count that includes it looks perfectly reasonable until somebody
       tries to fix a product nobody can reach. */
    [ 'C1d', 'three unmapped, one of them archived', [
        [ 'sid' => 'p1', 'ref' => null, 'active' => 1 ],
        [ 'sid' => 'p2', 'ref' => null, 'active' => 1 ],
        [ 'sid' => 'p3', 'ref' => null, 'active' => 1 ],
        [ 'sid' => 'p4', 'ref' => null, 'active' => 0 ],
    ] ],
    [ 'C1e', 'an unmapped REGIONAL product', [
        [ 'sid' => 'p1', 'ref' => null, 'region' => 'regional_a', 'active' => 1 ],
    ] ],
];

foreach ( $fixtures as [ $id, $label, $rows ] ) {
    reset_all();
    foreach ( $rows as $r ) { seed_product( $r + [ 'name' => 'P' ] ); }
    $mine   = ProductCatalog::unmappedActiveCount();
    $theirs = health_unmapped_count();
    is_( $mine === $theirs, sprintf( '%s %s — the tab says %d and Health says %d', $id, $label, $mine, $theirs ) );
}

/* AND THEY MOVE TOGETHER. A pair of numbers that agree at rest but not after a
   change is the failure this section exists for. */
reset_all();
seed_product( [ 'sid' => 'p1', 'ref' => null, 'active' => 1, 'name' => 'A' ] );
seed_product( [ 'sid' => 'p2', 'ref' => null, 'active' => 1, 'name' => 'B' ] );
$m0 = ProductCatalog::unmappedActiveCount(); $h0 = health_unmapped_count();
ProductCatalog::apply( 'p1', 'looth2', '', 7 );
$m1 = ProductCatalog::unmappedActiveCount(); $h1 = health_unmapped_count();
is_( $m0 === 2 && $h0 === 2 && $m1 === 1 && $h1 === 1,
     'C2 mapping ONE product drops BOTH numbers by exactly one (2,2 -> 1,1)' );

is_( health_catalogue()['status'] === 'fail',
     'C3 Health still calls a remaining unmapped product a FAIL, not a shrug' );

/* ⚠️ A DASH WRITE MUST NOT LOOK LIKE A WEBHOOK. Health's webhook question reads
   the same table; if this feature's audit rows leaked into it, the panel would
   report that Stripe had been in touch when it had not. */
$wh = Db::pdo()->query( "SELECT COUNT(*) FROM audit_log WHERE subject_type = 'webhook'" )->fetchColumn();
is_( (int) $wh === 0 && count( audit_rows() ) === 1,
     'C4 the mapping wrote one audit row and NONE of it reads as a webhook receipt' );

/* ========================================================================= */
section( 'D. STRIPE NEVER SETS THIS AND NEVER OVERWRITES IT' );
/* ========================================================================= */

/* Half one, BEHAVIOURAL: the real handler, through the real interface. */
final class GateRecordingProductRepo implements \LGSB\Domain\Repositories\ProductRepository {
    public array $calls = [];
    public function tierForPrice( string $p ): ?string { return null; }
    public function resolvePriceForCountry( string $p, ?string $c ): string { return $p; }
    public function grantsDurationDays( string $p ): ?int { return null; }
    public function pricePerYearCentsForTier( string $t ): ?int { return null; }
    public function findPriceData( string $p ): ?array { return null; }
    public function regionTagForPrice( string $p ): ?string { return null; }
    public function listMembership( ?string $c = null ): array { return []; }
    public function countryInRegion( string $c, string $r ): bool { return false; }
    public function standardPriceForTierAndInterval( string $p ): ?string { return null; }
    public function upsertProduct( string $id, string $name, string $kind, ?string $ref, bool $active ): void {
        $this->calls[] = compact( 'id', 'name', 'kind', 'ref', 'active' );
    }
    public function upsertPrice( string $pid, string $prod, string $type, ?string $int, int $cents, string $cur,
                                 ?string $region, int $prio, bool $active, ?int $days, float $scale = 1.0, int $trial = 0 ): void {}
}

$repo    = new GateRecordingProductRepo();
$handler = new \LGSB\Core\ProductSyncHandler( $repo );
$handler->handleProductEvent( (object) [ 'id' => 'prod_LITE', 'name' => 'Renamed by Stripe', 'active' => true ] );

is_( count( $repo->calls ) === 1 && $repo->calls[0]['ref'] === null,
     'D1 a product.updated event carries NO tier — the real handler passes ref as null' );
is_( $repo->calls[0]['name'] === 'Renamed by Stripe' && $repo->calls[0]['active'] === true,
     'D2 it does carry the name and the active flag, so the sync is alive and not a stub' );

/* Half two, BY SOURCE — because the upsert is MySQL-only and cannot be run
   here. Said out loud in the header rather than faked. */
$adapter = php_code_only( $GATE_ROOT . '/lg-stripe-billing/src/Adapters/PdoProductRepository.php' );
$upsert  = php_function_body( $adapter, 'upsertProduct' );
$dupPos  = stripos( $upsert, 'ON DUPLICATE KEY UPDATE' );
$updateClause = $dupPos === false ? '' : substr( $upsert, $dupPos );
is_( $updateClause !== ''
     && ! preg_match( '/\bref\s*=/', $updateClause )
     && ! preg_match( '/\bkind\s*=/', $updateClause ),
     'D3 upsertProduct\'s update clause names neither ref nor kind, so a webhook cannot overwrite a mapping' );
is_( preg_match( '/\bname\s*=/', $updateClause ) && preg_match( '/\bactive\s*=/', $updateClause ),
     'D4 it DOES update name and active — the clause is real, not empty' );

/* And the screen says so, because the issue asks for it in as many words. */
reset_all();
seed_product( [ 'sid' => 'prod_LITE', 'name' => 'Looth LITE', 'ref' => 'looth2', 'active' => 1 ] );
$html = render_panel();
is_( stripos( $html, 'authority' ) !== false && stripos( $html, 'never overwrite' ) !== false,
     'D5 the tab states ON SCREEN that it is the authority and Stripe never overwrites it' );

/* ========================================================================= */
section( 'E. A MONEY-ADJACENT WRITE: capability, nonce, and one line per change' );
/* ========================================================================= */

reset_all();
/* ⚠️ SEEDED WITH THE WRONG `kind` ON PURPOSE. Seeded as 'membership' — which is
   what the webhook inserts — E4's kind assertion passes whether or not the
   UPDATE writes that column at all, because it was already right. That is the
   same shape as #148's vacuous green, and red-first M26 found it here. Starting
   from another kind means only an UPDATE that really writes it can pass. */
seed_product( [ 'sid' => 'prod_LITE', 'name' => 'Looth LITE', 'ref' => null, 'kind' => 'digital', 'active' => 1 ] );

$GLOBALS['CAPS'] = false;
$r = run_handler( [ 'product' => 'prod_LITE', 'tier' => 'looth2', 'region' => '' ] );
is_( str_starts_with( $r, 'DIED:' ) && row_for( 'prod_LITE' )['ref'] === null,
     'E1 without manage_options the handler refuses and writes nothing' );
$GLOBALS['CAPS'] = true;

$GLOBALS['NONCE_OK'] = false;
$r = run_handler( [ 'product' => 'prod_LITE', 'tier' => 'looth2', 'region' => '' ] );
is_( str_starts_with( $r, 'DIED:' ) && row_for( 'prod_LITE' )['ref'] === null,
     'E2 a bad nonce refuses and writes nothing' );
$GLOBALS['NONCE_OK'] = true;

/* The guards are in the handler's OWN body, brace-matched — not its
   neighbour's (gate 90 §E2's defect). */
$panelCode   = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/ProductsPanel.php' );
$handlerBody = php_function_body( $panelCode, 'handleSet' );
is_( $handlerBody !== ''
     && str_contains( $handlerBody, 'current_user_can' )
     && str_contains( $handlerBody, 'check_admin_referer' ),
     'E3 both guards live inside handleSet\'s own body' );

$r = run_handler( [ 'product' => 'prod_LITE', 'tier' => 'looth2', 'region' => 'regional_a' ] );
$row = row_for( 'prod_LITE' );
is_( $row['ref'] === 'looth2' && $row['region_tag'] === 'regional_a' && $row['kind'] === 'membership',
     'E4 a good request writes ref, region_tag AND kind — the import script\'s whole column set' );
is_( str_contains( $r, 'lgms_prod_ok' ), 'E5 and it redirects back to the tab saying so' );

$au = audit_rows();
is_( count( $au ) === 1, 'E6 exactly ONE audit line for one change' );
$d = json_decode( (string) $au[0]['details'], true );
is_( $au[0]['actor_type'] === 'admin' && (string) $au[0]['actor_ref'] === '7'
     && $au[0]['subject_type'] === 'product' && (int) $au[0]['subject_id'] > 0
     && $au[0]['action'] === ProductCatalog::AUDIT_ACTION,
     'E7 it records WHO, on WHAT, and that it was an admin — not a webhook' );
is_( is_array( $d ) && ( $d['from']['ref'] ?? 'x' ) === '' && ( $d['to']['ref'] ?? '' ) === 'looth2'
     && ( $d['to']['region_tag'] ?? '' ) === 'regional_a',
     'E8 and it records FROM what TO what, which is the whole point of an audit line' );

$r = run_handler( [ 'product' => 'prod_LITE', 'tier' => 'looth2', 'region' => 'regional_a' ] );
is_( count( audit_rows() ) === 1,
     'E9 pressing Save again with nothing changed writes NO second line — one line per CHANGE' );
is_( str_contains( $r, 'lgms_prod_ok' ) && str_contains( rawurldecode( $r ), 'nothing changed' ),
     'E10 and it says so plainly rather than pretending to have done something' );

$r = run_handler( [ 'product' => 'prod_LITE', 'tier' => 'looth9', 'region' => '' ] );
is_( str_contains( $r, 'lgms_prod_err' ) && count( audit_rows() ) === 1
     && row_for( 'prod_LITE' )['ref'] === 'looth2',
     'E11 a refused change reports the error, writes no audit line, and leaves the row alone' );

/* ⚠️ THE AUDIT IS PART OF THE WRITE, NOT A BEST EFFORT — deliberately the
   opposite of WebhookReceipts. If the receipt cannot be written, the mapping
   does not happen. */
reset_all();
seed_product( [ 'sid' => 'prod_LITE', 'name' => 'Looth LITE', 'ref' => null, 'active' => 1 ] );
Db::pdo()->exec( 'DROP TABLE audit_log' );
$threw = false;
try { ProductCatalog::apply( 'prod_LITE', 'looth3', '', 7 ); }
catch ( Throwable $e ) { $threw = true; }
is_( $threw && row_for( 'prod_LITE' )['ref'] === null,
     'E12 if the audit line cannot be written the whole change is rolled back' );
\LGMS\Db::schema( Db::pdo() );

/* ========================================================================= */
section( 'F. ONE WRITER, AND NO SECOND NORMALISATION' );
/* ========================================================================= */

$CATALOG = $GATE_ROOT . '/lg-patreon-stripe-poller/src/Membership/ProductCatalog.php';
$IMPORT  = $GATE_ROOT . '/lg-stripe-billing/bin/stripe-import-catalog.php';

$mine   = written_product_columns( $CATALOG );
$theirs = written_product_columns( $IMPORT );
is_( $mine === [ 'kind', 'ref', 'region_tag' ],
     'F1 the dash writes exactly ref, kind and region_tag — nothing more, nothing less' );
is_( $mine === $theirs,
     'F2 and that is the SAME column set the import script stamps, so the two cannot drift' );

/* Nobody else writes these columns. A second writer is how the two surfaces
   start disagreeing without anything looking wrong. */
$others = [];
foreach ( explode( "\n", (string) shell_exec(
    'cd ' . escapeshellarg( $GATE_ROOT ) . ' && git ls-files "*.php" 2>/dev/null'
) ) as $rel ) {
    $rel = trim( $rel );
    if ( $rel === '' || str_starts_with( $rel, 'tools/gates/' ) || str_contains( $rel, '/vendor/' ) ) { continue; }
    if ( $rel === 'lg-patreon-stripe-poller/src/Membership/ProductCatalog.php' ) { continue; }
    if ( $rel === 'lg-stripe-billing/bin/stripe-import-catalog.php' ) { continue; }
    $full = $GATE_ROOT . '/' . $rel;
    if ( ! is_readable( $full ) ) { continue; }
    foreach ( php_string_literals( $full ) as $lit ) {
        if ( preg_match( '/UPDATE\s+products\b/i', $lit ) && preg_match( '/\b(ref|kind|region_tag)\s*=/', $lit ) ) {
            $others[] = $rel;
            break;
        }
    }
}
is_( $others === [],
     'F3 nothing else in the monorepo writes products.ref / kind / region_tag'
     . ( $others === [] ? '' : ' — found: ' . implode( ', ', array_unique( $others ) ) ) );

/* The tier list is ONE list, and the screen offers exactly it. */
reset_all();
seed_product( [ 'sid' => 'p1', 'ref' => null, 'active' => 1, 'name' => 'A' ] );
$html = render_panel();
is_( substr_count( $html, 'value="looth2"' ) === 1 && substr_count( $html, 'value="looth3"' ) === 1,
     'F4 the tier control offers Lite and Pro' );
is_( ! str_contains( $html, 'value="looth1"' ) && ! str_contains( $html, 'value="looth4"' ),
     'F5 and never offers looth1 or looth4, which the writer would refuse anyway' );

/* ========================================================================= */
section( 'G. THE SCREEN TELLS THE TRUTH — including when it cannot answer' );
/* ========================================================================= */

reset_all();
$a = seed_product( [ 'sid' => 'prod_A', 'name' => 'Looth LITE', 'ref' => null, 'active' => 1 ] );
$b = seed_product( [ 'sid' => 'prod_B', 'name' => 'Looth PRO', 'ref' => 'looth3', 'region' => 'regional_a', 'active' => 1 ] );
$c = seed_product( [ 'sid' => 'prod_C', 'name' => 'Old thing', 'ref' => null, 'active' => 0 ] );
seed_price( $b, 'price_b_m', [ 'cents' => 1100, 'interval' => 'month' ] );
seed_price( $b, 'price_b_y', [ 'cents' => 12000, 'interval' => 'year' ] );
seed_price( $b, 'price_b_old', [ 'cents' => 900, 'interval' => 'month', 'active' => 0 ] );
$html = render_panel();

is_( str_contains( $html, 'prod_A' ) && str_contains( $html, 'prod_B' ) && str_contains( $html, 'prod_C' ),
     'G1 every product is listed, archived ones included, with its Stripe id' );
is_( str_contains( $html, 'regional_a' ), 'G2 the region tag is shown' );
is_( str_contains( $html, '$11.00' ) && str_contains( $html, '/ month' )
     && str_contains( $html, '$120.00' ) && str_contains( $html, '/ year' ),
     'G3 prices are shown with their intervals, in money a person can read' );
is_( str_contains( $html, '1 archived price' ),
     'G4 archived prices are counted, not drawn — dev2\'s LITE carries TEN and only three can be reached' );

/* ⚠️ RED IS THE ACTIVE ONES ONLY, matching Health exactly. An archived product
   with no tier is not a problem, and painting it red would train people to
   ignore the colour. */
$rowA = substr( $html, (int) strpos( $html, 'prod_A' ) - 400, 600 );
$rowC = substr( $html, (int) strpos( $html, 'prod_C' ) - 400, 600 );
is_( str_contains( $rowA, 'is-unmapped' ), 'G5 an ACTIVE product with no tier is drawn red' );
is_( ! str_contains( $rowC, 'is-unmapped' ), 'G6 an ARCHIVED product with no tier is NOT drawn red' );
is_( str_contains( $html, 'NO TIER' ), 'G7 and the red row says in words that nobody can buy it' );

reset_all();
$html = render_panel();
is_( stripos( $html, 'catalogue is empty' ) !== false,
     'G8 an empty catalogue says so — which is what live looks like today' );
is_( ! str_contains( $html, '<table' ),
     'G9 and draws no empty table pretending to be a list' );

/* ⚠️ `unknown` MAY NOT COLLAPSE INTO "nothing is registered". Those two states
   need opposite responses, which is #192's standing lesson. */
reset_all();
$GLOBALS['DB_BROKEN'] = true;
$html = render_panel();
unset( $GLOBALS['DB_BROKEN'] );
is_( stripos( $html, 'Cannot see the billing database' ) !== false,
     'G10 a database it cannot reach says exactly that' );
is_( stripos( $html, 'not the same as an empty catalogue' ) !== false,
     'G11 and distinguishes itself from an empty catalogue in as many words' );
is_( ! preg_match( '/Fatal error|Warning:|Notice:/', $html ),
     'G12 no state produces a PHP notice, warning or fatal in the markup' );

/* The tab is wired into the dash — by SOURCE, because loading Admin.php pulls
   in half the plugin. Tokenized, never a regex: gate 90's equivalents twice
   matched their own explanatory prose. */
$adminCode = php_code_only( $GATE_ROOT . '/lg-patreon-stripe-poller/src/Admin.php' );
is_( str_contains( $adminCode, "'products'" ) && str_contains( $adminCode, 'ProductsPanel::render()' ),
     'G13 Admin.php registers the tab and points it at the panel' );
is_( str_contains( $adminCode, 'ProductsPanel::boot()' ),
     'G14 and boots the panel so its save handler is registered' );
/* Ian's standing rule, 2026-08-22: every membership admin surface is a TAB in
   this dash. One top-level menu, which gate 90 §G owns — this asserts only that
   #194 did not add a second one. */
is_( substr_count( $adminCode, 'add_menu_page(' ) === 1,
     'G15 this feature added NO new top-level menu — it is a tab, per Ian\'s 8/22 rule' );

/* ========================================================================= */
echo "\n";
foreach ( $reports as $r ) { echo "  note $r\n"; }
if ( $fail > 0 ) {
    echo "\nGATE 93 RED — $fail failure(s), $pass ok\n";
    exit( 1 );
}
echo "\nGATE 93 GREEN — $pass assertions\n";
exit( 0 );

} // namespace
