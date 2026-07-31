<?php
/**
 * follow-digest-live-oneshot.php — Ian's controlled live test. ONE recipient: himself.
 *
 * ⚠️ THIS FILE SENDS REAL EMAIL FROM LIVE. There is no containment on live and there
 * is no undo. Every guard below exists because something specific would go wrong
 * without it; none of them are ceremony.
 *
 *   STEP 1  see what would happen — sends NOTHING:
 *     cd ~/loothplatformv2-clean && sudo -u looth-dev env LG_FOLLOW_DIGEST=1 \
 *       php platform/bin/follow-digest-live-oneshot.php
 *
 *   STEP 2  prove the mail channel is not swallowing anything — sends ONE probe
 *           email to you and nothing else:
 *     ... php platform/bin/follow-digest-live-oneshot.php --probe
 *
 *   STEP 3  give yourself a window (sets YOUR cadence + watermark, nobody else's):
 *     ... php platform/bin/follow-digest-live-oneshot.php --arm --since=2026-07-20
 *
 *   STEP 4  send it, for real, to you (re-probes first, then sends):
 *     ... php platform/bin/follow-digest-live-oneshot.php --send
 *
 *   STEP 5  put your account back exactly as it was:
 *     ... php platform/bin/follow-digest-live-oneshot.php --disarm
 *
 * ── WHY LG_FOLLOW_DIGEST=1 IS ON THE COMMAND LINE AND NOT IN THE TRACKED CONFIG ──
 * platform/config/follow-digest.php ships `'enabled' => false`, and this test does NOT
 * change that. Putting the flag on this one command means it is on for THIS PROCESS
 * and nothing else:
 *
 *   - no cron event is armed on live (the hourly sender stays unscheduled);
 *   - php-fpm never sees the flag, so no member's page changes in any way;
 *   - nothing persists — when the process exits, live is exactly as it was.
 *
 * The alternative (flipping 'enabled' in the tracked config) arms the real hourly
 * schedule for everybody. That is a bigger step and it is Ian's to take separately.
 * ⚠️ AND IT WOULD LAND ONE HOUR BEFORE THE WEEKLY DIGEST: lg_fd_tick() flushes at
 * 08:00 site-local = 12:00 UTC, and lg_wd_send_digest — a DIFFERENT system, the
 * editorial weekly recap — is scheduled for MONDAY 2026-08-03 13:00 UTC. Nothing
 * here rides that hook or touches it, but on that Monday the two would send an hour
 * apart. Read the handoff note before flipping the tracked flag.
 *
 * ── WHAT THIS PROVES, AND WHAT IT CANNOT ─────────────────────────────────────
 * PROVES: the recipient set. Before anything is sent it prints who the REAL resolver
 * returns, and it refuses to send unless that set is exactly [Ian]. That is the
 * safety question, and it is fully answerable.
 *
 * CANNOT PROVE: an SES MessageId. The real pipeline is wp_mail() -> FluentSMTP, and
 * FluentSMTP only records the SES response when its logging table exists — it does
 * not, on live or on dev2 (checked: no wp_fluentmail_* tables). Handing back a
 * MessageId would mean bypassing wp_mail and calling SES directly, which is a
 * DIFFERENT code path from the one under test — that is what the earlier one-off
 * send did, and it is exactly why it could not answer "does the batcher work?".
 * So the delivery proof here is: the channel probe passed the WHOLE pre_wp_mail chain
 * without being short-circuited (which is strictly stronger than wp_mail returning
 * true — that is exactly what a swallowed send also returns), no wp_mail_failed error
 * fired, the watermark advanced to the newest reply included — and the mail is in
 * Ian's inbox. The last one is the real proof and it needs a human.
 */

declare( strict_types=1 );

const IAN_UID   = 1;
const IAN_EMAIL = 'ian.davlin@gmail.com';

$argvv    = $_SERVER['argv'] ?? array();
$do_send  = in_array( '--send', $argvv, true );
$do_arm   = in_array( '--arm', $argvv, true );
$do_dis   = in_array( '--disarm', $argvv, true );
$do_probe = in_array( '--probe', $argvv, true );
$since   = '';
foreach ( $argvv as $a ) {
	if ( 0 === strpos( (string) $a, '--since=' ) ) { $since = substr( (string) $a, 8 ); }
}

