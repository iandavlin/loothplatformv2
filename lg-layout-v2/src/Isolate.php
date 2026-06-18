<?php
/**
 * Isolate — confine v2-managed posts to v2's CSS/JS authority.
 *
 * WordPress ships every plugin/theme stylesheet as an unlayered <link>. Per
 * the cascade-layers spec, unlayered rules beat layered rules regardless of
 * specificity — which means v2's entire @layer architecture loses to any
 * other plugin's CSS on the same page. The harness proves the layers work
 * in isolation; this class makes them work in production.
 *
 * Strategy (only runs on v2-managed posts when LG_LAYOUT_V2_ISOLATE is true):
 *
 *   1. Dequeue everything not on the allowlist (lg-layout-v2 bundle,
 *      admin-bar + its deps, and an optional extras list).
 *   2. For the surviving handles, rewrite them so they load via
 *      `@import url(...) layer(legacy);` inside a <style> tag instead of
 *      <link>. CSS files referenced by <link> can't be layered after the
 *      fact; @import inside a layered <style> can.
 *   3. CssBuilder declares `legacy` as the first cascade layer so anything
 *      wrapped in `layer(legacy)` loses to every v2 layer.
 *
 * This is opt-in: a new plugin installed tomorrow doesn't sneak onto v2
 * pages by default. Editable via the `lg_layout_v2_isolate_allowlist_*`
 * filters for extras that prove load-bearing.
 *
 * Read docs/ARCHITECTURE.md#cascade-layers before changing this — the
 * layer ordering invariant is the entire point.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Isolate
{
    /** Handles that survive the dequeue pass. Order doesn't matter. */
    public const STYLE_ALLOWLIST = [
        'lg-layout-v2',                  /* our own bundle */
        'admin-bar', 'dashicons',        /* admin bar + its dep */
        'wp-block-library',              /* core block defaults — needed for
                                            embed/image markup in legacy posts;
                                            harmless on v2 because it'll be
                                            wrapped in layer(legacy) */
        'lg-fe-edit-btn',                /* FE editor entry button inline css */
        'lg-fe-editor',                  /* FE editor chrome (pills, contenteditable) */
        'lg-link-edit',                  /* link-edit pencil chrome (admin/author only) */
        'lg-shared-site-header',         /* shared canonical header CSS (/lg-shared/) */
        'lg-site-footer',                /* plugin-resident site footer chrome */
    ];

    public const SCRIPT_ALLOWLIST = [
        'lg-layout-v2-front',            /* our own front-end JS (lightbox + popout) */
        'lg-fe-editor',                  /* front-end inline editor (only loaded with ?lg_edit=1) */
        'lg-link-edit',                  /* quick link-edit pencil (admin/author, any view) */
        'admin-bar',
        'hoverintent-js',                /* admin-bar dropdown intent */
        'jquery', 'jquery-core', 'jquery-migrate',
    ];

    public static function boot(): void
    {
        /* Run after every other plugin has enqueued its stuff. Priority
           PHP_INT_MAX so we win the timing race; the dequeue + wrap pass
           must come last. */
        add_action('wp_enqueue_scripts', [self::class, 'isolate_frontend'], PHP_INT_MAX);
    }

    /** Called on every front-end request. No-ops for non-v2-managed posts. */
    public static function isolate_frontend(): void
    {
        if (!self::should_isolate()) return;

        $styleAllow  = self::allow('style');
        $scriptAllow = self::allow('script');

        self::dequeue_styles($styleAllow);
        self::dequeue_scripts($scriptAllow);
        self::layer_wrap_surviving_styles($styleAllow);
    }

    /** True iff we're on a singular post v2 is configured to manage. */
    private static function should_isolate(): bool
    {
        if (!defined('LG_LAYOUT_V2_ISOLATE') || !LG_LAYOUT_V2_ISOLATE) return false;
        if (!is_singular(Plugin::MANAGED_CPTS)) return false;
        global $post;
        return Plugin::manages($post);
    }

    /** Filterable allowlist per kind ('style' or 'script'). */
    private static function allow(string $kind): array
    {
        $defaults = $kind === 'style' ? self::STYLE_ALLOWLIST : self::SCRIPT_ALLOWLIST;
        $filtered = apply_filters("lg_layout_v2_isolate_allowlist_$kind", $defaults);
        return is_array($filtered) ? array_values(array_unique($filtered)) : $defaults;
    }

    /** Dequeue + deregister every style not on the allowlist. */
    private static function dequeue_styles(array $allow): void
    {
        global $wp_styles;
        if (!$wp_styles instanceof \WP_Styles) return;
        $allow = array_flip($allow);
        foreach (array_keys($wp_styles->registered) as $handle) {
            if (isset($allow[$handle])) continue;
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    /** Dequeue + deregister every script not on the allowlist. */
    private static function dequeue_scripts(array $allow): void
    {
        global $wp_scripts;
        if (!$wp_scripts instanceof \WP_Scripts) return;
        $allow = array_flip($allow);
        foreach (array_keys($wp_scripts->registered) as $handle) {
            if (isset($allow[$handle])) continue;
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }

    /**
     * Replace each survivor's <link> with an `@import url(...) layer(legacy);`
     * inside a <style> tag. This is the only way to put an externally-loaded
     * stylesheet into a cascade layer after the fact — <link> tags are always
     * unlayered.
     *
     * The exception is our own bundle: it already opens with `@layer reset,
     * theme, block-shell, block-defaults, context, dash;` and emits rules into
     * those layers internally, so wrapping it in `layer(legacy)` would demote
     * v2's own architecture. Skip it.
     */
    /** Handles whose stylesheets we do NOT demote into layer(legacy). The
     *  layer wrap empties the registered src and relies on an @import in
     *  add_data('after', ...) — but WP's do_item skips emission entirely
     *  when src is empty, so the @import is never printed and the rules
     *  are missing from the page. For UI-chrome assets with highly
     *  specific selectors (#wpadminbar, dashicons) the layer demotion
     *  isn't needed anyway — their rules don't fight v2's cascade. */
    private const NO_LAYER_WRAP = ['admin-bar', 'dashicons', 'lg-fe-editor', 'lg-link-edit', 'lg-shared-site-header', 'lg-site-footer'];

    /** Filterable so consumers (FeEditor) can extend at runtime. */
    private static function no_layer_wrap(): array
    {
        return apply_filters('lg_layout_v2_isolate_no_layer_wrap', self::NO_LAYER_WRAP);
    }

    private static function layer_wrap_surviving_styles(array $allow): void
    {
        global $wp_styles;
        if (!$wp_styles instanceof \WP_Styles) return;

        $noWrap = self::no_layer_wrap();
        foreach ($allow as $handle) {
            if ($handle === 'lg-layout-v2') continue;
            if (in_array($handle, $noWrap, true)) continue;
            if (!isset($wp_styles->registered[$handle])) continue;

            $reg = $wp_styles->registered[$handle];
            $src = (string) $reg->src;
            if ($src === '') continue;

            /* Resolve protocol-relative + WP-relative srcs to absolute URLs
               so @import resolves no matter the host. WP normally does this
               in WP_Styles::do_item; we replicate the relevant bit. */
            $url = $src;
            if (str_starts_with($url, '//')) $url = (is_ssl() ? 'https:' : 'http:') . $url;
            if ($url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) {
                $url = site_url($url);
            }

            $ver  = $reg->ver !== null && $reg->ver !== false ? (string) $reg->ver : '';
            $href = $ver !== '' ? add_query_arg('ver', $ver, $url) : $url;

            /* Empty src so wp_head() doesn't print a <link>; the @import we
               attach via 'after' becomes the only loader for this stylesheet. */
            $wp_styles->registered[$handle]->src = '';

            $import = sprintf("@import url(%s) layer(legacy);", wp_json_encode($href));
            $wp_styles->add_data($handle, 'after', $import);
        }
    }
}
