<?php
require __DIR__.'/../config.php';
/**
 * archive-poc/web/_chrome.php — site header partial.
 *
 * Thin adapter: resolves viewer state from /whoami and passes it to the
 * shared header partial at /srv/lg-shared/site-header.php.
 *
 * Optional vars from caller:
 *   $is_member (bool)   — legacy authenticated hint; only used as a fallback
 *                         when /whoami is unavailable.
 *
 * Identity ($ctx authenticated/tier/display_name/avatar_url/capabilities/
 * profile_url) is sourced from /whoami VERBATIM per the shared-header contract
 * (docs/relay-header-convergence.md) — NOT cookies, globals, or JWT claims.
 * lg-shell owns the $ctx contract; route contract questions there.
 */

require_once '/srv/lg-shared/site-header.php';

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

// Single source of truth for viewer identity (cached per request).
$_whoami        = lg_archive_poc_whoami();
$_authenticated = (bool) ($_whoami['authenticated'] ?? ($is_member ?? false));

lg_shared_render_site_header([
    'authenticated'      => $_authenticated,
    'tier'               => (string) ($_whoami['tier'] ?? 'public'),
    'display_name'       => (string) ($_whoami['display_name'] ?? ''),
    'avatar_url'         => $_whoami['avatar_url'] ?? null,
    'capabilities'       => (array) ($_whoami['capabilities'] ?? []),
    'msg_unread'         => null,          // lazy-loaded by archive.js
    'notif_unread'       => null,          // lazy-loaded by archive.js
    'logo_url'           => LG_ARCHIVE_POC_LOGO_URL,
    'search_id'          => 'chrome-q',    // archive.js listens for #chrome-q
    'search_placeholder' => 'Search the archive…',
    'profile_url'        => !empty($_whoami['slug'])
        ? '/u/' . rawurlencode((string) $_whoami['slug'])
        : '/profile/edit',
    // Which nav item to suppress (you don't link to the page you're on). The
    // /archive/ search page sets 'archive'; the front page sets '' so the
    // Archive button shows. Defaults to 'archive' if a caller doesn't set it.
    'active_nav'         => $lg_active_nav ?? 'archive',
]);
