<?php
/**
 * RED-FIRST GATE for the retraction sweep (design doc §4, Phase 1).
 *
 *   php test-retraction-sweep.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Covers BOTH legs: the detection pass wired into the tick
 * (LGMS\RetractionSweep, behind lgms_retraction_sweep) and the explicit
 * apply invocation (apply-retraction-sweep.php) — the REAL code in both
 * cases, over a SQLite lg_membership shaped like live.
 *
 * What must hold, in order of what it costs to get wrong:
 *
 *   1. OFF (absent AND explicit) is a TOTAL no-op — no DB read, no option
 *      write, no log line. This is the gated OFF state; the missing OFF
 *      assertion is the failure class behind every phone-vs-suite miss.
 *   2. Detection NEVER moves a member — v1 journals + notifies only. Rows
 *      and roles must be byte-identical across a tick, and the Arbiter must
 *      never be called.
 *   3. It iterates THE OPINIONS, not customers: an opinion whose customer
 *      row was deleted outright — the shape all 41 live orphans have, which
 *      a customers-driven sweep can never visit — MUST be found.
 *   4. The negatives: a justified opinion is never flagged, and a NULL
 *      opinion on a live bridged customer is the correct retracted state,
 *      not a finding.
 *   5. Apply holds anything member-visible for a per-member allow=, repairs
 *      the shadowed-patreon case before retracting (the class that nearly
 *      demoted four paying members), journals BEFORE mutating, and reverts
 *      byte-identically.
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

$SRC = __DIR__ . '/../../src';
foreach ( [ '/RetractionSweep.php', '/RoleSourceWriter.php', '/Patreon/PatreonSourceReader.php' ] as $f ) {
    if ( ! is_readable( $SRC . $f ) ) { fwrite( STDERR, "CANNOT RUN: missing src{$f}\n" ); exit( 3 ); }
}
if ( ! is_readable( __DIR__ . '/apply-retraction-sweep.php' ) ) {
    fwrite( STDERR, "CANNOT RUN: apply-retraction-sweep.php missing\n" );
    exit( 3 );
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/stub/' ); }

const TRS_TIER_MAP = [ '22199086' => 'looth2', '22207438' => 'looth3', '10455112' => 'looth1' ];

$GLOBALS['OPTS'] = [];
$GLOBALS['AUTOLOAD'] = [];
$GLOBALS['LOG']  = [];
$GLOBALS['FIX']  = [];

function get_option( $n, $d = false ) {
    if ( $n === 'lgpo_tier_map' ) { return TRS_TIER_MAP; }
    return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d;
}
function update_option( $n, $v, $autoload = null ) {
    $GLOBALS['OPTS'][ $n ] = $v;
    $GLOBALS['AUTOLOAD'][ $n ] = $autoload;
    return true;
}
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
function current_time( $type ) { return '2026-08-09 12:00:00'; }

require_once $SRC . '/Patreon/PatreonSourceReader.php';
require_once $SRC . '/RoleSourceWriter.php';
require_once $SRC . '/RetractionSweep.php';

use LGMS\RetractionSweep;

/** SQLite shim for the one MySQL upsert the apply/revert path issues. */
final class TestPdo extends PDO {
    public function prepare( string $q, array $o = [] ): PDOStatement|false {
        if ( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false ) {
            $q = preg_replace( '/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(wp_user_id, source) DO UPDATE SET', $q );
            $q = preg_replace( '/VALUES\s*\(\s*(\w+)\s*\)/i', 'excluded.$1', $q );
            if ( strpos( $q, 'excluded.' ) === false ) {
                throw new RuntimeException( "upsert rewrite failed: $q" );
            }
        }
        return parent::prepare( $q, $o );
    }
}

/**
 * The live-shaped scenario. Every reason class, both negatives, the repair
 * case, the looth4 guard, a defused latent, and a debris row with no user.
 */
