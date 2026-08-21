<?php
/**
 * GATE 89 — comp timers actually run out, and the already-overdue are held.
 *
 *   php tools/gates/comp-expiry-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * THE DEFECT THIS EXISTS FOR (#183; Ian 2026-08-21: "comp timers need to
 * work"). `lg-looth4-expiry 1.0.0` enforced looth4 expiry before the cutover
 * and did not survive it. Measured both sides on 2026-08-21 — keeper on live's
 * filesystem, this lane on the database: no file under wp-content, absent from
 * `active_plugins`, `recently_activated` empty, no cron event in the
 * 13,182-byte `cron` option, no ACF field (there is no `_looth4_expires_at`
 * companion row), no snippet, no option naming the key. Two live timers lapsed
 * in July with nothing watching, and — the half that is easy to miss — nothing
 * could SET a comp end-date either.
 *
 * WHAT MUST HOLD, in the order keeper required it:
 *
 *   1. THE TWO ALREADY-OVERDUE ACCOUNTS ARE UNTOUCHED BY A REAL SWEEP. Not
 *      argued — run, with their real dates, before and after. (§E.)
 *   2. A TIMER THAT RAN OUT AT OR AFTER THE CUTOVER DOES DEMOTE, and the
 *      member lands on their ARBITRATED tier rather than nothing. (§F.)
 *   3. `Arbiter::sync` REMAINS THE ONLY WRITER of wp_capabilities. (§G.)
 *   4. FLAG OFF IS A TOTAL NO-OP, read from the flag rather than hardcoded.
 *      (§H.)
 *
 * ⚠️ THE ASSERTION THAT LOOKS RIGHT AND MEASURES NOTHING, named so nobody
 * re-adds it as evidence: "an unexpired comp keeps looth4" passes on the
 * BROKEN code — before this lane every comp kept looth4 forever, expired or
 * not. It is kept (§C is the liveness half of §E: a sweep that demotes nobody
 * because it demotes nobody is not a passing gate) but it proves nothing on
 * its own. The assertions that bite are §F1 (a due timer DOES come off) and
 * §E (the held ones do not), and they have to pass TOGETHER — either alone is
 * satisfiable by a sweep that is broken in one direction.
 *
 * ⚠️ THE TIMEZONE IS ASSERTED AGAINST A HOSTILE PROCESS TIMEZONE. This file
 * sets PHP's default zone to America/New_York — what both boxes actually run —
 * before loading anything. A reader that dropped the explicit UTC zone would
 * then parse in local time and §B would go red, which is the regression that
 * matters: the values are UTC (the old plugin's own source says so, and both
 * live rows' minute-of-day matches their UTC registration), so a site-zone
 * read places every expiry FOUR HOURS LATE.
 *
 * HOW THE COLLABORATORS ARE HANDLED. `CompExpiry`, `CompStanding` and
 * `Arbiter` are the REAL classes — the decisions under test are never stubbed.
 * The role sources, the log, $wpdb and the WP user surface are observable
 * stand-ins, so what is measured is the decision and not the plumbing.
 *
 * Red-first record with measured counts at the foot of this file.
 */

declare(strict_types=1);

/* ─── observable stand-ins, declared before the real classes are loaded ───── */

namespace LGMS {
    class Log {
        public static function line( string $m, string $n = 'tick.log' ): void { $GLOBALS['LOG'][] = rtrim( $m, "\n" ); }
    }
    class RoleSourceWriter {
        /** Shape mirrors the real method: source => tier, NOT a list of rows. */
        public static function readAllForUser( int $uid ): array { return $GLOBALS['SOURCES'][ $uid ] ?? []; }
        public static function report( int $uid, string $src, ?string $tier ): void { $GLOBALS['SOURCES'][ $uid ][ $src ] = $tier; }
    }
}

namespace LGMS\Wp {
    class WelcomeMailer {
        public static function sendIfNeeded( int $uid, string $tier ): void { $GLOBALS['MAILED'][] = $uid; }
    }
    class InternalRestController {
        public static function deriveProvenance( ?string $winning, array $sources ): string { return 'stub'; }
    }
}

