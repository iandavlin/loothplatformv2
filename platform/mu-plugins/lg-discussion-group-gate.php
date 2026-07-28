<?php
/**
 * Plugin Name: LG — Discussion group-subscription gate
 * Description: Enforces Ian's 2026-07-28 ruling that LAYOUT groups are plumbing and must produce no notifications or emails. Local Looths (real communities) ride an allow-list that ships EMPTY until that feature exists.
 * Author: thread-follow lane
 * Version: 1.0
 *
 * SPEC: docs/atlas/THREAD-FOLLOW-SPEC.md §8.2. Lane: thread-follow, 2026-07-28.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 * "GROUP" MEANS TWO DIFFERENT THINGS ON THIS PLATFORM (Ian, 2026-07-28):
 *
 *   TYPE 1 — LAYOUT GROUPS. A group created purely as a BuddyBoss mechanism, to
 *     force a layout so a forum gets its own activity feed. PLUMBING, not a
 *     community. Nobody joined to receive anything. MUST produce no notifications
 *     and no emails, and must never appear in the toggle UI.
 *
 *   TYPE 2 — LOCAL LOOTHS. The geographic-community initiative. These ARE real
 *     communities and WILL need notifications and email prompts — but the feature
 *     is NOT BUILT OUT YET. Design the seam; build nothing.
 *
 * Ruling 10 ("honour existing subscriptions, show them as ON") governs the 1,519
 * TOPIC + 46 FORUM subscriptions and does NOT reach these. Measured on LIVE
 * 2026-07-28: the group subscriptions are NOT chosen — 12,944 of 12,948 match a
 * group membership 1:1 (99.97%) and 12,917 (99.8%) are timestamped within 5 seconds
 * of the join, because BP_Groups_Member::bb_create_group_subscription() mints one on
 * join and deletes it on leave. Nobody clicked anything.
 *
 * ── WHAT IT PREVENTS, CONCRETELY ─────────────────────────────────────────────
 * Group subscriptions concentrate in five platform-wide LAYOUT groups of ~1,853
 * members each, every one with a linked forum. ONE new topic in the New Builds
 * forum would email 1,830 people (measured, net of opt-outs). The whole platform
 * sent 33 discussion emails in the trailing 14 days — so that single post is ~55x
 * the fortnight. It has not fired only because those forums are near-unused (21
 * topics across all 16 group-linked forums in the site's entire history, 11 have
 * zero, newest 2026-05-18). DORMANT BY ACCIDENT, NOT BY DESIGN. This gate is what
 * makes it dormant by design.
 *
 * ── ALLOW-LIST, AND IT FAILS CLOSED ──────────────────────────────────────────
 * The discriminator is BuddyBoss's own group-type taxonomy `bp_group_type` — a real
 * field, verified to split cleanly (one type per group, zero multi-typed, totals
 * reconciling to 12,948 exactly).
 *
 * ⚠️ THE TERM NAMES INVERT THE MEANING, which is why this is an allow-list:
 *      `loothing` → the 5 LAYOUT groups (9,262 subs). Reads like the core community
 *                   activity; it is the opposite — inert scaffolding.
 *      `34507`    → the 9 LOCAL LOOTHS (3,480 subs). Term id 1450, whose name AND
 *                   slug are the literal string "34507", description empty.
 * Denying `loothing` would fail OPEN: any renamed or newly-created type would start
 * emailing people silently. Allowing only known Type-2 slugs fails CLOSED — unknown
 * type means silence, which is the only safe direction under Ian's rule.
 *
 * Because Local Looths is not built, LG_DISCUSSION_GROUP_ALLOW SHIPS EMPTY and no
 * group subscription produces anything at all. That is the entire seam: the day
 * Local Looths is real, add its slug. One line, no other change.
 *
 * ⚠️ KEY ON THE SLUG, NEVER THE LABEL. Someone tidying "34507" into "Local Looths"
 * in wp-admin changes the slug and would silently re-arm this path with no error.
 * Recommended before Local Looths ships: re-slug 34507 to something legible. That is
 * a live write, so it is Ian's.
 *
 * ── SCOPE: THE GROUP BRANCH ONLY ─────────────────────────────────────────────
 * bbp_notify_forum_subscribers takes an EXCLUSIVE if/else (bp-forums/common/
 * functions.php:1382-1416): group-linked forum → group subscribers; otherwise →
 * forum subscribers. This gate must touch ONLY the group leg. The 46 forum
 * subscriptions are covered by ruling 10 and are deliberately left alone.
 *
 * REPLY email is untouched by design — it resolves recipients strictly by
 * (type=topic, item_id=topic) with no inheritance, so it never reaches this filter.
 */

