<?php
/**
 * subscription-preserved-probe — the WP-side half of subscription-preserved-gate.py.
 *
 * Run through `wp eval-file` as looth-dev. Mode comes from LG_PFS_MODE:
 *   nofix    — do NOT load the repair. This is the NEGATIVE CONTROL: it must FAIL,
 *              i.e. reproduce the data loss. A gate whose red state was never observed
 *              is decoration.
 *   fix-on   — load the repair with the flag ON. Must preserve.
 *   fix-off  — load the repair with the flag OFF. Must behave EXACTLY like nofix,
 *              which is the no-op claim, asserted rather than believed.
 *
 * Prints one `KEY=value` per line. Exercises the REAL routes via rest_do_request —
 * the same call bb-mirror/api/v0/reply.php:485 makes and the same one the composer's
 * edit posts to — because the defect lives in what BuddyBoss does with a request that
 * omits a field, and only a real request omits it authentically.
 *
 * ⚠️ wp eval-file runs in FUNCTION scope: top-level vars are NOT global. Everything
 * here is local or explicitly `global`, and $wpdb is fetched where needed.
 *
 * Leaves the box as it found it: probe replies are hard-deleted and the subscription
 * is restored to whatever it was on entry.
 */

$mode = (string) getenv( 'LG_PFS_MODE' );
$T    = (int) ( getenv( 'LG_PFS_TOPIC' ) ?: 0 );
$U    = (int) ( getenv( 'LG_PFS_USER' ) ?: 1 );

if ( ! in_array( $mode, array( 'nofix', 'fix-on', 'fix-off' ), true ) ) {
	echo "ERROR=bad_mode\n"; return;
}
if ( $T < 1 ) { echo "ERROR=no_topic\n"; return; }

// Load the repair for the fix modes, pinning the flag BEFORE the file defines it.
if ( 'fix-on' === $mode || 'fix-off' === $mode ) {
	define( 'LG_PRESERVE_FORUM_SUBSCRIPTION', 'fix-on' === $mode );
	$mu = dirname( __DIR__, 2 ) . '/platform/mu-plugins/lg-preserve-forum-subscription.php';
	if ( ! is_readable( $mu ) ) { echo "ERROR=mu_unreadable\n"; return; }
	require_once $mu;
	echo 'FLAG=' . ( LG_PRESERVE_FORUM_SUBSCRIPTION ? 'on' : 'off' ) . "\n";
	echo 'HOOKED=' . ( has_filter( 'rest_request_before_callbacks', 'lg_pfs_preserve' ) ? 'yes' : 'no' ) . "\n";
} else {
	echo "FLAG=absent\n";
	echo 'HOOKED=' . ( has_filter( 'rest_request_before_callbacks', 'lg_pfs_preserve' ) ? 'yes' : 'no' ) . "\n";
}

wp_set_current_user( $U );

// ── LIVENESS: the machinery must actually be here, or every assertion below is
//    vacuous. "Still subscribed" is trivially true on a box with no forums at all.
if ( ! function_exists( 'bbp_is_user_subscribed' ) || ! function_exists( 'bbp_add_user_subscription' ) ) {
	echo "ERROR=no_bbp\n"; return;
}
if ( ! bb_is_enabled_subscription( 'topic' ) ) { echo "ERROR=subs_disabled\n"; return; }
echo "LIVENESS=ok\n";

$entry_sub = bbp_is_user_subscribed( $U, $T );

/** Put the member in the subscribed state the defect destroys. */
$arm = function () use ( $U, $T ) {
	if ( ! bbp_is_user_subscribed( $U, $T ) ) { bbp_add_user_subscription( $U, $T ); }
	return bbp_is_user_subscribed( $U, $T );
};

// ── LEG 1: posting a reply ───────────────────────────────────────────────────
$armed = $arm();
echo 'REPLY_BEFORE=' . ( $armed ? 'subscribed' : 'NOT-subscribed' ) . "\n";
if ( $armed ) {
	$GLOBALS['lg_bb_mirror_reply_owned'] = true;              // as reply.php:483
	$req = new WP_REST_Request( 'POST', '/buddyboss/v1/reply' );
	$req->set_param( 'topic_id', $T );
	$req->set_param( 'forum_id', (int) bbp_get_topic_forum_id( $T ) );
	$req->set_param( 'content', 'subscription-preserved-gate probe — safe to delete' );
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		echo 'REPLY_ERROR=' . str_replace( "\n", ' ', $res->as_error()->get_error_message() ) . "\n";
	} else {
		$rid = (int) ( ( (array) $res->get_data() )['id'] ?? 0 );
		echo "REPLY_CREATED=$rid\n";
		echo 'REPLY_AFTER=' . ( bbp_is_user_subscribed( $U, $T ) ? 'subscribed' : 'NOT-subscribed' ) . "\n";
		if ( $rid > 0 ) { wp_delete_post( $rid, true ); }
	}
}

// ── LEG 2: editing the topic ─────────────────────────────────────────────────
$armed = $arm();
echo 'TOPIC_BEFORE=' . ( $armed ? 'subscribed' : 'NOT-subscribed' ) . "\n";
if ( $armed ) {
	$topic = get_post( $T );
	// ⚠️ `parent` is REQUIRED. Omitting it makes the route 400 before it ever reaches
	// the subscription block, and the probe then reports "preserved" for a request
	// that never ran — a false PASS that already fooled this lane once.
	$req = new WP_REST_Request( 'POST', '/buddyboss/v1/topics/' . $T );
	$req->set_param( 'id', $T );
	$req->set_param( 'title', (string) $topic->post_title );
	$req->set_param( 'content', (string) $topic->post_content );
	$req->set_param( 'parent', (int) bbp_get_topic_forum_id( $T ) );
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		echo 'TOPIC_ERROR=' . str_replace( "\n", ' ', $res->as_error()->get_error_message() ) . "\n";
	} else {
		echo 'TOPIC_STATUS=' . (int) $res->get_status() . "\n";
		echo 'TOPIC_AFTER=' . ( bbp_is_user_subscribed( $U, $T ) ? 'subscribed' : 'NOT-subscribed' ) . "\n";
	}
}

// ── restore entry state ──────────────────────────────────────────────────────
if ( $entry_sub && ! bbp_is_user_subscribed( $U, $T ) )      { bbp_add_user_subscription( $U, $T ); }
if ( ! $entry_sub && bbp_is_user_subscribed( $U, $T ) )      { bbp_remove_user_subscription( $U, $T ); }
echo 'RESTORED=' . ( bbp_is_user_subscribed( $U, $T ) === $entry_sub ? 'yes' : 'NO' ) . "\n";
echo "DONE=1\n";
