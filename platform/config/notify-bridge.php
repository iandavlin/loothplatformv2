<?php
/**
 * notify-bridge — THE TRACKED CONFIG for the WP→bell bridge.
 *
 * Same discipline and the same measured reason as platform/config/follow-digest.php:
 * this code runs in the FPM pool (bb-mirror's reply.php) AND inside mu-plugins loaded
 * by WP-CLI and cron, and those contexts do not share an environment
 * (trap-wp-cron-has-no-environment, trap-fastcgi-param-not-in-getenv). A tracked PHP
 * file read relative to __DIR__ is visible in all of them because it is one file on
 * disk. /srv/lg-shared symlinks into this repo, so it arrives by `git pull`.
 *
 * ⚠️ DO NOT KEY ANYTHING HERE ON LG_ENV — live's /etc/looth/env says LG_ENV=dev2
 * (trap-live-lg-env-says-dev2). One value, the same on every box.
 */

return array(

	/**
	 * DOES A BUDDYBOSS DISCUSSION SUBSCRIPTION RING OUR BELL? (notif-bridge, 2026-08-08)
	 *
	 * Leg 4 of lg_notify_on_reply() — `forum.followed_topic` — asks
	 * lg_notify_topic_followers() who follows a discussion. It reads
	 * `forums.topic_follow`, our own 🔔 store, and NOTHING ELSE. Measured on live
	 * 2026-08-08 (docs/atlas/NOTIF-BRIDGE-GAP-2026-08-08.md):
	 *
	 *     forums.topic_follow                                12 rows
	 *     wp_bb_notifications_subscriptions type='topic'  1,515 rows / 381 members
	 *
	 * So 381 members follow discussions and our bell has never once rung for it.
	 * Over 15 days BuddyBoss raised 33 `bb_forums_subscribed_reply` events; we raised
	 * 2. Pair-by-pair on Ian's own account: he is subscribed to 66434, 72472 and
	 * 72554 in BuddyBoss, and `topic_follow` knows only 72447 and 72554.
	 *
	 * true ⇒ leg 4 takes the UNION of both stores, so an existing BuddyBoss
	 *        subscriber starts getting the bell for discussions they already follow.
	 *
	 * ── ⚠️ TWO THINGS TO WEIGH BEFORE FLIPPING, BOTH FOR IAN ────────────────
	 *
	 * 1. BLAST RADIUS IS NOT EVENLY SPREAD. User 779 alone holds 340 topic
	 *    subscriptions; the next highest is 49. Turning this on makes 779's bell ring
	 *    for every reply in 340 discussions. Coalescing bounds it to one unread row
	 *    per topic, but that is still a different product for that member.
	 *
	 * 2. IT TREATS AN EMAIL FOLLOW AS A BELL FOLLOW, and ruling 6 deliberately made
	 *    those two separate controls (🔔 writes topic_follow, ✉ writes the
	 *    subscription row). The case FOR: those 1,515 rows were overwhelmingly not
	 *    chosen — ruling 4 measured 71% as auto-subscribed by replying — so reading
	 *    them as "wants to hear about this discussion" is truer to the member's intent
	 *    than reading them as "chose email, declined bell". The case AGAINST: it is
	 *    still an inference about consent, and inferring consent is how the mail
	 *    problem started. NOT keeper's call. Ian's.
	 *
	 * ── ✅ RULED 2026-08-09: LEAVE OFF. Ian. ─────────────────────────────────
	 * He took the case AGAINST, on both counts: ruling 6's bell/email separation
	 * STANDS, and inferring consent from rows nobody chose is how the mail problem
	 * started in the first place.
	 *
	 * So this stays false, and it is now a DECISION rather than a default awaiting
	 * one. Do not flip it as tidy-up, and do not read the measured gap below as a
	 * standing argument to — the gap is real and is knowingly accepted. If the bell
	 * should reach those 381 members, the route Ian left open is the one ruling 6
	 * built: they tick 🔔 on the composer, and forums.topic_follow fills up
	 * honestly. Re-opening this needs him, not a lane.
	 *
	 * The code stays because the measurement stays true and reversing the ruling
	 * must not require rediscovering any of it. It is gated in both states
	 * (tools/gates/notif-dismiss-gate.sh) so OFF is asserted, not assumed.
	 */
	'bell_follows_bb_subscriptions' => false,

);
