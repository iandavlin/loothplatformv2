#!/usr/bin/env php
<?php
/**
 * export-bundle.php — dump a WP post as a v2 migration bundle.
 *
 * Wraps the v1 BundleExporter with two enhancements:
 *   1. `media_resolved`: pre-resolved attachment metadata for every media ID
 *      referenced anywhere in the bundle (ACF image/gallery/file, postmeta,
 *      hero, thumbnail). Saves the translator a second-lookup pass.
 *   2. `rendered_html`: the post's current rendered HTML, so the translator
 *      can cross-check "did I capture everything visible today."
 *
 * Bundle format documented at docs/MIGRATION.md#bundle-format.
 *
 * Usage:
 *   bin/export-bundle.php --post-id=69206
 *   bin/export-bundle.php --post-id=69206 --out=/path/to/file.json
 *   bin/export-bundle.php --cpt=post-imgcap --all          # bulk dump
 *
 * Writes to storage/exports/<cpt>-<id>.json by default.
 *
 * Requires a WordPress install accessible via:
 *   - WP CLI (preferred, auto-detected)
 *   - or LG_WP_PATH env var pointing to wp-load.php's directory
 *
 * Phase 0 status: works against the production WP install via wp-cli eval.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$OUT_DIR = $ROOT . '/storage/exports';

$args = parseArgs(array_slice($argv, 1));

if (empty($args['post-id']) && empty($args['cpt'])) {
    fwrite(STDERR, "usage: bin/export-bundle.php --post-id=N | --cpt=NAME --all\n");
    exit(2);
}

@mkdir($OUT_DIR, 0755, true);

/* Locate wp-cli */
$wpCli = trim((string) shell_exec('command -v wp 2>/dev/null'));
if (!$wpCli) {
    fwrite(STDERR, "export-bundle: wp-cli not found in PATH\n");
    exit(2);
}

$wpRoot = $args['wp-root'] ?? '/var/www/dev';
$wpUser = $args['wp-user'] ?? 'www-data';

/* Determine post IDs to export */
$postIds = [];
if (!empty($args['post-id'])) {
    $postIds[] = (int) $args['post-id'];
} elseif (!empty($args['cpt']) && !empty($args['all'])) {
    $cpt = escapeshellarg($args['cpt']);
    $cmd = sprintf(
        "cd %s && sudo -u %s %s post list --post_type=%s --post_status=publish --format=ids 2>/dev/null",
        escapeshellarg($wpRoot), escapeshellarg($wpUser), $wpCli, $cpt
    );
    $idsRaw = shell_exec($cmd);
    foreach (preg_split('/\s+/', trim((string) $idsRaw)) as $id) {
        if ((int) $id > 0) $postIds[] = (int) $id;
    }
}

if (!$postIds) {
    fwrite(STDERR, "export-bundle: no posts to export\n");
    exit(2);
}

fwrite(STDOUT, "export-bundle: dumping " . count($postIds) . " post(s)\n");
$success = 0;
$failed = 0;

