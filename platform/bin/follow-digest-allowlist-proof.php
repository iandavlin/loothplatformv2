<?php
/**
 * follow-digest-allowlist-proof.php — prove the allowlist HOLDS against real rows.
 *
 *   # build the branch harness once (this is the gate's own, and it is why the run
 *   # tests THIS branch instead of main — the serve symlinks main's sender):
 *   python3 tools/gates/follow-digest-gate.py --plugin platform/mu-plugins/lg-follow-digest.php
 *
 *   sudo -u looth-dev env LG_FOLLOW_DIGEST=1 LG_FD_MU_DIR=/tmp/lg-fd-gate-mu \
 *        php platform/bin/follow-digest-allowlist-proof.php
 *
 * Standalone rather than `wp eval-file`, for two reasons that both bite: eval-file
 * runs in FUNCTION scope (so a top-level $var is not a global and helpers reading
 * `global $x` silently see nothing), and it strips the opening tag, so a
 * declare(strict_types=1) is a fatal. Booting WP directly also lets this file define
 * WPMU_PLUGIN_DIR itself, which is the only way to put a BRANCH's mu-plugin in front
 * of the one the docroot symlinks.
 *
 * ── WHAT THIS PROVES THAT THE GATE CANNOT ────────────────────────────────────
 * The gate proves the allowlist's GRAMMAR and its DECISIONS, exhaustively, against a
 * table. That is a statement about a function. This is a statement about the SYSTEM:
 * a member who genuinely qualifies — real subscription, real replies, real cadence,
 * real watermark — is put through the REAL cron callback and receives NOTHING.
 *
 *     ⚠️ AND AN ABSENCE PROVES NOTHING WITHOUT ITS CONTROL.
 *
 * "The canary got no mail" is equally true of a canary that never qualified, of a
 * flush that never ran, and of a box with no WordPress. So this runs TWICE:
 *
 *   RUN 1  allowlist = Ian only          -> Ian is mailed, the canary is NOT
 *   RUN 2  allowlist = Ian + the canary  -> the canary IS mailed
 *
 * Run 2 is the whole argument. It proves the canary qualified all along and that the
 * zero in run 1 was caused by the allowlist and by nothing else. Without it this file
 * would be the vacuous pass that feedback-absence-assertion-needs-liveness describes.
 *
 * And run 2 proves the subtle invariant too: the canary's run-2 digest contains
 * EXACTLY the replies run 1 withheld. A blocked send must leave the member untouched —
 * if the watermark had advanced while the mail was blocked, those replies would be
 * gone for good, and nobody would find out until the allowlist was widened.
 *
 * ── ⚠️ THIS FILE SENDS MAIL. THE GUARDS BELOW ARE NOT DECORATION ─────────────
 * It calls the real sender, which calls wp_mail(). On dev2 that is intercepted by
 * lg-dev-mail-containment.php and delivered to mailpit. ON A BOX WITHOUT CONTAINMENT
 * IT WOULD REACH REAL INBOXES. So it refuses to run unless it can SEE containment
 * registered, and refuses unless home_url() is dev2.
 *
 * ⚠️ NOT LG_ENV — LIVE'S /etc/looth/env SAYS `LG_ENV=dev2`. Verified over ssh live-ro
 * on 2026-07-31. Anything keying on LG_ENV would consider live a dev box; the
 * containment plugin itself keys on exactly that value, which is why the presence of
 * containment is checked directly rather than inferred from the environment.
 *
 * ── WHAT IS SEEDED, STATED PLAINLY ───────────────────────────────────────────
 * The REPLIES and the TOPICS are real dev2 rows, none written for this test. What is
 * seeded: a canary user that exists only for this run, its subscription, both
 * cadences, and both watermarks — backdated, because a digest with a watermark
 * stamped NOW correctly contains nothing. Backdating is simulating the passage of a
 * day, which is the one thing a test of a daily digest cannot wait for.
 * Everything seeded is torn down at the end, including on failure.
 */

declare( strict_types=1 );

/** RFC 2606 guarantees .invalid can never resolve, so even a catastrophic escape of
 *  this address cannot reach a person. It still proves the point: containment hands
 *  mailpit whatever it is given, so a message addressed here WOULD be captured and
 *  counted — the zero below is a real zero, not an undeliverable one. */
