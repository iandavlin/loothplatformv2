<?php
/**
 * Plugin Name: LG — post→follow controls (ruling 6)
 * Description: Lets the composer and reply box record the 🔔 bell follow at post time.
 *              Flag OFF by default.
 *
 * ── WHAT RULING 6 NEEDS, AND HOW LITTLE OF IT IS SERVER WORK ─────────────────
 * Ian, 2026-08-08 (re-amended): both surfaces carry both follow controls —
 * 🔔 Notifications TICKED by default, ✉ Emails PRESENT but UNTICKED.
 *
 * The ✉ half needs NO code here at all, which was worth proving before writing any.
 * BuddyBoss's own REST endpoints already accept a `subscribe` param, and passing it
 * writes exactly the row the follow roundup reads. Measured on dev2:
 *
 *     BEFORE subscribed=false → POST /buddyboss/v1/reply with subscribe:true
 *     AFTER  subscribed=true
 *     ROUNDUP_ROW={"type":"topic","item_id":71525,"status":"1"}
 *
 * So ticking ✉ is a client-side param, and lg-preserve-forum-subscription.php already
 * stands down when an explicit `subscribe` arrives — the two compose without either
 * knowing about the other.
 *
 * That leaves ONE thing that genuinely needs server code: the 🔔 bit, which lives in
 * OUR Postgres (forums.topic_follow) and has no BuddyBoss equivalent.
 *
 * ── WHY A PARAM AND NOT A SECOND CALL ────────────────────────────────────────
 * The client could POST /bb-mirror-api/v0/follow after the post succeeds. It would
 * work, and it would be one more thing that can half-fail: a reply that posted with
 * the box ticked, and a follow that did not land, with the UI showing ticked. Riding
 * the same request keeps "posted" and "following" a single outcome from the member's
 * point of view.
 *
 * ── THE FLAG IS OFF BY DEFAULT, AND HERE THAT IS THE PLAIN HOUSE RULE ────────
 * This IS a member-facing feature (unlike lg-preserve-forum-subscription.php, which is
 * a repair and argues its ON default explicitly). OFF must be a byte-identical no-op:
 * no param is read, no row is written, and the filter returns its input untouched.
 * tools/gates/post-follow-controls-gate.py asserts all three states off the constant
 * rather than hardcoding one, so flipping this line needs no gate edit.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ONE SOURCE OF TRUTH, shared with the UI. platform/config/post-follow.php is read by
 * this file AND by bb-mirror's forums app, because a constant in each would let the
 * control and the write disagree — a rendered control that does nothing is the "UI
 * lies" class, and it is silent.
 *
 * Fails CLOSED: an unreadable or malformed config leaves the feature off.
 */
function lg_pfc_config_enabled(): bool {
	static $on = null;
	if ( null !== $on ) { return $on; }
	$on   = false;
	$path = dirname( __DIR__ ) . '/config/post-follow.php';
	if ( is_readable( $path ) ) {
		$raw = require $path;
		$on  = is_array( $raw ) && true === ( $raw['enabled'] ?? false );
	} else {
		error_log( '[lg-pfc] tracked config unreadable at ' . $path . ' — feature OFF (fail-closed)' );
	}
	return $on;
}

if ( ! defined( 'LG_POST_FOLLOW_CONTROLS' ) ) {
	// Both override sources, for the same reason the config header gives: a
	// fastcgi_param set by a lane preview lands in $_SERVER but not in getenv().
	define( 'LG_POST_FOLLOW_CONTROLS',
		lg_pfc_config_enabled()
		|| getenv( 'LG_POST_FOLLOW' ) === '1'
		|| ( ( $_SERVER['LG_POST_FOLLOW'] ?? '' ) === '1' ) );
}

/** The request param the composer and reply box set when 🔔 is ticked. */
const LG_PFC_NOTIFY_PARAM = 'lg_follow_notify';

