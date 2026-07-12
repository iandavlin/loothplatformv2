<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Messaging.php';

/**
 * Thread MEMBERSHIP management (group messaging, lane: messages-manage). Backend:
 * src/Messaging.php. Identified by thread UUID (opaque, shareable), same as me-thread.php.
 *
 *   POST /profile-api/v0/me/messages/<uuid>/members  body:
 *     { add: [user-uuid, …] }   add members (any participant; connection-gated; adding to a
 *                               1:1 FORKS a new group and returns {forked:true, thread_uuid})
 *     { remove: user-uuid }     remove another member (CREATOR or site admin only → 403 else)
 *     { leave: true }           remove yourself (always allowed for a participant)
 *
 * Every rule is enforced server-side (client gating is how the last privacy bug shipped).
 * Deny reads as 404 for a non-participant (existing model); a forbidden remove is 403.
 *
 * NOTE TO COORDINATOR — nginx route (mirror me-thread; place ABOVE the bare
 *   /me/messages/<uuid> rewrite so the /members suffix wins):
 *   rewrite "^/profile-api/v0/me/messages/([0-9a-f-]{36})/members/?$" /profile-api/v0/me-thread-members.php?uuid=$1 last;
 *   …and add `me-thread-members` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Messaging;

$user = Auth::requireUser();
$uuid = $user['uuid'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') profile_app_json(405, ['error' => 'method_not_allowed']);

$threadUuid = (string)($_GET['uuid'] ?? '');
if ($threadUuid === '' && preg_match('~/messages/([0-9a-f-]{36})/members~', (string)($_SERVER['REQUEST_URI'] ?? ''), $m)) {
    $threadUuid = $m[1];
}
if ($threadUuid === '') profile_app_json(400, ['error' => 'thread_uuid_required']);

$st = Db::pg()->prepare('SELECT id FROM message_threads WHERE uuid = :u');
$st->execute([':u' => strtolower($threadUuid)]);
$threadId = $st->fetchColumn();
if ($threadId === false) profile_app_json(404, ['error' => 'thread_not_found']);
$threadId = (int)$threadId;

$in = json_decode(file_get_contents('php://input') ?: '', true);
$in = is_array($in) ? $in : [];

// deny for a non-participant reads as 404 (existing model): never confirm a thread's members
// to an outsider. Messaging asserts the same, but gate the shape here before dispatch.
if (!Messaging::isParticipant($uuid, $threadId)) profile_app_json(404, ['error' => 'thread_not_found']);

if (!empty($in['leave'])) {
    $res = Messaging::leave($uuid, $threadId);
    profile_app_json($res['ok'] ? 200 : 400, $res);
}

if (isset($in['remove'])) {
    $target = trim((string)$in['remove']);
    if ($target === '') profile_app_json(400, ['error' => 'target_required']);
    $res  = Messaging::removeMember($uuid, $threadId, $target, Auth::isAdmin());
    $code = $res['ok'] ? 200 : ($res['error'] === 'forbidden' ? 403 : 400);
    profile_app_json($code, $res);
}

if (isset($in['add'])) {
    $add = is_array($in['add']) ? $in['add'] : [$in['add']];
    $res  = Messaging::addMembers($uuid, $threadId, $add);
    $code = $res['ok'] ? 200 : ($res['error'] === 'not_connected' ? 403 : 400);
    profile_app_json($code, $res);
}

profile_app_json(400, ['error' => 'no_action']);