const CANARY_EMAIL = 'follow-digest-canary@dev2.invalid';
const CANARY_LOGIN = 'lg-fd-canary';
const IAN_UID      = 1;
const IAN_EMAIL    = 'ian.davlin@gmail.com';

/** Far enough back to catch real late-July dev2 replies, and stated in the report. */
const BACKDATE = '2026-07-25 00:00:00';

const MAILPIT = 'http://127.0.0.1:8025';

/* ── BOOT WORDPRESS, WITH THIS BRANCH'S SENDER IN FRONT ───────────────────────
 * WPMU_PLUGIN_DIR is only defined by wp_initial_constants() `if ( ! defined(...) )`,
 * so defining it here wins and WordPress loads the mirrored mu-plugin directory the
 * gate built — same DB, same BuddyBoss, same containment, this branch's sender.
 * Without it the docroot symlink wins and this would test main, which has no
 * allowlist at all: the proof would pass by mailing everybody.
 *
 * ⚠️ RUN AS looth-dev, NOT root. Postgres uses peer auth, so as root the role is
 * "root", which does not exist — lg_following_pg() returns null, every hub deep link
 * resolves to '' and the digest renders with NO clickable discussions while looking
 * completely normal. */
$mu = (string) ( getenv( 'LG_FD_MU_DIR' ) ?: '' );
if ( '' !== $mu ) {
	if ( ! is_dir( $mu ) ) {
		fwrite( STDERR, "LG_FD_MU_DIR=$mu is not a directory. Build it with:\n"
			. "  python3 tools/gates/follow-digest-gate.py --plugin platform/mu-plugins/lg-follow-digest.php\n" );
		exit( 64 );
	}
	define( 'WPMU_PLUGIN_DIR', $mu );
}
$wp_load = '/var/www/dev/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "cannot read $wp_load (run as looth-dev)\n" );
	exit( 65 );
}
define( 'WP_USE_THEMES', false );
$prev_display = ini_get( 'display_errors' );
ini_set( 'display_errors', '0' );
require_once $wp_load;
ini_set( 'display_errors', (string) $prev_display );

$FAIL = [];
$OK   = [];
function ok( string $s ): void   { global $OK;   $OK[] = $s;   printf( "  ok   %s\n", $s ); }
function bad( string $s ): void  { global $FAIL; $FAIL[] = $s; printf( "  FAIL %s\n", $s ); }
function say( string $s ): void  { printf( "%s\n", $s ); }

function mailpit( string $path ) {
	$raw = @file_get_contents( MAILPIT . $path );
	return false === $raw ? null : json_decode( $raw, true );
}

/** Every message id mailpit currently holds. Used as a BASELINE rather than deleting:
 *  this is a shared box and another lane's mail is not ours to throw away. */
function mailpit_ids(): array {
	$out = array();
	$d   = mailpit( '/api/v1/messages?limit=200' );
	foreach ( (array) ( $d['messages'] ?? array() ) as $m ) { $out[ (string) $m['ID'] ] = true; }
	return $out;
}

/** Messages that appeared since the baseline, as [id => [to[], subject]]. */
function mailpit_since( array $baseline ): array {
	$new = array();
	$d   = mailpit( '/api/v1/messages?limit=200' );
	foreach ( (array) ( $d['messages'] ?? array() ) as $m ) {
		$id = (string) $m['ID'];
		if ( isset( $baseline[ $id ] ) ) { continue; }
		$to = array();
		foreach ( (array) ( $m['To'] ?? array() ) as $t ) { $to[] = strtolower( (string) ( $t['Address'] ?? '' ) ); }
		$new[ $id ] = array( 'to' => $to, 'subject' => (string) ( $m['Subject'] ?? '' ) );
	}
	return $new;
}

function to_count( array $msgs, string $addr ): int {
	$n = 0;
	foreach ( $msgs as $m ) { if ( in_array( strtolower( $addr ), $m['to'], true ) ) { $n++; } }
	return $n;
}

// ─────────────────────────────────────────────────────────────────────────────
// GUARDS. Each one is a reason this file would be dangerous somewhere else.
// ─────────────────────────────────────────────────────────────────────────────
say( "=== follow-digest allowlist proof — the allowlist HOLDS against real rows ===\n" );

$whoami = function_exists( 'posix_getpwuid' ) ? ( posix_getpwuid( posix_geteuid() )['name'] ?? '?' ) : '?';
if ( 'root' === $whoami ) {
	fwrite( STDERR, "refusing to run as root: Postgres peer auth would fail (role \"root\"), every\n"
		. "hub link would silently resolve to empty, and the digest would look normal.\n" );
	exit( 73 );
}

