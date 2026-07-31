<?php
/**
 * Plugin Name: LG — Follow digest (Daily / Weekly discussion email)
 * Description: Batches replies in followed discussions into one Daily or Weekly email, and suppresses the native per-reply send for members who chose to be batched. Flagged OFF; the OFF state is a proven no-op.
 * Author: follow-digest lane
 * Version: 0.1
 *
 * DESIGN: docs/atlas/FOLLOW-DIGEST-DESIGN.md. GATE: tools/gates/follow-digest-gate.py.
 * SPEC context: docs/atlas/THREAD-FOLLOW-SPEC.md §15.3-15.5 (the questions this answers).
 *
 * ── WHAT THIS IS FOR ─────────────────────────────────────────────────────────
 * thread-follow built a frequency control and shipped it DARK (FREQ_ENABLED=false)
 * because "storing a cadence does nothing without a batcher, and there is no sender."
 * This is the sender. Ian, 2026-07-31: build the batcher so Daily/Weekly really work,
 * and cadence is ONE ACCOUNT-LEVEL setting, not per-thread.
 *
 * ── ⚠️ EMAIL IS UNRECALLABLE — read this before editing anything below ───────
 * A bug here does not render wrong. It lands in real members' inboxes and cannot be
 * taken back. Three rules govern every line:
 *
 *   1. FLAG OFF IS A PROVEN NO-OP. Not "should be" — proven. With the flag off the
 *      suppression filter is the IDENTITY function and no cron event is registered
 *      at all. tools/gates/follow-digest-gate.py asserts both, and asserts them
 *      NON-VACUOUSLY (it proves the machinery is live before asserting absence).
 *   2. PROVE THE RECIPIENT SET BEFORE THE CONTENT. Sending the right email to the
 *      wrong people is worse than sending nothing.
 *   3. A GREEN TEST ON DEV2 IS NOT EVIDENCE OF A SEND. lg-dev-mail-containment.php
 *      swallows wp_mail into mailpit and RETURNS TRUE. Assert on the recipient set
 *      and the store, never on "wp_mail returned true".
 *
 * ── THE ONE THING THAT WOULD DO REAL DAMAGE ──────────────────────────────────
 * A member turning Daily on has no watermark. Read naively that is epoch, and their
 * first digest is the ENTIRE reply history of every thread they follow — one live
 * account holds 335 subscriptions. So: the watermark is stamped to NOW at the moment
 * cadence is first written (lg_fd_set_cadence), and the collector REFUSES to run
 * without one. A digest is never a backfill. See §4.3 of the design note.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ── THE FLAG ──────────────────────────────────────────────────────────────────
 * Defaulted OFF, copying LG_THREAD_FOLLOW_ENABLED (bb-mirror/config.php:461) and
 * LG_AUTHOR_SOCIALS_ALL_MEMBERS. Override without editing the repo:
 * LG_FOLLOW_DIGEST=1 in the pool environment.
 *
 * ⚠️ THIS FLAG GOVERNS AN UNRECALLABLE CHANNEL. It is not flipped by this lane and
 * not flipped because the gates are green. It flips when the batcher is proven to
 * deliver, keeper takes that to Ian, and Ian says so. */
if ( ! defined( 'LG_FOLLOW_DIGEST_ENABLED' ) ) {
	define( 'LG_FOLLOW_DIGEST_ENABLED',
		getenv( 'LG_FOLLOW_DIGEST' ) === '1'
		|| ( ( $_SERVER['LG_FOLLOW_DIGEST'] ?? '' ) === '1' ) );
}

const LG_FD_CADENCE_META   = 'lg_disc_email_cadence';
const LG_FD_WATERMARK_META = 'lg_disc_digest_watermark';
const LG_FD_CRON_HOOK      = 'lg_fd_send';

/** The only deliverable cadences. Hourly is out on measurement (no member on live has
 *  ever had two forum notifications in the same hour); "Off" is out because the ✉
 *  toggle owns on/off. Anything not in this list is not a cadence. */
function lg_fd_cadences(): array {
	return array( 'instant', 'daily', 'weekly' );
}

/**
 * A member's cadence. ABSENT MEANS INSTANT — deliberately, and it is what makes the
 * flag's OFF state a no-op for the whole membership on day one: nobody carries a
 * cadence, so nobody is suppressed and nobody is batched.
 */
function lg_fd_cadence( int $user_id ): string {
	if ( $user_id <= 0 ) { return 'instant'; }
	$c = (string) get_user_meta( $user_id, LG_FD_CADENCE_META, true );
	return in_array( $c, lg_fd_cadences(), true ) ? $c : 'instant';
}

/**
 * Write a member's cadence. THE WATERMARK STAMP HERE IS THE FLOOD GUARD — it is the
 * single most consequential line in this file.
 *
 * Stamped on any transition INTO a batched cadence, not only the first write: a
 * member who goes daily → instant → daily has a stale watermark from the first
 * period, and honouring it would deliver weeks of already-read replies in one email.
 *
 * @return bool false if the cadence is not one we can actually deliver.
 */
