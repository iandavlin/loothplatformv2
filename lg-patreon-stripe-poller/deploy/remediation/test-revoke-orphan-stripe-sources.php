<?php
/**
 * RED-FIRST GATE for revoke-orphan-stripe-sources.php.
 *
 * Runs the REAL remediation script (not a replica of it) in review mode against
 * the 41 orphan rows exported from live on 2026-08-08, on a throwaway SQLite
 * database with the WP layer stubbed. Review mode touches only SELECTs, so the
 * classifier — where all the risk lives — executes verbatim.
 *
 *   php test-revoke-orphan-stripe-sources.php
 *
 * Exit 0 = green, 1 = a real failure, 3 = cannot run (missing fixture / pdo_sqlite).
 * Per docs/CRAFT-STANDARD.md an open defect is exit 1; "could not run" must never
 * be reported as a finding.
 *
 * The point of the MUTATION cases is that a classifier which cannot be moved is
 * not a classifier. Each one perturbs exactly one input and asserts the verdict
 * changes in the stated direction. If a mutation ever stops flipping the result,
 * that assertion has gone decorative and the gate says so.
 */

declare(strict_types=1);

namespace LGMS {
    class Db     { public static function pdo() { return $GLOBALS['PDO']; } }
    class Arbiter {
        public static array $calls = [];
        public static function sync( int $uid ): array { self::$calls[] = $uid; return [ 'ok' => true, 'winning_tier' => null ]; }
    }
}

namespace {

$HERE    = __DIR__;
$FIXTURE = $HERE . '/orphans-20260808.tsv';
$SCRIPT  = $HERE . '/revoke-orphan-stripe-sources.php';

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "CANNOT RUN: pdo_sqlite missing\n" ); exit( 3 ); }
if ( ! is_readable( $FIXTURE ) )          { fwrite( STDERR, "CANNOT RUN: fixture missing: $FIXTURE\n" ); exit( 3 ); }
if ( ! is_readable( $SCRIPT ) )           { fwrite( STDERR, "CANNOT RUN: script missing: $SCRIPT\n" ); exit( 3 ); }

// live wp_options lgpo_tier_map, read 2026-08-08 (string keys — get_option
// returns the unserialized array whose keys PHP casts to int; the script
// looks them up with a string tier id, so both forms must resolve).
const FIX_TIER_MAP = [
    '10455112' => 'looth1', '24295274' => 'looth4', '5735635' => 'looth2',
    '7757819'  => 'looth2', '22199086' => 'looth2', '6401900' => 'looth3',
    '8603192'  => 'looth3', '9220984'  => 'looth3', '9517681' => 'looth3',
    '22226742' => 'looth3', '7757908'  => 'looth3', '22207438' => 'looth3',
    '5735762'  => 'looth3', '25496422' => 'looth3', '25496403' => 'looth3',
];

// ---------------------------------------------------------------- fixture

function load_fixture( string $path ): array {
    $rows = [];
    foreach ( file( $path, FILE_IGNORE_NEW_LINES ) as $line ) {
        if ( $line === '' ) { continue; }
        $f = explode( "\t", $line );
        $n = fn( $v ) => ( $v === '\\N' || $v === '\\\\N' ) ? null : $v;
        $caps  = $n( $f[10] );
        $roles = [];
        if ( $caps !== null ) {
            $u = @unserialize( $caps );
            if ( is_array( $u ) ) { $roles = array_keys( array_filter( $u ) ); }
        }
        $rows[ (int) $f[0] ] = [
            'uid' => (int) $f[0], 'stripe' => $n( $f[1] ), 'email' => $f[2],
            'pat_tier' => $n( $f[3] ), 'pat_rows' => (int) $f[4],
            'man_tier' => $n( $f[5] ), 'man_rows' => (int) $f[6],
            'pay_src' => $n( $f[8] ), 'pat_tid' => $n( $f[9] ), 'roles' => $roles,
            'patron_st' => $n( $f[11] ), 'cents' => $n( $f[12] ), 'label' => $n( $f[13] ),
            'updated' => $f[14],
        ];
    }
    return $rows;
}

/**
 * PDO shim: rewrites MySQL upsert syntax to SQLite so the APPLY path — journal,
 * repair, delete, revert — runs verbatim rather than being asserted from the
 * outside. The rewrite is deliberately narrow: it only understands the two
 * statements the script actually issues, so a third would fail loudly here
 * rather than being silently mistranslated.
 */
final class TestPdo extends PDO {
    public function prepare( string $q, array $o = [] ): PDOStatement|false {
        if ( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false ) {
            $q = preg_replace( '/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(wp_user_id, source) DO UPDATE SET', $q );
            $q = preg_replace( '/VALUES\s*\(\s*(\w+)\s*\)/i', 'excluded.$1', $q );
            // guard: the VALUES(col) rewrite must not have eaten the INSERT's own VALUES list
            if ( strpos( $q, 'excluded.' ) === false ) {
                throw new RuntimeException( "upsert rewrite failed: $q" );
            }
        }
        return parent::prepare( $q, $o );
    }
}

