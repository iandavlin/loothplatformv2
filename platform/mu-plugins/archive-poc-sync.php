<?php
/**
 * Plugin Name: Archive POC Sync
 * Description: Posts {post_id, action} to /archive-api/v0/_sync on relevant WP
 *              edit events. Non-blocking. Loopback only. Dev/POC scope.
 * Version:     0.1.0
 *
 * Hooks: save_post, before_delete_post, trashed_post, untrashed_post,
 *        bbp_new_topic, bbp_new_reply, edit_post, deleted_post.
 *
 * Notes:
 *   - Calls https://127.0.0.1/archive-api/v0/_sync with Host: dev.loothgroup.com
 *     so traffic stays on the box and the loopback allow-rule fires.
 *   - sslverify is off (self-signed-friendly path; dev only).
 *   - timeout=1s, blocking=false → save_post returns immediately.
 */

if (!defined('ABSPATH')) exit;

// Register edit_archive_poc capability on the administrator role.
// Stored in wp_options (wp_user_roles), so the add_cap() only writes once.
add_action('init', function () {
    $role = get_role('administrator');
    if ($role && !isset($role->capabilities['edit_archive_poc'])) {
        $role->add_cap('edit_archive_poc');
    }
}, 1);

if (!function_exists('archive_poc_sync_dispatch')) {
function archive_poc_sync_dispatch(int $post_id, string $action = 'upsert'): void {
    if ($post_id <= 0) return;
    // Skip autosaves and revisions — useless reindexes.
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    $payload = wp_json_encode(['post_id' => $post_id, 'action' => $action]);
    wp_remote_post('https://127.0.0.1/archive-api/v0/_sync', [
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

// Upsert on any edit/publish.
add_action('save_post',      function ($post_id) { archive_poc_sync_dispatch((int)$post_id, 'upsert'); }, 99, 1);
add_action('edit_post',      function ($post_id) { archive_poc_sync_dispatch((int)$post_id, 'upsert'); }, 99, 1);
add_action('trashed_post',   function ($post_id) { archive_poc_sync_dispatch((int)$post_id, 'delete'); }, 99, 1);
add_action('untrashed_post', function ($post_id) { archive_poc_sync_dispatch((int)$post_id, 'upsert'); }, 99, 1);
add_action('deleted_post',   function ($post_id) { archive_poc_sync_dispatch((int)$post_id, 'delete'); }, 99, 1);

// bbPress: new topic / new reply. Replies fold into their parent topic in the
// indexer, so passing the reply ID is enough — indexer.php resolves it.
add_action('bbp_new_topic', function ($topic_id) { archive_poc_sync_dispatch((int)$topic_id, 'upsert'); }, 99, 1);
add_action('bbp_new_reply', function ($reply_id) { archive_poc_sync_dispatch((int)$reply_id, 'upsert'); }, 99, 1);
add_action('bbp_edit_topic',function ($topic_id) { archive_poc_sync_dispatch((int)$topic_id, 'upsert'); }, 99, 1);
add_action('bbp_edit_reply',function ($reply_id) { archive_poc_sync_dispatch((int)$reply_id, 'upsert'); }, 99, 1);

// ---------------------------------------------------------------------------
// /wp-json/looth/v1/activity — page-lead activity strip endpoint.
//
// Returns recent site activity with privacy filtering. Stickies come first,
// then wp_bp_activity descending. Activities targeting gated content
// (post tier != public) are dropped for unauthenticated requests.
// Cursor-paginated: ?before=<unix_ts>&limit=<int>. Cached 30s.
// ---------------------------------------------------------------------------

const LG_ACTIVITY_TYPES = [
    // type → action-verb shown in the card
    'new_blog_post-imgcap'           => 'published an article',
    'new_blog_post-regular'          => 'published an article',
    'new_blog_post'                  => 'published an article',
    'new_blog_user-post-imgcap'      => 'shared a post',
    'new_blog_post-type-videos'      => 'posted a video',
    'new_blog_loothprint'            => 'shared a loothprint',
    'new_blog_loothcuts'             => 'shared a loothcut',
    'new_blog_document'              => 'shared a document',
    'new_blog_international-loothi'  => 'added an event',
    'new_blog_member-benefit'        => 'posted a member benefit',
    'new_blog_sponsor-post'          => 'shared sponsor news',
    'new_blog_sponsor-product'       => 'added a sponsor product',
    'new_blog_useful_links'          => 'shared a link',
    'new_blog_coe-questions'         => 'asked a question',
    'new_blog_shorty'                => 'posted a Shorty',
    'bbp_topic_create'               => 'started a discussion',
    'new_member'                     => 'joined the community',
];

add_action('rest_api_init', function () {
    register_rest_route('looth/v1', '/activity', [
        'methods'  => 'GET',
        'callback' => 'lg_activity_route',
        'permission_callback' => '__return_true',
        'args' => [
            'limit'  => ['default' => 15, 'sanitize_callback' => 'absint'],
            'before' => ['default' => 0,  'sanitize_callback' => 'absint'],
        ],
    ]);
    register_rest_route('looth/v1', '/author/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'lg_author_route',
        'permission_callback' => '__return_true',
        'args' => [
            'id' => ['validate_callback' => fn($v) => is_numeric($v)],
        ],
    ]);
});

/**
 * Public author profile blob for archive-poc's author-filter banner.
 * GET /wp-json/looth/v1/author/<id>
 *   { id, name, slug, avatar_url, profile_url, socials: { instagram, ... } }
 * Socials pulled from ACF brand_* user meta + user_url fallback.
 * Cached 5min per user (transient).
 */
function lg_author_route(WP_REST_Request $req) {
    $id = (int) $req['id'];
    if ($id <= 0) return new WP_Error('bad_id', 'invalid id', ['status' => 400]);

    $ck = "lg_author_$id";
    $cached = get_transient($ck);
    if (is_array($cached)) return rest_ensure_response($cached);

    $u = get_user_by('id', $id);
    if (!$u) return new WP_Error('not_found', 'user not found', ['status' => 404]);

    // BP profile URL if available (members directory link), else author archive.
    $profile_url = function_exists('bp_core_get_user_domain')
        ? bp_core_get_user_domain($id)
        : get_author_posts_url($id);

    // Socials: ACF author_* user-meta fields (mirrors the v2 post-footer's
    // "Links" section, single source of truth for the author's own URLs).
    $platforms = ['website', 'instagram', 'facebook', 'youtube', 'linktree'];
    $socials = [];
    foreach ($platforms as $p) {
        $v = trim((string) get_user_meta($id, "author_$p", true));
        if ($v !== '') $socials[$p] = esc_url_raw($v);
    }

    // Avatar: prefer the author_image attachment override, else default get_avatar.
    $avatar_url   = '';
    $avatar_att   = (int) (get_user_meta($id, 'author_image', true) ?: 0);
    if ($avatar_att > 0) $avatar_url = wp_get_attachment_image_url($avatar_att, 'thumbnail') ?: '';
    if ($avatar_url === '') $avatar_url = get_avatar_url($id, ['size' => 96]);

    // Bio + looth profile URL — useful for the banner header.
    $bio = trim((string) (get_user_meta($id, 'author_about', true) ?: get_user_meta($id, 'description', true) ?: ''));
    $looth_profile = trim((string) (get_user_meta($id, 'author_looth_group_profile', true) ?: ''));

    $payload = [
        'id'             => $id,
        'name'           => $u->display_name ?: $u->user_login,
        'slug'           => $u->user_nicename,
        'avatar_url'     => $avatar_url,
        'profile_url'    => $profile_url,
        'looth_profile'  => $looth_profile !== '' ? esc_url_raw($looth_profile) : '',
        'bio'            => $bio,
        'socials'        => (object) $socials,
    ];

    set_transient($ck, $payload, 5 * MINUTE_IN_SECONDS);
    return rest_ensure_response($payload);
}

function lg_activity_route(WP_REST_Request $req) {
    global $wpdb;
    $limit  = max(1, min(50, (int) $req->get_param('limit')));
    $before = (int) $req->get_param('before');
    // Member detection by VALIDATING the logged-in cookie (HMAC signature +
    // expiry) — NOT by cookie-NAME presence. A junk `wordpress_logged_in_*`
    // cookie used to flip the audience to "member" and leak gated / private-group
    // rows to anyone who set one (infra lane 2026-06-13, reproduced end-to-end).
    // wp_validate_auth_cookie() returns the user id on a genuine cookie, else false.
    $is_member = ( wp_validate_auth_cookie( '', 'logged_in' ) !== false );

    // Cache key includes audience + cursor + limit
    $cache_key = 'lg_act_' . ($is_member ? 'm' : 'p') . "_{$before}_{$limit}";
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        $resp = rest_ensure_response($cached);
        $resp->header('X-LG-Activity-Audience', $is_member ? 'member' : 'public');
        return $resp;
    }

    $items = [];

    // ---- Stickies first (only on the initial page, not cursor follow-ups) ----
    if ($before === 0) {
        // (a) BuddyBoss activity pins, stored as group-meta bb_pinned_post (one
        //     activity ID per group). Anon visitors only see pins from public
        //     groups; members see all (per BB privacy convention).
        $pin_rows = $wpdb->get_results($wpdb->prepare("
            SELECT a.*, g.status AS group_status
            FROM {$wpdb->prefix}bp_groups_groupmeta gm
            JOIN {$wpdb->prefix}bp_groups       g ON g.id = gm.group_id
            JOIN {$wpdb->prefix}bp_activity     a ON a.id = CAST(gm.meta_value AS UNSIGNED)
            WHERE gm.meta_key = 'bb_pinned_post' AND CAST(gm.meta_value AS UNSIGNED) > 0
              AND a.hide_sitewide = 0 AND a.is_spam = 0 AND a.status = 'published'
              AND (%d = 1 OR g.status = 'public')
            ORDER BY a.date_recorded DESC
        ", $is_member ? 1 : 0), ARRAY_A);
        foreach ($pin_rows as $r) {
            $target_id = (int) $r['secondary_item_id'];
            if (!$target_id) continue;
            $row = lg_activity_hydrate_post($target_id, $is_member, /*sticky*/ true, $r);
            if ($row) $items[] = $row;
        }

        // (b) CPT-level stickies: any post with _lg_sticky postmeta truthy.
        //     Lets editors pin any post regardless of CPT, not just core 'post'.
        $cpt_sticky_ids = $wpdb->get_col("
            SELECT pm.post_id
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts}    p ON p.ID = pm.post_id AND p.post_status = 'publish'
            WHERE pm.meta_key = '_lg_sticky' AND pm.meta_value NOT IN ('','0')
            ORDER BY p.post_date DESC
        ");
        foreach ($cpt_sticky_ids as $sid) {
            $row = lg_activity_hydrate_post((int)$sid, $is_member, /*sticky*/ true);
            if ($row) $items[] = $row;
        }
    }

    // ---- BP activity stream ----
    $types = array_keys(LG_ACTIVITY_TYPES);
    $type_ph = implode(',', array_fill(0, count($types), '%s'));
    $params  = $types;
    $where_extra = '';
    if ($before > 0) {
        $where_extra .= ' AND a.date_recorded < FROM_UNIXTIME(%d)';
        $params[] = $before;
    }
    // Over-fetch then trim — many recent rows get discarded (gated content for
    // anon, dedupe, and especially activities whose target post was deleted —
    // dev sees heavy test-post churn). A shallow over-fetch can leave the strip
    // nearly empty, so scan deep with a generous floor + bounded cap.
    $sql_limit = max($limit * 3 + 5, 120);
    if ($sql_limit > 250) $sql_limit = 250;
    $params[] = $sql_limit;

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT a.id, a.user_id, a.component, a.type, a.item_id, a.secondary_item_id,
               a.content, a.primary_link, a.date_recorded, a.privacy,
               g.status AS group_status
        FROM {$wpdb->prefix}bp_activity a
        LEFT JOIN {$wpdb->prefix}bp_groups g ON g.id = a.item_id AND a.component = 'groups'
        WHERE a.hide_sitewide = 0
          AND a.is_spam = 0
          AND a.status = 'published'
          AND a.type IN ($type_ph)
          $where_extra
        ORDER BY a.date_recorded DESC
        LIMIT %d
    ", $params), ARRAY_A);

    $seen_targets = [];
    foreach ($items as $it) $seen_targets[$it['target']['url'] ?? ''] = true;

    foreach ($rows as $r) {
        if (count($items) >= ($limit + (count($items) - count(array_filter($items, fn($x) => ($x['kind'] ?? '') !== 'sticky'))))) break;
        if (count($items) >= $limit) break;

        // Group activities in private/hidden groups → drop for anon
        if ($r['component'] === 'groups' && $r['group_status']) {
            if (!$is_member && $r['group_status'] !== 'public') continue;
        }
        // Per-row privacy override
        if ($r['privacy'] !== 'public' && !$is_member) continue;

        // Hydrate target by type
        $row = null;
        if (str_starts_with($r['type'], 'new_blog_') || $r['type'] === 'bbp_topic_create') {
            $target_id = (int) $r['secondary_item_id'];
            if (!$target_id) continue;
            $row = lg_activity_hydrate_post($target_id, $is_member, false, $r);
        } elseif ($r['type'] === 'new_member') {
            $row = lg_activity_hydrate_join((int)$r['user_id'], $r);
        }
        if (!$row) continue;
        $key = $row['target']['url'] ?? ($row['kind'] . ':' . $row['id']);
        if (isset($seen_targets[$key])) continue;
        $seen_targets[$key] = true;
        $items[] = $row;
    }

    $payload = ['items' => $items, 'limit' => $limit, 'before' => $before];
    set_transient($cache_key, $payload, 30);
    $resp = rest_ensure_response($payload);
    $resp->header('X-LG-Activity-Audience', $is_member ? 'member' : 'public');
    return $resp;
}

/** First BuddyBoss media attachment image for a post — forum posts attach
 *  photos as bp_media (post_content is often empty). Mirrors the archive-poc
 *  indexer's first_bp_media_thumb(). */
function lg_activity_first_bp_media_thumb(int $post_id): ?string {
    global $wpdb;
    $ids_csv = (string) get_post_meta($post_id, 'bp_media_ids', true);
    if ($ids_csv === '') return null;
    $ids = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $ids_csv)))));
    if (!$ids) return null;
    $ph  = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "SELECT attachment_id FROM {$wpdb->prefix}bp_media
            WHERE id IN ($ph) ORDER BY FIELD(id, $ph) LIMIT 1";
    $att_id = (int) $wpdb->get_var($wpdb->prepare($sql, ...array_merge($ids, $ids)));
    if (!$att_id) return null;
    return wp_get_attachment_image_url($att_id, 'medium_large') ?: null;
}

