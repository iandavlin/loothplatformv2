<?php
/**
 * bb-mirror/api/v0/reply.php — server-side bbPress reply-write endpoint.
 *
 * POST /bb-mirror-api/v0/reply   (runs on the WP FPM pool, cookie-authed)
 *   body (JSON): { topic_id:int, content:string(html), reply_to?:int, media_ids?:int[] }
 *
 * The ONE owned reply-write path for the stack — the /stream/ inline-reply UI and
 * the /hub/ reply forms both wire to this. It reuses BuddyBoss's reply handler
 * in-process via rest_do_request() (so media attach, reply/topic counts, BB
 * notifications, and the bb→pg sync hooks all fire exactly as in the native path),
 * but wraps it in ONE clean contract and explicitly handles the ~10s flood
 * throttle (clean 429 + retry_after) and moderation (202 pending).
 *
 * Auth: the same-origin WordPress login cookie (the browser sends it). bbPress is
 * WP, so writes need the WP user. looth_id-only (JWT, no WP cookie) auth is a
 * future enhancement — would map sub→wp_user_id and wp_set_current_user().
 *
 * Contract: docs/reply-write-endpoint.md
 */

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function reply_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    reply_out(405, ['ok' => false, 'error' => 'method', 'message' => 'POST/PUT/DELETE only.']);
}

$uid = get_current_user_id();
if (!$uid) {
    reply_out(401, ['ok' => false, 'error' => 'auth', 'message' => 'Sign in to reply.']);
}

// Moderator/keymaster/admin — the authoritative "edit or delete anyone's post"
// gate, used by every edit/delete branch below. auth.php's can_edit_others
// mirrors this EXACTLY so the ⋯ menu UI-reveal and this server enforcement always
// agree. 'administrator' is a role name (not a cap), so admins are caught via
// 'manage_options'; 'moderate'/'keep_gate' cover the bbPress keymaster + gate
// roles (Ian 2026-06-25).
$is_mod_viewer = current_user_can('moderate')
    || current_user_can('keep_gate')
    || current_user_can('manage_options');

$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

