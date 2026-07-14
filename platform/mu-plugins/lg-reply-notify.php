<?php
/**
 * Plugin Name: LG Reply Notify — universal bell
 * Description: Rings the profile-app notification bell on EVERY forum reply
 *              creation, from a single WordPress hook (bbp_new_reply) that fires
 *              regardless of which endpoint created the reply.
 *
 *              WHY THIS EXISTS (live bug it fixes): the hub discussion-modal
 *              reply CREATE posts to the NATIVE BuddyBoss route
 *              (/wp-json/buddyboss/v1/reply), NOT our bb-mirror reply.php. The
 *              notification bridge (lg_notify_on_reply) used to be called ONLY
 *              from reply.php's POST handler — a path members never hit on
 *              create — so modal replies rang NOBODY. bbp_new_reply fires on the
 *              native route, on reply.php's in-process rest_do_request(), and on
 *              wp-admin, so hooking it here notifies universally. reply.php's own
 *              call was retired in the same change (one hook = exactly one ring).
 *
 *              Phase 1 of the reply-images-6 lane (Ian 2026-07-13). Phase 2
 *              (our own create endpoint + WebP media) will fire the bridge
 *              natively; this hook stays as the safety net for any other path.
 *
 *              Option-gated: set option `lg_reply_notify_enabled` to 0 to disable
 *              without removing the file (house rule).
 */

if (!defined('ABSPATH')) exit;

if (!get_option('lg_reply_notify_enabled', 1)) return;

// Priority 20: after bb-mirror-sync's prio-5 anon-flag stamp, well before the
// prio-99 deferred PG sync. The reply post (author, topic, reply_to, content) is
// fully inserted by the time bbp_new_reply fires; media attach happens later but
// the bell only needs the text (for @mention detection), so timing is safe.
add_action('bbp_new_reply', function ($reply_id) {
    $reply_id = (int) $reply_id;
    if ($reply_id < 1) return;

    // Only PUBLISHED replies raise the bell — held (pending/spam) replies stay
    // silent, matching the retired reply.php behavior (it 202'd before notifying).
    $reply = get_post($reply_id);
    if (!$reply || $reply->post_status !== 'publish') return;

    if (!is_file('/srv/lg-shared/notify-bridge.php')) return;
    require_once '/srv/lg-shared/notify-bridge.php';
    if (!function_exists('lg_notify_on_reply')) return;

    // Derive everything from the stored reply (never trust a caller) — same shape
    // reply.php passed. bbp_get_reply_to() is 0 for a top-level reply.
    $topic_id = function_exists('bbp_get_reply_topic_id') ? (int) bbp_get_reply_topic_id($reply_id) : 0;
    $reply_to = function_exists('bbp_get_reply_to')       ? (int) bbp_get_reply_to($reply_id)       : 0;
    if ($topic_id < 1) return;

    // Fire-and-forget by contract: the bridge swallows its own errors, and a
    // published reply must never fail because the bell is down.
    lg_notify_on_reply(
        $topic_id,
        $reply_id,
        (int) $reply->post_author,
        $reply_to,
        (string) $reply->post_content
    );
}, 20, 1);