$home = home_url();
if ( false === strpos( $home, 'dev2.' ) ) {
	fwrite( STDERR, "refusing: home_url() is $home, which is not dev2. This file SENDS MAIL.\n"
		. "⚠️ Do not 'fix' this by checking LG_ENV — live's /etc/looth/env says LG_ENV=dev2.\n" );
	exit( 74 );
}
ok( "box is dev2 by home_url() — $home  (LG_ENV is NOT trusted; live reports 'dev2')" );

if ( ! has_filter( 'pre_wp_mail' ) ) {
	fwrite( STDERR, "refusing: no pre_wp_mail filter is registered, so lg-dev-mail-containment is\n"
		. "NOT active and wp_mail() would reach real inboxes. This file sends mail.\n" );
	exit( 75 );
}
ok( 'mail containment is ACTIVE (pre_wp_mail registered) — sends land in mailpit, not inboxes' );

if ( null === mailpit( '/api/v1/messages?limit=1' ) ) {
	fwrite( STDERR, "refusing: mailpit is not answering on " . MAILPIT . ". It is the instrument\n"
		. "this proof reads; without it 'the canary got nothing' would be unobservable\n"
		. "rather than false.\n" );
	exit( 76 );
}
ok( 'mailpit answers — the recipient oracle is live, so a zero is an observation' );

if ( ! function_exists( 'lg_fd_allowed' ) || ! function_exists( 'lg_fd_tick' ) ) {
	fwrite( STDERR, "refusing: the allowlist is not loaded. You are almost certainly testing MAIN.\n"
		. "Run the gate once with --plugin to build /tmp/lg-fd-gate-boot.php, then pass\n"
		. "--require=/tmp/lg-fd-gate-boot.php to wp.\n" );
	exit( 77 );
}
$loaded = ( new ReflectionFunction( 'lg_fd_allowed' ) )->getFileName();
ok( "sender under test: $loaded" );

if ( ! LG_FOLLOW_DIGEST_ENABLED ) {
	fwrite( STDERR, "refusing: LG_FOLLOW_DIGEST_ENABLED is false, so the sender is a no-op and every\n"
		. "assertion below would pass vacuously. Re-run with LG_FOLLOW_DIGEST=1.\n" );
	exit( 78 );
}
ok( 'LG_FOLLOW_DIGEST_ENABLED is ON — the ON path is under test, not the OFF no-op' );

$ian = get_userdata( IAN_UID );
if ( ! $ian || 0 !== strcasecmp( (string) $ian->user_email, IAN_EMAIL ) ) {
	fwrite( STDERR, sprintf( "refusing: user %d is %s, not %s\n", IAN_UID,
		$ian ? $ian->user_email : 'missing', IAN_EMAIL ) );
	exit( 79 );
}

// ─────────────────────────────────────────────────────────────────────────────
// SEED. Everything created here is torn down in teardown(), including on failure.
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- seeding (dev2 only; torn down at the end) ---" );

global $wpdb;
$subs_table = $wpdb->prefix . 'bb_notifications_subscriptions';

$canary = get_user_by( 'login', CANARY_LOGIN );
$canary_uid = $canary ? (int) $canary->ID : 0;
if ( ! $canary_uid ) {
	$canary_uid = wp_insert_user( array(
		'user_login' => CANARY_LOGIN, 'user_email' => CANARY_EMAIL,
		'user_pass'  => wp_generate_password( 32 ), 'display_name' => 'Follow-digest canary',
		'role'       => 'subscriber',
	) );
	if ( is_wp_error( $canary_uid ) ) {
		fwrite( STDERR, 'could not create the canary: ' . $canary_uid->get_error_message() . "\n" );
		exit( 80 );
	}
	$canary_uid = (int) $canary_uid;
}
ok( sprintf( 'canary member uid %d <%s> — exists only for this run', $canary_uid, CANARY_EMAIL ) );

/** A REAL dev2 topic with real recent replies, chosen by row count rather than named,
 *  so this keeps working as the box's data changes. Excludes replies by the member
 *  themselves, which is what the collector does too. */
$topic = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT r.post_parent FROM {$wpdb->posts} r
	  WHERE r.post_type='reply' AND r.post_status='publish' AND r.post_date_gmt > %s
	    AND r.post_author NOT IN (%d, %d)
	  GROUP BY r.post_parent ORDER BY COUNT(*) DESC LIMIT 1", BACKDATE, IAN_UID, $canary_uid ) );
