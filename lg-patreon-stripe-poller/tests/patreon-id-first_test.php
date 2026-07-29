<?php
/**
 * Proof for the Patreon-ID-first fix in UserLifecycle::provision().
 *
 * Run on DEV2 only:
 *   sudo wp --allow-root --path=/var/www/dev eval-file <this>
 *
 * Constructs the exact case Ian described — an account with Patreon id X and email A,
 * then a lifecycle call with Patreon id X and email B — and asserts it UPDATES rather
 * than MINTS. Also proves the negative (a genuinely new patron still gets an account)
 * and the two refusals. Cleans up every fixture it makes.
 */

// Default to the copy that ships beside this test, so it exercises the tree it was
// checked out with. Override with LGPO_LIFECYCLE_FILE to test a specific build.
$FIX = getenv( 'LGPO_LIFECYCLE_FILE' ) ?: dirname( __DIR__ ) . '/src/UserLifecycle.php';
$FIX = realpath( $FIX ) ?: $FIX;
if ( ! class_exists( 'LGMS\\UserLifecycle', false ) ) {
    require_once $FIX;
}
$rc = new ReflectionClass( 'LGMS\\UserLifecycle' );
echo "class loaded from : " . $rc->getFileName() . "\n";
// Reflection, not trust: if WordPress already loaded a DIFFERENT UserLifecycle,
// this test would silently grade the wrong bytes. Abort instead.
echo "IS THE FIX UNDER TEST: " . ( realpath( $rc->getFileName() ) === $FIX ? "YES\n" : "NO — ABORT\n" );
if ( realpath( $rc->getFileName() ) !== $FIX ) { return; }
echo str_repeat( '=', 72 ) . "\n";

$TAG   = 'lgproof' . time();
$PID_A = '99000001' . substr( (string) time(), -4 );
$PID_B = '99000002' . substr( (string) time(), -4 );
$mailA = $TAG . '.olda@example.invalid';
$mailB = $TAG . '.newb@example.invalid';
$mailC = $TAG . '.fresh@example.invalid';
$mailD = $TAG . '.otherowner@example.invalid';
$made  = [];

function line( $label, $got, $want ) {
    $ok = ( (string) $got === (string) $want );
    printf( "  %-46s %-26s %s\n", $label, (string) $got, $ok ? 'PASS' : 'FAIL (want ' . $want . ')' );
    return $ok;
}

// ---------- fixture: an existing member, Patreon id A, email A ----------
$fid = wp_insert_user( [
    'user_login' => $TAG . '_existing',
    'user_email' => $mailA,
    'user_pass'  => wp_generate_password( 20 ),
    'display_name' => 'Proof Existing',
] );
if ( is_wp_error( $fid ) ) { echo "fixture failed: " . $fid->get_error_message() . "\n"; return; }
$made[] = (int) $fid;
update_user_meta( (int) $fid, 'lgpo_patreon_user_id', $PID_A );
echo "fixture: wp #{$fid}  patreon_id={$PID_A}  email={$mailA}\n\n";

// The counterfactual, stated as a measurement rather than a claim: the OLD code was
// get_user_by('email',$email) and nothing else, so with email B unknown it MUST mint.
$preexisting = get_user_by( 'email', $mailB );
echo "COUNTERFACTUAL — does email B match any account? "
   . ( $preexisting ? 'yes' : 'NO -> the old email-only lookup would have MINTED a second account' ) . "\n\n";

// ---------- 1. THE CASE: same Patreon id, new email ----------
echo "1. same Patreon id, NEW email — must UPDATE, not mint\n";
$r = LGMS\UserLifecycle::provision( $mailB, [ 'patreon_user_id' => $PID_A, 'display_name' => 'Proof Existing' ] );
if ( ! empty( $r['wp_user_id'] ) && ! in_array( (int) $r['wp_user_id'], $made, true ) ) { $made[] = (int) $r['wp_user_id']; }
$pass  = line( 'created a new account?', $r['created'] ? 'yes' : 'no', 'no' );
$pass &= line( 'resolved to the SAME wp_user_id', $r['wp_user_id'], $fid );
$pass &= line( 'matched_by', $r['matched_by'] ?? '(unset)', 'patreon_id' );
$u = get_user_by( 'id', (int) $fid );
$pass &= line( 'account email now', $u ? $u->user_email : '(gone)', $mailB );
$pass &= line( 'previous email recorded (reversible)', get_user_meta( (int) $fid, 'lgpo_previous_user_email', true ), $mailA );
$total = count( get_users( [ 'search' => $TAG . '*', 'search_columns' => [ 'user_login' ], 'number' => 99 ] ) );
$pass &= line( 'accounts bearing this proof tag', $total, 1 );
echo "\n";

