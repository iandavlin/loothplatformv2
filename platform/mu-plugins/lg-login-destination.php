<?php
/**
 * Plugin Name: LG Login Destination
 * Description: The WordPress adapter for the shared destination helper
 *   (/srv/lg-shared/lg-destination.php). Three jobs:
 *
 *     1. lg_dest_capture_wp() — the WP-free core PLUS real host validation
 *        (wp_sanitize_redirect + wp_validate_redirect against this site's own
 *        home_url, with an EMPTY fallback).
 *     2. The login_redirect exception — the narrow carve-out that stops
 *        BuddyBoss stomping every post-login destination to /activity/.
 *        ABSORBED from lg-login-redirect-honor.php (9ab8fcd), which this
 *        plugin REPLACES; that file is deleted in the same commit so there are
 *        never two filters racing on one chain.
 *     3. lg_dest_stash() / lg_dest_take() — a ONE-SHOT store that carries a
 *        destination across the new-member password detour (ruling 2).
 *
 * @see docs/atlas/LOGIN-DESTINATION.md   the contract, and which door calls what
 */

if (!defined('ABSPATH')) exit;

/**
 * The core is plain PHP and MUST stay WP-free — it is required by profile-app,
 * membership-pages, bb-mirror and archive-poc with no WordPress loaded. Guarded
 * so a missing /srv symlink degrades to "no destinations carried" rather than
 * fataling wp-login.php, which would lock everyone out of the site.
 */
if (is_readable('/srv/lg-shared/lg-destination.php')) {
    require_once '/srv/lg-shared/lg-destination.php';
}

/** User meta key for the one-shot destination stash. */
if (!defined('LG_DEST_STASH_KEY')) {
    define('LG_DEST_STASH_KEY', '_lg_login_dest');
}

/**
 * Reduce a candidate destination to a bindable path, or '' — the WP-aware
 * version of lg_dest_capture().
 *
 * The core already rejects pseudo-schemes, scheme-relative and backslash
 * values, control characters, over-long values and the auth paths. This adds
 * what only WordPress can answer: is that host actually OUR host? An empty
 * fallback on wp_validate_redirect is the whole posture — a value we can't
 * vouch for yields '', and the caller keeps its own default (ruling 1).
 *
 * @param mixed $raw
 */
function lg_dest_capture_wp($raw): string {
    if (!function_exists('lg_dest_capture')) {
        return '';
    }

    // Core first: it is the stricter of the two and is the only one that knows
    // about the auth-path loop. Anything it rejects never reaches WP.
    $captured = lg_dest_capture($raw);
    if ($captured === '') {
        return '';
    }

    $validated = wp_validate_redirect(wp_sanitize_redirect($captured), '');
    if ($validated === '') {
        return '';
    }

    // wp_validate_redirect may hand back an absolute same-host URL; run it
    // through the core once more so what we return is always a bare path and
    // always satisfies the same invariants.
    return lg_dest_capture($validated);
}

/**
 * The destination the CURRENT request is asking for, if any — a validated
 * redirect_to on the query string or POST body. '' when none was requested,
 * which is what keeps a bare login untouched (ruling 5).
 */
function lg_dest_requested(): string {
    if (!isset($_REQUEST['redirect_to'])) {
        return '';
    }
    return lg_dest_capture_wp((string) wp_unslash($_REQUEST['redirect_to']));
}

/**
 * Park a destination for a user across a detour that cannot carry a query
 * string of its own — today that is the new-member /patreon-password/ page
 * (ruling 2: a brand-new member goes to the password page first, THEN to what
 * they originally asked for).
 *
 * @param int    $uid
 * @param string $dest Raw candidate; validated here, and a value that fails
 *                     validation CLEARS any previous stash rather than leaving
 *                     a stale one to fire later.
 */
function lg_dest_stash(int $uid, string $dest): void {
    if ($uid <= 0) {
        return;
    }
    $d = lg_dest_capture_wp($dest);
    if ($d === '') {
        delete_user_meta($uid, LG_DEST_STASH_KEY);
        return;
    }
    update_user_meta($uid, LG_DEST_STASH_KEY, $d);
}

/**
 * Read AND clear the stash — ONE SHOT.
 *
 * The one-shot is the point. A member who abandons the password page and comes
 * back through a different door a week later must not be silently teleported to
 * a destination they asked for in a session they've forgotten. Re-validated on
 * the way out too: the stash is user meta, and user meta is editable by anyone
 * with the right capability, so it is not a trusted store.
 */
function lg_dest_take(int $uid): string {
    if ($uid <= 0) {
        return '';
    }
    $raw = (string) get_user_meta($uid, LG_DEST_STASH_KEY, true);
    if ($raw === '') {
        return '';
    }
    delete_user_meta($uid, LG_DEST_STASH_KEY);
    return lg_dest_capture_wp($raw);
}

/**
 * ============================================================
 * The login_redirect exception (absorbed from lg-login-redirect-honor.php)
 * ============================================================
 *
 * BuddyBoss ("Login Redirection" setting, bb-custom-login-redirection =
 * /activity/) stomps EVERY post-login destination via bb_login_redirect() at
 * PHP_INT_MAX on the login_redirect chain — it never consults the requested
 * redirect_to, so every login-with-destination lands on /activity/. This filter
 * runs AFTER BuddyBoss and restores the caller's redirect_to ONLY when one was
 * actually requested AND it validates.
 *
 * NOTE (verified on dev2 2026-07-27): the BuddyBoss Pro SSO leg
 * (class-bb-sso-provider.php::redirect_to_last_location) calls
 * bb_login_redirect() DIRECTLY, not through the login_redirect filter, so this
 * exception cannot reach it. There is no bb_sso_settings option on dev2, i.e.
 * that leg is dormant here. See the atlas note before assuming the same on live.
 */

// Register on init (priority 0) so this lands AFTER BuddyBoss's own PHP_INT_MAX
// registration at plugins_loaded — same priority, later registration = runs
// last, i.e. after the stomp. login_redirect only fires during wp-login.php POST
// handling, well after init.
//
// DO NOT "tidy" this into a top-level add_filter: losing this ordering silently
// restores the /activity/ stomp with every test still green.
add_action('init', function () {
    add_filter('login_redirect', 'lg_dest_login_redirect', PHP_INT_MAX, 3);
}, 0);

/**
 * @param string           $redirect_to           Destination after the full filter chain
 *                                                (BuddyBoss has already forced /activity/ here).
 * @param string           $requested_redirect_to The redirect_to the login request actually carried.
 * @param WP_User|WP_Error $user                  Logged-in user, or error on failed login.
 */
function lg_dest_login_redirect($redirect_to, $requested_redirect_to, $user) {
    // Failed login, or nothing was requested → leave the chain's answer alone
    // (bare login keeps the /activity/ default).
    if (!($user instanceof WP_User) || !is_string($requested_redirect_to) || $requested_redirect_to === '') {
        return $redirect_to;
    }

    // BuddyBoss never touches administrators; neither do we.
    if (in_array('administrator', (array) $user->roles, true)) {
        return $redirect_to;
    }

    $dest = lg_dest_capture_wp($requested_redirect_to);
    if ($dest === '') {
        return $redirect_to;
    }

    return $dest;
}
