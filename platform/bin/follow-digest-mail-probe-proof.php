<?php
/**
 * follow-digest-mail-probe-proof.php — prove the CHANNEL PROBE tells the two apart.
 *
 *   sudo -u looth-dev env LG_FOLLOW_DIGEST=1 LG_FD_MU_DIR=/tmp/lg-fd-gate-mu \
 *        php platform/bin/follow-digest-mail-probe-proof.php
 *
 * ── THE CLAIM UNDER TEST ─────────────────────────────────────────────────────
 * The live one-shot used to refuse whenever ANY pre_wp_mail filter was registered.
 * lg-poller-mail-killswitch.php is always registered on live, so that guard could never
 * let a send through — while being no protection at all against the thing that actually
 * matters. It was replaced by a behavioural probe (platform/lib/lg-fd-mail-probe.php).
 *
 * A probe is only worth having if it gives DIFFERENT answers to the two cases, so this
 * asserts both directions against the REAL plugins on this box:
 *
 *   A  containment registered (dev2's true state)  ⇒  SWALLOWED, one-shot refuses
 *   B  killswitch only (LIVE's true state)         ⇒  CLEAR, one-shot may proceed
 *   C  an address that is not on the allowlist     ⇒  refused before anything is sent
 *
 * B is the one that had to be engineered, because dev2 always has containment. It is
 * simulated by removing containment's callback FROM THIS PROCESS ONLY — no file is
 * touched, nothing persists, and the process exits moments later.
 *
 * ⚠️ AND THAT SIMULATION IS THE DANGEROUS PART, SO READ THE BELTS ────────────
 * Removing containment means a "clear" verdict is followed by wp_mail() genuinely
 * trying to deliver. Four independent things make that harmless, and the run asserts
 * the first two rather than trusting them:
 *
 *   1. THE ADDRESS CANNOT EXIST. RFC 2606 reserves .invalid; it can never resolve, so
 *      even a total escape reaches no person and no real mailbox.
 *   2. THE ALLOWLIST admits only that address for this run, so the probe itself would
 *      refuse any other — the wall is not lowered to run the test.
 *   3. PHPMailer is repointed at mailpit (127.0.0.1:1025) BEFORE containment is removed,
 *      so the transport is local even if something did try to send.
 *   4. FLUENTMAIL_SIMULATE_EMAILS is already defined by containment at load time, and a
 *      constant cannot be undefined — so FluentSMTP stays in simulate mode regardless.
 *
 * And it refuses to run anywhere but dev2, checked by home_url() rather than LG_ENV —
 * live's /etc/looth/env says LG_ENV=dev2, so LG_ENV cannot tell the boxes apart.
 */

declare( strict_types=1 );

/** RFC 2606: .invalid is guaranteed never to resolve. */
const PROBE_EMAIL = 'follow-digest-probe@dev2.invalid';
const PROBE_UID   = 424242;          // never a real WP user; lg_fd_allowed() needs no row

$mu = (string) ( getenv( 'LG_FD_MU_DIR' ) ?: '' );
if ( '' !== $mu ) {
	if ( ! is_dir( $mu ) ) {
		fwrite( STDERR, "LG_FD_MU_DIR=$mu is not a directory. Build it with:\n"
			. "  python3 tools/gates/follow-digest-gate.py --plugin platform/mu-plugins/lg-follow-digest.php\n" );
		exit( 64 );
	}
	define( 'WPMU_PLUGIN_DIR', $mu );
}

/* The allowlist for this run admits ONLY the unreachable probe address. Set before WP
 * boots so the sender reads it, and so nothing in this file can mail a real person. */
putenv( 'LG_FOLLOW_DIGEST_ALLOWLIST=' . PROBE_UID . ':' . PROBE_EMAIL );
$_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] = PROBE_UID . ':' . PROBE_EMAIL;

$wp_load = '/var/www/dev/wp-load.php';
if ( ! is_readable( $wp_load ) ) { fwrite( STDERR, "cannot read $wp_load (run as looth-dev)\n" ); exit( 65 ); }
define( 'WP_USE_THEMES', false );
$pd = ini_get( 'display_errors' );
ini_set( 'display_errors', '0' );
require_once $wp_load;
ini_set( 'display_errors', (string) $pd );

$FAIL = array();
$OK   = array();
function ok( string $s ): void  { global $OK;   $OK[] = $s;   printf( "  ok   %s\n", $s ); }
function bad( string $s ): void { global $FAIL; $FAIL[] = $s; printf( "  FAIL %s\n", $s ); }
function say( string $s ): void { printf( "%s\n", $s ); }

