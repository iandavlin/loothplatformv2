<?php
/**
 * RED-FIRST GATE for the null-shadow reader fix (handoff §2.1, findings §3).
 *
 *   php test-null-shadow-reader.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * The defect: RoleSourceWriter::readAllForUser consults the live
 * PatreonSourceReader only when NO persisted patreon row exists, and
 * `array_key_exists` is TRUE for tier=NULL — so a stale NULL row SHADOWS a
 * reader that would have said looth2/looth3. Four paying members were nearly
 * demoted through this hole; live still carries two loaded demotions (612,
 * 1768: $11 active patrons whose only source row is a May-07 patreon NULL).
 *
 * Section [1] asserts the DEFECT is real in the OFF state — red-first: the
 * shadow must be demonstrable before the fix is trusted to remove it.
 *
 * Flag: lgms_null_shadow_fix, DEFAULT OFF. The OFF state must be
 * byte-identical to the pre-fix reader over the full input matrix, and is
 * GATED here — the missing OFF assertion is the failure class Ian's phone
 * keeps finding. It is OFF by default because the fix changes the Arbiter's
 * computed outcome for real members (2 on live, 3 on dev2), even though every
 * one of those members' FIXED outcome equals the tier they already hold.
 *
 * Sections [4]/[5] replay the committed 2026-08-09 exports of every member
 * with a persisted NULL patreon row on BOTH boxes through the REAL reader,
 * OFF vs ON, and assert byte-identical effective tiers for every member
 * claimed unaffected — and exactly {17,612,1768} / {612,1768} moving, each
 * onto the tier they already hold.
 */

declare(strict_types=1);

namespace LGMS {
    class Db { public static function pdo() { return $GLOBALS['PDO']; } }
}

namespace {

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "CANNOT RUN: pdo_sqlite missing\n" ); exit( 3 ); }

$SRC = __DIR__ . '/../../src';
foreach ( [ '/RoleSourceWriter.php', '/Patreon/PatreonSourceReader.php' ] as $f ) {
    if ( ! is_readable( $SRC . $f ) ) { fwrite( STDERR, "CANNOT RUN: missing src{$f}\n" ); exit( 3 ); }
}
$FIXTURES = [
    'dev2' => __DIR__ . '/null-shadow-dev2-20260809.tsv',
    'live' => __DIR__ . '/null-shadow-live-20260809.tsv',
];
foreach ( $FIXTURES as $f ) {
    if ( ! is_readable( $f ) ) { fwrite( STDERR, "CANNOT RUN: fixture missing: $f\n" ); exit( 3 ); }
}

// live + dev2 lgpo_tier_map, read 2026-08-09, byte-identical on both boxes.
const NS_TIER_MAP = [
    '10455112' => 'looth1', '24295274' => 'looth4', '5735635' => 'looth2',
    '7757819'  => 'looth2', '22199086' => 'looth2', '6401900' => 'looth3',
    '8603192'  => 'looth3', '9220984'  => 'looth3', '9517681' => 'looth3',
    '22226742' => 'looth3', '7757908'  => 'looth3', '22207438' => 'looth3',
    '5735762'  => 'looth3', '25496422' => 'looth3', '25496403' => 'looth3',
];

$GLOBALS['OPTS'] = [];
$GLOBALS['META'] = [];   // uid => [key => value]

function get_option( $n, $d = false ) {
    if ( $n === 'lgpo_tier_map' ) { return NS_TIER_MAP; }
    return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d;
}
function get_user_meta( $id, $k, $s = false ) { return $GLOBALS['META'][ (int) $id ][ $k ] ?? ''; }

require_once $SRC . '/Patreon/PatreonSourceReader.php';
require_once $SRC . '/RoleSourceWriter.php';

use LGMS\RoleSourceWriter;

/** Fresh in-memory lg_role_sources; rows = [ [uid, source, tier], ... ]. */
function seed( array $rows, array $meta, $flag = null ): void {
    $pdo = new PDO( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->exec( 'CREATE TABLE lg_role_sources (wp_user_id INTEGER, source TEXT, tier TEXT, PRIMARY KEY (wp_user_id, source))' );
    $ins = $pdo->prepare( 'INSERT INTO lg_role_sources (wp_user_id, source, tier) VALUES (?,?,?)' );
    foreach ( $rows as $r ) { $ins->execute( $r ); }
    $GLOBALS['PDO']  = $pdo;
    $GLOBALS['META'] = $meta;
    $GLOBALS['OPTS'] = [];
    if ( $flag !== null ) { $GLOBALS['OPTS']['lgms_null_shadow_fix'] = $flag; }
}

$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $d ? "  ({$d})" : '' ); }
};

