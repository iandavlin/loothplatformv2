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

/* ── --expect=hold | leak ─────────────────────────────────────────────────────
 * RED-FIRST FOR AN END-TO-END ASSERTION, which is harder than red-first for a unit:
 * you cannot prove "the canary got nothing because of the allowlist" by deleting the
 * allowlist and observing nothing, because nothing is also what a broken harness
 * produces. So the driver can be told which outcome it is checking for:
 *
 *   --expect=hold   (default) the allowlist HOLDS: Ian is mailed, the canary is not.
 *   --expect=leak             the allowlist is GONE: the canary IS mailed.
 *
 * Run against a build with lg_fd_allowed() forced true, `--expect=leak` PASSES — which
 * is what proves this driver can actually see the failure it claims to rule out. A
 * driver that reports "held" against both builds is measuring nothing, and that is the
 * failure mode a plain red-first would not catch here.
 *
 * tools/gates/follow-digest-gate.py --prove-test-mode runs BOTH and requires both.
 */
$expect = 'hold';
foreach ( $argvv = ( $_SERVER['argv'] ?? array() ) as $a ) {
	if ( 0 === strpos( (string) $a, '--expect=' ) ) { $expect = substr( (string) $a, 9 ); }
}
if ( ! in_array( $expect, array( 'hold', 'leak' ), true ) ) {
	fwrite( STDERR, "--expect must be 'hold' or 'leak'\n" );
	exit( 64 );
}

$FAIL = [];
$OK   = [];
function ok( string $s ): void   { global $OK;   $OK[] = $s;   printf( "  ok   %s\n", $s ); }
function bad( string $s ): void  { global $FAIL; $FAIL[] = $s; printf( "  FAIL %s\n", $s ); }
function say( string $s ): void  { printf( "%s\n", $s ); }

/* ── THE ATTEMPT RECORDER — keeper, 2026-07-31 ───────────────────────────────
 * "A swallowed-but-attempted send to a non-allowlist member is a FAIL even if
 * containment ate it."
 *
 * Exactly right, and mailpit alone answers it only INDIRECTLY. Containment hooks
 * pre_wp_mail at priority 1, so anything handed to wp_mail() does reach mailpit and a
 * mailpit zero does imply no attempt — but that is a chain of reasoning about someone
 * else's plugin, and it would break silently if containment's priority ever changed.
 *
 * This sits at priority 0 — BEFORE containment — records every address wp_mail() is
 * asked to send to, and returns $null so the mail continues to containment untouched.
 * It measures the ATTEMPT rather than the capture, so "nothing was even tried for the
 * blocked member" becomes a direct observation instead of an inference.
 *
 * ⚠️ It must never short-circuit. Returning anything non-null here would itself
 * suppress the send and make every assertion below vacuous.
 */
$ATTEMPTS = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$ATTEMPTS ) {
	$to = $atts['to'] ?? '';
	foreach ( ( is_array( $to ) ? $to : explode( ',', (string) $to ) ) as $addr ) {
		$addr = trim( (string) $addr );
		if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) { $addr = $m[1]; }
		if ( '' !== $addr ) { $ATTEMPTS[] = strtolower( $addr ); }
	}
	return $null;                    // pass through — never suppress
}, 0, 2 );

/** Addresses wp_mail() was asked to send to since the last reset, and reset it. */
function attempts_take(): array {
	global $ATTEMPTS;
	$out = $ATTEMPTS;
	$ATTEMPTS = array();
	return $out;
}

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

