<?php
/**
 * LoothcutExtractor — loothcuts CPT (CNC-cut parts).
 *
 * Like LoothprintExtractor but with the `loothcut_*` prefix and a different
 * body source: `loothcut_about_your_loothcut` (rich text) carries the
 * article body, not post_content. We promote that into post_content so the
 * Mapper's universal body-emission code path works without special-casing.
 *
 * Has its own link repeater (`loothcut_links_repeater`) that becomes the
 * related-links callout, plus a CNC file attachment (extra_files).
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

final class LoothcutExtractor extends BaseExtractor
{
    public static function post_type(): string { return 'loothcuts'; }

    public function extract(int $post_id): array
    {
        $out = parent::extract($post_id);
        /* Loothcuts store the body text in a meta field rather than
           post_content. Promote it so the Mapper handles it like any other
           classic-editor body. */
        if (trim($out['post_content']) === '') {
            $meta = get_post_meta($post_id);
            $body = (string) (self::single($meta, 'loothcut_about_your_loothcut') ?: '');
            if (trim($body) !== '') $out['post_content'] = $body;
        }
        return $out;
    }

    protected function extract_specifics(array $meta, int $post_id): array
    {
        $rows = [];

        $gallery = self::unserialize_ids(self::single($meta, 'loothcut_more_images') ?: '');
        if ($gallery) {
            $row = self::empty_row();
            $row['flags']['gallery'] = true;
            $row['gallery_ids']      = $gallery;
            $rows[] = $row;
        }

        $videoUrl = trim((string) (self::single($meta, 'loothcut_video_instructions') ?: ''));
        if ($videoUrl !== '') {
            $row = self::empty_row();
            $row['flags']['oembed'] = true;
            $row['oembed']          = $videoUrl;
            $rows[] = $row;
        }

        $sidecarBits = [];
        $cc = trim((string) (self::single($meta, 'loothcut_creative_commons') ?: ''));
        if ($cc !== '') $sidecarBits[] = '<p><strong>License:</strong> ' . esc_html($cc) . '</p>';
        $onshape = trim((string) (self::single($meta, 'loothcut_onshape_link') ?: ''));
        if ($onshape !== '') $sidecarBits[] = '<p><a href="' . esc_url($onshape) . '" target="_blank" rel="noopener">Edit on Onshape →</a></p>';
        $coffee = trim((string) (self::single($meta, 'loothcut_buy_me_a_coffee') ?: ''));
        if ($coffee !== '') $sidecarBits[] = '<p><a href="' . esc_url($coffee) . '" target="_blank" rel="noopener">Buy me a coffee ☕</a></p>';
        if ($sidecarBits) {
            $row = self::empty_row();
            $row['flags']['text'] = true;
            $row['text'] = implode("\n\n", $sidecarBits);
            $rows[] = $row;
        }

        /* loothcut_links_repeater uses different subfield names than the
           shared post_related_links_repeater — desc + url instead of
           description + url. */
        $cutLinks = self::read_link_repeater($meta, 'loothcut_links_repeater', 'loothcut_links_desc', 'loothcut_links_url');

        $extraFiles = self::default_extra_files($meta);
        $cncFile = (int) (self::single($meta, 'loothcut_cnc_file') ?: 0);
        if ($cncFile > 0) {
            $extraFiles[] = ['description' => 'CNC file', 'attachment_id' => $cncFile];
        }

        return [
            'hero' => ['type' => null],
            'rows' => $rows,
            'related_links' => array_merge(self::default_related_links($meta), $cutLinks),
            'extra_files'   => $extraFiles,
        ];
    }

    protected function known_meta_prefixes(): array
    {
        return array_merge(parent::known_meta_prefixes(), ['loothcut_']);
    }
}
