<?php
/**
 * Plugin Name: LG Membership Chrome
 * Description: Renders WP membership pages on the shared /srv/lg-shared/ header instead of BuddyBoss theme chrome.
 * Author: Looth Group
 *
 * Briefing: /home/ubuntu/projects/docs/briefing-membership-pages.md
 * Coord doc: STRANGLER-COORDINATION.md §0a (active_nav + logout_url required),
 *            §0b (launch invariant — see SESSION-HANDOFF for the open question
 *                 about template_include vs. standalone), §1 (tier vocab).
 *
 * PoC scope (2026-05-29): /membership-guide/ only. The other 10 auto-seeded
 * membership slugs (lgjoin, lggift-buy, lggift, manage-subscription, etc.)
 * are listed below as comments and will be added after coordinator + Ian
 * resolve briefing decision #1 (fate of [lg_member_nav]).
 *
 * Mechanism: hooks `template_include` at priority 99 (after the theme has
 * made its pick) to swap to a self-contained template that renders:
 *   <doctype> → wp_head() → shared header → the_content() → shared footer → wp_footer()
 *
 * Bypasses BuddyBoss theme chrome entirely. WP plugins/styles still load via
 * wp_head/wp_footer so welcome modal, REST endpoints, body_class filters
 * (lgms-mg-anon/lgms-mg-member) continue to work.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Page slugs that should render on the shared shell.
 *
 * PoC starts with just /membership-guide/. After briefing decision #1 lands,
 * extend this with the remaining auto-seeded membership slugs:
 *   lgjoin, lggift-buy, lggift, manage-subscription,
 *   regional-pricing-not-available, welcome, my-gifts,
 *   affiliate-earnings, test-checklist, request-refund
 */
const LG_MEMBERSHIP_CHROME_SLUGS = [
    'membership-guide',
];

add_filter( 'template_include', static function ( $template ) {
    global $post;

    if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'page' ) {
        return $template;
    }
    if ( ! in_array( $post->post_name, LG_MEMBERSHIP_CHROME_SLUGS, true ) ) {
        return $template;
    }

    $custom = __DIR__ . '/lg-membership-chrome/template.php';
    return file_exists( $custom ) ? $custom : $template;
}, 99 );

/**
 * Admin-only Stripe panel — minimal renderer for the standalone
 * /manage-subscription/ surface's iframe (see membership-pages/web/manage-
 * subscription.php). The standalone surface embeds an iframe pointing at
 * /__lg-stripe-panel/ when the viewer has manage_options; this hook serves
 * that URL with a stripped-down template that runs wp_head/wp_footer and
 * outputs [lg_manage_subscription] — no BB chrome, no theme, no membership
 * shell — so the shortcode's JS / REST / nonces all load and work in the
 * iframe without porting any of that to standalone.
 *
 * Server-side gate enforces manage_options independently — the iframe URL
 * is safe to leak (non-admins get 403). No corresponding WP page exists;
 * WP's query layer 404s but template_include swaps the template anyway,
 * and we set 200 in the template itself.
 *
 * Priority 98 (above the page-slug filter at 99) so this fires first; if
 * it doesn't match, the page-slug filter still gets to run.
 */
add_filter( 'template_include', static function ( $template ) {
    $req = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
    $req = rtrim( (string) $req, '/' );
    if ( $req !== '/__lg-stripe-panel' ) {
        return $template;
    }

    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        status_header( 403 );
        nocache_headers();
        echo '<!doctype html><meta charset="utf-8"><title>403</title><p>Admin only.</p>';
        exit;
    }

    $custom = __DIR__ . '/lg-membership-chrome/stripe-panel-template.php';
    return file_exists( $custom ) ? $custom : $template;
}, 98 );

/**
 * Build the viewer-context array for lg_shared_render_site_header().
 *
 * Derives identity + tier from in-process WP state (the briefing's
 * "Alternative" path — simpler than calling /whoami over loopback, and
 * we already have WP booted on this request). Role→tier mapping matches
 * STRANGLER-COORDINATION.md §1.
 */
