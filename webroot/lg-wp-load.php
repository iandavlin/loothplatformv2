<?php
/**
 * Resolve WordPress's wp-load.php for a webroot script. Returns '' if it can't.
 *
 *   $wp_load = require __DIR__ . '/lg-wp-load.php';
 *
 * WHY THIS EXISTS. These scripts are SYMLINKED into the docroot from the
 * monorepo (/var/www/dev/loothalong.php -> …/loothplatformv2-clean/webroot/…),
 * and PHP resolves __DIR__ through the symlink to the REPO directory — where
 * wp-load.php does not and will never live. So `require __DIR__ . '/wp-load.php'`
 * fataled the moment those files became symlinks (2026-07-26), taking out the
 * Loothalong join redirect, saved posts, and push subscription registration.
 * The docroot is the only place that knows where WordPress is; ask the request.
 *
 * __DIR__ stays as the last resort so any surface still deployed as a plain
 * file-copy next to wp-load.php keeps working.
 */
declare(strict_types=1);

return (static function (): string {
    $candidates = [
        (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        isset($_SERVER['SCRIPT_FILENAME']) ? dirname((string) $_SERVER['SCRIPT_FILENAME']) : '',
        __DIR__,
    ];
    foreach ($candidates as $dir) {
        if ($dir !== '' && is_readable($dir . '/wp-load.php')) {
            return $dir . '/wp-load.php';
        }
    }
    return '';
})();
