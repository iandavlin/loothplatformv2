<?php
/**
 * RED-FIRST GATE for the identity gate (audit R1 — the email-keyed minter).
 *
 *   php test-identity-gate.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Asserts the flag PER STATE — absent / OFF / ON — read from the option rather
 * than hardcoded, so flipping the default needs no edit here. The state that
 * matters most is ABSENT, because that is live today: it must be a proven
 * byte-identical no-op, and "byte-identical" is asserted by exercising the
 * old mint path and requiring it to still mint.
 *
 * The flag also must not neuter the tests that cover the behaviour behind it:
 * every ON assertion is paired with an OFF one over the same input, so a bug
 * that made the gate never arm would show up as the two agreeing.
 */

declare(strict_types=1);

namespace LGMS {
    class Db { public static function pdo() { return $GLOBALS['PDO']; } }
    class Log { public static function line( string $m, string $n = 'tick.log' ): void { $GLOBALS['LOG'][] = rtrim( $m, "\n" ); } }
}

namespace {

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "CANNOT RUN: pdo_sqlite missing\n" ); exit( 3 ); }

$SRC = __DIR__ . '/../../src/Wp';
foreach ( [ 'IdentityMatcher.php', 'UserProvisioner.php' ] as $f ) {
    if ( ! is_readable( "$SRC/$f" ) ) { fwrite( STDERR, "CANNOT RUN: missing $f\n" ); exit( 3 ); }
}

$GLOBALS['USERS']   = [];   // id => ['email'=>, 'meta'=>[]]
$GLOBALS['OPTS']    = [];
$GLOBALS['OPTS'][ 'lgms_checkout_audience' ] = 'off';   // #181: this file's subject is not the audience
$GLOBALS['LOG']     = [];
$GLOBALS['MINTED']  = [];
$GLOBALS['NOTIFY']  = [];
$GLOBALS['NEXTID']  = 9000;

