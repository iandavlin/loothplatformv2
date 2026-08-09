<?php
/**
 * RED-FIRST GATE for creator-token expiry bookkeeping.
 *
 *   php test-token-expiry-bookkeeping.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * The defect, as found on live 2026-08-09: lgpo_creator_token_expires_at
 * said 2026-07-31 while the access token verified VALID against Patreon's
 * API. The bookkeeping options last moved together on 2026-06-30
 * (obtained_at 1782870758, expires_at 1785462758 — exactly +30d), and the
 * persist path only touches expires_at when Patreon's response carries
 * expires_in:
 *
 *     if ( ! empty( $token_body['expires_in'] ) ) { update_option( ... ); }
 *
 * So a rotation whose response omits expires_in rotates BOTH tokens and
 * leaves expires_at asserting the OLD token's expiry — stale in the
 * dangerous direction: it reads "expired" for a token that works, which
 * trains operators to ignore the one field that exists to warn them.
 * (The other rotation path with the same hole is platform/bin/
 * lg-secrets-helper `set lgpo-access-token` — the manifest deliberately
 * marks expires-at read-only, so a dash rotation can never fix it up.
 * That is an operator-tooling gap, noted, not fixed here.)
 *
 * The fix under test: lgpo_persist_creator_tokens reconciles expires_at on
 * EVERY rotation — from Patreon's response when expires_in is present, and
 * DELETED when it is not, so the settings badge honestly says "no
 * expires_at recorded" (the code path for that already exists) instead of
 * asserting a timestamp that belongs to a dead token.
 *
 * Runs the REAL lg-patreon-onboard.php: LGPO_PLUGIN_DIR is pre-defined to
 * a stub dir so its requires load empty files, and the WP surface is
 * stubbed. Section [1] is the red assertion — it FAILS against the pre-fix
 * code.
 */

declare(strict_types=1);

// Point the plugin's own requires at empty stubs BEFORE including it.
$STUB = sys_get_temp_dir() . '/lgpo-token-gate-' . getmypid() . '/';
@mkdir( $STUB . 'includes', 0700, true );
foreach ( [ 'includes/campaign-filter.php', 'includes/class-lgpo-sync-engine.php', 'includes/class-lgpo-sync-cron.php' ] as $f ) {
    file_put_contents( $STUB . $f, "<?php\n" );
}
define( 'ABSPATH', '/stub/' );
define( 'LGPO_VERSION', 'gate' );
define( 'LGPO_PLUGIN_FILE', __FILE__ );
define( 'LGPO_PLUGIN_DIR', $STUB );
define( 'LGPO_PLUGIN_URL', 'https://stub.invalid/' );
define( 'LGMS_PLUGIN_DIR', $STUB );

$GLOBALS['OPTS'] = [];
$GLOBALS['HTTP'] = null;   // next wp_remote_post response

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function update_option( $n, $v, $autoload = null ) { $GLOBALS['OPTS'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['OPTS'][ $n ] ); return true; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function is_wp_error( $x ) { return false; }
function wp_remote_post( $url, $args = [] ) { $GLOBALS['HTTP_SENT'] = [ 'url' => $url, 'args' => $args ]; return $GLOBALS['HTTP']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

$PLUGIN = __DIR__ . '/../../lg-patreon-onboard.php';
if ( ! is_readable( $PLUGIN ) ) { fwrite( STDERR, "CANNOT RUN: $PLUGIN missing\n" ); exit( 3 ); }
require $PLUGIN;
if ( ! function_exists( 'lgpo_persist_creator_tokens' ) || ! function_exists( 'lgpo_refresh_creator_token' ) ) {
    fwrite( STDERR, "CANNOT RUN: persist/refresh functions did not load\n" );
    exit( 3 );
}

/** Live's exact 2026-08-09 shape: tokens from 6/30, expiry claiming 7/31. */
function seed_live_shape(): void {
    $GLOBALS['OPTS'] = [
        'lgpo_creator_access_token'     => 'old-access-2026-06-30',
        'lgpo_creator_refresh_token'    => 'old-refresh-2026-06-30',
        'lgpo_creator_token_expires_at' => 1785462758,   // 2026-07-31 12:32:38 UTC
        'lgpo_creator_token_obtained_at'=> 1782870758,   // 2026-06-30 12:32:38 UTC
        'lgpo_client_id'                => 'cid',
        'lgpo_client_secret'            => 'csec',
    ];
}

$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $d ? "  ({$d})" : '' ); }
};

echo "=== creator-token expiry bookkeeping — red-first gate ===\n";

// ------------------------------------------------ 1. THE DEFECT (red)
echo "\n[1] a rotation WITHOUT expires_in must not leave the old expiry standing\n";
seed_live_shape();
$ok = lgpo_persist_creator_tokens( [ 'access_token' => 'new-A', 'refresh_token' => 'new-R' ] );
$note( $ok === true, 'persist reports success' );
$note( get_option( 'lgpo_creator_access_token' ) === 'new-A'
       && get_option( 'lgpo_creator_refresh_token' ) === 'new-R', 'both tokens rotated' );
