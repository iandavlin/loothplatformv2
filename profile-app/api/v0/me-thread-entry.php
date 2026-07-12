<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Messaging.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/MessageR2.php';

/**
 * Edit / delete an OWN message entry (group messaging, lane: messages-manage). Backend:
 * src/Messaging.php. Owner-only, enforced server-side (a hidden button is not a gate).
 *
 *   PATCH  /profile-api/v0/me/messages/<uuid>/entries/<id>  body { body }  → edit text ("(edited)")
 *   DELETE /profile-api/v0/me/messages/<uuid>/entries/<id>                 → soft tombstone + media GC
 *
 * Non-owner → 403. A delete blanks the body + strips the media reference in the DB and then
 * GCs the stored object through the SAME message store as the upload path (MessageR2, or the
 * local fallback while creds pend) — the bytes are gone, not merely hidden.
 *
 * NOTE TO COORDINATOR — nginx route (place ABOVE the bare /me/messages/<uuid> rewrite):
 *   rewrite "^/profile-api/v0/me/messages/([0-9a-f-]{36})/entries/([0-9]+)/?$" /profile-api/v0/me-thread-entry.php?uuid=$1&mid=$2 last;
 *   …and add `me-thread-entry` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Messaging;
use Looth\ProfileApp\MessageR2;

const LG_MSG_MEDIA_STORE    = '/srv/profile-app-message-media';   // local fallback when R2 disabled
const LG_MSG_MEDIA_URL_BASE = '/message-media';

$user = Auth::requireUser();
$uuid = strtolower((string)$user['uuid']);

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'PATCH' && $method !== 'DELETE') profile_app_json(405, ['error' => 'method_not_allowed']);

$threadUuid = (string)($_GET['uuid'] ?? '');
$mid        = isset($_GET['mid']) ? (int)$_GET['mid'] : 0;
if (($threadUuid === '' || $mid === 0)
    && preg_match('~/messages/([0-9a-f-]{36})/entries/([0-9]+)~', (string)($_SERVER['REQUEST_URI'] ?? ''), $m)) {
    $threadUuid = $threadUuid !== '' ? $threadUuid : $m[1];
    $mid        = $mid !== 0 ? $mid : (int)$m[2];
}
if ($threadUuid === '' || $mid === 0) profile_app_json(400, ['error' => 'thread_and_message_required']);

$st = Db::pg()->prepare('SELECT id FROM message_threads WHERE uuid = :u');
$st->execute([':u' => strtolower($threadUuid)]);
$threadId = $st->fetchColumn();
if ($threadId === false) profile_app_json(404, ['error' => 'thread_not_found']);
$threadId   = (int)$threadId;
$threadUuid = strtolower($threadUuid);

if ($method === 'PATCH') {
    $in   = json_decode(file_get_contents('php://input') ?: '', true);
    $body = is_array($in) ? (string)($in['body'] ?? '') : '';
    if (trim($body) === '') profile_app_json(400, ['error' => 'body_required']);
    $res  = Messaging::editMessage($uuid, $threadId, $mid, $body);
    $code = $res['ok'] ? 200 : ($res['error'] === 'forbidden' ? 403 : ($res['error'] === 'not_found' ? 404 : 400));
    profile_app_json($code, $res);
}

// DELETE
$res = Messaging::deleteMessage($uuid, $threadId, $mid);
if (empty($res['ok'])) {
    $code = $res['error'] === 'forbidden' ? 403 : ($res['error'] === 'not_found' ? 404 : 400);
    profile_app_json($code, $res);
}

// GC the stored object (if the deleted message carried one). media_url is the proxy path
// /message-media/<thread-uuid>/<file>; the store key is the <thread-uuid>/<file> tail.
$mediaUrl = (string)($res['media_url'] ?? '');
if ($mediaUrl !== '' && str_starts_with($mediaUrl, LG_MSG_MEDIA_URL_BASE . '/')) {
    $rel = ltrim(substr($mediaUrl, strlen(LG_MSG_MEDIA_URL_BASE)), '/');   // "<thread-uuid>/<file>"
    // Only touch objects under this thread's own prefix — never traverse out of it.
    if ($rel !== '' && strpos($rel, '..') === false && str_starts_with($rel, $threadUuid . '/')) {
        if (MessageR2::enabled()) {
            MessageR2::delete($rel);
        } else {
            @unlink(LG_MSG_MEDIA_STORE . '/' . $rel);
        }
    }
}

profile_app_json(200, ['ok' => true, 'message_id' => $mid, 'deleted' => true]);