/* ⚠️ RUN AS looth-dev, NOT root. Postgres uses peer auth, so as root the role is
 * "root", which does not exist — lg_following_pg() returns null, every hub deep link
 * silently resolves to '' and the digest goes out with NO clickable discussions while
 * looking completely normal. Measured on dev2; an email cannot be edited afterwards. */
$whoami = function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( posix_geteuid() )['name'] ?? '?' ) : '?';
if ( 'root' === $whoami ) {
	fwrite( STDERR, "refusing to run as root — every hub link would resolve to empty and the\n"
		. "digest would look normal. Re-run with: sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php " . __FILE__ . "\n" );
	exit( 73 );
}

$wp_load = '/var/www/dev/wp-load.php';
if ( ! is_readable( $wp_load ) ) { fwrite( STDERR, "cannot read $wp_load\n" ); exit( 65 ); }
define( 'WP_USE_THEMES', false );
$pd = ini_get( 'display_errors' );
ini_set( 'display_errors', '0' );
require_once $wp_load;
ini_set( 'display_errors', (string) $pd );

function line( string $s ): void { printf( "%s\n", $s ); }
function die_with( string $s, int $code ): void { fwrite( STDERR, $s . "\n" ); exit( $code ); }

line( "=== follow-digest — controlled LIVE one-shot, recipient locked to one person ===\n" );

// ── GUARD 1: is this actually live? ──────────────────────────────────────────
// ⚠️ NOT LG_ENV. Live's /etc/looth/env says LG_ENV=dev2 (verified over ssh live-ro,
// 2026-07-31), so anything keying on it would mistake live for a dev box — and the
// containment plugin keys on exactly that value. home_url() is the honest tell.
$home = home_url();
$is_live = ( false !== strpos( $home, 'loothgroup.com' ) ) && ( false === strpos( $home, 'dev2.' ) );
if ( ! $is_live ) {
	die_with( "this is not live (home_url() = $home). It is written for live and its guards\n"
		. "assume live. On dev2 use platform/bin/follow-digest-allowlist-proof.php instead.", 74 );
}
line( "  ok   live confirmed by home_url() — $home   (LG_ENV is NOT trusted; live reports 'dev2')" );

// ── GUARD 2: nothing may be swallowing the send ──────────────────────────────
// If containment were somehow present, wp_mail would return TRUE having delivered
// nowhere, and this run would report a success that never happened.
//
// ⚠️ THIS USED TO REFUSE ON `has_filter('pre_wp_mail')` AND THAT WAS WRONG BOTH WAYS.
// Too strict: lg-poller-mail-killswitch.php registers on every box where
// `lgms_poller_mail_enabled` is unset — which is LIVE's state — so the one-shot could
// never send there at all. It is also SELECTIVE (read it: it returns $short untouched
// unless the call stack runs through /lg-patreon-stripe-poller/), so it would not have
// touched this send. A presence test cannot tell a benign filter from a fatal one.
// Too weak: it would also have passed a box where containment was registered but
// `has_filter` had been satisfied some other way, and containment returns TRUE.
//
// So the question is asked BEHAVIOURALLY instead — a tokenised probe through the real
// wp_mail(), observed at PHP_INT_MAX where WordPress itself reads the verdict. See
// platform/lib/lg-fd-mail-probe.php. It costs one extra email TO YOU, and that email
// is itself the first honest delivery receipt of the run.
$probe_lib = dirname( __DIR__ ) . '/lib/lg-fd-mail-probe.php';
if ( ! is_readable( $probe_lib ) ) {
	die_with( "cannot read $probe_lib — the mail-channel probe is missing, so this run cannot\n"
		. 'establish that a send would not be swallowed. Pull the merge first.', 75 );
}
require_once $probe_lib;

$filters = lg_fd_mail_probe_filters();
if ( $filters ) {
	line( sprintf( '  ..   %d pre_wp_mail filter(s) registered — presence alone decides nothing; '
		. 'the probe below decides:', count( $filters ) ) );
	foreach ( $filters as $f ) {
		line( sprintf( '         prio %-6s %s', $f['priority'], $f['file'] ?: $f['fn'] ) );
	}
} else {
	line( '  ..   no pre_wp_mail filter registered' );
}