function lg_fd_set_cadence( int $user_id, string $cadence ): bool {
	if ( $user_id <= 0 || ! in_array( $cadence, lg_fd_cadences(), true ) ) { return false; }

	$was = lg_fd_cadence( $user_id );
	update_user_meta( $user_id, LG_FD_CADENCE_META, $cadence );

	if ( 'instant' !== $cadence && $cadence !== $was ) {
		// NOW, never epoch. A digest is never a backfill.
		update_user_meta( $user_id, LG_FD_WATERMARK_META, gmdate( 'Y-m-d H:i:s' ) );
	}
	if ( 'instant' === $cadence ) {
		// Nothing to flush; leaving a stale watermark behind is what makes the
		// re-entry case above dangerous, so it goes with the cadence.
		delete_user_meta( $user_id, LG_FD_WATERMARK_META );
	}
	return true;
}

/** The watermark, or '' when absent. '' is a REFUSAL signal, not "the beginning of time". */
function lg_fd_watermark( int $user_id ): string {
	return (string) get_user_meta( $user_id, LG_FD_WATERMARK_META, true );
}

/* ── SUPPRESSION: the seam that makes Daily/Weekly honest ──────────────────────
 * You cannot build Daily/Weekly without touching the Instant path. A member on Daily
 * whose native per-reply mail keeps running gets instant mail AND a digest — the same
 * lie in a new costume.
 *
 * Hook verified in live's deployed BuddyBoss 2.20.0, inside
 * BP_Forums_Notification::bb_send_forums_subscribed_reply() (class-bp-forums-
 * notification.php:989), in its per-recipient loop at :1069. Three properties make it
 * the right seam rather than a hack:
 *   1. PER-RECIPIENT — $args['recipient_user_id'] is exactly the granularity needed.
 *   2. EMAIL ONLY — bb_send_forums_subscribed_reply_notifications is a SEPARATE
 *      filter, so a batched member keeps their real-time bell. Ian wanted the bell to
 *      be the real-time channel; this preserves that exactly.
 *   3. UNCLAIMED — verified across the monorepo. The one adjacent filter we own,
 *      lg-discussion-group-gate.php:112 (bbp_forum_subscription_user_ids), is the
 *      forum/new-discussion path and is disjoint.
 *
 * ⚠️ RUNS IN BUDDYBOSS'S BACKGROUND UPDATER, not the request: bb_send_notifications_
 * to_subscribers sets $background_process=true whenever total>1 and sends in chunks of
 * 20 (bb-core-subscriptions.php:1135-1163). So the suppression decision is made at
 * FLUSH time, not post time, and a member who changes cadence in between can have one
 * reply fall either way. Bounded and named: it cannot double-send, because the digest
 * is watermark-driven and the watermark only moves on a send.
 *
 * THE OFF PATH IS THE IDENTITY FUNCTION. That is the whole no-op claim, and it is
 * asserted by the gate rather than believed. */
add_filter( 'bb_send_forums_subscribed_reply_email_notifications', 'lg_fd_suppress_instant', 10, 2 );
function lg_fd_suppress_instant( $send_mail, $args ) {
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) { return $send_mail; }          // ← proven no-op
	$uid = (int) ( is_array( $args ) ? ( $args['recipient_user_id'] ?? 0 ) : 0 );
	if ( $uid <= 0 ) { return $send_mail; }
	// Only ever REMOVES mail. This filter must never turn a false into a true — a
	// member BB already decided not to mail (blocked, opted out, moderated) stays
	// unmailed, and the digest must not become a backdoor around that decision.
	if ( ! $send_mail ) { return $send_mail; }
	return 'instant' === lg_fd_cadence( $uid ) ? $send_mail : false;
}

/* ── THE RECIPIENT SET ─────────────────────────────────────────────────────────
 * "Prove the recipient set before the content." This is the function the gate calls
 * to prove the OFF state resolves NOBODY — the one negative dev2 can genuinely prove
 * about an unrecallable channel, since a swallowed send returns true and proves
 * nothing positive.
 *
 * @return int[] WP user ids due for a digest at this cadence, right now.
 */
function lg_fd_due_recipients( string $cadence ): array {
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) { return array(); }             // ← the negative, asserted
	if ( ! in_array( $cadence, array( 'daily', 'weekly' ), true ) ) { return array(); }

	global $wpdb;
	if ( ! isset( $wpdb ) ) { return array(); }

	// A member is due only if they hold this cadence AND a watermark. No watermark
	// means the flood guard has not run for them, and the correct response is to send
	// them nothing rather than to invent a window.
	$sql = $wpdb->prepare(
		"SELECT c.user_id
		   FROM {$wpdb->usermeta} c
		   JOIN {$wpdb->usermeta} w
		     ON w.user_id = c.user_id AND w.meta_key = %s AND w.meta_value <> ''
		  WHERE c.meta_key = %s AND c.meta_value = %s",
		LG_FD_WATERMARK_META, LG_FD_CADENCE_META, $cadence
	);
	return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
}