$note( get_option( 'lgpo_creator_token_expires_at', 0 ) !== 1785462758,
       'the OLD token\'s expiry is GONE — this is the live 7/31-vs-valid-8/09 defect',
       'still ' . var_export( get_option( 'lgpo_creator_token_expires_at', 0 ), true ) );
$note( get_option( 'lgpo_creator_token_expires_at', 0 ) === 0,
       'and nothing was invented in its place: absent, so the settings badge says "no expires_at recorded"' );
$note( abs( (int) get_option( 'lgpo_creator_token_obtained_at', 0 ) - time() ) <= 5,
       'obtained_at moved to now' );

// ------------------------------------------------ 2. the normal rotation
echo "\n[2] a rotation WITH expires_in books the expiry from Patreon's response\n";
seed_live_shape();
lgpo_persist_creator_tokens( [ 'access_token' => 'new-A', 'refresh_token' => 'new-R', 'expires_in' => 2678400 ] );
$note( abs( (int) get_option( 'lgpo_creator_token_expires_at', 0 ) - ( time() + 2678400 ) ) <= 5,
       'expires_at = now + expires_in (31 days)' );

seed_live_shape();
lgpo_persist_creator_tokens( [ 'access_token' => 'new-A', 'expires_in' => '2678400' ] );
$note( abs( (int) get_option( 'lgpo_creator_token_expires_at', 0 ) - ( time() + 2678400 ) ) <= 5,
       'a string expires_in (JSON from some stacks) books identically' );

// ------------------------------------------------ 3. documented Patreon quirks
echo "\n[3] the quirks the function already handled must still hold\n";
seed_live_shape();
lgpo_persist_creator_tokens( [ 'access_token' => 'new-A', 'expires_in' => 2678400 ] );
$note( get_option( 'lgpo_creator_refresh_token' ) === 'old-refresh-2026-06-30',
       'a response omitting refresh_token keeps the old one (Patreon does not always re-issue)' );

seed_live_shape();
$before = $GLOBALS['OPTS'];
$ok = lgpo_persist_creator_tokens( [ 'refresh_token' => 'new-R', 'expires_in' => 2678400 ] );
$note( $ok === false && $GLOBALS['OPTS'] === $before,
       'no access_token -> refused, and NOTHING is written (expires_at untouched)' );

// ------------------------------------------------ 4. the full refresh path
echo "\n[4] lgpo_refresh_creator_token end to end, stubbed Patreon endpoint\n";
seed_live_shape();
$GLOBALS['HTTP'] = [ 'code' => 200, 'body' => json_encode( [
    'access_token' => 'fresh-A', 'refresh_token' => 'fresh-R', 'expires_in' => 2678400,
    'scope' => 'campaigns.members', 'token_type' => 'Bearer',
] ) ];
$r = lgpo_refresh_creator_token();
$note( ( $r['ok'] ?? false ) === true && $r['access_token'] === 'fresh-A', 'refresh succeeds with the new token' );
$note( abs( (int) get_option( 'lgpo_creator_token_expires_at', 0 ) - ( time() + 2678400 ) ) <= 5
       && abs( (int) get_option( 'lgpo_creator_token_obtained_at', 0 ) - time() ) <= 5,
       'expires_at + obtained_at both book from the response — the 6/30-frozen pair cannot recur' );

seed_live_shape();
$GLOBALS['HTTP'] = [ 'code' => 200, 'body' => json_encode( [
    'access_token' => 'fresh-A', 'refresh_token' => 'fresh-R',
] ) ];
$r = lgpo_refresh_creator_token();
$note( ( $r['ok'] ?? false ) === true && get_option( 'lgpo_creator_token_expires_at', 0 ) === 0,
       'a refresh response WITHOUT expires_in rotates tokens and CLEARS the stale expiry' );

seed_live_shape();
$before = $GLOBALS['OPTS'];
$GLOBALS['HTTP'] = [ 'code' => 401, 'body' => '{"error":"invalid_grant"}' ];
$r = lgpo_refresh_creator_token();
$note( ( $r['ok'] ?? true ) === false && $GLOBALS['OPTS'] === $before,
       'a failed refresh writes nothing — the old bookkeeping stays intact' );

printf( "\n%d passed, %d failed\n", $pass, $fail );

// tidy the stub dir
foreach ( [ 'includes/campaign-filter.php', 'includes/class-lgpo-sync-engine.php', 'includes/class-lgpo-sync-cron.php' ] as $f ) { @unlink( $STUB . $f ); }
@rmdir( $STUB . 'includes' ); @rmdir( $STUB );

if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — every rotation leaves expires_at telling the truth: booked from Patreon, or honestly absent.\n";
exit( 0 );