// ── EDIT (PUT) / DELETE — own reply OR moderator (Ian 2026-06-11: members can
//    edit AND delete their own replies, no time limit, hard remove). The native
//    BuddyBoss DELETE is moderators-only, so we own the policy here. ──────────
if ($method === 'PUT' || $method === 'DELETE') {
    // ── DELETE a whole TOPIC (the OP/post) — author-or-moderator, same policy as
    //    replies (Ian 2026-06-15: delete-post in the modal, author + admin). The
    //    bb-forum-author-delete mu-plugin maps delete_topic to an author-scoped
    //    primitive; mods/admins delete others' via `moderate`. ──────────────────
    if ($method === 'DELETE' && (int) ($body['reply_id'] ?? 0) <= 0 && (int) ($body['topic_id'] ?? 0) > 0) {
        $topic_id_del = (int) $body['topic_id'];
        if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_WP_NONCE'] ?? ''), 'wp_rest')) {
            reply_out(403, ['ok' => false, 'error' => 'nonce', 'message' => 'Session expired — reload and retry.']);
        }
        if (!function_exists('bbp_get_topic_post_type')) {
            reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Forum engine unavailable.']);
        }
        $topic = get_post($topic_id_del);
        if (!$topic || $topic->post_type !== bbp_get_topic_post_type()) {
            reply_out(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Post not found.']);
        }
        // Author-or-moderator; author taken from the stored post (IDOR-proof), then
        // the cap (author-scoped via the mu-plugin) is the authoritative gate.
        $t_is_author = ((int) $topic->post_author === (int) $uid);
        $t_is_mod    = $is_mod_viewer;
        if ((!$t_is_author && !$t_is_mod) || !current_user_can('delete_topic', $topic_id_del)) {
            reply_out(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'You can only delete your own posts.']);
        }
        // Hard remove (Ian: not a tombstone). before_delete_post → bbp_deleted_topic
        // → the bb→pg sync 'delete' drops the topic (and its replies) from the mirror.
        $del = wp_delete_post($topic_id_del, true);
        if (!$del) {
            reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Could not delete the post.']);
        }
        if (function_exists('bb_mirror_sync_dispatch')) bb_mirror_sync_dispatch('topic', $topic_id_del, 'delete');
        reply_out(200, ['ok' => true, 'status' => 'deleted', 'topic_id' => $topic_id_del]);
    }

    // ── EDIT (PUT) a whole TOPIC (the OP title + body) — author-or-moderator,
    //    mirrors the reply-edit PUT below (Ian 2026-06-25: members edit their own
    //    posts via the FB-style ⋯ menu). The owned, IDOR-proof twin of the
    //    topic-delete path above, so the Hub has ONE authoritative author-or-mod
    //    gate for both topic edit and topic delete (vs. the native BuddyBoss
    //    topics PUT, whose ownership rules we don't control). ────────────────────
    if ($method === 'PUT' && (int) ($body['reply_id'] ?? 0) <= 0 && (int) ($body['topic_id'] ?? 0) > 0) {
        $topic_id_edit = (int) $body['topic_id'];
        if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_WP_NONCE'] ?? ''), 'wp_rest')) {
            reply_out(403, ['ok' => false, 'error' => 'nonce', 'message' => 'Session expired — reload and retry.']);
        }
        if (!function_exists('bbp_get_topic_post_type')) {
            reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Forum engine unavailable.']);
        }
        $topic = get_post($topic_id_edit);
        if (!$topic || $topic->post_type !== bbp_get_topic_post_type()) {
            reply_out(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Post not found.']);
        }
        // Author-or-moderator; the author is taken from the stored post (never the
        // client — IDOR-proof), exactly like the reply-edit + topic-delete paths.
        $t_is_author = ((int) $topic->post_author === (int) $uid);
        $t_is_mod    = $is_mod_viewer;
        if (!$t_is_author && !$t_is_mod) {
            reply_out(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'You can only edit your own posts.']);
        }
        $new_body  = trim((string) ($body['content'] ?? ''));
        $new_title = trim((string) ($body['title'] ?? ''));
        if ($new_body === '') {
            reply_out(400, ['ok' => false, 'error' => 'invalid', 'message' => "Post can't be empty."]);
        }
        // wp_update_post kses-filters post_content (and sanitizes post_title) for
        // users without unfiltered_html. Title is optional — keep the stored one
        // when the client omits it (body-only edits).
        $update = ['ID' => $topic_id_edit, 'post_content' => $new_body];
        if ($new_title !== '') $update['post_title'] = $new_title;
        $upd = wp_update_post($update, true);
        if (is_wp_error($upd)) {
            reply_out(500, ['ok' => false, 'error' => 'server', 'message' => (string) $upd->get_error_message()]);
        }
        // wp_update_post doesn't fire bbp_edit_topic, so sync the PG mirror
        // explicitly ('upsert' is the same action the bbp_edit_topic hook maps to).
        if (function_exists('bb_mirror_sync_dispatch')) bb_mirror_sync_dispatch('topic', $topic_id_edit, 'upsert');
        $fresh = get_post($topic_id_edit);
        reply_out(200, [
            'ok'           => true,
            'status'       => 'edited',
            'topic_id'     => $topic_id_edit,
            'title'        => (string) $fresh->post_title,
            'content_html' => (string) apply_filters('bbp_get_topic_content', $fresh->post_content, $topic_id_edit),
        ]);
    }

    $reply_id = (int) ($body['reply_id'] ?? 0);
    if ($reply_id <= 0) {
        reply_out(400, ['ok' => false, 'error' => 'invalid', 'message' => 'reply_id is required.']);
    }
    // CSRF: the same wp_rest nonce the auth endpoint mints (X-WP-Nonce header).
    if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_WP_NONCE'] ?? ''), 'wp_rest')) {
        reply_out(403, ['ok' => false, 'error' => 'nonce', 'message' => 'Session expired — reload and retry.']);
    }
    if (!function_exists('bbp_get_reply_post_type')) {
        reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Forum engine unavailable.']);
    }
    $reply = get_post($reply_id);
    if (!$reply || $reply->post_type !== bbp_get_reply_post_type()) {
        reply_out(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Reply not found.']);
    }
    // Author-or-moderator. The reply author is taken from the stored post, never
    // the client (IDOR-proof) — same contract as the create path's flood check.
    $is_author = ((int) $reply->post_author === (int) $uid);
    $is_mod    = $is_mod_viewer;
    if (!$is_author && !$is_mod) {
        reply_out(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'You can only edit or delete your own replies.']);
    }

    if ($method === 'DELETE') {
        // Hard remove (Ian: not a tombstone). wp_delete_post(force) fires
        // before_delete_post → bbp_deleted_reply → the bb→pg sync 'delete'.
        $del = wp_delete_post($reply_id, true);
        if (!$del) {
            reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Could not delete the reply.']);
        }
        if (function_exists('bb_mirror_sync_dispatch')) bb_mirror_sync_dispatch('reply', $reply_id, 'delete'); // belt-and-suspenders
        reply_out(200, ['ok' => true, 'status' => 'deleted', 'reply_id' => $reply_id]);
    }

    // PUT — edit content. wp_update_post kses-filters for non-unfiltered_html users.
    $new = trim((string) ($body['content'] ?? ''));
    if ($new === '') {
        reply_out(400, ['ok' => false, 'error' => 'invalid', 'message' => "Reply can't be empty."]);
    }
    $upd = wp_update_post(['ID' => $reply_id, 'post_content' => $new], true);
    if (is_wp_error($upd)) {
        reply_out(500, ['ok' => false, 'error' => 'server', 'message' => (string) $upd->get_error_message()]);
    }
    // wp_update_post doesn't fire bbp_edit_reply, so sync the PG mirror explicitly.
    if (function_exists('bb_mirror_sync_dispatch')) bb_mirror_sync_dispatch('reply', $reply_id, 'upsert');
    $fresh = get_post($reply_id);
    reply_out(200, [
        'ok'           => true,
        'status'       => 'edited',
        'reply_id'     => $reply_id,
        'content_html' => (string) apply_filters('bbp_get_reply_content', $fresh->post_content, $reply_id),
    ]);
}