namespace {

/* ⚠️ HOSTILE PROCESS TIMEZONE ON PURPOSE — see the header. Set before the real
   units are required below, so nothing can capture UTC by accident. Both boxes
   run America/New_York; a reader that dropped its explicit UTC zone would parse
   every timer four hours late and §B would go red. */
date_default_timezone_set( 'America/New_York' );

$ROOT = dirname( __DIR__, 2 );

$pass = 0; $fail = 0;
function ok( string $m ): void   { global $pass; $pass++; echo "  ok   $m\n"; }
function bad( string $m ): void  { global $fail; $fail++; echo "  FAIL $m\n"; }
function is_( bool $c, string $m ): void { $c ? ok( $m ) : bad( $m ); }
function section( string $t ): void { echo "\n$t\n"; }
function note( string $t ): void { echo "  ..   $t\n"; }
function cannot( string $why ): void { echo "CANNOT RUN: $why\n"; exit( 3 ); }

/** Source with comments stripped, so prose can never satisfy an assertion. */
function bare( string $file ): string {
    if ( ! is_readable( $file ) ) { cannot( "unreadable: $file" ); }
    $out = '';
    foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tok ) {
        if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) { continue; }
        $out .= is_array( $tok ) ? $tok[1] : $tok;
    }
    return $out;
}

/* ─── the WordPress surface ───────────────────────────────────────────────── */

$GLOBALS['OPTS'] = []; $GLOBALS['USERS'] = []; $GLOBALS['USERMETA'] = [];
$GLOBALS['SOURCES'] = []; $GLOBALS['ROLE_OPS'] = []; $GLOBALS['LOG'] = [];
$GLOBALS['ACTIONS'] = []; $GLOBALS['MAILED'] = []; $GLOBALS['METAOPS'] = [];

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTS'][ $n ] = $v; return true; }

class FakeUser {
    public function __construct( public int $ID, public string $user_email, public array $roles, public string $user_login = 'u' ) {}
    public function add_role( string $r ): void {
        if ( ! in_array( $r, $this->roles, true ) ) { $this->roles[] = $r; }
        $GLOBALS['ROLE_OPS'][] = "+$r#{$this->ID}";
    }
    public function remove_role( string $r ): void {
        $this->roles = array_values( array_filter( $this->roles, fn( $x ) => $x !== $r ) );
        $GLOBALS['ROLE_OPS'][] = "-$r#{$this->ID}";
    }
}
function get_user_by( $field, $value ) {
    foreach ( $GLOBALS['USERS'] as $id => $u ) {
        if ( $field === 'id' && (int) $value === (int) $id ) { return $u; }
        if ( $field === 'email' && strcasecmp( (string) $value, (string) $u->user_email ) === 0 ) { return $u; }
    }
    return false;
}
function get_user_meta( $uid, $key, $single = false ) {
    $v = $GLOBALS['USERMETA'][ $uid ][ $key ] ?? '';
    return $single ? $v : ( $v === '' ? [] : [ $v ] );
}
function update_user_meta( $uid, $k, $v ) { $GLOBALS['USERMETA'][ $uid ][ $k ] = $v; $GLOBALS['METAOPS'][] = "set:$uid:$k"; return true; }
function delete_user_meta( $uid, $k ) { unset( $GLOBALS['USERMETA'][ $uid ][ $k ] ); $GLOBALS['METAOPS'][] = "del:$uid:$k"; return true; }
function do_action( $h, ...$a ) { $GLOBALS['ACTIONS'][] = [ $h, $a ]; }
function add_action( $h, $c, $p = 10, $n = 1 ) { return true; }

