<?php
/**
 * BACKFILL the first-activation marker for members who already hold a paid tier.
 *
 * ── THIS IS A LIVE WRITE AND IT IS NOT MINE TO RUN ──────────────────────────
 * Run by Ian (or keeper) on live. It writes user meta for ~1,225 members.
 *
 *   DRY RUN (default, writes nothing):
 *     wp --path=<docroot> eval-file tools/welcome-activation-backfill.php
 *
 *   APPLY:
 *     wp --path=<docroot> eval-file tools/welcome-activation-backfill.php apply
 *
 * The apply switch is a POSITIONAL ARG, deliberately not an env var: sudo strips
 * the environment, so an env-gated script run under sudo silently takes the wrong
 * branch — and on this box that has already turned a flag-ON run into an OFF one.
 * An arg cannot be lost that way.
 *
 * ── WHAT IT WRITES, AND WHY THE VALUE LOOKS LIKE THAT ───────────────────────
 * _lg_membership_activated_at = "backfill:<ISO8601>" for every member currently
 * holding looth2/3/4 who carries no marker. The value is PREFIXED so it can never
 * be misread as a real activation date: these members activated months or years
 * ago, and stamping a bare timestamp of today would make the field confidently
 * wrong for anyone who later reads it as "when did this member join".
 *
 * It sends NOTHING. It never touches _lg_pending_welcome, never calls
 * WelcomeMailer, and never changes a role.
 *
 * ── IT IS NOT THE GUARD, AND ORDER DOES NOT MATTER ──────────────────────────
 * The guard is the cutover fence in Arbiter::registeredAfterCutover: first
 * activation may only welcome an account registered AT OR AFTER the cutover, and
 * every existing paid member registered before it. So arming the flag cannot mail
 * an existing member EVEN IF THIS SCRIPT IS NEVER RUN. A backfill is something a
 * person has to remember to do, which makes it a plan rather than a guard — this
 * exists to make the state explicit and auditable, not to prevent the disaster.
 *
 * ── VERIFYING IT DID WHAT IT SAID ───────────────────────────────────────────
 * The script prints the counts itself, before and after. Two numbers settle it:
 * paid-members-carrying-a-marker should equal paid-members-total, and
 * welcome-emails-ever must be UNCHANGED (16 as of 2026-08-15). If the second
 * number moved, something mailed and that is an incident, not a backfill.
 */

$apply = isset( $args[0] ) && $args[0] === 'apply';

$paid_roles = array( 'looth2', 'looth3', 'looth4' );

$paid_ids = get_users( array(
	'role__in' => $paid_roles,
	'fields'   => 'ID',
	'number'   => -1,
) );

$marked    = array();
$unmarked  = array();
foreach ( $paid_ids as $uid ) {
	$m = (string) get_user_meta( (int) $uid, '_lg_membership_activated_at', true );
	if ( $m === '' ) {
		$unmarked[] = (int) $uid;
	} else {
		$marked[] = (int) $uid;
	}
}

// The number that must NOT move. Counted the same way before and after.
$mail_stamps = count( get_users( array(
	'fields'       => 'ID',
	'number'       => -1,
	'meta_key'     => '_lg_welcome_email_sent_at',
	'meta_compare' => 'EXISTS',
) ) );

echo "WELCOME-ACTIVATION BACKFILL — " . ( $apply ? "APPLY" : "DRY RUN (nothing will be written)" ) . "\n";
echo str_repeat( '=', 70 ) . "\n";
echo "paid members (looth2/3/4)        : " . count( $paid_ids ) . "\n";
echo "  already carrying a marker      : " . count( $marked ) . "\n";
echo "  WOULD BE STAMPED by this run   : " . count( $unmarked ) . "\n";
echo "welcome emails ever (must not move): {$mail_stamps}\n";

if ( ! $apply ) {
	echo "\nDRY RUN — nothing written. Re-run with the literal arg 'apply' to write.\n";
	return;
}

$stamp   = 'backfill:' . gmdate( 'c' );
$written = 0;
foreach ( $unmarked as $uid ) {
	if ( update_user_meta( $uid, '_lg_membership_activated_at', $stamp ) ) {
		$written++;
	}
}

// Re-count from the database rather than trusting the loop counter: the whole
// point of the exercise is the state, not what the script believes it did.
$still_unmarked = 0;
foreach ( $paid_ids as $uid ) {
	if ( (string) get_user_meta( (int) $uid, '_lg_membership_activated_at', true ) === '' ) {
		$still_unmarked++;
	}
}
$mail_after = count( get_users( array(
	'fields'       => 'ID',
	'number'       => -1,
	'meta_key'     => '_lg_welcome_email_sent_at',
	'meta_compare' => 'EXISTS',
) ) );

echo "\nAPPLIED\n";
echo "  stamped                        : {$written}\n";
echo "  paid members STILL unmarked    : {$still_unmarked}  (must be 0)\n";
echo "  welcome emails ever, after     : {$mail_after}  (must equal {$mail_stamps})\n";

if ( $still_unmarked !== 0 ) {
	echo "\n⚠️  NOT COMPLETE — some paid members are still unmarked. Re-run and investigate.\n";
}
if ( $mail_after !== $mail_stamps ) {
	echo "\n🚨 MAIL COUNT MOVED — this script sends nothing, so something else mailed during the run.\n";
}
if ( $still_unmarked === 0 && $mail_after === $mail_stamps ) {
	echo "\nCLEAN — every paid member is marked and not one email was sent.\n";
}
