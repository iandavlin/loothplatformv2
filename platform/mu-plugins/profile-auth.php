<?php
/**
 * Plugin Name: Looth Auth (JWT minter)
 * Description: Mints an RS256 JWT on WP login + drops it as the `looth_id`
 *              cookie scoped to .loothgroup.com. All other Looth services
 *              (profile-app, archive-poc, future native app) verify the same
 *              JWT against the matching public key — there is no other auth.
 * Version:     0.1.0
 *
 * Wiring:
 *   - hook wp_login          → mint + set cookie
 *   - hook init              → if user is logged in and cookie missing, mint
 *   - REST  /wp-json/looth/auth/refresh → re-mint from current WP session
 *
 * Keys:
 *   /etc/looth/jwt-private.pem  (640 root:looth-dev)
 *   /etc/looth/jwt-public.pem   (644 root:root — readable everywhere)
 *
 * `sub` claim is the STORED profile-app users.uuid, read from WP usermeta
 * `_looth_uuid` (mirrored at provision by the poller lane). It is NOT recomputed
 * from the email, so an email change can't drift the token subject (the G4
 * silent-logout bug). Missing mirror => skip minting, never email-derive.
 * Decision 2 option (b); collapses to profile-app-sole-signer (a) post-cut.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/looth-vendor/autoload.php';

// Derive the public host from the shared env so the cookie domain + issuer
// auto-correct per box (dev1→.dev.loothgroup.com, dev2→.dev2.loothgroup.com,
// prod→.loothgroup.com). Absent-safe: on a box WITHOUT /etc/looth/env the
// fallback is the current dev literal, so behavior is byte-identical to today.
if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
$lg_shared = function_exists('lg_env') ? lg_env() : [];
$lg_host   = $lg_shared['host'] ?? 'dev.loothgroup.com';   // fallback = dev literal → absent-env behaves EXACTLY as before

const LOOTH_AUTH_NAMESPACE      = 'eaef23f7-9bc9-4a95-ac49-ffff632e6646';
const LOOTH_AUTH_COOKIE         = 'looth_id';
define('LOOTH_AUTH_COOKIE_DOMAIN', '.' . $lg_host);          // env-derived; const can't take a runtime expr
const LOOTH_AUTH_TTL_SECONDS    = 30 * 24 * 60 * 60;          // 30 days
const LOOTH_AUTH_PRIVATE_KEY    = '/etc/looth/jwt-private.pem';
const LOOTH_AUTH_PUBLIC_KEY     = '/etc/looth/jwt-public.pem';   // 644 root:root — readable
define('LOOTH_AUTH_ISS', 'https://' . $lg_host);             // env-derived; const can't take a runtime expr

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;

function looth_auth_compute_uuid(string $email): string {
    $ns = Uuid::fromString(LOOTH_AUTH_NAMESPACE);
    return Uuid::uuid5($ns, strtolower(trim($email)))->toString();
}

/**
 * Resolve the member's public `slug` for the looth_id `slug` claim (§0c shape;
 * mirrors profile-app's Mint::mintForWpUserId, the canonical signer).
 *
 * WHY THIS EXISTS: the `slug` claim feeds the shared header's "My Profile" link
 * (/srv/lg-shared/site-header.php — /u/<slug>, else /profile/edit). The slug is
 * minted in profile-app's Postgres at provision, which the WP minter cannot read
 * (looth-dev has SELECT-only on the WP MySQL DB, no Postgres access at all). So we:
 *   1. Prefer the `_looth_slug` usermeta mirror — a local, zero-latency cache.
 *   2. On a miss, resolve it ONCE from profile-app's gate-EXEMPT internal slug
 *      endpoint (GET /profile-api/v0/internal/slug?wp_user_id=<int>; loopback-only,
 *      X-LG-Internal-Auth shared secret, reads users.slug fresh from Postgres),
 *      then stamp the result into `_looth_slug` so subsequent mints are local-only.
 *
 * NOTE the endpoint is under /profile-api/v0/internal/ deliberately: the public
 * /whoami sits behind the dev COOKIE GATE, and a server-to-server mint call
 * carries no gate cookie (it 403s). The internal/ block is cookie-gate-exempt
 * and locked to localhost at the nginx layer + the shared secret in PHP.
 *
 * Best-effort: returns '' (claim omitted) on ANY failure — a missing slug claim
 * only degrades the menu link to /profile/edit, it must never block a mint.
 * The slug write happens AFTER provision, so a first login can legitimately miss
 * here; the init re-mint (below) heals the token on the next pageview once the
 * slug exists, with no "visit your profile once" detour.
 */
