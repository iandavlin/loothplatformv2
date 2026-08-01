<?php
/**
 * cadence-seam-proof.php — prove the cadence a member sets on /manage-subscription/
 * is the cadence the SENDER resolves, and prove the other write path is a black hole.
 *
 *   sudo -u looth-dev env LG_FOLLOW_DIGEST=1 LG_FD_MU_DIR=/tmp/lg-fd-gate-mu \
 *        php platform/bin/cadence-seam-proof.php
 *
 * account-following lane, 2026-08-01. Charter item 3: "ONE STORE, ONE WRITE PATH —
 * prove a set here is what the sender resolves."
 *
 * ── THIS FILE SENDS NO MAIL ──────────────────────────────────────────────────
 * Unlike follow-digest-allowlist-proof.php it never reaches wp_mail(). It asks two
 * questions about RESOLUTION only: what does lg_fd_cadence() return, and is this
 * member in lg_fd_due_recipients(). So it needs no containment guard — but it DOES
 * write usermeta, so it uses a canary it creates and destroys, and never touches a
 * real member's row.
 *
 * ── WHY IT HAS TWO ARMS, AND WHY ARM B IS THE POINT ──────────────────────────
 * There are two pieces of code in this repo that can write lg_disc_email_cadence:
 *
 *   ARM A  lg_fd_set_cadence()                       lg-follow-digest.php:288
 *          ← the ONLY path /manage-subscription/ uses, via lg_fd_ajax_set
 *   ARM B  update_user_meta(...,'lg_disc_email_cadence',...)   follow.php:212
 *          ← the raw write, reproduced here verbatim
 *
 * Both make lg_fd_cadence() return 'daily'. That is exactly why the bug is invisible
 * to any test that checks the stored value: BOTH ARMS PASS that check. The difference
 * is the WATERMARK, which arm A stamps and arm B does not, and which
 * lg_fd_due_recipients() requires (:512-521). So arm B's member is suppressed out of
 * instant mail (:494) and simultaneously refused a digest — they receive NOTHING.
 *
 *     ⚠️ AN ABSENCE PROVES NOTHING WITHOUT ITS CONTROL.
 *
 * "The arm-B canary was not due" is equally true of a canary that never qualified, of
 * an allowlist that excluded it, and of a box where the sender is off. Arm A IS that
 * control: same canary, same allowlist, same everything, one function call different,
 * and it comes out DUE. Arm A is what makes arm B's zero mean something.
 *
 * ── LIVENESS IS ASSERTED, NOT ASSUMED ────────────────────────────────────────
 * sudo strips the environment, so a run meant to exercise the flag-ON path can
 * silently exercise the OFF path and report a serene row of zeros. This file refuses
 * to run unless LG_FOLLOW_DIGEST_ENABLED is actually true in the booted process and
 * unless the canary is actually on the resolved allowlist.
 */

declare( strict_types=1 );

const CANARY_LOGIN = 'lg-cadence-seam-canary';
const CANARY_EMAIL = 'cadence-seam-canary@dev2.invalid';   // RFC 2606 .invalid — cannot resolve

/* ── BOOT, with this BRANCH's sender in front ─────────────────────────────────
 * Same mechanism and the same reason as follow-digest-allowlist-proof.php: without
 * LG_FD_MU_DIR the docroot symlink wins and this would prove something about main.
 * Run as looth-dev, not root (Postgres peer auth) — not needed here, but the same
 * habit, because a proof that runs as the wrong user is how the P0 happened. */
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
$prev = ini_get( 'display_errors' );
ini_set( 'display_errors', '0' );
require_once $wp_load;
ini_set( 'display_errors', (string) $prev );

$FAIL   = array();
$DEFECT = array();
function ok( string $s ): void  { printf( "  ok   %s\n", $s ); }
function bad( string $s ): void { global $FAIL; $FAIL[] = $s; printf( "  FAIL %s\n", $s ); }
/** A reproduced defect in a DEPENDENCY — distinct from a failed assertion about this
 *  lane's own seam, so a non-zero exit is never misread as "the harness is broken". */
function defect( string $s ): void { global $DEFECT; $DEFECT[] = $s; printf( "  ⚠ DEFECT %s\n", $s ); }
function say( string $s ): void { printf( "%s\n", $s ); }
function eq( string $what, $got, $want ): void {
	$g = is_bool( $got ) ? var_export( $got, true ) : (string) $got;
	$w = is_bool( $want ) ? var_export( $want, true ) : (string) $want;
	$g === $w ? ok( "$what = $g" ) : bad( "$what = $g, expected $w" );
}

