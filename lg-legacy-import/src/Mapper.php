<?php
/**
 * Mapper — turn an Extractor-shaped intermediate array into a v2 layout JSON
 * (`{ schema: 1, blocks: [...] }`) ready to drop into _lg_layout_v2 meta.
 *
 * Row → blocks (in order):
 *
 *   single_image only          → image block
 *   single_image + text        → image block with image_text=text
 *   text only                  → wysiwyg block
 *   gallery (with or without
 *     accompanying single_img) → image block (lead photo), then gallery block,
 *                                then wysiwyg if text is also present
 *   oembed (with or without
 *     accompanying media)      → embed block
 *
 * Surrounding chrome:
 *   - post-header at index 0   (title + featured image + categories as chips)
 *   - optional wysiwyg with intro text right after post-header
 *   - optional wysiwyg with conclusion text after the last row
 *   - optional callout with related_links (data variant) before post-footer
 *   - post-footer last         (author card + related-posts grid)
 *
 * Block IDs follow the v2 convention `b_<8-char hex>` so the FE editor can
 * address them. Generated via wp_generate_uuid4() truncated.
 */

declare(strict_types=1);

namespace LG_Legacy_Import;

final class Mapper
{
    public static function to_layout(array $ext): array
    {
        $blocks = [];

        /* post-header */
        $blocks[] = self::id_block([
            'type'  => 'post-header',
            'title' => $ext['title'],
            'tagline' => self::derive_tagline($ext),
            'featured_image_id' => $ext['featured_image_id'],
            'tier' => $ext['tier'],
            'show_byline' => true,
            'show_categories' => true,
            'show_tags' => true,
        ]);

        /* hero — top-of-page lead media for CPTs that have one (videos:
           a YouTube embed; future: a featured-image-as-banner). Sits right
           under post-header so the page leads with the thing the post is
           about, then prose underneath. */
        if (!empty($ext['hero']['type'])) {
            $hero = self::hero_block($ext['hero']);
            if ($hero) $blocks[] = $hero;
        }

        /* intro */
        /* Hero URL — used to dedup any embed block that splits out of the
           body if it points at the same video the hero already shows. */
        $heroUrl = (string) ($ext['hero']['url'] ?? '');

        if (trim($ext['intro']) !== '') {
            foreach (self::split_to_blocks(self::clean_html($ext['intro']), $heroUrl) as $b) $blocks[] = $b;
        }

        /* post_content body. Many legacy posts authored entirely in the classic
           editor have the article prose here, with the ACF repeater only used
           for supplementary photos. Emit as wysiwyg + interleaved embed blocks
           where [embed]URL[/embed] shortcodes or bare-URL paragraphs appear
           (classic-editor's oembed trigger). Skip if the content is just
           whitespace/markup with no real prose. */
        $bodyText = trim(wp_strip_all_tags($ext['post_content']));
        if ($bodyText !== '' && strlen($bodyText) >= 30) {
            foreach (self::split_to_blocks(self::clean_html($ext['post_content']), $heroUrl) as $b) $blocks[] = $b;
        }

        /* repeater rows */
        foreach ($ext['rows'] as $i => $row) {
            foreach (self::row_to_blocks($row) as $b) $blocks[] = $b;
        }

        /* conclusion */
        if (trim($ext['conclusion']) !== '') {
            foreach (self::split_to_blocks(self::clean_html($ext['conclusion']), $heroUrl) as $b) $blocks[] = $b;
        }

        /* related links as a callout-data block */
        if (!empty($ext['related_links'])) {
            $links_html = '<ul>';
            foreach ($ext['related_links'] as $rl) {
                $url  = esc_url($rl['url']);
                $desc = esc_html($rl['description'] !== '' ? $rl['description'] : $rl['url']);
                if ($url === '') continue;
                $links_html .= '<li><a href="' . $url . '" target="_blank" rel="noopener">' . $desc . '</a></li>';
            }
            $links_html .= '</ul>';
            $blocks[] = self::id_block([
                'type' => 'callout',
                'variant' => 'data',
                'heading' => 'Related links',
                'body' => $links_html,
            ]);
        }

        /* post-footer */
        $blocks[] = self::id_block([
            'type' => 'post-footer',
            'show_author' => true,
            'show_related' => true,
        ]);

        return [
            'schema' => 1,
            '_meta'  => [
                'imported_from'   => $ext['id'],
                'imported_at'     => gmdate('c'),
                'importer'        => 'lg-legacy-import ' . (defined('LG_LEGACY_IMPORT_VERSION') ? LG_LEGACY_IMPORT_VERSION : '?'),
                'original_title'  => $ext['title'],
                'unhandled_meta'  => $ext['unhandled'],
            ],
            'blocks' => $blocks,
        ];
    }

