<?php
/**
 * archive-poc/bin/materializer.php
 *
 * Production materializer for the layout-standalone lane. Resolves a managed-CPT
 * post's render data ONCE, where the WP functions exist, into a flat blob the
 * standalone renderer reads with zero WP boot.
 *
 * This is the WRITE side of the strangler mirror. It is the exact inverse of
 * archive-poc/standalone/wp-shim.php (the read side): every key the shim reads,
 * this file resolves. The contract is pinned in
 * archive-poc/standalone/RENDER-STANDALONE-POC.md ("PostContext contract") and
 * the PoC blob archive-poc/standalone/blobs/article-jazz-bass.json.
 *
 * Blob shape (must equal the PoC blob):
 *   { "layout": <_lg_layout_v2 meta, verbatim>, "post_context": <flat identity> }
 *
 * Reuses lg-layout-v2's own classes (the plugin is active in WP):
 *   \LG\LayoutV2\Plugin::manages / ::load_layout   (managed-set + event flatten)
 *   \LG\LayoutV2\WpMedia::resolve                   (attachment → {url,alt,mime,sizes})
 *   \LG\LayoutV2\GateCta::OPTION                    (gate-cta copy option name)
 *
 * Used by:
 *   - api/v0/_materialize.php  (loopback save-hook endpoint, looth-dev pool)
 *   - bin/materialize-all.php  (one-pass backfill)
 *
 * Independence (design §): the materializer ONLY writes. It never reads a blob
 * back, never renders, never gates. The renderer ONLY reads. Keep them apart.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    fwrite(STDERR, "materializer.php must run inside WordPress (needs get_post et al.)\n");
    return;
}

/** Taxonomies the post-header surfaces (tier prominently + the bottom strip).
 *  Mirrors blocks/post-header/render.php's tier + bottom-taxes set. */
const LG_MAT_TERM_TAXONOMIES = ['tier', 'shared_category', 'article-type', 'post_tag', 'series'];

/** Author user-meta the post-header / post-footer read. NOT `author_image` —
 *  the avatar is pre-resolved into post_context.author.avatar_url, and leaving
 *  author_image absent makes the standalone block fall through to that URL
 *  (matches the live render). `description` is the bio fallback for author_about. */
const LG_MAT_AUTHOR_META_KEYS = [
    'author_about', 'description',
    'author_looth_group_profile', 'author_website', 'author_instagram',
    'author_facebook', 'author_youtube', 'author_linktree',
];

/** Sponsor brand-kit ACF fields (stored on the author user, prefix `user_<id>`)
 *  that the post-header "sponsor" variant reads via get_field(). Resolved here so
 *  the byte-identical block reads them from post_context.sponsor with no ACF at
 *  render. `brand-logo1` is resolved separately (attachment → {url}). */
const LG_MAT_SPONSOR_FIELDS = [
    'brand_name', 'brand_primary_color_1', 'brand_secondary_color_',
    'brand_third_color_header_color', 'brand_website', 'brand_instagram',
    'brand_facebook', 'brand_youtube', 'tlg_sponsor_page_url', 'brand_email', 'brand_tag',
];

/**
 * Build the blob for one post, or null if it should not have one (not a managed
 * post / no layout / not published). Callers treat null as "delete any blob".
 *
 * @return array{layout: array, post_context: array}|null
 */