foreach ($postIds as $postId) {
    $bundleJson = exportOne($postId, $wpCli, $wpRoot, $wpUser);
    if ($bundleJson === null) { $failed++; continue; }

    $bundle = json_decode($bundleJson, true);
    if (!is_array($bundle)) {
        fwrite(STDERR, "  ✗ post $postId: bundle is not valid JSON\n");
        $failed++;
        continue;
    }

    $outFile = $args['out'] ?? ($OUT_DIR . '/' . $bundle['post']['post_type'] . '-' . $postId . '.json');
    file_put_contents($outFile, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fwrite(STDOUT, "  ✓ post $postId → " . substr($outFile, strlen($ROOT) + 1) . "\n");
    $success++;
}

fwrite(STDOUT, "\nexport-bundle: $success exported, $failed failed\n");
exit($failed === 0 ? 0 : 1);

/* ─────────────────────────────────────────────────────────────────
   Run a PHP fragment inside WP via wp eval and capture the result
───────────────────────────────────────────────────────────────── */

function exportOne(int $postId, string $wpCli, string $wpRoot, string $wpUser): ?string {
    $eval = <<<'PHP'
$post = get_post($POST_ID);
if (!$post) { echo "__ERR__post_not_found"; return; }

$mediaIds = [];

/* Base fields */
$bundle = [
    "exported_at" => gmdate("c"),
    "post" => [
        "ID" => $post->ID,
        "post_type" => $post->post_type,
        "post_title" => $post->post_title,
        "post_name" => $post->post_name,
        "post_status" => $post->post_status,
        "post_date" => $post->post_date,
        "post_modified" => $post->post_modified,
        "post_author" => (int) $post->post_author,
        "post_excerpt" => $post->post_excerpt,
        "post_content" => $post->post_content,
        "permalink" => get_permalink($post->ID),
    ],
];

/* Author */
$u = get_userdata((int) $post->post_author);
if ($u) {
    $umeta = [];
    foreach (get_user_meta($u->ID) as $k => $v) {
        if (str_starts_with($k, "session_tokens") || str_starts_with($k, "wp_capabilities")) continue;
        $umeta[$k] = maybe_unserialize(is_array($v) ? ($v[0] ?? "") : $v);
        if (preg_match("/_image$|_avatar/", $k) && is_numeric($umeta[$k])) $mediaIds[] = (int) $umeta[$k];
    }
    $bundle["author"] = [
        "ID" => $u->ID,
        "display_name" => $u->display_name,
        "user_login" => $u->user_login,
        "user_email" => $u->user_email,
        "roles" => $u->roles,
        "meta" => $umeta,
    ];
}

/* Taxonomies */
$tax = [];
foreach (get_object_taxonomies($post->post_type) as $t) {
    $terms = wp_get_post_terms($post->ID, $t);
    if (is_wp_error($terms) || !$terms) continue;
    $tax[$t] = array_map(fn($x) => ["id" => $x->term_id, "slug" => $x->slug, "name" => $x->name], $terms);
}
$bundle["taxonomies"] = $tax;

/* Thumbnail */
$tid = get_post_thumbnail_id($post->ID);
if ($tid) {
    $mediaIds[] = (int) $tid;
    $bundle["thumbnail"] = ["ID" => $tid, "url" => wp_get_attachment_url($tid), "alt" => get_post_meta($tid, "_wp_attachment_image_alt", true)];
}

/* Attachments by post_parent */
$attachments = get_posts(["post_type" => "attachment", "post_parent" => $post->ID, "numberposts" => -1, "post_status" => "inherit"]);
$bundle["attachments"] = array_map(function($a) use (&$mediaIds) {
    $mediaIds[] = (int) $a->ID;
    return ["ID" => $a->ID, "url" => wp_get_attachment_url($a->ID), "mime" => $a->post_mime_type, "title" => $a->post_title, "alt" => get_post_meta($a->ID, "_wp_attachment_image_alt", true)];
}, $attachments);

/* All postmeta (minus elementor css blob to save space) */
$pmeta = [];
foreach (get_post_meta($post->ID) as $k => $v) {
    if ($k === "_elementor_css") continue;
    $pmeta[$k] = array_map(fn($x) => maybe_unserialize($x), $v);
    foreach ($pmeta[$k] as $v2) {
        if (is_numeric($v2) && (int) $v2 > 10 && strpos($k, "_id") !== false) $mediaIds[] = (int) $v2;
    }
}
$bundle["postmeta_raw"] = $pmeta;

/* ACF fields (if available) */
$bundle["acf"] = function_exists("get_fields") ? (get_fields($post->ID) ?: []) : [];

/* Walk ACF to collect referenced media IDs */
$walk = function($node) use (&$walk, &$mediaIds) {
    if (is_array($node)) {
        if (isset($node["ID"]) && isset($node["url"])) { $mediaIds[] = (int) $node["ID"]; return; }
        if (isset($node["id"]) && isset($node["url"])) { $mediaIds[] = (int) $node["id"]; return; }
        foreach ($node as $v) $walk($v);
    }
};
$walk($bundle["acf"]);

/* Resolve every collected media ID */
$mediaIds = array_unique(array_filter(array_map("intval", $mediaIds), fn($x) => $x > 0));
$resolved = [];
foreach ($mediaIds as $mid) {
    $att = get_post($mid);
    if (!$att || $att->post_type !== "attachment") continue;
    $sizes = wp_get_attachment_metadata($mid);
    $sizeMap = [];
    if (is_array($sizes) && isset($sizes["sizes"])) {
        $uploadDir = wp_get_upload_dir();
        $baseDir = $uploadDir["baseurl"] . "/" . dirname($sizes["file"] ?? "");
        foreach ($sizes["sizes"] as $sn => $si) {
            $sizeMap[$sn] = ["url" => $baseDir . "/" . $si["file"], "width" => $si["width"], "height" => $si["height"]];
        }
    }
    $resolved[(string) $mid] = [
        "id" => $mid,
        "url" => wp_get_attachment_url($mid),
        "alt" => get_post_meta($mid, "_wp_attachment_image_alt", true),
        "mime" => $att->post_mime_type,
        "sizes" => $sizeMap,
        "filesize" => isset($sizes["filesize"]) ? (int) $sizes["filesize"] : null,
    ];
}
$bundle["media_resolved"] = $resolved;

/* Rendered HTML — what the post currently looks like */
setup_postdata($post);
$bundle["rendered_html"] = apply_filters("the_content", $post->post_content);
wp_reset_postdata();

echo json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP;

    $eval = str_replace('$POST_ID', (string) $postId, $eval);
    $tmpFile = tempnam(sys_get_temp_dir(), 'lg_export_');
    file_put_contents($tmpFile, "<?php\n" . $eval);
    chmod($tmpFile, 0644);  /* readable by www-data */

    $cmd = sprintf(
        "cd %s && sudo -u %s %s eval-file %s 2>/dev/null",
        escapeshellarg($wpRoot), escapeshellarg($wpUser), $wpCli, escapeshellarg($tmpFile)
    );
    $output = shell_exec($cmd);
    @unlink($tmpFile);

    if ($output === null || $output === '') {
        fwrite(STDERR, "  ✗ post $postId: wp eval returned no output\n");
        return null;
    }
    if (str_starts_with($output, '__ERR__')) {
        fwrite(STDERR, "  ✗ post $postId: " . substr($output, 7) . "\n");
        return null;
    }
    return $output;
}

function parseArgs(array $argv): array {
    $out = [];
    foreach ($argv as $a) {
        if ($a === '--all') $out['all'] = true;
        elseif (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $out[$m[1]] = $m[2];
    }
    return $out;
}
