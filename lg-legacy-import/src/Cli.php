<?php
/**
 * WP-CLI commands for the legacy importer.
 *
 *   wp lg-legacy export <id> [--out=DIR]
 *     Print the v2 layout JSON to stdout, or write layout.json + extracted.json
 *     + post.md to <DIR>/post-<id>/ for inspection.
 *
 *   wp lg-legacy export <id> --apply
 *     Write the layout JSON straight into the post's _lg_layout_v2 meta so v2
 *     takes over the render. The original ACF data is untouched — re-running
 *     without --apply still produces the same intermediate.
 *
 *   wp lg-legacy export-all [--out=DIR] [--limit=N] [--apply]
 *     Bulk variant. Skips posts that already have _lg_layout_v2 (v2-managed).
 *
 *   wp lg-legacy inspect <id>
 *     Print the extracted intermediate array. No layout mapping. Useful when
 *     a mapping looks wrong and you want to see what the Extractor saw.
 */

declare(strict_types=1);

namespace LG_Legacy_Import;

final class Cli
{
    /**
     * Convert a single legacy post.
     *
     * ## OPTIONS
     *
     * <id>
     * : Post ID to convert.
     *
     * [--out=<dir>]
     * : Write layout.json + extracted.json + post.md to <dir>/post-<id>/.
     *   Without this flag, layout JSON is printed to stdout.
     *
     * [--apply]
     * : Write the layout into the post's _lg_layout_v2 meta so v2 takes over.
     *   Read-only without this flag.
     *
     * ## EXAMPLES
     *
     *     wp lg-legacy export 50070
     *     wp lg-legacy export 50070 --out=/tmp/legacy
     *     wp lg-legacy export 50070 --apply
     */
    public function export($args, $assoc_args): void
    {
        $target  = (string) ($args[0] ?? '');
        if ($target === '') \WP_CLI::error('post id or URL required');
        $post_id = self::resolve_target($target);
        if (!$post_id) \WP_CLI::error("could not resolve '$target' to a post on this site");

        $ext = Extractor::extract($post_id);
        $layout = Mapper::to_layout($ext);
        $json   = wp_json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!empty($assoc_args['out'])) {
            $dir = rtrim((string) $assoc_args['out'], '/') . "/post-{$post_id}";
            if (!wp_mkdir_p($dir)) \WP_CLI::error("could not create $dir");
            file_put_contents("$dir/layout.json", $json);
            file_put_contents("$dir/extracted.json", wp_json_encode($ext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            file_put_contents("$dir/post.md", self::to_markdown($ext, $layout));
            \WP_CLI::success("wrote $dir/{layout,extracted}.json + post.md");
        } else {
            \WP_CLI::log($json);
        }

        if (!empty($assoc_args['apply'])) {
            self::apply_layout($post_id, $layout);
            \WP_CLI::success("applied layout to post $post_id _lg_layout_v2 meta");
        }
    }

    /**
     * Bulk convert legacy posts across one or more supported CPTs.
     *
     * ## OPTIONS
     *
     * [--post-type=<cpt>]
     * : Limit to a single CPT. Default: all registered CPTs (post-imgcap,
     *   post-type-videos, loothprint, loothcuts, shorty, useful_links).
     *
     * [--out=<dir>]
     * : Output directory (one subfolder per post).
     *
     * [--limit=<n>]
     * : Stop after N posts total (across all CPTs being processed).
     *
     * [--apply]
     * : Write layouts into _lg_layout_v2 meta for each. CAREFUL.
     */
    public function export_all($args, $assoc_args): void
    {
        $pt = $assoc_args['post-type'] ?? null;
        $post_types = $pt ? [$pt] : Extractor::supported_post_types();
        $ids = get_posts([
            'post_type'   => $post_types,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        $ids = array_filter($ids, function ($id) {
            return !get_post_meta($id, '_lg_layout_v2', true) && !get_post_meta($id, 'lg_layout_v2', true);
        });
        $limit = (int) ($assoc_args['limit'] ?? 0);
        if ($limit > 0) $ids = array_slice($ids, 0, $limit);

        \WP_CLI::log('converting ' . count($ids) . ' posts');
        foreach ($ids as $id) {
            \WP_CLI::log("  → post $id");
            try {
                $this->export([(string) $id], $assoc_args);
            } catch (\Throwable $e) {
                \WP_CLI::warning("post $id failed: " . $e->getMessage());
            }
        }
        \WP_CLI::success('done');
    }

    /**
     * Print the Extractor's intermediate for one post.
     *
     * ## OPTIONS
     *
     * <id>
     * : Post ID.
     */
    public function inspect($args, $assoc_args): void
    {
        $target  = (string) ($args[0] ?? '');
        if ($target === '') \WP_CLI::error('post id or URL required');
        $post_id = self::resolve_target($target);
        if (!$post_id) \WP_CLI::error("could not resolve '$target' to a post on this site");
        $ext = Extractor::extract($post_id);
        \WP_CLI::log(wp_json_encode($ext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Resolve a CLI argument that's either a post ID or a URL into a local
     * post ID on this site. URL form is the testing-on-dev workflow:
     *
     *   wp lg-legacy export https://loothgroup.com/post-imgcap/martin-binding-repair/
     *
     * Dev is a 2026-05-15 snapshot of live, so slugs match — we strip the
     * host, walk the path segments looking for "/<post-type>/<slug>/", and
     * look up by post_name + post_type. Any host works (live, dev, staging),
     * since we only use the path.
     */
    private static function resolve_target(string $target): int
    {
        /* Numeric → post ID directly. */
        if (ctype_digit($target)) return (int) $target;

        $parts = parse_url($target);
        if (!is_array($parts) || empty($parts['path'])) return 0;
        $segs = array_values(array_filter(explode('/', (string) $parts['path']), 'strlen'));
        if (count($segs) < 2) return 0;

        /* Typical legacy permalink shape: /<post_type>/<slug>/. Try the last
           two segments as (post_type, slug). If the post_type slug doesn't
           match a real CPT, fall back to scanning all public CPTs. */
        $maybe_type = $segs[count($segs) - 2];
        $slug       = $segs[count($segs) - 1];

        if (post_type_exists($maybe_type)) {
            $p = get_page_by_path($slug, OBJECT, $maybe_type);
            if ($p) return (int) $p->ID;
        }
        /* Fallback: scan all registered CPTs by slug. */
        foreach (Extractor::supported_post_types() as $pt) {
            $p = get_page_by_path($slug, OBJECT, $pt);
            if ($p) return (int) $p->ID;
        }
        return 0;
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    /** Write the layout into the post's _lg_layout_v2 meta. wp_slash so
     *  WP's slashing-on-save doesn't double-escape the JSON. */
    private static function apply_layout(int $post_id, array $layout): void
    {
        update_post_meta($post_id, '_lg_layout_v2', wp_slash(wp_json_encode($layout)));
        /* Bump the v2 cache epoch so anon renders pick up the new layout. */
        update_option('lg_layout_v2_cache_epoch', time());
    }

    /** Render a human-readable markdown mirror of the post — useful for
     *  passing to the write-article-v2 skill if a layout needs refinement. */
    private static function to_markdown(array $ext, array $layout): string
    {
        $out  = "# {$ext['title']}\n\n";
        $out .= "**Post ID:** {$ext['id']}  \n";
        $out .= "**Permalink:** {$ext['permalink']}  \n";
        $out .= "**Date:** {$ext['date']}  \n";
        $out .= "**Tier:** " . ($ext['tier'] !== '' ? $ext['tier'] : 'public') . "  \n";
        if (!empty($ext['tags']))       $out .= '**Tags:** ' . implode(', ', $ext['tags']) . "  \n";
        if (!empty($ext['categories'])) $out .= '**Category IDs:** ' . implode(', ', $ext['categories']) . "  \n";
        $out .= "\n";

        if (trim($ext['intro']) !== '')      $out .= "## Intro\n\n" . wp_strip_all_tags($ext['intro']) . "\n\n";

        foreach ($ext['rows'] as $i => $r) {
            $n = $i + 1;
            $out .= "## Row {$n}\n\n";
            if (!empty($r['flags']['single_image']) && $r['image_id']) $out .= "- image: attachment {$r['image_id']}\n";
            if (!empty($r['flags']['gallery']))     $out .= '- gallery: ' . count($r['gallery_ids']) . " images (" . implode(', ', $r['gallery_ids']) . ")\n";
            if (!empty($r['flags']['oembed']))      $out .= "- oembed: {$r['oembed']}\n";
            if (!empty($r['flags']['text']) && trim($r['text']) !== '') {
                $out .= "\n" . wp_strip_all_tags($r['text']) . "\n\n";
            }
        }

        if (trim($ext['conclusion']) !== '') $out .= "## Conclusion\n\n" . wp_strip_all_tags($ext['conclusion']) . "\n\n";

        if (!empty($ext['unhandled'])) {
            $out .= "## Unhandled meta keys\n\n";
            foreach ($ext['unhandled'] as $k) $out .= "- `$k`\n";
        }

        return $out;
    }
}