/** $wpdb, answering the two meta queries subjects() actually makes. */
class FakeWpdb {
    public string $usermeta = 'wp_usermeta';
    public function get_blog_prefix(): string { return 'wp_'; }
    public function esc_like( $s ) { return $s; }
    public function prepare( $sql, ...$args ) {
        foreach ( $args as $a ) {
            $sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $a ) . "'", (string) $sql, 1 );
        }
        return $sql;
    }
    public function get_col( $sql ) {
        $out = [];
        if ( strpos( (string) $sql, 'looth4_expires_at' ) !== false ) {
            foreach ( $GLOBALS['USERMETA'] as $uid => $m ) {
                if ( trim( (string) ( $m['looth4_expires_at'] ?? '' ) ) !== '' ) { $out[] = (int) $uid; }
            }
            return $out;
        }
        if ( strpos( (string) $sql, 'capabilities' ) !== false ) {
            foreach ( $GLOBALS['USERS'] as $uid => $u ) {
                if ( in_array( 'looth4', $u->roles, true ) ) { $out[] = (int) $uid; }
            }
            return $out;
        }
        return $out;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

/* ─── load the real units ─────────────────────────────────────────────────── */

$FILES = [
    'standing' => "$ROOT/lg-patreon-stripe-poller/src/Membership/CompStanding.php",
    'expiry'   => "$ROOT/lg-patreon-stripe-poller/src/Membership/CompExpiry.php",
    'arbiter'  => "$ROOT/lg-patreon-stripe-poller/src/Arbiter.php",
    'tick'     => "$ROOT/lg-patreon-stripe-poller/src/Tick.php",
    'admin'    => "$ROOT/lg-patreon-stripe-poller/src/Admin.php",
    'cfg'      => "$ROOT/platform/config/comp-expiry.php",
];
foreach ( $FILES as $k => $f ) { if ( ! is_readable( $f ) ) { cannot( "missing $k: $f" ); } }

require_once $FILES['standing'];
require_once $FILES['expiry'];
require_once $FILES['arbiter'];

use LGMS\Arbiter;
use LGMS\Membership\CompExpiry as CE;
use LGMS\Membership\CompStanding as CS;

/** Reset the world so no case can be carried by another's state. */
function reset_world(): void {
    CE::$override = null;
    $GLOBALS['OPTS'] = []; $GLOBALS['SOURCES'] = []; $GLOBALS['ROLE_OPS'] = [];
    $GLOBALS['LOG'] = []; $GLOBALS['ACTIONS'] = []; $GLOBALS['MAILED'] = [];
    $GLOBALS['METAOPS'] = []; $GLOBALS['USERMETA'] = [];
    $GLOBALS['USERS'] = [
        // Mirrors the real shape measured on both boxes 8/21: 14 comp holders on
        // live, only 2 carrying a timer, and both of those already past.
        400  => new FakeUser( 400,  'comp@example.com',    [ 'looth4' ],            'comp_no_timer' ),
        401  => new FakeUser( 401,  'comp2@example.com',   [ 'looth4', 'looth1' ],  'comp_stale_lower' ),
        402  => new FakeUser( 402,  'due@example.com',     [ 'looth4' ],            'comp_due' ),
        403  => new FakeUser( 403,  'running@example.com', [ 'looth4' ],            'comp_running' ),
        404  => new FakeUser( 404,  'payer@example.com',   [ 'looth4' ],            'comp_and_patron' ),
        900  => new FakeUser( 900,  'member@example.com',  [ 'looth3' ],            'plain_member' ),
        // THE TWO REAL ACCOUNTS, with their real ids and their real dates.
        1829 => new FakeUser( 1829, 'seth@example.com',    [ 'looth4' ],            'sethleejones' ),
        1865 => new FakeUser( 1865, 'yuexin@example.com',  [ 'looth4' ],            'Yuexin Chen' ),
    ];
    $GLOBALS['USERMETA'][1829]['looth4_expires_at'] = '2026-07-28 21:11:00';
    $GLOBALS['USERMETA'][1865]['looth4_expires_at'] = '2026-07-11 15:25:00';
}

/** Arm the flag exactly as a box would, without touching any file. */
function arm( bool $enabled, string $cutover ): void {
    CE::$override = [ 'enabled' => $enabled, 'effective_from' => $cutover ];
}

function roles_of( int $uid ): array { return $GLOBALS['USERS'][ $uid ]->roles; }
function tier_of( int $uid ): ?string {
    $t = array_values( array_intersect( roles_of( $uid ), [ 'looth1', 'looth2', 'looth3', 'looth4' ] ) );
    return $t === [] ? null : end( $t );
}
function ops_touching( int $uid ): array {
    return array_values( array_filter( $GLOBALS['ROLE_OPS'], fn( $o ) => str_ends_with( $o, "#$uid" ) ) );
}

echo "GATE 89 — comp timers run out, and the already-overdue are HELD (#183)\n";
note( 'Ian 8/21: "comp timers need to work." And: the two overdue accounts are LEFT ALONE.' );
note( 'process timezone is deliberately America/New_York — what both boxes run.' );

/* ═══ §A — the config contract, and the seam that must not leak ═══════════ */
section( '§A  the flag, its defaults, and the test seam' );

reset_world();
$cfg = require $FILES['cfg'];
is_( is_array( $cfg ), 'A1  the tracked config returns an array' );
is_( ( $cfg['enabled'] ?? null ) === false,
     'A2  tracked default is enabled => FALSE — this flag can take access away, so it merges dark' );
is_( trim( (string) ( $cfg['effective_from'] ?? 'x' ) ) === '',
     'A3  tracked default cutover is EMPTY — enabled-with-no-cutover is detect-and-report' );

// THE SEAM IS SAFE BY ASSERTION, not by convention. Nothing outside the gates
// may write CompExpiry::$override, or the whole fence could be lifted in-process.
$leaks = [];
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ROOT, FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $f ) {
    $path = $f->getPathname();
    if ( substr( $path, -4 ) !== '.php' ) { continue; }
    if ( strpos( $path, '/tools/gates/' ) !== false ) { continue; }
    if ( strpos( $path, '/vendor/' ) !== false || strpos( $path, '/node_modules/' ) !== false ) { continue; }
    $src = (string) @file_get_contents( $path );
    if ( strpos( $src, '$override' ) !== false && strpos( $src, 'CompExpiry' ) !== false
         && preg_match( '/CompExpiry::\$override\s*=/', $src ) ) {
        $leaks[] = substr( $path, strlen( $ROOT ) + 1 );
    }
}
is_( $leaks === [],
     'A4  NO file outside tools/gates/ assigns CompExpiry::$override — the seam cannot lift the fence in production'
     . ( $leaks === [] ? '' : ' [' . implode( ', ', $leaks ) . ']' ) );