function lg_materialize_build_blob(int $post_id): ?array {
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) return null;
    if (!class_exists('LG\\LayoutV2\\Plugin')) return null;          // lg-layout-v2 not active
    if (!\LG\LayoutV2\Plugin::manages($post)) return null;          // not managed / no v2 layout
    if ($post->post_status !== 'publish') return null;             // store mirrors the PUBLIC set

    // layout = the _lg_layout_v2 meta verbatim (events: synthesized + flattened
    // by load_layout — design §3, the events exception).
    $layout = \LG\LayoutV2\Plugin::load_layout($post_id);
    if (!is_array($layout)) return null;

    // ── Author ─────────────────────────────────────────────────────────────
    $author_id    = (int) $post->post_author;
    $author       = $author_id ? get_userdata($author_id) : null;
    $display_name = $author ? (string) $author->display_name : '';

    // Avatar: custom author_image attachment → get_avatar_url fallback. One URL
    // (the shim returns one for both header + footer). Single-source avatar
    // direction (coord §3) will later swap this for the profile-app spine URL.
    $avatar_url = '';
    $custom_avatar = (int) (get_user_meta($author_id, 'author_image', true) ?: 0);
    if ($custom_avatar > 0) {
        $avatar_url = (string) (wp_get_attachment_image_url($custom_avatar, 'medium') ?: '');
    }
    if ($avatar_url === '') {
        $avatar_url = (string) (get_avatar_url($author_id, ['size' => 192]) ?: '');
    }

    // Author archive URL — resolved THROUGH the same filter the live byline uses
    // (lg-layout-v2 points it at the archive-poc grid), so standalone == live.
    $archive_url = $author_id ? (string) get_author_posts_url($author_id) : '';
    $archive_url = (string) apply_filters('lg_layout_v2_author_archive_url', $archive_url, $author_id);

    $author_meta = [];
    foreach (LG_MAT_AUTHOR_META_KEYS as $k) {
        $v = trim((string) get_user_meta($author_id, $k, true));
        if ($v !== '') $author_meta[$k] = $v;
    }

    // ── Featured image ─────────────────────────────────────────────────────
    $thumb_id = (int) get_post_thumbnail_id($post_id);
    $featured = ['id' => 0, 'url' => '', 'alt' => ''];
    if ($thumb_id > 0) {
        $featured = [
            'id'  => $thumb_id,
            'url' => (string) (wp_get_attachment_image_url($thumb_id, 'full') ?: ''),
            'alt' => (string) (get_post_meta($thumb_id, '_wp_attachment_image_alt', true) ?: ''),
        ];
    }

    // ── Terms (tier chip + tag strip) ──────────────────────────────────────
    $terms = [];
    foreach (LG_MAT_TERM_TAXONOMIES as $tax) {
        $tobj = get_the_terms($post_id, $tax);
        if (!is_array($tobj)) continue;
        $rows = [];
        foreach ($tobj as $t) {
            if (!is_object($t)) continue;
            $link = get_term_link($t);
            $rows[] = [
                'name'    => (string) $t->name,
                'slug'    => (string) $t->slug,
                'term_id' => (int) $t->term_id,
                'link'    => is_wp_error($link) ? '#' : (string) $link,
            ];
        }
        if ($rows) $terms[$tax] = $rows;
    }

    // ── post_tier (gating) — first non-public slug, mirrors WpRenderer ─────
    $post_tier = '';
    foreach ((array) wp_get_object_terms($post_id, 'tier', ['fields' => 'slugs']) as $slug) {
        if (is_string($slug) && $slug !== '' && $slug !== 'public') { $post_tier = $slug; break; }
    }

    // ── Media map — every attachment the layout references + the featured img.
    //    Backed by WpMedia::resolve so the shape matches the shim exactly. ──
    $media = [];
    $ids = lg_materialize_collect_media_ids($layout);
    if ($thumb_id > 0) $ids[$thumb_id] = true;          // GateCta poster reads media[thumb_id]
    foreach (array_keys($ids) as $mid) {
        if ($mid <= 0) continue;
        $m = \LG\LayoutV2\WpMedia::resolve((int) $mid);
        // Defensive srcset filter: the dev clone reload left some attachments whose metadata
        // lists size-variant filenames that don't exist on disk (the `-1` collision mismatch).
        // Those bake into srcset as 404 candidates → the browser picks one → broken <img>.
        // Drop any size whose file is absent so only real candidates reach the blob.
        if (!empty($m['sizes']) && is_array($m['sizes'])) {
            $base = ($pth = get_attached_file((int) $mid)) ? dirname($pth) : '';
            if ($base !== '') {
                foreach ($m['sizes'] as $sz => $info) {
                    $fn = basename((string) ($info['url'] ?? ''));
                    if ($fn !== '' && !file_exists($base . '/' . $fn)) unset($m['sizes'][$sz]);
                }
            }
        }
        $media[(string) $mid] = $m;
    }

    // ── Site-global options (gate-cta copy + brand/dash snapshot) ──────────
    //    Snapshotted per-blob so the renderer is self-contained. A brand/dash
    //    change therefore needs a re-materialize pass (bin/materialize-all.php).
    $options = [
        \LG\LayoutV2\GateCta::OPTION => get_option(\LG\LayoutV2\GateCta::OPTION, []),
    ];
    if (defined('LG_LAYOUT_V2_BRAND_OPTION')) {
        $options[LG_LAYOUT_V2_BRAND_OPTION] = get_option(LG_LAYOUT_V2_BRAND_OPTION, []);
    }
    if (defined('LG_LAYOUT_V2_STYLE_OPTION')) {
        $options[LG_LAYOUT_V2_STYLE_OPTION] = get_option(LG_LAYOUT_V2_STYLE_OPTION, []);
    }

    // ── Sponsor brand kit (ACF on the author user) ─────────────────────────
    //    Only the post-header "sponsor" variant reads these; resolve once here so
    //    the byte-identical block renders standalone with no ACF. Logo pre-resolved
    //    to the {url:…} shape the block already handles. Null when the author has
    //    no brand kit (the common case) → shim get_field() returns null, block skips.
    $sponsor = null;
    if ($author_id > 0 && function_exists('get_field')) {
        $uid = 'user_' . $author_id;
        $sp  = [];
        foreach (LG_MAT_SPONSOR_FIELDS as $f) {
            $v = get_field($f, $uid);
            if (is_string($v) && $v !== '') $sp[$f] = $v;
        }
        $logo = get_field('brand-logo1', $uid);
        $logo_url = '';
        if (is_array($logo) && !empty($logo['url']))  $logo_url = (string) $logo['url'];
        elseif (is_numeric($logo) && (int) $logo > 0) $logo_url = (string) (wp_get_attachment_image_url((int) $logo, 'thumbnail') ?: '');
        if ($logo_url !== '') $sp['brand-logo1'] = ['url' => $logo_url];
        if ($sp) $sponsor = $sp;
    }

    // ── Related posts (footer carousel) — pre-resolved cards ───────────────
    //    Standalone can't run WP_Query/RelatedPosts at render, so bake the cards
    //    here using the same primary-category + CPT fallback the footer block uses.
    //    Up to 6; the block caps to its related_count at render.
    $related = [];
    if ($post_id > 0) {
        $cats   = get_the_terms($post_id, 'category');
        $cat_id = is_array($cats) && $cats ? (int) $cats[0]->term_id : 0;
        $rq = new WP_Query([
            'post_type'      => $post->post_type ?: 'post-imgcap',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'post__not_in'   => [$post_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'tax_query'      => $cat_id ? [['taxonomy' => 'category', 'terms' => [$cat_id]]] : [],
        ]);
        foreach (array_map('intval', (array) $rq->posts) as $rid) {
            if (!$rid) continue;
            $r_auth = (int) get_post_field('post_author', $rid);
            $related[] = [
                'url'         => (string) get_permalink($rid),
                'title'       => (string) get_the_title($rid),
                'img'         => (string) (get_the_post_thumbnail_url($rid, 'medium_large') ?: ''),
                'author_name' => $r_auth ? (string) get_the_author_meta('display_name', $r_auth) : '',
            ];
        }
    }

    $post_context = [
        'post_id'         => $post_id,
        'sponsor'         => $sponsor,
        'related'         => $related,
        'comments_count'  => (int) get_comments_number($post_id),
        'comments_open'   => (get_post_field('comment_status', $post_id) === 'open'),
        'title'         => (string) get_the_title($post_id),
        'permalink'     => (string) get_permalink($post_id),
        'date'          => (string) get_the_date('', $post_id),
        'post_tier'     => $post_tier,
        'bloginfo_name' => (string) get_bloginfo('name'),
        'author' => [
            'id'           => $author_id,
            'display_name' => $display_name,
            'avatar_url'   => $avatar_url,
            'archive_url'  => $archive_url,
            'meta'         => $author_meta,
        ],
        'featured_image' => $featured,
        'terms'          => $terms,
        'media'          => $media,
        'options'        => $options,
    ];

    return ['layout' => $layout, 'post_context' => $post_context];
}

