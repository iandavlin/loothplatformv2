<?php
/**
 * VideoExtractor — post-type-videos CPT.
 *
 * Body content lives in post_content (classic editor or empty). The video
 * itself is in the `youtube_link` field — we emit it as a hero embed at the
 * top of the layout. `video_related_links_repeater` becomes the bottom
 * related-links callout.
 *
 * start_time and video_published_date are intentionally not mapped — they
 * surface in the unhandled list so they can be added later if useful.
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

final class VideoExtractor extends BaseExtractor
{
    public static function post_type(): string { return 'post-type-videos'; }

    protected function extract_specifics(array $meta, int $post_id): array
    {
        $youtube = trim((string) (self::single($meta, 'youtube_link') ?: ''));

        return [
            'hero' => $youtube !== ''
                ? ['type' => 'embed', 'url' => $youtube]
                : ['type' => null],
            'rows' => [],
            'related_links' => array_merge(
                self::default_related_links($meta),
                self::read_link_repeater($meta, 'video_related_links_repeater', 'description', 'url')
            ),
            'extra_files' => self::default_extra_files($meta),
        ];
    }

    protected function known_meta_prefixes(): array
    {
        return array_merge(parent::known_meta_prefixes(), [
            'youtube_link', 'start_time', 'video_related_links_repeater',
            'video_category', 'video_published_date', 'content_category_',
            'is_this_post_free_',
        ]);
    }
}
