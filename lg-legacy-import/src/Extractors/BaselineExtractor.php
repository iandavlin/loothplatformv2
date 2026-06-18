<?php
/**
 * BaselineExtractor — fallback when no per-CPT extractor is registered.
 *
 * Reads only the universal fields (title, post_content, featured image,
 * categories, tier, related-links repeater). Useful for any CPT we haven't
 * explicitly mapped yet — produces a workable "post-header + body + footer"
 * layout that the author can refine in the v2 editor.
 *
 * The dispatcher uses this whenever Extractors::for() doesn't recognize the
 * post type. Per-CPT specifics get added by writing a new subclass.
 */

declare(strict_types=1);

namespace LG_Legacy_Import\Extractors;

final class BaselineExtractor extends BaseExtractor
{
    public static function post_type(): string { return '*'; }
}