function scenario(): void {
    $pdo = new TestPdo( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->exec( 'CREATE TABLE lg_role_sources (wp_user_id INTEGER, source TEXT, tier TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (wp_user_id, source))' );
    $pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE)' );
    $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY, deleted_at TEXT)' );
    $pdo->exec( 'CREATE TABLE entitlements (id INTEGER PRIMARY KEY, customer_id INTEGER, kind TEXT, ref TEXT, revoked_at TEXT, starts_at TEXT, expires_at TEXT)' );
    $pdo->exec( 'CREATE TABLE lg_patreon_members (wp_user_id INTEGER PRIMARY KEY, patron_status TEXT, currently_entitled_amount_cents INTEGER)' );

    $rs = $pdo->prepare( 'INSERT INTO lg_role_sources (wp_user_id, source, tier, updated_at) VALUES (?,?,?,?)' );
    $rows = [
        // uid, source, tier
        [ 101, 'stripe', 'looth3' ],   // orphan, reader refills looth3 -> INERT delete
        [ 102, 'stripe', null     ],   // orphan NULL debris -> INERT delete
        [ 103, 'stripe', 'looth2' ],   // justified: live customer + active looth2
        [ 104, 'stripe', null     ],   // justified: NULL opinion, live customer
        [ 105, 'stripe', 'looth2' ],   // no_entitlement, moves -> VISIBLE held
        [ 106, 'stripe', 'looth2' ],   // customer_deleted, manual_admin holds -> INERT
        [ 106, 'manual_admin', 'looth2' ],
        [ 107, 'stripe', null     ],   // bridge to a customer row that is GONE
        [ 108, 'stripe', 'looth3' ],   // tier_mismatch (entitled looth2), moves -> VISIBLE held
        [ 109, 'stripe', 'looth2' ],   // REPAIR: shadowed NULL patreon row, paying member
        [ 109, 'patreon', null    ],
        [ 110, 'stripe', 'looth3' ],   // looth4 holder: finding, but Arbiter-guarded -> INERT
        [ 111, 'stripe', 'looth3' ],   // DEFUSES: latent upgrade, reader says looth2
        [ 112, 'stripe', 'looth2' ],   // NOUSER debris
    ];
    foreach ( $rows as $r ) { $rs->execute( [ $r[0], $r[1], $r[2], '2026-05-07 19:40:10' ] ); }

    foreach ( [ [ 11, 103 ], [ 12, 104 ], [ 13, 105 ], [ 14, 106 ], [ 15, 107 ], [ 16, 108 ] ] as $b ) {
        $pdo->prepare( 'INSERT INTO wp_user_bridge (customer_id, wp_user_id) VALUES (?,?)' )->execute( $b );
    }
    // customer 15 deliberately ABSENT (customer_gone); 14 soft-deleted
    foreach ( [ [ 11, null ], [ 12, null ], [ 13, null ], [ 14, '2026-06-01 00:00:00' ], [ 16, null ] ] as $c ) {
        $pdo->prepare( 'INSERT INTO customers (id, deleted_at) VALUES (?,?)' )->execute( $c );
    }
    $ent = $pdo->prepare( 'INSERT INTO entitlements (customer_id, kind, ref, revoked_at, starts_at, expires_at) VALUES (?,?,?,?,?,?)' );
    $ent->execute( [ 11, 'membership_tier', 'looth2', null, '2026-01-01 00:00:00', null ] );                    // 103 justified
    $ent->execute( [ 13, 'membership_tier', 'looth2', '2026-06-09 00:00:00', '2026-01-01 00:00:00', null ] );  // 105 revoked
    $ent->execute( [ 16, 'membership_tier', 'looth2', null, '2026-01-01 00:00:00', null ] );                    // 108 mismatch
    $ent->execute( [ 16, 'gift_code_thing', 'looth3', null, '2026-01-01 00:00:00', null ] );                    // wrong kind: ignored

    $pm = $pdo->prepare( 'INSERT INTO lg_patreon_members (wp_user_id, patron_status, currently_entitled_amount_cents) VALUES (?,?,?)' );
    $pm->execute( [ 101, 'active_patron', 1100 ] );
    $pm->execute( [ 109, 'active_patron', 500 ] );
    $pm->execute( [ 111, 'active_patron', 500 ] );

    $GLOBALS['PDO'] = $pdo;
    $GLOBALS['FIX'] = [
        101 => [ 'uid' => 101, 'email' => 'u101@x.test', 'roles' => [ 'looth3', 'bbp_participant' ], 'pay_src' => 'patreon', 'pat_tid' => '22207438' ],
        102 => [ 'uid' => 102, 'email' => 'u102@x.test', 'roles' => [ 'looth1' ], 'pay_src' => '', 'pat_tid' => '' ],
        103 => [ 'uid' => 103, 'email' => 'u103@x.test', 'roles' => [ 'looth2' ], 'pay_src' => 'stripe', 'pat_tid' => '' ],
        104 => [ 'uid' => 104, 'email' => 'u104@x.test', 'roles' => [ 'looth1' ], 'pay_src' => 'stripe', 'pat_tid' => '' ],
        105 => [ 'uid' => 105, 'email' => 'u105@x.test', 'roles' => [ 'looth2', 'looth1' ], 'pay_src' => '', 'pat_tid' => '' ],
        106 => [ 'uid' => 106, 'email' => 'u106@x.test', 'roles' => [ 'looth2' ], 'pay_src' => '', 'pat_tid' => '' ],
        107 => [ 'uid' => 107, 'email' => 'u107@x.test', 'roles' => [ 'looth1' ], 'pay_src' => '', 'pat_tid' => '' ],
        108 => [ 'uid' => 108, 'email' => 'u108@x.test', 'roles' => [ 'looth3' ], 'pay_src' => '', 'pat_tid' => '' ],
        109 => [ 'uid' => 109, 'email' => 'u109@x.test', 'roles' => [ 'looth2' ], 'pay_src' => 'patreon', 'pat_tid' => '22199086' ],
        110 => [ 'uid' => 110, 'email' => 'u110@x.test', 'roles' => [ 'looth4' ], 'pay_src' => '', 'pat_tid' => '' ],
        111 => [ 'uid' => 111, 'email' => 'u111@x.test', 'roles' => [ 'looth2' ], 'pay_src' => 'patreon', 'pat_tid' => '22199086' ],
        // 112 deliberately has NO WP user
    ];
    $GLOBALS['OPTS'] = [];
    $GLOBALS['AUTOLOAD'] = [];
    $GLOBALS['LOG'] = [];
    \LGMS\Db::$calls = 0;
    \LGMS\Arbiter::$calls = [];
}

