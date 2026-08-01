<?php
/**
 * CADENCE, END TO END — "a member sets Daily, the sender honours it, and changing it
 * changes the next send."
 *
 * keeper, 2026-08-01, item 4. Everything the gate asserts about cadence up to now is
 * about SHAPES: the meta key exists, the resolver is callable, no member holds a value
 * outside the allow-list. None of it exercises the loop a member actually experiences,
 * and on dev2 those assertions are VACUOUS anyway — nobody holds a cadence, so
 * "resolver answers" and "every due recipient is allowlisted" are satisfied by an empty
 * store. (They now say so out loud; see vac() in the gate.) This drives the real thing.
 *
 * ── THE CLAIM CHAIN, AND WHY EACH LINK NEEDS ITS OWN CONTROL ──────────────────
 * The failure this lane keeps hitting is a zero that means something other than what it
 * appears to mean. "The member received nothing" is equally produced by: a member who
 * never qualified, a build that cannot render a link and refuses everybody, a flush
 * interval guard left set by a crashed run, an allowlist blocking them, and a watermark
 * stamped to now. FIVE causes, one observation. So every negative below is paired with
 * a positive on the SAME member, the SAME topic and the SAME tick, where the only thing
 * that changed is the cadence.
 *
 * ── WHAT IS SEEDED ───────────────────────────────────────────────────────────
 * One canary member at an RFC 2606 .invalid address, subscribed to one REAL dev2 topic
 * with real recent replies. The allowlist is pointed at THE CANARY ALONE — not at Ian —
 * so this proof cannot mail a person even if every guard in it fails at once. Torn down
 * at the end, including on a fatal.
 *
 * ⚠️ THE WATERMARK IS BACKDATED BETWEEN TICKS, DELIBERATELY. lg_fd_set_cadence() stamps
 * it to NOW on entry to a batched cadence (the flood guard), which correctly means the
 * very next flush has nothing to send. Backdating simulates the passage of a day — the
 * one thing a test of a daily digest cannot wait for — and each backdate is asserted
 * rather than assumed.
 *
 * Run:
 *   python3 tools/gates/follow-digest-gate.py --plugin platform/mu-plugins/lg-follow-digest.php \
 *           --prove-cadence
 */

declare( strict_types=1 );

const CANARY_EMAIL = 'follow-digest-cadence@dev2.invalid';
const CANARY_LOGIN = 'lg-fd-cadence-canary';

/** Far enough back to catch real late-July dev2 replies. */
const BACKDATE = '2026-07-25 00:00:00';

/* ── BOOT WORDPRESS WITH THIS BRANCH'S SENDER IN FRONT ────────────────────────
 * Same reasoning as the allowlist proof: without WPMU_PLUGIN_DIR the docroot symlink
 * wins and this tests main, which is a different sender. And run as looth-dev, not
 * root — Postgres peer auth means root resolves no hub links, so every digest would
 * render linkless while looking normal. */
$mu = (string) ( getenv( 'LG_FD_MU_DIR' ) ?: '' );
if ( '' !== $mu ) {
	if ( ! is_dir( $mu ) ) {
		fwrite( STDERR, "LG_FD_MU_DIR=$mu is not a directory.\n" );
		exit( 64 );
	}
	define( 'WPMU_PLUGIN_DIR', $mu );
}
$wp_load = ( getenv( 'LG_FD_WP_LOAD' ) ?: '/var/www/dev/wp-load.php' );
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
function ok( string $s ): void  { global $OK;   $OK[] = $s;   printf( "  ok   %s\n", $s ); }
function bad( string $s ): void { global $FAIL; $FAIL[] = $s; printf( "  FAIL %s\n", $s ); }
function say( string $s ): void { printf( "%s\n", $s ); }

/* ── THE ATTEMPT RECORDER, at priority 0 — ahead of containment's 1 ───────────
 * Measures what wp_mail() was ASKED to do, not what survived. dev2 containment returns
 * true on a swallowed send, so "wp_mail returned true" proves nothing; this records the
 * attempt itself and passes the mail through untouched. It must never short-circuit —
 * returning non-null here would suppress the send and make every assertion vacuous. */
$ATTEMPTS = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$ATTEMPTS ) {
	$to = $atts['to'] ?? '';
	foreach ( ( is_array( $to ) ? $to : explode( ',', (string) $to ) ) as $addr ) {
		$addr = trim( (string) $addr );
		if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) { $addr = $m[1]; }
		if ( '' !== $addr ) { $ATTEMPTS[] = strtolower( $addr ); }
	}
	return $null;
}, 0, 2 );