// ── GUARD 3: the flag ────────────────────────────────────────────────────────
if ( ! defined( 'LG_FOLLOW_DIGEST_ENABLED' ) || ! LG_FOLLOW_DIGEST_ENABLED ) {
	die_with( "LG_FOLLOW_DIGEST is not 1, so the sender is a no-op and this would silently do\n"
		. "nothing. Re-run with: sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php " . __FILE__, 78 );
}
if ( ! function_exists( 'lg_fd_allowed' ) || ! function_exists( 'lg_fd_deliver' ) ) {
	die_with( "the allowlist is NOT present in the deployed sender. Do not proceed: without it\n"
		. "the recipient set is every member holding a cadence. Pull the merge first.", 77 );
}
line( '  ok   sender loaded WITH the allowlist, flag ON for this process only' );

// ── GUARD 4: THE ONE THAT MATTERS. Exactly Ian, pinned to his address. ───────
$a = lg_fd_allowlist();
$ian = get_userdata( IAN_UID );
if ( ! $ian ) { die_with( 'user ' . IAN_UID . ' does not exist', 79 ); }

$pinned_ok = 'list' === $a['mode']
	&& array( IAN_UID ) === array_map( 'intval', $a['uids'] )
	&& isset( $a['emails'][ IAN_UID ] )
	&& 0 === strcasecmp( (string) $a['emails'][ IAN_UID ], IAN_EMAIL );
if ( ! $pinned_ok ) {
	die_with( sprintf(
		"REFUSING: the allowlist is %s (%s).\n"
		. "This file will only run when it is EXACTLY '%d:%s' — one member, pinned to one\n"
		. "address. Anything wider is not a controlled test, and 'all-members' would mail\n"
		. "the membership. Fix platform/config/follow-digest.php and pull again.",
		var_export( $a['raw'], true ), $a['reason'], IAN_UID, IAN_EMAIL ), 80 );
}
if ( 0 !== strcasecmp( (string) $ian->user_email, IAN_EMAIL ) ) {
	die_with( sprintf( "REFUSING: user %d's address is %s, not %s. The pin exists for exactly\n"
		. "this case — the account is not who the allowlist names.", IAN_UID, $ian->user_email, IAN_EMAIL ), 81 );
}
line( sprintf( '  ok   allowlist is EXACTLY one member, pinned: %d:%s', IAN_UID, IAN_EMAIL ) );

// ── AUDIT: who else could possibly be in scope? ──────────────────────────────
global $wpdb;
$holders = (array) $wpdb->get_col( $wpdb->prepare(
	"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN ('daily','weekly')",
	LG_FD_CADENCE_META ) );
$holders = array_map( 'intval', $holders );
$others  = array_values( array_diff( $holders, array( IAN_UID ) ) );
line( sprintf( '  ..   members holding a batched cadence on live: %d%s',
	count( $holders ), $holders ? ' (' . implode( ',', $holders ) . ')' : ' — nobody' ) );
if ( $others ) {
	line( sprintf( '  ..   %d of them are NOT Ian — the allowlist blocks every one; they will be '
		. 'listed as blocked below', count( $others ) ) );
}

/**
 * Run the channel probe and REFUSE unless it comes back clear.
 *
 * Placed after GUARD 4 on purpose: the probe is itself behind the allowlist, so it
 * cannot even be attempted until the allowlist has been proven to be exactly Ian.
 */
