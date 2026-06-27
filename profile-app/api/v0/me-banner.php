<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/ImageOptimize.php';

/**
 * Banner image upload — wide hero strip at the top of the profile header card.
 *
 *   POST /profile-api/v0/me/banner   (multipart; field name "banner")
 *     Validates a real jpeg/png/webp ≤ 8 MB, writes the bytes to the app-owned
 *     store, bumps users.banner_version, sets users.banner_url to the versioned
 *     served URL.
 *
 *   DELETE /profile-api/v0/me/banner
 *     Clears banner_url (keeps banner_version so any cached URL still busts on
 *     the next set). Files on disk are left in place — owner-only churn,
 *     cheap to ignore; future GC sweep could remove them.
 *
 *   store:  <LG_BANNER_STORE>/<uuid>/<version>.<ext>     (NOT wp-content)
 *   serve:  /profile-media/banners/<uuid>/<version>.<ext>?v=<version>
 *
 * NOTE TO COORDINATOR (provision before this works):
 *   1. mkdir -p /srv/profile-app-media/banners && chown profile-app:loothdevs;
 *      mode 2775 (setgid). Queued in /srv/lg-sudo-queue/REQUESTS.md as
 *      buck-2026-06-02-1.
 *   2. nginx route additions in /etc/nginx/snippets/strangler-profile-app.conf:
 *        rewrite "^/profile-api/v0/me/banner/?$" /profile-api/v0/me-banner.php last;
 *        # add `me-banner` to the auth-gated /me/* allowlist regex.
 *      The /profile-media/ static alias already serves new subdirs — no change
 *      needed for the GET-image side.
 *   3. Schema: sql/2026-06-02-banner.sql adds banner_url + banner_version.
 *
 * Unlike me-avatar.php, NO /whoami purge — banner_url is not in the whoami
 * contract (avatar_url + display_name are; banner is a profile-page-only
 * surface).
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\ImageOptimize;
use Looth\ProfileApp\Media;
use Looth\ProfileApp\R2;

const LG_BANNER_STORE    = '/srv/profile-app-media/banners';
const LG_BANNER_URL_BASE = '/profile-media/banners';
const LG_BANNER_MAX      = 8 * 1024 * 1024;   // 8 MB — banners are wider than avatars

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'DELETE') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

$user = Auth::requireUser();
$uid  = (int) $user['id'];
$uuid = strtolower((string) $user['uuid']);

if ($method === 'DELETE') {
    Db::pg()->prepare('UPDATE users SET banner_url = NULL WHERE id = :i')->execute([':i' => $uid]);
    Media::unlinkUrl($user['banner_url'] ?? null);   // remove the bytes, not just the row
    profile_app_json(200, ['ok' => true, 'banner_url' => null]);
}

// POST upload.
profile_app_rate_gate('upload:' . $uuid, 30, 300);
$file = $_FILES['banner'] ?? null;
if (!is_array($file)) profile_app_json(400, ['error' => 'banner_file_required']);
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    profile_app_json(400, ['error' => 'upload_error', 'code' => $file['error'] ?? null]);
}
if ((int)($file['size'] ?? 0) > LG_BANNER_MAX) profile_app_json(400, ['error' => 'too_large', 'max' => '8MB']);

$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) profile_app_json(400, ['error' => 'bad_upload']);

// Real image + allowed type (don't trust the client mime).
$info = @getimagesize($tmp);
if ($info === false) profile_app_json(400, ['error' => 'not_an_image']);
$ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
if ($ext === null) profile_app_json(400, ['error' => 'unsupported_type', 'allowed' => ['jpeg', 'png', 'webp']]);

// Atomic version bump — race-safe vs concurrent uploads (see me-avatar.php).
$bvs = Db::pg()->prepare('UPDATE users SET banner_version = COALESCE(banner_version,0) + 1 WHERE id = :i RETURNING banner_version');
$bvs->execute([':i' => $uid]);
$ver = (int) $bvs->fetchColumn();

// Compress / cap / auto-orient at write time (cap 1600px, WebP q82). Fall back
// to the raw upload only if the bytes are un-decodable, so a valid banner is
// never dropped — same contract as the avatar/gallery paths.
$raw = @file_get_contents($tmp);
if ($raw === false) profile_app_json(500, ['error' => 'read_failed']);
$mime = (string)($info['mime'] ?? 'application/octet-stream');
try {
    [$bytes, $ext] = ImageOptimize::banner($raw);
    $mime = 'image/webp';
} catch (\Throwable $e) {
    $bytes = $raw;                          // keep $ext from the validated mime
    error_log('[me-banner] optimize fallback (raw .' . $ext . '): ' . $e->getMessage());
}

$fn = $ver . '.' . $ext;
if (R2::enabled()) {
    if (!R2::put('banners/' . $uuid . '/' . $fn, $bytes, $mime)) {
        profile_app_json(500, ['error' => 'write_failed']);
    }
} else {
    $dir = LG_BANNER_STORE . '/' . $uuid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        profile_app_json(500, ['error' => 'store_unwritable', 'hint' => 'provision ' . LG_BANNER_STORE . ' (chown to the FPM user)']);
    }
    $dest = $dir . '/' . $fn;
    if (@file_put_contents($dest, $bytes) === false) profile_app_json(500, ['error' => 'write_failed']);
    @chmod($dest, 0644);
}

$url = LG_BANNER_URL_BASE . '/' . $uuid . '/' . $ver . '.' . $ext . '?v=' . $ver;

Db::pg()->prepare('UPDATE users SET banner_url = :u WHERE id = :i')
    ->execute([':u' => $url, ':i' => $uid]);

// GC the previous banner file + cache twins (replace would orphan it).
Media::unlinkUrl($user['banner_url'] ?? null);

profile_app_json(200, ['ok' => true, 'banner_url' => $url, 'banner_version' => $ver]);
