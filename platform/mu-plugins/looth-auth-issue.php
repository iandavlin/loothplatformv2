<?php
/**
 * Plugin Name: Looth Auth — non-REST issue endpoint
 * Description: Mints a `looth_id` for the logged-in WP user at a PLAIN (non-REST)
 *              URL and 302s back. The drop-in replacement for the bounce that
 *              profile-app fires when it sees a `wordpress_logged_in_*` cookie
 *              but no valid `looth_id`.
 * Version:     0.1.0
 *
 * WHY THIS EXISTS (cut-critical — proven by the profile-app lane 2026-06-15):
 *   The old bounce target, GET /wp-json/looth/auth/issue, is broken for a plain
 *   browser navigation TWO independent ways, and BOTH recur on every DB reload
 *   (the cut imports a DB):
 *     1. BuddyBoss's "restrict REST API to authenticated members"
 *        (wp_option `bb-enable-private-rest-apis`, re-armed by every reload)
 *        short-circuits the route with 401 `bb_rest_authorization_required`
 *        before our callback runs.
 *     2. Even with that gate off, WP REST cookie-auth requires a valid `wp_rest`
 *        nonce. A top-level navigation carries cookies but NO nonce, so inside
 *        the REST callback `is_user_logged_in()` is false → it 302s to wp-login.
 *   Net effect: a logged-in member whose looth_id is missing/expired/rotated
 *   gets NO profile editor (profile-app renders them as a guest).
 *
 * THE FIX: serve the mint+redirect from a NORMAL (non-REST) request instead.
 *   A normal page request has neither problem — the `wordpress_logged_in_`
 *   cookie authenticates without a nonce (wp_validate_logged_in_cookie runs on
 *   determine_current_user), and BuddyBoss's REST gate does not apply. No
 *   wp_option to keep toggled; survives a DB restore with zero manual steps.
 *
 * CONTRACT:  GET /looth-auth/issue?return=<same-origin-absolute-path>
 *   - logged in  → mint looth_id (reusing profile-auth.php's minter), 302 to return
 *   - logged out → 302 to wp-login with redirect_to=return
 *   `return` is validated to a same-origin absolute path (never an off-host or
 *   protocol-relative bounce). The redirect is emitted as a host-relative path
 *   so it stays on the requesting host even if siteurl drifts to live after a
 *   DB reload (reload-proof).
 *
 * The minter itself stays owned by profile-auth.php (one source of truth for the
 * `sub`=stored-_looth_uuid claim shape); this handler only routes to it.
 */

if (!defined('ABSPATH')) exit;

const LOOTH_AUTH_ISSUE_PATH = '/looth-auth/issue';

/**
 * Normalize the ?return target to a safe, same-origin, host-relative path.
 * Rejects empty, off-host (no leading '/'), and protocol-relative ('//host')
 * values, falling back to the editor.
 */
function looth_auth_issue_safe_return(): string {
    $return = isset($_GET['return']) ? (string) $_GET['return'] : '/profile/edit';
    // Must start with a single '/', and not be protocol-relative ('//evil.com').
    if ($return === '' || $return[0] !== '/' || (isset($return[1]) && $return[1] === '/')) {
        return '/profile/edit';
    }
    // Strip any CR/LF (header-injection belt) and cap length defensively.
    $return = str_replace(["\r", "\n"], '', $return);
    if (strlen($return) > 2048) return '/profile/edit';
    return $return;
}

// Intercept on `init`: early enough to pre-empt WP's 404 handling and
// redirect_canonical, late enough that the logged-in cookie is validated on
// first is_user_logged_in() call. Mirrors profile-auth.php's reverse-bridge,
// which authenticates off the same cookie at init.
add_action('init', function () {
    if (PHP_SAPI === 'cli') return;

    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $path = rtrim($path, '/');                 // tolerate a trailing slash
    if ($path !== LOOTH_AUTH_ISSUE_PATH) return;

    if (headers_sent()) return;                // can't redirect — fall through

    $return = looth_auth_issue_safe_return();

    if (!is_user_logged_in()) {
        // Defensive: profile-app only bounces here when a WP cookie is present,
        // but guard the direct-hit case so nobody lands on a blank 404.
        wp_safe_redirect('/wp-login.php?redirect_to=' . rawurlencode($return));
        exit;
    }

    // Reuse profile-auth.php's minter (sibling mu-plugin; its functions are
    // defined by the time this runtime hook fires). function_exists guards
    // against a load-order or rename regression so we 302 instead of fatalling.
    if (function_exists('looth_auth_mint_jwt') && function_exists('looth_auth_set_cookie')) {
        try {
            looth_auth_set_cookie(looth_auth_mint_jwt(wp_get_current_user()));
        } catch (Throwable $e) {
            // Absent _looth_uuid mirror / unreadable key / encode error: log and
            // still 302 back. The member renders as a guest (no editor) rather
            // than stranded on an error page; the next provision/backfill heals
            // the mint, and profile-app's one-shot guard stops a bounce loop.
            error_log('looth-auth-issue: mint failed: ' . $e->getMessage());
        }
    } else {
        error_log('looth-auth-issue: profile-auth.php minter functions unavailable');
    }

    wp_safe_redirect($return);   // host-relative → reload-proof
    exit;
}, 1);