function probe_channel_or_die(): void {
	line( "\n--- MAIL CHANNEL PROBE (one real email to you, before any digest) ---" );
	$p = lg_fd_mail_probe( IAN_UID, IAN_EMAIL );
	line( sprintf( '       token            : %s', $p['token'] ?: '(none)' ) );
	line( sprintf( '       wp_mail returned : %s', var_export( $p['wp_mail_returned'], true ) ) );
	line( sprintf( '       observed at the end of the chain: %s',
		$p['ran'] ? ( $p['swallowed'] ? 'SHORT-CIRCUITED' : 'still null — nothing intercepted' ) : 'NEVER RAN' ) );

	if ( ! $p['clear'] ) {
		die_with( "\nREFUSING TO SEND — " . $p['reason'] . "\n\n"
			. "This is the guard doing its job. A swallowed send returns TRUE from wp_mail(),\n"
			. "so continuing would report a delivery that never happened. Note that the mere\n"
			. "PRESENCE of a filter is not what stopped this — the probe was actually eaten.", 75 );
	}
	line( '  ok   ' . $p['reason'] );
	line( sprintf( '  ⚠️  CHECK YOUR INBOX FOR THE PROBE (subject contains %s).', $p['token'] ) );
	line( '       wp_mail passing the chain means nothing suppressed it — the probe ARRIVING' );
	line( '       is what proves SES actually delivers. If it does not turn up, stop here.' );
}

// ── --probe ──────────────────────────────────────────────────────────────────
// Check the channel on its own, without building or sending a digest. Safe to run
// any number of times; it only ever mails Ian.
if ( $do_probe ) {
	probe_channel_or_die();
	line( "\n  The channel is clear. Nothing else was done — no digest was built or sent." );
	exit( 0 );
}

// ── --disarm ─────────────────────────────────────────────────────────────────
if ( $do_dis ) {
	delete_user_meta( IAN_UID, LG_FD_CADENCE_META );
	delete_user_meta( IAN_UID, LG_FD_WATERMARK_META );
	while ( $ts = wp_next_scheduled( LG_FD_CRON_HOOK ) ) { wp_unschedule_event( $ts, LG_FD_CRON_HOOK ); }
	delete_option( 'lg_fd_last_flush_daily' );
	delete_option( 'lg_fd_last_flush_weekly' );
	line( "\n  ok   disarmed: your cadence and watermark are removed (back to \"absent means\n"
		. '       instant\", the state every other member is in), and no lg_fd cron is scheduled.' );
	exit( 0 );
}

// ── --arm ────────────────────────────────────────────────────────────────────
if ( $do_arm ) {
	if ( '' === $since || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
		die_with( "--arm needs --since=YYYY-MM-DD.\n\n"
			. "Why it is required rather than defaulted: lg_fd_set_cadence() stamps the watermark\n"
			. "to NOW — deliberately, because that is the flood guard that stops a first digest\n"
			. "being the entire reply history of every thread you follow. So arming without a\n"
			. "backdate correctly produces an EMPTY digest and sends nothing. --since says how\n"
			. "far back this one test should look, and it is your decision, not a default.", 82 );
	}
	lg_fd_set_cadence( IAN_UID, 'daily' );                       // the real writer
	update_user_meta( IAN_UID, LG_FD_WATERMARK_META, $since . ' 00:00:00' );
	line( sprintf( "\n  ok   armed: YOUR cadence is daily and YOUR watermark is %s 00:00:00.", $since ) );
	line( '       Nobody else was touched. Re-run without --arm to see what would be sent.' );
	line( '       ⚠️ Run --disarm when you are done, or you stay on Daily.' );
	exit( 0 );
}

// ── WHAT WOULD BE SENT ───────────────────────────────────────────────────────
line( "\n--- the REAL resolver, on live data ---" );
$due = array();
foreach ( array( 'daily', 'weekly' ) as $c ) {
	$r = lg_fd_due_recipients( $c );
	$due[ $c ] = array_map( 'intval', (array) $r );
	line( sprintf( '  %-6s due after the allowlist: %s', $c, $due[ $c ] ? implode( ',', $due[ $c ] ) : 'nobody' ) );
}
$all_due = array_unique( array_merge( $due['daily'], $due['weekly'] ) );
$strangers = array_diff( $all_due, array( IAN_UID ) );
if ( $strangers ) {
	die_with( sprintf( "REFUSING: the resolver returned uid(s) %s besides Ian. The allowlist did not\n"
		. "hold and NOTHING will be sent.", implode( ',', $strangers ) ), 83 );
}

$cad   = lg_fd_cadence( IAN_UID );
$wm    = lg_fd_watermark( IAN_UID );
$batch = lg_fd_items_for( IAN_UID );
line( sprintf( "\n  your cadence   : %s", $cad ) );
line( sprintf( '  your watermark : %s', '' === $wm ? '(none — run --arm first)' : $wm ) );
line( sprintf( '  your recap     : %d repl(y|ies) across %d discussion(s)',
	count( $batch['items'] ),
	count( array_unique( array_column( $batch['items'], 'topic_id' ) ) ) ) );