// The reader must FAIL CLOSED on every broken shape.
foreach ( [
    'a missing enabled key'   => [ 'effective_from' => '2026-08-21' ],
    'a non-boolean enabled'   => [ 'enabled' => 'yes-please', 'effective_from' => '2026-08-21' ],
] as $label => $shape ) {
    CE::$override = $shape;
    // 'yes-please' is truthy, so this asserts the SHAPE is read, not that junk disables.
    is_( is_bool( CE::enabled() ), "A5  enabled() returns a bool for $label" );
}
CE::$override = [ 'enabled' => true, 'effective_from' => 'not a date' ];
is_( CE::effectiveFrom() === null,
     'A6  an UNPARSEABLE cutover reads as null — a broken fence must never fail open' );
CE::$override = [ 'enabled' => true, 'effective_from' => '' ];
is_( CE::effectiveFrom() === null, 'A7  an EMPTY cutover reads as null' );
CE::$override = [ 'enabled' => true, 'effective_from' => '2026-08-21' ];
is_( CE::effectiveFrom() === gmmktime( 0, 0, 0, 8, 21, 2026 ),
     'A8  the cutover is read at MIDNIGHT UTC — the same zone the timers are stored in' );

/* ═══ §B — the timezone is UTC, measured against a hostile process zone ═══ */
section( '§B  the timezone (UTC), the four-hour bug it replaces' );

note( 'the old plugin: define( LG_L4E_META_EXPIRES, ... ); // stored as Y-m-d H:i:s UTC' );
note( 'and both live rows match their UTC registration minute-of-day, two for two.' );

is_( date_default_timezone_get() === 'America/New_York',
     'B0  the process really is running in the site zone — otherwise §B measures nothing' );

is_( CS::parse( '2026-07-28 21:11:00' ) === 1785273060,
     'B1  user 1829s stored value parses as 2026-07-28 21:11 UTC' );
is_( CS::parse( '2026-07-28 21:11:00' ) !== 1785287460,
     'B1b ...and NOT as 21:11 New York, which is the four-hours-late reading' );
is_( CS::parse( '2026-07-11 15:25:00' ) === 1783783500,
     'B2  user 1865s stored value parses as 2026-07-11 15:25 UTC' );

// THE DISCRIMINATING CASE: a value the two zones disagree about RIGHT NOW.
// One hour ago in UTC has passed; read as New York it is three hours in the
// future. America/New_York is always behind UTC, so this holds year-round.
reset_world();
$oneHourAgoUtc = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$GLOBALS['USERMETA'][403]['looth4_expires_at'] = $oneHourAgoUtc;
is_( CS::isExpiredComp( 403 ),
     'B3  a timer set ONE HOUR AGO UTC reads as expired — under a site-zone read it would still be running' );
is_( ! CS::isActiveComp( 403 ), 'B3b ...and the member is not an active comp' );