function looth_auth_slug_for_user(WP_User $user): string {
    $wpId = (int) $user->ID;
    if ($wpId < 1) return '';

    // 1) Local mirror — fast path, no network. Filled by a prior resolve below.
    $cached = get_user_meta($wpId, '_looth_slug', true);
    if (is_string($cached) && $cached !== '') return $cached;

    // 2) Resolve from profile-app's PG-backed, gate-exempt internal slug endpoint.
    //    Short timeouts: this is a login hot-path and the slug is non-critical,
    //    so we never let it stall the mint.
    $secret = @file_get_contents('/etc/lg-internal-secret');
    if (!is_string($secret) || $secret === '') return '';
    $secret = trim($secret);

    $host = $_SERVER['HTTP_HOST'] ?? $GLOBALS['lg_host'] ?? 'dev.loothgroup.com';
    $ch = curl_init('https://127.0.0.1/profile-api/v0/internal/slug?wp_user_id=' . $wpId);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_SSL_VERIFYPEER => false,   // loopback to local nginx self-signed cert
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . $host,
            'X-LG-Internal-Auth: ' . $secret,
            'Accept: application/json',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || !is_string($body)) return '';

    $d = json_decode($body, true);
    $slug = (is_array($d) && !empty($d['slug']) && is_string($d['slug'])) ? $d['slug'] : '';
    if ($slug === '') return '';

    // Cache for next time so the common path is the local mirror, not a hop.
    update_user_meta($wpId, '_looth_slug', $slug);
    return $slug;
}

function looth_auth_mint_jwt(WP_User $user): string {
    static $pk = null;
    if ($pk === null) $pk = @file_get_contents(LOOTH_AUTH_PRIVATE_KEY);
    if (!$pk) throw new RuntimeException('looth-auth: private key unreadable');

    // `sub` = the STORED profile-app users.uuid, mirrored into WP usermeta as
    // `_looth_uuid` at provision (poller lane). Seeded from the email ONCE at
    // create and never recomputed, so a WP email change can't drift the token
    // subject (G4). Absent mirror => refuse to mint (caller skips the cookie);
    // the init/issue re-mint heals on the next pageview once the mirror lands.
    // NEVER fall back to looth_auth_compute_uuid($email) — that is the bug.
    $sub = get_user_meta($user->ID, '_looth_uuid', true);
    if (!is_string($sub) || $sub === '') {
        throw new RuntimeException('looth-auth: no _looth_uuid mirror for user #'
            . (int) $user->ID . ' — skipping mint (provision/backfill pending)');
    }

    $now = time();
    $payload = [
        'iss'        => LOOTH_AUTH_ISS,
        'sub'        => strtolower($sub),
        'wp_user_id' => (int) $user->ID,
        'email'      => $user->user_email,
        'iat'        => $now,
        'exp'        => $now + LOOTH_AUTH_TTL_SECONDS,
    ];

    // `slug` claim (§0c shape; matches profile-app Mint). Populated whenever a
    // slug exists so a fresh connection's FIRST page load already resolves
    // /u/<slug> in the shared header — no "visit your profile once" detour.
    // Omitted (never null/empty) when unresolved, so the header's slug-less
    // /profile/edit fallback still applies and the claim shape stays clean.
    $slug = looth_auth_slug_for_user($user);
    if ($slug !== '') $payload['slug'] = $slug;

    return JWT::encode($payload, $pk, 'RS256');
}

// Verify a looth_id JWT against the public key. Returns the claims array on a
// good token (signature + exp + iss all valid), or null on anything suspect.
// Used by the reverse session bridge below — we mint a real WP session off this
// token, so it must be cryptographically trusted, never just parsed.
function looth_auth_verify_jwt(string $jwt): ?array {
    static $pub = null;
    if ($pub === null) $pub = @file_get_contents(LOOTH_AUTH_PUBLIC_KEY) ?: false;
    if (!$pub) return null;
    try {
        $claims = (array) JWT::decode($jwt, new Key($pub, 'RS256'));   // throws on bad sig/expiry
    } catch (Throwable $e) {
        return null;
    }
    if (($claims['iss'] ?? '') !== LOOTH_AUTH_ISS) return null;
    return $claims;
}