function lg_membership_chrome_viewer(): array {
    $user = wp_get_current_user();
    $auth = ( $user instanceof WP_User ) && (int) $user->ID > 0;

    $tier = 'public';
    if ( $auth ) {
        // Walk highest → lowest so a user with multiple looth roles gets the
        // top one. Matches Arbiter + InternalRestController convention.
        foreach ( [ 'looth4' => 'pro', 'looth3' => 'pro', 'looth2' => 'lite', 'looth1' => 'public' ] as $role => $t ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                $tier = $t;
                break;
            }
        }
    }

    $caps = [
        'manage_options'   => $auth && user_can( $user->ID, 'manage_options' ),
        'edit_archive_poc' => $auth && user_can( $user->ID, 'edit_archive_poc' ),
    ];

    return [
        'authenticated' => $auth,
        'tier'          => $tier,
        'display_name'  => $auth ? (string) $user->display_name : '',
        'avatar_url'    => $auth ? (string) get_avatar_url( $user->ID, [ 'size' => 96 ] ) : null,
        'capabilities'  => $caps,
        // null = let the header lazy-load these via REST (§0a allows null).
        'msg_unread'    => null,
        'notif_unread'  => null,
        // §0a required. Empty string = no top-nav item highlighted (membership
        // pages aren't in the canonical top nav per §0d).
        'active_nav'    => '',
        // §0a required. wp_logout_url() emits a nonce'd URL that returns to
        // the current page after sign-out, or home on anon (idempotent).
        'logout_url'    => wp_logout_url( $auth ? get_permalink() : home_url( '/' ) ),
        // Contract (Ian 2026-06-03): account chip → public profile /u/<slug>
        // everywhere; /profile/edit only as the no-slug fallback.
        'profile_url'   => ( $auth && $user->user_nicename )
            ? '/u/' . rawurlencode( (string) $user->user_nicename )
            : '/profile/edit',
    ];
}

/**
 * GET /wp-json/looth/v1/rest-nonce  →  { nonce: <wp_rest nonce for the caller> }
 *
 * The nonce-via-loopback bridge for the standalone membership surfaces. A
 * standalone page (no WP boot) can't mint a wp_rest nonce, but the interactive
 * surfaces (refund, affiliate withdrawal, gift mgmt, subscription mgmt) POST to
 * the cookie+nonce-gated /wp-json/lg-member-sync/v1/* routes. Each surface
 * server-side loopback-fetches this endpoint (forwarding the browser's WP
 * cookies, same as lib/whoami.php) and embeds the returned nonce where the
 * shortcode did `echo $nonce` — so the existing JS + REST routes work verbatim.
 *
 * Cookie-auth without a nonce: WP REST cookie auth normally requires an
 * X-WP-Nonce to populate current_user, so we suppress the cookie-nonce error
 * for this one route (below) and resolve the user from the logged_in cookie via
 * wp_validate_auth_cookie() — the same primitive profile-whoami-shim uses.
 * wp_set_current_user() before wp_create_nonce() ties the nonce to that user
 * (matches RestController's post-login nonce mint).
 */
add_filter( 'rest_authentication_errors', static function ( $result ) {
    if ( is_wp_error( $result ) && $result->get_error_code() === 'rest_cookie_invalid_nonce' ) {
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        if ( strpos( $uri, '/looth/v1/rest-nonce' ) !== false ) {
            return null;
        }
    }
    return $result;
}, 100 );

add_action( 'rest_api_init', static function () {
    register_rest_route( 'looth/v1', '/rest-nonce', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => static function () {
            $uid = 0;
            foreach ( $_COOKIE as $cName => $cVal ) {
                if ( strpos( $cName, 'wordpress_logged_in_' ) === 0 ) {
                    $u = wp_validate_auth_cookie( (string) $cVal, 'logged_in' );
                    if ( $u ) { $uid = (int) $u; break; }
                }
            }
            if ( $uid <= 0 ) {
                return new WP_REST_Response( [ 'error' => 'not_logged_in' ], 401 );
            }
            wp_set_current_user( $uid );

            // Optional ?action=<name> mints a nonce for that action instead of
            // the default wp_rest. Whitelisted; the lgms_test_* QA-tool nonces
            // require manage_options (the admin-only /test-checklist/ surface
            // drives admin-ajax.php with these action nonces).
            $action = isset( $_GET['action'] ) ? (string) $_GET['action'] : '';
            if ( $action === '' ) { $action = 'wp_rest'; }
            $allowed = [ 'wp_rest', 'lgms_test_feedback', 'lgms_test_wipe' ];
            if ( ! in_array( $action, $allowed, true ) ) {
                return new WP_REST_Response( [ 'error' => 'action_not_allowed' ], 400 );
            }
            if ( strpos( $action, 'lgms_test_' ) === 0 && ! current_user_can( 'manage_options' ) ) {
                return new WP_REST_Response( [ 'error' => 'forbidden' ], 403 );
            }
            return new WP_REST_Response( [ 'nonce' => wp_create_nonce( $action ), 'action' => $action ] );
        },
    ] );
} );
