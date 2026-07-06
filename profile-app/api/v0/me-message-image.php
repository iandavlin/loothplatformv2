<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/ImageOptimize.php';

/**
 * DM image attachment upload. POST multipart "image" (+ optional "body" caption),
 * targeting the thread the same way the text send does:
 *   POST /profile-api/v0/me/messages/image                   multipart {image, to_uuid, body?}  (new DM)
 *   POST /profile-api/v0/me/messages/<thread-uuid>/image     multipart {image, body?}            (reply)
 *
 * Validates a real jpeg/png/webp ≤5MB, COMPRESSES it (cap 1600px, WebP q82,
 * auto-oriented via ImageOptimize — same pipeline as the gallery), stores the bytes
 * to the DEDICATED message R2 bucket (MessageR2; local fallback while creds pend),
 * then inserts a message row carrying media_url. The stored URL is the ACCESS-
 * CONTROLLED proxy path /message-media/<thread-uuid>/<file> — NOT a public URL,
 * because DMs are private.
 *
 * Authorization (recipient + connection gate, or new-DM connection gate) runs BEFORE
 * any bytes are stored, so an attachment is never written for a non-participant. A
 * failed insert GCs the just-stored object (no orphans).
 *
 * NOTE TO COORDINATOR — nginx routes (mirror me-thread / me-messages, place ABOVE the
 * bare /me/messages/<uuid> rewrite):
 *   rewrite "^/profile-api/v0/me/messages/image/?$"                  /profile-api/v0/me-message-image.php last;
 *   rewrite "^/profile-api/v0/me/messages/([0-9a-f-]{36})/image/?$"  /profile-api/v0/me-message-image.php?uuid=$1 last;
 *   …plus the /message-media/ auth-proxy serve block (see web/message-media.php).
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\ImageOptimize;
use Looth\ProfileApp\Messaging;
use Looth\ProfileApp\MessageR2;

const LG_MSG_MEDIA_STORE    = '/srv/profile-app-message-media';   // local fallback when R2 disabled
const LG_MSG_MEDIA_URL_BASE = '/message-media';
const LG_MSG_MEDIA_MAX      = 5 * 1024 * 1024;                    // 5 MB

$user       = Auth::requireUser();
$senderUuid = strtolower((string) $user['uuid']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') profile_app_json(405, ['error' => 'method_not_allowed']);
profile_app_rate_gate('msg-upload:' . $senderUuid, 30, 300);

// ── resolve target thread + AUTHORIZE before storing any bytes ──────────────────
$threadUuid = (string)($_GET['uuid'] ?? '');
if ($threadUuid === '' && preg_match('~/messages/([0-9a-f-]{36})/image~', (string)($_SERVER['REQUEST_URI'] ?? ''), $mm)) {
    $threadUuid = $mm[1];
}
$toUuid = isset($_POST['to_uuid']) ? trim((string) $_POST['to_uuid']) : '';
$body   = isset($_POST['body'])    ? (string) $_POST['body']         : '';

$threadId = null;
if ($threadUuid !== '') {
    $st = Db::pg()->prepare('SELECT id FROM message_threads WHERE uuid = :u');
    $st->execute([':u' => strtolower($threadUuid)]);
    $tid = $st->fetchColumn();
    if ($tid === false) profile_app_json(404, ['error' => 'thread_not_found']);
    $threadId   = (int) $tid;
    $threadUuid = strtolower($threadUuid);
    $gate = Messaging::canSendTo($senderUuid, $threadId);
    if (!$gate['ok']) profile_app_json(403, $gate);
} elseif ($toUuid !== '') {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $toUuid)) {
        profile_app_json(400, ['error' => 'invalid_to_uuid']);
    }
    $res = Messaging::ensurePairThread($senderUuid, strtolower($toUuid));
    if (!$res['ok']) profile_app_json($res['error'] === 'not_connected' ? 403 : 400, $res);
    $threadId   = (int) $res['thread_id'];
    $threadUuid = (string) $res['thread_uuid'];
} else {
    profile_app_json(400, ['error' => 'target_required']);
}

// ── validate + optimize the image ───────────────────────────────────────────────
$file = $_FILES['image'] ?? null;
if (!is_array($file)) profile_app_json(400, ['error' => 'image_required']);
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    profile_app_json(400, ['error' => 'upload_error', 'code' => $file['error'] ?? null]);
}
if ((int)($file['size'] ?? 0) > LG_MSG_MEDIA_MAX) profile_app_json(400, ['error' => 'too_large', 'max' => '5MB']);
$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) profile_app_json(400, ['error' => 'bad_upload']);

$info = @getimagesize($tmp);
if ($info === false) profile_app_json(400, ['error' => 'not_an_image']);
$ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
if ($ext === null) profile_app_json(400, ['error' => 'unsupported_type', 'allowed' => ['jpeg', 'png', 'webp']]);

$raw = @file_get_contents($tmp);
if ($raw === false) profile_app_json(500, ['error' => 'read_failed']);
$mime = (string)($info['mime'] ?? 'application/octet-stream');
try {
    [$bytes, $ext] = ImageOptimize::gallery($raw);   // cap 1600px, WebP q82, auto-orient
    $mime = 'image/webp';
} catch (\Throwable $e) {
    $bytes = $raw;                                    // keep $ext from the validated mime
    error_log('[me-message-image] optimize fallback (raw .' . $ext . '): ' . $e->getMessage());
}
$dim = @getimagesizefromstring($bytes);
$mw  = is_array($dim) ? (int) $dim[0] : null;
$mh  = is_array($dim) ? (int) $dim[1] : null;

// ── store under the thread-uuid key (the access anchor) ─────────────────────────
$fn  = bin2hex(random_bytes(8)) . '.' . $ext;
$rel = $threadUuid . '/' . $fn;
if (MessageR2::enabled()) {
    // Verify the object really landed before we reference it from a message row.
    if (!MessageR2::put($rel, $bytes, $mime) || !MessageR2::exists($rel)) {
        profile_app_json(500, ['error' => 'write_failed']);
    }
} else {
    $dir = LG_MSG_MEDIA_STORE . '/' . $threadUuid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        profile_app_json(500, ['error' => 'store_unwritable', 'hint' => 'provision ' . LG_MSG_MEDIA_STORE . ' OR wire /etc/looth/messages-r2']);
    }
    $dest = $dir . '/' . $fn;
    if (@file_put_contents($dest, $bytes) === false) profile_app_json(500, ['error' => 'write_failed']);
    @chmod($dest, 0644);
}

$url   = LG_MSG_MEDIA_URL_BASE . '/' . $threadUuid . '/' . $fn;
$media = ['url' => $url, 'mime' => $mime, 'w' => $mw, 'h' => $mh];

$send = Messaging::send($senderUuid, $threadId, null, $body, $media);
if (empty($send['ok'])) {
    // Insert failed (rare: peer blocked mid-session) — GC the bytes so nothing is orphaned.
    if (MessageR2::enabled()) MessageR2::delete($rel);
    else @unlink(LG_MSG_MEDIA_STORE . '/' . $threadUuid . '/' . $fn);
    profile_app_json($send['error'] === 'not_connected' ? 403 : 400, $send);
}

profile_app_json(200, [
    'ok'          => true,
    'thread_id'   => $threadId,
    'thread_uuid' => $threadUuid,
    'message_id'  => $send['message_id'] ?? null,
    'created_at'  => $send['created_at'] ?? null,
    'media_url'   => $url,
    'media_w'     => $mw,
    'media_h'     => $mh,
]);