function looth_auth_set_cookie(string $jwt): void {
    setcookie(LOOTH_AUTH_COOKIE, $jwt, [
        'expires'  => time() + LOOTH_AUTH_TTL_SECONDS,
        'path'     => '/',
        'domain'   => LOOTH_AUTH_COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function looth_auth_clear_cookie(): void {
    setcookie(LOOTH_AUTH_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => LOOTH_AUTH_COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// On successful login, mint + set.
add_action('wp_login', function ($user_login, WP_User $user) {
    try { looth_auth_set_cookie(looth_auth_mint_jwt($user)); }
    catch (Throwable $e) { error_log('looth-auth mint failed: ' . $e->getMessage()); }
}, 10, 2);

// On logout, clear.
add_action('wp_logout', function () { looth_auth_clear_cookie(); });

// Belt to wp_logout's suspenders: clear_auth_cookie fires on programmatic
// cookie clears + session destruction (password change, "log out everywhere")
// that wp_logout alone misses. Without this, those paths leave a valid looth_id
// behind and the user stays "logged in" to strangler surfaces after WP logout.
// Double-clear on a plain logout is harmless (setcookie with past expiry twice).
add_action('clear_auth_cookie', function () { looth_auth_clear_cookie(); });

// On any normal pageview where user is logged in but the cookie is MISSING, mint.
// Also self-heal a stale SLUG-LESS cookie: a token minted before the member's
// slug existed carries no `slug` claim, so the shared header degrades the
// "My Profile" link to /profile/edit until something re-mints. Once the local
// `_looth_slug` mirror is populated (by a prior mint's whoami resolve), re-mint
// so the claim lands on the NEXT pageview — no "visit your profile once" detour.
// The heal is gated on the LOCAL mirror only (no whoami hop on the hot path) and
// is one-shot per request, so a member whose slug genuinely doesn't exist yet
// can't loop. NULL claims (forged/expired) fall through to the missing-cookie mint.
add_action('init', function () {
    if (is_admin() && wp_doing_ajax()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (!is_user_logged_in()) return;
    if (headers_sent()) return;

    $cookie = $_COOKIE[LOOTH_AUTH_COOKIE] ?? '';
    if ($cookie !== '') {
        // Present cookie: only re-mint to backfill a missing slug claim.
        $claims = looth_auth_verify_jwt((string) $cookie);
        if ($claims === null) return;                       // invalid → handled by reverse-bridge/issue
        if (!empty($claims['slug'])) return;                // already carries slug — nothing to heal
        $user = wp_get_current_user();
        $mirror = get_user_meta($user->ID, '_looth_slug', true);
        if (!is_string($mirror) || $mirror === '') {
            // No local slug yet (fresh connection — provision writes the slug to
            // Postgres slightly AFTER first login). Try ONE whoami resolve to
            // populate the mirror, throttled to ~1/min/user so we never hop on
            // every pageview while a member genuinely has no slug. Once it lands,
            // looth_auth_slug_for_user stamps `_looth_slug` and we re-mint.
            $throttle = 'looth_slug_probe_' . (int) $user->ID;
            if (get_transient($throttle)) return;
            set_transient($throttle, 1, 60);
            $mirror = looth_auth_slug_for_user($user);      // whoami hop + stamps mirror on success
            if ($mirror === '') return;                     // still no slug — leave token as-is
        }
        try { looth_auth_set_cookie(looth_auth_mint_jwt($user)); }
        catch (Throwable $e) { error_log('looth-auth slug-heal mint failed: ' . $e->getMessage()); }
        return;
    }

    // No cookie at all → mint a fresh one (resolves slug inline).
    try { looth_auth_set_cookie(looth_auth_mint_jwt(wp_get_current_user())); }
    catch (Throwable $e) { error_log('looth-auth init-mint failed: ' . $e->getMessage()); }
});

// Reverse direction: a valid looth_id JWT but NO live WP session → establish one.
// The forward hook above keeps the JWT in sync when WP says logged-in; this keeps
// the WP session in sync when the JWT says logged-in. Without it the two disagree:
//   - Patreon-onboarded members created before onboard minted a WP cookie carry a
//     looth_id (whoami=logged-in) but have no WP session, so wp_validate_auth_cookie
//     returns anon and the membership nonce bridge / /me/* surface 401s.
//   - On this DB-reload-heavy box a reload wipes server-side session tokens, so the
//     WP cookie stops validating while the stateless JWT survives — same split.
// Contract this restores: whoami=logged-in ⇒ a valid WP auth cookie exists.
// Runs early (priority 1) so the rest of the request sees the authenticated user.
add_action('init', function () {
    if (is_admin() && wp_doing_ajax()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;   // healed via the next pageview, not mid-REST
    if (is_user_logged_in()) return;                        // WP session already valid — nothing to heal
    if (empty($_COOKIE[LOOTH_AUTH_COOKIE])) return;         // no identity to bridge from
    if (headers_sent()) return;

    $claims = looth_auth_verify_jwt((string) $_COOKIE[LOOTH_AUTH_COOKIE]);
    if (!$claims) return;                                   // forged / expired / wrong issuer → ignore

    // Anchor on email, not wp_user_id: IDs are recyclable after a DB reload, so a
    // stale id claim can point at a different human. The JWT sub (uuid5 of email)
    // makes email the identity of record; the id claim is advisory only.
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    if ($email === '') return;
    $user = get_user_by('email', $email);
    if (!$user) return;

    wp_set_current_user($user->ID, $user->user_login);
    wp_set_auth_cookie($user->ID, true);
    error_log('looth-auth: bridged WP session from looth_id for ' . $email . ' (#' . $user->ID . ')');
}, 1);

// Admin bar: "My Profile" link visible to all logged-in users.
// (Visible whether or not they've claimed; the editor handles the interstitial.)
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    if (!is_user_logged_in()) return;
    $bar->add_node([
        'id'    => 'looth-my-profile',
        'title' => 'My Profile',
        'href'  => LOOTH_AUTH_ISS . '/profile/edit',
        'meta'  => ['title' => 'Edit your Looth profile'],
    ]);
}, 80);

// BuddyBoss member nav: front-end "My Profile 2.0" entry. The WP admin bar
// is hidden on front-end for members, so the slice-1.5 admin_bar item only
// showed in wp-admin. BB's nav renders on every front-end page.
add_action('bp_setup_nav', function () {
    if (!is_user_logged_in() || !function_exists('bp_core_new_nav_item')) return;
    bp_core_new_nav_item([
        'name'                    => 'My Profile 2.0',
        'slug'                    => 'profile-2',
        'position'                => 5,
        'default_subnav_slug'     => 'profile-2',
        'show_for_displayed_user' => false,    // only on user's own profile
        'item_css_id'             => 'looth-profile-2',
        'screen_function'         => function () {
            wp_safe_redirect('/profile/edit');
            exit;
        },
    ]);
});

// REST: /wp-json/looth/auth/refresh — re-mint from current WP session.
// REST: /wp-json/looth/auth/issue?return=<path>  — mint + 302 back to the
//   given path. Used by profile-app when it sees a wordpress_logged_in cookie
//   but no looth_id cookie; the round-trip is invisible to the user.
add_action('rest_api_init', function () {
    register_rest_route('looth/auth', '/refresh', [
        'methods'  => 'POST',
        'permission_callback' => function () { return is_user_logged_in(); },
        'callback' => function () {
            try {
                $jwt = looth_auth_mint_jwt(wp_get_current_user());
                looth_auth_set_cookie($jwt);
                return ['ok' => true, 'exp' => time() + LOOTH_AUTH_TTL_SECONDS];
            } catch (Throwable $e) {
                return new WP_Error('mint_failed', $e->getMessage(), ['status' => 500]);
            }
        },
    ]);

    register_rest_route('looth/auth', '/issue', [
        'methods'  => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) {
            $return = $req->get_param('return') ?: '/profile/edit';
            // Only allow same-origin returns — never bounce to off-host URLs.
            if (!is_string($return) || $return === '' || $return[0] !== '/') {
                $return = '/profile/edit';
            }
            $back = 'https://' . ($_SERVER['HTTP_HOST'] ?? $GLOBALS['lg_host'] ?? 'dev.loothgroup.com') . $return;
            if (!is_user_logged_in()) {
                $login = wp_login_url($back);
                wp_redirect($login); exit;
            }
            try {
                $jwt = looth_auth_mint_jwt(wp_get_current_user());
                looth_auth_set_cookie($jwt);
            } catch (Throwable $e) {
                error_log('looth-auth issue mint failed: ' . $e->getMessage());
            }
            wp_redirect($back); exit;
        },
    ]);
});
