<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/ImageOptimize.php';

/**
 * Avatar single-source upload. POST /profile-api/v0/me/avatar (multipart;
 * field name "avatar"). Validates a real jpeg/png/webp ≤ 5 MB, COMPRESSES it
 * (cap 512px, WebP q82 via ImageOptimize), writes the bytes to the app-owned
 * store, bumps users.avatar_version, sets users.avatar_url to the versioned
 * served URL, and purges /whoami so every mirror re-pulls.
 *
 *   store:  R2 avatars/<uuid>/<version>.webp   (local fallback if R2 disabled)
 *   serve:  /profile-media/avatars/<uuid>/<version>.webp?v=<version>
 *
 * The R2 write is VERIFIED (put + exists) before avatar_url advances — a put
 * that 2xx-but-didn't-land, or a mis-wired bucket, must never leave the row
 * pointing at a missing object (the failure that orphaned avatars at a repoint).
 *
 * NOTE TO COORDINATOR (provision before this works):
 *   1. mkdir -p /srv/profile-app-media/avatars && chown to the profile-app FPM
 *      pool user (the one running php8.3-fpm-profile-app.sock); mode 0775.
 *   2. nginx: serve /profile-media/avatars/ from that dir (cookie-gated like the
 *      other static assets).
 *   3. nginx route for the endpoint (mirror me-craft): rewrite
 *      "^/profile-api/v0/me/avatar/?$" /profile-api/v0/me-avatar.php last; + allowlist.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Cache;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\ImageOptimize;
use Looth\ProfileApp\Media;
use Looth\ProfileApp\Provision;
use Looth\ProfileApp\R2;

const LG_AVATAR_STORE    = '/srv/profile-app-media/avatars';
const LG_AVATAR_URL_BASE = '/profile-media/avatars';
const LG_AVATAR_MAX      = 5 * 1024 * 1024;   // 5 MB

$user = Auth::requireUser();

// DELETE → drop the custom photo and revert to the branded fallback (the same
// Optimum default a photo-less member has). GCs the uploaded file from R2 + local
// + cache twins; Media::unlinkUrl is a no-op if the stored url is already the
// (non-/profile-media) default, so a double-delete is safe.
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $old = $user['avatar_url'] ?? null;
    $pg  = Db::pg();
    $pg->prepare('UPDATE users SET avatar_url = :u WHERE id = :i')
       ->execute([':u' => Provision::DEFAULT_AVATAR_URL, ':i' => (int)$user['id']]);
    Media::unlinkUrl($old);
    // avatar_url is in the /whoami contract — purge so the shared header + mirrors
    // re-pull the fallback (best-effort; never blocks the API).
    try {
        $b = $pg->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
        $b->execute([':u' => (int)$user['id']]);
        $wpId = (int) $b->fetchColumn();
        if ($wpId > 0) Cache::purgeWhoami($wpId);
    } catch (Throwable $e) {
        error_log('[me-avatar] whoami purge failed: ' . $e->getMessage());
    }
    profile_app_json(200, ['ok' => true, 'avatar_url' => Provision::DEFAULT_AVATAR_URL]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') profile_app_json(405, ['error' => 'method_not_allowed']);

profile_app_rate_gate('upload:' . strtolower((string)$user['uuid']), 30, 300);

$file = $_FILES['avatar'] ?? null;
if (!is_array($file)) profile_app_json(400, ['error' => 'avatar_file_required']);
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    profile_app_json(400, ['error' => 'upload_error', 'code' => $file['error'] ?? null]);
}
if ((int)($file['size'] ?? 0) > LG_AVATAR_MAX) profile_app_json(400, ['error' => 'too_large', 'max' => '5MB']);

$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) profile_app_json(400, ['error' => 'bad_upload']);

// Real image + allowed type (don't trust the client mime).
$info = @getimagesize($tmp);
if ($info === false) profile_app_json(400, ['error' => 'not_an_image']);
$ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
if ($ext === null) profile_app_json(400, ['error' => 'unsupported_type', 'allowed' => ['jpeg', 'png', 'webp']]);

$uuid = strtolower((string)$user['uuid']);
// Atomic version bump — race-safe vs concurrent uploads (two near-simultaneous
// uploads otherwise compute the same version → same filename → one overwrites
// the other). RETURNING gives each request a unique version.
$pg    = Db::pg();
$vstmt = $pg->prepare('UPDATE users SET avatar_version = COALESCE(avatar_version,0) + 1 WHERE id = :i RETURNING avatar_version');
$vstmt->execute([':i' => (int)$user['id']]);
$ver = (int) $vstmt->fetchColumn();

// Compress / cap at write time (cap 512px, WebP q82). Fall back to the raw
// upload only if the bytes are un-decodable, so a valid avatar is never dropped.
$raw = @file_get_contents($tmp);
if ($raw === false) profile_app_json(500, ['error' => 'read_failed']);
$mime = (string)($info['mime'] ?? 'application/octet-stream');
try {
    [$bytes, $ext] = ImageOptimize::avatar($raw);
    $mime = 'image/webp';
} catch (\Throwable $e) {
    $bytes = $raw;                          // keep $ext from the validated mime
    error_log('[me-avatar] optimize fallback (raw .' . $ext . '): ' . $e->getMessage());
}

$fn  = $ver . '.' . $ext;
$key = 'avatars/' . $uuid . '/' . $fn;
if (R2::enabled()) {
    // Verify the object is really there before advancing avatar_url.
    if (!R2::put($key, $bytes, $mime) || !R2::exists($key)) {
        profile_app_json(500, ['error' => 'write_failed']);
    }
} else {
    $dir = LG_AVATAR_STORE . '/' . $uuid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        profile_app_json(500, ['error' => 'store_unwritable', 'hint' => 'provision ' . LG_AVATAR_STORE . ' (chown to the FPM user)']);
    }
    $dest = $dir . '/' . $fn;
    if (@file_put_contents($dest, $bytes) === false) profile_app_json(500, ['error' => 'write_failed']);
    @chmod($dest, 0644);
}

$url = LG_AVATAR_URL_BASE . '/' . $uuid . '/' . $ver . '.' . $ext . '?v=' . $ver;

$pg->prepare('UPDATE users SET avatar_url = :u WHERE id = :i')
   ->execute([':u' => $url, ':i' => (int)$user['id']]);

// GC the previous avatar file + its resizer cache twins (replace would orphan it).
Media::unlinkUrl($user['avatar_url'] ?? null);

// Identity purge — mirrors (shared header, forum threads, archive bylines) re-pull
// on their next /whoami / batch-users read. Best-effort; never blocks the API.
try {
    $b = $pg->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
    $b->execute([':u' => (int)$user['id']]);
    $wpId = (int) $b->fetchColumn();
    if ($wpId > 0) Cache::purgeWhoami($wpId);
} catch (Throwable $e) {
    error_log('[me-avatar] whoami purge failed: ' . $e->getMessage());
}

profile_app_json(200, ['ok' => true, 'avatar_url' => $url, 'avatar_version' => $ver]);
