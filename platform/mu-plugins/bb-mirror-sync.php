<?php
/**
 * Plugin Name: BB-Mirror Sync
 * Description: Posts {kind, id, action} to bb-mirror's /api/v0/_sync endpoint
 *              on bbPress edit events. Non-blocking, loopback only. DRAFT —
 *              not yet deployed to /var/www/dev/wp-content/mu-plugins/.
 * Version:     0.0.1-draft
 *
 * Status: NOT ACTIVATED. Schema is still draft. Drop into mu-plugins
 *         AFTER schema.sql is ratified and bin/init-db.php has created
 *         the SQLite file. See ../SESSION-HANDOFF.md.
 *
 * Pattern lifted from archive-poc-sync.php — same dispatcher shape, same
 * loopback-only constraint, same timeout=1s/blocking=false.
 *
 * Hooks (bbPress, shipped inside BuddyBoss Platform 2.20.x):
 *   bbp_new_topic            — topic created
 *   bbp_edit_topic           — topic edited
 *   bbp_trashed_topic        — soft-delete
 *   bbp_untrashed_topic      — restore
 *   bbp_spammed_topic        — marked spam
 *   bbp_unspammed_topic      — unmarked spam
 *   bbp_deleted_topic        — hard-delete
 *   bbp_stick_topic / bbp_unstick_topic — sticky toggle
 *   bbp_closed_topic / bbp_opened_topic — close toggle
 *   bbp_merged_topic         — destination_id receives the merged content
 *   bbp_post_split_topic     — new topic created from a reply
 *
 *   bbp_new_reply / bbp_edit_reply / bbp_trashed_reply / bbp_untrashed_reply
 *   bbp_spammed_reply / bbp_unspammed_reply / bbp_deleted_reply
 *
 *   bbp_new_forum / bbp_edit_forum (rare, but visibility/closure changes here)
 *
 *   bbp_subscriptions_handler — subscribe/unsubscribe (user_id, target_id)
 *
 * Identity is intentionally NOT pushed by these hooks. bb-mirror's indexer
 * fetches author display data from the poller's user-context endpoint
 * (GET /wp-json/looth-internal/v1/user-context/{wp_user_id}) when it
 * materializes a row. Single round-trip per author, cached.
 */

if (!defined('ABSPATH')) exit;

if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';

const BB_MIRROR_SYNC_URL = 'https://127.0.0.1/bb-mirror-api/v0/_sync';