if ( ! $topic ) {
	fwrite( STDERR, "no dev2 topic has replies since " . BACKDATE . " — nothing to batch, and a\n"
		. "zero-content run would prove nothing either way.\n" );
	exit( 81 );
}
say( sprintf( '       seed topic %d — %s', $topic, get_the_title( $topic ) ) );

/** Subscribe BOTH to the same topic, so the ONLY difference between them is the
 *  allowlist. Same topic, same replies, same cadence, same watermark: if one is
 *  mailed and the other is not, there is exactly one variable left. */
$seeded_subs = array();
foreach ( array( IAN_UID, $canary_uid ) as $uid ) {
	$has = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$subs_table} WHERE user_id=%d AND type='topic' AND item_id=%d AND status=1",
		$uid, $topic ) );
	if ( ! $has ) {
		$wpdb->insert( $subs_table, array(
			'blog_id' => get_current_blog_id(), 'user_id' => $uid, 'type' => 'topic',
			'item_id' => $topic, 'secondary_item_id' => 0, 'status' => 1,
			'date_recorded' => current_time( 'mysql', true ),
		) );
		$seeded_subs[] = $uid;
	}
	// The REAL writer, so the flood guard runs — then backdated, because a watermark
	// stamped NOW correctly yields an empty digest and there is no waiting a day.
	lg_fd_set_cadence( $uid, 'daily' );
	update_user_meta( $uid, LG_FD_WATERMARK_META, BACKDATE );
}
ok( sprintf( 'both members: subscribed to topic %d, cadence=daily, watermark=%s', $topic, BACKDATE ) );

$ian_batch    = lg_fd_items_for( IAN_UID );
$canary_batch = lg_fd_items_for( $canary_uid );
if ( ! $canary_batch['items'] ) {
	fwrite( STDERR, "the canary has NO items — it does not qualify, so 'it received nothing'\n"
		. "would be vacuous. Aborting rather than reporting a meaningless pass.\n" );
	exit( 82 );
}
ok( sprintf( 'the canary GENUINELY QUALIFIES: %d real repl(y|ies) waiting — so a zero below '
	. 'is the allowlist, not an empty queue', count( $canary_batch['items'] ) ) );
say( sprintf( '       (Ian qualifies too: %d repl(y|ies) across his %d real subscriptions)',
	count( $ian_batch['items'] ),
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subs_table} WHERE user_id=%d AND type='topic' AND status=1", IAN_UID ) ) ) );

$canary_expected = count( $canary_batch['items'] );

function teardown( int $canary_uid, array $seeded_subs, int $topic ): void {
	global $wpdb;
	$subs_table = $wpdb->prefix . 'bb_notifications_subscriptions';
	foreach ( $seeded_subs as $uid ) {
		$wpdb->delete( $subs_table, array( 'user_id' => $uid, 'type' => 'topic', 'item_id' => $topic ) );
	}
	// Ian's cadence and watermark go back to absent — "absent means instant", which is
	// the state the whole membership is in and the state the gate asserts.
	delete_user_meta( IAN_UID, LG_FD_CADENCE_META );
	delete_user_meta( IAN_UID, LG_FD_WATERMARK_META );
	delete_option( 'lg_fd_last_flush_daily' );
	delete_option( 'lg_fd_last_flush_weekly' );
	// Booting WP with the flag on lets `init` arm the real sender. The harness blocks
	// that (00-lg-fd-gate-guard.php) and the sender unschedules itself the next time
	// the flag reads off — but neither is this file's to rely on, so it disarms what
	// it may have caused rather than leaving a live sender behind.
	while ( $ts = wp_next_scheduled( LG_FD_CRON_HOOK ) ) { wp_unschedule_event( $ts, LG_FD_CRON_HOOK ); }
	if ( $canary_uid > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $canary_uid );
	}
}

/**
 * Fire the REAL cron callback, at a SIMULATED 08:00.
 *
 * ⚠️ THE CLOCK IS SIMULATED; NOTHING ELSE IS. lg_fd_tick() only flushes at
 * LG_FD_DAILY_HOUR, and a test cannot wait until 08:00 site-local. So the site's
 * timezone is filtered IN THIS PROCESS ONLY — no option is written — until wp_date()
 * reports hour 8, and then the genuine hook callback runs: the real predicate, the
 * real flush, the real resolver, the real collector, the real render, the real
 * wp_mail(). If the simulated clock does not take, this ABORTS rather than reporting
 * a pass, because a tick that silently did nothing would look exactly like a pass.
 */
