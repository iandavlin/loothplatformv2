<?php
/**
 * ShortyExtractor — shorty CPT (short-form posts).
 *
 * Body in post_content. Almost pure baseline; the only CPT-specific bit is
 * `associated_links__` (an alternate name for the per-row related links).
 * We fold those into the standard related_links output.
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

final class ShortyExtractor extends BaseExtractor
{
    public static function post_type(): string { return 'shorty'; }

    protected function extract_specifics(array $meta, int $post_id): array
    {
        return [
            'hero' => ['type' => null],
            'rows' => [],
            'related_links' => array_merge(
                self::default_related_links($meta),
                self::read_link_repeater($meta, 'associated_links__', 'link_description', 'url_')
            ),
            'extra_files' => self::default_extra_files($meta),
        ];
    }

    protected function known_meta_prefixes(): array
    {
        return array_merge(parent::known_meta_prefixes(), ['associated_links__']);
    }
}
