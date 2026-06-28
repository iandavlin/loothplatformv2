<?php
declare(strict_types=1);

/**
 * /profile-media/<class>/<uuid>/<file> — auth front controller (Ian 6/12).
 *
 * Closes THE standing hole: the media store used to be a bare nginx alias that
 * served everything (gallery photos + resumes included) to anyone past the dev
 * cookie. Now every request is decided by Visibility::fileVisible:
 *
 *   avatars/banners → public (identity chrome — bylines, comments, messages)
 *   gallery         → the owner's gallery-section visibility (+ master switch)
 *   resumes         → users.resume_visibility (+ master switch)
 *   anything else   → fails closed
 *
 * PHP never streams bytes: on allow we hand nginx an X-Accel-Redirect to the
 * internal alias and it serves the file itself. Denials answer 404 (not 403)
 * so a gated file's existence can't be probed.
 */

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Visibility;
use Looth\ProfileApp\R2;

/**
 * EXIF orientation (1..8) of JPEG $bytes — or 1 when none / not JPEG / no exif ext.
 * GD strips and IGNORES EXIF orientation, so the ?w= resizer must bake it in or a
 * phone photo (orientation 6/8) serves sideways. webp/png originals carry no EXIF
 * orientation here, so they return 1 and are left untouched (the avatar/gallery
 * upload path already stores upright webp via ImageOptimize).
 */
function lg_media_exif_orientation(string $bytes): int
{
    if (!function_exists('exif_read_data')) return 1;
    if (strncmp($bytes, "\xFF\xD8", 2) !== 0) return 1;                   // JPEG SOI only
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
    $o = (is_array($exif) && isset($exif['Orientation'])) ? (int)$exif['Orientation'] : 1;
    return ($o >= 1 && $o <= 8) ? $o : 1;
}

/**
 * Bake an EXIF orientation into GD pixels (mirrors ImageOptimize::applyOrientation
 * on the Imagick side). GD imagerotate is COUNTER-clockwise, so EXIF 6 (display
 * needs +90 CW) = imagerotate(-90). Flip cases mutate in place and return the same
 * resource; rotate cases return a NEW resource — the caller frees the original then.
 */
function lg_media_gd_orient($im, int $o)
{
    switch ($o) {
        case 2: imageflip($im, IMG_FLIP_HORIZONTAL); return $im;
        case 3: return imagerotate($im, 180, 0);
        case 4: imageflip($im, IMG_FLIP_VERTICAL); return $im;
        case 5: imageflip($im, IMG_FLIP_HORIZONTAL); return imagerotate($im, -90, 0);
        case 6: return imagerotate($im, -90, 0);
        case 7: imageflip($im, IMG_FLIP_HORIZONTAL); return imagerotate($im, 90, 0);
        case 8: return imagerotate($im, 90, 0);
        default: return $im;
    }
}

$path = (string)($_GET['path'] ?? '');
if (!preg_match('#^(avatars|banners|gallery|resumes)/([0-9a-fA-F-]{36})/([A-Za-z0-9][A-Za-z0-9._ -]*)$#', $path, $m)
    || str_contains($m[3], '..')) {
    http_response_code(404);
    exit;
}
[, $class, $uuid, $file] = $m;

if (!Visibility::fileVisible(Visibility::viewer(), strtolower($class), $uuid)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif',
    'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
];

// ---- optional ?w= resize (craft gate 6/12: originals were shipping into
// 25px slots). Same buckets as /img.php; raster classes only; decision above
// already ran. Cache: .cache/<w>/<class>/<uuid>/<file>.webp under the media
// root (profile-app-writable), served via the same internal alias. Any
// failure falls through to the original — resize is an optimization, never
// a gate.
$w = (int)($_GET['w'] ?? 0);
$resizable = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)
          && in_array($class, ['avatars', 'banners', 'gallery'], true)
          && in_array($w, [96, 240, 400, 480, 600, 800, 960, 1200, 1600], true);
