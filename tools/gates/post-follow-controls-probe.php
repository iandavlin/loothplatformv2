<?php
/**
 * post-follow-controls-probe — the WP-side half of post-follow-controls-gate.py.
 *
 * Modes via LG_PFC_MODE, one per process because the flag is a constant:
 *   absent       our mu-plugin NOT loaded at all — the true pre-feature baseline.
 *   off          flag OFF, request asking for both controls. Must match `absent` EXACTLY.
 *   on-default   flag ON, ruling-6 DEFAULTS: 🔔 ticked, ✉ unticked. Must create the
 *                topic_follow row and must NOT create the type='topic' roundup row.
 *   on-email     flag ON, member also ticks ✉. Must create BOTH.
 *
 * ⚠️ THE NO-OP CLAIM IS "OFF == ABSENT", NOT "OFF WRITES NOTHING", and the difference
 * is real. This flag gates the code THIS file adds — the 🔔 topic_follow write. It does
 * not gate `subscribe`, because that is BuddyBoss's own long-standing REST parameter and
 * suppressing it would change native behaviour and break the preservation filter, which
 * relies on passing it. So with the flag OFF a request that independently sends
 * `subscribe` still subscribes — exactly as it did before this file existed. Comparing
 * against `absent` is what makes that a measured equivalence rather than an excuse.
 *
 * Every leg starts from a KNOWN-CLEAN state (no follow row, no subscription) and the
 * probe prints what it observed rather than what it expected — the gate decides.
 *
 * ⚠️ Reads the follow store back over its OWN connection rather than trusting the
 * write to have happened. A "no rows" that is really "wrong database" would otherwise
 * pass as a clean OFF and fail as a broken ON.
 */

$mode = (string) getenv( 'LG_PFC_MODE' );
$T    = (int) ( getenv( 'LG_PFC_TOPIC' ) ?: 0 );
$U    = (int) ( getenv( 'LG_PFC_USER' ) ?: 1 );

if ( ! in_array( $mode, array( 'absent', 'off', 'on-default', 'on-email' ), true ) ) { echo "ERROR=bad_mode\n"; return; }
if ( $T < 1 ) { echo "ERROR=no_topic\n"; return; }

define( 'LG_POST_FOLLOW_CONTROLS', ! in_array( $mode, array( 'absent', 'off' ), true ) );
$root  = dirname( __DIR__, 2 ) . '/platform/mu-plugins/';
$files = array( 'lg-preserve-forum-subscription.php' );
if ( 'absent' !== $mode ) { $files[] = 'lg-post-follow-controls.php'; }
foreach ( $files as $f ) {
	if ( ! is_readable( $root . $f ) ) { echo "ERROR=mu_unreadable_$f\n"; return; }
	require_once $root . $f;
}
echo 'FLAG=' . ( 'absent' === $mode ? 'absent' : ( LG_POST_FOLLOW_CONTROLS ? 'on' : 'off' ) ) . "\n";
echo 'HOOKED=' . ( has_filter( 'rest_request_after_callbacks', 'lg_pfc_record_follow' ) ? 'yes' : 'no' ) . "\n";

if ( ! function_exists( 'lg_pfc_db' ) ) {
	function lg_pfc_db(): ?PDO {
		try {
			if ( function_exists( 'bb_mirror_db' ) ) { return bb_mirror_db( false ); }
			$pdo = new PDO( 'pgsql:host=/var/run/postgresql;dbname=looth', null, null );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$pdo->exec( 'SET search_path = forums, public' );
			return $pdo;
		} catch ( Throwable $e ) { return null; }
	}
}
if ( ! defined( 'LG_PFC_NOTIFY_PARAM' ) ) { define( 'LG_PFC_NOTIFY_PARAM', 'lg_follow_notify' ); }

wp_set_current_user( $U );
if ( ! function_exists( 'bbp_is_user_subscribed' ) ) { echo "ERROR=no_bbp\n"; return; }

/** Read the 🔔 store back over its own connection. */
$follow_rows = function () use ( $U, $T ) {
	$pdo = lg_pfc_db();
	if ( null === $pdo ) { return -1; }
	try {
		$st = $pdo->prepare( 'SELECT COUNT(*) FROM topic_follow WHERE user_id = :u AND topic_id = :t' );
		$st->execute( array( ':u' => $U, ':t' => $T ) );
		return (int) $st->fetchColumn();
	} catch ( Throwable $e ) { return -1; }
};
$clear_follow = function () use ( $U, $T ) {
	$pdo = lg_pfc_db();
	if ( null === $pdo ) { return; }
	try {
		$st = $pdo->prepare( 'DELETE FROM topic_follow WHERE user_id = :u AND topic_id = :t' );
		$st->execute( array( ':u' => $U, ':t' => $T ) );
	} catch ( Throwable $e ) { /* reported by the count below */ }
};

// ── LIVENESS: the store must be reachable, or every "0 rows" below is meaningless.
$clear_follow();
$probe = $follow_rows();
if ( $probe < 0 ) { echo "ERROR=follow_store_unreachable\n"; return; }
echo "LIVENESS=ok\n";

// known-clean start for BOTH channels
$entry_sub = bbp_is_user_subscribed( $U, $T );
if ( $entry_sub ) { bbp_remove_user_subscription( $U, $T ); }
echo 'START_FOLLOW=' . $follow_rows() . "\n";
echo 'START_SUB=' . ( bbp_is_user_subscribed( $U, $T ) ? 1 : 0 ) . "\n";

// ── the post ─────────────────────────────────────────────────────────────────
$GLOBALS['lg_bb_mirror_reply_owned'] = true;
$req = new WP_REST_Request( 'POST', '/buddyboss/v1/reply' );
$req->set_param( 'topic_id', $T );
$req->set_param( 'forum_id', (int) bbp_get_topic_forum_id( $T ) );
$req->set_param( 'content', 'post-follow-controls-gate probe — safe to delete' );
$req->set_param( LG_PFC_NOTIFY_PARAM, true );                  // 🔔 ticked (the default)
if ( 'on-email' === $mode ) { $req->set_param( 'subscribe', true ); }   // ✉ ticked
// 'off' mode deliberately sends BOTH as if ticked: a no-op must be a no-op even when
// the request is asking for the feature.
if ( 'off' === $mode || 'absent' === $mode ) { $req->set_param( 'subscribe', true ); }

$res = rest_do_request( $req );
if ( $res->is_error() ) {
	echo 'POST_ERROR=' . str_replace( "\n", ' ', $res->as_error()->get_error_message() ) . "\n";
} else {
	$rid = (int) ( ( (array) $res->get_data() )['id'] ?? 0 );
	echo "POST_OK=$rid\n";
	echo 'AFTER_FOLLOW=' . $follow_rows() . "\n";
	echo 'AFTER_SUB=' . ( bbp_is_user_subscribed( $U, $T ) ? 1 : 0 ) . "\n";
	if ( $rid > 0 ) { wp_delete_post( $rid, true ); }
}

// ── restore ──────────────────────────────────────────────────────────────────
$clear_follow();
if ( $entry_sub && ! bbp_is_user_subscribed( $U, $T ) )  { bbp_add_user_subscription( $U, $T ); }
if ( ! $entry_sub && bbp_is_user_subscribed( $U, $T ) )  { bbp_remove_user_subscription( $U, $T ); }
echo 'RESTORED=' . ( ( bbp_is_user_subscribed( $U, $T ) === $entry_sub && 0 === $follow_rows() ) ? 'yes' : 'NO' ) . "\n";
echo "DONE=1\n";
