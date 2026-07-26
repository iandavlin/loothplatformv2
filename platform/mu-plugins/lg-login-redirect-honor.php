<?php
/**
 * Plugin Name: LG Login Redirect — honor redirect_to
 * Description: Narrow exception to the BuddyBoss forced login destination.
 *   BuddyBoss ("Login Redirection" setting, bb-custom-login-redirection =
 *   /activity/) stomps EVERY post-login destination via bb_login_redirect()
 *   at PHP_INT_MAX on the login_redirect chain — it never consults the
 *   requested redirect_to, so every login-with-destination (including the
 *   /profile/edit sign-in interstitial, GH #51) lands on /activity/.
 *   This filter runs AFTER BuddyBoss and restores the caller's redirect_to
 *   ONLY when one was actually requested AND it validates same-host
 *   (wp_validate_redirect with an empty fallback — off-host / scheme-relative
 *   hostile values are rejected and the BuddyBoss default keeps winning).
 *   Bare logins (no redirect_to) are untouched: /activity/ stays the default.
 *   Administrators are untouched: BuddyBoss already excludes them.
 *
 * NOTE: the Patreon SSO leg (buddyboss-platform-pro class-bb-sso-provider.php
 *   redirect_to_last_location) calls bb_login_redirect() DIRECTLY, not through
 *   the login_redirect filter, so this exception cannot reach it. Flagged on
 *   the board 2026-07-26; needs its own ruling if SSO-with-destination matters.
 */

if (!defined('ABSPATH')) exit;

// Register on init (priority 0) so this lands AFTER BuddyBoss's own
// PHP_INT_MAX registration at plugins_loaded — same priority, later
// registration = runs last, i.e. after the stomp. login_redirect only
// fires during wp-login.php POST handling, well after init.
add_action('init', function () {
    add_filter('login_redirect', 'lg_login_redirect_honor_requested', PHP_INT_MAX, 3);
}, 0);

/**
 * @param string             $redirect_to           Destination after the full filter chain
 *                                                  (BuddyBoss has already forced /activity/ here).
 * @param string             $requested_redirect_to The redirect_to the login request actually carried.
 * @param WP_User|WP_Error   $user                  Logged-in user, or error on failed login.
 * @return string
 */
function lg_login_redirect_honor_requested($redirect_to, $requested_redirect_to, $user) {
    // Failed login, or nothing was requested → leave the chain's answer alone
    // (bare login keeps the /activity/ default).
    if (!($user instanceof WP_User) || !is_string($requested_redirect_to) || $requested_redirect_to === '') {
        return $redirect_to;
    }

    // BuddyBoss never touches administrators; neither do we.
    if (in_array('administrator', (array) $user->roles, true)) {
        return $redirect_to;
    }

    // Same-host validation with NO fallback: anything off-host (https://evil.example,
    // scheme-relative //evil.example, garbage) comes back '' and the forced
    // default stands. An open redirect is not acceptable here.
    $validated = wp_validate_redirect(wp_sanitize_redirect($requested_redirect_to), '');
    if ($validated === '') {
        return $redirect_to;
    }

    return $validated;
}
