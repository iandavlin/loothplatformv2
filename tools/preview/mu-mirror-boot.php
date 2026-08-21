<?php
/**
 * mu-mirror-boot.php — the front controller for a lane preview that needs the
 * BRANCH's mu-plugin, not the serve's.
 *
 * nginx points SCRIPT_FILENAME here and names the mirror in a fastcgi_param.
 * WordPress core sets WPMU_PLUGIN_DIR with `if ( ! defined(...) )`, so defining
 * it before wp-blog-header.php runs redirects the whole mu-plugin set to a
 * directory built by tools/preview/mu-mirror.sh — the serve's files, with one
 * swapped. Nothing on the serve is modified, and /compose/ on the real vhost is
 * completely unaffected.
 *
 * ⚠️ LG_MU_MIRROR IS READ FROM $_SERVER, WHICH ONLY AN NGINX CONF CAN SET.
 * A fastcgi_param never comes from a query string or a header a visitor
 * controls — and it does not appear in getenv() either, which is a recorded box
 * trap. It is checked against a fixed prefix as well, so even a
 * misconfiguration cannot point this at an arbitrary directory.
 */

$mirror = isset($_SERVER['LG_MU_MIRROR']) ? (string) $_SERVER['LG_MU_MIRROR'] : '';
$root   = '/home/ubuntu/.lg-preview/';

if ($mirror === '' || strpos($mirror, $root) !== 0 || strpos($mirror, '..') !== false
    || !is_dir($mirror) || !is_readable($mirror)) {
    // LOUD, never a silent fall-through to the serve's code. A preview that
    // quietly served main is the whole failure this file exists to prevent.
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "lane preview: LG_MU_MIRROR is not a readable mirror under $root.\n";
    echo "Build it with tools/preview/mu-mirror.sh before loading this URL.\n";
    exit;
}

define('WPMU_PLUGIN_DIR', $mirror);

/* The serve's own front controller, by absolute path. Its __DIR__ stays
   /var/www/dev, so every relative require inside WordPress still resolves —
   the box trap that breaks symlink-deployed docroot scripts. */
require '/var/www/dev/index.php';