say( "\n=== cadence seam: does the sender resolve what the account page set? ===\n" );

/* ── 0. LIVENESS ──────────────────────────────────────────────────────────── */
say( "[0] the machinery is actually live (else every zero below is vacuous)" );
foreach ( array( 'lg_fd_cadence', 'lg_fd_set_cadence', 'lg_fd_due_recipients',
                 'lg_fd_watermark', 'lg_fd_allowed', 'lg_fd_cadence_ui_enabled' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		bad( "$fn() is not loaded — the branch sender did not boot" );
		say( "\nCANNOT RUN: the sender is not in this process. Set LG_FD_MU_DIR.\n" );
		exit( 70 );
	}
}
ok( 'the branch sender is loaded' );
if ( ! defined( 'LG_FOLLOW_DIGEST_ENABLED' ) || ! LG_FOLLOW_DIGEST_ENABLED ) {
	bad( 'LG_FOLLOW_DIGEST_ENABLED is FALSE in this process' );
	say( "\nCANNOT RUN: lg_fd_due_recipients() returns array() unconditionally while the\n"
	   . "flag is off, so both arms would 'pass' by being empty. Re-run with\n"
	   . "  env LG_FOLLOW_DIGEST=1 …   (and note that sudo strips it)\n" );
	exit( 70 );
}
ok( 'LG_FOLLOW_DIGEST_ENABLED is true' );

/* ── 1. the canary ────────────────────────────────────────────────────────── */
say( "\n[1] canary" );
$uid = (int) ( username_exists( CANARY_LOGIN ) ?: 0 );
$made = false;
if ( $uid < 1 ) {
	$uid = wp_insert_user( array(
		'user_login' => CANARY_LOGIN,
		'user_email' => CANARY_EMAIL,
		'user_pass'  => wp_generate_password( 32, true, true ),
		'role'       => 'subscriber',
	) );
	if ( is_wp_error( $uid ) ) {
		bad( 'could not create the canary: ' . $uid->get_error_message() );
		exit( 70 );
	}
	$uid  = (int) $uid;
	$made = true;
}
ok( sprintf( 'canary uid=%d %s%s', $uid, CANARY_EMAIL, $made ? ' (created)' : ' (reused)' ) );

/* Teardown runs on ANY exit path, including a fatal — a proof that leaves a member
 * carrying a cadence has changed the very store it was measuring. */
register_shutdown_function( static function () use ( $uid, $made ) {
	delete_user_meta( $uid, LG_FD_CADENCE_META );
	delete_user_meta( $uid, LG_FD_WATERMARK_META );
	if ( $made ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $uid );
	}
	$left = (string) get_user_meta( $uid, LG_FD_CADENCE_META, true );
	printf( "\n  teardown: cadence meta now %s%s\n",
		'' === $left ? "'' (clean)" : "'$left' ⚠ NOT CLEAN",
		$made ? ', canary user deleted' : ', canary user kept (pre-existing)' );
} );

/* The allowlist must admit the canary or lg_fd_due_recipients() drops it for a
 * reason that has nothing to do with the watermark — which would make arm A fail and
 * arm B "pass" for the wrong reason, i.e. the exact confusion this file exists to
 * remove. Asserted, not assumed.
 *
 * SET HERE RATHER THAN PASSED IN, and pinned to exactly this canary. The uid does not
 * exist until the line above runs, so an operator cannot name it in advance — and the
 * alternatives are worse: 'all-members' in a proof script is a loaded gun, and reusing
 * the tracked allowlist would put a REAL member (Ian) in a run that writes cadence
 * meta. Pinning uid:email means this run can admit precisely one throwaway account and
 * nothing else, whatever the environment says. lg_fd_allowlist_raw() re-reads the
 * environment on every call, so this takes effect immediately and is not cached. */