// ---------- 2. NEGATIVE: a genuinely new patron still gets an account ----------
echo "2. unknown Patreon id + unknown email — must MINT\n";
$r2 = LGMS\UserLifecycle::provision( $mailC, [ 'patreon_user_id' => $PID_B, 'display_name' => 'Proof Fresh' ] );
if ( ! empty( $r2['wp_user_id'] ) ) { $made[] = (int) $r2['wp_user_id']; }
$pass2  = line( 'created a new account?', $r2['created'] ? 'yes' : 'no', 'yes' );
$pass2 &= line( 'patreon id stamped on the new account',
    get_user_meta( (int) $r2['wp_user_id'], 'lgpo_patreon_user_id', true ), $PID_B );
echo "\n";

// ---------- 3. REFUSAL: email already owned by a different account ----------
echo "3. same Patreon id, but the new email belongs to SOMEBODY ELSE — must refuse the move\n";
$oid = wp_insert_user( [
    'user_login' => $TAG . '_owner', 'user_email' => $mailD,
    'user_pass' => wp_generate_password( 20 ), 'display_name' => 'Proof Owner',
] );
if ( ! is_wp_error( $oid ) ) {
    $made[] = (int) $oid;
    $before = get_user_by( 'id', (int) $fid )->user_email;
    $r3 = LGMS\UserLifecycle::provision( $mailD, [ 'patreon_user_id' => $PID_A ] );
    $after = get_user_by( 'id', (int) $fid )->user_email;
    $pass3  = line( 'email left unchanged', $after, $before );
    $pass3 &= line( 'owner account untouched', get_user_by( 'id', (int) $oid )->user_email, $mailD );
    $pass3 &= line( 'reported an error', empty( $r3['errors'] ) ? 'no' : 'yes', 'yes' );
    if ( ! empty( $r3['errors'] ) ) { echo "      -> " . $r3['errors'][0] . "\n"; }
} else { $pass3 = false; echo "  owner fixture failed\n"; }
echo "\n";

// ---------- 4. REFUSAL: never adopt an admin ----------
echo "4. Patreon id resolves to an ADMIN — must refuse\n";
$aid = wp_insert_user( [
    'user_login' => $TAG . '_admin', 'user_email' => $TAG . '.admin@example.invalid',
    'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator',
] );
if ( ! is_wp_error( $aid ) ) {
    $made[] = (int) $aid;
    update_user_meta( (int) $aid, 'lgpo_patreon_user_id', $PID_A . '9' );
    $r4 = LGMS\UserLifecycle::provision( $TAG . '.adminnew@example.invalid', [ 'patreon_user_id' => $PID_A . '9' ] );
    $pass4  = line( 'refused (ok=false)', $r4['ok'] ? 'true' : 'false', 'false' );
    $pass4 &= line( 'admin email unchanged', get_user_by( 'id', (int) $aid )->user_email, $TAG . '.admin@example.invalid' );
    if ( ! empty( $r4['errors'] ) ) { echo "      -> " . $r4['errors'][0] . "\n"; }
} else { $pass4 = false; echo "  admin fixture failed\n"; }

echo "\n" . str_repeat( '=', 72 ) . "\n";
printf( "RESULT  1.update=%s  2.mint=%s  3.email-guard=%s  4.admin-guard=%s\n",
    $pass ? 'PASS' : 'FAIL', $pass2 ? 'PASS' : 'FAIL', $pass3 ? 'PASS' : 'FAIL', $pass4 ? 'PASS' : 'FAIL' );

// ---------- cleanup ----------
require_once ABSPATH . 'wp-admin/includes/user.php';
$made = array_values( array_unique( array_filter( $made ) ) );
foreach ( $made as $id ) { wp_delete_user( $id ); }
echo "cleaned up fixture accounts: " . implode( ', ', $made ) . "\n";
$left = get_users( [ 'search' => $TAG . '*', 'search_columns' => [ 'user_login' ], 'number' => 99 ] );
echo "fixtures remaining (must be 0): " . count( $left ) . "\n";
