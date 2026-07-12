<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Messaging.php';

/**
 * One thread (messages + reply). Plan: social-layer §4. Backend: src/Messaging.php.
 * Asserts the viewer is a recipient. Identified by thread UUID (opaque, shareable).
 *
 *   GET  /profile-api/v0/me/messages/<uuid> → { thread, peers[], messages[] }  (marks read)
 *   POST /profile-api/v0/me/messages/<uuid> → reply  body { body }
 *
 * NOTE TO COORDINATOR — nginx route:
 *   rewrite ^/profile-api/v0/me/messages/([0-9a-f-]{36})/?$ /profile-api/v0/me-thread.php?uuid=$1 last;
 *   …and add `me-thread` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Messaging;

$user = Auth::requireUser();
$uuid = $user['uuid'];

$threadUuid = (string)($_GET['uuid'] ?? '');
if ($threadUuid === '' && preg_match('~/messages/([0-9a-f-]{36})~', (string)($_SERVER['REQUEST_URI'] ?? ''), $m)) {
    $threadUuid = $m[1];
}
if ($threadUuid === '') profile_app_json(400, ['error' => 'thread_uuid_required']);

$st = Db::pg()->prepare('SELECT id FROM message_threads WHERE uuid = :u');
$st->execute([':u' => $threadUuid]);
$threadId = $st->fetchColumn();
if ($threadId === false) profile_app_json(404, ['error' => 'thread_not_found']);
$threadId = (int)$threadId;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Auth::isAdmin() feeds can_manage (a site admin may remove any member, per Ian 7/12).
    $res = Messaging::thread($uuid, $threadId, Auth::isAdmin());
    profile_app_json($res['ok'] ? 200 : 403, $res);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    $body = is_array($in) ? (string)($in['body'] ?? '') : '';
    if ($body === '') profile_app_json(400, ['error' => 'body_required']);
    $res = Messaging::send($uuid, $threadId, null, $body);
    $code = $res['ok'] ? 200 : ($res['error'] === 'not_connected' ? 403 : 400);
    profile_app_json($code, $res);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