// ── CREATE (POST) ───────────────────────────────────────────────────────────
// CSRF: the same wp_rest nonce the PUT/DELETE path verifies (X-WP-Nonce header).
// rest_do_request() below runs the BuddyBoss handler in-process and so bypasses
// the REST nonce gate — verify here. The client fetches this nonce from auth.php.
if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_WP_NONCE'] ?? ''), 'wp_rest')) {
    reply_out(403, ['ok' => false, 'error' => 'nonce', 'message' => 'Session expired — reload and retry.']);
}

$topic_id = (int) ($body['topic_id'] ?? 0);
$content  = trim((string) ($body['content'] ?? ''));
$reply_to = (int) ($body['reply_to'] ?? 0);
$media    = array_values(array_filter(array_map('intval', (array) ($body['media_ids'] ?? []))));

if ($topic_id <= 0) {
    reply_out(400, ['ok' => false, 'error' => 'invalid', 'message' => 'topic_id is required.']);
}
if ($content === '' && !$media) {
    reply_out(400, ['ok' => false, 'error' => 'invalid', 'message' => "Reply can't be empty."]);
}
if (!function_exists('bbp_get_topic_post_type')) {
    reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Forum engine unavailable.']);
}

// Target must be a real, published, open discussion.
$topic = get_post($topic_id);
if (!$topic || $topic->post_type !== bbp_get_topic_post_type() || $topic->post_status !== 'publish') {
    reply_out(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Discussion not found.']);
}
if (bbp_is_topic_closed($topic_id)) {
    reply_out(403, ['ok' => false, 'error' => 'closed', 'message' => 'This discussion is closed.']);
}
$forum_id = (int) bbp_get_topic_forum_id($topic_id);

// ── Flood throttle (~10s per author; keymasters/mods bypass, mirroring bbPress).
//    Pre-check so callers get a clean 429 + retry_after instead of a generic error.
$throttle = (int) get_option('_bbp_throttle_time', 10);
$bypass   = current_user_can('moderate') || current_user_can('keep_gate');
if ($throttle > 0 && !$bypass) {
    $last    = (int) get_user_meta($uid, '_bbp_last_posted', true);
    $elapsed = time() - $last;
    if ($last && $elapsed < $throttle) {
        $retry = max(1, $throttle - $elapsed);
        header('Retry-After: ' . $retry);
        reply_out(429, [
            'ok' => false, 'error' => 'flood', 'retry_after' => $retry,
            'message' => "You're posting too fast — wait {$retry}s and try again.",
        ]);
    }
}

// ── Insert via BuddyBoss REST in-process. Reuses media + counts + notifications
//    + the bb→pg sync hooks; permission_callback re-checks the viewer server-side.
$req = new WP_REST_Request('POST', '/buddyboss/v1/reply');
$req->set_param('topic_id', $topic_id);
$req->set_param('forum_id', $forum_id);
if ($content !== '') $req->set_param('content', $content);
if ($reply_to > 0)   $req->set_param('reply_to', $reply_to);
if ($media)          $req->set_param('bbp_media', $media);

$res = rest_do_request($req);

if ($res->is_error()) {
    $err  = $res->as_error();
    $code = (string) $err->get_error_code();
    $msg  = (string) $err->get_error_message();
    if (str_contains($code, 'flood') || stripos($msg, 'too quickly') !== false || stripos($msg, 'wait') !== false) {
        header('Retry-After: ' . $throttle);
        reply_out(429, ['ok' => false, 'error' => 'flood', 'retry_after' => $throttle, 'message' => $msg]);
    }
    $status = (int) $res->get_status();
    reply_out($status >= 400 ? $status : 400, ['ok' => false, 'error' => $code ?: 'failed', 'message' => $msg ?: 'Reply failed.']);
}

$data     = (array) $res->get_data();
$reply_id = (int) ($data['id'] ?? 0);
if ($reply_id <= 0) {
    reply_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Reply was not created.']);
}

// Belt-and-suspenders throttle bookkeeping for our own pre-check.
update_user_meta($uid, '_bbp_last_posted', time());

// Anonymous REPLIES retired 2026-06-10 (Ian: "we don't want anon replies.
// Just anon posts."). The composer toggle is gone from the reply modal and
// this endpoint no longer honors `_lg_anon` on replies — anonymity remains a
// new-TOPIC feature only. (Existing anon replies keep their stored meta.)

$reply = get_post($reply_id);

// Moderation: held replies come back pending/spam.
if ($reply && in_array($reply->post_status, ['pending', 'spam'], true)) {
    reply_out(202, [
        'ok' => true, 'status' => 'pending', 'reply_id' => $reply_id,
        'message' => 'Your reply was submitted and is awaiting moderation.',
    ]);
}

// Published — return everything a surface needs for an optimistic insert.
$u          = wp_get_current_user();
$forum_slug = get_post_field('post_name', $forum_id);
$permalink  = LG_BB_MIRROR_PUBLIC_PATH . '/' . $forum_slug . '/' . $topic->post_name . '/#reply-' . $reply_id;

reply_out(200, [
    'ok'              => true,
    'status'          => 'published',
    'reply_id'        => $reply_id,
    'topic_id'        => $topic_id,
    'parent_reply_id' => $reply_to ?: null,
    'author'          => [
        'wp_user_id'   => (int) $uid,
        'display_name' => (string) $u->display_name,
        'slug'         => $u->user_nicename ?: null,
        'avatar_url'   => get_avatar_url($uid, ['size' => 96]) ?: null,
    ],
    'content_html'    => (string) apply_filters('bbp_get_reply_content', $reply->post_content, $reply_id),
    'created_at'      => mysql2date('c', $reply->post_date_gmt, false),
    'permalink'       => $permalink,
]);
