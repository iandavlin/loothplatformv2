<?php
/**
 * php -S router that serves a BRANCH's profile-app the way nginx serves the real one.
 *
 * nginx (snippets/strangler-profile-app.conf) does not hand profile-app a filesystem
 * path — it rewrites, per route:
 *
 *   ^/u/<slug>/edit  -> web/u-edit.php   QUERY_STRING slug=<slug>&<args>
 *   ^/u/<slug>       -> web/u.php        QUERY_STRING slug=<slug>&<args>
 *   ^/p/<slug>       -> web/p.php        QUERY_STRING slug=<slug>&<args>
 *
 * php -S has no such rewrite, so pointed straight at web/ it 404s every clean URL
 * and the branch reads as broken. This reproduces the mapping, nothing more.
 *
 * Run as the REAL pool user so the permissions posture is production's:
 *
 *   sudo -u profile-app env LG_SOCIAL_ACTIONS_SRC=1 \
 *     php -S 127.0.0.1:8894 -t $BR/profile-app/web \
 *     /tmp/<lane>-exercise/profile-app-router.php
 *
 * Copy it somewhere world-traversable first — /home/ubuntu is drwxr-x--x, so the
 * pool user can read the files but cannot traverse a /tmp/claude-* scratch dir.
 */

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');

$map = [
    '#^/u/([\w\-]+)/edit/?$#' => 'u-edit.php',
    '#^/u/([\w\-]+)/?$#'      => 'u.php',
    '#^/p/([\w\-]+)/?$#'      => 'p.php',
];

foreach ($map as $re => $script) {
    if (preg_match($re, $uri, $m)) {
        // Mirror nginx's QUERY_STRING rewrite: the slug is PREPENDED, so an
        // explicit ?slug= in the URL loses to the path — same as production.
        $_GET['slug'] = $m[1];
        $_REQUEST['slug'] = $m[1];
        $qs = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '';
        $_SERVER['QUERY_STRING'] = 'slug=' . rawurlencode($m[1]) . ($qs !== '' ? '&' . $qs : '');
        $_SERVER['SCRIPT_FILENAME'] = $root . '/' . $script;
        $_SERVER['SCRIPT_NAME'] = '/' . $script;
        require $root . '/' . $script;
        return true;
    }
}

// Anything else: let php -S serve it from the docroot if it exists.
$file = $root . $uri;
if ($uri !== '/' && is_file($file)) return false;

http_response_code(404);
echo "profile-app-router: no route for " . htmlspecialchars($uri);
return true;