/** Build a throwaway SQLite lg_membership seeded from the fixture. */
function build_pdo( array $rows ): PDO {
    $pdo = new TestPdo( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->exec( 'CREATE TABLE lg_role_sources (wp_user_id INTEGER, source TEXT, tier TEXT, updated_at TEXT, PRIMARY KEY (wp_user_id, source))' );
    $pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE)' );
    $pdo->exec( 'CREATE TABLE lg_patreon_members (wp_user_id INTEGER PRIMARY KEY, patron_status TEXT, currently_entitled_amount_cents INTEGER, tier_label TEXT)' );

    $rs = $pdo->prepare( 'INSERT INTO lg_role_sources (wp_user_id, source, tier, updated_at) VALUES (?,?,?,?)' );
    $pm = $pdo->prepare( 'INSERT INTO lg_patreon_members (wp_user_id, patron_status, currently_entitled_amount_cents, tier_label) VALUES (?,?,?,?)' );
    foreach ( $rows as $r ) {
        $rs->execute( [ $r['uid'], 'stripe', $r['stripe'], $r['updated'] ] );
        if ( $r['pat_rows'] > 0 ) { $rs->execute( [ $r['uid'], 'patreon', $r['pat_tier'], $r['updated'] ] ); }
        if ( $r['man_rows'] > 0 ) { $rs->execute( [ $r['uid'], 'manual_admin', $r['man_tier'], $r['updated'] ] ); }
        if ( $r['patron_st'] !== null || $r['cents'] !== null ) {
            $pm->execute( [ $r['uid'], $r['patron_st'], $r['cents'], $r['label'] ] );
        }
    }
    return $pdo;
}

// ---------------------------------------------------------------- WP stubs

$GLOBALS['FIX']  = [];
$GLOBALS['PDO']  = null;
$GLOBALS['OPTS'] = [];

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/stub/' ); }

function get_user_by( $field, $id ) {
    $r = $GLOBALS['FIX'][ (int) $id ] ?? null;
    if ( ! $r ) { return false; }
    return (object) [ 'ID' => $r['uid'], 'roles' => $r['roles'], 'user_email' => $r['email'] ];
}
function get_user_meta( $id, $key, $single = false ) {
    $r = $GLOBALS['FIX'][ (int) $id ] ?? null;
    if ( ! $r ) { return ''; }
    if ( $key === 'payment_source' )       { return $r['pay_src'] ?? ''; }
    if ( $key === 'lgpo_patreon_tier_id' ) { return $r['pat_tid'] ?? ''; }
    return '';
}
function get_option( $name, $default = false ) {
    if ( $name === 'lgpo_tier_map' ) { return FIX_TIER_MAP; }
    return $GLOBALS['OPTS'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTS'][ $name ] = $value; return true; }
function current_time( $type ) { return '2026-08-08 22:30:00'; }


/** Run the real script in review mode over $rows; return its stdout. */
function run_script( array $rows, array $args = [] ): string {
    $GLOBALS['FIX'] = $rows;
    $GLOBALS['PDO'] = build_pdo( $rows );
    $GLOBALS['OPTS'] = [];
    \LGMS\Arbiter::$calls = [];
    $args_local = $args;
    $run = function () use ( $args_local ) {
        $args = $args_local;             // the script reads `$args ?? []`
        ob_start();
        include __DIR__ . '/revoke-orphan-stripe-sources.php';
        return ob_get_clean();
    };
    return $run();
}

/** Run the script against an ALREADY-BUILT db/options pair (state persists). */
function run_again( array $args = [] ): string {
    \LGMS\Arbiter::$calls = [];
    $args_local = $args;
    $run = function () use ( $args_local ) {
        $args = $args_local;
        ob_start();
        include __DIR__ . '/revoke-orphan-stripe-sources.php';
        return ob_get_clean();
    };
    return $run();
}

/** Snapshot every role-source row, for byte-comparison across a round trip. */
function snapshot_rows(): array {
    $r = $GLOBALS['PDO']->query( 'SELECT wp_user_id, source, tier, updated_at FROM lg_role_sources ORDER BY wp_user_id, source' )
                        ->fetchAll( PDO::FETCH_ASSOC );
    return $r;
}

/** Pull the ids the script listed under a given class heading. */
function ids_in_class( string $out, string $cls ): array {
    if ( ! preg_match( '/^--- ' . $cls . ' \(\d+\)\n((?:  #.*\n)*)/m', $out, $m ) ) { return []; }
    preg_match_all( '/^  #(\d+)/m', $m[1], $n );
    $ids = array_map( 'intval', $n[1] );
    sort( $ids );
    return $ids;
}

$BASE = load_fixture( $FIXTURE );
$fail = 0;
$pass = 0;
$note = function ( bool $ok, string $label, string $detail = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $detail ? "  ({$detail})" : '' ); }
};

