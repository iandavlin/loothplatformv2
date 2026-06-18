<?php
/**
 * bb-mirror/api/v0/topic-media.php — read + edit a TOPIC's photo set.
 *
 * The BuddyBoss REST `PUT /topics/<id>` deliberately does NOT touch forum media on
 * edit (it unhooks bp_media_forums_new_post_media_save around wp_update_post and
 * never re-fires it on bbp_edit_topic — verified 2026-06-17). So the composer's
 * edit flow can change title/content via that PUT, but adding or REMOVING photos
 * has no path. This endpoint owns that: it sets the topic's photo set to exactly
 * what the editor kept (+ any newly uploaded), deleting the rest.
 *
 *   GET  /bb-mirror-api/v0/topic-media?topic_id=N
 *        → { ok, topic_id, media:[ { media_id, attachment_id, url, thumb, name } ] }
 *
 *   POST /bb-mirror-api/v0/topic-media   (X-WP-Nonce: wp_rest)
 *        body: { topic_id:int, keep_media_ids:int[], add_upload_ids:int[] }
 *        keep_media_ids = existing bp_media.id to KEEP; add_upload_ids = attachment
 *        ids freshly returned by /media/upload to ADD. Anything existing but not in
 *        keep is removed (bp_media_delete). Author-or-moderator only.
 *        → { ok, topic_id, media_ids:[...] }
 *
 * Reuses BuddyBoss's own bp_media_forums_new_post_media_save() (driven via $_POST)
 * so keep/add/delete + activity-meta + attachment cleanup behave EXACTLY like the
 * native create path — no reimplementation drift.
 *
 * Auth: same-origin WP login cookie (author = stored post_author, IDOR-proof) +
 * the wp_rest nonce (mints from auth.php). Mirrors reply.php's contract.
 */

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function tm_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

/** Resolve a topic's current photo set → list of {media_id, attachment_id, url, thumb, name}. */
function tm_media_list(int $topic_id): array {
    $csv = (string) get_post_meta($topic_id, 'bp_media_ids', true);
    if ($csv === '') return [];
    $out = [];
    foreach (array_filter(array_map('intval', explode(',', $csv))) as $mid) {
        $m = new BP_Media($mid);
        $att = (int) ($m->attachment_id ?? 0);
        if (!$att) continue;
        $url   = wp_get_attachment_image_url($att, 'large') ?: wp_get_attachment_url($att);
        $thumb = wp_get_attachment_image_url($att, 'thumbnail') ?: $url;
        $out[] = [
            'media_id'      => $mid,
            'attachment_id' => $att,
            'url'           => $url ?: null,
            'thumb'         => $thumb ?: null,
            'name'          => (string) ($m->title ?? ''),
            'menu_order'    => (int) ($m->menu_order ?? 0),
        ];
    }
    return $out;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

// Target topic id (query for GET, body for POST).
if ($method === 'GET') {
    $topic_id = (int) ($_GET['topic_id'] ?? 0);
} else {
    $body     = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $topic_id = (int) ($body['topic_id'] ?? 0);
}

if (!function_exists('bbp_get_topic_post_type')) {
    tm_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Forum engine unavailable.']);
}
if ($topic_id <= 0) {
    tm_out(400, ['ok' => false, 'error' => 'invalid', 'message' => 'topic_id is required.']);
}
$topic = get_post($topic_id);
if (!$topic || $topic->post_type !== bbp_get_topic_post_type()) {
    tm_out(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Post not found.']);
}

// ── READ ────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    tm_out(200, ['ok' => true, 'topic_id' => $topic_id, 'media' => tm_media_list($topic_id)]);
}

if ($method !== 'POST') {
    tm_out(405, ['ok' => false, 'error' => 'method', 'message' => 'GET or POST only.']);
}

// ── WRITE — author-or-moderator, nonce-gated (same as reply.php) ──────────────
$uid = get_current_user_id();
if (!$uid) {
    tm_out(401, ['ok' => false, 'error' => 'auth', 'message' => 'Sign in to edit photos.']);
}
if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_WP_NONCE'] ?? ''), 'wp_rest')) {
    tm_out(403, ['ok' => false, 'error' => 'nonce', 'message' => 'Session expired — reload and retry.']);
}
$is_author = ((int) $topic->post_author === (int) $uid);
$is_mod    = current_user_can('moderate') || current_user_can('keep_gate');
if ((!$is_author && !$is_mod) || !current_user_can('edit_topic', $topic_id)) {
    tm_out(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'You can only edit your own posts.']);
}
if (!function_exists('bp_media_forums_new_post_media_save')) {
    tm_out(500, ['ok' => false, 'error' => 'server', 'message' => 'Media component unavailable.']);
}

$keep_ids = array_values(array_filter(array_map('intval', (array) ($body['keep_media_ids'] ?? []))));
$add_atts = array_values(array_filter(array_map('intval', (array) ($body['add_upload_ids'] ?? []))));

// Build the bbp_media object list BuddyBoss's saver expects (matched by
// attachment_id+media_id → kept; no media_id → newly created). Only KEEP existing
// media that genuinely belongs to this topic (sanitize against the stored set).
$existing = tm_media_list($topic_id);
$by_mid   = [];
foreach ($existing as $e) $by_mid[(int) $e['media_id']] = $e;

$media_objects = [];
$order = 0;
foreach ($keep_ids as $mid) {
    if (empty($by_mid[$mid])) continue;                 // not ours / already gone — skip
    $media_objects[] = [
        'id'         => (int) $by_mid[$mid]['attachment_id'],   // attachment id
        'media_id'   => $mid,                                   // existing bp_media id → KEEP
        'name'       => (string) $by_mid[$mid]['name'],
        'menu_order' => $order++,
    ];
}
foreach ($add_atts as $att) {
    if (!wp_get_attachment_url($att)) continue;          // must be a real attachment
    $media_objects[] = ['id' => $att, 'menu_order' => $order++];   // no media_id → ADD
}

// Drive BuddyBoss's own forum media saver. It reads $_POST['bbp_media'] (JSON),
// keeps matches, creates new, and bp_media_delete()s anything dropped — exactly
// the native semantics. Empty list ⇒ remove ALL photos.
$_POST['bbp_media'] = wp_json_encode($media_objects);
bp_media_forums_new_post_media_save($topic_id);
unset($_POST['bbp_media']);

// Re-materialize the topic in the PG mirror so the new photo set renders.
if (function_exists('bb_mirror_sync_dispatch')) bb_mirror_sync_dispatch('topic', $topic_id, 'upsert');

tm_out(200, [
    'ok'        => true,
    'topic_id'  => $topic_id,
    'media_ids' => array_map(static fn($m) => (int) $m['media_id'], tm_media_list($topic_id)),
]);