function get_user_by( $field, $v ) {
    foreach ( $GLOBALS['USERS'] as $id => $u ) {
        if ( $field === 'id'    && (int) $v === $id )                              { return (object) [ 'ID' => $id, 'user_email' => $u['email'], 'roles' => [] ]; }
        if ( $field === 'email' && strcasecmp( (string) $v, $u['email'] ) === 0 )   { return (object) [ 'ID' => $id, 'user_email' => $u['email'], 'roles' => [] ]; }
    }
    return false;
}
function get_users( array $a ) {
    $out = [];
    foreach ( $GLOBALS['USERS'] as $id => $u ) {
        if ( ( $u['meta'][ $a['meta_key'] ] ?? null ) === $a['meta_value'] ) { $out[] = $id; }
    }
    return $out;
}
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTS'] ) ? $GLOBALS['OPTS'][ $n ] : $d; }
function get_user_meta( $id, $k, $s = false ) { return $GLOBALS['USERS'][ $id ]['meta'][ $k ] ?? ''; }
function update_user_meta( $id, $k, $v ) { $GLOBALS['USERS'][ $id ]['meta'][ $k ] = $v; return true; }
function sanitize_user( $s, $strict = false ) { return preg_replace( '/[^a-z0-9_.\-]/i', '', (string) $s ); }
function username_exists( $u ) { foreach ( $GLOBALS['USERS'] as $x ) { if ( ( $x['login'] ?? '' ) === $u ) { return true; } } return false; }
function wp_generate_password( $l = 12, $a = true, $b = false ) { return str_repeat( 'p', (int) $l ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_insert_user( array $a ) {
    $id = ++$GLOBALS['NEXTID'];
    $GLOBALS['USERS'][ $id ] = [ 'email' => $a['user_email'], 'login' => $a['user_login'], 'meta' => [] ];
    $GLOBALS['MINTED'][] = $id;
    return $id;
}
function do_action( $h, ...$a ) {}
function lgpo_notify_failure( $e, $n, $ctx, $detail, $uid = 0 ) { $GLOBALS['NOTIFY'][] = $ctx; }
function lgpo_notify_onboard( ...$a ) {}
class WP_Error {}

require_once __DIR__ . '/../../src/Wp/IdentityMatcher.php';
// #181: findOrProvision now asks CheckoutAudience whether the buyer may be
// provisioned at all, and that option DEFAULTS TO `allowlist`. Pinned OFF
// because this file's subject is the IDENTITY gate — a different flag, one
// layer down. Gate 86 §C owns the audience fence, including its interaction
// with this one (it sits ABOVE the identity gate and below the bridge return).
require_once __DIR__ . '/../../src/Membership/CheckoutAudience.php';
require_once __DIR__ . '/../../src/Wp/UserProvisioner.php';

use LGMS\Wp\UserProvisioner;

/**
 * SQLite cannot parse MySQL's upsert, and — more importantly — MySQL's
 * `ON DUPLICATE KEY UPDATE` fires on ANY unique key, while SQLite's
 * `ON CONFLICT(target)` fires only on the named one. That asymmetry IS audit
 * R3: writeBridge's clause was written for the customer_id primary key, but
 * `uk_wp_user` is also unique, so a collision there updates the OTHER
 * customer's row and silently leaves this customer unbridged.
 *
 * So the shim emulates MySQL's documented behaviour on BOTH keys, including
 * the silent no-op. A shim that merely threw would make the OFF path look
 * safe for the wrong reason.
 */
class TestStmt extends PDOStatement {
    protected PDO $conn;
    protected function __construct( PDO $conn ) { $this->conn = $conn; }
    public function execute( $params = null ): bool {
        try {
            return parent::execute( $params );
        } catch ( PDOException $e ) {
            if ( strpos( $e->getMessage(), 'UNIQUE' ) !== false
              && strpos( $e->getMessage(), 'wp_user_bridge.wp_user_id' ) !== false ) {
                // MySQL would update the row that already owns this wp_user_id
                // and report success; this customer never gets a row.
                $this->conn->prepare( 'UPDATE wp_user_bridge SET synced_at = CURRENT_TIMESTAMP WHERE wp_user_id = ?' )
                           ->execute( [ $params[1] ?? null ] );
                return true;
            }
            throw $e;
        }
    }
}

final class TestPdo extends PDO {
    public function prepare( string $q, array $o = [] ): PDOStatement|false {
        if ( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false ) {
            $q = preg_replace( '/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(customer_id) DO UPDATE SET', $q );
            $q = preg_replace( '/VALUES\s*\(\s*(\w+)\s*\)/i', 'excluded.$1', $q );
        }
        $q = preg_replace( '/\bNOW\(\)/i', 'CURRENT_TIMESTAMP', $q );
        return parent::prepare( $q, $o );
    }
}

/** Fresh in-memory lg_membership + WP user table for one scenario. */
function scenario( array $users, array $customers, array $bridges = [], $flag = null ): void {
    $pdo = new TestPdo( 'sqlite::memory:' );
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $pdo->setAttribute( PDO::ATTR_STATEMENT_CLASS, [ TestStmt::class, [ $pdo ] ] );
    $pdo->exec( 'CREATE TABLE wp_user_bridge (customer_id INTEGER PRIMARY KEY, wp_user_id INTEGER UNIQUE, synced_at TEXT)' );
    $pdo->exec( 'CREATE TABLE customers (id INTEGER PRIMARY KEY, email TEXT, metadata TEXT)' );
    foreach ( $customers as $id => $c ) {
        $pdo->prepare( 'INSERT INTO customers (id,email,metadata) VALUES (?,?,?)' )
            ->execute( [ $id, $c['email'] ?? '', isset( $c['metadata'] ) ? json_encode( $c['metadata'] ) : null ] );
    }
    foreach ( $bridges as $cid => $uid ) {
        $pdo->prepare( 'INSERT INTO wp_user_bridge (customer_id,wp_user_id,synced_at) VALUES (?,?,?)' )->execute( [ $cid, $uid, 'x' ] );
    }
    $GLOBALS['PDO']    = $pdo;
    $GLOBALS['USERS']  = $users;
    $GLOBALS['MINTED'] = [];
    $GLOBALS['NOTIFY'] = [];
    $GLOBALS['LOG']    = [];
    $GLOBALS['OPTS']   = [];
    $GLOBALS['OPTS'][ 'lgms_checkout_audience' ] = 'off';   // #181: this file's subject is not the audience
    if ( $flag !== null ) { $GLOBALS['OPTS']['lgms_identity_gate'] = $flag; }
}

function bridged_user( int $cid ): ?int {
    $st = $GLOBALS['PDO']->prepare( 'SELECT wp_user_id FROM wp_user_bridge WHERE customer_id = ?' );
    $st->execute( [ $cid ] );
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $d ? "  ({$d})" : '' ); }
};