echo "=== revoke-orphan-stripe-sources — red-first gate ===\n";
printf( "fixture: %d orphan rows (live 2026-08-08)\n\n", count( $BASE ) );

// ------------------------------------------------ 1. baseline classification
echo "[1] baseline classification matches the pinned expectation\n";
$out = run_script( $BASE );

$note( count( $BASE ) === 41, 'fixture carries all 41 orphan rows', 'got ' . count( $BASE ) );
$note( ids_in_class( $out, 'REPAIR' )  === [ 1817, 1840, 1861, 1881 ], 'REPAIR  = 1817,1840,1861,1881',
       implode( ',', ids_in_class( $out, 'REPAIR' ) ) );
$note( ids_in_class( $out, 'VISIBLE' ) === [ 1860, 1862, 1863, 1869, 1870, 1884 ], 'VISIBLE = 1860,1862,1863,1869,1870,1884',
       implode( ',', ids_in_class( $out, 'VISIBLE' ) ) );
$note( ids_in_class( $out, 'DEFUSES' ) === [ 1879 ], 'DEFUSES = 1879',
       implode( ',', ids_in_class( $out, 'DEFUSES' ) ) );
$note( count( ids_in_class( $out, 'INERT' ) ) === 30, 'INERT   = 30 rows',
       (string) count( ids_in_class( $out, 'INERT' ) ) );
$note( strpos( $out, 'Pinned expectation matches live exactly' ) !== false, 'tripwire reports no drift' );
$note( strpos( $out, 'REVIEW ONLY — nothing was written' ) !== false, 'review mode writes nothing' );
$note( \LGMS\Arbiter::$calls === [], 'review mode never calls the Arbiter' );

// ------------------------------------------------ 2. the held set is held
echo "\n[2] member-visible rows are held, and released only by explicit id\n";
$note( preg_match( '/PLAN: (\d+) rows to delete \(4 repair-first\), 6 HELD/', $out, $m ) === 1
       && (int) $m[1] === 35, 'default plan = 35 delete / 6 held', $m[1] ?? '?' );

$out_allow = run_script( $BASE, [ 'allow=1863' ] );
$note( preg_match( '/PLAN: (\d+) rows to delete .*, 5 HELD/', $out_allow, $m2 ) === 1
       && (int) $m2[1] === 36, 'allow=1863 releases exactly one row', $m2[1] ?? '?' );
$note( strpos( $out_allow, 'allow=all' ) === false, 'no bulk-release form exists' );

// ------------------------------------------------ 3. MUTATIONS (red-first)
echo "\n[3] mutations must flip the verdict — an immovable classifier is decoration\n";

// 3a. A paying member's patreon row is what makes them REPAIR, not VISIBLE.
//     Mark 1861 a former_patron: the repair precondition fails, so the row
//     must fall through to VISIBLE (held) rather than being silently repaired.
$mut = $BASE;
$mut[1861]['patron_st'] = 'former_patron';
$mut[1861]['cents']     = '0';
$o = run_script( $mut );
$note( ! in_array( 1861, ids_in_class( $o, 'REPAIR' ), true )
       && in_array( 1861, ids_in_class( $o, 'VISIBLE' ), true ),
       '1861 lapsed  => REPAIR -> VISIBLE (held, not silently repaired)' );

// 3b. The no-op condition is load-bearing. Give 1817 a tier id that maps to
//     looth3 while they hold looth2: repairing would MOVE them, so it must be
//     refused and held instead.
$mut = $BASE;
$mut[1817]['pat_tid'] = '22207438';           // -> looth3, but they hold looth2
$o = run_script( $mut );
$note( ! in_array( 1817, ids_in_class( $o, 'REPAIR' ), true )
       && in_array( 1817, ids_in_class( $o, 'VISIBLE' ), true ),
       '1817 repair-would-move => refused, held' );

// 3c. The shadowing bug itself. Delete 1817's stale patreon ROW (leaving the
//     adapter free to speak) and the row becomes INERT — proving the classifier
//     is reading row-existence, not just the tier value.
$mut = $BASE;
$mut[1817]['pat_rows'] = 0;
$mut[1817]['pat_tier'] = null;
$o = run_script( $mut );
$note( in_array( 1817, ids_in_class( $o, 'INERT' ), true ),
       '1817 without the shadowing row => INERT (row-existence is read, not the tier)' );

