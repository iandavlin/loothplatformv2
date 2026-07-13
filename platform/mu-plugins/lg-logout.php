<?php
/**
 * Plugin Name: LG One-Click Logout
 * Description: A branded, nonce-free /logout endpoint so Sign out from EVERY
 *              surface — WP-rendered AND WP-free (strangler) pages that share
 *              one header (lg-shared/site-header.php) — logs the member out in
 *              ONE click and lands them on / . Kills the bare unbranded WP
 *              "Do you really want to log out?" interstitial. (GH #55)
 *
 * WHY THIS EXISTS: the shared header ships a single sign-out href. WP-free pages
 * cannot mint a per-user WP logout nonce at render time, so they fell back to a
 * bare /wp-login.php?action=logout — and without _wpnonce WP shows the confirm
 * interstitial. This route validates the WP auth cookie server-side (it runs
 * inside WP), calls wp_logout(), and redirects home. No nonce needed.
 *
 * CSRF TRADE-OFF (honest): a nonce-free GET logout reintroduces logout-CSRF —
 * an attacker page could try to sign a member out. This is an ANNOYANCE, not a
 * breach (no data read/written, no session hijack). Two layers contain it:
 *   1. The WP auth cookie is SameSite=Lax, so it is WITHHELD on cross-site
 *      SUBRESOURCE loads (<img>, fetch, iframe). The classic silent
 *      auto-logout-on-page-load attack therefore reaches us with no session and
 *      is a harmless no-op.
 *   2. Lax still sends the cookie on a cross-site TOP-LEVEL navigation (victim
 *      clicks an attacker link). We reject those via Sec-Fetch-Site: cross-site.
 * Same-origin / same-site / none (direct nav, bookmark) and header-absent
 * (older browsers — annoyance-only) are allowed. wp-admin's own logout handler
 * and its check_admin_referer('log-out') nonce gate are UNTOUCHED — nothing here
 * weakens wp-admin.
 *
 * DEPLOY (house rule: mu-plugins ship in the repo, deploy by cp):
 *   cp platform/mu-plugins/lg-logout.php /srv/.../wp-content/mu-plugins/
 * OFF SWITCH: update_option('lg_logout_endpoint_enabled','0') degrades /logout
 * gracefully to WP's own /wp-login.php?action=logout (logout still works, the
 * old interstitial returns) — no white screen, no broken Sign out.
 */

defined('ABSPATH') || exit;

// Priority MUST be negative: /logout is an unrouted path, so WP treats it as a
// 404, and lg-error-pages.php hooks template_redirect at priority 0 to readfile()
// the branded 404 and exit(). It loads before this file (alphabetical mu-plugin
// glob order), so at equal priority it would win and our handler would never run.
// Running early (before any 404 interceptor) claims /logout first.
add_action('template_redirect', function () {
    // Match /logout (with or without a trailing slash), query string ignored.
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    $path = rtrim($path, '/');
    if ($path !== '/logout') {
        return;
    }

    // Graceful off switch: hand back to WP's native logout (interstitial and
    // all) rather than 404 the Sign out link the shared header points here.
    if (get_option('lg_logout_endpoint_enabled', '1') !== '1') {
        nocache_headers();
        wp_safe_redirect('/wp-login.php?action=logout', 302);
        exit;
    }

    // Logout-CSRF containment: reject cross-site top-level navigations. A
    // genuine Sign out click is same-origin; SameSite=Lax already neutralises
    // cross-site subresource requests before they get here (see header note).
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite === 'cross-site') {
        // Harmless no-op: bounce to home WITHOUT touching the session.
        nocache_headers();
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    // Anon hitting /logout directly is not an error — wp_logout() is a safe
    // no-op on a logged-out request; we just send them home cleanly.
    if (is_user_logged_in()) {
        wp_logout();
    }

    nocache_headers();
    wp_safe_redirect(home_url('/'), 302);
    exit;
}, -100);   // negative: run before lg-error-pages' priority-0 404 interceptor (see note above)
