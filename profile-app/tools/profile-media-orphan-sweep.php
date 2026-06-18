<?php
declare(strict_types=1);

/**
 * tools/profile-media-orphan-sweep.php — find (and optionally delete) profile
 * media files on disk that NO live DB record references. These are the
 * historical orphans left by the pre-GC upload/replace/delete paths (the
 * handlers now clean up going forward; this reaps the backlog).
 *
 * DEFAULTS TO --dry-run: it only LISTS candidates + counts, deletes NOTHING.
 * Real deletion requires the explicit --apply flag.
 *
 * Orphan = an on-disk <class>/<uuid>/<file> whose served URL is referenced by
 *   neither users.{avatar,banner,resume}_url NOR profile_sections key='gallery'.
 * Deletion (only with --apply) routes through Media::unlinkOwnedFile, so it is
 * confined to the owner's dir and sweeps the resizer .cache/<w>/ twins too.
 *
 * Reads/writes /srv/profile-app-media (owned by profile-app) — run as that user:
 *   sudo -u profile-app php tools/profile-media-orphan-sweep.php            # dry-run
 *   sudo -u profile-app php tools/profile-media-orphan-sweep.php --apply    # delete
 *
 * SAFETY: before any --apply run, snapshot the store first, e.g.
 *   sudo tar czf /home/ubuntu/backups/profile-app-media-$(date +%F).tgz /srv/profile-app-media
 */

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Media;

const MEDIA_ROOT = '/srv/profile-app-media';
const CLASSES    = ['avatars', 'banners', 'gallery', 'resumes'];
const RESIZABLE  = ['avatars', 'banners', 'gallery'];
const WIDTHS     = [96, 240, 400, 480, 600, 800, 960, 1200, 1600];

$apply = in_array('--apply', $argv, true);

/** Parse a /profile-media/<class>/<uuid>/<file>[?v=] URL → "class/uuid/basename" key, or null. */
function ref_key(?string $url): ?string
{
    if (!is_string($url) || $url === '') return null;
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return null;
    if (!preg_match('#^/profile-media/(avatars|banners|gallery|resumes)/([0-9a-fA-F-]{36})/(.+)$#', $path, $m)) return null;
    return $m[1] . '/' . strtolower($m[2]) . '/' . basename($m[3]);
}

// ---- 1. Build the referenced-URL set from the DB --------------------------
$pg = Db::pg();
$referenced = [];

$rows = $pg->query('SELECT avatar_url, banner_url, resume_url FROM users')->fetchAll();
foreach ($rows as $r) {
    foreach (['avatar_url', 'banner_url', 'resume_url'] as $col) {
        if ($k = ref_key($r[$col] ?? null)) $referenced[$k] = true;
    }
}
$gal = $pg->query("SELECT data FROM profile_sections WHERE key = 'gallery'")->fetchAll();
foreach ($gal as $g) {
    $d = json_decode((string)$g['data'], true) ?: [];
    foreach (($d['images'] ?? []) as $im) {
        if ($k = ref_key($im['url'] ?? null)) $referenced[$k] = true;
    }
}

// ---- 2. Walk the disk; anything not referenced is an orphan ---------------
$totalFiles = 0; $totalBytes = 0; $totalTwins = 0;
$perClass = [];

foreach (CLASSES as $class) {
    $classDir = MEDIA_ROOT . '/' . $class;
    if (!is_dir($classDir)) continue;
    foreach (scandir($classDir) ?: [] as $uuid) {
        if ($uuid === '.' || $uuid === '..' || $uuid === '.cache') continue;
        $uDir = $classDir . '/' . $uuid;
        if (!is_dir($uDir)) continue;
        foreach (scandir($uDir) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            $full = $uDir . '/' . $file;
            if (!is_file($full)) continue;
            $key = $class . '/' . strtolower($uuid) . '/' . $file;
            if (isset($referenced[$key])) continue;   // live — keep

            $bytes = (int)@filesize($full);
            $twins = 0;
            if (in_array($class, RESIZABLE, true)) {
                foreach (WIDTHS as $w) {
                    if (is_file(MEDIA_ROOT . '/.cache/' . $w . '/' . $class . '/' . strtolower($uuid) . '/' . $file . '.webp')) $twins++;
                }
            }
            $totalFiles++; $totalBytes += $bytes; $totalTwins += $twins;
            $perClass[$class] = ($perClass[$class] ?? 0) + 1;

            printf("%-8s %s/%s  %8d B  +%d cache\n", $apply ? 'DELETE' : 'ORPHAN', $uuid, $file, $bytes, $twins);
            if ($apply) {
                Media::unlinkOwnedFile($class, $uuid, $file);
            }
        }
    }
}

// ---- 3. Summary -----------------------------------------------------------
echo "\n" . ($apply ? 'DELETED' : 'DRY-RUN — nothing deleted') . "\n";
foreach (CLASSES as $class) {
    echo "  $class: " . ($perClass[$class] ?? 0) . " orphan(s)\n";
}
printf("  total: %d originals, %d cache twins, %s\n",
    $totalFiles, $totalTwins, number_format($totalBytes / 1024, 1) . ' KiB');
if (!$apply && $totalFiles > 0) {
    echo "\nRe-run with --apply to delete (snapshot the store first).\n";
}
