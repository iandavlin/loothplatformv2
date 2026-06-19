<?php
/**
 * profile-app — AVATAR CONSOLIDATION (clean-slate build, Phase 2).
 *
 * Make the R2 profile bucket the SINGLE source of truth for avatars. For every
 * (non-archived) user we re-derive one avatar by this fallback chain, COMPRESS
 * it (cap 512px, WebP q82 via ImageOptimize), write the result into R2 keyed on
 * uuid, and point avatar_url at the served URL:
 *
 *   1. BB upload   /wp-content/uploads/avatars/<wp_user_id>/*-bpfull.*  (mount)
 *   2. real Gravatar   md5(primary_email) with ?d=404  (404 ⇒ no real gravatar)
 *   3. colored-letter  generated PNG (first initial + deterministic colour)
 *
 *   store (R2):  avatars/<uuid>/<V>.webp        (everything normalised to webp)
 *   serve:       /profile-media/avatars/<uuid>/<V>.webp?v=<V>   (avatar_version=V)
 *
 * Nobody is left null — the letter avatar is a real bucket object — so the
 * bucket alone defines every avatar and there is nothing left to drift.
 *
 * IDEMPOTENCY is OBJECT-PRESENCE, not a version number: a user is "done" only if
 * they are at TARGET_VERSION *and* their object actually exists in the bucket.
 * (The earlier version-only guard skipped users who were coincidentally already
 * at the target version from prior work but had no object — they 404'd. This
 * self-heals that and survives a bucket repoint, e.g. dev3 / the live cut.)
 *
 * Writes are verified (put + exists) before the DB advances, and the prior
 * object is GC'd, so the bucket never accretes orphans across re-runs.
 *
 * Flags:  --dry-run         compute + report the chain breakdown, write nothing
 *         --limit=N         only the first N candidates
 *         --only=<uuid>     a single user (debug)
 *         --force           re-process even users already done
 *
 * Run from /srv/profile-app (config.php + R2 secret); BB mount needs root, but
 * PG is peer-auth so run as the profile-app user with LG_BB_AVATAR_DIR pointed
 * at a profile-app-readable stage of the *-bpfull.* originals.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/ImageOptimize.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\R2;
use Looth\ProfileApp\ImageOptimize;

const TARGET_VERSION = 3;          // compressed-webp generation (was 2 = raw)
const AVATAR_PX      = 512;
const FONT_BOLD      = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
const URL_BASE       = '/profile-media/avatars';

$DRY   = in_array('--dry-run', $argv, true);
$FORCE = in_array('--force', $argv, true);
$LIMIT = 0; $ONLY = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--limit=')) $LIMIT = (int) substr($a, 8);
    if (str_starts_with($a, '--only='))  $ONLY  = strtolower(substr($a, 7));
}

$bbBase = getenv('LG_BB_AVATAR_DIR') ?: '/var/www/dev/wp-content/uploads/avatars';
if (!is_dir($bbBase) || !is_readable($bbBase)) {
    fwrite(STDERR, "ABORT — BB avatar dir not readable: $bbBase (run as root for the uid-locked mount)\n");
    exit(3);
}
if (!$DRY && !R2::enabled()) {
    fwrite(STDERR, "ABORT — R2 not configured (check /etc/looth/profile-r2)\n");
    exit(3);
}

$pg = Db::pg();
$sql = "SELECT u.id, u.uuid, u.primary_email, u.display_name, u.slug, u.avatar_version, u.avatar_url,
               b.wp_user_id
        FROM users u
        LEFT JOIN wp_user_bridge b ON b.user_id = u.id
        WHERE u.archived_at IS NULL";
if ($ONLY !== '') $sql .= " AND lower(u.uuid::text) = " . $pg->quote($ONLY);
$sql .= " ORDER BY u.id";
if ($LIMIT > 0) $sql .= " LIMIT " . $LIMIT;
$rows = $pg->query($sql)->fetchAll(PDO::FETCH_ASSOC);

printf("consolidate-avatars: %d candidates%s%s\n", count($rows),
    $DRY ? "  [DRY-RUN]" : "", $FORCE ? "  [FORCE]" : "");

$upd = $pg->prepare("UPDATE users SET avatar_url = :url, avatar_version = :v WHERE id = :id");
$c = ['bb' => 0, 'gravatar' => 0, 'letter' => 0, 'skipped' => 0, 'failed' => 0, 'raw_fallback' => 0];

foreach ($rows as $r) {
    $uuid = strtolower((string) $r['uuid']);

    // OBJECT-PRESENCE idempotency: done only if at TARGET *and* object really exists.
    if (!$FORCE && (int) $r['avatar_version'] === TARGET_VERSION) {
        $curKey = key_from_url($r['avatar_url']);
        if ($curKey !== null && R2::exists($curKey)) { $c['skipped']++; continue; }
    }

    [$bytes, $ext, $source] = resolve_avatar($r, $bbBase);
    if ($bytes === null) { $c['failed']++; fwrite(STDERR, "  ! u{$r['id']} ($uuid): all sources failed\n"); continue; }

    // Compress / cap at write time; fall back to the raw bytes if un-decodable.
    try {
        [$bytes, $ext] = ImageOptimize::avatar($bytes);
    } catch (\Throwable $e) {
        $c['raw_fallback']++;
        fwrite(STDERR, "  ~ u{$r['id']} ($uuid): optimize fallback raw .$ext: " . $e->getMessage() . "\n");
    }

    $key = "avatars/$uuid/" . TARGET_VERSION . ".$ext";
    if (!$DRY) {
        if (!R2::put($key, $bytes, mime_for($ext)) || !R2::exists($key)) {
            $c['failed']++; fwrite(STDERR, "  ! u{$r['id']} ($uuid): R2 write/verify failed ($key)\n"); continue;
        }
        $oldKey = key_from_url($r['avatar_url']);
        $upd->execute([
            ':url' => URL_BASE . "/$uuid/" . TARGET_VERSION . ".$ext?v=" . TARGET_VERSION,
            ':v'   => TARGET_VERSION,
            ':id'  => $r['id'],
        ]);
        // GC the prior object so the bucket never accretes orphans across re-runs.
        if ($oldKey !== null && $oldKey !== $key) @R2::delete($oldKey);
    }
    $c[$source]++;
}

echo "----\n";
foreach ($c as $k => $v) printf("  %-12s %d\n", $k, $v);

// ── helpers ─────────────────────────────────────────────────────────────────

/** "/profile-media/avatars/<uuid>/<file>?v=" → "avatars/<uuid>/<file>" (or null). */
function key_from_url(?string $url): ?string
{
    if (!is_string($url) || $url === '') return null;
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return null;
    if (!preg_match('#^/profile-media/(avatars/[0-9a-fA-F-]{36}/.+)$#', $path, $m)) return null;
    return $m[1];
}