putenv( 'LG_FOLLOW_DIGEST_ALLOWLIST=' . $uid . ':' . CANARY_EMAIL );
$u = get_userdata( $uid );
if ( ! lg_fd_allowed( $uid, (string) $u->user_email ) ) {
	bad( 'the canary is NOT on the resolved allowlist' );
	say( "\nCANNOT RUN: re-run with\n"
	   . "  env LG_FOLLOW_DIGEST_ALLOWLIST='$uid:" . CANARY_EMAIL . "' …\n"
	   . "Without it both arms come out 'not due' and the run proves nothing.\n" );
	exit( 70 );
}
ok( 'the canary is on the resolved allowlist' );

/** Is this member in the real due set the flush walks? */
$is_due = static function ( int $uid, string $cadence ): bool {
	return in_array( $uid, lg_fd_due_recipients( $cadence ), true );
};
/** Would BuddyBoss still mail them per-reply? Asked through the REAL filter. */
$gets_instant = static function ( int $uid ): bool {
	return (bool) apply_filters( 'bb_send_forums_subscribed_reply_email_notifications',
		true, array( 'recipient_user_id' => $uid ) );
};

/* ── 2. baseline ──────────────────────────────────────────────────────────── */
say( "\n[2] baseline — no cadence stored" );
delete_user_meta( $uid, LG_FD_CADENCE_META );
delete_user_meta( $uid, LG_FD_WATERMARK_META );
eq( 'lg_fd_cadence() with nothing stored', lg_fd_cadence( $uid ), 'instant' );
eq( 'instant mail still flows', $gets_instant( $uid ), true );
eq( 'not in the daily due set', $is_due( $uid, 'daily' ), false );

/* ── 3. ARM A — the path /manage-subscription/ actually uses ──────────────── */
say( "\n[3] ARM A — written through lg_fd_set_cadence() (what lg_fd_ajax_set calls)" );
say( "    this is the exact call the account page's write reaches" );
eq( 'the writer accepted "daily"', lg_fd_set_cadence( $uid, 'daily' ), true );
eq( 'THE SENDER RESOLVES WHAT THE PAGE SET', lg_fd_cadence( $uid ), 'daily' );
$wm_a = lg_fd_watermark( $uid );
'' !== $wm_a ? ok( "the flood guard stamped a watermark: $wm_a" )
             : bad( 'no watermark stamped — the flood guard did not run' );
eq( 'instant mail is now suppressed', $gets_instant( $uid ), false );
eq( 'AND THE MEMBER IS DUE A DAILY DIGEST', $is_due( $uid, 'daily' ), true );
eq( 'but not a weekly one', $is_due( $uid, 'weekly' ), false );

say( "\n    round trip: back to instant" );
eq( 'the writer accepted "instant"', lg_fd_set_cadence( $uid, 'instant' ), true );
eq( 'resolves as instant', lg_fd_cadence( $uid ), 'instant' );
eq( 'the stale watermark was cleared', lg_fd_watermark( $uid ), '' );
eq( 'instant mail flows again', $gets_instant( $uid ), true );

say( "\n    and it refuses what it cannot deliver" );
eq( 'hourly is refused', lg_fd_set_cadence( $uid, 'hourly' ), false );
eq( 'garbage is refused', lg_fd_set_cadence( $uid, 'daily; DROP' ), false );
eq( 'the refusal did not change the stored value', lg_fd_cadence( $uid ), 'instant' );

/* ── 4. ARM B — follow.php:212, reproduced verbatim ───────────────────────── */
say( "\n[4] ARM B — the OTHER write path: raw update_user_meta (follow.php:212)" );
say( "    same store, same key, same value. Only the writer differs." );
delete_user_meta( $uid, LG_FD_CADENCE_META );
delete_user_meta( $uid, LG_FD_WATERMARK_META );
update_user_meta( $uid, 'lg_disc_email_cadence', 'daily' );      // ← follow.php:212

eq( 'the value IS stored, and the sender reads it', lg_fd_cadence( $uid ), 'daily' );
say( '    ↑ this is why the bug is invisible: BOTH arms pass this check' );
eq( 'but NO watermark was stamped', lg_fd_watermark( $uid ), '' );
eq( 'instant mail is suppressed all the same', $gets_instant( $uid ), false );
$due_b = $is_due( $uid, 'daily' );
eq( 'and the member is NOT due a digest', $due_b, false );

if ( 'daily' === lg_fd_cadence( $uid ) && ! $gets_instant( $uid ) && ! $due_b ) {
	ok( 'BLACK HOLE REPRODUCED: instant suppressed AND no digest ⇒ this member '
	  . 'receives nothing at all, from a control they just used' );
} else {
	bad( 'the black hole did NOT reproduce — re-read this before trusting arm A' );
}