/**
 * Open the follow store, in either of the two contexts this can run in.
 *
 * Mirrors lg_notify_topic_followers() (lg-shared/notify-bridge.php:263) exactly:
 * bb-mirror's config.php may or may not be loaded depending on whether the request
 * arrived through our own endpoint, and both contexts run on the same FPM pool as the
 * same OS user, so the same peer-auth socket is available either way.
 */
function lg_pfc_db(): ?PDO {
	try {
		if ( function_exists( 'bb_mirror_db' ) ) { return bb_mirror_db( false ); }
		$db  = defined( 'LG_BB_MIRROR_PG_DB' ) ? LG_BB_MIRROR_PG_DB : 'looth';
		$pdo = new PDO( 'pgsql:host=/var/run/postgresql;dbname=' . $db, null, null );
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->exec( 'SET search_path = forums, public' );
		return $pdo;
	} catch ( Throwable $e ) {
		error_log( '[lg-pfc] follow store unreachable: ' . $e->getMessage() );
		return null;
	}
}

/**
 * Record the 🔔 follow after a successful post.
 *
 * Hooked AFTER the callbacks, deliberately: a follow must never be recorded for a post
 * that failed to save. The member would then be following a discussion they did not
 * manage to contribute to, which reads as the platform inventing a preference.
 */
add_filter( 'rest_request_after_callbacks', 'lg_pfc_record_follow', 10, 3 );
function lg_pfc_record_follow( $response, $handler, $request ) {
	if ( ! LG_POST_FOLLOW_CONTROLS ) { return $response; }          // ← proven no-op
	if ( ! ( $request instanceof WP_REST_Request ) ) { return $response; }
	if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) { return $response; }

	// Only ever ADDS. There is no unfollow here: unticking a box on one post is not a
	// request to stop following a discussion you already follow — the 🔔/✉ controls in
	// the follow modal are the deliberate way out. Same asymmetry, and the same reason,
	// as lg-preserve-forum-subscription.php only ever passing true.
	if ( true !== filter_var( $request->get_param( LG_PFC_NOTIFY_PARAM ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) {
		return $response;
	}

	// A failed post must not produce a follow.
	if ( is_wp_error( $response ) ) { return $response; }
	if ( $response instanceof WP_REST_Response && $response->get_status() >= 300 ) { return $response; }

	$uid = get_current_user_id();
	if ( $uid < 1 ) { return $response; }

	$topic_id = lg_pfc_topic_from( (string) $request->get_route(), $request, $response );
	if ( $topic_id < 1 ) { return $response; }

	/* SILENT ON FAILURE, like every other write on this path (notify-bridge.php:283).
	 * A reply that posted must never fail because the follow store was unreachable —
	 * the member's content is the thing that matters, and a missing bell row is
	 * recoverable from the follow modal. Logged loudly so it is not invisible. */
	$pdo = lg_pfc_db();
	if ( null === $pdo ) { return $response; }
	try {
		$st = $pdo->prepare( 'INSERT INTO topic_follow (user_id, topic_id) VALUES (:u, :t)
		                      ON CONFLICT (user_id, topic_id) DO NOTHING' );
		$st->execute( array( ':u' => $uid, ':t' => $topic_id ) );
	} catch ( Throwable $e ) {
		error_log( '[lg-pfc] follow write failed uid=' . $uid . ' topic=' . $topic_id . ': ' . $e->getMessage() );
	}

	return $response;
}

/**
 * Which discussion did this post belong to?
 *
 * A new REPLY carries its topic in the request. A new TOPIC *is* the topic, and its id
 * only exists once the endpoint has run — which is the other reason this hook is on
 * after_callbacks rather than before.
 */
function lg_pfc_topic_from( string $route, WP_REST_Request $request, $response ): int {
	if ( preg_match( '#^/buddyboss/v1/reply$#', $route ) ) {
		return (int) $request->get_param( 'topic_id' );
	}
	if ( preg_match( '#^/buddyboss/v1/topics$#', $route ) ) {
		$data = ( $response instanceof WP_REST_Response ) ? (array) $response->get_data() : array();
		return (int) ( $data['id'] ?? 0 );
	}
	return 0;
}
