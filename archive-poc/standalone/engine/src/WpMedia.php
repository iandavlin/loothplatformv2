<?php
/**
 * WpMedia — bridge between block render.php files and WP's attachment API.
 *
 * Block render templates receive a `media_resolver` callable in $ctx. In the
 * CLI harness this is backed by tests/fixtures/_media.json; in WP it's this
 * class's resolve() method, which queries the attachment system.
 *
 * Same return shape in both: { id, url, alt, mime, sizes }.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class WpMedia
{
    /** @return array{id: int, url: string, alt: string, mime: string, sizes: array} */
    public static function resolve(int $id): array
    {
        if ($id <= 0) return self::empty($id);

        $url = wp_get_attachment_url($id);
        if ($url === false) return self::empty($id);

        $att = get_post($id);
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $mime = $att ? (string) $att->post_mime_type : '';

        $sizes = [];
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && !empty($meta['sizes'])) {
            $baseUrl = dirname($url);
            foreach ($meta['sizes'] as $size => $info) {
                $sizes[$size] = [
                    'url'    => $baseUrl . '/' . ($info['file'] ?? ''),
                    'width'  => (int) ($info['width'] ?? 0),
                    'height' => (int) ($info['height'] ?? 0),
                ];
            }
        }

        return [
            'id'    => $id,
            'url'   => (string) $url,
            'alt'   => $alt,
            'mime'  => $mime,
            'sizes' => $sizes,
        ];
    }

    private static function empty(int $id): array
    {
        return ['id' => $id, 'url' => '', 'alt' => '', 'mime' => '', 'sizes' => []];
    }
}
