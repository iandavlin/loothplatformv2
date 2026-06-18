<?php
/**
 * SiteFooter — plugin-resident site footer.
 *
 * Mirrors SiteHeader's approach: BuddyBoss's `buddyboss_footer` action gets
 * its callbacks stripped and replaced with our render. The outer
 * `<footer id="colophon">` (or whatever BB's template-parts/footer-* file
 * emits) is bypassed entirely — we control the entire footer surface.
 *
 * Nav-menu-driven link columns: registers three locations
 * (`lg-footer-1`, `lg-footer-2`, `lg-footer-3`) under Appearance → Menus.
 * Empty locations collapse silently.
 *
 * Killable per-site via wp-config:
 *     define('LG_LAYOUT_V2_SITE_FOOTER', false);
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class SiteFooter
{
    public const PARTIAL = LG_LAYOUT_V2_DIR . 'templates/partials/site-footer.php';

    public const MENU_LOCATIONS = [
        'lg-footer-1' => 'Footer column 1',
        'lg-footer-2' => 'Footer column 2',
        'lg-footer-3' => 'Footer column 3',
    ];

    /** Inline legal-links menu rendered in the lower legal strip. */
    public const LEGAL_LOCATION = 'lg-footer-legal';

    public static function boot(): void
    {
        add_action('after_setup_theme', [self::class, 'register_menus'], 20);
        add_action('wp',                [self::class, 'replace_bb_footer'], 999);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function register_menus(): void
    {
        register_nav_menus(self::MENU_LOCATIONS + [self::LEGAL_LOCATION => 'Footer legal links']);
    }

    public static function replace_bb_footer(): void
    {
        $hook = (defined('THEME_HOOK_PREFIX') ? THEME_HOOK_PREFIX : 'buddyboss_') . 'footer';
        remove_all_actions($hook);
        add_action($hook, [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!is_file(self::PARTIAL)) return;
        include self::PARTIAL;
    }

    public static function enqueue_assets(): void
    {
        $css_path = LG_LAYOUT_V2_DIR . 'assets/lg-site-footer.css';
        $css_ver  = is_file($css_path) ? (string) filemtime($css_path) : LG_LAYOUT_V2_VERSION;
        wp_enqueue_style('lg-site-footer', LG_LAYOUT_V2_URL . 'assets/lg-site-footer.css', [], $css_ver);
    }
}