/** Walk the layout collecting every attachment id it references. */
function lg_materialize_collect_media_ids(array $layout): array {
    $ids = [];
    $walk = function ($node) use (&$walk, &$ids): void {
        if (!is_array($node)) return;
        // image_id (image block), featured_image_id, file_id (download block).
        // Every attachment a block resolves at render must be pre-resolved into
        // the blob's `media` map — the standalone path has no WP to call.
        foreach (['image_id', 'featured_image_id', 'file_id'] as $k) {
            if (!empty($node[$k]) && is_numeric($node[$k])) $ids[(int) $node[$k]] = true;
        }
        // gallery block: image_ids is a flat array of attachment ids
        if (!empty($node['image_ids']) && is_array($node['image_ids'])) {
            foreach ($node['image_ids'] as $iid) {
                if (is_numeric($iid)) $ids[(int) $iid] = true;
            }
        }
        // Repeater/gallery rows that carry their own image_id.
        if (!empty($node['items']) && is_array($node['items'])) {
            foreach ($node['items'] as $it) {
                if (is_array($it) && !empty($it['image_id']) && is_numeric($it['image_id'])) {
                    $ids[(int) $it['image_id']] = true;
                }
            }
        }
        if (!empty($node['columns']) && is_array($node['columns'])) {
            foreach ($node['columns'] as $col) {
                if (!empty($col['blocks']) && is_array($col['blocks'])) {
                    foreach ($col['blocks'] as $b) $walk($b);
                }
            }
        }
        if (!empty($node['blocks']) && is_array($node['blocks'])) {
            foreach ($node['blocks'] as $b) $walk($b);
        }
    };
    foreach ($layout['blocks'] ?? [] as $b) $walk($b);
    return $ids;
}

