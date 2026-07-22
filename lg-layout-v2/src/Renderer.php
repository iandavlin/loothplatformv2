<?php
/**
 * Renderer — walk a layout tree, dispatch each block to its render.php,
 * produce article HTML.
 *
 * Block render templates live at blocks/<name>/render.php. They're included
 * with $args (validated props) and $ctx (render context) in scope. They
 * emit the block's HTML and nothing else — no <lg-edit> marker, no chrome,
 * no DB queries. The renderer wraps the marker around the block in
 * editor mode.
 *
 * Gating: blocks with `gated_tier` are skipped when the viewer doesn't
 * satisfy the tier (or shown with a paywall-preview badge for admins).
 *
 * Side effects: the renderer writes nothing. Render is a pure function of
 * (layout, viewer, manifests). That's what makes the harness possible.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class Renderer
{
    /** Block types that auto-inherit the post's tier when the post is
     *  tagged with a `tier` taxonomy term and the block has no explicit
     *  `gated_tier`. Kept narrow on purpose — we only want the actual
     *  "members-only deliverable" types to lock; images/wysiwyg/callouts
     *  stay visible as preview content. */
    private const AUTO_GATE_TYPES = ['embed', 'video', 'download', 'file', 'attachment'];


    /**
     * @param array $layout    Parsed layout JSON: { schema, _meta, blocks }
     * @param array $ctx       Render context: { viewer, editor_mode, post_id?, media_resolver? }
     * @return string          Article HTML.
     */
    public static function render(array $layout, array $ctx): string
    {
        $blocks = $layout['blocks'] ?? [];
        $manifests = Manifest::all();

        $ctx['manifests']    = $manifests;
        $ctx['editor_mode']  = !empty($ctx['editor_mode']);
        $ctx['viewer']       = $ctx['viewer']       ?? TierResolver::anonymous();
        $ctx['media_resolver'] = $ctx['media_resolver'] ?? fn(int $id) => ['id' => $id, 'url' => "(media $id)", 'alt' => '', 'sizes' => []];

        /* Sponsor record (brand pages). The host may inject $ctx['sponsor']
           directly (WP: fetched from the Lane-A API); otherwise we read it off
           the layout itself ($layout['sponsor'], baked at author/materialize
           time by the write-sponsor-v2 skill). Either way the sponsor blocks
           (brand-hero, featured-products, …) read $ctx['sponsor'] for their
           data, and the article root carries the brand-color CSS vars so every
           block auto-themes — no per-page CSS. */
        $sponsor = $ctx['sponsor'] ?? ($layout['sponsor'] ?? null);
        $ctx['sponsor'] = is_array($sponsor) ? $sponsor : null;

        /* Sponsor feed resolver — for featured-products blocks to pull live
           sponsor-product posts with their ACF URLs instead of baked
           permalinks. Returns items array or null on error. */
        $ctx['sponsor_feed'] = function(string $cpt, int $author_id, int $limit): ?array {
            if ($cpt !== 'sponsor-product' || $author_id <= 0 || $limit <= 0) return null;
            try {
                $posts = get_posts(['post_type' => 'sponsor-product', 'author' => $author_id, 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC']);
                if (empty($posts)) return null;
                $items = [];
                foreach ($posts as $post) {
                    $acf_url = function_exists('get_field') ? get_field('sponsor_product_link_to_product_page', $post->ID) : '';
                    $items[] = ['title' => $post->post_title, 'url' => $acf_url ?: get_permalink($post->ID), 'image' => '', 'price' => '', 'badge' => ''];
                }
                return $items ?: null;
            } catch (Throwable $e) { return null; }
        };

        /* Sponsor feed resolver — for featured-products blocks to pull live
           sponsor-product posts with their ACF URLs instead of baked
           permalinks. Returns items array or null on error. */
        \$ctx['sponsor_feed'] = function(string \$cpt, int \$author_id, int \$limit): ?array {
            if (\$cpt !== 'sponsor-product' || \$author_id <= 0 || \$limit <= 0) return null;
            try {
                \$posts = get_posts(['post_type' => 'sponsor-product', 'author' => \$author_id, 'posts_per_page' => \$limit, 'orderby' => 'date', 'order' => 'DESC']);
                if (empty(\$posts)) return null;
                \$items = [];
                foreach (\$posts as \$post) {
                    \$acf_url = function_exists('get_field') ? get_field('sponsor_product_link_to_product_page', \$post->ID) : '';
                    \$items[] = ['title' => \$post->post_title, 'url' => \$acf_url ?: get_permalink(\$post->ID), 'image' => '', 'price' => '', 'badge' => ''];
                }
                return \$items ?: null;
            } catch (Throwable \$e) { return null; }
        };

        /* data-lg-v2 marks the article as v2-rendered HTML — handy in browser
           devtools to tell v2 output apart from v1 / cached / theme-template
           output. data-lg-blocks + data-lg-schema give counts + manifest
           version at a glance. */
        $rootClass = 'lg-article' . ($ctx['sponsor'] ? ' lg-article--sponsor' : '');
        $rootStyle = $ctx['sponsor'] ? self::brandVarStyle($ctx['sponsor']) : '';
        $out = [sprintf(
            '<article class="%s" data-lg-v2="1" data-lg-blocks="%d" data-lg-schema="%d"%s>',
            $rootClass,
            count($blocks),
            (int) ($layout['schema'] ?? 1),
            $rootStyle !== '' ? ' style="' . self::attr($rootStyle) . '"' : ''
        )];
        foreach ($blocks as $i => $b) {
            /* Section gate: a `paywall` block whose tier the viewer doesn't
               satisfy cuts the post here. Emit the gate-CTA card in place
               and stop iterating — everything past the paywall is excluded
               from the response entirely (crawler-safe). Editor mode keeps
               rendering normally so authors can see/edit the trimmed
               content. */
            if (!$ctx['editor_mode']
                && is_array($b)
                && ($b['type'] ?? '') === 'paywall'
            ) {
                $tier = (string) ($b['tier'] ?? 'public');
                if (!TierResolver::satisfies($ctx['viewer'], $tier)) {
                    $cta = GateCta::render('paywall', $ctx, /* depth */ 1, $b);
                    if ($cta !== '') $out[] = $cta;
                    break;
                }
            }
            $rendered = self::renderBlock($b, $ctx, /* depth */ 1, [$i]);
            if ($rendered !== '') $out[] = $rendered;
        }
        $out[] = '</article>';
        $html = implode("\n", $out) . "\n";

        /* Gate scrub: when the post has a tier the viewer doesn't satisfy,
           strip any <a href> in the rendered HTML that points to the same
           canonical video URL as the gated embed. Keep the anchor's label
           text — useful as chapter listings for members watching the gated
           embed, but for non-members it'd be a bypass-route to the video
           on YouTube. Members and admins keep the clickable anchors. */
        $html = self::scrubGatedAnchors($html, $blocks, $ctx);
        return $html;
    }

    /** Strip <a href> wrappers around same-video YouTube URLs for viewers
     *  who don't satisfy the post's tier. Anchors to OTHER videos are left
     *  alone (those are external references, not bypass routes). */
    private static function scrubGatedAnchors(string $html, array $blocks, array $ctx): string
    {
        if (!empty($ctx['editor_mode'])) return $html;
        $postTier = (string) ($ctx['post_tier'] ?? '');
        if ($postTier === '') return $html;
        if (TierResolver::satisfies($ctx['viewer'], $postTier)) return $html;

        $videoIds = [];
        foreach ($blocks as $b) {
            if (!is_array($b)) continue;
            if (($b['type'] ?? '') !== 'embed') continue;
            $url = (string) ($b['url'] ?? '');
            if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})~', $url, $m)) {
                $videoIds[] = $m[1];
            }
        }
        if (!$videoIds) return $html;

        foreach (array_unique($videoIds) as $vid) {
            $pat = '~<a\s+[^>]*href="(?:https?://)?(?:www\.)?(?:youtu\.be|youtube\.com)/[^"]*' . preg_quote($vid, '~') . '[^"]*"[^>]*>(.*?)</a>~is';
            $html = (string) preg_replace($pat, '$1', $html);
        }
        return $html;
    }

    /**
     * Render a single block (recursive for containers).
     * $path is the addressing path to this block (used by the editor JS
     * to call REST against the right node). Empty string = skip.
     */
    private static function renderBlock(array $b, array $ctx, int $depth, array $path = []): string
    {
        $type = $b['type'] ?? null;
        if ($type === null) return '';

        $manifests = $ctx['manifests'];
        if (!isset($manifests[$type])) {
            return $ctx['editor_mode']
                ? self::indent($depth) . "<!-- lg-layout-v2: unknown block type '$type' -->"
                : '';
        }

        $manifest = $manifests[$type];

        /* Per-block gating. When the viewer doesn't satisfy gated_tier on a
           single block (mid-article gated download, etc.), substitute the
           gate-CTA card instead of returning empty. Editor mode keeps the
           original block visible so authors can edit gated content; a
           sibling HTML comment marks it for the FE editor.

           Section gates — the `paywall` block, which cuts everything past
           it — are handled by the top-level Renderer::render loop, NOT
           here. By the time the renderer recurses into a child block,
           that branch has already been decided. */
        $gated = $b['gated_tier'] ?? null;
        /* Auto-gate fallback: if the block didn't declare an explicit
           gated_tier but the POST has a tier taxonomy term AND the block
           is one of the "primary deliverable" types (video / download),
           treat it as gated to the post's tier. Lets authors tag the post
           with a tier and forget per-block gating. */
        if ($gated === null && !empty($ctx['post_tier'])
            && in_array($type, self::AUTO_GATE_TYPES, true)) {
            $gated = (string) $ctx['post_tier'];
        }
        if ($gated !== null) {
            if (!TierResolver::satisfies($ctx['viewer'], $gated)) {
                if ($ctx['editor_mode']) {
                    return self::indent($depth) . "<!-- lg-layout-v2: block '$type' gated (requires tier '$gated') -->";
                }
                $variant = GateCta::variantFor($type);
                return GateCta::render($variant, $ctx, $depth, $b);
            }
        }

        /* Thread current block's path through $ctx so container renders
           (columns) can derive their children's paths from it. */
        $ctx['__path'] = $path;

        /* Dispatch to blocks/<name>/render.php */
        $html = self::dispatch($type, $b, $ctx, $depth);
        if ($html === '') return '';

        /* Emit <lg-edit> markers when EITHER full editor mode (?lg_edit=1) or
           the lighter can_edit context (admin/author browsing without edit
           mode — used by the link-edit pencil to locate blocks). The host
           is the first element of $html (by convention). */
        if (!empty($ctx['editor_mode']) || !empty($ctx['can_edit'])) {
            $id = $b['id'] ?? '';
            $editable = !empty($manifest['editor']['insertable']) || !empty($manifest['editor']['inline_editable_props']);
            $tier = $b['gated_tier'] ?? '';
            $marker = sprintf(
                '<lg-edit data-lg-block-id="%s" data-lg-block-type="%s" data-lg-block-editable="%s" data-lg-block-gated-tier="%s" data-lg-block-path="%s"></lg-edit>',
                self::attr($id), self::attr($type), $editable ? 'true' : 'false', self::attr($tier),
                self::attr((string) json_encode($path))
            );
            return self::indent($depth) . $marker . "\n" . $html;
        }

        return $html;
    }

    /** Include blocks/<name>/render.php with $args + $ctx in scope, capture output. */
    private static function dispatch(string $type, array $block, array $ctx, int $depth): string
    {
        $blocksDir = dirname(__DIR__) . '/blocks';
        $renderPath = "$blocksDir/$type/render.php";
        if (!is_file($renderPath)) return '';

        $args = $block;
        $args['_depth'] = $depth;

        $callable = static function (array $args, array $ctx) use ($renderPath) {
            ob_start();
            include $renderPath;
            return (string) ob_get_clean();
        };

        $html = $callable($args, $ctx);
        return rtrim($html);
    }

    /**
     * Public helper for container blocks (columns) to render their children.
     * $parentPath addresses the children's array (e.g. [1, 'columns', 0, 'blocks']).
     * Each child gets path [...parentPath, $i] for editor addressing.
     */
    public static function renderChildren(array $blocks, array $ctx, int $depth, array $parentPath = []): string
    {
        $out = [];
        foreach ($blocks as $i => $child) {
            $r = self::renderBlock($child, $ctx, $depth, [...$parentPath, $i]);
            if ($r !== '') $out[] = $r;
        }
        return implode("\n", $out);
    }

    public static function indent(int $depth): string { return str_repeat('  ', $depth); }
    public static function attr(string $s): string    { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function text(string $s): string    { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /**
     * Build the inline `--brand-*` CSS-var declaration for a sponsor record's
     * colors. This is the brand-theming contract (Lane A): the article root
     * emits the vars, sponsor block shell.css reads them via
     * var(--brand-primary, <fallback>). Only non-null colors are emitted so a
     * sparse sponsor (e.g. gluboost has no primary) falls through to the
     * block's baked fallback instead of an empty `var()`.
     *
     * For each emitted color we also derive a readable `*-ink` companion
     * (black or white by luminance) so buttons/labels placed ON a brand color
     * stay legible without the block having to compute contrast itself.
     */
    public static function brandVarStyle(array $sponsor): string
    {
        $colors = is_array($sponsor['colors'] ?? null) ? $sponsor['colors'] : [];
        $map = [
            'primary'   => '--brand-primary',
            'secondary' => '--brand-secondary',
            'header'    => '--brand-header',
        ];
        $decls = [];
        foreach ($map as $key => $var) {
            $hex = $colors[$key] ?? null;
            if (!is_string($hex) || $hex === '') continue;
            if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)) continue;
            $decls[] = $var . ':' . $hex;
            $decls[] = $var . '-ink:' . (self::luminance($hex) > 0.55 ? '#1a1a1a' : '#ffffff');
        }
        return implode(';', $decls);
    }

    /** Relative luminance (0..1) of a #rgb / #rrggbb hex. Used to pick a
     *  readable ink color over a brand background. */
    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) !== 6) return 0.0;
        return (0.299 * hexdec(substr($hex,0,2))
              + 0.587 * hexdec(substr($hex,2,2))
              + 0.114 * hexdec(substr($hex,4,2))) / 255;
    }
}