function tick_at_send_hour(): void {
	static $wired = false;
	if ( ! $wired ) {
		add_filter( 'pre_option_timezone_string', static function () { return ''; } );
		add_filter( 'pre_option_gmt_offset', static function () {
			return LG_FD_DAILY_HOUR - (int) gmdate( 'G' );
		} );
		$wired = true;
	}
	$now = lg_fd_local_now();
	if ( LG_FD_DAILY_HOUR !== $now['h'] ) {
		fwrite( STDERR, sprintf( "the simulated clock did not take: the sender reads %02d:00, not %02d:00.\n"
			. "Aborting — a tick that quietly does nothing is indistinguishable from a pass.\n",
			$now['h'], LG_FD_DAILY_HOUR ) );
		exit( 83 );
	}
	// The real hook, dispatched the way cron dispatches it.
	do_action( LG_FD_CRON_HOOK );
}

// ─────────────────────────────────────────────────────────────────────────────
// RUN 1 — allowlist = Ian alone.
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- RUN 1: allowlist = Ian only ---" );
putenv( 'LG_FOLLOW_DIGEST_ALLOWLIST=' . IAN_UID . ':' . IAN_EMAIL );
$_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] = IAN_UID . ':' . IAN_EMAIL;
say( sprintf( '       allowlist resolves: %s', lg_fd_allowlist()['reason'] ) );

/* ── ⚠️ THE MAIL BLACK HOLE, ASSERTED BEFORE ANYTHING IS SENT ────────────────
 * Suppressing a member's per-reply mail is only defensible because a digest replaces
 * it. For a member the allowlist BLOCKS, no digest is coming — so if suppression
 * still applied, the allowlist would take away the mail they DO get and give nothing
 * back. That is strictly worse than never having built any of this, and it is the one
 * way an allowlist can make things worse rather than safer.
 *
 * Both members are on cadence=daily here, so the filter has a real decision to make
 * and neither answer is the default. */
$filter = 'bb_send_forums_subscribed_reply_email_notifications';
$ian_suppressed    = ! (bool) apply_filters( $filter, true, array( 'recipient_user_id' => IAN_UID ) );
$canary_suppressed = ! (bool) apply_filters( $filter, true, array( 'recipient_user_id' => $canary_uid ) );

if ( $ian_suppressed ) {
	ok( 'Ian is on Daily and IS served, so his per-reply mail is suppressed — the digest replaces it' );
} else {
	bad( 'Ian is on Daily but his per-reply mail is NOT suppressed — he would get instant mail AND a digest' );
}
if ( ! $canary_suppressed ) {
	ok( 'the BLOCKED canary is on Daily but its per-reply mail is NOT suppressed — no digest '
		. 'is coming, so taking away its instant mail would leave it with NOTHING' );
} else {
	bad( '⚠️ MAIL BLACK HOLE: the blocked canary is on Daily, its per-reply mail is suppressed, '
		. 'and the allowlist withholds its digest — it would receive nothing at all' );
}

$baseline = mailpit_ids();
tick_at_send_hour();
$run1 = mailpit_since( $baseline );

$ian_got    = to_count( $run1, IAN_EMAIL );
$canary_got = to_count( $run1, CANARY_EMAIL );

if ( 1 === $ian_got ) { ok( sprintf( 'Ian received EXACTLY ONE digest — %s', reset( $run1 )['subject'] ?? '' ) ); }
else { bad( sprintf( 'Ian received %d digests, expected exactly 1', $ian_got ) ); }

if ( 0 === $canary_got ) {
	ok( sprintf( 'the canary received ZERO — and it qualified with %d repl(y|ies). '
		. 'THE ALLOWLIST HELD.', $canary_expected ) );
} else {
	bad( sprintf( '⚠️ THE CANARY RECEIVED %d MESSAGE(S). The allowlist did NOT hold.', $canary_got ) );
}

