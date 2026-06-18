<?php
/**
 * BaseExtractor — the common ground every per-CPT extractor stands on.
 *
 * Reads the WP-core fields that apply to any post — title, slug, author,
 * date, post_content, excerpt, featured image, categories, tags, tier —
 * plus the cross-CPT "universal" meta fields (patreon-level, related-links
 * repeater, extra-content-files repeater).
 *
 * Subclasses fill in the CPT-specific bits: row sequences, hero media,
 * supplementary file/URL fields. The intermediate shape returned by extract()
 * is identical regardless of CPT, so the downstream Mapper doesn't need to
 * know which Extractor produced it.
 *
 * Intermediate shape contract:
 *   [
 *     'id', 'title', 'slug', 'author_id', 'date', 'permalink',
 *     'post_content', 'excerpt', 'featured_image_id',
 *     'categories' => int[], 'tags' => string[],
 *     'tier' => string,
 *     'post_type' => string,
 *
 *     'intro'      => string,
 *     'conclusion' => string,
 *
 *     'hero' => [ 'type' => 'embed'|'image'|null, 'url'?: string, 'image_id'?: int ],
 *
 *     'rows' => [
 *       [
 *         'flags' => [ single_image, gallery, text, oembed ],
 *         'image_id', 'gallery_ids', 'text', 'oembed',
 *       ]
 *     ],
 *
 *     'related_links' => [ ['description', 'url'] ],
 *     'extra_files'   => [ ['description', 'attachment_id'] ],
 *
 *     'unhandled' => string[],
 *   ]
 *
 * Concrete subclasses override `extract_specifics()` to populate hero/rows.
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

abstract class BaseExtractor
{
    /** patreon-level → v2 tier mapping. 0/1 = public, 2 = lite, 3+ = pro.
     *  Adjust here once if the Members plugin's level mapping changes. */
    protected const TIER_MAP = [
        0 => '',
        1 => '',
        2 => 'looth-lite',
        3 => 'looth-pro',
    ];

    /** Override in subclasses. */
    public static function post_type(): string
    {
        throw new \LogicException('BaseExtractor subclass must declare post_type()');
    }

    /** Subclass hook for CPT-specific reads. Default returns the empty
     *  contributions (no hero, no rows, no extras), which already produces
     *  a valid post-header + post_content + post-footer layout via the
     *  Mapper. Useful as-is for CPTs whose body is entirely in post_content. */
    protected function extract_specifics(array $meta, int $post_id): array
    {
        return [
            'hero' => ['type' => null],
            'rows' => [],
            'related_links' => self::default_related_links($meta),
            'extra_files'   => self::default_extra_files($meta),
        ];
    }

    public function extract(int $post_id): array
    {
        $post = get_post($post_id);
        if (!$post) throw new \RuntimeException("post $post_id not found");

        $meta = get_post_meta($post_id);

        $core = [
            'id'         => $post_id,
            'post_type'  => (string) $post->post_type,
            'title'      => (string) $post->post_title,
            'slug'       => (string) $post->post_name,
            'author_id'  => (int) $post->post_author,
            'date'       => (string) $post->post_date,
            'permalink'  => (string) get_permalink($post_id),
            'post_content' => (string) $post->post_content,
            'excerpt'    => (string) $post->post_excerpt,
            'featured_image_id' => (int) get_post_thumbnail_id($post_id),
            'categories' => wp_get_post_categories($post_id) ?: [],
            'tags'       => self::flatten_tags(wp_get_post_tags($post_id) ?: []),
            'tier'       => self::resolve_tier((int) (self::single($meta, 'patreon-level') ?: 0)),
            'intro'      => (string) (self::single($meta, 'img_cap_introduction_text_preamble') ?: ''),
            'conclusion' => (string) (self::single($meta, 'img_cap_conclusion_text') ?: ''),
        ];

        $specifics = $this->extract_specifics($meta, $post_id);

        return array_merge($core, $specifics, [
            'unhandled' => $this->unhandled_keys($meta),
        ]);
    }

    /** post_related_links_repeater is shared across most CPTs. Subclasses
     *  can override if a CPT uses a different field name. */
    protected static function default_related_links(array $meta): array
    {
        return self::read_link_repeater(
            $meta,
            'post_related_links_repeater',
            'post_related_link_description',
            'post_related_link_url'
        );
    }

    /** Same for extra_content_files_repeater. */
    protected static function default_extra_files(array $meta): array
    {
        $count = (int) (self::single($meta, 'extra_content_files_repeater') ?: 0);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $desc = trim((string) (self::single($meta, "extra_content_files_repeater_{$i}_extra_content_file_description_") ?: ''));
            $id   = (int) (self::single($meta, "extra_content_files_repeater_{$i}_extra_content_file") ?: 0);
            if ($id > 0) $out[] = ['description' => $desc, 'attachment_id' => $id];
        }
        return $out;
    }

    /** Generic ACF link-repeater reader. Used by post_related_links_repeater
     *  and per-CPT variants (loothcut_links_repeater, etc.). */
    protected static function read_link_repeater(
        array $meta,
        string $base,
        string $desc_field,
        string $url_field
    ): array {
        $count = (int) (self::single($meta, $base) ?: 0);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $desc = trim((string) (self::single($meta, "{$base}_{$i}_{$desc_field}") ?: ''));
            $url  = trim((string) (self::single($meta, "{$base}_{$i}_{$url_field}")  ?: ''));
            if ($desc !== '' || $url !== '') $out[] = ['description' => $desc, 'url' => $url];
        }
        return $out;
    }

    /** Meta keys we explicitly know about. Subclasses extend with their
     *  CPT prefix so the unhandled-list stays clean. */
    protected function known_meta_prefixes(): array
    {
        return [
            '_', 'classic-editor-', 'patreon-', 'fea_limit_', 'admin_form_',
            'wp_statistics_', '_oembed_', 'amazonS3_cache', 'add_repeater_fields',
            'do_you_have_', 'taxonomy_toggle', 'post_tip_link_radio',
            'post_addon_gallery', 'post_related_link_radio', 'category', 'tag',
            'img_cap_introduction', 'img_cap_conclusion', 'img_cap_add_tags',
            'post_related_links_repeater', 'extra_content_files_repeater',
            'user_post_', 'categories_', 'featured_content',
        ];
    }

    protected function unhandled_keys(array $meta): array
    {
        $known = $this->known_meta_prefixes();
        $unhandled = [];
        foreach (array_keys($meta) as $k) {
            $matched = false;
            foreach ($known as $p) {
                if (str_starts_with($k, $p)) { $matched = true; break; }
            }
            if (!$matched) $unhandled[] = $k;
        }
        sort($unhandled);
        return $unhandled;
    }

    /* ── primitive helpers ─────────────────────────────────────────────── */

    protected static function single(array $meta, string $key)
    {
        return isset($meta[$key]) ? maybe_unserialize($meta[$key][0]) : null;
    }

    protected static function unserialize_ids($raw): array
    {
        $v = maybe_unserialize($raw);
        if (!is_array($v)) return [];
        return array_values(array_filter(array_map('intval', $v), fn($n) => $n > 0));
    }

    protected static function flatten_tags(array $terms): array
    {
        return array_values(array_map(fn($t) => (string) $t->name, $terms));
    }

    protected static function resolve_tier(int $level): string
    {
        return self::TIER_MAP[$level] ?? '';
    }

    /** Empty row helper — subclasses use this when reading their own row
     *  sequence so all rows share a shape. */
    protected static function empty_row(): array
    {
        return [
            'flags' => ['single_image' => false, 'gallery' => false, 'text' => false, 'oembed' => false],
            'image_id'    => 0,
            'gallery_ids' => [],
            'text'        => '',
            'oembed'      => '',
        ];
    }
}