/**
 * Materialize one post into the blob store. Builds the blob and upserts on
 * post_id; if the post no longer qualifies (unpublished / layout removed /
 * trashed), deletes any existing blob instead. Returns a small status array.
 */
function lg_materialize_upsert(PDO $db, int $post_id): array {
    $blob = lg_materialize_build_blob($post_id);
    if ($blob === null) {
        lg_materialize_delete($db, $post_id);
        return ['post_id' => $post_id, 'action' => 'delete', 'reason' => 'not-managed-or-unpublished'];
    }

    $post = get_post($post_id);
    $json = json_encode($blob, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['post_id' => $post_id, 'action' => 'error', 'reason' => 'json_encode failed'];
    }
    $checksum = hash('sha256', $json);

    $stmt = $db->prepare('
        INSERT INTO article_blobs (post_id, post_type, slug, blob, materialized_at, checksum)
        VALUES (:pid, :ptype, :slug, CAST(:blob AS jsonb), now(), :sum)
        ON CONFLICT (post_id) DO UPDATE SET
            post_type       = EXCLUDED.post_type,
            slug            = EXCLUDED.slug,
            blob            = EXCLUDED.blob,
            materialized_at = EXCLUDED.materialized_at,
            checksum        = EXCLUDED.checksum
    ');
    $stmt->execute([
        ':pid'   => $post_id,
        ':ptype' => (string) $post->post_type,
        ':slug'  => (string) ($post->post_name ?: ('p-' . $post_id)),
        ':blob'  => $json,
        ':sum'   => $checksum,
    ]);

    return ['post_id' => $post_id, 'action' => 'upsert', 'checksum' => $checksum, 'bytes' => strlen($json)];
}

/** Remove a post's blob (delete / trash / unpublish / un-manage). */
function lg_materialize_delete(PDO $db, int $post_id): void {
    $db->prepare('DELETE FROM article_blobs WHERE post_id = :pid')->execute([':pid' => $post_id]);
}

/**
 * Open the blob store (postgres `discovery`). The blob store is pg from day one
 * (it's new — no SQLite legacy to migrate, unlike content_item). Peer-auths as
 * the running unix user (looth-dev pool / `sudo -u looth-dev`), which holds the
 * write grants. Reuses config.php's single PDO factory.
 */
function lg_materialize_pdo(): PDO {
    if (!getenv('LG_ARCHIVE_POC_DSN')) {
        putenv('LG_ARCHIVE_POC_DSN=pgsql:host=/var/run/postgresql;dbname=looth');
    }
    $db = lg_archive_poc_pdo();   // from config.php (sets search_path=discovery,public for pgsql)
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        throw new RuntimeException('article_blobs store must be postgres (got '
            . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . '); set LG_ARCHIVE_POC_DSN to the pg DSN');
    }
    return $db;
}