// The scenario that produced the duplicate accounts: a Stripe customer whose
// email does not match any WP account, because the platform rewrote the
// member's WP email to their current Patreon address.
$UNKNOWN_EMAIL = [
    'users'     => [ 501 => [ 'email' => 'member@patreon-address.com', 'login' => 'member', 'meta' => [ 'lgpo_patreon_user_id' => '99887766' ] ] ],
    'customers' => [ 7 => [ 'email' => 'member@icloud-relay.example', 'metadata' => null ] ],
];

echo "=== identity gate — red-first gate (audit R1) ===\n";

// ------------------------------------------------ 1. flag ABSENT = live today
echo "\n[1] flag ABSENT (live's current state) — must be a byte-identical no-op\n";
scenario( $UNKNOWN_EMAIL['users'], $UNKNOWN_EMAIL['customers'] );
$note( get_option( 'lgms_identity_gate', false ) === false, 'option is genuinely absent, not seeded' );
$note( UserProvisioner::identityGateOn() === false, 'gate reads OFF when the option is absent' );
$id = UserProvisioner::findOrProvision( 7, 'member@icloud-relay.example', 'A Member' );
$note( count( $GLOBALS['MINTED'] ) === 1 && $id === $GLOBALS['MINTED'][0],
       'the OLD behaviour is intact: an unmatched customer still MINTS' );
$note( bridged_user( 7 ) === $id, 'and the mint is bridged, exactly as before' );

// ------------------------------------------------ 2. flag OFF explicitly
echo "\n[2] flag explicitly OFF — same as absent\n";
scenario( $UNKNOWN_EMAIL['users'], $UNKNOWN_EMAIL['customers'], [], false );
$note( UserProvisioner::identityGateOn() === false, 'gate reads OFF' );
UserProvisioner::findOrProvision( 7, 'member@icloud-relay.example', 'A Member' );
$note( count( $GLOBALS['MINTED'] ) === 1, 'still mints (OFF must not differ from absent)' );

// ------------------------------------------------ 3. flag ON — THE FIX
echo "\n[3] flag ON — refuses to mint, loudly\n";
scenario( $UNKNOWN_EMAIL['users'], $UNKNOWN_EMAIL['customers'], [], true );
$note( UserProvisioner::identityGateOn() === true, 'gate reads ON' );
$threw = false; $msg = '';
try { UserProvisioner::findOrProvision( 7, 'member@icloud-relay.example', 'A Member' ); }
catch ( \RuntimeException $e ) { $threw = true; $msg = $e->getMessage(); }
$note( $threw, 'an unmatchable customer THROWS instead of minting' );
$note( $GLOBALS['MINTED'] === [], 'NOTHING was minted', implode( ',', $GLOBALS['MINTED'] ) );
$note( bridged_user( 7 ) === null, 'no bridge row was written' );
$note( in_array( 'stripe.identity_unmatched', $GLOBALS['NOTIFY'], true ), 'an operator is notified' );
$note( (bool) preg_grep( '/provision REFUSED/', $GLOBALS['LOG'] ), 'the refusal reaches the tick log' );
$note( strpos( $msg, 'no WP account carries this email' ) !== false,
       'the refusal explains WHY, so it is a 30-second fix not an investigation' );