// The live shape: a paying member whose ONLY row is patreon=NULL while the
// reader would say looth3 (payment_source=patreon, $11 tier id).
$PAYING_META = [ 612 => [ 'payment_source' => 'patreon', 'lgpo_patreon_tier_id' => '22207438' ] ];

echo "=== null-shadow reader fix — red-first gate ===\n";

// ------------------------------------------------ 1. the defect, demonstrated
echo "\n[1] the DEFECT (flag absent — live today): a NULL row shadows a paying reader\n";
seed( [ [ 612, 'patreon', null ] ], $PAYING_META );
$note( get_option( 'lgms_null_shadow_fix', false ) === false, 'flag is genuinely absent, not seeded' );
$s = RoleSourceWriter::readAllForUser( 612 );
$note( array_key_exists( 'patreon', $s ) && $s['patreon'] === null,
       'reader says looth3, readAllForUser returns patreon=NULL — the shadow is real',
       var_export( $s, true ) );

// ------------------------------------------------ 2. OFF byte-identical matrix
echo "\n[2] flag absent / explicitly OFF — byte-identical to the pre-fix reader\n";
foreach ( [ 'absent' => null, 'off' => false ] as $label => $flag ) {
    // no patreon row at all: the reader is consulted, exactly as before
    seed( [ [ 612, 'stripe', 'looth2' ] ], $PAYING_META, $flag );
    $s = RoleSourceWriter::readAllForUser( 612 );
    $note( ( $s['patreon'] ?? null ) === 'looth3' && $s['stripe'] === 'looth2',
           "flag {$label}: NO row -> reader consulted (fallback intact)" );

    // NULL row: shadows, exactly as before
    seed( [ [ 612, 'patreon', null ] ], $PAYING_META, $flag );
    $s = RoleSourceWriter::readAllForUser( 612 );
    $note( $s === [ 'patreon' => null ], "flag {$label}: NULL row still shadows (no behaviour change)" );

    // non-NULL row: authoritative, exactly as before
    seed( [ [ 612, 'patreon', 'looth2' ] ], $PAYING_META, $flag );
    $s = RoleSourceWriter::readAllForUser( 612 );
    $note( $s === [ 'patreon' => 'looth2' ], "flag {$label}: persisted tier stays authoritative" );
}

// ------------------------------------------------ 3. ON — the fix
echo "\n[3] flag ON — a NULL row no longer blocks the reader\n";
seed( [ [ 612, 'patreon', null ] ], $PAYING_META, true );
$s = RoleSourceWriter::readAllForUser( 612 );
$note( $s === [ 'patreon' => 'looth3' ], 'NULL row + paying reader -> reader answer replaces the shadow',
       var_export( $s, true ) );

// silent reader (payment_source deleted at lapse): the NULL stands
seed( [ [ 612, 'patreon', null ] ], [ 612 => [ 'lgpo_patreon_tier_id' => '22207438' ] ], true );
$s = RoleSourceWriter::readAllForUser( 612 );
$note( $s === [ 'patreon' => null ], 'NULL row + silent reader -> the NULL stands (a lapsed member stays lapsed)' );

// reader speaks but maps to no paid tier: NULL replaces NULL, same value
seed( [ [ 612, 'patreon', null ] ], [ 612 => [ 'payment_source' => 'patreon', 'lgpo_patreon_tier_id' => '10455112' ] ], true );
$s = RoleSourceWriter::readAllForUser( 612 );
$note( $s === [ 'patreon' => null ], 'NULL row + free-tier reader -> still NULL (no invented entitlement)' );

// the circular-read regression guard: a NON-null row is never re-read
seed( [ [ 612, 'patreon', 'looth2' ] ], $PAYING_META, true );
$s = RoleSourceWriter::readAllForUser( 612 );
$note( $s === [ 'patreon' => 'looth2' ],
       'ON: a persisted looth2 outranks a looth3 reader — the sweep stays the authority' );

// other sources ride along untouched (key order is the SELECT's, not ours)
seed( [ [ 612, 'patreon', null ], [ 612, 'stripe', 'looth2' ], [ 612, 'manual_admin', 'looth4' ] ], $PAYING_META, true );
$s = RoleSourceWriter::readAllForUser( 612 );
ksort( $s );
$note( $s === [ 'manual_admin' => 'looth4', 'patreon' => 'looth3', 'stripe' => 'looth2' ],
       'ON: stripe + manual_admin rows are untouched by the refill', var_export( $s, true ) );

