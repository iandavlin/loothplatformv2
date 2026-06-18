<?php
/**
 * LoothprintExtractor — loothprint CPT (3D-printable parts).
 *
 * Schema: body in post_content, gallery in `loothprint_more_images`, plus
 * supplementary fields (3D file attachment, video instructions URL, Onshape
 * link, Creative Commons text, Buy Me a Coffee link). We synthesize a single
 * gallery row from the images, an optional embed row from the video link,
 * and surface the auxiliary fields as a callout in the Mapper later.
 *
 * The 3D file attachment is added to extra_files so authors get a download
 * link in the post-footer area.
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

final class LoothprintExtractor extends BaseExtractor
{
    public static function post_type(): string { return 'loothprint'; }

    protected function extract_specifics(array $meta, int $post_id): array
    {
        $rows = [];

        $gallery = self::unserialize_ids(self::single($meta, 'loothprint_more_images') ?: '');
        if ($gallery) {
            $row = self::empty_row();
            $row['flags']['gallery'] = true;
            $row['gallery_ids']      = $gallery;
            $rows[] = $row;
        }

        $videoUrl = trim((string) (self::single($meta, 'loothprint_video_instructions') ?: ''));
        if ($videoUrl !== '') {
            $row = self::empty_row();
            $row['flags']['oembed'] = true;
            $row['oembed']          = $videoUrl;
            $rows[] = $row;
        }

        /* Onshape + creative commons + buy-me-a-coffee → a synthesized
           text row that becomes a wysiwyg block at the bottom. Keeps the
           "where do I download the file / who can use it" affordances. */
        $sidecarBits = [];
        $cc = trim((string) (self::single($meta, 'loothprint_creative_commons') ?: ''));
        if ($cc !== '') $sidecarBits[] = '<p><strong>License:</strong> ' . esc_html($cc) . '</p>';
        $onshape = trim((string) (self::single($meta, 'loothprint_onshape_link') ?: ''));
        if ($onshape !== '') $sidecarBits[] = '<p><a href="' . esc_url($onshape) . '" target="_blank" rel="noopener">Edit on Onshape →</a></p>';
        $coffee = trim((string) (self::single($meta, 'loothprint_buy_me_a_coffee') ?: ''));
        if ($coffee !== '') $sidecarBits[] = '<p><a href="' . esc_url($coffee) . '" target="_blank" rel="noopener">Buy me a coffee ☕</a></p>';
        if ($sidecarBits) {
            $row = self::empty_row();
            $row['flags']['text'] = true;
            $row['text'] = implode("\n\n", $sidecarBits);
            $rows[] = $row;
        }

        $extraFiles = self::default_extra_files($meta);
        $threeD = (int) (self::single($meta, 'loothprint_3d_file') ?: 0);
        if ($threeD > 0) {
            $extraFiles[] = ['description' => '3D file', 'attachment_id' => $threeD];
        }

        return [
            'hero' => ['type' => null],
            'rows' => $rows,
            'related_links' => self::default_related_links($meta),
            'extra_files'   => $extraFiles,
        ];
    }

    protected function known_meta_prefixes(): array
    {
        return array_merge(parent::known_meta_prefixes(), ['loothprint_', 'content_topic_broad_terms']);
    }
}