// Nobody else, either. The set of new messages must be exactly one, to Ian.
$strangers = array();
foreach ( $run1 as $id => $m ) {
	foreach ( $m['to'] as $addr ) {
		if ( 0 !== strcasecmp( $addr, IAN_EMAIL ) ) { $strangers[] = $addr; }
	}
}
if ( $strangers ) {
	bad( 'mail went to address(es) that are not on the allowlist: ' . implode( ', ', array_unique( $strangers ) ) );
} else {
	ok( sprintf( 'no message reached ANY address other than the allowlisted one '
		. '(%d new message(s) in mailpit, all to Ian)', count( $run1 ) ) );
}

/** ⚠️ THE SUBTLE ONE. A blocked member must be left EXACTLY as found. */
$canary_wm = lg_fd_watermark( $canary_uid );
if ( BACKDATE === $canary_wm ) {
	ok( 'the blocked canary\'s watermark did NOT move — its replies are still pending, '
		. 'so widening the allowlist later loses nothing' );
} else {
	bad( sprintf( 'the blocked canary\'s watermark MOVED to %s — its replies were consumed '
		. 'without being delivered, and would be silently missing from its first real digest', $canary_wm ) );
}
$ian_wm = lg_fd_watermark( IAN_UID );
if ( BACKDATE !== $ian_wm ) { ok( sprintf( "Ian's watermark advanced to %s — his batch was consumed by a real send", $ian_wm ) ); }
else { bad( "Ian's watermark did not advance, so nothing was actually sent to him" ); }

// ─────────────────────────────────────────────────────────────────────────────
// RUN 2 — THE CONTROL. Widen the allowlist to include the canary.
// Without this, run 1 is indistinguishable from a canary that never qualified.
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- RUN 2 (THE CONTROL): allowlist = Ian + the canary ---" );
delete_option( 'lg_fd_last_flush_daily' );      // simulate the next day's tick
$widened = IAN_UID . ':' . IAN_EMAIL . ',' . $canary_uid . ':' . CANARY_EMAIL;
putenv( 'LG_FOLLOW_DIGEST_ALLOWLIST=' . $widened );
$_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] = $widened;
say( sprintf( '       allowlist resolves: %s', lg_fd_allowlist()['reason'] ) );

$baseline2 = mailpit_ids();
tick_at_send_hour();
$run2 = mailpit_since( $baseline2 );

$canary_got2 = to_count( $run2, CANARY_EMAIL );
if ( 1 === $canary_got2 ) {
	ok( 'the canary received a digest once allowlisted — so run 1\'s zero was caused by '
		. 'the ALLOWLIST and by nothing else. This is what makes run 1 evidence.' );
} else {
	bad( sprintf( 'the canary received %d digest(s) even when allowlisted — run 1\'s zero '
		. 'proves nothing, because this member may never have qualified', $canary_got2 ) );
}

$canary_wm2 = lg_fd_watermark( $canary_uid );
if ( BACKDATE !== $canary_wm2 ) {
	ok( sprintf( 'and its watermark advanced only now (%s) — the %d repl(y|ies) run 1 '
		. 'withheld were delivered intact, not lost', $canary_wm2, $canary_expected ) );
}

$ian_got2 = to_count( $run2, IAN_EMAIL );
if ( 0 === $ian_got2 ) {
	ok( 'Ian received NOTHING on the second tick — the watermark makes a repeat send '
		. 'impossible by construction, not by care' );
} else {
	bad( sprintf( 'Ian received %d further digest(s) on a second tick — DOUBLE SEND', $ian_got2 ) );
}

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- teardown ---" );
teardown( $canary_uid, $seeded_subs, $topic );
$left = get_user_by( 'login', CANARY_LOGIN );
$ian_left = get_user_meta( IAN_UID, LG_FD_CADENCE_META, true );
if ( ! $left && '' === (string) $ian_left ) {
	ok( 'canary deleted; Ian\'s cadence and watermark removed (back to "absent means instant")' );
} else {
	bad( sprintf( 'teardown incomplete — canary:%s ian_cadence:%s',
		$left ? 'STILL PRESENT' : 'gone', $ian_left ?: 'gone' ) );
}

say( '' );
if ( $FAIL ) {
	printf( "############ ALLOWLIST PROOF FAILED — %d finding(s) ############\n", count( $FAIL ) );
	exit( 1 );
}
printf( "############ ALLOWLIST PROOF PASSED — %d assertion(s) ############\n", count( $OK ) );
say( 'The allowlist held against a member who genuinely qualified, and the control run' );
say( 'proves the zero was the allowlist rather than an empty queue.' );
exit( 0 );
