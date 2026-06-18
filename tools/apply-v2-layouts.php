<?php
/**
 * tools/apply-v2-layouts.php — apply the conversion-carry bundle ON LIVE.
 * Partner of export-v2-layouts.php; cut Phase B step (see LIVE-DEPLOY-PLAN §3c).
 *
 * For each bundle item, against the LIVE post with the SAME ID:
 *   - GUARD: live slug must equal the bundle slug (and type must match) —
 *     mismatch means the ID belongs to something else: SKIP + report, never
 *     write. (IDs are safe in principle — all bundle posts predate the dev
 *     snapshot — this guard catches the in-principle-impossible.)
 *   - Write the raw meta bytes via $wpdb (NOT update_post_meta: the layout
 *     is a JSON string or a PHP-serialized array and must round-trip
 *     byte-exact; update_post_meta would re-serialize).
 *   - content_md5 mismatch (post edited on live since the snapshot) is
 *     REPORTED (review list: did the live edit conflict with the layout?)
 *     but the meta still applies — layout meta does not overwrite content.
 *
 * Dry-run by default. Usage on live:
 *   wp eval-file tools/apply-v2-layouts.php v2-layouts-bundle.json [--apply]
 * After applying: re-run the archive-poc materializer so standalone renders
 * (article_blobs) regenerate, then bump the lg-layout-v2 asset epoch.
 */

if (!defined('ABSPATH')) { fwrite(STDERR, "run via wp eval-file\n"); exit(2); }

global $wpdb;
$bundleFile = $args[0] ?? '';
$apply      = in_array('--apply', $args, true);
if (!$bundleFile || !is_readable($bundleFile)) { fwrite(STDERR, "bundle json path required\n"); exit(2); }

$bundle = json_decode((string)file_get_contents($bundleFile), true);
if (!is_array($bundle['items'] ?? null)) { fwrite(STDERR, "bad bundle\n"); exit(2); }

$n = ['applied' => 0, 'missing' => 0, 'slug_mismatch' => 0, 'content_drift' => 0];

foreach ($bundle['items'] as $it) {
    $p = $wpdb->get_row($wpdb->prepare(
        "SELECT ID, post_name, post_type, MD5(post_content) AS md5 FROM {$wpdb->posts} WHERE ID = %d", $it['id']));
    if (!$p) { $n['missing']++; echo "MISSING   #{$it['id']} {$it['slug']} (no such ID on live)\n"; continue; }
    if ($p->post_name !== $it['slug'] || $p->post_type !== $it['type']) {
        $n['slug_mismatch']++;
        echo "MISMATCH  #{$it['id']} bundle={$it['type']}/{$it['slug']} live={$p->post_type}/{$p->post_name} — SKIPPED\n";
        continue;
    }
    if ($p->md5 !== $it['content_md5']) {
        $n['content_drift']++;
        echo "DRIFT     #{$it['id']} {$it['slug']} (content edited on live since snapshot — review render)\n";
    }
    if ($apply) {
        foreach ($it['meta_b64'] as $key => $b64) {
            $raw = base64_decode($b64);
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $it['id'], $key));
            if ($existing) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => $raw], ['meta_id' => $existing]);
            } else {
                $wpdb->insert($wpdb->postmeta, ['post_id' => $it['id'], 'meta_key' => $key, 'meta_value' => $raw]);
            }
        }
        clean_post_cache($it['id']);
    }
    $n['applied']++;
}

printf("\n%s: ok=%d missing=%d slug-mismatch=%d content-drift=%d (of %d in bundle)\n",
    $apply ? 'APPLIED' : 'DRY-RUN', $n['applied'], $n['missing'], $n['slug_mismatch'], $n['content_drift'],
    count($bundle['items']));
echo "Gap report = live posts of converted types CREATED after the bundle date — list with:\n";
echo "  wp post list --post_type=post-type-videos,post-imgcap,loothprint --field=ID --after='" . ($bundle['generated'] ?? '?') . "'\n";
