<?php
/**
 * GateCta — the "members-only" placeholder card the renderer substitutes
 * for any block a non-member can't see.
 *
 * Not a user-insertable block. There's no manifest, no insert pill entry.
 * The engine renders this whenever it would have rendered a gated_tier
 * block (per-block gate) OR everything-past-a-paywall-block (section
 * gate) to a viewer who doesn't satisfy the tier.
 *
 * Three variants picked automatically:
 *   - `embed`     — gated_tier on an `embed` block      → 16:9 with play overlay
 *   - `download`  — gated_tier on a download/file block → compact card
 *   - `paywall`   — content past a `paywall` block      → full 21:9 + featured image
 *
 * Copy comes from a single WP option (`lg_layout_v2_gate_cta`); defaults
 * fall through when the option doesn't exist (typical CLI / test harness).
 *
 * CSS lives in `blocks/paywall/shell.css` — see that file's comment header
 * for the styling contract.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class GateCta
{
    public const OPTION = 'lg_layout_v2_gate_cta';

    /** Block types that should get the `download` variant when gated.
     *  Other gated blocks fall back to `embed` for visual ones (anything with
     *  a poster makes sense) or just plain `download` shape otherwise. */
    private const DOWNLOAD_TYPES = ['download', 'file', 'attachment'];
    private const EMBED_TYPES    = ['embed', 'video', 'gallery', 'image'];

    /**
     * Read effective settings — stored option layered over sensible defaults.
     * Callers never need to handle missing keys.
     */
    public static function settings(): array
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        if (!is_array($stored)) $stored = [];
        return $stored + [
            'enabled'      => true,
            'headline'     => 'Members-only content',
            'body'         => 'Join The Looth Group to unlock the full library — long-form video, transcripts, build threads, and the trade-craft community of 1,500+ luthiers, repair techs, and instrument builders.',
            'button_label' => 'Become a member',
            'button_url'   => 'https://www.patreon.com/theloothgroup',
            'eyebrow_embed'    => 'Members-only video',
            'eyebrow_download' => 'Members-only download',
            'eyebrow_paywall'  => 'Looth Pro — continue reading',
            'headline_paywall' => 'The rest of this piece is for members',
        ];
    }

    /**
     * Render the CTA card.
     *
     * @param string $variant   'embed' | 'download' | 'paywall'
     * @param array  $ctx       The render context (used for post_id → featured image)
     * @param int    $depth     Indent depth
     * @param array  $blockHint Optional original block (used for download row meta — label/ext/size)
     */
    public static function render(string $variant, array $ctx, int $depth, array $blockHint = []): string
    {
        $s = self::settings();
        if (empty($s['enabled'])) return '';

        if (!in_array($variant, ['embed', 'download', 'paywall'], true)) {
            $variant = 'embed';
        }

        $ind  = Renderer::indent($depth);
        $ind2 = $ind . '  ';

        $btnLabel = Renderer::text((string) $s['button_label']);
        $btnUrl   = Renderer::attr((string) $s['button_url']);

        if ($variant === 'download') {
            /* Download cards lean on the original block's metadata when
               available — "Members-only download / Fritz rosette template
               — PDF, 412 KB" reads better than the generic copy alone. */
            $rowLabel = '';
            $rowMeta  = '';
            $hintItems = isset($blockHint['items']) && is_array($blockHint['items']) ? $blockHint['items'] : [];
            if (isset($hintItems[0]) && is_array($hintItems[0])) {
                $first = $hintItems[0];
                $rowLabel = isset($first['label']) ? (string) $first['label'] : '';
                $parts = [];
                if (!empty($first['ext']))  $parts[] = (string) $first['ext'];
                if (!empty($first['size'])) $parts[] = (string) $first['size'];
                if ($parts) $rowMeta = ' — ' . implode(', ', $parts);
            } elseif (($blockHint['type'] ?? '') === 'download') {
                /* The `download` BLOCK's shape, which is not the callout's.
                   Everything above reads `items[0]` — the prose-callout row this
                   card was originally written against. A download block carries
                   file_id / label / title instead, so without this arm the card
                   fell back to the generic "Members-only content" headline on
                   the very block Ian asked us to route the file through.

                   ⚠️ LABEL, EXTENSION AND SIZE ONLY — never the URL. This card is
                   what a NON-MEMBER sees; naming the file is the teaser, handing
                   over its address is the leak the gate exists to prevent. */
                $rowLabel = isset($blockHint['label']) ? trim((string) $blockHint['label']) : '';
                $fileId   = (int) ($blockHint['file_id'] ?? 0);
                if ($fileId > 0 && isset($ctx['media_resolver'])) {
                    $media = ($ctx['media_resolver'])($fileId);
                    if ($rowLabel === '') {
                        $rowLabel = trim((string) ($media['title'] ?? ''));
                        if ($rowLabel === '') $rowLabel = (string) ($media['filename'] ?? '');
                    }
                    $parts    = [];
                    $filename = (string) ($media['filename'] ?? '');
                    if ($filename !== '' && ($dot = strrpos($filename, '.')) !== false) {
                        $parts[] = strtoupper(substr($filename, $dot + 1));
                    }
                    if (!empty($media['filesize_human'])) $parts[] = (string) $media['filesize_human'];
                    if ($parts) $rowMeta = ' — ' . implode(', ', $parts);
                }
            }
            if ($rowLabel === '') $rowLabel = (string) ($s['headline'] ?? 'Members-only download');
            $eyebrow  = Renderer::text((string) ($s['eyebrow_download'] ?? 'Members-only download'));
            $headline = Renderer::text($rowLabel . $rowMeta);
            $body     = Renderer::text((string) $s['body']);

            return "$ind<aside class=\"lg-gate-cta lg-gate-cta--download\" data-lg-gate=\"download\">\n"
                 . "$ind2<div class=\"lg-gate-cta__icon\" aria-hidden=\"true\">\n"
                 . "$ind2  <svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"/><path d=\"M14 2v6h6\"/><path d=\"M12 18v-6M9 15h6\"/></svg>\n"
                 . "$ind2</div>\n"
                 . "$ind2<div class=\"lg-gate-cta__body\">\n"
                 . "$ind2  <div class=\"lg-gate-cta__eyebrow\">$eyebrow</div>\n"
                 . "$ind2  <p class=\"lg-gate-cta__headline\">$headline</p>\n"
                 . "$ind2  <p class=\"lg-gate-cta__text\">$body</p>\n"
                 . "$ind2</div>\n"
                 . "$ind2<a class=\"lg-gate-cta__btn\" href=\"$btnUrl\" rel=\"noopener\" target=\"_blank\">$btnLabel</a>\n"
                 . "$ind</aside>";
        }

        /* embed + paywall both use the featured-image overlay treatment. */
        [$posterUrl, $hasPoster] = self::resolveFeaturedImage($ctx);

        if ($variant === 'paywall') {
            $eyebrow  = Renderer::text((string) ($s['eyebrow_paywall']  ?? 'Continue reading'));
            $headline = Renderer::text((string) ($s['headline_paywall'] ?? 'The rest of this piece is for members'));
        } else {
            $eyebrow  = Renderer::text((string) ($s['eyebrow_embed'] ?? 'Members-only video'));
            $headline = Renderer::text((string) $s['headline']);
        }
        $body = Renderer::text((string) $s['body']);

        $hasPosterCls = $hasPoster ? ' has-poster' : '';
        $posterStyle  = $hasPoster ? ' style="background-image: url(' . Renderer::attr($posterUrl) . ')"' : '';

        $playGlyph = $variant === 'embed'
            ? "$ind2<span class=\"lg-gate-cta__play\" aria-hidden=\"true\"></span>\n"
            : '';

        return "$ind<aside class=\"lg-gate-cta lg-gate-cta--$variant$hasPosterCls\" data-lg-gate=\"$variant\">\n"
             . "$ind2<span class=\"lg-gate-cta__poster\"$posterStyle></span>\n"
             . "$ind2<span class=\"lg-gate-cta__scrim\"></span>\n"
             . $playGlyph
             . "$ind2<div class=\"lg-gate-cta__body\">\n"
             . "$ind2  <div class=\"lg-gate-cta__eyebrow\">$eyebrow</div>\n"
             . "$ind2  <div class=\"lg-gate-cta__headline\">$headline</div>\n"
             . "$ind2  <p class=\"lg-gate-cta__text\">$body</p>\n"
             . "$ind2  <a class=\"lg-gate-cta__btn\" href=\"$btnUrl\" rel=\"noopener\" target=\"_blank\">$btnLabel</a>\n"
             . "$ind2</div>\n"
             . "$ind</aside>";
    }

    /**
     * Pick the right CTA variant for a gated block. Public so the Renderer
     * can use the same dispatch table.
     */
    public static function variantFor(string $blockType, array $block = []): string
    {
        if (in_array($blockType, self::DOWNLOAD_TYPES, true)) return 'download';

        /* A `callout variant=files` IS a download — it is the shape a
           synthesized print page used before the `download` block, and 3 stored
           layouts still carry it. It is in neither list below, so it fell all
           the way through to the `embed` default and drew the VIDEO card over a
           ZIP. That is the second of the two identical panels Ian photographed
           on live post 72801 (#199).

           Fixing it at the synthesizer was not enough on its own: a layout that
           already exists on disk still says `callout`, and nothing re-renders it
           into a download block. So the dispatch table learns the shape too.

           Behind the same flag as the rest of #199, so OFF stays byte-identical.
           Depends on the block, not just its type, which is why this signature
           now takes one — the caller always had it. */
        if ($blockType === 'callout'
            && ($block['variant'] ?? '') === 'files'
            && Renderer::loothprintGatingEnabled()) {
            return 'download';
        }

        if (in_array($blockType, self::EMBED_TYPES, true))    return 'embed';
        /* Default for any other gated block — text-only `wysiwyg` etc.
           Use `embed` shape since it carries the featured image; better
           than the compact download card for an unknown content type. */
        return 'embed';
    }

    /**
     * Pull the post's featured image URL via WP. Returns [url, has_poster].
     */
    private static function resolveFeaturedImage(array $ctx): array
    {
        $postId = (int) ($ctx['post_id'] ?? 0);
        if ($postId <= 0) return ['', false];
        if (!function_exists('get_post_thumbnail_id')) return ['', false];

        $thumbId = (int) get_post_thumbnail_id($postId);
        if ($thumbId <= 0) return ['', false];
        if (!function_exists('wp_get_attachment_image_url')) return ['', false];

        $url = (string) (wp_get_attachment_image_url($thumbId, 'large') ?: '');
        return [$url, $url !== ''];
    }
}
