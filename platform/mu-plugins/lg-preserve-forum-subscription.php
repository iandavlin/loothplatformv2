<?php
/**
 * Plugin Name: LG — preserve a member's discussion subscription across forum writes
 * Description: Participation must never silently unsubscribe you. Repairs a
 *              data-destroying BuddyBoss default on the REST reply and topic routes.
 *
 * ── THE DEFECT ────────────────────────────────────────────────────────────────
 * BuddyBoss treats the ABSENCE of a subscription field as "the member unticked the
 * box". bbp_update_reply() (bp-forums/replies/functions.php:996-1008), hooked to
 * bbp_new_reply at priority 10 (bp-forums/core/actions.php:177):
 *
 *     $subscribed = bbp_is_user_subscribed( $author_id, $topic_id );
 *     $subscheck  = ( ! empty($_POST['bbp_topic_subscription'])
 *                     && 'bbp_subscribe' === $_POST['bbp_topic_subscription'] );
 *     if ( true === $subscribed && false === $subscheck ) {
 *         bbp_remove_user_subscription( $author_id, $topic_id );   // ← silent data loss
 *     }
 *
 * $_POST['bbp_topic_subscription'] is only ever set by BuddyBoss's own form, or by
 * class-bp-rest-reply-endpoint.php:2748 when a `subscribe` param was supplied. Our
 * composer replaced that form in June 2026 and sends neither. So every reply posted
 * through our box REMOVES the author's subscription to that discussion, and every
 * topic edit does the same via the identical block at
 * class-bp-rest-topics-endpoint.php:1622.
 *
 * Proven in-process on dev2 against the deployed tree, both routes:
 *     BEFORE subscribed=true → reply created → AFTER subscribed=false
 *
 * The consequence is the inverse of what a forum is for: taking part in a thread is
 * what stops you being told about it, and it erodes the subscriber list every time
 * one of them participates. Same class as edit-post-parity (b99570b) — a save that
 * SUCCEEDS while destroying the member's data, with no error and nothing to notice.
 *
 * ── WHAT THIS DOES, AND THE LINE IT DOES NOT CROSS ────────────────────────────
 * It PRESERVES state. It never creates one. If the member is already subscribed we
 * tell BuddyBoss so, which makes both of its branches no-ops:
 *
 *     subscribed=true,  subscheck=true   → neither branch fires → preserved
 *     subscribed=false, (no param)       → neither branch fires → still not subscribed
 *
 * Restoring the TICKED-BY-DEFAULT checkbox is ruling 6 and is a separate change on a
 * separate surface. This file must never subscribe anybody, because "we fixed your
 * data loss and also signed you up" is a different act needing a different consent.
 *
 * ⚠️ AN EXPLICIT `subscribe` PARAM ALWAYS WINS. When the caller has said what it
 * wants — ruling 6's checkbox will — this stands down entirely. Preserving is only
 * correct in the absence of intent; overriding a stated intent would be the same
 * class of bug in the opposite direction.
 *
 * ── THE FLAG DEFAULTS **ON**, DELIBERATELY, AND THAT IS A DEVIATION ───────────
 * House rule (CLAUDE.md) is that member-facing changes merge behind a flag defaulted
 * OFF. That rule exists because a FEATURE cannot be verified on the dev2 serve until
 * it is already merged. This is not a feature — it is a repair for ongoing data
 * destruction, and OFF-by-default would mean shipping the destruction and calling it
 * safe. The precedent is exact: edit-post-parity (b99570b) was a P0 data-loss fix and
 * landed as a direct repair.
 *
 * The flag therefore exists so it can be turned OFF instantly, not so it arrives
 * inert. Both states are asserted by tools/gates/subscription-preserved-gate.py,
 * which READS the constant rather than assuming it — so flipping this line needs no
 * gate edit.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'LG_PRESERVE_FORUM_SUBSCRIPTION' ) ) {
	define( 'LG_PRESERVE_FORUM_SUBSCRIPTION', true );
}

/**
 * Resolve (topic_id, subject_id) exactly as BuddyBoss will for a given REST route, or
 * null when this route cannot reach the destructive block.
 *
 * ⚠️ "WHOSE SUBSCRIPTION" IS NOT THE SAME QUESTION ON EVERY ROUTE, and guessing it
 * wrong is a silent no-fix. The first draft of this file assumed the POST AUTHOR
 * throughout, reasoning that a moderator editing someone else's discussion would run
 * the block against the author. That is not what BuddyBoss does, the gate caught it,
 * and it had shipped a repair that repaired nothing on two of three routes:
 *
 *   /reply        (create) — class-bp-rest-reply-endpoint.php:640
 *                            $reply_author = bbp_get_current_user_id()   → CURRENT USER
 *   /reply/<id>   (edit)   — :1326 $reply_author = bbp_get_reply_author_id()
 *                            → the REPLY'S AUTHOR, not the editor
 *   /topics/<id>  (edit)   — class-bp-rest-topics-endpoint.php:1620
 *                            $author_id = bbp_get_user_id( 0, true, true ) → CURRENT USER
 *
 * Each is mirrored below rather than unified, because the divergence is BuddyBoss's
 * and unifying it here would just move the guess.
 *
 * @return array{0:int,1:int}|null  [topic_id, the user whose subscription is at risk]
 */