function snapshot_rows(): array {
    return $GLOBALS['PDO']->query( 'SELECT wp_user_id, source, tier, updated_at FROM lg_role_sources ORDER BY wp_user_id, source' )
                          ->fetchAll( PDO::FETCH_ASSOC );
}
function row_tier( int $uid, string $source ) {
    $st = $GLOBALS['PDO']->prepare( 'SELECT tier, COUNT(*) AS n FROM lg_role_sources WHERE wp_user_id = ? AND source = ?' );
    $st->execute( [ $uid, $source ] );
    $r = $st->fetch( PDO::FETCH_ASSOC );
    return (int) $r['n'] === 0 ? 'ABSENT' : $r['tier'];
}
function run_apply( array $args = [] ): string {
    $args_local = $args;
    $run = function () use ( $args_local ) {
        $args = $args_local;
        ob_start();
        include __DIR__ . '/apply-retraction-sweep.php';
        return ob_get_clean();
    };
    return $run();
}

$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $d ? "  ({$d})" : '' ); }
};

echo "=== retraction sweep — red-first gate ===\n";

// ------------------------------------------------ 1. OFF is a total no-op
echo "\n[1] flag ABSENT (live's state at merge) and explicitly OFF — total no-op\n";
scenario();
$note( get_option( RetractionSweep::FLAG, false ) === false, 'option is genuinely absent, not seeded' );
RetractionSweep::tick();
$note( \LGMS\Db::$calls === 0, 'absent: the database is never even touched', (string) \LGMS\Db::$calls );
$note( $GLOBALS['OPTS'] === [], 'absent: no option is written' );
$note( $GLOBALS['LOG'] === [], 'absent: not a single log line' );

$GLOBALS['OPTS'][ RetractionSweep::FLAG ] = false;
$opts_before = $GLOBALS['OPTS'];
RetractionSweep::tick();
$note( \LGMS\Db::$calls === 0 && $GLOBALS['OPTS'] === $opts_before && $GLOBALS['LOG'] === [],
       'explicit OFF: identical — no read, no write, no line' );