// 3d. looth4 protection.
$mut = $BASE;
$mut[1860]['roles'] = [ 'looth4', 'bbp_participant' ];
$o = run_script( $mut );
$note( in_array( 1860, ids_in_class( $o, 'INERT' ), true ), '1860 as looth4 => INERT (protected)' );

// 3e. The manual_admin row is what saves 1894. Remove it and they must move.
$mut = $BASE;
$mut[1894]['man_rows'] = 0;
$mut[1894]['man_tier'] = null;
$o = run_script( $mut );
$note( in_array( 1894, ids_in_class( $o, 'VISIBLE' ), true ),
       '1894 without manual_admin => VISIBLE (the audit\'s claim, under its own premise)' );

// 3f. The drift tripwire must actually trip, and must block apply.
$mut = $BASE;
unset( $mut[1818] );                           // 40 rows, not 41
$o = run_script( $mut, [ 'apply' ] );
$note( strpos( $o, 'DRIFT from the 2026-08-08 pinned expectation' ) !== false, 'drift tripwire fires' );
$note( strpos( $o, 'REFUSING to apply' ) !== false, 'drift blocks apply' );
$o = run_script( $mut, [ 'apply', 'accept-drift' ] );
$note( strpos( $o, 'REFUSING to apply' ) === false, 'accept-drift overrides the block' );

// 3g. A no-op mutation must NOT flip anything (guards against a gate that
//     reddens on any perturbation, which would be equally useless).
$mut = $BASE;
$mut[1820]['email'] = 'renamed@example.com';
$o = run_script( $mut );
$note( ids_in_class( $o, 'VISIBLE' ) === [ 1860, 1862, 1863, 1869, 1870, 1884 ],
       'an irrelevant change flips nothing' );

// ------------------------------------------------ 4. apply -> revert round trip
echo "\n[4] apply is journalled and fully reversible\n";

$before_rows = null;
run_script( $BASE );                       // builds the db + options
$before_rows = snapshot_rows();
$n_before    = count( $before_rows );

$o_apply = run_again( [ 'apply' ] );
$note( preg_match( '/Applied batch (\S+) — rows deleted: (\d+), patreon rows repaired: (\d+)/', $o_apply, $ma ) === 1,
       'apply reports a batch id and counts' );
$batch_id = $ma[1] ?? '';
$note( ( $ma[2] ?? '' ) === '35', 'apply deleted exactly 35 rows', $ma[2] ?? '?' );
$note( ( $ma[3] ?? '' ) === '4',  'apply repaired exactly 4 patreon rows', $ma[3] ?? '?' );

$after_apply = snapshot_rows();
$stripe_left = count( array_filter( $after_apply, fn( $r ) => $r['source'] === 'stripe' ) );
$note( $stripe_left === 6, '6 stripe rows remain (the held, member-visible ones)', (string) $stripe_left );

// the four repairs must be visible in the store, not merely claimed in stdout
$pat = [];
foreach ( $after_apply as $r ) { if ( $r['source'] === 'patreon' ) { $pat[ (int) $r['wp_user_id'] ] = $r['tier']; } }
$note( ( $pat[1817] ?? null ) === 'looth2' && ( $pat[1840] ?? null ) === 'looth2'
    && ( $pat[1861] ?? null ) === 'looth3' && ( $pat[1881] ?? null ) === 'looth3',
       'repaired patreon rows hold the entitled tier in the STORE' );

$o_ver = run_again( [ 'verify' ] );
$note( strpos( $o_ver, 'Clean: everything that can be retracted' ) !== false, 'verify reports clean' );

// idempotence: a second apply finds nothing left to do
$o_again = run_again( [ 'apply', 'accept-drift' ] );
$note( preg_match( '/rows deleted: 0,/', $o_again ) === 1, 'second apply is a no-op (idempotent)' );

$o_rev = run_again( [ 'revert', $batch_id ] );
$note( strpos( $o_rev, 'stripe rows restored: 35' ) !== false, 'revert restores all 35 rows' );
$note( strpos( $o_rev, 'patreon repairs undone: 4' ) !== false, 'revert undoes all 4 repairs' );

$after_revert = snapshot_rows();
$note( count( $after_revert ) === $n_before, 'row count returns to its pre-apply value',
       count( $after_revert ) . ' vs ' . $n_before );
$note( $after_revert == $before_rows, 'every row is byte-identical to its pre-apply state' );

$o_rev2 = run_again( [ 'revert', $batch_id ] );
$note( strpos( $o_rev2, 'already reverted' ) !== false, 'double-revert is refused' );

// ------------------------------------------------ summary
printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED — do not run the remediation on live.\n"; exit( 1 ); }
echo "GREEN — classifier agrees with the pinned live expectation and is movable.\n";
exit( 0 );

}