/* ── 5. THE REPAIR INVARIANT — and it does not hold ───────────────────────────
 * Fixing follow.php:212 stops NEW members falling in. It does not get the existing
 * ones out, and this phase is how that was discovered rather than assumed.
 *
 * A member left carrying a cadence with no watermark should be repairable by the
 * obvious act: set the cadence again, through the correct writer. It is not, because
 * the flood guard keys on a CADENCE TRANSITION, not on the watermark:
 *
 *     $was = lg_fd_cadence($uid);                    // already 'daily' (raw-written)
 *     update_user_meta(..., $cadence);               // 'daily'
 *     if ('instant' !== $cadence && $cadence !== $was) { …stamp… }   // false ⇒ no stamp
 *
 * So the member stays in the black hole, and — this is the part that reaches a
 * person — pressing "Daily" on /manage-subscription/ RETURNS ok:true AND CHANGES
 * NOTHING. The page repaints from the stored value, which is already 'daily', so the
 * UI is honest about the store and the store is still wrong. There is no sequence of
 * clicks that fixes it; only instant→daily happens to, by accident of the transition.
 *
 * THE FIX IS IN THE CONDITION, not in the callers — stamp when the watermark is
 * missing, whatever the previous cadence was:
 *
 *     if ('instant' !== $cadence && ($cadence !== $was || '' === lg_fd_watermark($uid)))
 *
 * That also makes lg_fd_set_cadence idempotent-safe and self-healing, which is what
 * the docblock at :279-285 already claims it is.
 *
 * ⚠️ THIS PHASE IS EXPECTED RED until follow-digest lands that condition. It is
 * reported as an OPEN DEFECT rather than a failed assertion so that nobody reads this
 * script's non-zero exit as "the harness is broken". */
say( "\n[5] THE REPAIR INVARIANT — can a member in the black hole get out?" );
say( "    (arm B left this row: cadence=daily, no watermark)" );
eq( 'the writer accepts the same cadence again', lg_fd_set_cadence( $uid, 'daily' ), true );
$wm_c   = lg_fd_watermark( $uid );
$due_c  = $is_due( $uid, 'daily' );
$healed = ( '' !== $wm_c ) && $due_c;

if ( $healed ) {
	ok( "re-setting the cadence REPAIRED the row — watermark $wm_c, member due" );
} else {
	defect( 'lg_fd_set_cadence() is NOT self-healing: re-setting the same cadence '
	      . 'stamps no watermark (the guard keys on a transition), so a member in the '
	      . 'black hole CANNOT click their way out — the write returns ok and does nothing' );
}

/* And the control: instant→daily still works, which is what proves the row itself was
 * fine and the guard's CONDITION is the whole defect. */
lg_fd_set_cadence( $uid, 'instant' );
lg_fd_set_cadence( $uid, 'daily' );
eq( 'control — a real transition still stamps', '' !== lg_fd_watermark( $uid ), true );
eq( 'control — and that member IS due', $is_due( $uid, 'daily' ), true );

/* ── verdict ──────────────────────────────────────────────────────────────── */
say( "\n" . str_repeat( '─', 74 ) );
if ( $FAIL ) {
	printf( "RED — %d assertion(s) failed:\n", count( $FAIL ) );
	foreach ( $FAIL as $f ) { printf( "  · %s\n", $f ); }
	exit( 1 );
}
say( "THE SEAM THIS LANE OWNS IS PROVEN:" );
say( "  · a set through lg_fd_set_cadence — the ONLY path /manage-subscription/" );
say( "    uses — is exactly what the sender resolves, and it makes the member due." );
say( "  · the raw path (follow.php:212) stores the same value and produces a mail" );
say( "    black hole, on the same row, with the same allowlist, one call apart." );
if ( $DEFECT ) {
	say( "" );
	printf( "%d OPEN DEFECT(S) in the shared writer — reported, not this lane's file:\n",
		count( $DEFECT ) );
	foreach ( $DEFECT as $d ) { printf( "  · %s\n", $d ); }
	say( "" );
	say( "Non-zero exit is CORRECT: the defect is real and still open." );
	exit( 2 );
}
say( "\nGREEN — and the repair invariant holds too." );
exit( 0 );