if (!function_exists('bb_mirror_sync_host')) {
/**
 * Resolve the loopback Host header per box. Precedence:
 *   1. BB_MIRROR_SYNC_HOST_OVERRIDE define (wp-config escape hatch)
 *   2. BB_MIRROR_SYNC_HOST env var (escape hatch)
 *   3. /etc/looth/env shared host via lg_env() -- the box-static migration source
 *      (dev1->dev.loothgroup.com, dev2->dev2.loothgroup.com, prod->loothgroup.com)
 *   4. request-host detection (dev.* / claude.loothgroup -> dev, else live)
 *   5. 'loothgroup.com' final fallback
 * lg_env() is the preferred source (matches the live deployed form); the override
 * hooks + request detection are retained from origin/main so no escape hatch is lost. */
function bb_mirror_sync_host(): string {
    if (defined('BB_MIRROR_SYNC_HOST_OVERRIDE')) return (string) constant('BB_MIRROR_SYNC_HOST_OVERRIDE');
    $env = getenv('BB_MIRROR_SYNC_HOST');
    if ($env !== false && $env !== '') return $env;
    if (function_exists('lg_env')) {
        $h = lg_env()['host'] ?? '';
        if ($h !== '') return $h;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (str_contains($host, 'dev.') || str_contains($host, 'claude.loothgroup')) {
        return 'dev.loothgroup.com';
    }
    return 'loothgroup.com';
}
}

if (!function_exists('bb_mirror_sync_dispatch')) {
function bb_mirror_sync_dispatch(string $kind, int $id, string $action = 'upsert', array $extra = []): void {
    if ($id <= 0) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $payload = wp_json_encode(array_merge([
        'kind'   => $kind,    // 'forum' | 'topic' | 'reply' | 'subscription'
        'id'     => $id,
        'action' => $action,  // 'upsert' | 'delete' | 'spam' | 'trash' | 'restore'
    ], $extra));

    wp_remote_post(BB_MIRROR_SYNC_URL, [
        'method'    => 'POST',
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => false,
        'headers'   => [
            'Host'         => bb_mirror_sync_host(),
            'Content-Type' => 'application/json',
            'X-BB-Mirror-Sync' => '1',
        ],
        'body' => $payload,
    ]);
}
}

// -- Anonymous-posting capture (anon-rebuild lane) ---------------------------
// The Hub composer ("Post anonymously" toggle) sends `_lg_anon` with the
// topic/reply write. Forum writes ride bbPress via JSON REST (BuddyBoss
// /topics, /reply, or bb-mirror's reply.php which forwards in-process), so the
// flag arrives in the JSON request body, NOT $_REQUEST. We read it once from
// php://input (re-readable for application/json under PHP-FPM) and stamp the
// `_lg_anon` post meta on bbp_new_topic / bbp_new_reply. The materializer then
// carries the meta into forums.{topic,reply}.is_anon at sync (deferred to
// shutdown, after this meta is written). Absence = not anonymous; we never
// write a 0 (keeps meta clean + the materializer reads !empty).
if (!function_exists('bb_mirror_request_anon_flag')) {
function bb_mirror_request_anon_flag(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = false;
    if (isset($_REQUEST['_lg_anon'])) {
        $cached = (bool)(int)$_REQUEST['_lg_anon'];
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '' && $raw[0] === '{') {
        $body = json_decode($raw, true);
        if (is_array($body) && !empty($body['_lg_anon'])) $cached = true;
    }
    return $cached;
}
}
add_action('bbp_new_topic', function ($topic_id) {
    if (bb_mirror_request_anon_flag()) update_post_meta((int)$topic_id, '_lg_anon', 1);
}, 5, 1);   // prio 5: BEFORE the prio-99 deferred sync dispatch queues
add_action('bbp_new_reply', function ($reply_id) {
    if (bb_mirror_request_anon_flag()) update_post_meta((int)$reply_id, '_lg_anon', 1);
}, 5, 1);

// -- @mention mint + bell for NEW TOPICS (username-mentions lane, 2026-07-23) -----
// New discussions POST to NATIVE BuddyBoss REST (/wp-json/buddyboss/v1/topics); they
// never touch bb-mirror's reply.php, so — unlike replies — nothing mints their
// @mentions into the stable storage anchor or rings the mentioned member's bell.
// Do it here on the post-insert action, mirroring reply.php EXACTLY:
//   1. Re-mint the SAVED post_content with kses OFF. BuddyBoss's insert sanitizes a
//      pre-minted anchor away (proven in reply.php 2026-07-23), so a pre_content
//      filter would be stripped; the canonical anchor is already escaped, so we write
//      it back kses-off. wp_update_post re-fires the save hooks, so the bb->pg mirror
//      carries the anchor too. Idempotent: no resolvable @token round-trips unchanged.
//   2. Ring every @mentioned member via the shared notify-bridge (forum.mention).
// Fire-and-forget: a published topic must never fail because minting or the bell is
// down (both legs swallow their own errors).
add_action('bbp_new_topic', function ($topic_id) {
    static $inMint = false;
    if ($inMint) return;                            // the wp_update_post below must not re-enter
    $topic_id = (int) $topic_id;
    if ($topic_id < 1) return;
    if (!is_file('/srv/bb-mirror/api/v0/_mention-ingest.php')) return;

    // The mint resolves @handles over a loopback to /profile-api. LG_BB_MIRROR_HOST is a
    // bb-mirror API constant, undefined in this WP mu-plugin context — without it the
    // resolve loopback Host defaults to 'localhost' and 404s. Seed it from the same
    // per-box host the sync dispatcher already resolves.
    if (!defined('LG_BB_MIRROR_HOST')) define('LG_BB_MIRROR_HOST', bb_mirror_sync_host());
    require_once '/srv/bb-mirror/api/v0/_mention-ingest.php';
    if (!function_exists('lg_bb_mirror_mint_mentions')) return;

    $topic = get_post($topic_id);
    if (!$topic) return;

    $minted = lg_bb_mirror_mint_mentions((string) $topic->post_content);
    if ($minted !== (string) $topic->post_content) {
        $inMint = true;
        kses_remove_filters();
        wp_update_post(['ID' => $topic_id, 'post_content' => $minted]);
        kses_init_filters();
        $inMint = false;
    }

    if (is_file('/srv/lg-shared/notify-bridge.php')) {
        require_once '/srv/lg-shared/notify-bridge.php';
        if (function_exists('lg_notify_on_topic')) {
            lg_notify_on_topic($topic_id, (int) $topic->post_author, $minted);
        }
    }
}, 20, 1);   // prio 20: AFTER anon-meta (5), before the shutdown-deferred sync reads content

// Deferred dispatch: fires on `shutdown` instead of immediately. Needed for
// topic/reply CREATE + EDIT because BuddyBoss attaches forum media via an
// `edit_post` priority-999 hook that runs AFTER bbp_new_topic/bbp_new_reply.
// Dispatching at prio 99 reads the post before `bp_media_ids` is committed, so
// the mirror misses freshly-attached images. By shutdown the meta is written.
// De-dupes per (kind,id,action) so multiple hooks in one request fire once.
if (!function_exists('bb_mirror_sync_dispatch_deferred')) {
function bb_mirror_sync_dispatch_deferred(string $kind, int $id, string $action = 'upsert', array $extra = []): void {
    if ($id <= 0) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    static $queued = [];
    $key = $kind . ':' . $id . ':' . $action;
    if (isset($queued[$key])) return;
    $queued[$key] = true;
    add_action('shutdown', function () use ($kind, $id, $action, $extra) {
        bb_mirror_sync_dispatch($kind, $id, $action, $extra);
    }, 99);
}
}

// -- Forums (rare events, but visibility changes matter) ---------------------
add_action('bbp_new_forum',  function ($args) {
    $id = is_array($args) ? (int)($args['forum_id'] ?? 0) : (int)$args;
    bb_mirror_sync_dispatch('forum', $id, 'upsert');
}, 99, 1);
add_action('bbp_edit_forum', function ($args) {
    $id = is_array($args) ? (int)($args['forum_id'] ?? 0) : (int)$args;
    bb_mirror_sync_dispatch('forum', $id, 'upsert');
}, 99, 1);

// -- Topics ------------------------------------------------------------------
// new/edit defer to shutdown (media may attach at edit_post prio 999); the rest
// are immediate (no media involved).
$bb_mirror_topic_deferred = ['bbp_new_topic', 'bbp_edit_topic'];
foreach ([
    'bbp_new_topic'       => 'upsert',
    'bbp_edit_topic'      => 'upsert',
    'bbp_stick_topic'     => 'upsert',
    'bbp_unstick_topic'   => 'upsert',
    'bbp_closed_topic'    => 'upsert',
    'bbp_opened_topic'    => 'upsert',
    'bbp_trashed_topic'   => 'trash',
    'bbp_untrashed_topic' => 'restore',
    'bbp_spammed_topic'   => 'spam',
    'bbp_unspammed_topic' => 'upsert',
    'bbp_deleted_topic'   => 'delete',
] as $hook => $action) {
    $deferred = in_array($hook, $bb_mirror_topic_deferred, true);
    add_action($hook, function ($topic_id) use ($action, $deferred) {
        if ($deferred) bb_mirror_sync_dispatch_deferred('topic', (int)$topic_id, $action);
        else           bb_mirror_sync_dispatch('topic', (int)$topic_id, $action);
    }, 99, 1);
}

// Merge: destination_topic_id absorbs source. Both must reindex.
add_action('bbp_merged_topic', function ($destination_topic_id, $source_topic_id, $source_topic_forum_id) {
    bb_mirror_sync_dispatch('topic', (int)$destination_topic_id, 'upsert', ['merge_source' => (int)$source_topic_id]);
    bb_mirror_sync_dispatch('topic', (int)$source_topic_id,      'delete');
}, 99, 3);

// Split: a reply (or replies) becomes a new topic. New topic_id will fire
// bbp_new_topic separately; this hook flags the affected source topic for
// reply-count refresh.
add_action('bbp_post_split_topic', function ($from_reply_id, $source_topic_id, $destination_topic_id) {
    bb_mirror_sync_dispatch('topic', (int)$source_topic_id,      'upsert', ['split_from_reply' => (int)$from_reply_id]);
    bb_mirror_sync_dispatch('topic', (int)$destination_topic_id, 'upsert');
}, 99, 3);

// -- Replies -----------------------------------------------------------------
$bb_mirror_reply_deferred = ['bbp_new_reply', 'bbp_edit_reply'];
foreach ([
    'bbp_new_reply'       => 'upsert',
    'bbp_edit_reply'      => 'upsert',
    'bbp_trashed_reply'   => 'trash',
    'bbp_untrashed_reply' => 'restore',
    'bbp_spammed_reply'   => 'spam',
    'bbp_unspammed_reply' => 'upsert',
    'bbp_deleted_reply'   => 'delete',
] as $hook => $action) {
    $deferred = in_array($hook, $bb_mirror_reply_deferred, true);
    add_action($hook, function ($reply_id) use ($action, $deferred) {
        if ($deferred) bb_mirror_sync_dispatch_deferred('reply', (int)$reply_id, $action);
        else           bb_mirror_sync_dispatch('reply', (int)$reply_id, $action);
    }, 99, 1);
}

// -- Subscriptions -----------------------------------------------------------
// BB's `bbp_subscriptions_handler` fires for both forum + topic subscribe;
// we route by post_type lookup on the indexer side. Action verb is
// 'subscribe' or 'unsubscribe'.
add_action('bbp_subscriptions_handler', function ($success, $user_id, $object_id, $action) {
    if (!$success) return;
    bb_mirror_sync_dispatch('subscription', (int)$object_id, $action === 'bbp_subscribe' ? 'subscribe' : 'unsubscribe', [
        'user_id' => (int)$user_id,
    ]);
}, 99, 4);

// -- BP Groups ---------------------------------------------------------------
// Mirror wp_bp_groups into forums.bp_group. Group→forum attachment is needed
// for the "Local: <group>" pill and (eventually) write-gating against
// group membership. Hooks fire from BuddyBoss Platform's bp-groups component.
add_action('groups_create_group', function ($group_id) {
    bb_mirror_sync_dispatch('bp_group', (int)$group_id, 'upsert');
}, 99, 1);
add_action('groups_update_group', function ($group_id) {
    bb_mirror_sync_dispatch('bp_group', (int)$group_id, 'upsert');
}, 99, 1);
add_action('groups_before_delete_group', function ($group_id) {
    bb_mirror_sync_dispatch('bp_group', (int)$group_id, 'delete');
}, 99, 1);
// Group settings save (forum attachment, status changes etc.)
add_action('groups_settings_updated', function ($group_id) {
    bb_mirror_sync_dispatch('bp_group', (int)$group_id, 'upsert');
}, 99, 1);
// Member join/leave shifts the group's total_member_count — refresh.
add_action('groups_join_group', function ($group_id) {
    bb_mirror_sync_dispatch('bp_group', (int)$group_id, 'upsert');
}, 99, 1);
add_action('groups_leave_group', function ($group_id) {
    bb_mirror_sync_dispatch('bp_group', (int)$group_id, 'upsert');
}, 99, 1);
