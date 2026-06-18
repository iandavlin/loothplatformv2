<?php
/**
 * On-the-fly cover-image resizer for the Hub feed (Ian 2026-06-11).
 *
 * Deployed at /var/www/dev/img.php (served by the looth-dev WP pool, which can
 * read the R2-backed uploads + write the cache). Behind the dev cookie gate.
 *
 * The Hub feed stored full-resolution upload URLs (3024px phone photos served
 * into 434px cards = ~9 MB/page). This reads the original, downscales to a
 * clamped width, and serves cached WebP. Also normalises http:// upload URLs to
 * a same-origin https request (kills the mixed-content warnings).
 *
 *   /img.php?s=<uploads-relative-path>&w=<400|600|800|1200>
 *
 * Source copy lives in the bespoke-cutover branch at
 * bb-mirror/cover-img-resizer.php for review/versioning.
 */
declare(strict_types=1);

const UPLOADS = '/var/www/dev/wp-content/uploads';
const CACHE   = UPLOADS . '/_rzcache';
const QUALITY = 82;
const ALLOWED_W = [96, 240, 400, 480, 600, 800, 960, 1200, 1600];  // small buckets: avatars 96, rails 240/480 (craft gate 6/12)
const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

$s = (string)($_GET['s'] ?? '');
$w = (int)($_GET['w'] ?? 800);
if (!in_array($w, ALLOWED_W, true)) {
    $w = 800;
}

// Last-resort: send the browser to the original so the <img> never breaks.
$orig_url = '/wp-content/uploads/' . ltrim($s, '/');
function fallback(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

if ($s === '' || str_contains($s, '..') || str_contains($s, "\0")) {
    fallback($orig_url);
}

// Resolve + hard-validate the source stays inside the uploads tree.
$real = realpath(UPLOADS . '/' . ltrim($s, '/'));
// BB discussion-attachment URLs are VIRTUAL (bb_medias/... served by BB's
// media handler); the real bytes live under fea-submissions/ with the same
// basename. Without this remap, those covers 302'd to the full-res original
// (craft gate caught a 394KB/1871px one in a 375px slot — 6/12).
if ($real === false && str_starts_with(ltrim($s, '/'), 'bb_medias/')) {
    $real = realpath(UPLOADS . '/fea-submissions/' . basename($s));
    if ($real === false) {
        // Only WP's sized variants exist for some attachments — take the widest.
        $stem = pathinfo(basename($s), PATHINFO_FILENAME);
        $bext = pathinfo(basename($s), PATHINFO_EXTENSION);
        $best = 0; $pick = false;
        foreach ((glob(UPLOADS . '/fea-submissions/' . $stem . '-*x*.' . $bext) ?: []) as $cand) {
            if (preg_match('/-(\d+)x\d+\.' . preg_quote($bext, '/') . '$/', $cand, $mm) && (int)$mm[1] > $best) {
                $best = (int)$mm[1]; $pick = $cand;
            }
        }
        if ($pick !== false) $real = realpath($pick);
    }
}
$base = realpath(UPLOADS);
// Containment: inside the uploads tree OR the R2 uploads mount (symlinked
// subdirs resolve into the mount; dev + live mountpoints both allowed).
$lg_contained = static function (?string $r) use ($base): bool {
    if (!$r) return false;
    foreach (array_filter([$base, '/mnt/loothgroup-uploads-dev', '/mnt/r2-uploads']) as $root) {
        if (strncmp($r, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0) return true;
    }
    return false;
};
if ($real === false || $base === false || !$lg_contained($real)) {
    fallback($orig_url);
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if (!in_array($ext, ALLOWED_EXT, true)) {
    fallback($orig_url);
}

$serve = static function (string $file): never {
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
};

$key       = sha1($real . '|' . (filemtime($real) ?: 0) . '|' . $w) . '.webp';
$cachefile = CACHE . '/' . $key;
if (is_file($cachefile)) {
    $serve($cachefile);
}

$info = @getimagesize($real);
if ($info === false) {
    fallback($orig_url);
}
[$ow, $oh] = $info;

// Decode by ACTUAL mime, not filename: uploads carry misnamed files (a JPEG
// with a .webp name 302'd to its 394KB original — craft gate, 6/12).
$src_im = match ($info['mime'] ?? '') {
    'image/jpeg' => @imagecreatefromjpeg($real),
    'image/png'  => @imagecreatefrompng($real),
    'image/webp' => @imagecreatefromwebp($real),
    'image/gif'  => @imagecreatefromgif($real),
    default      => false,
};
if (!$src_im) {
    fallback($orig_url);
}

if ($ow <= $w) {
    $dst = $src_im;                              // already small — just re-encode
} else {
    $nw  = $w;
    $nh  = max(1, (int)round($oh * ($w / $ow)));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src_im, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
}

if (!is_dir(CACHE)) {
    @mkdir(CACHE, 0775, true);
}
// Write to a temp file then rename so concurrent requests never serve a partial.
$tmp = $cachefile . '.' . getmypid() . '.tmp';
$ok  = @imagewebp($dst, $tmp, QUALITY);
imagedestroy($src_im);
if ($dst !== $src_im) {
    imagedestroy($dst);
}

if ($ok && @rename($tmp, $cachefile)) {
    $serve($cachefile);
}
@unlink($tmp);
fallback($orig_url);