// ------------------------------------------------ 4. ON still matches real members
echo "\n[4] flag ON — the chain still finds people, by something better than email\n";

// 4a. explicit bridge from checkout
scenario( $UNKNOWN_EMAIL['users'],
          [ 7 => [ 'email' => 'member@icloud-relay.example', 'metadata' => [ 'wp_user_id' => 501 ] ] ], [], true );
$note( UserProvisioner::findOrProvision( 7, 'member@icloud-relay.example', null ) === 501
       && $GLOBALS['MINTED'] === [], 'matched via customer.metadata.wp_user_id, no mint' );
$note( bridged_user( 7 ) === 501, 'and bridged' );

// 4b. stable patreon id — the case email CANNOT solve
scenario( $UNKNOWN_EMAIL['users'],
          [ 7 => [ 'email' => 'member@icloud-relay.example', 'metadata' => [ 'patreon_user_id' => '99887766' ] ] ], [], true );
$note( UserProvisioner::findOrProvision( 7, 'member@icloud-relay.example', null ) === 501
       && $GLOBALS['MINTED'] === [], 'matched via lgpo_patreon_user_id, no mint' );

// 4c. email still works when it IS right
scenario( [ 501 => [ 'email' => 'same@example.com', 'login' => 'm', 'meta' => [] ] ],
          [ 7 => [ 'email' => 'same@example.com' ] ], [], true );
$note( UserProvisioner::findOrProvision( 7, 'same@example.com', null ) === 501
       && $GLOBALS['MINTED'] === [], 'email still matches when it is genuinely the same person' );

// ------------------------------------------------ 5. R3 — the silent bridge theft
echo "\n[5] a WP user already bridged to ANOTHER customer is never stolen\n";
scenario( [ 501 => [ 'email' => 'shared@example.com', 'login' => 'm', 'meta' => [] ] ],
          [ 7 => [ 'email' => 'shared@example.com' ], 9 => [ 'email' => 'shared@example.com' ] ],
          [ 9 => 501 ], true );
$threw = false;
try { UserProvisioner::findOrProvision( 7, 'shared@example.com', null ); }
catch ( \RuntimeException $e ) { $threw = true; }
$note( $threw, 'customer 7 cannot claim a user bridged to customer 9' );
$note( bridged_user( 9 ) === 501, "customer 9's bridge is untouched" );
$note( bridged_user( 7 ) === null, 'customer 7 gets no bridge (the R3 silent no-op is now loud)' );

// paired OFF run over the SAME input, so the flag cannot be silently inert
scenario( [ 501 => [ 'email' => 'shared@example.com', 'login' => 'm', 'meta' => [] ] ],
          [ 7 => [ 'email' => 'shared@example.com' ], 9 => [ 'email' => 'shared@example.com' ] ],
          [ 9 => 501 ], false );
$off_threw = false;
try { UserProvisioner::findOrProvision( 7, 'shared@example.com', null ); }
catch ( \RuntimeException $e ) { $off_threw = true; }
$note( ! $off_threw, 'OFF over the SAME input does NOT throw — the two states really differ' );

// ------------------------------------------------ 6. already-bridged short-circuit
echo "\n[6] an existing bridge short-circuits in BOTH states\n";
foreach ( [ 'absent' => null, 'on' => true, 'off' => false ] as $label => $flag ) {
    scenario( [ 501 => [ 'email' => 'x@example.com', 'login' => 'm', 'meta' => [] ] ],
              [ 7 => [ 'email' => 'x@example.com' ] ], [ 7 => 501 ], $flag );
    $note( UserProvisioner::findOrProvision( 7, 'anything@else.com', null ) === 501 && $GLOBALS['MINTED'] === [],
           "flag {$label}: existing bridge wins without consulting email" );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — OFF is the old behaviour exactly; ON cannot mint a duplicate.\n";
exit( 0 );

}