say( "=== follow-digest mail-probe proof — the probe must tell containment from the killswitch ===\n" );

// ── GUARDS ───────────────────────────────────────────────────────────────────
$whoami = function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( posix_geteuid() )['name'] ?? '?' ) : '?';
if ( 'root' === $whoami ) { fwrite( STDERR, "refusing to run as root\n" ); exit( 73 ); }

$home = home_url();
if ( false === strpos( $home, 'dev2.' ) ) {
	fwrite( STDERR, "refusing: home_url() is $home, not dev2. This file REMOVES MAIL CONTAINMENT\n"
		. "from its own process and must never do that anywhere else.\n"
		. "⚠️ Do not 'fix' this with LG_ENV — live's /etc/looth/env says LG_ENV=dev2.\n" );
	exit( 74 );
}
ok( "box is dev2 by home_url() — $home" );

$probe_lib = dirname( __DIR__ ) . '/lib/lg-fd-mail-probe.php';
if ( ! is_readable( $probe_lib ) ) { fwrite( STDERR, "cannot read $probe_lib\n" ); exit( 65 ); }
require_once $probe_lib;
if ( ! function_exists( 'lg_fd_allowed' ) ) {
	fwrite( STDERR, "refusing: lg_fd_allowed() is not loaded — you are testing a build with no\n"
		. "allowlist. Pass LG_FD_MU_DIR=/tmp/lg-fd-gate-mu after building the harness.\n" );
	exit( 77 );
}
ok( 'the probe library and the allowlist are both loaded' );

/** Classify the registered pre_wp_mail callbacks by the file they came from. */
function chain_files(): array {
	$out = array();
	foreach ( lg_fd_mail_probe_filters() as $f ) { $out[] = basename( (string) $f['file'] ); }
	return $out;
}

$files = chain_files();
say( '       pre_wp_mail chain: ' . ( $files ? implode( ', ', $files ) : '(empty)' ) );

$has_containment = (bool) array_filter( $files, static fn( $f ) => false !== strpos( $f, 'lg-dev-mail-containment' ) );
$has_killswitch  = (bool) array_filter( $files, static fn( $f ) => false !== strpos( $f, 'lg-poller-mail-killswitch' ) );

/* ⚠️ LIVENESS BEFORE EITHER SCENARIO. If containment is not actually registered,
 * scenario A would "pass" against an empty chain and prove nothing; if the killswitch
 * is not registered, scenario B would be testing that no filters means no swallow —
 * true, useless, and NOT what live looks like. Both are asserted, not assumed. */
if ( $has_containment ) { ok( 'lg-dev-mail-containment IS registered — scenario A has something real to detect' ); }
else { bad( 'lg-dev-mail-containment is NOT registered; scenario A would pass vacuously' ); }
if ( $has_killswitch ) { ok( 'lg-poller-mail-killswitch IS registered — scenario B reproduces live\'s real chain' ); }
else { bad( 'lg-poller-mail-killswitch is NOT registered; scenario B would not reproduce live' ); }

// ─────────────────────────────────────────────────────────────────────────────
// C — THE WALL. Do this first: if the probe would mail a non-allowlisted address,
// nothing else in this file should run.
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C: the probe is itself behind the allowlist ---" );
$stranger = lg_fd_mail_probe( 777001, 'someone-else@dev2.invalid' );
if ( ! $stranger['clear'] && '' === $stranger['token'] ) {
	ok( 'a NON-allowlisted address is refused before a token is even generated — the probe '
		. 'cannot become a way around the wall it exists to inspect' );
} else {
	bad( 'the probe accepted a non-allowlisted address — it is a hole in the allowlist' );
}

// ─────────────────────────────────────────────────────────────────────────────
// A — REAL CONTAINMENT. The probe must report SWALLOWED.
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- A: with dev2's real containment registered ---" );
$a = lg_fd_mail_probe( PROBE_UID, PROBE_EMAIL );
say( sprintf( '       ran=%s swallowed=%s wp_mail_returned=%s',
	$a['ran'] ? 'yes' : 'no', $a['swallowed'] ? 'yes' : 'no', var_export( $a['wp_mail_returned'], true ) ) );
