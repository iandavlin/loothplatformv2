<?php
/**
 * Plugin Name: LG Article Materializer (sync)
 * Description: On publish/update/delete of a managed-CPT post, POSTs {post_id,
 *              action} to /archive-api/v0/_materialize so the standalone-render
 *              blob is rebuilt. Non-blocking, loopback only. Layout-standalone lane.
 * Version:     0.1.0
 *
 * Mirrors archive-poc-sync.mu-plugin.php (the search-index sync) but targets the
 * blob store, and only for posts lg-layout-v2 manages. Independent of the search
 * sync: the materializer writes blobs, the indexer writes the search index; a
 * post save fans out to both, each non-blocking.
 *
 * The endpoint re-checks Plugin::manages()/publish status authoritatively and
 * upserts-or-deletes accordingly, so this dispatcher can be liberal — it just
 * filters to the managed CPT post-types to avoid pinging the endpoint on every
 * forum reply / unrelated save.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('lg_materializer_managed_types')) {
/** Managed CPT set — from lg-layout-v2 when available (single source of truth),
 *  else a hardcoded fallback that mirrors Plugin::MANAGED_CPTS. */
function lg_materializer_managed_types(): array {
    if (class_exists('LG\\LayoutV2\\Plugin')) {
        return (array) \LG\LayoutV2\Plugin::MANAGED_CPTS;
    }
    return ['post-imgcap', 'post-type-videos', 'sponsor-post', 'event'];
}
}

if (!function_exists('lg_materializer_dispatch')) {
function lg_materializer_dispatch(int $post_id, string $action = 'upsert'): void {
    if ($post_id <= 0) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    // De-dupe: one dispatch per (post, action) per request. save hooks can fire
    // more than once (meta saves, term sets) in a single edit.
    static $done = [];
    $key = $post_id . ':' . $action;
    if (isset($done[$key])) return;
    $done[$key] = true;

    // Only managed CPTs (delete is allowed through for any of them; the endpoint
    // decides). post_type is read fresh — a trash flips status, not type.
    $ptype = get_post_type($post_id);
    if ($ptype === false || !in_array($ptype, lg_materializer_managed_types(), true)) return;

    $payload = wp_json_encode(['post_id' => $post_id, 'action' => $action]);
    wp_remote_post('https://127.0.0.1/archive-api/v0/_materialize', [
        'method'    => 'POST',
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => false,
        'headers'   => [
            'Host'         => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'Content-Type' => 'application/json',
        ],
        'body' => $payload,
    ]);
}
}

/* Upsert on publish/update. wp_after_insert_post fires at the END of the insert,
   AFTER post meta (incl. _lg_layout_v2) is written — so the blob is built from
   the just-saved layout, not a stale one. */
add_action('wp_after_insert_post', function ($post_id, $post = null, $update = null, $post_before = null) {
    lg_materializer_dispatch((int) $post_id, 'upsert');
}, 99, 4);

/* THE FE-EDIT WIRE. The front-end editor (EditorRest) saves the layout via a bare
   update_post_meta(_lg_layout_v2) — which does NOT fire wp_after_insert_post. Without
   this, an inline FE edit writes to WP but never re-bakes the blob, so the standalone
   page (what front-end users see) shows stale content. Re-bake on the meta write
   itself. Dispatcher de-dupes per (post,action)/request, so a full save that fires
   both hooks still bakes once. */
$lg_mat_meta_rebake = function ($meta_id, $post_id, $meta_key) {
    if ($meta_key === '_lg_layout_v2'
        || (defined('LG_LAYOUT_V2_META_KEY') && $meta_key === LG_LAYOUT_V2_META_KEY)) {
        lg_materializer_dispatch((int) $post_id, 'upsert');
    }
};
add_action('updated_post_meta', $lg_mat_meta_rebake, 99, 3);
add_action('added_post_meta',   $lg_mat_meta_rebake, 99, 3);

/* A `tier` term change alters gating (post_tier + the tier chip) without
   touching post meta — re-materialize so the blob's gating stays correct. */
add_action('set_object_terms', function ($object_id, $terms, $tt_ids, $taxonomy) {
    if ($taxonomy !== 'tier') return;
    lg_materializer_dispatch((int) $object_id, 'upsert');
}, 99, 4);

/* Removal paths → delete the blob. */
add_action('trashed_post',       function ($post_id) { lg_materializer_dispatch((int) $post_id, 'delete'); }, 99, 1);
add_action('before_delete_post', function ($post_id) { lg_materializer_dispatch((int) $post_id, 'delete'); }, 99, 1);
add_action('untrashed_post',     function ($post_id) { lg_materializer_dispatch((int) $post_id, 'upsert'); }, 99, 1);

/* ── Dash theme snapshot ─────────────────────────────────────────────────
 * The standalone renderer has no WP, so it can't read the dash brand/style
 * options live. Snapshot them to a JSON file it reads (dash-theme.json). Keep
 * it fresh whenever the dash saves brand palette or block styles. File is
 * looth-dev:www-data 664 → www-data (wp-admin) can rewrite it. */
/* Env-parameterized so the write target resolves on each box. Live sets
 * LG_DASH_THEME_SNAPSHOT (env var, or a define() in wp-config) to the standalone
 * renderer's dash-theme.json path on that host; dev leaves it unset and falls
 * back to the repo path below, so behavior is unchanged on dev. */
if (!defined('LG_DASH_THEME_SNAPSHOT')) {
    $__lg_dash_snap_env = getenv('LG_DASH_THEME_SNAPSHOT');
    define('LG_DASH_THEME_SNAPSHOT',
        ($__lg_dash_snap_env !== false && $__lg_dash_snap_env !== '')
            ? $__lg_dash_snap_env
            : '/home/ubuntu/projects/archive-poc/standalone/dash-theme.json');
    unset($__lg_dash_snap_env);
}
function lg_materializer_write_theme_snapshot() {
    $snap = [
        'brand'  => get_option('lg_layout_v2_brand_palette', []),
        'styles' => get_option('lg_layout_v2_block_styles', []),
        'epoch'  => (int) get_option('lg_layout_v2_cache_epoch', 0),
    ];
    @file_put_contents(LG_DASH_THEME_SNAPSHOT, json_encode($snap, JSON_UNESCAPED_SLASHES));
}
add_action('update_option_lg_layout_v2_block_styles',   'lg_materializer_write_theme_snapshot', 99);
add_action('update_option_lg_layout_v2_brand_palette',  'lg_materializer_write_theme_snapshot', 99);
add_action('update_option_lg_layout_v2_cache_epoch',    'lg_materializer_write_theme_snapshot', 99);
