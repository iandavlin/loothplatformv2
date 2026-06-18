<?php
/**
 * UsefulLinkExtractor — useful_links CPT.
 *
 * Almost data-only — a single URL + a description. The "post" is really a
 * link card. We synthesize a one-row sequence with a text block containing
 * the description + linked URL so the Mapper produces a callout-shaped
 * presentation. Featured image (if any) carries through the post-header.
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

final class UsefulLinkExtractor extends BaseExtractor
{
    public static function post_type(): string { return 'useful_links'; }

    protected function extract_specifics(array $meta, int $post_id): array
    {
        $url  = trim((string) (self::single($meta, 'useful_url') ?: ''));
        $desc = trim((string) (self::single($meta, 'useful_link_description') ?: ''));

        $rows = [];
        if ($url !== '' || $desc !== '') {
            $bits = [];
            if ($desc !== '') $bits[] = '<p>' . esc_html($desc) . '</p>';
            if ($url !== '')  $bits[] = '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a></p>';
            $row = self::empty_row();
            $row['flags']['text'] = true;
            $row['text']          = implode("\n\n", $bits);
            $rows[] = $row;
        }

        return [
            'hero' => ['type' => null],
            'rows' => $rows,
            'related_links' => self::default_related_links($meta),
            'extra_files'   => self::default_extra_files($meta),
        ];
    }

    protected function known_meta_prefixes(): array
    {
        return array_merge(parent::known_meta_prefixes(), ['useful_url', 'useful_link_description']);
    }
}
