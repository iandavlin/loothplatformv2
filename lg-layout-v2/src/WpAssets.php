<?php
/**
 * WpAssets — enqueue the v2 stylesheet on front-end + admin preview.
 *
 * The bundle.css file is written to assets/lg-layout-v2-bundle.css by
 * regenerate_bundle(), which is invoked:
 *   - On plugin activation (Plugin::activate)
 *   - When the block-styles option is saved (Plugin::on_option_changed)
 *   - When the brand-palette option is saved
 *   - On-demand from the WP-CLI rerender command (Phase 5)
 *
 * Versioning: filemtime of the bundle is used as `?ver=` for cache-busting.
 * No JS yet — Phase 4 adds the editor framework.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class WpAssets
{
    public static function enqueue_front(): void
    {
        if (!is_singular(Plugin::MANAGED_CPTS)) return;
        global $post;
        if (!Plugin::manages($post)) return;
        self::enqueue_bundle();

        /* Front-end behaviors: lightbox + future interactive helpers.
           Vanilla JS, no deps, deferred so it never blocks paint. */
        $frontJsPath = LG_LAYOUT_V2_DIR . 'assets/lg-front.js';
        $frontJsVer  = is_file($frontJsPath) ? (string) filemtime($frontJsPath) : LG_LAYOUT_V2_VERSION;
        wp_enqueue_script(
            'lg-layout-v2-front',
            LG_LAYOUT_V2_URL . 'assets/lg-front.js',
            [],
            $frontJsVer,
            true
        );

        /* Admin-bar style + script are registered by WP core but never auto-
           enqueued unless the active theme hooks 'wp_admin_bar_header'. Our
           lite template bypasses the theme entirely. We can't gate on
           is_admin_bar_showing() here — that function returns false at
           wp_enqueue_scripts time (the user-auth-cookie path hasn't fully
           settled the global yet), even when the bar will subsequently
           render. So enqueue unconditionally on managed posts; if the bar
           ends up not visible, the asset is harmless. */
        wp_enqueue_style('admin-bar');
        wp_enqueue_script('admin-bar');

        /* The shared canonical header CSS (handle `lg-shared-site-header`,
           /lg-shared/site-header.css) is enqueued site-wide by
           SiteHeader::enqueue_assets(). Isolate.php allowlists that handle so it
           survives the dequeue pass on managed CPTs. */
    }

    public static function enqueue_admin(string $hook): void
    {
        /* Phase 3 dash screen needs wpColorPicker + brand-swatch payload. */
        if (Dash::is_dash_screen($hook)) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            /* Feed brand colors as the picker's swatch palette so authors land
               on the house palette without typing hex codes. */
            $palette = [];
            foreach (Theme::tokens() as $_name => $meta) {
                if (($meta['category'] ?? '') === 'color') {
                    $default = (string) $meta['default'];
                    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $default)) $palette[] = $default;
                }
            }
            wp_add_inline_script(
                'wp-color-picker',
                'window.lgLayoutV2Dash = { palette: ' . wp_json_encode(array_values(array_unique($palette))) . ' };',
                'before'
            );
        }
        /* Phase 3 metabox on the post-edit screen of any managed CPT. */
        if (in_array($hook, ['post.php', 'post-new.php'], true)) {
            global $post_type;
            if (in_array($post_type, Plugin::MANAGED_CPTS, true)) {
                wp_enqueue_media();   /* exposes window.wp.media for the image picker */
                wp_enqueue_script(
                    'lg-layout-v2-metabox',
                    LG_LAYOUT_V2_URL . 'assets/admin-metabox.js',
                    ['jquery'],
                    LG_LAYOUT_V2_VERSION,
                    true
                );
            }
        }
        /* Phase 4 editor adds its own enqueues here. */
    }

    public static function enqueue_bundle(): void
    {
        $bundlePath = LG_LAYOUT_V2_DIR . LG_LAYOUT_V2_BUNDLE_CSS;
        $bundleUrl  = LG_LAYOUT_V2_URL . LG_LAYOUT_V2_BUNDLE_CSS;

        /* Lazy-regenerate if missing (e.g. fresh install without activation hook ran) */
        if (!is_file($bundlePath)) self::regenerate_bundle();

        /* Happy path: the generated bundle is on disk and readable. Enqueue it
           as an external, cacheable <link> — filemtime is the cache-buster so
           it caches hard across requests. This is the preferred path; the
           inline fallback below only fires when the file can't be served. */
        if (is_file($bundlePath) && is_readable($bundlePath)) {
            $ver = (string) filemtime($bundlePath);
            wp_enqueue_style('lg-layout-v2', $bundleUrl, [], $ver);
            return;
        }

        /* Fallback: the bundle file is missing or unreadable — e.g. the assets
           dir isn't writable by the PHP user, so regenerate_bundle() can't
           create it (a real, host-dependent condition we log in debug.log).
           Rather than enqueue a <link> to a 404 — which strips every block's
           SVG-sizing CSS and balloons the viewBox-only icon SVGs to viewport
           width (the "blue blob" in the ?lg_edit=1 / WP-template path) — emit
           the freshly-built CSS inline.

           wp_register_style(src=false) + wp_add_inline_style prints a plain
           <style> with no network dependency, so the sizing rules are always
           present regardless of filesystem perms or host. Isolate.php
           allowlists the 'lg-layout-v2' handle and explicitly skips layer-
           wrapping it, so the inline payload survives the isolation pass
           untouched. (Verified on WP 6.9.4: a false-src handle still emits its
           inline 'after' data.) */
        wp_register_style('lg-layout-v2', false, [], LG_LAYOUT_V2_VERSION);
        wp_enqueue_style('lg-layout-v2');
        wp_add_inline_style('lg-layout-v2', self::build_css());
    }

    /**
     * Build the full @layer bundle CSS string from current manifests + brand
     * palette + dash overrides. Single source of truth shared by
     * regenerate_bundle() (which writes it to disk) and enqueue_bundle()'s
     * inline fallback (which prints it directly) so the two can never drift.
     *
     * $brandOverride / $styleOption mirror regenerate_bundle(): pass the
     * freshly-sanitized arrays from the dash save handler to sidestep stale
     * object-cache reads; pass null to read the saved options.
     */
    public static function build_css(?array $brandOverride = null, ?array $styleOption = null): string
    {
        $manifests = Manifest::all();
        if ($brandOverride === null) {
            $brandOverride = get_option(LG_LAYOUT_V2_BRAND_OPTION, []);
        }
        $brandTokens = Theme::resolve(is_array($brandOverride) ? $brandOverride : []);
        if ($styleOption === null) {
            $styleOption = get_option(LG_LAYOUT_V2_STYLE_OPTION, []);
        }
        $dashOverrides = is_array($styleOption) ? $styleOption : [];

        return CssBuilder::build($manifests, $brandTokens, $dashOverrides);
    }

    /**
     * Build the full @layer bundle from current manifests + dash overrides +
     * brand-palette overrides, write it to disk.
     *
     * Returns true on success, false if the bundle file could not be written.
     * Write failures are also logged via error_log so they appear in
     * wp-content/debug.log when WP_DEBUG_LOG is on — the v1 symptom of this
     * bug was a stale CSS file with no clue why, which we want to avoid.
     */
    public static function regenerate_bundle(?array $brandOverride = null, ?array $styleOption = null): bool
    {
        /* Built via the shared helper so the on-disk bundle and the inline
           fallback (enqueue_bundle) can never drift. The sanitized-array
           params are forwarded for the dash save-handler's stale-cache
           sidestep — see build_css(). */
        $css = self::build_css($brandOverride, $styleOption);

        $bundlePath = LG_LAYOUT_V2_DIR . LG_LAYOUT_V2_BUNDLE_CSS;
        $dir = dirname($bundlePath);

        /* Pre-checks. Each surfaces a *specific* reason so a sysadmin reading
           debug.log knows exactly what to fix, instead of "write failed". */
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                self::log_bundle_failure("cannot create assets dir: $dir");
                return false;
            }
        }
        if (!is_writable($dir)) {
            self::log_bundle_failure(sprintf(
                "assets dir not writable by PHP user (%s): %s",
                self::current_php_user(), $dir
            ));
            return false;
        }
        if (is_file($bundlePath) && !is_writable($bundlePath)) {
            self::log_bundle_failure(sprintf(
                "bundle file exists but not writable by PHP user (%s): %s — check ownership/perms",
                self::current_php_user(), $bundlePath
            ));
            return false;
        }

        $bytes = @file_put_contents($bundlePath, $css);
        if ($bytes === false || $bytes !== strlen($css)) {
            $err = error_get_last();
            self::log_bundle_failure(sprintf(
                "file_put_contents wrote %s of %d bytes to %s%s",
                $bytes === false ? 'FAILED' : (string) $bytes,
                strlen($css),
                $bundlePath,
                $err ? ' — ' . ($err['message'] ?? '?') : ''
            ));
            return false;
        }

        return true;
    }

    private static function log_bundle_failure(string $detail): void
    {
        error_log('[lg-layout-v2] bundle regeneration failed: ' . $detail);
    }

    /** Best-effort identification of the PHP user, for log messages. */
    private static function current_php_user(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name'])) return (string) $info['name'];
        }
        return (string) (getenv('USER') ?: getenv('USERNAME') ?: 'unknown');
    }
}