/** @return array{0:?string,1:string,2:string} [bytes, ext, source] */
function resolve_avatar(array $r, string $bbBase): array
{
    if (!empty($r['wp_user_id'])) {
        $src = bb_avatar_file($bbBase, (int) $r['wp_user_id']);
        if ($src !== null) {
            $b = @file_get_contents($src);
            if ($b !== false && $b !== '') {
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';
                if ($ext === 'jpeg') $ext = 'jpg';
                return [$b, $ext, 'bb'];
            }
        }
    }
    [$gb, $gext] = real_gravatar((string) ($r['primary_email'] ?? ''));
    if ($gb !== null) return [$gb, $gext, 'gravatar'];

    $label = $r['display_name'] ?: $r['slug'] ?: '?';
    return [letter_avatar((string) $label, strtolower((string) $r['uuid'])), 'png', 'letter'];
}

function bb_avatar_file(string $base, int $wpId): ?string
{
    if ($wpId < 1) return null;
    $dir = $base . '/' . $wpId;
    if (!is_dir($dir)) return null;
    $m = glob($dir . '/*-bpfull.*') ?: [];
    if (!$m) return null;
    usort($m, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $m[0];
}

/** @return array{0:?string,1:string} */
function real_gravatar(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) return [null, 'jpg'];
    $url = "https://www.gravatar.com/avatar/" . md5($email) . "?d=404&s=" . AVATAR_PX;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body) || $body === '') return [null, 'jpg'];
    return [$body, 'jpg'];   // ext irrelevant — ImageOptimize re-encodes to webp
}

function letter_avatar(string $label, string $uuid): string
{
    $ch = '?';
    if (preg_match('/\p{L}|\p{N}/u', $label, $mm)) $ch = mb_strtoupper($mm[0]);

    [$rr, $gg, $bb] = hsl_to_rgb((crc32($uuid) % 360) / 360.0, 0.52, 0.52);

    $sz = AVATAR_PX;
    $im = imagecreatetruecolor($sz, $sz);
    $bg = imagecolorallocate($im, $rr, $gg, $bb);
    $fg = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, $sz, $sz, $bg);

    $fontSize = (int) ($sz * 0.44);
    $box = imagettfbbox($fontSize, 0, FONT_BOLD, $ch);
    $tw = $box[2] - $box[0]; $th = $box[1] - $box[7];
    $x = (int) (($sz - $tw) / 2 - $box[0]);
    $y = (int) (($sz - $th) / 2 - $box[7]);
    imagettftext($im, $fontSize, 0, $x, $y, $fg, FONT_BOLD, $ch);

    ob_start(); imagepng($im); $png = (string) ob_get_clean();
    imagedestroy($im);
    return $png;
}

/** @return array{0:int,1:int,2:int} */
function hsl_to_rgb(float $h, float $s, float $l): array
{
    $f = function ($n) use ($h, $s, $l) {
        $k = fmod($n + $h * 12, 12);
        $a = $s * min($l, 1 - $l);
        return $l - $a * max(-1, min($k - 3, 9 - $k, 1));
    };
    return [(int) round($f(0) * 255), (int) round($f(8) * 255), (int) round($f(4) * 255)];
}

function mime_for(string $ext): string
{
    return ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream';
}