function attempts_take(): array {
	global $ATTEMPTS;
	$out = $ATTEMPTS;
	$ATTEMPTS = array();
	return $out;
}

/** Clear the interval guard, then flush. lg_fd_flush() refuses to run twice inside its
 *  window, so without this every tick after the first is a silent no-op and every
 *  assertion downstream fails for a reason that has nothing to do with cadence. */
function tick( string $cadence ): array {
	delete_option( 'lg_fd_last_flush_' . $cadence );
	attempts_take();                       // discard anything from earlier steps
	lg_fd_flush( $cadence, 0 );
	return attempts_take();
}

/** Backdate the watermark so there is something to batch, and PROVE it took. */
function backdate_watermark( int $uid ): bool {
	update_user_meta( $uid, LG_FD_WATERMARK_META, BACKDATE );
	return BACKDATE === (string) get_user_meta( $uid, LG_FD_WATERMARK_META, true );
}

// ─────────────────────────────────────────────────────────────────────────────
// PREREQUISITES. Every one of these, unmet, turns the whole run into a vacuous pass.
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- prerequisites ---" );

if ( ! defined( 'LG_FOLLOW_DIGEST_ENABLED' ) || ! LG_FOLLOW_DIGEST_ENABLED ) {
	fwrite( STDERR, "LG_FOLLOW_DIGEST_ENABLED is OFF. The resolver returns array() on the\n"
		. "first line, so every assertion below would pass vacuously. Re-run with\n"
		. "LG_FOLLOW_DIGEST=1.\n" );
	exit( 66 );
}
ok( 'the sender is ENABLED — the resolver is not short-circuiting on the flag' );

foreach ( array( 'lg_fd_set_cadence', 'lg_fd_cadence', 'lg_fd_due_recipients',
                 'lg_fd_flush', 'lg_fd_suppress_instant', 'lg_fd_items_for' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		fwrite( STDERR, "$fn() missing — wrong build loaded.\n" );
		exit( 67 );
	}
}
ok( 'the sender under test exposes the whole cadence path (6 functions)' );

// ─────────────────────────────────────────────────────────────────────────────
// SEED
// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- seeding (dev2 only; torn down at the end) ---" );

global $wpdb;
$subs_table = $wpdb->prefix . 'bb_notifications_subscriptions';

$existing   = get_user_by( 'login', CANARY_LOGIN );
$canary_uid = $existing ? (int) $existing->ID : 0;
if ( ! $canary_uid ) {
	$canary_uid = wp_insert_user( array(
		'user_login' => CANARY_LOGIN, 'user_email' => CANARY_EMAIL,
		'user_pass'  => wp_generate_password( 32 ),
		'display_name' => 'Follow-digest cadence canary', 'role' => 'subscriber',
	) );
	if ( is_wp_error( $canary_uid ) ) {
		fwrite( STDERR, 'could not create the canary: ' . $canary_uid->get_error_message() . "\n" );
		exit( 80 );
	}
	$canary_uid = (int) $canary_uid;
}
ok( sprintf( 'canary uid %d <%s> — exists only for this run', $canary_uid, CANARY_EMAIL ) );

/* ⚠️ THE ALLOWLIST IS POINTED AT THE CANARY ALONE, and this is a safety property, not
 * a convenience. To prove the sender HONOURS Daily something must actually be mailed,
 * so the subject of the test has to be deliverable. Pointing it at the canary's
 * .invalid address means the one address this run can reach is one that cannot exist —
 * and Ian is NOT on it, so this proof can never mail him by accident either. */
putenv( sprintf( 'LG_FOLLOW_DIGEST_ALLOWLIST=%d:%s', $canary_uid, CANARY_EMAIL ) );
unset( $_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] );

if ( ! lg_fd_allowed( $canary_uid, CANARY_EMAIL ) ) {
	fwrite( STDERR, "the canary is NOT allowlisted, so every zero below would be the\n"
		. "allowlist rather than the cadence. Aborting.\n" );
	exit( 68 );
}
ok( 'the canary IS allowlisted — so a zero below is caused by cadence, not by the allowlist' );

