<?php
/**
 * lg-shared/notify-bridge.php — the WP-side door to the bell.
 *
 * ONE writer. bb-mirror's reply path and archive-poc's reaction path both live on
 * the WP FPM pool and both need to raise notifications; rather than each growing
 * its own copy of "resolve author → build link → POST", they require THIS file and
 * call one function. (Same discipline as the reaction_count contract: one store,
 * one writer.)  Lane: notifications, 2026-07-12.
 *
 * The bell itself is profile-app's `notifications` table, in the profile_app
 * Postgres database. WP cannot reach that database — so we POST over loopback to
 * /profile-api/v0/internal/notify (shared secret, deny-all-but-127.0.0.1). See
 * profile-app/api/v0/internal-notify.php.
 *
 * DEEP LINKS ARE THE POINT (Ian, merge gate): "someone mentions you in this
 * discussion actually needs to go to that discussion." Every link this file builds
 * is a CURRENT-system deep link into the §4e discussion modal —
 *     /hub/?topic=<forum-slug>/<topic-slug>[&reply=<reply-id>]
 * — the shape forums.js §4f already routes on BOTH surfaces (desktop lgDmodalOpen,
 * mobile lgOpenTopicMobile). NOT a legacy BuddyBoss permalink, NOT a bare /hub/.
 * We do not build a second deep-link system; we reuse hub-deeplinks (@20299f1) and
 * extend it with the &reply= anchor.
 *
 * Failure is ALWAYS silent: a reply that posted must never fail because the bell
 * was down. Every call is fire-and-forget with a short timeout, errors go to the log.
 */

if (defined('LG_NOTIFY_BRIDGE_LOADED')) return;
define('LG_NOTIFY_BRIDGE_LOADED', true);

/** Where the hub is mounted. bb-mirror defines this; archive-poc doesn't. */
function lg_notify_forum_base(): string
{
    $base = defined('LG_BB_MIRROR_PUBLIC_PATH') ? (string) LG_BB_MIRROR_PUBLIC_PATH : '/hub';
    return rtrim($base, '/') ?: '/hub';
}

/**
 * The deep link for a topic, optionally anchored on a reply.
 * Encoded exactly like forums.js's shareUrl() (rawurlencode → %2F), which its
 * parseTopicParam() decodes back. Returns '' when the slugs can't be resolved —
 * callers then skip the notification rather than raise one that lands nowhere.
 */
function lg_notify_topic_url(int $topic_id, int $reply_id = 0): string
{
    if ($topic_id < 1 || !function_exists('bbp_get_topic_forum_id')) return '';
    $topic_slug = (string) get_post_field('post_name', $topic_id);
    $forum_id   = (int) bbp_get_topic_forum_id($topic_id);
    $forum_slug = $forum_id ? (string) get_post_field('post_name', $forum_id) : '';
    if ($topic_slug === '' || $forum_slug === '') return '';

    $url = lg_notify_forum_base() . '/?topic=' . rawurlencode($forum_slug . '/' . $topic_slug);
    if ($reply_id > 0) $url .= '&reply=' . $reply_id;
    return $url;
}

/**
 * Fire one notification at the bell. Fire-and-forget: never throws, never blocks
 * the caller's write for long.
 */
function lg_notify_push(array $ev): void
{
    $secret = @file_get_contents('/etc/lg-internal-secret');
    if (!is_string($secret) || trim($secret) === '') {
        error_log('[lg-notify] no internal secret readable — notification dropped');
        return;
    }
    // https://127.0.0.1 + Host header + no peer verify — the SAME loopback convention
    // the whoami shim uses (profile-app/deploy/profile-whoami-shim.mu-plugin.php:42).
    // Plain http:// gets a 301 to https from the vhost and the POST body is lost.
    $host = defined('LG_BB_MIRROR_HOST') ? LG_BB_MIRROR_HOST
          : (defined('LG_ARCHIVE_POC_HOST') ? LG_ARCHIVE_POC_HOST : 'localhost');
    $payload = wp_json_encode($ev);
    $ch = curl_init('https://127.0.0.1/profile-api/v0/internal/notify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,          // the reply write must not hang on the bell
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_SSL_VERIFYPEER => false,      // loopback to ourselves; the cert is for the public host
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-LG-Internal-Auth: ' . trim($secret),
            'Host: ' . $host,
        ],
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code !== 200) {
        error_log('[lg-notify] ' . ($ev['type'] ?? '?') . ' → wp:' . ($ev['recipient_wp_id'] ?? '?')
                  . ' http=' . $code . ($err ? (' curl=' . $err) : '') . ' body=' . substr((string) $res, 0, 200));
    }
}

