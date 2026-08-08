<?php
/**
 * RED-FIRST GATE for check-stuck-sources.php.
 *
 *   php test-stuck-sources.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Runs the REAL checker over the 41 orphan rows exported from live 2026-08-08,
 * on SQLite with WP stubbed. The checker is read-only, so it executes verbatim.
 *
 * The assertions that earn their keep are the NEGATIVE ones: a check that flags
 * everything is as useless as one that flags nothing. manual_admin must never
 * be called dead, a lapsed patron must never be called dead, a looth4 holder
 * must be skipped, and dead-but-harmless debris must be counted separately
 * from members who are actually held up.
 */

declare(strict_types=1);

namespace {

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "CANNOT RUN: pdo_sqlite\n" ); exit( 3 ); }
$FIXTURE = __DIR__ . '/orphans-20260808.tsv';
if ( ! is_readable( $FIXTURE ) ) { fwrite( STDERR, "CANNOT RUN: fixture missing\n" ); exit( 3 ); }

const T_MAP = [
    '10455112' => 'looth1', '24295274' => 'looth4', '5735635' => 'looth2', '7757819' => 'looth2',
    '22199086' => 'looth2', '6401900' => 'looth3', '8603192' => 'looth3', '9220984' => 'looth3',
    '9517681'  => 'looth3', '22226742' => 'looth3', '7757908' => 'looth3', '22207438' => 'looth3',
    '5735762'  => 'looth3', '25496422' => 'looth3', '25496403' => 'looth3',
];

function load( string $p ): array {
    $out = [];
    foreach ( file( $p, FILE_IGNORE_NEW_LINES ) as $line ) {
        if ( $line === '' ) { continue; }
        $f = explode( "\t", $line );
        $n = fn( $v ) => ( $v === '\\N' || $v === '\\\\N' ) ? null : $v;
        $roles = [];
        if ( ( $c = $n( $f[10] ) ) !== null ) { $u = @unserialize( $c ); if ( is_array( $u ) ) { $roles = array_keys( array_filter( $u ) ); } }
        $out[ (int) $f[0] ] = [
            'uid' => (int) $f[0], 'stripe' => $n( $f[1] ), 'email' => $f[2],
            'pat_tier' => $n( $f[3] ), 'pat_rows' => (int) $f[4],
            'man_tier' => $n( $f[5] ), 'man_rows' => (int) $f[6],
            'pay_src' => $n( $f[8] ), 'pat_tid' => $n( $f[9] ), 'roles' => $roles,
            'patron_st' => $n( $f[11] ),
            // live: every one of these carries a patreon linkage
            'pat_uid' => $n( $f[11] ) !== null ? 'pid-' . $f[0] : null,
            'in_pm'   => $n( $f[11] ) !== null,
        ];
    }
    return $out;
}

/** Run the real checker as a SUBPROCESS so its genuine exit code survives. */
function run( array $rows, array $bridges = [], array $args = [] ): array {
    $scn = [ 'rows' => array_values( $rows ), 'bridges' => array_values( $bridges ), 'tier_map' => T_MAP ];
    $tmp = tempnam( sys_get_temp_dir(), 'stuck' );
    file_put_contents( $tmp, json_encode( $scn ) );
    $cmd = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/_stuck-harness.php' )
         . ' ' . escapeshellarg( $tmp );
    foreach ( $args as $a ) { $cmd .= ' ' . escapeshellarg( $a ); }
    exec( $cmd . ' 2>&1', $lines, $code );
    unlink( $tmp );
    return [ $code, implode( "\n", $lines ) . "\n" ];
}

$BASE = load( $FIXTURE );
$fail = 0; $pass = 0;
$note = function ( bool $ok, string $l, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $l ); } else { $fail++; printf( "  FAIL %s%s\n", $l, $d ? "  ({$d})" : '' ); }
};
$ids = function ( string $out ): array {
    preg_match_all( '/^  (\d{3,})\s/m', $out, $m );
    $i = array_map( 'intval', $m[1] ); sort( $i ); return $i;
};
$held = function ( string $out ): int {
    return preg_match( '/MEMBERS HELD UP BY A DEAD SOURCE: (\d+)/', $out, $m ) ? (int) $m[1] : -1;
};

