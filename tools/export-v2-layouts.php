<?php
/**
 * tools/export-v2-layouts.php — build the conversion-carry bundle for the cut
 * (Ian 6/12: "I don't want to reconvert everything. Just the gaps.").
 *
 * The month of conversion work lives as POST META on posts whose IDs are
 * live's own (dev = live clone; all 674 layout-bearing posts predate the
 * 6/11 snapshot). So the carry is a by-ID meta patch, not a re-conversion
 * and not a WXR import. This script dumps one JSON bundle:
 *
 *   { generated, host, items: [ { id, slug, type, status, content_md5,
 *       meta: { _lg_layout_v2: <raw meta_value>, ... } } ] }
 *
 * RAW meta_value bytes are carried verbatim (layout meta is a JSON string
 * from the FE editor OR a PHP-serialized array from CLI imports — both must
 * round-trip exactly; the apply script writes raw bytes back via $wpdb).
 *
 * Run on dev:   sudo -u www-data wp --path=/var/www/dev eval-file \
 *                 /home/ubuntu/projects/tools/export-v2-layouts.php \
 *                 > /home/ubuntu/projects/live-bundle/v2-layouts-bundle.json
 * Apply on live: tools/apply-v2-layouts.php (see its header).
 */

if (!defined('ABSPATH')) { fwrite(STDERR, "run via wp eval-file\n"); exit(2); }

global $wpdb;

// The meta the conversion/render pipeline owns. rendered_html/rendered_at are
// CACHES — excluded on purpose (live re-renders; smaller bundle, no stale
// HTML). er_/wd_ keys belong to other features, not the conversion carry.
$KEYS = ['_lg_layout_v2'];

$rows = $wpdb->get_results("
    SELECT p.ID, p.post_name, p.post_type, p.post_status, MD5(p.post_content) AS content_md5
    FROM {$wpdb->posts} p
    JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_lg_layout_v2'
    WHERE p.post_status IN ('publish', 'draft')
    ORDER BY p.ID
");

$items = [];
foreach ($rows as $r) {
    $meta = [];
    foreach ($KEYS as $k) {
        $v = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $r->ID, $k
        ));
        if ($v !== null) $meta[$k] = base64_encode($v);   // raw bytes, transport-safe
    }
    $items[] = [
        'id'          => (int)$r->ID,
        'slug'        => $r->post_name,
        'type'        => $r->post_type,
        'status'      => $r->post_status,
        'content_md5' => $r->content_md5,
        'meta_b64'    => $meta,
    ];
}

echo json_encode([
    'generated' => gmdate('c'),
    'host'      => php_uname('n'),
    'count'     => count($items),
    'items'     => $items,
], JSON_UNESCAPED_SLASHES) . "\n";
