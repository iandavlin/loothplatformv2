<?php
/**
 * ImgCapExtractor — post-imgcap "Articles" CPT.
 *
 * The richest legacy schema. Authors built articles by adding rows to the
 * `img_cap_images_and_captions_repeater` ACF repeater, with four per-row
 * toggles (single image, gallery, text, oembed). See the manifest mapping
 * table in Mapper.php for how these become v2 blocks.
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

final class ImgCapExtractor extends BaseExtractor
{
    public const REPEATER_KEY = 'img_cap_images_and_captions_repeater';

    public static function post_type(): string { return 'post-imgcap'; }

    protected function extract_specifics(array $meta, int $post_id): array
    {
        return array_merge(
            ['hero' => ['type' => null], 'rows' => $this->rows($meta)],
            ['related_links' => self::default_related_links($meta)],
            ['extra_files'   => self::default_extra_files($meta)],
        );
    }

    private function rows(array $meta): array
    {
        $count = (int) (self::single($meta, self::REPEATER_KEY) ?: 0);
        if ($count <= 0) return [];

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'flags' => [
                    'single_image' => (bool) self::single($meta, self::sub($i, 'add_a_single_image__')),
                    'gallery'      => (bool) self::single($meta, self::sub($i, 'add_an_image_gallery__')),
                    'text'         => (bool) self::single($meta, self::sub($i, 'add_a_text_area__')),
                    'oembed'       => (bool) self::single($meta, self::sub($i, 'add_a_youtube_or_instagram_link__')),
                ],
                'image_id'    => (int) (self::single($meta, self::sub($i, 'img_cap_repeater_image')) ?: 0),
                'gallery_ids' => self::unserialize_ids(self::single($meta, self::sub($i, 'gallery2')) ?: ''),
                'text'        => (string) (self::single($meta, self::sub($i, 'img_cap_repeater_text')) ?: ''),
                'oembed'      => (string) (self::single($meta, self::sub($i, 'repeater_oembed')) ?: ''),
            ];
        }
        return $rows;
    }

    private static function sub(int $idx, string $field): string
    {
        return self::REPEATER_KEY . "_{$idx}_{$field}";
    }

    protected function known_meta_prefixes(): array
    {
        return array_merge(parent::known_meta_prefixes(), [
            'img_cap_images_and_captions_repeater',
        ]);
    }
}
