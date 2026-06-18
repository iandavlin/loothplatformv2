<?php
/**
 * Cli — WP-CLI commands for v2: validate / import / export layouts.
 *
 * Usage:
 *   wp lg-layout-v2 validate <file>
 *   wp lg-layout-v2 import   --post-id=NNN --file=path.json [--dry-run]
 *   wp lg-layout-v2 export   --post-id=NNN [--out=path.json]
 *
 * These exist so Claude (or any author tool) can generate a layout JSON
 * and apply it to a post in one command, and so existing layouts can be
 * round-tripped through plain JSON for hand-editing or migration.
 *
 * Registered in Plugin::boot when WP_CLI is defined.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

if (!class_exists(\WP_CLI::class)) return;

final class Cli
{
    public static function register(): void
    {
        \WP_CLI::add_command('lg-layout-v2 validate', [self::class, 'cmd_validate']);
        \WP_CLI::add_command('lg-layout-v2 import',   [self::class, 'cmd_import']);
        \WP_CLI::add_command('lg-layout-v2 export',   [self::class, 'cmd_export']);
    }

    /**
     * Validate a layout JSON file against the manifest contract.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the JSON file.
     *
     * ## EXAMPLES
     *
     *     wp lg-layout-v2 validate /tmp/article.json
     *     # exits 0 on clean validation; 1 if any error is fatal
     */
    public function cmd_validate(array $args): void
    {
        [$file] = $args;
        $layout = self::read_layout_file($file);
        $errors = Validator::validate($layout, Manifest::all());
        self::print_errors($errors);
        $fatal = array_filter($errors, fn($e) => !empty($e['fatal']));
        if ($fatal) {
            \WP_CLI::error(count($fatal) . ' fatal validation error(s)');
        }
        \WP_CLI::success('layout valid (' . count($layout['blocks'] ?? []) . ' blocks)');
    }

    /**
     * Import a layout JSON file into a post.
     *
     * Validates first; refuses to write if any error is fatal. Non-fatal
     * errors are printed as warnings. On success, writes the layout to
     * `_lg_layout_v2` post meta and bumps the global render cache epoch
     * so anonymous viewers see the new version on next page load.
     *
     * ## OPTIONS
     *
     * [--post-id=<id>]
     * : Target post ID. Required.
     *
     * [--file=<path>]
     * : JSON file to import. Required (or pass `-` to read from stdin).
     *
     * [--dry-run]
     * : Validate only — don't write to the database.
     *
     * ## EXAMPLES
     *
     *     wp lg-layout-v2 import --post-id=69338 --file=/tmp/article.json
     *     wp lg-layout-v2 import --post-id=69338 --file=- < article.json
     *     wp lg-layout-v2 import --post-id=69338 --file=/tmp/x.json --dry-run
     */
    public function cmd_import(array $args, array $assoc): void
    {
        $postId = (int) ($assoc['post-id'] ?? 0);
        $file   = (string) ($assoc['file'] ?? '');
        $dryRun = !empty($assoc['dry-run']);

        if ($postId <= 0)  \WP_CLI::error('--post-id is required and must be > 0');
        if ($file === '')  \WP_CLI::error('--file is required (path or `-` for stdin)');

        $post = get_post($postId);
        if (!$post) \WP_CLI::error("post $postId not found");
        if (!in_array($post->post_type, Plugin::MANAGED_CPTS, true)) {
            \WP_CLI::error("post $postId is type '{$post->post_type}', not v2-managed");
        }

        $layout = $file === '-' ? self::read_stdin_layout() : self::read_layout_file($file);

        $errors = Validator::validate($layout, Manifest::all());
        self::print_errors($errors);
        $fatal = array_filter($errors, fn($e) => !empty($e['fatal']));
        if ($fatal) {
            \WP_CLI::error(count($fatal) . ' fatal validation error(s) — nothing written');
        }

        $blocks = is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        \WP_CLI::log(sprintf('post %d: %d blocks parsed', $postId, count($blocks)));

        if ($dryRun) {
            \WP_CLI::success('dry-run: validation clean, no write performed');
            return;
        }

        update_post_meta($postId, LG_LAYOUT_V2_META_KEY, $layout);
        update_option('lg_layout_v2_cache_epoch', time(), true);

        \WP_CLI::success(sprintf('imported %d blocks into post %d; cache epoch bumped', count($blocks), $postId));
    }

    /**
     * Export a post's layout to JSON.
     *
     * ## OPTIONS
     *
     * [--post-id=<id>]
     * : Source post ID. Required.
     *
     * [--out=<path>]
     * : Output file path. Defaults to stdout.
     *
     * [--pretty]
     * : Pretty-print the JSON (multi-line, indented). Default: compact.
     *
     * ## EXAMPLES
     *
     *     wp lg-layout-v2 export --post-id=69338 --pretty
     *     wp lg-layout-v2 export --post-id=69338 --out=/tmp/article.json --pretty
     */
    public function cmd_export(array $args, array $assoc): void
    {
        $postId = (int) ($assoc['post-id'] ?? 0);
        $out    = (string) ($assoc['out']   ?? '');
        $pretty = !empty($assoc['pretty']);

        if ($postId <= 0) \WP_CLI::error('--post-id is required and must be > 0');

        $layout = get_post_meta($postId, LG_LAYOUT_V2_META_KEY, true);
        if (!is_array($layout)) \WP_CLI::error("post $postId has no v2 layout stored");

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) $flags |= JSON_PRETTY_PRINT;
        $json = json_encode($layout, $flags);
        if ($json === false) \WP_CLI::error('json_encode failed: ' . json_last_error_msg());

        if ($out === '') {
            \WP_CLI::log($json);
        } else {
            file_put_contents($out, $json . "\n");
            \WP_CLI::success("wrote $out");
        }
    }

    /* ── helpers ─────────────────────────────────────────────────────── */

    private static function read_layout_file(string $path): array
    {
        if (!is_file($path)) \WP_CLI::error("file not found: $path");
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) \WP_CLI::error('not valid JSON object: ' . json_last_error_msg());
        return $data;
    }

    private static function read_stdin_layout(): array
    {
        $raw = stream_get_contents(STDIN);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) \WP_CLI::error('stdin is not valid JSON object: ' . json_last_error_msg());
        return $data;
    }

    private static function print_errors(array $errors): void
    {
        foreach ($errors as $e) {
            $tag = !empty($e['fatal']) ? 'FATAL' : 'warn';
            $path = (string) ($e['path'] ?? '?');
            $msg  = (string) ($e['msg']  ?? '?');
            if (!empty($e['fatal'])) \WP_CLI::warning("$tag $path: $msg");
            else                     \WP_CLI::log("$tag $path: $msg");
        }
    }
}
