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

/* /profile-api/v0/* → the BRANCH profile-app on 8796 (its own pool user), so the
   notifications panel that feeds the ⋯ menu is served by branch code against the real
   profile_app database. nginx's rewrite convention here is regular —
   `me/notifications/` → `me-notifications.php`, `me/social-counts/` →
   `me-social-counts.php` — so we reproduce it rather than hardcode one route.
   Cookies pass straight through: the panel authenticates on the looth_id JWT. */
if (str_starts_with($uri, '/profile-api/v0/')) {
    $rest = trim(substr($uri, strlen('/profile-api/v0/')), '/');
    $file = $rest === '' ? 'me' : str_replace('/', '-', $rest);
    $qs   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    $ch   = curl_init('http://127.0.0.1:8796/' . $file . '.php' . ($qs ? '?' . $qs : ''));
    $hdrs = ['Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'application/json')];
    if (!empty($_SERVER['HTTP_COOKIE'])) $hdrs[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
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

/* Root-level docroot OVERLAYS (/app-settings.js, /hub-polish.js, /bottom-nav.js …).
   On dev2 these are symlinks in /var/www/dev pointing into the repo's webroot/. The
   Hub's <head> loads /app-settings.js, and that file is where §3.5's DARK rules for
   the ⋯ popover live — so without this route the popover renders cream-on-dark and a
   naive theme test passes for the wrong reason. Serve them from the BRANCH webroot. */
if (preg_match('#^/([A-Za-z0-9._-]+\.(?:js|css|json))$#', $uri, $mm)) {
    $WR = '/home/ubuntu/worktrees/thread-follow/webroot';
    $f  = realpath($WR . '/' . $mm[1]);
    if ($f !== false && str_starts_with($f, $WR . DIRECTORY_SEPARATOR) && is_file($f)) {
        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        header('Content-Type: ' . ['js' => 'application/javascript', 'css' => 'text/css',
                                   'json' => 'application/json'][$e]);
        readfile($f);
        return true;
    }
}

/* nginx `location ^~ /lg-shared/ { alias /srv/lg-shared/; }` — but pointed at the
   BRANCH copy, so the shared header's JS/CSS under test are the branch's. Note the
   PHP partial itself is still required by _chrome.php via the absolute /srv path,
   i.e. main's; only these static assets are branch. Labelled, not glossed. */
if (str_starts_with($uri, '/lg-shared/')) {
    $SH = '/home/ubuntu/worktrees/thread-follow/lg-shared';
    $f  = realpath($SH . substr($uri, strlen('/lg-shared')));
    if ($f !== false && str_starts_with($f, $SH . DIRECTORY_SEPARATOR) && is_file($f)
        && !str_ends_with(strtolower($f), '.php')) {
        $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        header('Content-Type: ' . (['js' => 'application/javascript', 'css' => 'text/css',
                                    'svg' => 'image/svg+xml'][$e] ?? 'application/octet-stream'));
        readfile($f);
        return true;
    }
    http_response_code(404);
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
    // Serve it OURSELVES. `return false` would hand php -S the ORIGINAL uri
    // (/hub/forums.js) which does not exist under the docroot — the /hub prefix is
    // an nginx `alias`, not a real directory. That 404s every asset while the HTML
    // still renders, so the page looks fine and no JS runs.
    static $types = [
        'js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ico' => 'image/x-icon',
        'json' => 'application/json', 'mp4' => 'video/mp4',
    ];
    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($candidate));
    readfile($candidate);
    return true;
}

/* ⚠️ THE OVERLAY LAYER IS INJECTED BY NGINX, NOT BY THE APP — and a loopback harness
   that bypasses nginx silently loses ALL of it.

   dev2.loothgroup.com.conf:47 carries a server-level `sub_filter '</head>' …` that
   appends the theme-boot script and `<script src="/pwa.js" defer>`. pwa.js then
   injects THIRTEEN root overlays at runtime — including app-settings.js (which holds
   §3.5's dark rules for the ⋯ popover) and hub-polish.js (which owns §2.4's
   #looth-rep-sheet). None of that appears in the app's own HTML, so grepping the
   served markup for "hub-polish" finds nothing and the wrong conclusion is very easy
   to reach: I reached it myself once.

   We reproduce the injection here so the harness renders the page the browser really
   gets. Kept deliberately minimal — the pwa.js loader only. */
$_SERVER['SCRIPT_FILENAME'] = $ROOT . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/hub/index.php';
ob_start();
require $ROOT . '/index.php';
$html = ob_get_clean();
$pos  = stripos($html, '</head>');
if ($pos !== false) {
    $html = substr($html, 0, $pos)
          . '<script src="/pwa.js" defer></script>'
          . substr($html, $pos);
}
echo $html;