if ( $a['ran'] && $a['swallowed'] && ! $a['clear'] ) {
	ok( 'CONTAINMENT IS DETECTED: the probe was short-circuited, so the one-shot REFUSES. '
		. 'Note wp_mail() returned ' . var_export( $a['wp_mail_returned'], true )
		. ' — which is exactly why returning true is not evidence of delivery.' );
} else {
	bad( sprintf( 'containment was NOT detected (ran=%s swallowed=%s clear=%s) — the guard would '
		. 'have let a swallowed send be reported as delivered',
		var_export( $a['ran'], true ), var_export( $a['swallowed'], true ), var_export( $a['clear'], true ) ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// B — LIVE'S CHAIN: the killswitch alone. The probe must report CLEAR.
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- B: killswitch only (live's real chain), containment removed IN-PROCESS ---" );

/* Belt 3, installed BEFORE containment is removed: any actual delivery attempt goes to
 * the local mailpit, not to SES. Ordering matters — with containment gone there is no
 * second chance to install it. */
add_action( 'phpmailer_init', static function ( $m ) {
	$m->isSMTP();
	$m->Host        = '127.0.0.1';
	$m->Port        = 1025;
	$m->SMTPAuth    = false;
	$m->SMTPAutoTLS = false;
	$m->Timeout     = 5;
}, PHP_INT_MAX );

/* Collect first, remove second — mutating wp_filter while iterating it is how a
 * "removed" callback survives and the scenario silently tests the wrong chain. */
$to_remove = array();
$reg = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
if ( $reg && isset( $reg->callbacks ) ) {
	foreach ( (array) $reg->callbacks as $prio => $cbs ) {
		foreach ( (array) $cbs as $cb ) {
			$fn = $cb['function'] ?? null;
			if ( ! ( $fn instanceof Closure ) ) { continue; }
			try {
				$file = (string) ( new ReflectionFunction( $fn ) )->getFileName();
			} catch ( Throwable $e ) { continue; }   // phpcs:ignore
			if ( false !== strpos( $file, 'lg-dev-mail-containment' ) ) {
				$to_remove[] = array( $fn, (int) $prio );
			}
		}
	}
}
foreach ( $to_remove as $r ) { remove_filter( 'pre_wp_mail', $r[0], $r[1] ); }

$files_b = chain_files();
say( '       pre_wp_mail chain now: ' . ( $files_b ? implode( ', ', $files_b ) : '(empty)' ) );
$still_contained = (bool) array_filter( $files_b, static fn( $f ) => false !== strpos( $f, 'lg-dev-mail-containment' ) );
$still_kill      = (bool) array_filter( $files_b, static fn( $f ) => false !== strpos( $f, 'lg-poller-mail-killswitch' ) );

if ( $still_contained ) {
	bad( 'containment could NOT be removed from this process — scenario B cannot be run, and '
		. 'a "swallowed" result below would be meaningless' );
} elseif ( ! $still_kill ) {
	bad( 'the killswitch vanished along with containment — scenario B would be testing an EMPTY '
		. 'chain, which is not live\'s configuration and proves nothing about the killswitch' );
} else {
	ok( 'containment removed, killswitch STILL REGISTERED — this process now has exactly '
		. 'live\'s pre_wp_mail chain' );

	$b = lg_fd_mail_probe( PROBE_UID, PROBE_EMAIL );
	say( sprintf( '       ran=%s swallowed=%s wp_mail_returned=%s',
		$b['ran'] ? 'yes' : 'no', $b['swallowed'] ? 'yes' : 'no', var_export( $b['wp_mail_returned'], true ) ) );
	if ( $b['ran'] && ! $b['swallowed'] && $b['clear'] ) {
		ok( 'THE KILLSWITCH DOES NOT SWALLOW A DIGEST SEND: the probe passed the whole chain. '
			. 'The one-shot may proceed on live — which the old has_filter() guard made impossible.' );
	} else {
		bad( sprintf( 'the probe reported NOT CLEAR with only the killswitch registered (%s). '
			. 'The one-shot would still be unable to send on live.', $b['reason'] ) );
	}
}

say( '' );
say( 'Nothing was persisted: containment was removed from this PROCESS only, no file was' );
say( 'touched, and the probe address (.invalid) can never resolve.' );
say( '' );
if ( $FAIL ) {
	printf( "############ MAIL-PROBE PROOF FAILED — %d finding(s) ############\n", count( $FAIL ) );
	exit( 1 );
}
printf( "############ MAIL-PROBE PROOF PASSED — %d assertion(s) ############\n", count( $OK ) );
say( 'The probe refuses on real containment and passes the poller killswitch, so the live' );
say( 'one-shot can distinguish "a filter exists" from "my mail dies".' );
exit( 0 );