if (!defined('ABSPATH')) exit;

/**
 * bp_group_type SLUGS whose members may receive group-derived discussion mail.
 *
 * EMPTY ON PURPOSE — Local Looths is not built (Ian, 2026-07-28: "design for it, do
 * not build it"). Add the Type-2 slug here the day it ships, and nothing else.
 * Filterable so a future lane can extend it without editing this file.
 */
function lg_discussion_group_allow(): array
{
    return array_values(array_filter(array_map(
        'strval',
        (array) apply_filters('lg_discussion_group_allow', [])
    )));
}

/** The bp_group_type slugs on a group (BuddyBoss stores them as a taxonomy). */
function lg_discussion_group_types(int $groupId): array
{
    if ($groupId < 1) return [];
    if (function_exists('bp_groups_get_group_type')) {
        $t = bp_groups_get_group_type($groupId, false);      // false → ALL types, as an array
        if (is_string($t) && $t !== '') return [$t];
        if (is_array($t)) return array_values(array_filter(array_map('strval', $t)));
    }
    // Fallback: read the taxonomy directly. bp_group_type is registered against the
    // group id as the object id.
    $terms = wp_get_object_terms($groupId, 'bp_group_type', ['fields' => 'slugs']);
    return is_wp_error($terms) ? [] : array_values(array_filter(array_map('strval', $terms)));
}

/**
 * Suppress group-derived discussion mail unless the group's type is allow-listed.
 *
 * Returning an EMPTY array is a hard stop: bbp_notify_forum_subscribers bails
 * (`if ( empty( $user_ids ) ) return false;`) BEFORE calling
 * bb_send_notifications_to_subscribers, so nothing is queued, nothing is sent, and
 * no background job is dispatched.
 */
add_filter('bbp_forum_subscription_user_ids', function ($user_ids, $topic_id, $forum_id) {
    $user_ids = (array) $user_ids;
    if (!$user_ids) return $user_ids;

    // Is this the GROUP branch? Mirror BuddyBoss's own test exactly, so we can never
    // gate the forum branch by accident.
    if (!function_exists('bbp_get_forum_group_ids') || !function_exists('bp_is_active') || !bp_is_active('groups')) {
        return $user_ids;
    }
    if (function_exists('bb_is_enabled_subscription') && !bb_is_enabled_subscription('group')) {
        return $user_ids;                                    // group subs off → forum branch
    }
    $groupIds = (array) bbp_get_forum_group_ids((int) $forum_id);
    $groupIds = array_values(array_filter(array_map('intval', $groupIds)));
    if (!$groupIds) return $user_ids;                        // not group-linked → forum branch, ruling 10

    $groupId = (int) $groupIds[0];                           // BB uses current() — the first
    $types   = lg_discussion_group_types($groupId);
    $allow   = lg_discussion_group_allow();
    $ok      = (bool) array_intersect($types, $allow);

    if ($ok) return $user_ids;                               // an allow-listed Local Looth

    // Suppressed. Log LOUDLY — a silent suppression is indistinguishable from a
    // broken mailer, and this path is dormant enough that nobody would notice.
    error_log(sprintf(
        '[lg-discussion-group-gate] suppressed %d recipient(s) for topic %d in forum %d — group %d type=[%s] not in allow-list [%s]',
        count($user_ids), (int) $topic_id, (int) $forum_id, $groupId,
        implode(',', $types) ?: 'none', implode(',', $allow) ?: 'EMPTY (Local Looths unbuilt)'
    ));
    return [];
}, 10, 3);