echo "=== stuck-source detector — red-first gate ===\n";
printf( "fixture: %d orphan rows (live 2026-08-08)\n\n", count( $BASE ) );

// ---------------------------------------------- 1. finds the real ones
echo "[1] over live's own state\n";
[ $code, $out ] = run( $BASE );
$found = $ids( $out );
$note( $held( $out ) === 10, 'flags exactly 10 members held up by a dead source', (string) $held( $out ) );
$note( $found === [ 1817, 1840, 1860, 1861, 1862, 1863, 1869, 1870, 1881, 1884 ],
       'and they are the 10 the classifier independently identified', implode( ',', $found ) );
$note( $code === 1, 'exits 1 — an open defect, not a CANNOT RUN', (string) $code );
$note( preg_match( '/debris, safe to retract\): (\d+)/', $out, $m ) && (int) $m[1] > 0,
       'harmless debris is counted SEPARATELY from held-up members', $m[1] ?? '?' );

// ---------------------------------------------- 2. the negatives
echo "\n[2] the negatives — a check that flags everything is useless\n";
$note( ! in_array( 1894, $found, true ), '1894 NOT flagged — manual_admin is never a dead source' );
$note( ! in_array( 1829, $found, true ) && ! in_array( 1865, $found, true ),
       'looth4 holders NOT flagged (Arbiter returns early; protected)' );
$note( ! in_array( 1859, $found, true ) && ! in_array( 1868, $found, true ),
       'lapsed patrons NOT flagged — a live source saying "no tier" is not dead' );
$note( ! in_array( 1879, $found, true ),
       '1879 NOT flagged — its dead row is latent, not load-bearing today' );

// ---------------------------------------------- 3. mutations
echo "\n[3] mutations — the detector must be movable in both directions\n";

// bridging every flagged user makes their stripe source live => clean
[ , $o ] = run( $BASE, array_keys( $BASE ) );
$note( $held( $o ) === 0 && strpos( $o, 'CLEAN' ) !== false,
       'bridge every user => 0 held, reports CLEAN' );
[ $c2, ] = run( $BASE, array_keys( $BASE ) );
$note( $c2 === 0, 'and exits 0' );

// give 1894 back a dead-but-load-bearing shape by removing manual_admin
$m = $BASE; $m[1894]['man_rows'] = 0; $m[1894]['man_tier'] = null;
[ , $o ] = run( $m );
$note( in_array( 1894, $ids( $o ), true ), '1894 without manual_admin IS flagged' );

// A patreon row is only dead AND load-bearing when the live reader cannot
// refill it either — i.e. no linkage AND no payment_source for the adapter to
// speak through. With the linkage merely removed the reader still answers, so
// retracting the row moves nobody. That distinction is the check's whole point.
$m = $BASE; $m[1853]['pat_uid'] = null; $m[1853]['in_pm'] = false;
[ , $o ] = run( $m );
$note( ! in_array( 1853, $ids( $o ), true ),
       'a dead patreon row the READER still refills is NOT flagged (harmless)' );

$m = $BASE; $m[1853]['pat_uid'] = null; $m[1853]['in_pm'] = false; $m[1853]['pay_src'] = null;
[ , $o ] = run( $m );
$note( in_array( 1853, $ids( $o ), true ),
       'a dead patreon row with no reader behind it IS flagged' );

// no-op mutation must not move anything
$m = $BASE; $m[1820]['email'] = 'renamed@example.com';
[ , $o ] = run( $m );
$note( $ids( $o ) === $found, 'an irrelevant change flips nothing' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — the detector finds the real cases, ignores the healthy ones, and moves.\n";
exit( 0 );

}