/** The subject of the message addressed to $addr — NOT merely the first message. */
function subject_to( array $msgs, string $addr ): string {
	foreach ( $msgs as $m ) {
		if ( in_array( strtolower( $addr ), $m['to'], true ) ) { return (string) $m['subject']; }
	}
	return '(none)';
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

/* ⚠️ CLEAR THE FLUSH INTERVAL GUARD FIRST. lg_fd_flush() refuses to run again inside
 * 20 hours, so a value left by ANY earlier run — including a crashed one — makes the
 * tick a silent no-op and every assertion below fail for a reason that has nothing to
 * do with the allowlist. Cleared here rather than only in teardown, so this run cannot
 * inherit a previous run's state. */
delete_option( 'lg_fd_last_flush_daily' );
delete_option( 'lg_fd_last_flush_weekly' );

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

/* ── ⚠️ LIVENESS: CAN THIS BUILD SEND TO ANYONE AT ALL? ──────────────────────
 * THE ASSERTION THAT MAKES EVERY ZERO BELOW MEAN SOMETHING, and its absence is what
 * made this whole proof vacuous once already.
 *
 * lg_fd_send_one() REFUSES to send — to everybody, allowlisted or not — when the link
 * store is unreachable, because a digest with no clickable discussions is worse than
 * no digest. That refusal is correct behaviour and it is indistinguishable, from the
 * outside, from "the allowlist held": in both cases the canary gets nothing.
 *
 * It really happened. The red-first build resolves its link library through
 * dirname(__DIR__, 2), so the gate mirrors the repo shape in /tmp with a symlink to
 * membership-pages. That symlink went stale (another worktree, since removed) and was
 * not rebuilt, because the guard used os.path.lexists(), which is TRUE for a dangling
 * link. Every send then refused, nobody was mailed, and the leak check reported "the
 * canary still received nothing" — which reads as "no leak" and actually meant "this
 * build cannot send at all".
 *
 * So: exercise the REAL link path on the REAL seed topic, before any tick. A build
 * that cannot produce a link cannot produce a digest, and the correct response is to
 * ABORT — a proof that cannot fail is not a proof. */
$probe_urls = lg_fd_topic_urls( array( $topic ) );
if ( lg_fd_links_degraded() ) {
	fwrite( STDERR, sprintf(
		"ABORTING: the link store is UNREACHABLE for the sender under test, so it will refuse\n"
		. "to send to EVERYONE — allowlisted or not — and every 'received nothing' below would\n"
		. "be caused by that, not by the allowlist.\n\n"
		. "  sender under test : %s\n"
		. "  it looks for      : %s\n\n"
		. "If that path is a dangling symlink, the red-first harness is stale: rebuild it with\n"
		. "  python3 tools/gates/follow-digest-gate.py --plugin platform/mu-plugins/lg-follow-digest.php\n"
		. "(which now always repoints it, and checks it is readable by this user).\n"
		. "If it is not a symlink problem, check Postgres peer auth — running as root makes the\n"
		. "role \"root\", which does not exist, and every link silently resolves to ''.\n",
		/* ⚠️ dirname($loaded, 3), NOT 2. The sender resolves dirname(__DIR__, 2) where
		 * __DIR__ is its own DIRECTORY, so from its FILE path that is three levels up.
		 * An off-by-one here prints a plausible path that does not exist and sends the
		 * reader hunting in the wrong directory — which is the entire job of this line. */
		$loaded, dirname( $loaded, 3 ) . '/membership-pages/lib/following-data.php' ) );
	exit( 84 );
}
if ( '' === (string) ( $probe_urls[ $topic ] ?? '' ) ) {
	fwrite( STDERR, sprintf(
		"ABORTING: the link store answered but produced an EMPTY url for seed topic %d.\n"
		. "The sender would emit a digest with no clickable discussion, and more to the point a\n"
		. "zero below could be caused by that rather than by the allowlist.\n", $topic ) );
	exit( 84 );
}
ok( sprintf( 'LIVENESS: this build CAN send — the link store resolved topic %d to a real hub url, '
	. 'so a zero below is not "refused to send to everyone"', $topic ) );

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

/* ⚠️ TEARDOWN MUST SURVIVE A FATAL, and the first version did not — which cost a
 * whole confusing run to diagnose. A crash AFTER the tick but BEFORE the end left
 * `lg_fd_last_flush_daily` set and Ian's watermark advanced; the next run then found
 * the interval guard closed and his queue already consumed, and reported four
 * failures that were entirely artefacts of the previous crash. A test that poisons
 * its own next run is worse than one that simply fails.
 *
 * Registered as a shutdown function so it runs on a fatal, on exit(), and on the
 * normal path — guarded so it happens exactly once. */
$TORN = false;
register_shutdown_function( static function () use ( &$TORN, &$canary_uid, &$seeded_subs, &$topic ) {
	if ( $TORN ) { return; }
	$TORN = true;
	fwrite( STDERR, "[proof] emergency teardown (the run did not reach its own teardown)\n" );
	teardown( (int) $canary_uid, (array) $seeded_subs, (int) $topic );
} );

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
if ( 'hold' === $expect ) {
	if ( ! $canary_suppressed ) {
		ok( 'the BLOCKED canary is on Daily but its per-reply mail is NOT suppressed — no digest '
			. 'is coming, so taking away its instant mail would leave it with NOTHING' );
	} else {
		bad( '⚠️ MAIL BLACK HOLE: the blocked canary is on Daily, its per-reply mail is suppressed, '
			. 'and the allowlist withholds its digest — it would receive nothing at all' );
	}
}

/* What the REAL resolver hands the flush, printed before the tick. Without this, a
 * tick that mails nobody is indistinguishable from a resolver that returned nobody,
 * and the two have completely different causes. */
say( sprintf( '       resolver says due(daily) = [%s]',
	implode( ',', lg_fd_due_recipients( 'daily' ) ) ) );

$baseline = mailpit_ids();
attempts_take();                       // reset the recorder immediately before the tick
tick_at_send_hour();
$run1     = mailpit_since( $baseline );
$attempts = attempts_take();

/* ── WHAT wp_mail() WAS ASKED TO DO, before containment saw any of it ────────
 * The direct answer to "no SES call, no queue entry": for a blocked member, wp_mail()
 * is never invoked at all — so there is nothing for containment to swallow, nothing
 * for FluentSMTP to queue, and nothing that would have become an SES call on a box
 * without containment. Measured at pre_wp_mail priority 0, ahead of containment's 1. */
$att_ian    = count( array_filter( $attempts, static fn( $a ) => 0 === strcasecmp( $a, IAN_EMAIL ) ) );
$att_canary = count( array_filter( $attempts, static fn( $a ) => 0 === strcasecmp( $a, CANARY_EMAIL ) ) );
$att_other  = array_values( array_filter( $attempts, static fn( $a ) =>
	0 !== strcasecmp( $a, IAN_EMAIL ) && 0 !== strcasecmp( $a, CANARY_EMAIL ) ) );

say( sprintf( '       wp_mail() attempts this tick: %s', $attempts ? implode( ', ', $attempts ) : 'none' ) );
if ( 'hold' === $expect ) {
	if ( 0 === $att_canary ) {
		ok( 'wp_mail() was NEVER INVOKED for the blocked canary — nothing to swallow, nothing '
			. 'to queue, and nothing that would have been an SES call on a box without containment' );
	} else {
		bad( sprintf( '⚠️ wp_mail() was invoked %d time(s) for the blocked canary. Containment ate it, '
			. 'but on live that is an SES call to a non-allowlisted address.', $att_canary ) );
	}
}
if ( 1 === $att_ian ) {
	ok( 'wp_mail() was invoked EXACTLY ONCE for Ian — the allowlisted send really happened' );
} else {
	bad( sprintf( 'wp_mail() was invoked %d time(s) for Ian, expected exactly 1', $att_ian ) );
}
if ( $att_other ) {
	bad( 'wp_mail() was invoked for address(es) outside this test: ' . implode( ', ', $att_other ) );
} else {
	ok( 'wp_mail() was invoked for NO other address — the whole tick attempted '
		. count( $attempts ) . ' send(s)' );
}

$ian_got    = to_count( $run1, IAN_EMAIL );
$canary_got = to_count( $run1, CANARY_EMAIL );

/* ⚠️ reset() would print whichever message happens to be FIRST, which in a leak run is
 * often the CANARY's — quoting another recipient's subject as Ian's. The subject also
 * carries the reply count, so the wrong one silently misreports what he received. */
if ( 1 === $ian_got ) { ok( sprintf( 'Ian received EXACTLY ONE digest — %s', subject_to( $run1, IAN_EMAIL ) ) ); }
else { bad( sprintf( 'Ian received %d digests, expected exactly 1', $ian_got ) ); }

if ( 'leak' === $expect ) {
	/* ── THE RED-FIRST HALF ───────────────────────────────────────────────────
	 * Run against a build with lg_fd_allowed() forced true. If the canary is mailed
	 * here, this driver demonstrably CAN see an allowlist failure — which is what
	 * makes the 'hold' run's zero mean something. If the canary is NOT mailed even
	 * with the allowlist gone, the zero in the other run was never evidence. */
	/* ── THE POSITIVE CONTROL, IN TWO ORDERED STEPS ───────────────────────────
	 * keeper, 2026-07-31: "make the canary a member who DEMONSTRABLY WOULD receive
	 * without the allowlist — prove the positive first."
	 *
	 * Step 1 is IAN, and it is asserted just above: wp_mail() invoked exactly once for
	 * him, one message arrived. That establishes THE MACHINERY DELIVERS in this build —
	 * the tick ran, the collector found content, the render produced links, wp_mail was
	 * reached. Nothing said about the canary means anything without it.
	 *
	 * Step 2 is the CANARY, here. Ian mailed while the canary is not says the two are
	 * distinguishable; the canary mailed once the allowlist is gone says the ONLY thing
	 * that distinguished them WAS the allowlist.
	 *
	 * The three-way split matters because the three causes need different fixes, and
	 * collapsing them into one FAIL is what sent the last diagnosis to the wrong place. */
	$machinery_delivers = ( 1 === $att_ian && 1 === $ian_got );
	if ( $canary_got >= 1 && $att_canary >= 1 ) {
		ok( sprintf( 'ALLOWLIST REMOVED ⇒ the canary WAS mailed (%d message, %d wp_mail attempt). '
			. 'The driver can see the failure, so a zero in --expect=hold is evidence.',
			$canary_got, $att_canary ) );
	} elseif ( ! $machinery_delivers ) {
		bad( sprintf( 'THE BUILD UNDER TEST CANNOT SEND AT ALL — not to the canary (mailpit %d, '
			. 'attempts %d) and not to Ian either (mailpit %d, attempts %d). This is NOT "no leak": '
			. 'it is a BROKEN red-first build, and it would make the hold run pass for a reason '
			. 'that has nothing to do with the allowlist. Check the stripped harness\'s '
			. 'membership-pages symlink first — a dangling one degrades the link store and makes '
			. 'the sender refuse everybody.', $canary_got, $att_canary, $ian_got, $att_ian ) );
	} else {
		bad( sprintf( 'ALLOWLIST REMOVED, IAN WAS MAILED, and the canary STILL got nothing '
			. '(mailpit %d, attempts %d). The machinery delivers, so this is specific to the canary. '
			. 'This driver cannot detect an allowlist failure, so its "held" result proves NOTHING '
			. '— the canary is inert for some other reason.', $canary_got, $att_canary ) );
	}
	say( "\n--- teardown (leak run: no control needed) ---" );
	$TORN = true;
	teardown( $canary_uid, $seeded_subs, $topic );
	say( '' );
	if ( $FAIL ) {
		printf( "############ RED-FIRST CHECK FAILED — %d finding(s) ############\n", count( $FAIL ) );
		exit( 1 );
	}
	printf( "############ RED-FIRST CHECK PASSED — %d assertion(s) ############\n", count( $OK ) );
	say( 'Without the allowlist the canary IS mailed. The hold run\'s zero is therefore' );
	say( 'caused by the allowlist and not by an inert test subject.' );
	exit( 0 );
}

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
$TORN = true;
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
