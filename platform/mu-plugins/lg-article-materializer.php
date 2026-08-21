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
/**
 * QUEUE a re-bake for the end of this request. It used to fire immediately, and
 * that was wrong in a way nothing could see until #179 gave a member a control
 * that depends on it.
 *
 * ══ WHY THIS QUEUES INSTEAD OF SENDING (2026-08-21, #179) ═══════════════════
 *
 * The de-dupe below is necessary — save hooks fire several times in one edit —
 * but combined with an INLINE send it silently pinned the blob to whatever the
 * post looked like at the FIRST hook of the request. Measured order for a
 * front-end compose save:
 *
 *   1. ACF form-front calls wp_update_post   -> wp_after_insert_post
 *        -> dispatch(id:upsert) SENT. The endpoint boots its own WordPress
 *           (~100-150ms) and reads the post AS IT IS NOW.
 *   2. acf/save_post 25  lg_fc_promote_draft -> wp_mail() to the moderator
 *   3. acf/save_post 26  the paywall toggle  -> wp_set_object_terms(tier)
 *        -> dispatch(id:upsert) SWALLOWED by $done. No second bake, ever.
 *
 * So the member ticks "behind the paywall", the term IS written, and the
 * standalone page keeps rendering the old gating. Not a race on the member path
 * either — step 2 sends mail before step 3, so on live it is deterministic.
 * That is the control-that-looks-right-and-does-nothing class.
 *
 * Queuing to `shutdown` fixes it at the root: every writer in the request has
 * finished before anything is sent, and the de-dupe still guarantees ONE bake
 * per (post, action) per request rather than one per hook.
 *
 * ⚠️ THE POST TYPE IS RESOLVED HERE, AT QUEUE TIME, NOT AT FLUSH TIME. That is
 * load-bearing for deletes: before_delete_post queues while the row still
 * exists, and by `shutdown` get_post_type() would return false and the filter
 * would DROP the delete — leaving a blob for a post that no longer exists.
 *
 * This is also the likeliest explanation for the "first publish gets no
 * standalone page" gap (dev2 post 73544): the first dispatch of the request
 * fires while the row is still an auto-draft, the endpoint correctly answers
 * "not-managed-or-unpublished", and the post-promotion dispatch is then
 * swallowed by the same de-dupe. Not chased here beyond saying so.
 */
function lg_materializer_dispatch(int $post_id, string $action = 'upsert'): void {
    if ($post_id <= 0) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    // De-dupe: one bake per (post, action) per request. Save hooks fire more than
    // once (meta saves, term sets) in a single edit.
    static $queued = [];
    $key = $post_id . ':' . $action;
    if (isset($queued[$key])) return;

    // Only managed CPTs (delete is allowed through for any of them; the endpoint
    // decides). Read NOW — see the warning above about deletes.
    $ptype = get_post_type($post_id);
    if ($ptype === false || !in_array($ptype, lg_materializer_managed_types(), true)) return;

    $queued[$key] = ['post_id' => $post_id, 'action' => $action];

    // Registered once, on the first queued item, so a request that queues nothing
    // adds no hook at all.
    static $hooked = false;
    if (!$hooked) {
        $hooked = true;
        add_action('shutdown', static function () use (&$queued) {
            foreach ($queued as $job) {
                lg_materializer_send($job['post_id'], $job['action']);
            }
            $queued = [];
        }, 99);
    }
}
}

if (!function_exists('lg_materializer_send')) {
/** The actual loopback POST. Split out so the queue above has one thing to call
 *  and so a caller that genuinely wants to bake NOW (a CLI backfill) still can. */
function lg_materializer_send(int $post_id, string $action = 'upsert'): void {
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
        || $meta_key === '_thumbnail_id'   // featured image (bridge sets it LAST on publish) -> re-bake with the hero
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
