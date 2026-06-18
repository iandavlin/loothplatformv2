<?php
/**
 * bb-mirror/api/v0/unread.php — which topic_ids are unread for the viewer.
 *
 * POST /bb-mirror-api/v0/unread
 *   body: { topic_ids: [int, ...] }
 *
 * Returns: { authenticated: bool, unread: [int, ...] }
 *
 * "Unread" = (no forum_read_state row for this user+topic)
 *         OR (topic.last_active_at > forum_read_state.last_read_at)
 *
 * Cookie-authed; anonymous → empty unread (the chrome doesn't show NEW
 * markers to non-members anyway).
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
    echo json_encode(['authenticated' => false, 'unread' => []]);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$ids = $body['topic_ids'] ?? [];
if (!is_array($ids) || !$ids) {
    echo json_encode(['authenticated' => true, 'unread' => []]);
    exit;
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
if (!$ids) {
    echo json_encode(['authenticated' => true, 'unread' => []]);
    exit;
}

$db = bb_mirror_db(readonly: false);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("
    SELECT t.id
      FROM topic t
      LEFT JOIN forum_read_state s
             ON s.topic_id = t.id AND s.user_id = ?
     WHERE t.id IN ($placeholders)
       AND (s.last_read_at IS NULL OR
            (t.last_active_at IS NOT NULL AND t.last_active_at > s.last_read_at))
");
$stmt->execute([(int)$uid, ...$ids]);
$unread = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

echo json_encode(['authenticated' => true, 'unread' => $unread]);