if ($resizable) {
    $root = '/srv/profile-app-media';
    $rel  = '.cache/' . $w . '/' . $class . '/' . strtolower($uuid) . '/' . $file . '.webp';
    $dst  = $root . '/' . $rel;
    // Versioned (?v=) / random gallery filenames mean a replaced image gets a NEW
    // name, so a cached variant is never stale — generate only on a cache miss.
    if (!is_file($dst)) {
        // Per-variant lock so a cold-cache burst doesn't fetch+resize the SAME image
        // N times (thundering herd): the first request builds, the rest block on the
        // lock then serve the freshly-built cache. The R2 fetch inside is capped by
        // R2's own 5s read timeout, so the critical section is bounded.
        @mkdir(dirname($dst), 0775, true);
        $lh = @fopen($dst . '.lock', 'c');
        if ($lh && flock($lh, LOCK_EX)) {
            if (!is_file($dst)) {   // recheck — another holder may have just built it
                $localSrc = $root . '/' . $class . '/' . strtolower($uuid) . '/' . $file;
                $bytes = is_file($localSrc) ? @file_get_contents($localSrc) : false;
                if (($bytes === false || $bytes === '') && R2::enabled()) {
                    $bytes = R2::get($class . '/' . strtolower($uuid) . '/' . $file);
                }
                if (is_string($bytes) && $bytes !== '') {
                    try {
                        $im = @imagecreatefromstring($bytes);
                        if ($im) {
                            // Bake EXIF orientation BEFORE measuring/resampling so a
                            // phone-JPEG original resizes upright (GD ignores EXIF).
                            $exifO = lg_media_exif_orientation($bytes);
                            if ($exifO > 1) {
                                $rot = lg_media_gd_orient($im, $exifO);
                                if ($rot && $rot !== $im) { imagedestroy($im); $im = $rot; }
                            }
                            $ow = imagesx($im); $oh = imagesy($im);
                            if ($ow > $w) {
                                $nh = (int)round($oh * $w / $ow);
                                $out = imagecreatetruecolor($w, $nh);
                                imagealphablending($out, false); imagesavealpha($out, true);
                                imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $nh, $ow, $oh);
                                imagedestroy($im); $im = $out;
                            }
                            @imagewebp($im, $dst, 82);
                            imagedestroy($im);
                        }
                    } catch (\Throwable $e) { /* fall through to original */ }
                }
            }
            flock($lh, LOCK_UN); fclose($lh); @unlink($dst . '.lock');
        } elseif ($lh) {
            fclose($lh);
        }
    }
    if (is_file($dst)) {
        header('Content-Type: image/webp');
        header('Cache-Control: ' . (($class === 'avatars' || $class === 'banners') ? 'public, max-age=2592000' : 'private, no-store'));
        header('X-Accel-Redirect: /profile-media-internal/' . str_replace('%2F', '/', rawurlencode($rel)));
        exit;
    }
}

header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));

// Public chrome stays long-cacheable (URLs carry ?v= versions). Gated classes
// must never land in a shared cache and must revalidate per viewer.
if ($class === 'avatars' || $class === 'banners') {
    header('Cache-Control: public, max-age=2592000');
} else {
    header('Cache-Control: private, no-store');
}

// Raw original: local (pre-migration) via X-Accel, else stream from R2. Only the
// rare raw path streams through PHP; the resized path above keeps the X-Accel model.
$localOrig = '/srv/profile-app-media/' . $class . '/' . strtolower($uuid) . '/' . $file;
if (is_file($localOrig)) {
    header('X-Accel-Redirect: /profile-media-internal/' . $class . '/' . strtolower($uuid) . '/' . rawurlencode($file));
    exit;
}
if (R2::enabled()) {
    $bytes = R2::get($class . '/' . strtolower($uuid) . '/' . $file);
    if (is_string($bytes)) {
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
}
http_response_code(404);