// ------------------------------------------------ 4/5. fixture replay, both boxes
// Arbiter projection replica (same helpers classify-orphans validated 40/41
// against live wp_capabilities).
$TIERS   = [ 'looth1', 'looth2', 'looth3', 'looth4' ];
$highest = function ( array $roles ) use ( $TIERS ) {
    $best = null;
    foreach ( $TIERS as $r ) {
        if ( in_array( $r, $roles, true ) && ( $best === null || strcmp( $r, $best ) > 0 ) ) { $best = $r; }
    }
    return $best;
};
$winning = function ( array $sources ) use ( $TIERS ) {
    if ( $sources === [] ) { return null; }
    $best = null;
    foreach ( $sources as $t ) {
        if ( $t === null || ! in_array( $t, $TIERS, true ) ) { continue; }
        if ( $best === null || strcmp( $t, $best ) > 0 ) { $best = $t; }
    }
    return $best ?? 'looth1';
};
$project = function ( array $roles, $w ) use ( $TIERS ) {
    $out = $roles;
    foreach ( $TIERS as $role ) {
        if ( $role === $w ) { continue; }
        if ( $role === 'looth1' && $w === null ) { continue; }
        $out = array_values( array_diff( $out, [ $role ] ) );
    }
    if ( $w !== null && ! in_array( $w, $out, true ) ) { $out[] = $w; }
    return $out;
};
$n = fn( ?string $v ) => ( $v === null || $v === '\\N' || $v === '\\\\N' ) ? null : $v;

$EXPECT = [ 'dev2' => [ 17, 612, 1768 ], 'live' => [ 612, 1768 ] ];
$sect   = 4;
foreach ( $FIXTURES as $box => $path ) {
    printf( "\n[%d] %s fixture — every member with a NULL patreon row, OFF vs ON through the REAL reader\n", $sect++, $box );
    $moved = []; $identical = 0; $guarded = 0; $statusQuo = true; $total = 0;
    foreach ( file( $path, FILE_IGNORE_NEW_LINES ) as $line ) {
        if ( trim( $line ) === '' ) { continue; }
        $f = explode( "\t", $line );
        $total++;
        $uid   = (int) $f[0];
        $rows  = [ [ $uid, 'patreon', null ] ];
        if ( (int) $f[3] > 0 ) { $rows[] = [ $uid, 'stripe', $n( $f[2] ) ]; }
        if ( (int) $f[5] > 0 ) { $rows[] = [ $uid, 'manual_admin', $n( $f[4] ) ]; }
        $meta  = [ $uid => [
            'payment_source'       => (string) ( $n( $f[6] ) ?? '' ),
            'lgpo_patreon_tier_id' => (string) ( $n( $f[7] ) ?? '' ),
        ] ];
        $roles = [];
        $capsS = $n( $f[8] );
        if ( $capsS !== null ) {
            $u = @unserialize( $capsS );
            if ( is_array( $u ) ) { $roles = array_keys( array_filter( $u ) ); }
        }

        // Arbiter guards: these members are unreachable under BOTH states.
        if ( in_array( 'looth4', $roles, true )
             || ( ( $meta[ $uid ]['payment_source'] ) === 'stripe' && ! in_array( 'looth1', $roles, true ) ) ) {
            $guarded++;
            continue;
        }

        seed( $rows, $meta, false );
        $off = $highest( $project( $roles, $winning( RoleSourceWriter::readAllForUser( $uid ) ) ) );
        seed( $rows, $meta, true );
        $on  = $highest( $project( $roles, $winning( RoleSourceWriter::readAllForUser( $uid ) ) ) );

        if ( $off === $on ) { $identical++; continue; }
        $moved[] = $uid;
        if ( $on !== $highest( $roles ) ) { $statusQuo = false; }
    }
    sort( $moved );
    $note( $moved === $EXPECT[ $box ],
           sprintf( '%s: exactly {%s} move under the fix', $box, implode( ',', $EXPECT[ $box ] ) ),
           implode( ',', $moved ) );
    $note( $identical === $total - $guarded - count( $EXPECT[ $box ] ),
           sprintf( '%s: the other %d reachable members are byte-identical OFF vs ON', $box, $identical ),
           (string) $identical );
    $note( $statusQuo,
           $box . ': every mover lands EXACTLY on the tier they already hold — the fix cancels' );
    $note( $guarded === 2, $box . ': 2 members sit behind Arbiter guards (looth4 / stripe-skip), untouched',
           (string) $guarded );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — OFF is the old reader exactly; ON un-shadows only what a paying member already holds.\n";
exit( 0 );

}