    /**
     * Walk a single repeater row → 0..N v2 blocks.
     *
     * Layout decisions per row:
     *   - oembed always wins for "what this row is about" — emit embed block
     *     first, then any image + text underneath as a caption-ish wysiwyg.
     *   - single_image + text → image block with image_text holding the prose.
     *   - gallery: emit a leading image block (if single_image is set) so the
     *     row's "hero" is preserved, then a gallery block for the rest, then
     *     a wysiwyg if text exists. (Trying to cram the gallery's text into
     *     the gallery block's image_text loses image-relative context.)
     */
    private static function row_to_blocks(array $row): array
    {
        $out = [];
        $f   = $row['flags'];

        if ($f['oembed'] && $row['oembed'] !== '') {
            $out[] = self::id_block([
                'type'  => 'embed',
                'url'   => $row['oembed'],
                'ratio' => '16x9',
            ]);
        }

        if ($f['single_image'] && $row['image_id'] > 0) {
            $img = ['type' => 'image', 'image_id' => $row['image_id']];
            /* If text is present AND there's no gallery, attach the text as
               image_text so we don't fragment one row into two blocks
               unnecessarily. */
            if ($f['text'] && trim($row['text']) !== '' && !$f['gallery']) {
                $img['image_text'] = self::plainish($row['text']);
            }
            $out[] = self::id_block($img);
        }

        if ($f['gallery'] && !empty($row['gallery_ids'])) {
            $out[] = self::id_block([
                'type' => 'gallery',
                'image_ids' => $row['gallery_ids'],
                'columns' => 3,
                'layout' => 'grid',
            ]);
        }

        /* Text emits separately whenever we couldn't fold it into image_text. */
        if ($f['text'] && trim($row['text']) !== '') {
            $alreadyConsumed = ($f['single_image'] && !$f['gallery']);
            if (!$alreadyConsumed) {
                $out[] = self::id_block([
                    'type' => 'wysiwyg',
                    'html' => self::clean_html($row['text']),
                ]);
            }
        }

        return $out;
    }

    /**
     * Turn an extractor's hero dict into a v2 block, or null if the dict is
     * empty/unrecognized. Currently supports:
     *   - { type: 'embed', url: '<youtube|vimeo|instagram>' } → embed block
     *   - { type: 'image', image_id: <int> }                  → image block
     * Returns null for unknown types so the Mapper doesn't crash on a
     * future extractor that emits a hero type we haven't taught it yet.
     */
    private static function hero_block(array $hero): ?array
    {
        $type = (string) ($hero['type'] ?? '');
        if ($type === 'embed' && !empty($hero['url'])) {
            return self::id_block([
                'type'  => 'embed',
                'url'   => (string) $hero['url'],
                'ratio' => '16x9',
            ]);
        }
        if ($type === 'image' && !empty($hero['image_id'])) {
            return self::id_block([
                'type'     => 'image',
                'image_id' => (int) $hero['image_id'],
            ]);
        }
        return null;
    }

    /** Slug a tagline out of the excerpt or the first sentence of post_content. */
    private static function derive_tagline(array $ext): string
    {
        if (trim($ext['excerpt']) !== '') return wp_strip_all_tags($ext['excerpt']);
        $body = wp_strip_all_tags($ext['post_content']);
        $body = preg_replace('/\s+/', ' ', $body);
        if (strlen($body) > 180) {
            $body = substr($body, 0, 180);
            $body = preg_replace('/\s+\S*$/', '', $body) . '…';
        }
        return trim((string) $body);
    }

    /** Clean up author-typed HTML so it survives wysiwyg block sanitization. */
    private static function clean_html(string $html): string
    {
        /* Strip Elementor / DCE shortcode wrappers that won't render in v2.
           Add patterns as we discover them in the corpus. */
        $html = preg_replace('/\[\/?(et_pb_[^\]]*|dce-[^\]]*|elementor-[^\]]*)\]/i', '', $html);

        /* Legacy posts authored in the Classic Editor store paragraphs as
           bare \n / \n\n with no <p> or <br> tags — WP's `the_content` filter
           normally inflates those via wpautop() at render time. v2's
           renderer turns wpautop OFF (so block-emitted HTML survives intact),
           which means we have to run the inflation HERE, at import time, or
           every line break in a legacy post collapses into one paragraph
           blob (most visibly: timestamp lists in video posts). idempotent on
           already-wrapped HTML — wpautop skips content that's already paragraph-
           wrapped. */
        if (function_exists('wpautop')) {
            $html = wpautop($html);
        }
        return trim($html);
    }