$a = lg_fd_allowlist();
if ( in_array( 1, (array) $a['uids'], true ) || 'all' === $a['mode'] ) {
	fwrite( STDERR, "the allowlist reaches uid 1 or is 'all'. This proof must be able to\n"
		. "mail ONLY the .invalid canary. Aborting.\n" );
	exit( 69 );
}
ok( sprintf( 'the allowlist admits ONLY the canary — uids %s, so no real address is reachable',
	json_encode( $a['uids'] ) ) );

/** A real dev2 topic with real recent replies, chosen by row count so this keeps
 *  working as the box's data changes. Excludes the canary's own replies, as the
 *  collector does. */
$topic = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT r.post_parent FROM {$wpdb->posts} r
	  WHERE r.post_type='reply' AND r.post_status='publish' AND r.post_date_gmt > %s
	    AND r.post_author <> %d
	  GROUP BY r.post_parent ORDER BY COUNT(*) DESC LIMIT 1", BACKDATE, $canary_uid ) );
if ( ! $topic ) {
	fwrite( STDERR, 'no dev2 topic has replies since ' . BACKDATE . " — nothing to batch.\n" );
	exit( 81 );
}
say( sprintf( '       seed topic %d — %s', $topic, (string) get_the_title( $topic ) ) );

$wpdb->delete( $subs_table, array( 'user_id' => $canary_uid ) );
$wpdb->insert( $subs_table, array(
	'blog_id' => get_current_blog_id(), 'user_id' => $canary_uid,
	'type' => 'topic', 'item_id' => $topic, 'secondary_item_id' => 0,
	'status' => 1, 'date_recorded' => current_time( 'mysql', true ),
) );
ok( sprintf( 'canary is subscribed to topic %d (the ✉ bit the collector reads)', $topic ) );

/* ── TEARDOWN, registered BEFORE the first assertion ──────────────────────────
 * A fatal between here and the end must not leave a member holding a cadence on this
 * box — that is the exact state the gate reds on, and a crashed proof would be
 * indistinguishable from a real defect. */
$TEARDOWN_DONE = false;
$teardown = static function () use ( $canary_uid, $subs_table, &$TEARDOWN_DONE ) {
	if ( $TEARDOWN_DONE ) { return; }
	$TEARDOWN_DONE = true;
	global $wpdb;
	delete_user_meta( $canary_uid, LG_FD_CADENCE_META );
	delete_user_meta( $canary_uid, LG_FD_WATERMARK_META );
	$wpdb->delete( $subs_table, array( 'user_id' => $canary_uid ) );
	delete_option( 'lg_fd_last_flush_daily' );
	delete_option( 'lg_fd_last_flush_weekly' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $canary_uid );
};
register_shutdown_function( $teardown );

// Start from a known state.
delete_user_meta( $canary_uid, LG_FD_CADENCE_META );
delete_user_meta( $canary_uid, LG_FD_WATERMARK_META );

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C1. the default is INSTANT, and instant is not a digest bucket ---" );
// ─────────────────────────────────────────────────────────────────────────────

$c = lg_fd_cadence( $canary_uid );
if ( 'instant' === $c ) {
	ok( "a member with NO stored cadence reads as 'instant' — absent means instant, so "
		. 'nobody is suppressed and nobody is batched' );
} else {
	bad( "a member with no stored cadence read as '$c', not 'instant'" );
}

$daily  = lg_fd_due_recipients( 'daily' );
$weekly = lg_fd_due_recipients( 'weekly' );
if ( ! in_array( $canary_uid, $daily, true ) && ! in_array( $canary_uid, $weekly, true ) ) {
	ok( 'an instant member is in NEITHER digest bucket — they are served by the native '
		. 'per-reply path and must never get the same reply twice' );
} else {
	bad( 'an instant member appeared in a digest bucket' );
}

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C2. setting Daily writes the cadence AND stamps the flood guard ---" );
// ─────────────────────────────────────────────────────────────────────────────

$before = time();
if ( ! lg_fd_set_cadence( $canary_uid, 'daily' ) ) {
	bad( 'lg_fd_set_cadence(daily) refused' );
} else {
	ok( 'lg_fd_set_cadence(daily) accepted — this is the ONE write path both UI '
		. 'surfaces call; neither may touch the usermeta key directly' );
}

$c = lg_fd_cadence( $canary_uid );
if ( 'daily' === $c ) {
	ok( "the store reads back 'daily'" );
} else {
	bad( "the store reads back '$c' after setting daily" );
}

/* THE SINGLE MOST DANGEROUS LINE IN THE DESIGN NOTE (§4.3). With no watermark the
 * first digest reads from the beginning of time — the entire reply history of every
 * thread they follow, unrecallable. One live account holds 335 subscriptions. */