foreach ( $batch['items'] as $it ) {
	$au = get_userdata( (int) $it['post_author'] );
	line( sprintf( '     %s  %-18s %s', $it['post_date_gmt'],
		$au ? $au->display_name : '?', get_the_title( (int) $it['topic_id'] ) ) );
}

if ( ! $batch['items'] ) {
	line( "\n  Nothing to send. That is the CORRECT behaviour, not a failure — \"empty means send" );
	line( '  nothing\", so no email goes out at all rather than one that says "no news".' );
	line( '  If you expected content, widen the window: --arm --since=YYYY-MM-DD.' );
	exit( 0 );
}
line( sprintf( "\n  subject        : %s", lg_fd_subject( $batch ) ) );

if ( ! $do_send ) {
	line( "\n  DRY RUN — nothing was sent. Re-run with --send to deliver it to you." );
	line( '  (--send probes the mail channel first, with one real email to you.)' );
	exit( 0 );
}

// The channel must be proven before the digest is put on it. If this refuses, nothing
// about your account has changed and the batch is still queued.
probe_channel_or_die();

// ── SEND, through the real cron callback ─────────────────────────────────────
// ⚠️ The CLOCK is simulated and nothing else is. lg_fd_tick() only flushes at 08:00
// site-local; filtering the timezone IN THIS PROCESS (no option is written) lets the
// genuine hook run now — real predicate, real flush, real resolver, real collector,
// real render, real wp_mail. If the simulation fails to take it ABORTS, because a
// tick that quietly did nothing is indistinguishable from a successful one.
add_filter( 'pre_option_timezone_string', static function () { return ''; } );
add_filter( 'pre_option_gmt_offset', static function () { return LG_FD_DAILY_HOUR - (int) gmdate( 'G' ); } );
$now = lg_fd_local_now();
if ( LG_FD_DAILY_HOUR !== $now['h'] ) {
	die_with( sprintf( 'the simulated send hour did not take (reads %02d:00, want %02d:00) — aborting '
		. 'rather than reporting a silent no-op as success', $now['h'], LG_FD_DAILY_HOUR ), 84 );
}

$mail_error = '';
add_action( 'wp_mail_failed', static function ( $e ) use ( &$mail_error ) {
	$mail_error = is_wp_error( $e ) ? $e->get_error_message() : 'unknown';
} );

$wm_before = lg_fd_watermark( IAN_UID );
line( "\n--- SENDING (real wp_mail -> FluentSMTP -> SES) ---" );
do_action( LG_FD_CRON_HOOK );
$wm_after = lg_fd_watermark( IAN_UID );

if ( '' !== $mail_error ) {
	die_with( "wp_mail FAILED: $mail_error\nThe watermark was still advanced by design (an unrecallable\n"
		. "channel must not build a retry loop), so check your inbox before re-arming.", 85 );
}
if ( $wm_before === $wm_after ) {
	die_with( "the watermark did NOT advance, which means nothing was sent. Check the error log\n"
		. "for [lg-fd] lines — the most likely cause is the link store being unreachable, which\n"
		. "makes the sender refuse rather than send a digest with no discussion links.", 86 );
}

line( '  ok   wp_mail returned without error, and no wp_mail_failed fired' );
line( sprintf( '  ok   your watermark advanced %s -> %s (the newest reply included)', $wm_before, $wm_after ) );
line( sprintf( '  ok   recipient: %s — and the resolver returned nobody else', IAN_EMAIL ) );
line( "\n  ⚠️ THE ACTUAL PROOF OF DELIVERY IS YOUR INBOX. wp_mail returning true means" );
line( '     FluentSMTP accepted it, not that SES delivered it — and there is no MessageId' );
line( '     to read back, because FluentSMTP only records one when its logging table' );
line( '     exists and it does not. If it did not arrive, say so and do NOT re-arm blindly.' );
line( "\n  Then put your account back: --disarm" );
exit( 0 );