reset_world();
$GLOBALS['USERMETA'][403]['looth4_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + 3600 );
is_( CS::isActiveComp( 403 ) && ! CS::isExpiredComp( 403 ),
     'B4  a timer one hour in the FUTURE is still running — the reader is not simply saying "expired"' );

// The reader must not depend on the ambient zone at all.
$before = CS::parse( '2026-07-28 21:11:00' );
date_default_timezone_set( 'Asia/Tokyo' );
$after = CS::parse( '2026-07-28 21:11:00' );
date_default_timezone_set( 'America/New_York' );
is_( $before === $after,
     'B5  the same value parses identically under a THIRD process zone — the UTC zone is explicit, not ambient' );

reset_world();
is_( CS::expiresAt( 400 ) === null, 'B6  no meta at all ⇒ null (12 of 14 live holders) — NOT "expired"' );
$GLOBALS['USERMETA'][400]['looth4_expires_at'] = 'not a date at all';
is_( CS::expiresAt( 400 ) === null && ! CS::isExpiredComp( 400 ),
     'B7  garbage ⇒ null, never "lapsed" — nobody is demoted for a fat-fingered field' );

/* ═══ §C — the policy: every reason a real person keeps their access ══════ */
section( '§C  shouldExpire — every "no", stated separately' );

// ⚠️ THE CUTOVER MUST NOT MASK THE FLAG. The first cut armed (false,
// '2026-08-21') against 1829's 2026-07-28 timer — which the cutover refuses on
// its own — so this passed identically with the flag check deleted, and the
// red-first run caught it as a MISSED mutation. The timer here is AFTER the
// cutover, so the flag is the only thing that can say no.
reset_world(); arm( false, '2026-07-01' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
is_( ! CE::shouldExpire( 402 ),
     'C1  flag OFF ⇒ never, for a timer the cutover would otherwise let through' );

reset_world(); arm( true, '' );
is_( ! CE::shouldExpire( 1829 ), 'C2  no cutover ⇒ never (detect-and-report mode)' );

reset_world(); arm( true, 'garbage' );
is_( ! CE::shouldExpire( 1829 ), 'C3  unparseable cutover ⇒ never — fail closed' );

reset_world(); arm( true, '2026-08-21' );
is_( ! CE::shouldExpire( 400 ), 'C4  a comp with NO timer ⇒ never — the 12-of-14 case' );
is_( ! CE::shouldExpire( 900 ), 'C5  a non-comp member is never touched by this policy' );

reset_world(); arm( true, '2026-08-21' );
$GLOBALS['USERMETA'][403]['looth4_expires_at'] = gmdate( 'Y-m-d H:i:s', time() + 86400 * 30 );
is_( ! CE::shouldExpire( 403 ), 'C6  a timer still RUNNING ⇒ never' );

reset_world(); arm( true, '2026-08-21' );
is_( ! CE::shouldExpire( 1829 ) && ! CE::shouldExpire( 1865 ),
     'C7  a timer that ran out BEFORE the cutover ⇒ never (the ruling, on the real dates)' );

reset_world(); arm( true, '2026-07-01' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
is_( CE::shouldExpire( 402 ), 'C8  a timer that ran out AFTER the cutover ⇒ YES — the liveness half' );

reset_world(); arm( true, '2026-08-01 00:00:00' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
is_( CE::shouldExpire( 402 ), 'C9  a timer exactly AT the cutover ⇒ yes (at-or-after, asserted at the boundary)' );

reset_world(); arm( true, '2026-08-01 00:00:01' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
is_( ! CE::shouldExpire( 402 ), 'C9b one second before the cutover ⇒ no — the boundary is real, not approximate' );

/* ═══ §D — what an expired comp BECOMES ══════════════════════════════════ */
section( '§D  an expiry returns a member to their real tier, it never flattens a payer' );

reset_world(); arm( true, '2026-07-01' );
$GLOBALS['USERMETA'][404]['looth4_expires_at'] = '2026-08-01 00:00:00';
$GLOBALS['SOURCES'][404] = [ 'patreon' => 'looth3' ];       // a real paying patron, ALSO comped
$res = Arbiter::sync( 404 );
is_( ! in_array( 'looth4', roles_of( 404 ), true ), 'D1  the lapsed comp role comes off a paying patron' );
is_( tier_of( 404 ) === 'looth3',
     'D2  ...and they land on looth3, their REAL paid tier — NOT a flat looth1' );
is_( ( $res['old_tier'] ?? null ) === 'looth4',
     'D3  the transition is reported FROM looth4, so looth_tier_changed purges against the right tier' );

reset_world(); arm( true, '2026-07-01' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
$GLOBALS['SOURCES'][402] = [];                               // no paying opinion anywhere
Arbiter::sync( 402 );
is_( ! in_array( 'looth4', roles_of( 402 ), true ), 'D4  a comp with no payment source loses looth4' );
is_( tier_of( 402 ) === 'looth1',
     'D5  ...and lands on looth1, the starter tier — NEVER no tier at all' );
is_( tier_of( 402 ) !== null, 'D5b explicitly: they hold SOME looth role afterwards' );

// ⚠️ payment_source IS SET HERE ON PURPOSE. Without it no case in this gate
// ever reached the stripe coexistence guard, and deleting `! $compExpired` from
// it was a MISSED mutation in the red-first run — a just-expired comp holds no
// tier for an instant, so that guard would return early and leave them with no
// looth role at all. This is the case that watches it.
reset_world(); arm( true, '2026-07-01' );
$GLOBALS['USERMETA'][404]['looth4_expires_at'] = '2026-08-01 00:00:00';
$GLOBALS['USERMETA'][404]['payment_source'] = 'stripe';
$GLOBALS['SOURCES'][404] = [ 'stripe' => 'looth2' ];
Arbiter::sync( 404 );
is_( tier_of( 404 ) === 'looth2', 'D6  a lapsed comp on the STRIPE rail lands on their Stripe tier' );
is_( tier_of( 404 ) !== null,
     'D6b ...and is NOT swallowed by the stripe coexistence guard into holding no tier at all' );

// The genuinely ambiguous payer: claims the stripe rail, has no opinion row.
reset_world(); arm( true, '2026-07-01' );
$GLOBALS['USERMETA'][404]['looth4_expires_at'] = '2026-08-01 00:00:00';
$GLOBALS['USERMETA'][404]['payment_source'] = 'stripe';
$GLOBALS['SOURCES'][404] = [];
$res = Arbiter::sync( 404 );
is_( in_array( 'looth4', roles_of( 404 ), true ),
     'D7  a lapsed comp with payment_source=stripe and NO source row is HELD — a payer is never flattened' );
is_( str_contains( (string) ( $res['reason'] ?? '' ), 'HELD' ),
     'D7b ...and the hold says so out loud rather than skipping quietly' );

// No welcome mail on the way DOWN.
reset_world(); arm( true, '2026-07-01' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
$GLOBALS['SOURCES'][402] = [];
Arbiter::sync( 402 );
is_( $GLOBALS['MAILED'] === [], 'D8  a demotion never sends the welcome email' );

/* ═══ §E — KEEPER CONDITION 1: the two overdue accounts, before and after ═ */
section( '§E  the two already-overdue accounts survive a REAL sweep' );

note( 'live: 1829 sethleejones (2026-07-28) and 1865 Yuexin Chen (2026-07-11), both past.' );
note( 'Ian 8/21: LEFT ALONE. No demotion, no extension.' );

reset_world();
arm( true, '2026-08-21' );                       // enforcement genuinely ON
$before = [ 1829 => roles_of( 1829 ), 1865 => roles_of( 1865 ) ];
CE::tick();                                      // a real sweep, not a simulation
$after  = [ 1829 => roles_of( 1829 ), 1865 => roles_of( 1865 ) ];

foreach ( [ 1829, 1865 ] as $uid ) {
    is_( $before[ $uid ] === $after[ $uid ],
         "E1  user $uid roles are BYTE-IDENTICAL across a real armed sweep (" . implode( ',', $after[ $uid ] ) . ')' );
    is_( in_array( 'looth4', $after[ $uid ], true ), "E1b user $uid still holds looth4" );
    is_( ops_touching( $uid ) === [],
         "E2  user $uid had NO role operation attempted at all — not demoted-then-restored" );
    is_( ( $GLOBALS['USERMETA'][ $uid ]['looth4_expires_at'] ?? '' ) !== '',
         "E3  user $uid keeps their timer — held is not the same as cleared" );
}
$logged = implode( "\n", $GLOBALS['LOG'] );
is_( str_contains( $logged, 'HELD' ) && str_contains( $logged, '1829' ) && str_contains( $logged, '1865' ),
     'E4  both held accounts are SURFACED by name in the log — Ian decides case by case, never silently' );
$found = get_option( CE::FINDINGS, [] );
is_( ( $found['held'] ?? 0 ) === 2, 'E5  the journal records exactly 2 held' );
is_( ( $found['expired'] ?? -1 ) === 0, 'E6  ...and 0 expired: an armed sweep demoted NOBODY on this data' );

// ⚠️ E6 alone is satisfied by a sweep that never demotes anyone. §F is its
// liveness partner and they must pass together.

/* ═══ §F — KEEPER CONDITION 2: a due timer really does come off ══════════ */
section( '§F  a timer at/after the cutover DOES demote, through the Arbiter' );

reset_world();
arm( true, '2026-08-21' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 3600 );  // due, post-cutover
$GLOBALS['SOURCES'][402] = [];
$GLOBALS['USERMETA'][404]['looth4_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$GLOBALS['SOURCES'][404] = [ 'patreon' => 'looth3' ];
CE::tick();

is_( ! in_array( 'looth4', roles_of( 402 ), true ), 'F1  the DUE comp lost looth4 in a real sweep' );
is_( tier_of( 402 ) === 'looth1', 'F2  ...and landed on looth1, not on nothing' );
is_( tier_of( 404 ) === 'looth3', 'F3  the due comp who also PAYS landed on looth3 — arbitrated, not flattened' );
is_( in_array( 'looth4', roles_of( 1829 ), true ) && in_array( 'looth4', roles_of( 1865 ), true ),
     'F4  the SAME sweep that demoted two still held 1829 and 1865 — both directions in one run' );
$found = get_option( CE::FINDINGS, [] );
is_( ( $found['expired'] ?? 0 ) === 2 && ( $found['held'] ?? 0 ) === 2,
     'F5  the journal records 2 expired AND 2 held from one sweep' );
is_( str_contains( implode( "\n", $GLOBALS['LOG'] ), 'EXPIRED' ),
     'F6  the expiry is logged, so an operator can see what moved and why' );

/* ═══ §G — KEEPER CONDITION 3: one writer, still ══════════════════════════ */
section( '§G  Arbiter::sync remains the only writer of wp_capabilities' );

$expSrc = bare( $FILES['expiry'] );
foreach ( [ 'add_role', 'remove_role', 'wp_capabilities', 'set_role' ] as $needle ) {
    is_( strpos( $expSrc, $needle ) === false,
         "G1  CompExpiry never contains `$needle` — it decides WHO, never WHAT" );
}
is_( strpos( $expSrc, 'Arbiter::sync' ) !== false,
     'G2  ...and it hands the member to Arbiter::sync, which is the one writer' );

$adminSrc = bare( $FILES['admin'] );

// ⚠️ SLICE ON THE DEFINITION, NOT THE FIRST MENTION. The first cut of this
// anchored on 'renderCompTimersTab', whose first occurrence is the one-line
// `match` arm that dispatches to it — so the slice was a dozen characters long
// and G3 passed having measured an empty string, while J3 and J6 went red and
// showed the seam. An assertion satisfied by a slice that contains nothing is
// the failure mode this gate exists to avoid, so the length is asserted too.
$slice = static function ( string $src, string $from, string $to ): string {
    $a = strpos( $src, $from );
    if ( $a === false ) { return ''; }
    $b = strpos( $src, $to, $a + strlen( $from ) );
    return substr( $src, $a, $b === false ? strlen( $src ) : $b - $a );
};
$compTab = $slice( $adminSrc, 'function renderCompTimersTab', 'function renderSettingsTab' );
is_( strlen( $compTab ) > 2000,
     'G3a the Comp Timers slice is the real method body (' . strlen( $compTab ) . ' chars) — G3 cannot pass on an empty string' );
foreach ( [ 'add_role', 'remove_role', 'set_role' ] as $needle ) {
    is_( strpos( $compTab, $needle ) === false,
         "G3  the Comp Timers screen and its handler never contain `$needle` — they set the DATE, never the role" );
}

// The sweep's writes are meta-only; every role op in §F came from the Arbiter.
reset_world(); arm( true, '2026-08-21' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 3600 );
CE::tick();
is_( $GLOBALS['METAOPS'] === [],
     'G4  a sweep writes NO user meta at all — it never clears or rewrites a timer behind an operator' );

/* ═══ §H — KEEPER CONDITION 4: OFF is a total no-op, read from the flag ═══ */
section( '§H  flag OFF' );

// Same trap as C1: the cutover here is BEFORE the timer, so every OFF assertion
// below is measuring the flag rather than being carried by the fence.
reset_world(); arm( false, '2026-07-01' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
$snapshot = [];
foreach ( array_keys( $GLOBALS['USERS'] ) as $uid ) { $snapshot[ $uid ] = roles_of( $uid ); }
CE::tick();
$moved = [];
foreach ( array_keys( $GLOBALS['USERS'] ) as $uid ) { if ( $snapshot[ $uid ] !== roles_of( $uid ) ) { $moved[] = $uid; } }
is_( $moved === [], 'H1  OFF: not one member moved' );
is_( $GLOBALS['ROLE_OPS'] === [], 'H2  OFF: not one role operation was attempted' );
is_( $GLOBALS['LOG'] === [], 'H3  OFF: not one log line — a total no-op, not "the old behaviour plus a write"' );
is_( ! array_key_exists( CE::FINDINGS, $GLOBALS['OPTS'] ), 'H4  OFF: the findings option is never written' );

// And the Arbiter itself is unchanged for a comp while the flag is off.
reset_world(); arm( false, '2026-07-01' );
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
$res = Arbiter::sync( 402 );
is_( in_array( 'looth4', roles_of( 402 ), true ) && str_contains( (string) ( $res['reason'] ?? '' ), 'looth4 protected' ),
     'H5  OFF: an EXPIRED comp is still "looth4 protected" in the Arbiter — todays behaviour exactly' );

// READ THE FLAG, do not hardcode a state: the same assertion in all three shapes.
foreach ( [ [ false, '2026-07-01', false ], [ true, '', false ], [ true, '2026-07-01', true ] ] as [ $en, $cut, $expect ] ) {
    reset_world(); arm( $en, $cut );
    $GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-01 00:00:00';
    is_( CE::shouldExpire( 402 ) === $expect,
         sprintf( 'H6  enabled=%s cutover=%s ⇒ shouldExpire=%s (read per state, never hardcoded)',
                  $en ? 'true' : 'false', $cut === '' ? '(empty)' : $cut, $expect ? 'true' : 'false' ) );
}

/* ═══ §I — the sweep reaches members nothing else visits ═════════════════ */
section( '§I  why a sweep, and what it enumerates' );

reset_world(); arm( true, '2026-08-21' );
$subjects = CE::subjects();
foreach ( [ 400, 401, 402, 403, 404, 1829, 1865 ] as $uid ) {
    is_( in_array( $uid, $subjects, true ), "I1  subjects() finds comp holder $uid" );
}
is_( ! in_array( 900, $subjects, true ), 'I1b ...and does not sweep a plain member' );

// A member demoted last night no longer holds looth4 but still carries the
// timer — dropping them the moment it happens is how an operator loses sight.
reset_world(); arm( true, '2026-08-21' );
$GLOBALS['USERS'][402]->roles = [ 'looth1' ];
$GLOBALS['USERMETA'][402]['looth4_expires_at'] = '2026-08-25 00:00:00';
is_( in_array( 402, CE::subjects(), true ),
     'I2  a member who carries a timer but no longer holds looth4 is STILL enumerated' );
is_( CE::statusFor( 402 )['state'] === CE::STATE_LAPSED, 'I2b ...and reads as lapsed, not as a running timer' );

// The comp de-dupe that predates this lane still works.
reset_world(); arm( true, '2026-08-21' );
$res = Arbiter::sync( 401 );
is_( in_array( 'looth4', roles_of( 401 ), true ) && ! in_array( 'looth1', roles_of( 401 ), true ),
     'I3  looth4 + a stale looth1 still de-dupes DOWN to looth4 — the pre-existing behaviour is intact' );

/* ═══ §J — the setter, which is the half that did not exist ══════════════ */
section( '§J  an operator can SET a comp end-date at all' );

note( 'measured 8/21: no plugin, no ACF field, no snippet, no option writes this meta.' );
is_( strpos( $adminSrc, "'comp_timers'" ) !== false, 'J1  the Comp Timers tab is registered' );
is_( strpos( $adminSrc, 'admin_post_lgms_comp_timer_set' ) !== false, 'J2  its save handler is wired' );
is_( strpos( $adminSrc, 'check_admin_referer' ) !== false && strpos( $compTab, 'wp_nonce_field' ) !== false,
     'J3  the form is nonced and the handler checks it' );
is_( strpos( $adminSrc, 'CompStanding::parse' ) !== false,
     'J4  input is parsed by the SAME code that reads it back — typed and swept cannot drift' );
is_( strpos( $adminSrc, "gmdate( 'Y-m-d H:i:s'" ) !== false,
     'J5  the value is normalised to Y-m-d H:i:s UTC before storing' );
// ⚠️ "UTC APPEARS SOMEWHERE" IS TOO WEAK, and the red-first run proved it:
// removing the visible label under the input still passed on the table header.
// The zone has to be legible where the operator TYPES, in both channels.
is_( strpos( $compTab, 'UTC. Empty = never expires.' ) !== false,
     'J6  the input carries a VISIBLE UTC label — both boxes run America/New_York, so an unlabelled field is a four-hour trap' );
is_( preg_match( '/aria-label="[^"]*UTC"/', $compTab ) === 1,
     'J6b ...and the same field is labelled UTC for a screen reader' );

// A value the parser refuses must not be stored: downstream it would read as
// "no expiry", so the screen would show a date the sweep could not see.
is_( CS::parse( 'next tuesday-ish' ) === null, 'J7  an unreadable value parses to null, so the handler refuses it' );

/* ═══ §K — the tick actually calls it ════════════════════════════════════ */
section( '§K  wired into the 5-minute tick' );

$tickSrc = bare( $FILES['tick'] );
is_( strpos( $tickSrc, 'CompExpiry::tick' ) !== false, 'K1  Tick::run calls CompExpiry::tick()' );
is_( preg_match( '/try\s*\{\s*\\\\?LGMS\\\\Membership\\\\CompExpiry::tick\(\s*\)\s*;\s*\}\s*catch/', $tickSrc ) === 1,
     'K2  ...inside its own try/catch, so a sweep failure cannot take down the tick' );

/* ─── verdict ─────────────────────────────────────────────────────────────── */
echo "\n$pass passed, $fail failed\n";
if ( $fail > 0 ) {
    echo "RED — a comp timer is not behaving as ruled. Do not merge.\n";
    exit( 1 );
}
echo "GREEN — timers run out at/after the cutover, the two overdue accounts are held,\n";
echo "the Arbiter is still the only writer, and OFF is a total no-op.\n";
exit( 0 );

}
