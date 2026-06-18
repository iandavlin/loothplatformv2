<?php
/**
 * bb-mirror/api/v0/_sync.php — loopback-only sync receiver.
 *
 * Called by mu-plugin bb-mirror-sync.php. Runs on the WP FPM pool
 * (looth-dev), so $wpdb is available for materializing the changed row.
 * In pg mode, peer-auths to postgres as role `looth-dev` (granted RWD on
 * schema `forums`).
 *
 * Materializers (bb_mirror_upsert_*, refresh helpers) live in
 * lib/materializers.php — shared with bin/reconcile.php.
 *
 * Contract from the mu-plugin:
 *   POST /bb-mirror-api/v0/_sync
 *   Headers: X-BB-Mirror-Sync: 1, Host: dev.loothgroup.com
 *   Body: { "kind": "forum|topic|reply|subscription|bp_group", "id": int,
 *           "action": "upsert|delete|spam|trash|restore|subscribe|unsubscribe",
 *           "user_id"?: int }
 *
 * nginx pins this location to allow 127.0.0.1 only.
 */

// Loopback double-check.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit('not loopback');
}
if (($_SERVER['HTTP_X_BB_MIRROR_SYNC'] ?? '') !== '1') {
    http_response_code(400); exit('missing sync header');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); exit('POST only');
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || empty($body['kind']) || empty($body['id'])) {
    http_response_code(400); exit('bad payload');
}

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;
require_once __DIR__ . '/../../lib/materializers.php';

$kind   = (string)$body['kind'];
$id     = (int)$body['id'];
$action = (string)($body['action'] ?? 'upsert');

$db = bb_mirror_db(readonly: false);

try {
    switch ([$kind, $action]) {
        case ['forum', 'upsert']:        bb_mirror_upsert_forum($id, $db); break;
        case ['topic', 'upsert']:        bb_mirror_upsert_topic($id, $db); break;
        case ['reply', 'upsert']:        bb_mirror_upsert_reply($id, $db); break;
        case ['bp_group', 'upsert']:     bb_mirror_upsert_bp_group($id, $db); break;
        case ['person', 'upsert']:       bb_mirror_person_for($id, $db); break;

        case ['bp_group', 'delete']:
            $db->prepare("DELETE FROM bp_group WHERE id = ?")->execute([$id]);
            break;

        case ['forum', 'delete']:
        case ['topic', 'delete']:
        case ['reply', 'delete']:
            $db->prepare("DELETE FROM $kind WHERE id = ?")->execute([$id]);
            break;

        case ['topic', 'trash']:
        case ['reply', 'trash']:
        case ['topic', 'spam']:
        case ['reply', 'spam']:
            $db->prepare("UPDATE $kind SET status = ?, sync_at = ? WHERE id = ?")
               ->execute([$action, bb_mirror_ts(time()), $id]);
            break;

        case ['topic', 'restore']:
        case ['reply', 'restore']:
            $kind === 'topic' ? bb_mirror_upsert_topic($id, $db) : bb_mirror_upsert_reply($id, $db);
            break;

        case ['subscription', 'subscribe']:
        case ['subscription', 'unsubscribe']:
            $user_id = (int)($body['user_id'] ?? 0);
            if (!$user_id) { http_response_code(400); exit('subscription needs user_id'); }
            $target_kind = get_post_type($id);
            if (!in_array($target_kind, ['forum', 'topic'], true)) {
                http_response_code(400); exit('subscription target not forum/topic');
            }
            if ($action === 'subscribe') {
                $sql = bb_mirror_upsert_sql(
                    'forum_subscription',
                    ['user_id','target_kind','target_id','subscribed_at','sync_at'],
                    'user_id, target_kind, target_id'
                );
                $db->prepare($sql)->execute([
                    $user_id, $target_kind, $id, bb_mirror_ts(time()), bb_mirror_ts(time())
                ]);
            } else {
                $db->prepare("DELETE FROM forum_subscription
                              WHERE user_id = ? AND target_kind = ? AND target_id = ?")
                   ->execute([$user_id, $target_kind, $id]);
            }
            break;

        default:
            http_response_code(400); exit("unknown kind/action: $kind/$action");
    }
} catch (Throwable $e) {
    error_log("[bb-mirror _sync] $kind#$id $action: " . $e->getMessage());
    http_response_code(500); exit('sync error');
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'kind' => $kind, 'id' => $id, 'action' => $action]);