/* ── THE CONTENT ───────────────────────────────────────────────────────────────
 * Replies in topics this member holds the ✉ bit on, since their watermark, excluding
 * their own. Counts, thread titles and sender display names only — NEVER reply body
 * text. That is the standing privacy ruling (NOTIF-EMAIL-STATE §1), not a style
 * choice, and it is enforced here by simply never selecting post_content.
 *
 * post_status='publish' is doing real work: it keeps a reply that was trashed,
 * spammed or left pending between post time and flush time OUT of the digest.
 * Instant has no equivalent protection, so the batcher is strictly safer here.
 *
 * @return array{items:array,capped:bool}  items ordered oldest-first.
 */
function lg_fd_items_for( int $user_id, int $limit = 50 ): array {
	$empty = array( 'items' => array(), 'capped' => false );
	if ( ! LG_FOLLOW_DIGEST_ENABLED || $user_id <= 0 ) { return $empty; }

	$since = lg_fd_watermark( $user_id );
	if ( '' === $since ) { return $empty; }   // REFUSE rather than backfill. §4.3.

	global $wpdb;
	if ( ! isset( $wpdb ) ) { return $empty; }
	$subs = $wpdb->prefix . 'bb_notifications_subscriptions';

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT r.ID, r.post_parent AS topic_id, r.post_author, r.post_date_gmt,
		        t.post_title AS topic_title
		   FROM {$wpdb->posts} r
		   JOIN {$subs} s
		     ON s.item_id = r.post_parent AND s.type = 'topic' AND s.status = 1
		    AND s.user_id = %d
		   JOIN {$wpdb->posts} t ON t.ID = r.post_parent
		  WHERE r.post_type    = 'reply'
		    AND r.post_status  = 'publish'
		    AND r.post_date_gmt > %s
		    AND r.post_author <> %d
		  ORDER BY r.post_date_gmt ASC
		  LIMIT %d",
		$user_id, $since, $user_id, $limit + 1
	), ARRAY_A );

	$rows   = (array) $rows;
	$capped = count( $rows ) > $limit;
	if ( $capped ) { $rows = array_slice( $rows, 0, $limit ); }

	return array( 'items' => $rows, 'capped' => $capped );
}

/**
 * Advance the watermark after a send the sender believes succeeded.
 *
 * ⚠️ TO THE NEWEST ITEM INCLUDED, NEVER TO now(). A reply landing between the query
 * and the send would otherwise be consumed without ever being sent — silently, and
 * with no way to notice.
 */
function lg_fd_advance_watermark( int $user_id, array $items ): void {
	if ( $user_id <= 0 || ! $items ) { return; }
	$newest = '';
	foreach ( $items as $it ) {
		$d = (string) ( $it['post_date_gmt'] ?? '' );
		if ( $d > $newest ) { $newest = $d; }
	}
	if ( '' !== $newest ) { update_user_meta( $user_id, LG_FD_WATERMARK_META, $newest ); }
}

/* ── THE SCHEDULE ──────────────────────────────────────────────────────────────
 * When it exists it will be armed ONLY while the flag is on, and UNSCHEDULED on
 * flag-off. The precedent is mirror-dispatch's outbox timer: a sender armed ahead of
 * its code reddens `systemctl --failed` forever and kills the alert channel — and
 * here it would also mail real members from a feature nobody switched on.
 *
 * It will ride the existing lg-wp-cron.timer (1-minute tick, `wp cron event run
 * --due-now`, verified active on dev2 AND live). No new systemd unit. Nowhere near
 * lg_wd_send_digest (weekly-recap's editorial broadcast, Mondays 13:00 UTC).
 *
 * MISSED RUNS ROLL FORWARD. Because the query is watermark-driven rather than
 * window-driven, a box down for two days sends a two-day digest rather than silently
 * dropping everything in between. That is a decision, not an accident: for an
 * unrecallable channel, "silently drops" is the wrong failure.
 *
 * ⚠️ NOTHING IS SCHEDULED IN THIS COMMIT, AND THAT IS DELIBERATE. The flush loop and
 * the template are not written yet. Scheduling the hook now would arm an event with
 * no callback: it would fire, do nothing, and — worse — satisfy the gate's "flag ON ⇒
 * lg_fd_send is scheduled" assertion, turning it green while no member would ever
 * receive anything. That is precisely the silent-nothing lie §15.4 forbids, rebuilt
 * inside the gate meant to catch it.
 *
 * So this only ever UNSCHEDULES, defensively. The scheduling half lands in the SAME
 * commit as the flush it drives, which keeps the gate's ON-path assertion honestly
 * red until there is something real behind it. */
add_action( 'init', 'lg_fd_sync_schedule' );
function lg_fd_sync_schedule(): void {
	$next = wp_next_scheduled( LG_FD_CRON_HOOK );
	if ( $next ) { wp_unschedule_event( $next, LG_FD_CRON_HOOK ); }
}