/**
 * @mentions in a post body → [wp_user_id, …] (deduped, mentioner excluded).
 *
 * ⚠️ THE SEAM FOR THE `username-mentions` LANE. That lane owns the mention DATA
 * (stable uuid + current-slug rendering); this lane owns the BELL. When its parse
 * lands, replace the BODY of this ONE function with a call into it — every caller,
 * link, dedup rule and surface below keeps working untouched. Nothing else in the
 * notification path knows how a mention is spelled.
 *
 * Today: BuddyBoss's own bp_activity_find_mentions() returns nothing on this box
 * (the activity component it depends on is strangled), so we resolve @name against
 * user_nicename via bp_core_get_userid_from_nicename() — verified working, and the
 * same identity the composer's autocomplete inserts. Tags are stripped first so a
 * mention already linkified by BB's filters still matches. The (^|\s|>|\() guard
 * keeps us from reading an email address as a mention.
 */
function lg_notify_find_mentions(string $content, int $exclude_wp_id = 0): array
{
    $ids = [];

    // username-mentions lane (2026-07-23): the write side mints every resolved
    // mention into the canonical anchor whose href carries the WP id directly —
    // {{mention_user_id_N}}. Parse that FIRST, from the RAW content (the id lives
    // in an attribute, so it must be read before tags are stripped). This is the
    // authoritative identity — profile-app slugs never equal WP nicenames, which
    // is why the nicename path below rang nobody for autocompleted mentions.
    if (preg_match_all('/\{\{mention_user_id_(\d+)\}\}/', $content, $mm)) {
        foreach ($mm[1] as $wid) {
            $wid = (int) $wid;
            if ($wid > 0 && $wid !== $exclude_wp_id) $ids[$wid] = true;
        }
    }

    // Fallback: hand-typed @name the minter could not resolve, and legacy content
    // — the original nicename path. Minted anchors also hit this via their @slug
    // text; that either resolves to the same id (deduped) or to nobody (skipped).
    $text = trim(wp_strip_all_tags($content));
    if ($text !== '' && strpos($text, '@') !== false
        && preg_match_all('/(?:^|[\s>(\[])@([A-Za-z0-9_.\-]{1,60})/u', $text, $m)) {
        foreach (array_unique($m[1]) as $name) {
            $name = rtrim($name, '.');                 // trailing sentence period
            if ($name === '') continue;
            $uid = 0;
            if (function_exists('bp_core_get_userid_from_nicename')) {
                $uid = (int) bp_core_get_userid_from_nicename($name);
            }
            if (!$uid) {
                $u = get_user_by('slug', $name) ?: get_user_by('login', $name);
                $uid = $u ? (int) $u->ID : 0;
            }
            if ($uid > 0 && $uid !== $exclude_wp_id) $ids[$uid] = true;
        }
    }

    return array_keys($ids);
}

/**
 * A reply was published → ring everyone it concerns. Called by bb-mirror reply.php.
 *
 * Exactly ONE notification per person per event, by design:
 *   - reply to your TOPIC      → forum.reply_to_topic, one row per topic while unread
 *                                (a 2nd replier coalesces: "Alice and 1 other replied"),
 *                                link always re-pointed at the NEWEST reply.
 *   - reply to your REPLY      → forum.reply_to_reply, one row per parent reply.
 *   - @mention                 → forum.mention, one row per mentioning reply.
 * A person who is BOTH mentioned and replied-to gets the more specific event only —
 * the mention wins, then reply-to-reply, then reply-to-topic. Nobody gets two rows
 * for one reply, and nobody is ever notified about their own reply.
 */