$wm = (string) get_user_meta( $canary_uid, LG_FD_WATERMARK_META, true );
$wm_ts = $wm ? strtotime( $wm . ' UTC' ) : 0;
if ( ! $wm ) {
	bad( 'THE FLOOD: cadence written with NO watermark — the first digest would be a '
		. 'complete backfill' );
} elseif ( abs( $wm_ts - $before ) > 300 ) {
	bad( sprintf( 'the watermark is %s, which is %d seconds from now — it must be NOW, '
		. 'never epoch. A digest is never a backfill.', $wm, $wm_ts - $before ) );
} else {
	ok( sprintf( 'the watermark was stamped to NOW (%s, within %ds) — the flood guard '
		. 'ran on the transition INTO a batched cadence', $wm, abs( $wm_ts - $before ) ) );
}

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C3. the resolver moves them into the daily bucket, and only that one ---" );
// ─────────────────────────────────────────────────────────────────────────────

$daily  = lg_fd_due_recipients( 'daily' );
$weekly = lg_fd_due_recipients( 'weekly' );
if ( in_array( $canary_uid, $daily, true ) ) {
	ok( 'the canary is now DUE for daily — the resolver reflects the member\'s choice' );
} else {
	bad( 'the canary set Daily and the daily resolver does not return them' );
}
if ( ! in_array( $canary_uid, $weekly, true ) ) {
	ok( 'and is NOT due for weekly — one cadence at a time' );
} else {
	bad( 'the canary is due for BOTH daily and weekly' );
}

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C4. the flood guard, demonstrated: a fresh watermark sends NOTHING ---" );
// ─────────────────────────────────────────────────────────────────────────────
// This zero is the guard working. It is asserted BEFORE the positive so that the
// positive (C5) is what proves this zero was the watermark and not an inert member.

$sent = tick( 'daily' );
if ( ! in_array( strtolower( CANARY_EMAIL ), $sent, true ) ) {
	ok( 'with the watermark at NOW, the daily flush mailed the canary NOTHING — there '
		. 'are no replies newer than the moment they subscribed, and EMPTY MEANS SEND '
		. 'NOTHING rather than an empty digest' );
} else {
	bad( 'a member whose watermark is NOW received a digest — that is a backfill' );
}

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C5. THE POSITIVE CONTROL: backdate the watermark and the digest arrives ---" );
// ─────────────────────────────────────────────────────────────────────────────
// Without this, C4's zero is worthless: a build that cannot send at all produces the
// identical observation. Same member, same topic, same tick — only the watermark moved.

if ( ! backdate_watermark( $canary_uid ) ) {
	bad( 'could not backdate the watermark — C4 above is therefore UNPROVEN' );
} else {
	ok( sprintf( 'watermark backdated to %s (simulating a day passing)', BACKDATE ) );

	$batch = lg_fd_items_for( $canary_uid );
	if ( $batch['items'] ) {
		ok( sprintf( 'the collector now finds %d reply(ies) in the followed topic — so '
			. 'this build CAN produce a non-empty batch', count( $batch['items'] ) ) );
	} else {
		bad( 'the collector found nothing even after backdating — the seed topic has no '
			. 'replies the collector accepts, so C4 proves nothing' );
	}

	$sent = tick( 'daily' );
	if ( in_array( strtolower( CANARY_EMAIL ), $sent, true ) ) {
		ok( 'THE DAILY FLUSH MAILED THE CANARY — so C4\'s zero was caused by the '
			. 'watermark, not by an inert member or a build that cannot send' );
	} else {
		bad( 'the daily flush did NOT mail a member who is due, allowlisted and has '
			. 'content — the instrument reads zero in both directions, so C4 is vacuous' );
	}
	if ( 1 === count( array_filter( $sent, static fn( $x ) => $x === strtolower( CANARY_EMAIL ) ) ) ) {
		ok( 'exactly ONE message — no double send' );
	} else {
		bad( sprintf( 'the canary was mailed %d times in one tick',
			count( array_filter( $sent, static fn( $x ) => $x === strtolower( CANARY_EMAIL ) ) ) ) );
	}
	foreach ( array_unique( $sent ) as $addr ) {
		if ( $addr !== strtolower( CANARY_EMAIL ) ) {
			bad( sprintf( 'the tick also mailed %s — an address outside the allowlist', $addr ) );
		}
	}
}

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C6. CHANGING THE CADENCE CHANGES THE NEXT SEND ---" );
// ─────────────────────────────────────────────────────────────────────────────
// The headline claim. Both directions on the same member: daily stops, weekly starts.