// ------------------------------------------------ 2. ON — detection
echo "\n[2] flag ON — finds every unjustifiable opinion, by the right reason\n";
scenario();
$GLOBALS['OPTS'][ RetractionSweep::FLAG ] = true;
$before = snapshot_rows();
RetractionSweep::tick();

$j = get_option( RetractionSweep::FINDINGS, null );
$found = is_array( $j ) ? array_keys( $j['findings'] ) : [];
sort( $found );
$note( $found === [ 101, 102, 105, 106, 107, 108, 109, 110, 111, 112 ],
       'findings = {101,102,105,106,107,108,109,110,111,112}', implode( ',', $found ) );
$reason = fn( int $u ) => $j['findings'][ $u ]['reason'] ?? '?';
$note( $reason( 101 ) === 'no_bridge' && $reason( 112 ) === 'no_bridge',
       'a hard-deleted customer (the 41-orphan shape) is reason no_bridge — the opinions-not-customers property' );
$note( $reason( 107 ) === 'customer_gone', 'a dangling bridge is customer_gone' );
$note( $reason( 106 ) === 'customer_deleted', 'a soft-deleted customer is customer_deleted' );
$note( $reason( 105 ) === 'no_entitlement', 'an asserted tier with no active entitlement is no_entitlement' );
$note( $reason( 108 ) === 'tier_mismatch' && $j['findings'][108]['justified'] === 'looth2',
       'an over-asserting opinion is tier_mismatch, carrying the entitled tier' );

// ------------------------------------------------ 3. the negatives
echo "\n[3] the negatives — a sweep that flags everything is a sweep nobody reads\n";
$note( ! in_array( 103, $found, true ), '103 NOT flagged — bridged live customer + active matching entitlement' );
$note( ! in_array( 104, $found, true ), '104 NOT flagged — a NULL opinion is the correct retracted state, not a finding' );
$note( in_array( 110, $found, true ), '110 (looth4) IS still detected — protection is the APPLY\'s job, not the detector\'s' );

// ------------------------------------------------ 4. detection never moves anyone
echo "\n[4] v1 detection NEVER moves a member\n";
$note( snapshot_rows() === $before, 'lg_role_sources byte-identical across the tick' );
$note( \LGMS\Arbiter::$calls === [], 'the Arbiter was never called' );
$note( $GLOBALS['AUTOLOAD'][ RetractionSweep::FINDINGS ] === false, 'findings journal is autoload=off' );
$note( (bool) preg_grep( '/retraction sweep: 10 unjustifiable stripe opinions \(10 new, 0 cleared\)/', $GLOBALS['LOG'] ),
       'the notification names the count and the newness', implode( ' | ', array_slice( $GLOBALS['LOG'], -2 ) ) );
$note( count( preg_grep( '/NEW unjustifiable stripe opinion/', $GLOBALS['LOG'] ) ) === 10,
       'one NEW line per finding' );

// ------------------------------------------------ 5. journal is stable, clearing is loud
echo "\n[5] across ticks: first_seen survives, a cleared finding says so\n";
$seen1 = $j['findings'][101]['first_seen'];
$GLOBALS['LOG'] = [];
RetractionSweep::tick();
$j2 = get_option( RetractionSweep::FINDINGS, null );
$note( $j2['findings'][101]['first_seen'] === $seen1, 'first_seen unchanged on the second tick' );
$note( (bool) preg_grep( '/\(0 new, 0 cleared\)/', $GLOBALS['LOG'] ), 'second tick reports 0 new' );

$GLOBALS['PDO']->exec( 'DELETE FROM lg_role_sources WHERE wp_user_id = 102 AND source = \'stripe\'' );
$GLOBALS['LOG'] = [];
RetractionSweep::tick();
$j3 = get_option( RetractionSweep::FINDINGS, null );
$note( ! isset( $j3['findings'][102] ) && (bool) preg_grep( '/finding CLEARED — user 102/', $GLOBALS['LOG'] ),
       'a retracted opinion clears its finding, loudly' );

