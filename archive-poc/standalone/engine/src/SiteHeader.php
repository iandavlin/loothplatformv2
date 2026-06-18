<?php
/**
 * SiteHeader — renders the ONE canonical shared site header on WP pages.
 *
 * Convergence (2026-06-03, docs/relay-header-convergence.md step 2): this used
 * to render lg-layout-v2's own `.lg-site-header` markup (templates/partials/
 * site-header.php + assets/lg-site-header.css|js). It now delegates to the
 * shared canonical header at /srv/lg-shared/site-header.php (rendered markup:
 * `.lg-chrome`), owned by the lg-shell lane. Consumers only populate $ctx —
 * no restyle / re-markup here. Same mechanism as
 * platform/mu-plugins/lg-membership-chrome.
 *
 * How it takes over (unchanged):
 *   - BuddyBoss parent header.php hooks its masthead chrome via
 *     `do_action(THEME_HOOK_PREFIX . 'header')`. We remove the BB callbacks
 *     and add our own, so BB's outer `<header id="masthead">` still wraps the
 *     page but its contents are the shared `.lg-chrome` markup.
 *   - We filter `buddyboss_site_header_class` so the masthead carries
 *     `site-header--lg` (not `site-header--bb`, which pulls in BB masthead CSS).
 *   - The v2 lite template (single-v2.php) bypasses get_header() and calls
 *     render() directly. Both paths share this one render — single source.
 *
 * Identity ($ctx) is derived from in-process WP state (wp_get_current_user()) —
 * the same source /whoami itself reads. Role→tier per STRANGLER-COORDINATION.md
 * §1. The $ctx contract is owned by lg-shell; this lane only populates it.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class SiteHeader
{
    /** Shared canonical header partial (lg-shell lane owns this file). */
    private const SHARED_HEADER  = '/srv/lg-shared/site-header.php';
    /** Host-relative CSS href (nginx maps /lg-shared/ → /srv/lg-shared/). */
    private const SHARED_CSS_URL = '/lg-shared/site-header.css';
    /** Filesystem path of the same CSS, for the filemtime cache-bust ver. */
    private const SHARED_CSS_FS  = '/srv/lg-shared/site-header.css';

    public static function boot(): void
    {
        /* Hook on `wp` so BB's own add_action() calls (which run during
           theme setup) have already registered by the time we tear them
           down. Priority 999 just to be sure we run last. */
        add_action('wp', [self::class, 'replace_bb_header'], 999);

        add_filter('buddyboss_site_header_class', [self::class, 'filter_header_class']);
        add_action('wp_enqueue_scripts',          [self::class, 'enqueue_assets']);
    }

    /**
     * Strip BB's masthead callbacks and replace with ours. Idempotent —
     * running twice is harmless because remove_all_actions clears whatever
     * was attached. THEME_HOOK_PREFIX is `buddyboss_` in BB; we fall back
     * to that literal if the constant isn't defined (e.g. swapped theme).
     */
    public static function replace_bb_header(): void
    {
        $hook = (defined('THEME_HOOK_PREFIX') ? THEME_HOOK_PREFIX : 'buddyboss_') . 'header';
        remove_all_actions($hook);
        add_action($hook, [self::class, 'render']);
    }

    public static function filter_header_class($cls): string
    {
        /* Replace BB's `site-header--bb` modifier so BB's CSS targeting that
           class doesn't paint over the shared chrome. Keep `site-header` so any
           generic theme rules that don't fight us still apply. */
        return 'site-header site-header--lg';
    }

    public static function render(): void
    {
        if (!is_file(self::SHARED_HEADER)) return;
        require_once self::SHARED_HEADER;
        if (!function_exists('lg_shared_render_site_header')) return;
        lg_shared_render_site_header(self::viewer());
    }

    /**
     * Build the $ctx for the shared header from in-process WP state.
     * Mirrors lg_membership_chrome_viewer() so every WP surface feeds the
     * shared header identical identity. Role→tier walks highest→lowest so a
     * user with multiple looth roles gets the top one (matches Arbiter +
     * InternalRestController convention).
     */
    private static function viewer(): array
    {
        $user = wp_get_current_user();
        $auth = ($user instanceof \WP_User) && (int) $user->ID > 0;

        $tier = 'public';
        if ($auth) {
            foreach (['looth4' => 'pro', 'looth3' => 'pro', 'looth2' => 'lite', 'looth1' => 'public'] as $role => $t) {
                if (in_array($role, (array) $user->roles, true)) { $tier = $t; break; }
            }
        }

        return [
            'authenticated' => $auth,
            'tier'          => $tier,
            'display_name'  => $auth ? (string) $user->display_name : '',
            'avatar_url'    => $auth ? (string) get_avatar_url($user->ID, ['size' => 96]) : null,
            'capabilities'  => [
                'manage_options'   => $auth && user_can($user->ID, 'manage_options'),
                'edit_archive_poc' => $auth && user_can($user->ID, 'edit_archive_poc'),
            ],
            // null = let the header lazy-load these via REST.
            'msg_unread'    => null,
            'notif_unread'  => null,
            // Article pages aren't a top-nav destination → no item highlighted.
            'active_nav'    => '',
            // wp_logout_url() emits a nonce'd URL returning to the current page.
            'logout_url'    => wp_logout_url($auth ? get_permalink() : home_url('/')),
            'profile_url'   => '/profile/edit',
        ];
    }

    /**
     * Enqueue the shared header CSS site-wide. The shared header's JS is
     * self-contained — inline <script> in the partial plus a /lg-shared/
     * social-modals.js tag it prints itself — so there is no script to enqueue.
     *
     * Handle is `lg-shared-site-header`; Isolate.php allowlists it (survives the
     * dequeue pass) and lists it in NO_LAYER_WRAP (kept unlayered so its chrome
     * rules aren't demoted under block CSS) on managed-CPT posts. Filemtime of
     * the shared file drives `?ver=` so a header CSS edit cache-busts browsers.
     */
    public static function enqueue_assets(): void
    {
        $ver = is_file(self::SHARED_CSS_FS) ? (string) filemtime(self::SHARED_CSS_FS) : LG_LAYOUT_V2_VERSION;
        wp_enqueue_style('lg-shared-site-header', self::SHARED_CSS_URL, [], $ver);
    }
}
