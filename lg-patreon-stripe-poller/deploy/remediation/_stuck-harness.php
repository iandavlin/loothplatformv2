<?php
/**
 * Subprocess harness for test-stuck-sources.php. Not a test itself.
 *
 * Stubs the WP layer, builds an in-memory lg_membership from a JSON scenario,
 * then includes the real check-stuck-sources.php so its genuine exit code
 * reaches the caller. Run as a subprocess precisely BECAUSE the checker calls
 * exit() — the exit code is part of the contract (0 clean / 1 defect / 3 cannot
 * run), and a harness that swallowed it would be testing the wrong thing.
 *
 *   php _stuck-harness.php <scenario.json> [quiet]
 */

declare(strict_types=1);

namespace LGMS { class Db { public static function pdo() { return $GLOBALS['PDO']; } } }

namespace {

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/stub/' ); }

$scenario = json_decode( (string) file_get_contents( $argv[1] ), true );
$GLOBALS['FIXR'] = [];
foreach ( $scenario['rows'] as $r ) { $GLOBALS['FIXR'][ (int) $r['uid'] ] = $r; }
$GLOBALS['TMAP'] = $scenario['tier_map'];

function get_user_by( $f, $id ) {
    $r = $GLOBALS['FIXR'][ (int) $id ] ?? null;
    return $r ? (object) [ 'ID' => $r['uid'], 'roles' => $r['roles'], 'user_email' => $r['email'] ] : false;
}
function get_user_meta( $id, $k, $s = false ) {
    $r = $GLOBALS['FIXR'][ (int) $id ] ?? null;
    if ( ! $r ) { return ''; }
    if ( $k === 'payment_source' )       { return (string) ( $r['pay_src'] ?? '' ); }
    if ( $k === 'lgpo_patreon_tier_id' ) { return (string) ( $r['pat_tid'] ?? '' ); }
    if ( $k === 'lgpo_patreon_user_id' ) { return (string) ( $r['pat_uid'] ?? '' ); }
    return '';
}
function get_option( $n, $d = false ) { return $n === 'lgpo_tier_map' ? $GLOBALS['TMAP'] : $d; }

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE lg_role_sources (wp_user_id INTEGER, source TEXT, tier TEXT, PRIMARY KEY(wp_user_id,source))' );
$pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE)' );
$pdo->exec( 'CREATE TABLE lg_patreon_members (wp_user_id INTEGER PRIMARY KEY)' );

$ins = $pdo->prepare( 'INSERT INTO lg_role_sources (wp_user_id,source,tier) VALUES (?,?,?)' );
$pm  = $pdo->prepare( 'INSERT INTO lg_patreon_members (wp_user_id) VALUES (?)' );
foreach ( $scenario['rows'] as $r ) {
    $ins->execute( [ $r['uid'], 'stripe', $r['stripe'] ] );
    if ( ! empty( $r['pat_rows'] ) ) { $ins->execute( [ $r['uid'], 'patreon', $r['pat_tier'] ] ); }
    if ( ! empty( $r['man_rows'] ) ) { $ins->execute( [ $r['uid'], 'manual_admin', $r['man_tier'] ] ); }
    if ( ! empty( $r['in_pm'] ) )    { $pm->execute( [ $r['uid'] ] ); }
}
$c = 1;
foreach ( $scenario['bridges'] as $uid ) {
    $pdo->prepare( 'INSERT INTO wp_user_bridge (customer_id,wp_user_id) VALUES (?,?)' )->execute( [ $c++, (int) $uid ] );
}

$GLOBALS['PDO'] = $pdo;
$args = array_slice( $argv, 2 );

include __DIR__ . '/check-stuck-sources.php';

}
