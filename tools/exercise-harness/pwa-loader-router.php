<?php
/**
 * pwa-loader-router.php — nginx's `location = /pwa.js` for php -S.
 *
 * nginx fastcgi-passes /pwa.js to pwa-loader.php, which prepends window.LG_V and (when
 * platform/config/pwa-sw.php is on) window.LG_SW. A php -S docroot'd at webroot/ would
 * serve the STATIC pwa.js instead and neither global would be emitted — so a swapped
 * /pwa.js would silently register '/sw.js' with no flag, and a flag-ON verification
 * would measure the OFF path while looking like it had armed the flag.
 *
 * Dispatches by absolute path into the BRANCH so pwa-loader.php's __DIR__-relative
 * config read (../platform/config/pwa-sw.php) resolves to the branch's config and not
 * the serving checkout's.
 */
$BR = getenv('PWA_BRANCH') ?: '/home/ubuntu/worktrees/recap-read-timer';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/pwa.js') {
    $f = $BR . '/webroot/pwa-loader.php';
    if (!is_file($f)) { http_response_code(500); echo "// missing $f"; return true; }
    chdir(dirname($f));
    require $f;
    return true;
}

// Anything else this router is asked for comes straight off the branch webroot.
$file = realpath($BR . '/webroot' . $path);
if ($file && strpos($file, realpath($BR . '/webroot')) === 0 && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = ['js' => 'application/javascript', 'html' => 'text/html', 'json' => 'application/json',
              'css' => 'text/css', 'png' => 'image/png', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream') . '; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile($file);
    return true;
}
http_response_code(404);
echo 'not found';
return true;
