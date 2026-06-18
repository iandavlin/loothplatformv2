<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Gallery block — image grid in the app-owned media store. Owner-only writes.
 *   GET  → the assembled gallery block (Block::loadGallery).
 *   POST → multipart "image" (jpeg/png/webp ≤5MB): store bytes, append to the list.
 *   PUT  → { images: [{url,caption}], visibility? } — replace the list (remove/reorder/caption/vis).
 *
 * Store:  <LG_GALLERY_STORE>/<uuid>/<rand>.<ext>   served at /profile-media/gallery/<uuid>/<rand>.<ext>
 * (nginx serves /profile-media/ from /srv/profile-app-media/, cookie-gated.)
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\R2;

const LG_GALLERY_STORE = '/srv/profile-app-media/gallery';
const LG_GALLERY_MAX   = 5 * 1024 * 1024;

$user   = Auth::requireUser();
$uid    = (int) $user['id'];
$uuid   = strtolower((string) $user['uuid']);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    profile_app_json(200, Block::loadGallery($uid));
}

if ($method === 'POST') {
    profile_app_rate_gate('upload:' . $uuid, 30, 300);
    $file = $_FILES['image'] ?? null;
    if (!is_array($file)) profile_app_json(400, ['error' => 'image_required']);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) profile_app_json(400, ['error' => 'upload_error', 'code' => $file['error'] ?? null]);
    if ((int)($file['size'] ?? 0) > LG_GALLERY_MAX) profile_app_json(400, ['error' => 'too_large', 'max' => '5MB']);
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) profile_app_json(400, ['error' => 'bad_upload']);

    $info = @getimagesize($tmp);
    if ($info === false) profile_app_json(400, ['error' => 'not_an_image']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) profile_app_json(400, ['error' => 'unsupported_type', 'allowed' => ['jpeg', 'png', 'webp']]);

    $current = Block::loadGallery($uid)['images'];
    if (count($current) >= Block::GALLERY_MAX) profile_app_json(400, ['error' => 'gallery_full', 'max' => Block::GALLERY_MAX]);

    $fn = bin2hex(random_bytes(8)) . '.' . $ext;
    if (R2::enabled()) {
        $bytes = @file_get_contents($tmp);
        if ($bytes === false || !R2::put('gallery/' . $uuid . '/' . $fn, $bytes, (string)$info['mime'])) {
            profile_app_json(500, ['error' => 'write_failed']);
        }
    } else {
        $dir = LG_GALLERY_STORE . '/' . $uuid;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            profile_app_json(500, ['error' => 'store_unwritable', 'hint' => 'provision ' . LG_GALLERY_STORE]);
        }
        $dest = $dir . '/' . $fn;
        if (!@move_uploaded_file($tmp, $dest)) profile_app_json(500, ['error' => 'write_failed']);
        @chmod($dest, 0644);
    }

    $url = Block::GALLERY_URL_BASE . '/' . $uuid . '/' . $fn;
    $current[] = ['url' => $url, 'caption' => ''];
    $gallery = Block::saveGalleryImages($uid, $current);
    profile_app_json(200, ['ok' => true, 'image' => ['url' => $url], 'gallery' => $gallery]);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);
// images only replaced when the key is present; a vis-only / title-only PUT keeps the photos.
$images = array_key_exists('images', $in) && is_array($in['images'])
    ? $in['images']
    : Block::loadGallery($uid)['images'];
$vis    = array_key_exists('visibility', $in) ? $in['visibility'] : null;
$title  = array_key_exists('title', $in) ? (string) $in['title'] : null;  // null = keep existing
$mode   = array_key_exists('display_mode', $in) ? (string) $in['display_mode'] : null;  // null = keep existing
$gallery = Block::saveGalleryImages($uid, $images, $vis, $title, $mode);
profile_app_json(200, ['ok' => true, 'gallery' => $gallery]);
