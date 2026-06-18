<?php
/**
 * Plugin Name: LG Viewer Tier Cookie
 * Description: Mints an `lg_tier` cookie (public|lite|pro) on every WP page load
 *              so archive-poc (running outside WP, in its own FPM pool) can
 *              tier-gate UI without a DB hit.
 *
 * Cookie value:
 *   public — anonymous OR logged-in user with no membership role (incl. looth1 placeholder)
 *   lite   — logged-in user with role `looth2`
 *   pro    — logged-in user with role `looth3` (paying) or `looth4` (poller bypass)
 *
 * Cookie scope: Domain=apex host, Path=/, NOT httpOnly (archive-poc PHP reads
 * it server-side, JS may also read to short-circuit member-only widgets).
 * Lifetime: 1 day. Re-issued each request so it follows role changes.
 *
 * Admins/editors get the role-based value too. If you want admins to always
 * see-everything regardless of their membership role, override below.
 */

if (!defined('ABSPATH')) exit;

/** Role → tier. Roles not listed → public. */
const LG_TIER_ROLE_MAP = [
    'looth2' => 'lite',
    'looth3' => 'pro',
    'looth4' => 'pro',   // poller bypass — same access as looth3
    // 'looth1' is the placeholder role → public (intentionally not mapped)
];

/** Resolve current viewer's tier. Highest tier wins if user has multiple roles. */
function lg_viewer_tier(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (!is_user_logged_in()) return $cached = 'public';

    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) return $cached = 'public';

    // Admins/editors see everything — saves us from needing to also assign
    // them a membership role just to QA gated content.
    if (user_can($user, 'manage_options')) return $cached = 'pro';

    $rank = ['public' => 0, 'lite' => 1, 'pro' => 2];
    $best = 'public';
    foreach ((array) $user->roles as $role) {
        $tier = LG_TIER_ROLE_MAP[$role] ?? null;
        if ($tier !== null && $rank[$tier] > $rank[$best]) $best = $tier;
    }
    return $cached = $best;
}

/** Mint the cookie on every page load (HTML responses only — skip REST/AJAX/cron). */
add_action('send_headers', function () {
    if (headers_sent()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('DOING_AJAX')  && DOING_AJAX)  return;
    if (defined('DOING_CRON')  && DOING_CRON)  return;

    $host = parse_url(home_url(), PHP_URL_HOST);
    setcookie('lg_tier', lg_viewer_tier(), [
        'expires'  => time() + DAY_IN_SECONDS,
        'path'     => '/',
        'domain'   => $host,
        'secure'   => is_ssl(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
});
