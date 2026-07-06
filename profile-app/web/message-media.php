<?php
declare(strict_types=1);

/**
 * /message-media/<thread-uuid>/<file> — ACCESS-CONTROLLED DM image proxy.
 *
 * DMs are PRIVATE. Unlike profile avatars/banners (world-public identity chrome), a
 * message image is served ONLY to a participant of the thread it belongs to. The
 * thread uuid in the path is the access anchor — opaque (gen_random_uuid), not
 * enumerable. Every request:
 *   1. viewer = Auth::currentUser()  (looth_id JWT cookie). Anonymous → 404.
 *   2. resolve the thread by uuid. Unknown → 404.
 *   3. Messaging::isParticipant(viewer, thread) — false → 404 (NOT 403: a non-
 *      participant must not even learn the image exists).
 *
 * On allow: Cache-Control: private, no-store (never a shared / CDN cache; viewer
 * participation can change), then the local original via X-Accel-Redirect, else the
 * bytes streamed from the dedicated message R2 bucket. PHP never serves a file it
 * has not authorized. Mirrors web/media.php's X-Accel model; the decision here is
 * thread participation (Messaging) instead of profile Visibility.
 *
 * NOTE TO COORDINATOR — nginx (mirror the /profile-media/ block):
 *   location ^~ /message-media/ {
 *       if ($loothdev_is_authorized != 1) { return 403; }      # dev cookie gate only; live var is 1
 *       rewrite ^/message-media/(.*)$ /message-media-auth?path=$1 last;
 *   }
 *   location = /message-media-auth {
 *       internal; include fastcgi.conf;
 *       fastcgi_pass unix:/run/php/php8.3-fpm-profile-app.sock;
 *       fastcgi_param SCRIPT_FILENAME /srv/profile-app/web/message-media.php;
 *   }
 *   location ^~ /message-media-internal/ { internal; alias /srv/profile-app-message-media/; }
 */

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Messaging;
use Looth\ProfileApp\MessageR2;

const LG_MSG_MEDIA_ROOT = '/srv/profile-app-message-media';

$path = (string)($_GET['path'] ?? '');
if (!preg_match('#^([0-9a-fA-F-]{36})/([A-Za-z0-9][A-Za-z0-9._-]*)$#', $path, $m) || str_contains($m[2], '..')) {
    http_response_code(404); exit;
}
$threadUuid = strtolower($m[1]);
$file       = $m[2];

// (1) viewer must be authenticated — anonymous can never see a private DM image.
$user = Auth::currentUser();
if (!$user || empty($user['uuid'])) { http_response_code(404); exit; }
$viewerUuid = strtolower((string) $user['uuid']);

// (2) resolve the thread; (3) assert participation. ANY miss → 404 (never reveal
// that the file / thread exists to a non-participant).
$st = Db::pg()->prepare('SELECT id FROM message_threads WHERE uuid = :u');
$st->execute([':u' => $threadUuid]);
$tid = $st->fetchColumn();
if ($tid === false) { http_response_code(404); exit; }
if (!Messaging::isParticipant($viewerUuid, (int) $tid)) { http_response_code(404); exit; }

$ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
// PRIVATE — never a shared / CDN cache; per-viewer revalidation (participation changes).
header('Cache-Control: private, no-store');

// Local original (R2-disabled fallback) via X-Accel; else stream from the message bucket.
$localOrig = LG_MSG_MEDIA_ROOT . '/' . $threadUuid . '/' . $file;
if (is_file($localOrig)) {
    header('X-Accel-Redirect: /message-media-internal/' . rawurlencode($threadUuid) . '/' . rawurlencode($file));
    exit;
}
if (MessageR2::enabled()) {
    $bytes = MessageR2::get($threadUuid . '/' . $file);
    if (is_string($bytes) && $bytes !== '') {
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
}
http_response_code(404);
