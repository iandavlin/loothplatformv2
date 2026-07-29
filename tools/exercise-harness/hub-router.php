<?php
/**
 * Loopback router for the thread-follow EXERCISE PASS.
 *
 * Emulates the nginx `location ^~ /hub/` block (strangler-bb-mirror.conf:60-79):
 *   alias /srv/bb-mirror/web/  +  try_files $uri /hub/index.php
 * but aliased at the BRANCH worktree instead of the serving checkout, so the
 * branch's server-rendered markup is what the browser receives.
 *
 * Runs under `php -S` as the SAME user as the prod pool (bb-mirror), so the
 * app's own permissions posture is unchanged — it still cannot read wp-load.php,
 * exactly as in production.
 *
 * Nothing here is deployed. The serving checkout is untouched.
 */

$ROOT = '/home/ubuntu/worktrees/thread-follow/bb-mirror/web';

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/* The nginx vhost routes /bb-mirror-api/v0/follow to the looth-dev pool, which is a
   DIFFERENT unix user from this one. We keep that split honest by proxying to the
   second loopback server (8792) rather than executing follow.php here — it would run
   as bb-mirror, which cannot read wp-load.php, exactly as in production.
   The browser's own Cookie and X-WP-Nonce are forwarded untouched, so the endpoint
   still resolves the acting user from real WP auth cookies. */
if (str_starts_with($uri, '/bb-mirror-api/v0/follow')) {
    $qs   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    $url  = 'http://127.0.0.1:8792/follow.php' . ($qs ? '?' . $qs : '');
    $hdrs = ['Content-Type: application/json'];
    foreach (['HTTP_COOKIE' => 'Cookie', 'HTTP_X_WP_NONCE' => 'X-WP-Nonce'] as $k => $h) {
        if (!empty($_SERVER[$k])) $hdrs[] = $h . ': ' . $_SERVER[$k];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $_SERVER['REQUEST_METHOD'],
        CURLOPT_POSTFIELDS     => file_get_contents('php://input'),
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($ch);
    http_response_code((int) curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    header('Content-Type: application/json');
    echo $body;
    return true;
}

// `alias` semantics: strip the /hub mount, resolve against the web dir.
$rel = $uri;
if ($rel === '/hub' || str_starts_with($rel, '/hub/')) {
    $rel = substr($rel, 4);
}
$rel = '/' . ltrim($rel, '/');

// try_files $uri — serve a real static file when one exists. Never allow a
// traversal out of the tree, and never serve a .php as bytes (nginx denies any
// .php under web/ other than the front controller).
$candidate = realpath($ROOT . $rel);
if ($candidate !== false
    && str_starts_with($candidate, $ROOT . DIRECTORY_SEPARATOR)
    && is_file($candidate)
    && !str_ends_with(strtolower($candidate), '.php')) {
    return false;   // let php -S serve it with its own mime handling
}

// ...otherwise the front controller, exactly as try_files falls through.
$_SERVER['SCRIPT_FILENAME'] = $ROOT . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/hub/index.php';
require $ROOT . '/index.php';