function lg_pfs_target( string $route, WP_REST_Request $request ): ?array {
	// New reply. BB acts on the CURRENT USER. (A brand-new TOPIC needs no entry here:
	// nobody can already follow a discussion that does not exist yet.)
	if ( preg_match( '#^/buddyboss/v1/reply$#', $route ) ) {
		$topic_id = (int) $request->get_param( 'topic_id' );
		return $topic_id > 0 ? array( $topic_id, get_current_user_id() ) : null;
	}

	// Editing a reply. BB acts on the REPLY'S AUTHOR — so a moderator's edit must not
	// preserve the moderator's own state and leave the author's being destroyed.
	if ( preg_match( '#^/buddyboss/v1/reply/(\d+)$#', $route, $m ) ) {
		$reply = get_post( (int) $m[1] );
		if ( ! $reply ) { return null; }
		return array( (int) $reply->post_parent, (int) $reply->post_author );
	}

	// Editing a topic — the composer's edit path. BB acts on the CURRENT USER.
	if ( preg_match( '#^/buddyboss/v1/topics/(\d+)$#', $route, $m ) ) {
		$topic = get_post( (int) $m[1] );
		if ( ! $topic ) { return null; }
		return array( (int) $topic->ID, get_current_user_id() );
	}

	return null;
}

/**
 * Inject the member's CURRENT subscription state as `subscribe` when the request has
 * not said otherwise.
 *
 * Hooked at rest_request_before_callbacks: late enough that params are parsed and the
 * route is resolved, early enough that the endpoint has not run. Returns $response
 * untouched — this filter must never affect the outcome of the request itself, only
 * the parameter the endpoint will read.
 */
add_filter( 'rest_request_before_callbacks', 'lg_pfs_preserve', 10, 3 );
function lg_pfs_preserve( $response, $handler, $request ) {
	if ( ! LG_PRESERVE_FORUM_SUBSCRIPTION ) { return $response; }   // ← proven no-op
	if ( ! ( $request instanceof WP_REST_Request ) ) { return $response; }

	$method = strtoupper( (string) $request->get_method() );
	if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) { return $response; }

	// An explicit intent always wins — see the header. null_on_failure keeps a
	// malformed value from reading as "false" and unsubscribing somebody.
	if ( null !== $request->get_param( 'subscribe' ) ) { return $response; }

	if ( ! function_exists( 'bbp_is_user_subscribed' ) ) { return $response; }

	$target = lg_pfs_target( (string) $request->get_route(), $request );
	if ( null === $target ) { return $response; }
	list( $topic_id, $subject_id ) = $target;
	if ( $topic_id < 1 || $subject_id < 1 ) { return $response; }

	/* ONLY EVER TRUE. Setting `subscribe` to false when they are not subscribed would
	 * be a no-op today, but it would also hand BuddyBoss an explicit "unsubscribe me"
	 * that any future change to that block could act on. Saying nothing is the safer
	 * shape, and it keeps this filter incapable of removing a subscription. */
	if ( bbp_is_user_subscribed( $subject_id, $topic_id ) ) {
		$request->set_param( 'subscribe', true );
	}

	return $response;
}
