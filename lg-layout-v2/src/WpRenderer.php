<?php
/**
 * WpRenderer — the_content filter integration.
 *
 * If the current post is managed by v2 and has a v2 layout, replace
 * $content with the engine's rendered HTML. Otherwise return $content
 * unchanged so v1 (or whatever else hooks the_content) can take over.
 *
 * Caching strategy (matches v1's intent):
 *   - Anonymous viewers: cache rendered HTML per (post + cache_epoch).
 *     Logged-in viewers always render fresh because gating produces
 *     viewer-specific HTML.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class WpRenderer
{
    public static function filter_content(string $content): string
    {
        /* Early-return paths are silent (would log every non-v2 post on the
           site). Only the actual render emits a log line at the bottom. */
        if (!is_singular() || !in_the_loop() || !is_main_query()) return $content;

        global $post;
        if (!Plugin::manages($post)) return $content;

        $layout = Plugin::load_layout($post->ID);
        if ($layout === null) return $content;

        $viewer = Plugin::current_viewer();
        $editorMode = (is_preview() && current_user_can('edit_post', $post->ID))
                   || FeEditor::is_active($post);
        $canEdit = FeEditor::can_edit($post);

        /* Once we've decided v2 owns this post's render, strip WP's text-
           formatting filters from the rest of the_content chain. They were
           designed for author-typed prose, not a structured-HTML engine's
           output, and they actively corrupt multi-line tags (wpautop
           inserts <p>/<br> mid-attribute on lines that *look* like text).
           wysiwyg block content comes from TinyMCE already paragraphed, so
           wpautop is at best a no-op there too. */
        remove_filter('the_content', 'wpautop');
        remove_filter('the_content', 'wptexturize');
        remove_filter('the_content', 'convert_smilies');
        remove_filter('the_content', 'convert_chars');
        remove_filter('the_content', 'capital_P_dangit');

        /* Anon cache fast-path */
        if (!$editorMode && !is_user_logged_in()) {
            $cached = self::cached_html($post->ID);
            if ($cached !== null) return $cached;
        }

        $brandOverride = get_option(LG_LAYOUT_V2_BRAND_OPTION, []);
        $brandTokens   = Theme::resolve(is_array($brandOverride) ? $brandOverride : []);
        $styleOption   = get_option(LG_LAYOUT_V2_STYLE_OPTION, []);
        $dashOverrides = is_array($styleOption) ? $styleOption : [];

        /* Post-level tier: the `tier` taxonomy term attached to this post.
           Used by the Renderer to auto-gate embed / download blocks (so
           authors don't have to also set `gated_tier` on each block —
           tagging the post is enough). Empty string = no implicit gate. */
        $postTier = '';
        foreach (wp_get_object_terms($post->ID, 'tier', ['fields' => 'slugs']) ?: [] as $slug) {
            if (is_string($slug) && $slug !== '' && $slug !== 'public') {
                $postTier = $slug;
                break;
            }
        }

        $ctx = [
            'viewer'         => $viewer,
            'editor_mode'    => $editorMode,
            'can_edit'       => $canEdit,
            'media_resolver' => [WpMedia::class, 'resolve'],
            'post_id'        => $post->ID,
            'post_tier'      => $postTier,
        ];

        $result = Pipeline::run($layout, $brandTokens, $dashOverrides, $ctx);
        $html   = $result['html'];

        /* BuddyBoss / BuddyPress's @mention filter runs on `the_content` and
           scans for `@<word>` tokens, wrapping each as
              <a class="bp-suggestions-mention" data-bb-hp-profile="…" href="…">@…</a>
           This injects a nested <a> inside our callout row's outer <a>,
           which is invalid HTML and visually breaks the row. Strip those
           inner anchors back to their plain text after the render. The
           BB filter never gets a useful match for legitimate v2 content
           anyway (an Instagram handle isn't a Looth member). */
        $html = preg_replace(
            '~<a\s+[^>]*class=["\'][^"\']*\bbp-suggestions-mention\b[^"\']*["\'][^>]*>(.*?)</a>~is',
            '$1',
            $html
        ) ?? $html;

        /* Tracing: log block count + any image blocks rendered with an empty
           image_id (the common "I added a block but no image shows" path).
           On fatal validation, surface the validator errors so debug.log
           tells the author exactly what's wrong instead of silent empty HTML. */
        $blocks  = is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        $empties = [];
        foreach ($blocks as $i => $b) {
            if (($b['type'] ?? '') === 'image' && empty($b['image_id'])) {
                $empties[] = "block[$i]";
            }
        }
        error_log(sprintf(
            '[lg-layout-v2 render] post=%d blocks=%d html_bytes=%d%s%s',
            $post->ID, count($blocks), strlen($html),
            $result['fatal'] ? ' FATAL' : '',
            $empties ? ' empty_images=' . implode(',', $empties) : ''
        ));
        if ($result['fatal'] && !empty($result['validation'])) {
            foreach ($result['validation'] as $err) {
                if (empty($err['fatal'])) continue;
                error_log(sprintf('[lg-layout-v2 render]   FATAL %s: %s',
                    (string) ($err['path'] ?? '?'), (string) ($err['msg'] ?? '?')));
            }
        }

        /* Store anon cache (don't write logged-in renders — gating taints them) */
        if (!$editorMode && !is_user_logged_in() && !$result['fatal']) {
            self::store_cache($post->ID, $html);
        }

        return $html;
    }

    /** Dedicated cache meta key, separate from post_content so the cached HTML
     *  doesn't leak into post revisions, search indexes, WYSIWYG previews, or
     *  exports. Previously we wrote into post_content; that worked but caused
     *  authors to see rendered HTML in the post-edit screen's content area
     *  and made revisions noisy. */
    private const CACHE_META = '_lg_layout_v2_rendered_html';

    private static function cached_html(int $post_id): ?string
    {
        $rendered_at = (int) get_post_meta($post_id, LG_LAYOUT_V2_RENDERED_AT_META, true);
        $epoch       = (int) get_option('lg_layout_v2_cache_epoch', 0);
        if ($rendered_at === 0 || $rendered_at < $epoch) return null;

        $cached = (string) get_post_meta($post_id, self::CACHE_META, true);
        return $cached !== '' ? $cached : null;
    }

    private static function store_cache(int $post_id, string $html): void
    {
        /* Direct meta writes — updated_post_meta hook would invalidate our
           own cache, so suppress the callback while we write. */
        remove_action('updated_post_meta', [Plugin::class, 'on_post_meta_changed'], 10);
        remove_action('added_post_meta',   [Plugin::class, 'on_post_meta_changed'], 10);
        update_post_meta($post_id, self::CACHE_META, $html);
        update_post_meta($post_id, LG_LAYOUT_V2_RENDERED_AT_META, time());
        add_action('updated_post_meta', [Plugin::class, 'on_post_meta_changed'], 10, 4);
        add_action('added_post_meta',   [Plugin::class, 'on_post_meta_changed'], 10, 4);
    }
}