if ( ! lg_fd_set_cadence( $canary_uid, 'weekly' ) ) {
	bad( 'lg_fd_set_cadence(weekly) refused' );
} else {
	ok( 'the member changes their mind: Daily -> Weekly through the same write path' );
}

$daily  = lg_fd_due_recipients( 'daily' );
$weekly = lg_fd_due_recipients( 'weekly' );
if ( ! in_array( $canary_uid, $daily, true ) ) {
	ok( 'they have LEFT the daily bucket' );
} else {
	bad( 'they are still due for daily after switching to weekly' );
}
if ( in_array( $canary_uid, $weekly, true ) ) {
	ok( 'and ENTERED the weekly bucket' );
} else {
	bad( 'they did not enter the weekly bucket' );
}

/* The switch re-stamped the watermark (cadence !== was), which is correct — but it
 * means there is nothing to send again, so backdate before asserting delivery. */
if ( ! backdate_watermark( $canary_uid ) ) {
	bad( 'could not backdate the watermark before the C6 ticks' );
} else {
	$sent_daily = tick( 'daily' );
	if ( ! in_array( strtolower( CANARY_EMAIL ), $sent_daily, true ) ) {
		ok( 'THE NEXT DAILY TICK MAILS THEM NOTHING — the change took effect' );
	} else {
		bad( 'a weekly member was mailed by the daily flush' );
	}

	$sent_weekly = tick( 'weekly' );
	if ( in_array( strtolower( CANARY_EMAIL ), $sent_weekly, true ) ) {
		ok( 'THE WEEKLY TICK MAILS THEM — so the daily zero above is the cadence change, '
			. 'not a member who stopped qualifying' );
	} else {
		bad( 'the weekly flush did not mail a member who is due for weekly with content — '
			. 'the daily zero above is therefore unproven' );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
say( "\n--- C7. instant restores per-reply mail, and clears the watermark ---" );
// ─────────────────────────────────────────────────────────────────────────────
// suppressed ⟺ served. A member the digest will not serve must never be made quieter.

$args = array( 'recipient_user_id' => $canary_uid );
$suppressed_while_weekly = ( false === lg_fd_suppress_instant( true, $args ) );
if ( $suppressed_while_weekly ) {
	ok( 'while on Weekly their per-reply mail IS suppressed — the digest replaces it' );
} else {
	bad( 'a batched member still gets per-reply mail — they would receive both' );
}

if ( ! lg_fd_set_cadence( $canary_uid, 'instant' ) ) {
	bad( 'lg_fd_set_cadence(instant) refused' );
} else {
	ok( 'the member switches back to Instant' );
}

if ( true === lg_fd_suppress_instant( true, $args ) ) {
	ok( 'their per-reply mail is NO LONGER suppressed — the filter is the identity '
		. 'function for them again' );
} else {
	bad( 'an instant member\'s per-reply mail is still suppressed — a mail black hole: '
		. 'no digest is coming and their normal mail was taken away' );
}

if ( '' === (string) get_user_meta( $canary_uid, LG_FD_WATERMARK_META, true ) ) {
	ok( 'the watermark went WITH the cadence — no stale watermark to make a later '
		. 're-entry to Daily send a backdated flood' );
} else {
	bad( 'a stale watermark survived the switch to instant' );
}

$daily  = lg_fd_due_recipients( 'daily' );
$weekly = lg_fd_due_recipients( 'weekly' );
if ( ! in_array( $canary_uid, $daily, true ) && ! in_array( $canary_uid, $weekly, true ) ) {
	ok( 'and they are in NEITHER bucket — the loop closes where it started' );
} else {
	bad( 'an instant member is still in a digest bucket' );
}

// ─────────────────────────────────────────────────────────────────────────────
say( '' );
$teardown();
say( '--- torn down: canary deleted, meta cleared, flush guards reset ---' );

if ( $FAIL ) {
	printf( "\n############ CADENCE PROOF FAILED — %d finding(s) ############\n", count( $FAIL ) );
	foreach ( $FAIL as $f ) { printf( "  FAIL %s\n", $f ); }
	exit( 1 );
}
printf( "\n############ CADENCE PROOF PASSED — %d assertion(s) ############\n", count( $OK ) );
exit( 0 );