// ------------------------------------------------ 6. apply: review + hold discipline
echo "\n[6] apply script — review writes nothing, member-visible is held\n";
scenario();
$before = snapshot_rows();
$out = run_apply();
$note( strpos( $out, 'REVIEW ONLY — nothing was written' ) !== false, 'review mode says so' );
$note( snapshot_rows() === $before && \LGMS\Arbiter::$calls === [], 'and writes nothing, calls no Arbiter' );
$note( preg_match( '/PLAN: 8 opinions to retract \(1 repair-first\), 2 HELD/', $out ) === 1,
       'plan: 8 actionable (1 repair-first), 2 held', $out ? ( preg_match( '/PLAN[^\n]*/', $out, $m ) ? $m[0] : '?' ) : '' );
$note( strpos( $out, 'Held: #105, #108' ) !== false, 'the held members are named' );
$note( strpos( $out, 'allow=all' ) === false, 'no bulk-release form exists' );

// ------------------------------------------------ 7. apply executes, held stays held
echo "\n[7] apply — retracts by reason, repairs first, journals BEFORE mutating\n";
$out = run_apply( [ 'apply' ] );
$batches = get_option( RSAP_INDEX_OPT, [] );
$note( count( $batches ) === 1, 'one journal batch recorded' );
$batch1 = $batches[0] ?? '';
$jj = get_option( RSAP_JOURNAL_PREFIX . $batch1, [] );
$note( count( $jj['entries'] ?? [] ) === 8, 'journal carries all 8 actioned rows', (string) count( $jj['entries'] ?? [] ) );
$note( row_tier( 101, 'stripe' ) === 'ABSENT' && row_tier( 102, 'stripe' ) === 'ABSENT'
       && row_tier( 107, 'stripe' ) === 'ABSENT' && row_tier( 112, 'stripe' ) === 'ABSENT',
       'no-subject rows (no_bridge / customer_gone) are DELETED' );
$note( row_tier( 106, 'stripe' ) === 'ABSENT', 'customer_deleted row is DELETED (deletion is for debris)' );
$note( row_tier( 109, 'stripe' ) === 'ABSENT' && row_tier( 109, 'patreon' ) === 'looth2',
       'REPAIR: patreon row healed NULL -> looth2 BEFORE the stripe row went' );
$note( row_tier( 105, 'stripe' ) === 'looth2' && row_tier( 108, 'stripe' ) === 'looth3',
       'HELD rows are untouched' );
$note( \LGMS\Arbiter::$calls === [], 'nothing actioned moved a member, so the Arbiter never ran' );

// ------------------------------------------------ 8. verify + per-member release
echo "\n[8] verify, then release exactly one member\n";
$out = run_apply( [ 'verify' ] );
$note( strpos( $out, 'VERIFY: 2 unjustifiable source=stripe opinions remain' ) !== false
       && strpos( $out, 'Clean: everything retractable without moving a member has been retracted' ) !== false,
       'verify: only the held two remain — clean' );

$out = run_apply( [ 'apply', 'allow=105' ] );
$note( row_tier( 105, 'stripe' ) === null, '105 released: opinion retracted to NULL, row kept (audit trail)' );
$note( row_tier( 108, 'stripe' ) === 'looth3', '108 still held — allow= releases exactly one member' );
$note( \LGMS\Arbiter::$calls === [ 105 ], 'the Arbiter ran for the released member only', implode( ',', \LGMS\Arbiter::$calls ) );
$note( ! isset( RetractionSweep::detect()[105] ), 'a retracted NULL opinion is JUSTIFIED on re-detection — the loop closes' );

// ------------------------------------------------ 9. revert restores byte-identically
echo "\n[9] revert — both batches, back to the exact starting rows\n";
$batches = get_option( RSAP_INDEX_OPT, [] );
run_apply( [ 'revert', $batches[1] ] );
$note( row_tier( 105, 'stripe' ) === 'looth2', 'batch 2 revert restores 105 to looth2' );
run_apply( [ 'revert', $batches[0] ] );
$note( snapshot_rows() === $before, 'after both reverts lg_role_sources is byte-identical to the start' );
$out = run_apply( [ 'revert', $batches[0] ] );
$note( strpos( $out, 'already reverted' ) !== false, 'a second revert of the same batch refuses' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — OFF is silence, detection is read-only, retraction is explicit, held means held, revert is exact.\n";
exit( 0 );

}
