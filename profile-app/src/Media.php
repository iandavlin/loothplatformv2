<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Media — confined deletion of a user's OWN profile-media file.
 *
 * The upload handlers (me-avatar / me-banner / me-resume) and the gallery save
 * (Block::saveGalleryImages) historically dropped or replaced a media reference
 * in the DB but never unlinked the underlying file under /srv/profile-app-media,
 * orphaning it — and its resizer .cache/<w>/ webp variants — forever. This is the
 * single place that removes one owned file safely.
 *
 * Confinement mirrors web/media.php's serve-side validation:
 *   - class must be one of the four media dirs;
 *   - filename is basename()'d, charset-whitelisted, '..' rejected;
 *   - the path is rebuilt under MEDIA_ROOT/<class>/<uuid>/ where <uuid> is the
 *     OWNER's uuid (never caller-supplied path), symlinks are refused, and a
 *     missing file is tolerated.
 *
 * EraseUser still owns whole-account teardown (recursive dir nuke); this helper
 * never touches another user's directory and never throws.
 */
final class Media
{
    private const MEDIA_ROOT        = '/srv/profile-app-media';
    private const CLASSES           = ['avatars', 'banners', 'gallery', 'resumes'];
    private const RESIZABLE_CLASSES = ['avatars', 'banners', 'gallery'];
    // Must match web/media.php's resizer width buckets exactly.
    private const CACHE_WIDTHS      = [96, 240, 400, 480, 600, 800, 960, 1200, 1600];

    /** Filename charset — same shape web/media.php accepts on serve. */
    private const FILE_RE = '/^[A-Za-z0-9][A-Za-z0-9._ -]*$/';
    private const UUID_RE = '/^[0-9a-fA-F-]{36}$/';

    /**
     * Delete one owned media file + its resizer cache variants. Best-effort:
     * returns true if the source existed and was removed; never throws on a
     * missing file. Refuses anything not strictly inside the owner's dir.
     */
    public static function unlinkOwnedFile(string $class, string $uuid, string $fn): bool
    {
        if (!in_array($class, self::CLASSES, true)) return false;
        if (!preg_match(self::UUID_RE, $uuid)) return false;
        $uuid = strtolower($uuid);

        $base = basename($fn);
        if ($base === '' || str_contains($base, '..') || !preg_match(self::FILE_RE, $base)) return false;

        $removed = false;

        // R2 (the store, when enabled): delete the original object. Confined by the
        // class/uuid/base validated above — no unchecked path component reaches R2.
        // Runs independently of the local dir, which may not exist for R2-only files.
        if (R2::enabled() && R2::delete($class . '/' . $uuid . '/' . $base)) {
            $removed = true;
            error_log('[media-gc] R2 deleted ' . $class . '/' . $uuid . '/' . $base);
        }

        // Local original (pre-migration files): realpath-confined to the owner dir.
        $classRoot = realpath(self::MEDIA_ROOT . '/' . $class);
        $realDir   = realpath(self::MEDIA_ROOT . '/' . $class . '/' . $uuid);
        if ($classRoot !== false && $realDir !== false
            && str_starts_with($realDir . '/', $classRoot . '/' . $uuid . '/')) {
            $target = $realDir . '/' . $base;
            if (is_file($target) && !is_link($target) && @unlink($target)) {
                $removed = true;
                error_log('[media-gc] unlinked ' . $class . '/' . $uuid . '/' . $base);
            }
        }

        // Resizer cache twins are ALWAYS local (resizable classes only).
        if (in_array($class, self::RESIZABLE_CLASSES, true)) {
            foreach (self::CACHE_WIDTHS as $w) {
                $cDir = realpath(self::MEDIA_ROOT . '/.cache/' . $w . '/' . $class . '/' . $uuid);
                if ($cDir === false) continue;
                $cTarget = $cDir . '/' . $base . '.webp';
                if (is_file($cTarget) && !is_link($cTarget) && @unlink($cTarget)) {
                    error_log('[media-gc] unlinked .cache/' . $w . '/' . $class . '/' . $uuid . '/' . $base . '.webp');
                }
            }
        }

        return $removed;
    }

    /**
     * Delete the file behind a stored /profile-media/<class>/<uuid>/<file> URL
     * (ignores any ?v= query). Returns false for any URL outside that shape, so
     * a null/empty/foreign url is a safe no-op.
     */
    public static function unlinkUrl(?string $url): bool
    {
        if ($url === null || $url === '') return false;
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) return false;
        if (!preg_match('#^/profile-media/(avatars|banners|gallery|resumes)/([0-9a-fA-F-]{36})/(.+)$#', $path, $m)) {
            return false;
        }
        return self::unlinkOwnedFile($m[1], $m[2], $m[3]);
    }
}