/** Build an activity row from a post target. Returns null if filtered out. */
function lg_activity_hydrate_post(int $post_id, bool $is_member, bool $is_sticky, ?array $activity_row = null): ?array {
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return null;

    // Tier from the 'tier' taxonomy (same logic as the indexer).
    global $wpdb;
    $slugs = $wpdb->get_col($wpdb->prepare("
        SELECT t.slug FROM {$wpdb->terms} t
        JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
        JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE tt.taxonomy = 'tier' AND tr.object_id = %d
    ", $post_id));
    $tier = 'public';
    foreach ($slugs as $s) {
        if ($s === 'looth-pro' || $s === 'pro')  $tier = 'pro';
        elseif (($s === 'looth-lite' || $s === 'lite') && $tier !== 'pro') $tier = 'lite';
    }
    // Privacy: drop gated for anon
    if (!$is_member && $tier !== 'public') return null;

    $user_id = $activity_row ? (int)$activity_row['user_id'] : (int)$post->post_author;
    $user    = $user_id ? get_userdata($user_id) : null;
    $action  = $is_sticky
        ? 'pinned'
        : (LG_ACTIVITY_TYPES[$activity_row['type'] ?? ''] ?? 'shared');

    // Target kind mapping (matches indexer)
    $kind_map = [
        'post-imgcap'=>'article','post-regular'=>'article','post'=>'article','user-post-imgcap'=>'article',
        'post-type-videos'=>'video','loothprint'=>'loothprint','loothcuts'=>'loothprint','document'=>'loothprint',
        'event'=>'event','ajde_events'=>'event','tribe_events'=>'event','international-loothi'=>'event',
        'topic'=>'topic','member-spotlight'=>'profile','member-directory'=>'profile',
        'member-benefit'=>'benefit','sponsor-product'=>'benefit',
    ];
    $target_kind = $kind_map[$post->post_type] ?? 'post';

    // Forum topics link to the bb-mirror reader / The Hub (/hub/<forum-slug>/<topic-slug>/),
    // NOT the legacy BuddyBoss group-forum permalink. bb-mirror's forum.slug and
    // topic.slug were backfilled from bbPress post_name, and a bbPress topic's
    // post_parent IS its forum — so we build the URL directly here, no shim.
    // Verified slug parity (e.g. topic blades-for-sale → forum sell-sell-sell).
    $target_url = get_permalink($post_id) ?: '';
    if ($post->post_type === 'topic') {
        $forum_id   = (int) $post->post_parent;
        $forum_slug = $forum_id ? (string) get_post_field('post_name', $forum_id) : '';
        $topic_slug = (string) $post->post_name;
        if ($forum_slug !== '' && $topic_slug !== '') {
            $target_url = '/hub/' . $forum_slug . '/' . $topic_slug . '/';
        }
    } elseif ($post->post_type === 'forum') {
        // A forum surfaced in the feed (e.g. a pinned forum) → bb-mirror forum page.
        $forum_slug = (string) $post->post_name;
        if ($forum_slug !== '') $target_url = '/hub/' . $forum_slug . '/';
    }

    $img = null;
    $tid = (int) get_post_thumbnail_id($post_id);
    if ($tid > 0) $img = wp_get_attachment_image_url($tid, 'medium_large') ?: null;
    if ($img) {
        // Same dev R2-graveyard guard as the archive-poc indexer
        $local = str_replace(['https://'.$_SERVER['HTTP_HOST'].'/','http://'.$_SERVER['HTTP_HOST'].'/'], ABSPATH, $img);
        if ($local !== $img && (!is_file(explode('?', $local, 2)[0]) || filesize(explode('?', $local, 2)[0]) === 0)) $img = null;
    }

    // Media facade: if the body links a YouTube video and there's no featured
    // image, use the (free, instant) YouTube thumbnail. The card renders as an
    // image card with a play overlay — no iframe, no oEmbed call → stays fast.
    $yt_id = null;
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,15})~i', (string) $post->post_content, $ytm)) {
        $yt_id = $ytm[1];
        if (!$img) $img = 'https://i.ytimg.com/vi/' . $yt_id . '/hqdefault.jpg';
    }

    // Forum posts attach photos as BuddyBoss media (post_content is often empty),
    // so resolve bp_media_ids → attachment image before falling back to inline <img>.
    if (!$img) {
        $bb = lg_activity_first_bp_media_thumb($post_id);
        if ($bb) $img = $bb;
    }

    // Still no image? Pull the first inline image from the body — some posts
    // embed photos inline instead. Skip emoji/avatar/spacer sprites. Then apply
    // the same R2-graveyard guard as the indexer so a missing dev-local file
    // doesn't render broken.
    if (!$img && preg_match_all('~<img[^>]+src=["\']([^"\']+)["\']~i', (string) $post->post_content, $imm)) {
        foreach ($imm[1] as $cand) {
            if (!preg_match('~^https?://~i', $cand)) continue;
            if (preg_match('~(emoji|smilie|gravatar|/s\.w\.org/|spacer|blank\.gif|wpforms|icon)~i', $cand)) continue;
            $img = $cand;
            break;
        }
        if ($img) {
            $local = str_replace(['https://'.$_SERVER['HTTP_HOST'].'/','http://'.$_SERVER['HTTP_HOST'].'/'], ABSPATH, $img);
            if ($local !== $img && (!is_file(explode('?', $local, 2)[0]) || filesize(explode('?', $local, 2)[0]) === 0)) $img = null;
        }
    }

    $excerpt = trim((string) $post->post_excerpt);
    if ($excerpt === '') $excerpt = wp_strip_all_tags($post->post_content);
    // Collapse all whitespace runs to single spaces, decode entities.
    $excerpt = html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Drop bare media URLs — they're represented by the thumbnail / link now,
    // and a raw URL as body text looks broken on the card.
    $excerpt = preg_replace('~\b(?:https?://)?(?:www\.|m\.)?(?:youtu\.be|youtube\.com|instagram\.com|reddit\.com|redd\.it)/\S*~i', '', $excerpt);
    $excerpt = preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt;
    $excerpt = trim($excerpt);
    if (mb_strlen($excerpt) > 200) $excerpt = mb_substr($excerpt, 0, 197) . '…';

    return [
        'kind'   => $is_sticky ? 'sticky' : ($post->post_type === 'topic' ? 'topic' : 'post'),
        'id'     => 'p' . $post_id,
        'when'   => $activity_row ? strtotime($activity_row['date_recorded']) : strtotime($post->post_date_gmt),
        'tier'   => $tier,
        'user'   => $user ? [
            'name'        => $user->display_name ?: $user->user_login,
            'avatar_url'  => get_avatar_url($user_id, ['size' => 64]) ?: null,
            'profile_url' => function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($user_id) : home_url('/members/' . $user->user_login . '/'),
        ] : null,
        'action' => $action,
        'target' => [
            'title' => $post->post_title ?: '(untitled)',
            'url'   => $target_url,
            'kind'  => $target_kind,
        ],
        'image_url' => $img,
        'yt_id'     => $yt_id,   // → card shows YT thumbnail + play overlay (facade)
        'excerpt'   => $excerpt,
    ];
}

function lg_activity_hydrate_join(int $user_id, array $row): ?array {
    $u = get_userdata($user_id);
    if (!$u) return null;
    return [
        'kind' => 'join',
        'id'   => 'u' . $user_id,
        'when' => strtotime($row['date_recorded']),
        'tier' => 'public',
        'user' => [
            'name'        => $u->display_name ?: $u->user_login,
            'avatar_url'  => get_avatar_url($user_id, ['size' => 64]) ?: null,
            'profile_url' => function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($user_id) : home_url('/members/' . $u->user_login . '/'),
        ],
        'action' => 'joined the community',
        'target' => null,
        'image_url' => null,
        'excerpt'   => '',
    ];
}
