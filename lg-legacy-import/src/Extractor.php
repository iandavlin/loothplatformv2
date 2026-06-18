<?php
/**
 * Extractor — dispatcher.
 *
 * Picks the right per-CPT reader (Extractors\ImgCapExtractor, VideoExtractor,
 * etc.) based on the post's CPT, falls back to BaselineExtractor for
 * anything we haven't explicitly mapped yet.
 *
 * Adding a new CPT to the importer = drop a new file in Extractors/ that
 * extends BaseExtractor, then register it in the REGISTRY below. No changes
 * to Mapper / Cli / EditorButton required — they consume the universal
 * intermediate shape that all extractors return.
 *
 * Public surface is intentionally tiny:
 *   Extractor::extract($post_id) → array     (universal intermediate)
 *   Extractor::supported_post_types() → string[]
 *   Extractor::has_specific_for($post_type) → bool
 */

declare(strict_types=1);

namespace LG_Legacy_Import;

use LG_Legacy_Import\Extractors\BaseExtractor;
use LG_Legacy_Import\Extractors\BaselineExtractor;
use LG_Legacy_Import\Extractors\ImgCapExtractor;
use LG_Legacy_Import\Extractors\LoothcutExtractor;
use LG_Legacy_Import\Extractors\LoothprintExtractor;
use LG_Legacy_Import\Extractors\ShortyExtractor;
use LG_Legacy_Import\Extractors\UsefulLinkExtractor;
use LG_Legacy_Import\Extractors\VideoExtractor;

final class Extractor
{
    /** Map post_type → Extractor class. Order doesn't matter. */
    private const REGISTRY = [
        'post-imgcap'      => ImgCapExtractor::class,
        'post-type-videos' => VideoExtractor::class,
        'loothprint'       => LoothprintExtractor::class,
        'loothcuts'        => LoothcutExtractor::class,
        'shorty'           => ShortyExtractor::class,
        'useful_links'     => UsefulLinkExtractor::class,
    ];

    public static function extract(int $post_id): array
    {
        $post = get_post($post_id);
        if (!$post) throw new \RuntimeException("post $post_id not found");
        return self::for_post_type($post->post_type)->extract($post_id);
    }

    public static function for_post_type(string $post_type): BaseExtractor
    {
        $class = self::REGISTRY[$post_type] ?? BaselineExtractor::class;
        return new $class();
    }

    /** True iff a per-CPT extractor is registered (vs falling back to baseline). */
    public static function has_specific_for(string $post_type): bool
    {
        return isset(self::REGISTRY[$post_type]);
    }

    /** CPTs the importer knows specifically about. Used by Cli::export_all
     *  to default the post_type filter when none is passed. */
    public static function supported_post_types(): array
    {
        return array_keys(self::REGISTRY);
    }
}