function lg_notify_on_reply(int $topic_id, int $reply_id, int $author_id, int $parent_reply_id, string $content): void
{
    if ($topic_id < 1 || $reply_id < 1) return;

    $url = lg_notify_topic_url($topic_id, $reply_id);
    if ($url === '') return;                       // no resolvable deep link → don't raise a dead row

    $notified = [$author_id => true];              // never notify yourself

    // 1. @mentions — the most specific signal, so it claims its recipients first.
    foreach (lg_notify_find_mentions($content, $author_id) as $mentioned_id) {
        if (isset($notified[$mentioned_id])) continue;
        $notified[$mentioned_id] = true;
        lg_notify_push([
            'recipient_wp_id' => $mentioned_id,
            'actor_wp_id'     => $author_id,
            'type'            => 'forum.mention',
            'target_kind'     => 'reply',
            'target_id'       => $topic_id,        // the modal opens a TOPIC…
            'anchor_id'       => $reply_id,        // …scrolled to the reply that mentioned you
            'target_url'      => $url,
        ]);
    }

    // 2. Reply to a reply → the parent reply's author.
    if ($parent_reply_id > 0) {
        $parent_author = (int) get_post_field('post_author', $parent_reply_id);
        if ($parent_author > 0 && !isset($notified[$parent_author])) {
            $notified[$parent_author] = true;
            lg_notify_push([
                'recipient_wp_id' => $parent_author,
                'actor_wp_id'     => $author_id,
                'type'            => 'forum.reply_to_reply',
                'target_kind'     => 'reply',
                'target_id'       => $topic_id,
                'anchor_id'       => $parent_reply_id,   // dedup scope = "replies to THIS comment of mine"
                'target_url'      => $url,               // …but the LINK lands on the new reply
            ]);
        }
    }

    // 3. Reply to the topic → the topic author.
    $topic_author = (int) get_post_field('post_author', $topic_id);
    if ($topic_author > 0 && !isset($notified[$topic_author])) {
        lg_notify_push([
            'recipient_wp_id' => $topic_author,
            'actor_wp_id'     => $author_id,
            'type'            => 'forum.reply_to_topic',
            'target_kind'     => 'topic',
            'target_id'       => $topic_id,
            'anchor_id'       => 0,                // NULL in the dedup key → ONE row per topic…
            'target_url'      => $url,             // …whose link re-points at the newest reply on coalesce
        ]);
    }
}

/**
 * A NEW TOPIC (discussion) was published → ring everyone @mentioned in its body.
 * Called by the bb-mirror-sync mu-plugin on bbp_new_topic — the native BuddyBoss
 * create path, which (unlike replies) never goes through reply.php, so nothing else
 * mints or rings for it (username-mentions lane, 2026-07-23).
 *
 * Only @mentions apply: a brand-new topic has no reply-to-topic / reply-to-reply
 * relationship, and the deep link lands on the topic itself (no reply anchor).
 * Mirrors the mention leg of lg_notify_on_reply exactly — same event type, same
 * dedup shape (one forum.mention row per mentioning post) — so the bell UI, links
 * and coalescing all keep working untouched.
 */
function lg_notify_on_topic(int $topic_id, int $author_id, string $content): void
{
    if ($topic_id < 1) return;

    $url = lg_notify_topic_url($topic_id);
    if ($url === '') return;                       // no resolvable deep link → don't raise a dead row

    foreach (lg_notify_find_mentions($content, $author_id) as $mentioned_id) {
        lg_notify_push([
            'recipient_wp_id' => $mentioned_id,
            'actor_wp_id'     => $author_id,
            'type'            => 'forum.mention',
            'target_kind'     => 'topic',          // the modal opens the topic itself
            'target_id'       => $topic_id,
            'anchor_id'       => 0,                 // no reply to scroll to on a fresh topic
            'target_url'      => $url,
        ]);
    }
}

/**
 * Someone reacted to a card → ring its author. Called by archive-poc card-react.php
 * ONLY when a reaction was ADDED (toggling your own reaction off must not notify).
 *
 * Cards are heterogeneous: bbPress topics/replies land in the discussion modal;
 * managed-CPT cards (videos, articles, sponsor posts…) have no modal, so they land
 * on their own permalink — still "the exact thing it is about".
 */
function lg_notify_on_reaction(string $post_type, int $item_id, int $actor_id): void
{
    if ($item_id < 1) return;
    $author_id = (int) get_post_field('post_author', $item_id);
    if ($author_id < 1 || $author_id === $actor_id) return;

    if ($post_type === 'topic') {
        $url = lg_notify_topic_url($item_id);
        if ($url === '') return;
        $kind = 'topic'; $target = $item_id; $anchor = 0;
    } elseif ($post_type === 'reply') {
        $topic_id = function_exists('bbp_get_reply_topic_id') ? (int) bbp_get_reply_topic_id($item_id) : 0;
        if ($topic_id < 1) return;
        $url = lg_notify_topic_url($topic_id, $item_id);
        if ($url === '') return;
        $kind = 'reply'; $target = $topic_id; $anchor = $item_id;
    } else {
        // Managed CPT card — its own page is the thing.
        $permalink = (string) get_permalink($item_id);
        $path      = $permalink ? (string) wp_parse_url($permalink, PHP_URL_PATH) : '';
        if ($path === '') return;
        $url = $path; $kind = 'card'; $target = $item_id; $anchor = 0;
    }

    lg_notify_push([
        'recipient_wp_id' => $author_id,
        'actor_wp_id'     => $actor_id,
        'type'            => 'reaction.on_post',
        'target_kind'     => $kind,
        'target_id'       => $target,
        'anchor_id'       => $anchor,
        'target_url'      => $url,
    ]);
}
