<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Messaging.php';

/**
 * Messages endpoint (thread list + send). Plan: social-layer §4.
 * Backend: src/Messaging.php (Connections-only gate on new DMs).
 *
 *   GET  /profile-api/v0/me/messages        → [ { id, uuid, peers[], last_snippet, unread_count, last_message_at } ]
 *   POST /profile-api/v0/me/messages        → send  body { to_uuid?, thread_id?, body }
 *                                              (to_uuid starts/finds the 1:1 thread; thread_id replies)
 *
 * NOTE TO COORDINATOR — nginx routes:
 *   rewrite ^/profile-api/v0/me/messages/?$ /profile-api/v0/me-messages.php last;
 *   …and add `me-messages` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Messaging;

$user   = Auth::requireUser();
$uuid   = $user['uuid'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    profile_app_json(200, ['threads' => Messaging::threadsFor($uuid)]);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    $in = is_array($in) ? $in : [];
    $body = (string)($in['body'] ?? '');
    $threadId = isset($in['thread_id']) ? (int)$in['thread_id'] : null;
    $toUuid   = isset($in['to_uuid']) ? trim((string)$in['to_uuid']) : null;
    if ($body === '' || ($threadId === null && !$toUuid)) {
        profile_app_json(400, ['error' => 'body_and_target_required']);
    }
    $res = Messaging::send($uuid, $threadId, $toUuid, $body);
    $code = $res['ok'] ? 200 : ($res['error'] === 'not_connected' ? 403 : 400);
    profile_app_json($code, $res);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
