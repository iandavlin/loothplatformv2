<?php
/**
 * bb-mirror/api/v0/mark-seen.php — record viewer's last-read timestamp for a topic.
 *
 * POST /bb-mirror-api/v0/mark-seen
 *   body: { topic_id: <int> }
 *   headers: WP login cookie (browser sends automatically same-origin)
 *
 * Anonymous viewers (no WP login) → 200 no-op (don't error; client fires
 * blindly on every single-topic render).
 *
 * Runs on the WP FPM pool because we need the cookie-resolved user via WP
 * context, plus $wpdb to write to forums.forum_read_state.
 */

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo '{"error":"POST only"}'; exit;
}

$uid = get_current_user_id();
if (!$uid) {
    // Anonymous — silently no-op so JS can fire without checking auth.
    echo '{"ok":true,"authenticated":false}';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$topic_id = (int)($body['topic_id'] ?? 0);
if ($topic_id <= 0) {
    http_response_code(400); echo '{"error":"topic_id required"}'; exit;
}

$db = bb_mirror_db(readonly: false);
$sql = bb_mirror_upsert_sql(
    'forum_read_state',
    ['user_id', 'topic_id', 'last_read_at'],
    'user_id, topic_id'
);
$db->prepare($sql)->execute([
    (int)$uid, $topic_id, bb_mirror_ts(time())
]);

echo json_encode(['ok' => true, 'authenticated' => true, 'topic_id' => $topic_id]);
