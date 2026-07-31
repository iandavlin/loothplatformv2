<?php
/**
 * verify-reply-to-reply-covered.php — a member who was replied to ON THEIR OWN REPLY
 * must see that row in their recap.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 *
 * ── WHY THIS GATE EXISTS ─────────────────────────────────────────────────────
 *
 * Ian asked twice whether the recap covers replies-to-replies, and twice the answer
 * had to be re-derived by reading three files. It is already covered — but nothing
 * ASSERTED it, so a future edit that tightened `INCLUDED_TYPES`, or turned the
 * endpoint's catch-all into an explicit type list, would silence a whole rung of the
 * reply ladder in complete silence. Per CRAFT-STANDARD: a question asked twice gets
 * encoded rather than answered a third time.
 *
 * THE LADDER, and why reply_to_reply is the rung most easily lost (notify-bridge.php
 * :160-168 is the authority):
 *
 *   forum.mention          most specific — claims its recipients first
 *   forum.reply_to_reply   someone replied to a comment YOU left
 *   forum.reply_to_topic   someone replied to a discussion YOU started
 *   forum.followed_topic   least specific — a thread you merely watch (EXCLUDED from
 *                          the digest by DECIDED_EXCLUDED; not this gate's business)
 *
 * ── WHAT IS ASSERTED, IN THREE PARTS ─────────────────────────────────────────
 *
 * 1. LIVENESS. At least one real member must qualify. An "every qualifying member is
 *    covered" assertion is VACUOUSLY TRUE on a box where nobody qualifies — it would
 *    print GREEN on an empty database, which is the failure mode this suite has been
 *    bitten by before. Zero qualifiers is reported INCONCLUSIVE, never GREEN.
 *
 * 2. COVERAGE. Every member with an unread, in-window `forum.reply_to_reply` carrying
 *    an actor and a target_url renders a row that says so.
 *
 * 3. TEETH. For each covered member the payload is re-rendered with the
 *    reply_to_reply rows REMOVED, and the row must DISAPPEAR. Without this a gate
 *    that matched something incidental in the chrome would pass forever while
 *    asserting nothing. This is the substitute for a red-first run: there is no
 *    defect to make it red, so it is made to fail on demand instead.
 *
 * NOT ASSERTED HERE, deliberately: the one-row-per-person-per-event rule. That is a
 * WRITE-side property of the `$notified` set in notify-bridge.php:177-234 and cannot
 * be reconstructed from the store, because rows carry no event id. Note that a member
 * legitimately holds BOTH a reply_to_reply and a reply_to_topic for the SAME topic —
 * Alice replies to my discussion, later Bob replies to my comment in it — so a
 * store-level "same topic" check would be a false red, not a double-count detector.
 *
 * READ-ONLY. Renders in memory. Sends nothing, writes nothing.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

// OFF on this box by design — turn it on for this process only, or the source's
// smart-code path and the recipient filter both short-circuit.
add_filter( 'lg_wd_recap_enabled', '__return_true' );

const TYPE = 'forum.reply_to_reply';

/** The sentence the renderer produces for this type — class-lg-wd-recap.php:305-310. */
function rtr_claim( string $html ): bool {
	return (bool) preg_match( '~repl(y|ies) to your comment~i', $html );
}

$all = get_users( [ 'fields' => 'ID', 'number' => -1 ] );
printf( "sweeping %d members through the live recap endpoint (window %d days)\n\n",
	count( $all ), LG_WD_Recap_Source::WINDOW_DAYS );

$qualify = [];   // members the STORE says should see a reply-to-reply row
foreach ( array_chunk( array_map( 'intval', $all ), 200 ) as $chunk ) {
	$payloads = LG_WD_Recap_Source::fetch( $chunk );

	// An outage returns the same shape as a quiet week. Refuse to grade either way.
	if ( ! LG_WD_Recap_Source::source_answered() ) {
		fwrite( STDERR, "recap source did not answer — cannot grade coverage\n" );
		exit( 1 );
	}

	foreach ( $payloads as $wp => $payload ) {
		if ( ! $payload ) { continue; }
		foreach ( $payload['notifications'] ?? [] as $n ) {
			// The renderer drops a hub row with no target_url or no actor, on purpose
			// (never a dead link). Such a row is NOT a coverage failure, so it must not
			// be counted as a qualifier either.
			if ( (string) ( $n['type'] ?? '' ) !== TYPE ) { continue; }
			if ( (string) ( $n['target_url'] ?? '' ) === '' ) { continue; }
			if ( trim( (string) ( $n['actor_name'] ?? '' ) ) === '' ) { continue; }
			$qualify[ (int) $wp ] = $payload;
			break;
		}
	}
}

// ── 1. LIVENESS ──────────────────────────────────────────────────────────────
if ( ! $qualify ) {
	echo "INCONCLUSIVE — no member on this box currently holds an unread, in-window\n"
	   . TYPE . " with an actor and a deep link, so there is nothing to grade.\n"
	   . "This is NOT a pass. Raise one (reply to someone's comment in the Hub) and re-run.\n";
	exit( 2 );
}
printf( "%d member(s) qualify — the store says each must see a reply-to-reply row.\n\n", count( $qualify ) );

// ── 2. COVERAGE + 3. TEETH ───────────────────────────────────────────────────
$fail = [];
foreach ( $qualify as $wp => $payload ) {
	$user = get_user_by( 'id', $wp );
	$name = $user ? $user->display_name : "wp:$wp";

	$html    = LG_WD_Recap::render( $payload );
	$covered = rtr_claim( $html );

	// TEETH: strip the type and the claim must vanish.
	$stripped = $payload;
	$stripped['notifications'] = array_values( array_filter(
		$payload['notifications'] ?? [],
		fn( $n ) => (string) ( $n['type'] ?? '' ) !== TYPE
	) );
	$without   = LG_WD_Recap::render( $stripped );
	$has_teeth = ! rtr_claim( $without );

	printf( "  wp %-5d %-34s covered=%-3s teeth=%s\n",
		$wp, substr( $name, 0, 34 ), $covered ? 'YES' : 'NO',
		$has_teeth ? 'ok (claim vanishes when the type is removed)' : 'VACUOUS — claim survives removal' );

	if ( ! $covered )   { $fail[] = "wp $wp ($name): store has a " . TYPE . " row, recap renders no such line"; }
	if ( ! $has_teeth ) { $fail[] = "wp $wp ($name): the assertion is vacuous — it matches something else"; }
}

echo "\n";
if ( $fail ) {
	echo "RED — replies-to-replies are NOT reaching the recap:\n";
	foreach ( $fail as $f ) { echo "  - $f\n"; }
	exit( 1 );
}

printf( "REPLY-TO-REPLY IS COVERED — all %d qualifying member(s) see their row, and the\n"
	  . "assertion demonstrably fails when the type is removed.\n", count( $qualify ) );
exit( 0 );
