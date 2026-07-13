<?php
/**
 * Viewer state for the shared header — cached /whoami loopback.
 *
 * Mirrors events / bb-mirror: render the chrome viewer-aware without paying
 * the WP-bootstrap tax on every request. Cached in /dev/shm keyed by the
 * WP session cookie (or "anon" if absent). TTL 45s — coarse enough to absorb
 * the role-change → /whoami-mint cycle.
 *
 * Per coord §0c: this is the TRANSITIONAL viewer source. Post-shim-replacement,
 * surfaces read identity from the `looth_id` JWT + `lg_tier` cookie directly
 * (no loopback). This helper is the bridge until that contract ships.
 *
 * CURL gotchas (called out in coord briefing — events-landing hit these):
 *   HTTP/2 ALPN handshake times out from a fresh FPM worker → force HTTP/1.1
 *   CURLOPT_TIMEOUT = 5 — bound the cold-cache latency
 */

declare(strict_types=1);

if (!function_exists('lg_membership_whoami')) {
function lg_membership_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;

    $ttl  = 45;
    $sess = '';
    foreach ($_COOKIE as $k => $v) {
        if (strpos($k, 'wordpress_logged_in_') === 0) { $sess = (string)$v; break; }
    }
    $key   = $sess !== '' ? hash('sha256', $sess) : 'anon';
    $cache = '/dev/shm/lg-membership-whoami-' . $key . '.json';
    if (is_readable($cache) && (time() - (int)filemtime($cache)) < $ttl) {
        $hit = json_decode((string)file_get_contents($cache), true);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $result = (is_array($hit['v']) ? $hit['v'] : null);
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/whoami',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_MEMBERSHIP_HOST,
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = ($code === 200 && is_string($body)) ? (json_decode($body, true) ?: null) : null;
    @file_put_contents($cache, json_encode(['v' => $result]), LOCK_EX);
    return $result;
}
}

/**
 * Mint a wp_rest nonce for the logged-in caller via loopback.
 *
 * The interactive ported surfaces (refund, affiliate, gift/subscription mgmt)
 * POST to cookie+nonce-gated /wp-json/lg-member-sync/v1/* routes. A standalone
 * page can't mint the nonce itself, so it loopback-fetches GET
 * /wp-json/looth/v1/rest-nonce (forwarding the browser's WP cookies, exactly
 * like lg_membership_whoami) and embeds the result where the shortcode did
 * `echo $nonce`. Returns '' for anon / on failure (the JS then no-ops/403s,
 * same as a logged-out shortcode visitor). Cached per request.
 */
if (!function_exists('lg_membership_rest_nonce')) {
function lg_membership_rest_nonce(string $action = ''): string {
    static $cache = [];
    $key = $action !== '' ? $action : 'wp_rest';
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (PHP_SAPI === 'cli') return $cache[$key] = '';

    $url = 'https://127.0.0.1/wp-json/looth/v1/rest-nonce'
         . ($action !== '' ? '?action=' . rawurlencode($action) : '');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_MEMBERSHIP_HOST,
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $nonce = '';
    if ($code === 200 && is_string($body)) {
        $j = json_decode($body, true);
        if (is_array($j) && !empty($j['nonce'])) $nonce = (string) $j['nonce'];
    }
    return $cache[$key] = $nonce;
}
}

/**
 * Build the §0a-compliant ctx array for lg_shared_render_site_header().
 * Pass-through from /whoami plus the consumer-responsibility fields
 * (active_nav, logout_url, logo_url, profile_url).
 *
 * @param string $active_nav   '' for membership pages (not in canonical top nav per §0d)
 */
if (!function_exists('lg_membership_header_ctx')) {
function lg_membership_header_ctx(string $active_nav = ''): array {
    $who    = lg_membership_whoami();
    $authed = ($who['authenticated'] ?? false) === true;

    return [
        'authenticated' => $authed,
        'tier'          => (string)($who['tier'] ?? 'public'),
        'display_name'  => (string)($who['display_name'] ?? ''),
        'avatar_url'    => $who['avatar_url'] ?? null,
        'capabilities'  => (array)($who['capabilities'] ?? []),
        'msg_unread'    => null,                                       // lazy via REST
        'notif_unread'  => null,                                       // lazy via REST
        'logo_url'      => LG_MEMBERSHIP_LOGO,
        'profile_url'   => '/profile/edit',
        'active_nav'    => $active_nav,                                // coord §0a
        'logout_url'    => $authed ? '/wp-login.php?action=logout' : null,
    ];
}
}

/**
 * "Your session has expired — sign in again" card (inner HTML).
 *
 * Actionable membership surfaces (gift send/void, affiliate withdraw, …) POST to
 * cookie+nonce-gated /wp-json/lg-member-sync/v1/* routes. The gate is the
 * wp_rest nonce minted by lg_membership_rest_nonce() — which loopbacks to the WP
 * REST nonce route and only succeeds for a LIVE WP session. A '' nonce means the
 * session is stale/rotated (or a Patreon-onboarded identity never minted a WP
 * auth cookie): the routes will 401. We must gate the action UI on THIS, not on
 * whoami / the cookie-username string (both of which can be present over a dead
 * session) — otherwise the page renders a live button that POSTs an empty
 * X-WP-Nonce and silently 401s. Render this re-auth state instead.
 *
 * Returns inner HTML for pages that own their <main>/page-wrap. The link sends
 * the viewer through wp-login and back to the page they were on.
 */
if (!function_exists('lg_membership_session_expired_html')) {
function lg_membership_session_expired_html(): string {
    $h        = 'lg_membership_h';
    $here     = 'https://' . LG_MEMBERSHIP_HOST . (string)($_SERVER['REQUEST_URI'] ?? '/');
    $loginUrl = 'https://' . LG_MEMBERSHIP_HOST . '/wp-login.php?redirect_to=' . rawurlencode($here);
    return
        '<div class="lg-session-expired" style="max-width:560px;margin:2.5em auto;padding:1.4em 1.6em;background:#fff8f0;border:1px solid #ECB351;border-radius:10px;color:#1f1d1a;line-height:1.55;text-align:center;">'
      . '<p style="margin:0 0 .5em;font-size:1.1em;font-weight:700;">Your session has expired</p>'
      . '<p style="margin:0 0 1.1em;color:#555;">For your security we couldn&rsquo;t verify your sign-in. Please sign in again to manage your membership.</p>'
      . '<a href="' . $h($loginUrl) . '" style="display:inline-block;padding:.6em 1.3em;background:#1f1d1a;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">Sign in again</a>'
      . '</div>';
}
}

/**
 * Emit a complete standalone "session expired" page (shared header + the card +
 * footer) and exit — for actionable surfaces that emit their doctype/header/
 * footer inline rather than through a page-wrap closure. Mirrors _admin-gate's
 * stub shape. Call AFTER config + lib/whoami + the shared header/footer are
 * required and $ctx is built.
 */
if (!function_exists('lg_membership_render_session_expired_or_exit')) {
function lg_membership_render_session_expired_or_exit(array $ctx, string $title = 'Session expired — The Looth Group'): void {
    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= lg_membership_h($title) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="lg-membership-page lg-session-expired-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
<?= lg_membership_session_expired_html() ?>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
<?php
    exit;
}
}