    /**
     * Split a wysiwyg HTML blob into a sequence of wysiwyg + embed blocks.
     *
     * Triggers a split when we hit:
     *   1. A `[embed]URL[/embed]` shortcode (with or without attrs)
     *   2. A paragraph whose only content is a single bare URL — classic
     *      editor's oembed-on-its-own-line trigger, preserved as <p>URL</p>
     *      after wpautop().
     *
     * If $heroUrl is non-empty and an embed URL matches it (host+path), the
     * embed block is dropped (the hero already shows that video). The
     * surrounding wysiwyg HTML is kept intact regardless.
     *
     * Returns an array of block payloads (each already passed through
     * id_block()). Falls back to a single wysiwyg block if no splits fire,
     * preserving the legacy behavior for pure-prose posts.
     */
    private static function split_to_blocks(string $html, string $heroUrl = ''): array
    {
        $html = trim($html);
        if ($html === '') return [];

        /* Pattern matches:
           - <p>[embed ...]URL[/embed]</p>          (most common after wpautop)
           - [embed ...]URL[/embed]                 (raw, no wrapping <p>)
           - <p>URL_ONLY</p>                        (bare-URL-on-its-own-line) */
        $pattern = '~'
            . '(?:<p[^>]*>\s*)?'                                  // optional opening <p>
            . '(?:'
            .   '\[embed[^\]]*\](https?://[^\[\s<]+)\[/embed\]'    // [embed]URL[/embed]
            .   '|'
            .   '(https?://[^\s<"\']+)'                           // bare URL
            . ')'
            . '(?:\s*</p>)?'                                       // optional closing </p>
            . '~i';

        $blocks = [];
        $cursor = 0;

        if (!preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [self::id_block(['type' => 'wysiwyg', 'html' => $html])];
        }

        foreach ($matches as $m) {
            $fullMatch  = $m[0][0];
            $matchStart = $m[0][1];
            $matchEnd   = $matchStart + strlen($fullMatch);

            /* Pick whichever capture group fired. */
            $url = !empty($m[1][0]) ? $m[1][0] : (string) ($m[2][0] ?? '');
            $url = trim($url);

            /* For the bare-URL alternative (capture 2), only treat as an
               embed if the matched fragment was a paragraph by itself —
               i.e., the match was wrapped by <p>...</p>. Otherwise we'd
               split on every inline link, which is wrong. */
            if (!empty($m[2][0])) {
                $hasOpenP  = strncmp(ltrim(substr($html, max(0, $matchStart - 4), 4)), '<p', 2) === 0
                          || strpos(substr($html, max(0, $matchStart - 8), 8), '<p>') !== false;
                $hasCloseP = strpos(substr($html, $matchEnd - 4, 8), '</p>') !== false;
                if (!$hasOpenP || !$hasCloseP) {
                    continue; /* inline link inside prose — don't split */
                }
            }

            if ($url === '') continue;

            /* Emit any text between the cursor and this match as a wysiwyg
               block. Skip empty/whitespace-only fragments. */
            $before = substr($html, $cursor, $matchStart - $cursor);
            if (trim(wp_strip_all_tags($before)) !== '') {
                $blocks[] = self::id_block(['type' => 'wysiwyg', 'html' => trim($before)]);
            }

            /* Dedup against hero. */
            if ($heroUrl !== '' && self::same_video($url, $heroUrl)) {
                $cursor = $matchEnd;
                continue;
            }

            $blocks[] = self::id_block(['type' => 'embed', 'url' => $url]);
            $cursor = $matchEnd;
        }

        /* Trailing text after the last match. */
        $tail = substr($html, $cursor);
        if (trim(wp_strip_all_tags($tail)) !== '') {
            $blocks[] = self::id_block(['type' => 'wysiwyg', 'html' => trim($tail)]);
        }

        /* If we somehow consumed everything but emitted nothing (e.g., the
           body was just a single hero-matching embed and dedup dropped it),
           return empty rather than an artificial wysiwyg. */
        return $blocks;
    }

    /**
     * Compare two URLs for "same video" — host + path, ignoring query
     * strings and trailing slashes. Lets us match
     *   https://youtu.be/abc123        vs
     *   https://www.youtube.com/watch?v=abc123
     * by extracting the YT video ID from both shapes when applicable.
     */
    private static function same_video(string $a, string $b): bool
    {
        $extractYt = function (string $u): string {
            if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{6,})~i', $u, $m)) {
                return $m[1];
            }
            return '';
        };
        $idA = $extractYt($a);
        $idB = $extractYt($b);
        if ($idA !== '' && $idB !== '') return $idA === $idB;

        /* Generic: compare host + path. */
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (!$pa || !$pb) return false;
        $ka = strtolower(($pa['host'] ?? '') . rtrim((string) ($pa['path'] ?? ''), '/'));
        $kb = strtolower(($pb['host'] ?? '') . rtrim((string) ($pb['path'] ?? ''), '/'));
        return $ka !== '' && $ka === $kb;
    }

    /** Plain-text-ish view of HTML, suitable for image_text. Preserves line
     *  breaks (paragraphs separated by blank lines) but drops inline tags so
     *  the figcaption reads as body copy, not stray <span>s and <a>s. */
    private static function plainish(string $html): string
    {
        $html = preg_replace('#</p>\s*<p>#i', "\n\n", $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $text = wp_strip_all_tags($html);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim((string) $text);
    }

    /** Inject a generated `id` into a block payload. Stable per call so two
     *  exports of the same post don't churn IDs unnecessarily (deterministic
     *  per block contents via crc32 — collisions are vanishingly rare in
     *  practice and the FE editor only needs uniqueness within a post). */
    private static function id_block(array $block): array
    {
        $sig = crc32(json_encode($block));
        $block['id'] = 'b_' . dechex($sig & 0xffffffff);
        return $block;
    }
}
