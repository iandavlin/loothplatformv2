<?php
/**
 * /forums-poc/ and /forums-poc/<slug>/ — activity feed (v2).
 *
 * Features:
 *  - Sort bar: new (default) / old / hot
 *  - Featured image thumbnail on cards (LATERAL join on attachment table)
 *  - Card redesign: OP excerpt + last 3 replies threaded beneath OP,
 *    with accordion toggle for > 3 replies.
 *  - Forum header with title, "Activity" label, and optional bg image.
 *
 * Sort is parsed from REQUEST_URI query string the same way forum_slug
 * and offset are parsed — nginx alias drops QUERY_STRING but REQUEST_URI
 * is intact; index.php already rebuilt $_GET from it before we arrive.
 */

declare(strict_types=1);
require __DIR__ . '/../_chrome.php';

$db         = bb_mirror_db();

// Build forum cat-map once for data-cat on feed cards.
$_cat_map_rows = $db->query("
    SELECT id, slug, parent_forum_id FROM forum
     WHERE visibility = 'public' AND status IN ('open','closed')
")->fetchAll();
$_forum_cat_map = bb_mirror_build_cat_map($_cat_map_rows);
$forum_slug = (string)($_GET['forum_slug'] ?? '');
$raw_offset = max(0, (int)($_GET['offset'] ?? 0));
$card_limit = 18;   // Smaller first page (Ian 2026-06-11 "load on button push"): fewer
                    // cards upfront cuts DOM/Style-Layout/TBT; infinite-scroll loads the
                    // rest in 18-card batches ($has_next/$next_offset below, unchanged).
$fetch_size = 300; // fetch extra to cover collapse loss

if (!function_exists('lg_cover_src')) {
    /**
     * Route a cover image through the on-the-fly resizer (/img.php) so the feed
     * serves display-sized WebP instead of full-res originals (3024px phone
     * photos were ~9 MB/page). Only rewrites our own /wp-content/uploads/ URLs;
     * external URLs pass through. Also normalises http:// uploads to a
     * same-origin request (kills the mixed-content warning). Ian 2026-06-11.
     */
    function lg_cover_src(?string $url, int $w = 800): ?string
    {
        if (!$url) {
            return $url;
        }
        if (preg_match('#/wp-content/uploads/(.+)$#', $url, $m)) {
            return '/img.php?s=' . rawurlencode($m[1]) . '&w=' . $w;
        }
        return $url;
    }
}

if (!function_exists('lg_cover_srcset')) {
    /**
     * srcset/sizes for a feed cover: the browser picks 400w for the ~381px
     * desktop slot and phones at 1x, 800w for retina/wide — same image, same
     * crop, right resolution (craft gate IMG-OVERSIZE; Ian 6/12 go). Only for
     * URLs lg_cover_src routed through /img.php; external URLs emit nothing.
     */
    function lg_cover_srcset(?string $resized): string
    {
        if (!$resized || !str_starts_with($resized, '/img.php?')) return '';
        $u400 = preg_replace('/&w=\d+$/', '&w=400', $resized);
        $u800 = preg_replace('/&w=\d+$/', '&w=800', $resized);
        return ' srcset="' . htmlspecialchars($u400, ENT_QUOTES) . ' 400w, '
                           . htmlspecialchars($u800, ENT_QUOTES) . ' 800w"'
             . ' sizes="(max-width: 640px) 100vw, 400px"';
    }
}

if (!function_exists('lg_cover_dims')) {
    /**
     * width/height attrs for a feed cover <img> so the browser reserves the
     * box before the photo arrives. Unsized lazy covers grew cards 200-500px
     * as each image loaded, shoving content + re-balancing the mosaic (the
     * Hub scroll-jump, Buck 6/11 — his hub-nojump.js 280px-placeholder shim
     * retires once these land). Dims = getimagesize on the uploads source,
     * scaled to the img.php w= target; img.php never upscales, so width is
     * min(w, original). Reads the R2 uploads MOUNT directly (allow_other) —
     * the /var/www/dev/wp-content path img.php uses is 2770 looth-dev:loothdevs
     * and this pool runs as bb-mirror, which can't traverse it; same files
     * (uploads symlinks to the mount). Repoint at cut alongside img.php's
     * UPLOADS const. Memoized per request; external (non-uploads) URLs emit
     * nothing and degrade exactly as before.
     */
    function lg_cover_dims(?string $url, int $w = 800): string
    {
        static $memo = [];
        if (!$url) return '';
        $clean = preg_replace('/[?#].*$/', '', $url);
        if (!preg_match('#/wp-content/uploads/(.+)$#', $clean, $m)) return '';
        $rel = $m[1];
        // Original [w,h], resolved at most ONCE per image: per-request memo
        // backed by a tmpfs cache (lg_cover_dims_resolve), so getimagesize()
        // touches the R2 mount only on a cold image — not once per card per
        // render. That per-card mount read was the hub's #1 server cost
        // (FPM slowlog 6/13: lg_cover_dims realpath+getimagesize, ~1s/page).
        if (!array_key_exists($rel, $memo)) $memo[$rel] = lg_cover_dims_resolve($rel);
        $dim = $memo[$rel];
        if (!$dim) return '';
        $tw = min($w, $dim[0]);
        $th = (int)round($dim[1] * $tw / $dim[0]);
        return $th > 0 ? ' width="' . $tw . '" height="' . $th . '"' : '';
    }
}

if (!function_exists('lg_cover_dims_resolve')) {
    /**
     * Original [w,h] for an uploads-relative path, or null. tmpfs-cached so the
     * getimagesize() through the R2 mount runs only on a cold image. Dimensions
     * never change for a given uploads URL (a new image is a new URL), so the
     * cache is TTL-only with NO per-render stat — a stat/realpath would itself
     * be a FUSE→R2 round-trip, the exact cost we're removing. Mirrors the whoami
     * tmpfs-cache idiom in config.php. Path-containment check stays; it now runs
     * once per image instead of once per card per render. [] = cached "no dims".
     */
    function lg_cover_dims_resolve(string $rel): ?array
    {
        $cacheFile = '/dev/shm/bb-imgdims-' . sha1($rel) . '.json';
        if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
            $d = json_decode((string)file_get_contents($cacheFile), true);
            return (is_array($d) && isset($d[0], $d[1])) ? [(int)$d[0], (int)$d[1]] : null;
        }
        $val  = null;
        $base = realpath('/mnt/loothgroup-uploads-dev');
        $real = realpath('/mnt/loothgroup-uploads-dev/' . urldecode($rel));
        if ($real !== false && $base !== false
            && strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0) {
            $info = @getimagesize($real);
            if ($info && (int)$info[0] > 0 && (int)$info[1] > 0) {
                $val = [(int)$info[0], (int)$info[1]];
            }
        }
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($val ?? [])) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $cacheFile);
        }
        return $val;
    }
}

if (!function_exists('lg_cover_loading_attrs')) {
    /**
     * Lazy-loading the FIRST feed cover delays the page's LCP element behind
     * the lazy-load machinery (Lighthouse flagged it; LCP 5-9s on mobile).
     * First cover paints with high priority, the next two (desktop shows ~3
     * columns above the fold) load eagerly, everything below stays lazy.
     * Infinite-scroll batches restart the counter — harmless, those covers
     * are being inserted into view anyway. Perf lane 2026-06-11.
     */
    function lg_cover_loading_attrs(): string
    {
        static $n = 0;
        $n++;
        if ($n === 1) return 'loading="eager" fetchpriority="high"';
        if ($n <= 3) return 'loading="eager"';
        return 'loading="lazy"';
    }
}

// ?fid=<id> disambiguates duplicate-slug forums (e.g. two 'finish' forums).
$fid = 0;
if (preg_match('/[?&]fid=(\d+)/', $_SERVER['REQUEST_URI'] ?? '', $m)) {
    $fid = (int)$m[1];
}

// Sort: random (front-door default) | new | old | hot
// The site-wide /hub/ front door defaults to "Random" — a popularity-weighted shuffle
// that surfaces old/popular content (Ian/Buck 2026-06-07). A scoped single-forum view
// keeps newest-first so a specific forum reads chronologically.
$sort_param   = strtolower(trim((string)($_GET['sort'] ?? '')));
$default_sort = ($forum_slug !== '' || $fid > 0) ? 'new' : 'random';
// Persist the picked sort across visits (Ian 2026-06-10): an explicit ?sort=
// writes a 1-year cookie; a bare /hub/ load reads it back, so Newest/Trending/
// Random survives navigation and hard refresh. Random's SEED still re-rolls
// each visit (it is only pinned across one visit's infinite scroll).
$lg_valid_sorts = ['new', 'old', 'hot', 'random'];
if (in_array($sort_param, $lg_valid_sorts, true)) {
    if (($_COOKIE['lg_hub_sort'] ?? '') !== $sort_param) {
        setcookie('lg_hub_sort', $sort_param,
            ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => true]);
    }
} else {
    $lg_saved_sort = strtolower(trim((string)($_COOKIE['lg_hub_sort'] ?? '')));
    $sort_param = in_array($lg_saved_sort, $lg_valid_sorts, true) ? $lg_saved_sort : $default_sort;
}
// Random uses a per-visit seed so the weighted shuffle is STABLE across infinite-scroll
// pages (same seed -> identical order -> coherent offset paging) and re-rolls on a fresh
// visit. The seed is carried forward in the "load older" URL below.
$rand_seed = 0;
if ($sort_param === 'random') {
    $rand_seed = (isset($_GET['seed']) && ctype_digit((string)$_GET['seed']))
        ? (int)$_GET['seed']
        : random_int(1, 2000000000);
}
// Hot scores divide by hours-since-NOW(), so the order DRIFTS between one
// infinite-scroll fetch and the next — offset paging over a moving order
// repeats/skips cards. Freeze the clock per scroll session (carried forward
// in the "load older" URL exactly like the random seed).
$hot_now = 0;
if ($sort_param === 'hot') {
    $hot_now = (isset($_GET['hnow']) && ctype_digit((string)$_GET['hnow']))
        ? (int)$_GET['hnow']
        : time();
}

$scoped_forum = null;

if ($forum_slug !== '' || $fid > 0) {
    if ($fid > 0) {
        $fs = $db->prepare("
            SELECT id, slug, title, description, parent_forum_id, forum_type, header_image_url
              FROM forum
             WHERE id = ? AND visibility = 'public' AND status IN ('open','closed')
             LIMIT 1
        ");
        $fs->execute([$fid]);
    } else {
        $fs = $db->prepare("
            SELECT id, slug, title, description, parent_forum_id, forum_type, header_image_url
              FROM forum
             WHERE slug = ? AND visibility = 'public' AND status IN ('open','closed')
             LIMIT 1
        ");
        $fs->execute([$forum_slug]);
    }
    $scoped_forum = $fs->fetch();
    if (!$scoped_forum) {
        http_response_code(404);
        bb_mirror_chrome_header('Not Found');
        echo '<div class="page"><p class="bb-mirror__empty">Forum not found.</p></div>';
        bb_mirror_chrome_footer();
        return;
    }
    // Normalise forum_slug for any downstream uses (sort bar, pagination, etc.)
    $forum_slug = (string)$scoped_forum['slug'];
}

// Child forums for subforum pills (only when scoped to a forum that has children)
$child_forums = [];
if ($scoped_forum) {
    $cf_stmt = $db->prepare("
        SELECT id, slug, title
          FROM forum
         WHERE parent_forum_id = ? AND visibility = 'public' AND status IN ('open','closed')
         ORDER BY menu_order ASC
    ");
    $cf_stmt->execute([(int)$scoped_forum['id']]);
    $child_forums = $cf_stmt->fetchAll();
}

// A forum is postable only if it's a LEAF: a real 'forum' (not a 'category'
// container) with no child sub-forums. Category/placeholder parents just hold
// subforums — you post to the subforum, not the container.
$is_postable_forum = $scoped_forum
    && empty($child_forums)
    && (($scoped_forum['forum_type'] ?? 'forum') !== 'category');

// Parent forum (for the header breadcrumb) + sibling forums (for leaf nav).
$parent_forum   = null;
$sibling_forums = [];
if ($scoped_forum && !empty($scoped_forum['parent_forum_id'])) {
    $pf = $db->prepare("SELECT id, slug, title, parent_forum_id
                          FROM forum WHERE id = ? AND visibility = 'public' LIMIT 1");
    $pf->execute([(int)$scoped_forum['parent_forum_id']]);
    $parent_forum = $pf->fetch() ?: null;

    // On a leaf, show the parent's children (this forum's siblings) as the pill row.
    if (empty($child_forums)) {
        $sf = $db->prepare("SELECT id, slug, title
                              FROM forum
                             WHERE parent_forum_id = ? AND visibility = 'public'
                               AND status IN ('open','closed')
                             ORDER BY menu_order ASC");
        $sf->execute([(int)$scoped_forum['parent_forum_id']]);
        $sibling_forums = $sf->fetchAll();
    }
}

// Pills: category page → its children; leaf page → its siblings (active = self).
$pill_forums    = !empty($child_forums) ? $child_forums : $sibling_forums;
$pill_active_id = (!empty($child_forums) || !$scoped_forum) ? 0 : (int)$scoped_forum['id'];

// Slug-frequency map for ?fid= disambiguation on pills + parent link (four forum
// slugs collide, incl. folk-bluegrass-irish-old-time-instruments).
$slug_freq = [];
foreach ($db->query("SELECT slug FROM forum WHERE visibility = 'public'")->fetchAll() as $r) {
    $slug_freq[$r['slug']] = ($slug_freq[$r['slug']] ?? 0) + 1;
}

// -- Build ORDER BY clause --
// Every sort carries a unique trailing tiebreaker (t.id): equal sort keys
// (bulk-imported timestamps, all-zero hot scores) otherwise come back in
// arbitrary per-query order and offset paging repeats/skips those cards.
switch ($sort_param) {
    case 'old':
        $order_by = 'ORDER BY t.last_active_at ASC NULLS LAST, t.id ASC';
        break;
    case 'hot':
        // GREATEST guards rows newer than the frozen clock (negative age
        // would take POW of a negative base → SQL error).
        $order_by = "ORDER BY (t.reply_count::float / POW(GREATEST(EXTRACT(EPOCH FROM (to_timestamp(:hot_now) - t.last_active_at))/3600, 0) + 2, 1.5)) DESC NULLS LAST, t.last_active_at DESC NULLS LAST, t.id DESC";
        break;
    case 'random':
        // Seeded popularity-weighted shuffle (Efraimidis–Spirakis key u^(1/w)):
        // u = deterministic uniform from hashtextextended(topic id, :rand_seed);
        // weight = (reply_count+1)^2. Recency-independent (old threads surface),
        // stable for a given seed. Scoped topics carry no tier gating.
        $order_by = "ORDER BY power(
            (hashtextextended('topic:' || t.id::text, :rand_seed) & 9223372036854775807)::double precision / 9223372036854775807.0,
            1.0 / power(t.reply_count + 1, 2)
        ) DESC";
        break;
    default: // new
        $order_by = 'ORDER BY t.last_active_at DESC NULLS FIRST, t.id DESC';
        break;
}

// -- ORDER BY for the unified (site-wide) feed --
// Same intent as $order_by but references the UNION's output aliases (the union
// merges forum topics + content, so it can't reference t.* directly). "Hot" uses
// reply_count + like_count as the engagement numerator so liked content (0 forum
// replies) can still surface, not just busy threads.
switch ($sort_param) {
    case 'old':
        // "old" sorts by CREATION time. "new" (Recent) sorts by LAST ACTIVITY:
        // a fresh reply bumps its discussion back to the top of the feed (Ian 6/16,
        // overriding the 2026-06-10 no-bump ruling). Hot uses the engagement decay.
        // card_type+topic_id tiebreaker: imported rows share timestamps and the
        // union has no globally-unique id, so ties paginate unstably without it.
        $union_order_by = 'ORDER BY created_at ASC NULLS LAST, card_type ASC, topic_id ASC';
        break;
    case 'hot':
        // Frozen :hot_now clock (see $hot_now above) + GREATEST guard + unique
        // tiebreaker — hot is the worst offset-paging offender: every
        // 0-engagement card scores exactly 0 and ties with all the others.
        $union_order_by = "ORDER BY ((reply_count + like_count)::float / POW(GREATEST(EXTRACT(EPOCH FROM (to_timestamp(:hot_now) - event_time))/3600, 0) + 2, 1.5)) DESC NULLS LAST, event_time DESC NULLS LAST, card_type ASC, topic_id DESC";
        break;
    case 'random':
        // Front-door Random: seeded shuffle over the unified feed with a GENTLE
        // popularity edge. u = uniform from hashtextextended(card_type||':'||id,
        // :rand_seed); key = u^(1/weight). Weight was (replies+likes+1)^2 — a
        // 10-reply discussion got weight 121 vs content's ~1, so discussions
        // statistically buried every CPT card on page one ("Random is only doing
        // discussions", Ian 2026-06-10). Now weight = 1 + ln(1+engagement)/5:
        // 0-engagement cards draw uniform, a 30-reply thread gets ~1.7 — a mild
        // lift, not a monopoly, so Random is a true mix of all CPTs + discussions
        // (both viewports; same query serves mobile). Stable per seed for
        // coherent infinite-scroll paging.
        // The locked-teaser 0.25 penalty is GONE (Buck/Ian 6/11: gated content
        // surfaces "same as normal" as lock-overlay teasers that drive signup —
        // the penalty buried every gated card off page 1 of the default sort,
        // which read as "the hub lock disappeared").
        $union_order_by = "ORDER BY power(
            (hashtextextended(card_type || ':' || topic_id::text, :rand_seed) & 9223372036854775807)::double precision / 9223372036854775807.0,
            1.0 / (1.0 + ln(1 + reply_count + like_count) / 5.0)
        ) DESC";
        break;
    default: // new
        $union_order_by = 'ORDER BY event_time DESC NULLS LAST, card_type ASC, topic_id DESC';
        break;
}

// -- Control sidebar: active filter selection (site-wide /hub/ only) --
require_once __DIR__ . '/_filter-rail.php'; // pulls in _hub-filters.php
require_once __DIR__ . '/../_anon-scrub.php'; // logged-out contact scrub (Ian 2026-06-10)
$hub_filters = hub_filters_parse();
$hub_muted   = hub_mute_parse();

// -- Content tiers: TEASER model (Ian 6/7) --
// Content is tier-gated. The Hub now SHOWS all content tiers (so gated items
// appear as locked teasers with an upgrade/login overlay — Archive parity) and
// gates per-viewer at render time. We compute the viewer's max entitled rank here;
// downstream the feed/counts/tree run over ALL tiers ($content_tiers below).
// Leak-safety: the union selects only teaser-safe columns for content (title,
// excerpt, thumb, metadata — NO body/embed/download URL), and the gated render
// path suppresses the excerpt + inline play, so a gated card carries no payload.
// Ladder public<lite<pro; ADMINS bypass (max rank). Fails open to public.
$LG_TIER_RANK     = ['public' => 0, 'lite' => 1, 'pro' => 2];
$viewer_tiers     = hub_content_tiers();
$viewer_tier_rank = 0;
foreach ($viewer_tiers as $vt) $viewer_tier_rank = max($viewer_tier_rank, $LG_TIER_RANK[$vt] ?? 0);
$GLOBALS['LG_HUB_VIEWER_RANK'] = $viewer_tier_rank;
// Display set = every known content tier (teasers for the ones above the viewer).
$content_tiers = ['public', 'lite', 'pro'];

// Sticky-mute toggle: flip the cookie + 302 back to the feed (no JS, headers
// not yet sent — chrome only outputs inside bb_mirror_chrome_header()).
$hub_mute_cookie = function (array $m): void {
    setcookie('hub_mute', hub_mute_serialize($m), [
        'expires'  => time() + 31536000, 'path' => '/',
        'secure'   => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
};
if (isset($_GET['mute_toggle'])) {
    $parts = explode(':', (string)$_GET['mute_toggle'], 2);
    if (count($parts) === 2 && in_array($parts[0], ['t', 'c', 'l'], true) && $parts[1] !== '') {
        [$mfacet, $mkey] = $parts;

        if ($mfacet === 'c') {
            // A category mute cascades to every leaf, so each subforum can still
            // be toggled individually afterward. We assume "mute the whole thing":
            // if all leaves are already muted, the click un-mutes them all.
            [$mt_tree] = hub_category_tree($db, $content_tiers, $_forum_cat_map);
            $leaf_keys = [];
            foreach ($mt_tree as $cp) {
                if ($cp['key'] === $mkey) { $leaf_keys = array_column($cp['leaves'], 'key'); break; }
            }
            if ($leaf_keys) {
                $all_muted = !array_diff($leaf_keys, $hub_muted['leaves']);
                $hub_muted['leaves'] = $all_muted
                    ? array_values(array_diff($hub_muted['leaves'], $leaf_keys))
                    : array_values(array_unique(array_merge($hub_muted['leaves'], $leaf_keys)));
                if (!$all_muted) {  // just muted → drop any active selection of it
                    $hub_filters['cats']   = array_values(array_diff($hub_filters['cats'], [$mkey]));
                    $hub_filters['leaves'] = array_values(array_diff($hub_filters['leaves'], $leaf_keys));
                }
            } else {  // leafless (content-only) category — plain cat toggle
                $hub_muted = hub_mute_apply_toggle($hub_muted, 'c', $mkey);
                if (in_array($mkey, $hub_muted['cats'], true)) {
                    $hub_filters['cats'] = array_values(array_diff($hub_filters['cats'], [$mkey]));
                }
            }
        } else {  // type or single leaf — plain toggle
            $hub_muted = hub_mute_apply_toggle($hub_muted, $mfacet, $mkey);
            $fkey = ['t' => 'types', 'l' => 'leaves'][$mfacet];
            if (in_array($mkey, $hub_muted[$fkey], true)) {  // just muted → drop selection
                $hub_filters[$fkey] = array_values(array_diff($hub_filters[$fkey] ?? [], [$mkey]));
            }
        }

        $hub_mute_cookie($hub_muted);
    }
    header('Location: ' . html_entity_decode(hub_url($hub_filters, $sort_param)));
    return;
}

// "Reset all" clears the sticky mute cookie too (not just the filter params).
if (isset($_GET['mute_reset'])) {
    $hub_muted = ['types' => [], 'cats' => [], 'leaves' => []];
    $hub_mute_cookie($hub_muted);
    header('Location: ' . html_entity_decode(hub_url($hub_filters, $sort_param)));
    return;
}

// Mutual exclusion, the other direction: an active filter selection wins over a
// mute. If something is both filtered-to AND muted, drop it from the muted set
// (and persist) — so selecting a muted type/category/leaf un-mutes it.
$__mute_before = hub_mute_serialize($hub_muted);
$hub_muted['types']  = array_values(array_diff($hub_muted['types'],  $hub_filters['types']  ?? []));
$hub_muted['cats']   = array_values(array_diff($hub_muted['cats'],   $hub_filters['cats']   ?? []));
$hub_muted['leaves'] = array_values(array_diff($hub_muted['leaves'], $hub_filters['leaves'] ?? []));
if (hub_mute_serialize($hub_muted) !== $__mute_before) {
    $hub_mute_cookie($hub_muted);
}

// -- Resolve scope into forum_ids array (for header image query) --
$scope_ids = null; // null = site-wide

if ($scoped_forum) {
    $scope_stmt = $db->prepare("
        WITH RECURSIVE scope AS (
          SELECT id FROM forum WHERE id = ?
          UNION ALL
          SELECT f.id FROM forum f
            JOIN scope s ON f.parent_forum_id = s.id
           WHERE f.visibility = 'public'
        )
        SELECT id FROM scope
    ");
    $scope_stmt->execute([(int)$scoped_forum['id']]);
    $scope_ids = array_column($scope_stmt->fetchAll(), 'id');
}

// -- Header image: explicit override (admin pencil) wins, else auto.
//    Scoped forum -> forum.header_image_url; site-wide -> sync_state.site_header_image.
$header_image_url = null;
$header_image_explicit = false;
if ($scoped_forum && !empty($scoped_forum['header_image_url'])) {
    $header_image_url = (string)$scoped_forum['header_image_url'];
    $header_image_explicit = true;
} elseif ($scope_ids !== null && count($scope_ids) > 0) {
    $scope_id_list = '{' . implode(',', array_map('intval', $scope_ids)) . '}';
    $hi_stmt = $db->prepare("
        SELECT a.url FROM forums.attachment a
          JOIN forums.topic t ON t.id = a.parent_id AND a.parent_kind = 'topic'
         WHERE t.forum_id = ANY(:fids::int[])
           AND a.url IS NOT NULL
         ORDER BY a.id DESC LIMIT 1
    ");
    $hi_stmt->bindValue(':fids', $scope_id_list);
    $hi_stmt->execute();
    $hi_row = $hi_stmt->fetch();
    $header_image_url = $hi_row ? $hi_row['url'] : null;
} else {
    // site-wide: explicit admin override (All Forums pencil) wins, else most recent.
    $sw = $db->query("SELECT value FROM sync_state WHERE key = 'site_header_image'")->fetch();
    if ($sw && trim((string)$sw['value']) !== '') {
        $header_image_url = (string)$sw['value'];
        $header_image_explicit = true;
    } else {
        $hi_row = $db->query("SELECT url FROM forums.attachment WHERE url IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch();
        $header_image_url = $hi_row ? $hi_row['url'] : null;
    }
}

// -- Topic query with LATERAL join for first attachment image --
if ($scoped_forum) {
    $topic_sql = "
        WITH RECURSIVE scope AS (
          SELECT id FROM forum WHERE id = :seed_id
          UNION ALL
          SELECT f.id FROM forum f
            JOIN scope s ON f.parent_forum_id = s.id
           WHERE f.visibility = 'public'
        )
        SELECT
            t.id                                                       AS topic_id,
            t.slug                                                     AS topic_slug,
            t.title                                                    AS topic_title,
            t.content_html                                             AS content_html,
            LEFT(t.content_text, 240)                                  AS op_snippet,
            COALESCE(t.featured_image_url, first_img.url, reply_img.url) AS card_image,
            t.tags                                                     AS tags,
            t.reply_count,
            t.last_active_at                                           AS event_time,
            COALESCE(t.author_name, 'Anonymous')                       AS author_name,
            t.author_id,
            p.slug                                                     AS author_slug,
            COALESCE(p.discussion_visibility, 'member')                AS discussion_visibility,
            t.is_anon::int                                             AS is_anon,
            t.created_at,
            t.forum_id,
            f.slug                                                     AS forum_slug,
            f.title                                                    AS forum_title,
            pf.slug                                                    AS parent_forum_slug,
            pf.title                                                   AS parent_forum_title
          FROM topic t
          JOIN forum f  ON f.id = t.forum_id
          LEFT JOIN forum pf ON pf.id = f.parent_forum_id
          LEFT JOIN person p ON p.id = t.author_id
          LEFT JOIN LATERAL (
            -- OP cover: prefer a materialized attachment, else the first inline
            -- <img> in the body (fluentform/inline images never hit forums.attachment).
            SELECT COALESCE(
                     (SELECT url FROM forums.attachment
                       WHERE parent_kind = 'topic' AND parent_id = t.id
                       ORDER BY id ASC LIMIT 1),
                     (regexp_match(t.content_html, '<img[^>]+src=\"([^\"]+)\"'))[1]
                   ) AS url
          ) first_img ON true
          -- Fallback: if the topic itself has no image, surface the first image
          -- posted in any of its replies as the card's featured image.
          LEFT JOIN LATERAL (
            SELECT a.url
              FROM forums.attachment a
              JOIN forums.reply r ON r.id = a.parent_id
             WHERE a.parent_kind = 'reply'
               AND r.topic_id = t.id
               AND r.status = 'publish'
               AND a.url IS NOT NULL
             ORDER BY r.created_at ASC, a.id ASC
             LIMIT 1
          ) reply_img ON true
         WHERE t.status = 'publish'
           AND t.forum_id IN (SELECT id FROM scope)
           AND t.forum_id NOT IN (3876)
         $order_by
         LIMIT :fetch_size OFFSET :raw_offset
    ";
    $stmt = $db->prepare($topic_sql);
    $stmt->bindValue(':seed_id',    (int)$scoped_forum['id'], PDO::PARAM_INT);
    $stmt->bindValue(':fetch_size', $card_limit,              PDO::PARAM_INT);
    $stmt->bindValue(':raw_offset', $raw_offset,              PDO::PARAM_INT);
    if ($sort_param === 'random') $stmt->bindValue(':rand_seed', $rand_seed, PDO::PARAM_INT);
    if ($sort_param === 'hot')    $stmt->bindValue(':hot_now',   $hot_now,   PDO::PARAM_INT);
    $stmt->execute();
} else {
    // Site-wide /hub/ = the UNIFIED feed: forum topics ∪ content (discovery).
    // Two schemas, one DB, one query — no data duplication. Column lists must
    // align 1:1 for UNION ALL; the content branch fills topic-only columns with
    // typed NULLs and vice-versa. card_type drives the renderer branch.
    $tier_ph = [];
    foreach ($content_tiers as $i => $tv) $tier_ph[] = ':ctier' . $i;
    $tier_in = $tier_ph ? implode(',', $tier_ph) : "''"; // never empty -> no rows

    // Server-side AND filter (Type ∩ Category ∩ Author) + sticky mute exclusions,
    // merged into one outer WHERE on the union's output.
    [$hub_cat_tree, $hub_leaf_reg] = hub_category_tree($db, $content_tiers, $_forum_cat_map);
    $hub_clabels = hub_content_cat_labels($db);
    [$f_clauses, $hub_binds]  = hub_filter_where($hub_filters, $_forum_cat_map, $hub_clabels, $hub_leaf_reg);
    [$m_clauses, $mute_binds] = hub_mute_clause($hub_muted, $_forum_cat_map, $hub_clabels, $hub_leaf_reg);
    $all_clauses = array_merge($f_clauses, $m_clauses);
    $hub_binds   = $hub_binds + $mute_binds;

    // Saved view (?saved=1) — constrain the union to the viewer's ☆ saves. Saved
    // lives in discovery (saved_posts), so we resolve the id set via the my-saved
    // loopback (same WP-cookie identity as the ☆ hydrate) and WHERE the union on it:
    // topics by id, content by 'cpt:id'. No saves / anon → FALSE → an empty feed
    // (an empty Saved list IS empty). Counts/facets stay full — saved is a view, not
    // a facet recount.
    if (!empty($hub_filters['saved'])) {
        $sv = hub_viewer_saved_set();
        $sv_or = [];
        if ($sv['topics']) {
            $ph = [];
            foreach ($sv['topics'] as $i => $tid) { $k = ":svt$i"; $ph[] = $k; $hub_binds[$k] = (int)$tid; }
            $sv_or[] = "(u.card_type = 'topic' AND u.topic_id IN (" . implode(',', $ph) . "))";
        }
        if ($sv['content']) {
            $ph = [];
            foreach ($sv['content'] as $i => $ck) { $k = ":svc$i"; $ph[] = $k; $hub_binds[$k] = $ck; }
            $sv_or[] = "(u.card_type = 'content' AND (u.content_cpt || ':' || u.topic_id) IN (" . implode(',', $ph) . "))";
        }
        $all_clauses[] = $sv_or ? '(' . implode(' OR ', $sv_or) . ')' : 'FALSE';
    }

    $hub_where   = $all_clauses ? 'WHERE ' . implode(' AND ', $all_clauses) : '';

    // Facet counts + stash so the chrome renders the control rail into the
    // left-nav slot (Option A: rail replaces forum nav on the site-wide feed).
    $hub_facets = hub_facet_counts($db, $content_tiers, $_forum_cat_map);
    $hub_leaf_labels = [];
    foreach ($hub_cat_tree as $_p) foreach ($_p['leaves'] as $_lf) $hub_leaf_labels[$_lf['key']] = $_lf['label'];
    $GLOBALS['__bb_hub_rail'] = ['facets' => $hub_facets, 'tree' => $hub_cat_tree, 'filters' => $hub_filters, 'muted' => $hub_muted, 'sort' => $sort_param];

    // Unified full-text search (q) — an AND dimension across BOTH worlds, applied
    // per-branch (FTS columns: topic.search_doc, content_item.tsv). websearch_to_
    // tsquery is forgiving on bad input. Empty q -> no clause.
    $hub_q = (string)($hub_filters['q'] ?? '');
    $q_topic = $q_content = '';
    if ($hub_q !== '') {
        $q_topic   = "AND t.search_doc @@ websearch_to_tsquery('english', :hq_t)";
        $q_content = "AND c.tsv @@ websearch_to_tsquery('english', :hq_c)";
    }

    // Hot-sort engagement: rank on the LIVE reaction store (discovery.card_reactions,
    // the one count source per the reaction contract), NOT the stale denormalized
    // content_item.like_count. Pre-aggregate once (card_reactions is net-new/small;
    // idx (post_type,item_id,slug)) and LEFT JOIN per branch. Counts ALL reactions on
    // the target as the engagement signal — any reaction now feeds hotness, and topics
    // (was hardcoded 0) finally contribute. Display counts are unchanged (rendered
    // separately via lg_card_reactions_for_items).
    $topic_sql = "
      WITH rx AS (
        SELECT post_type, item_id, COUNT(*) AS n
          FROM discovery.card_reactions GROUP BY post_type, item_id
      )
      SELECT * FROM (
        SELECT
            'topic'::text                                              AS card_type,
            t.id                                                       AS topic_id,
            t.slug                                                     AS topic_slug,
            t.title                                                    AS topic_title,
            t.content_html                                             AS content_html,
            LEFT(t.content_text, 240)                                  AS op_snippet,
            COALESCE(t.featured_image_url, first_img.url, reply_img.url) AS card_image,
            t.tags                                                     AS tags,
            t.reply_count,
            COALESCE(trx.n, 0)                                         AS like_count,
            t.last_active_at                                           AS event_time,
            COALESCE(t.author_name, 'Anonymous')                       AS author_name,
            t.author_id,
            p.slug                                                     AS author_slug,
            COALESCE(p.discussion_visibility, 'member')                AS discussion_visibility,
            t.created_at,
            t.forum_id,
            f.slug                                                     AS forum_slug,
            f.title                                                    AS forum_title,
            pf.slug                                                    AS parent_forum_slug,
            pf.title                                                   AS parent_forum_title,
            t.is_anon::int                                             AS is_anon,
            NULL::text                                                 AS content_kind,
            NULL::text                                                 AS content_tier,
            NULL::text                                                 AS content_url,
            NULL::int                                                  AS duration_min,
            false                                                      AS has_download,
            NULL::text                                                 AS content_cpt,
            NULL::text                                                 AS content_forum_label,
            NULL::text                                                 AS content_subforum_label
          FROM topic t
          JOIN forum f  ON f.id = t.forum_id
          LEFT JOIN forum pf ON pf.id = f.parent_forum_id
          LEFT JOIN person p ON p.id = t.author_id
          LEFT JOIN LATERAL (
            -- OP cover: prefer a materialized attachment, else the first inline
            -- <img> in the body (fluentform/inline images never hit forums.attachment).
            SELECT COALESCE(
                     (SELECT url FROM forums.attachment
                       WHERE parent_kind = 'topic' AND parent_id = t.id
                       ORDER BY id ASC LIMIT 1),
                     (regexp_match(t.content_html, '<img[^>]+src=\"([^\"]+)\"'))[1]
                   ) AS url
          ) first_img ON true
          -- Fallback: if the topic itself has no image, surface the first image
          -- posted in any of its replies as the card's featured image.
          LEFT JOIN LATERAL (
            SELECT a.url
              FROM forums.attachment a
              JOIN forums.reply r ON r.id = a.parent_id
             WHERE a.parent_kind = 'reply'
               AND r.topic_id = t.id
               AND r.status = 'publish'
               AND a.url IS NOT NULL
             ORDER BY r.created_at ASC, a.id ASC
             LIMIT 1
          ) reply_img ON true
          LEFT JOIN rx trx ON trx.post_type = 'topic' AND trx.item_id = t.id
         WHERE t.status = 'publish' AND f.visibility = 'public'
           AND t.forum_id NOT IN (3876)
           $q_topic

        UNION ALL

        SELECT
            'content'::text                                            AS card_type,
            c.id                                                       AS topic_id,
            c.slug                                                     AS topic_slug,
            c.title                                                    AS topic_title,
            NULL::text                                                 AS content_html,
            LEFT(c.excerpt, 240)                                       AS op_snippet,
            c.thumb_url                                                AS card_image,
            NULL::text[]                                               AS tags,
            c.reply_count,
            COALESCE(crx.n, 0)                                         AS like_count,
            COALESCE(c.last_activity, c.published_at)                  AS event_time,
            COALESCE(c.author_name, 'Anonymous')                       AS author_name,
            c.author_id,
            NULL::text                                                 AS author_slug,
            NULL::text                                                 AS discussion_visibility,
            c.published_at                                             AS created_at,
            NULL::bigint                                               AS forum_id,
            NULL::text                                                 AS forum_slug,
            NULL::text                                                 AS forum_title,
            NULL::text                                                 AS parent_forum_slug,
            NULL::text                                                 AS parent_forum_title,
            NULL::int                                                  AS is_anon,
            c.kind                                                     AS content_kind,
            c.tier                                                     AS content_tier,
            c.url                                                      AS content_url,
            c.duration_min,
            c.has_download,
            c.cpt                                                      AS content_cpt,
            c.forum_label                                              AS content_forum_label,
            c.subforum_label                                           AS content_subforum_label
          FROM discovery.content_item c
          LEFT JOIN rx crx ON crx.post_type = c.cpt AND crx.item_id = c.id
         WHERE c.tier IN ($tier_in)
           -- events have their own page (Ian 6/11); kind='misc' = sponsor
           -- plumbing (sponsor-product/-page rows feed the sponsor-page
           -- carousels, NOT user-facing — Ian 6/12: only sponsor-POSTS join
           -- the Hub). Matches the Type-facet exclusion above.
           AND c.kind NOT IN ('event', 'misc')
           $q_content
      ) u
      $hub_where
      $union_order_by
      LIMIT :fetch_size OFFSET :raw_offset
    ";
    $stmt = $db->prepare($topic_sql);
    foreach ($content_tiers as $i => $tv) $stmt->bindValue(':ctier' . $i, $tv);
    foreach ($hub_binds as $k => $v) $stmt->bindValue($k, $v);
    if ($hub_q !== '') { $stmt->bindValue(':hq_t', $hub_q); $stmt->bindValue(':hq_c', $hub_q); }
    $stmt->bindValue(':fetch_size', $card_limit, PDO::PARAM_INT);
    $stmt->bindValue(':raw_offset', $raw_offset, PDO::PARAM_INT);
    if ($sort_param === 'random') {
        $stmt->bindValue(':rand_seed', $rand_seed, PDO::PARAM_INT);
    }
    if ($sort_param === 'hot') $stmt->bindValue(':hot_now', $hot_now, PDO::PARAM_INT);
    $stmt->execute();
}

$topics = $stmt->fetchAll();

// Shared author/reply helpers (lg_bb_mirror_can_post, render stub) — loaded early
// here because the masks below run before the main "-- Helpers --" require. Pure
// function defs, require_once, so the later include is a no-op.
require_once __DIR__ . '/_reply-render.php';

// -- Anonymous-posting mask (anon-rebuild lane) -------------------------------
// Apply BEFORE author identity resolution so masked authors are both leak-safe
// (name/slug/avatar/author_id ABSENT from the row → never reach the DOM) AND
// cheaper (a nulled author_id is skipped by the profile-app batch below). Mods
// keep the real author + a "(posted anonymously)" marker. Topic cards only —
// content cards are CPTs, never anonymous.
$can_mod = lg_bb_mirror_can_moderate();
$viewer_logged_in = lg_bb_mirror_can_post();   // logged-out is the ONLY path that masks (perf rule)
foreach ($topics as &$_t) {
    if (($_t['card_type'] ?? 'topic') === 'topic') {
        lg_bb_mirror_mask_anon($_t, $can_mod);
        lg_bb_mirror_mask_visibility($_t, $viewer_logged_in);   // member-only author -> "Private member" @ logged-out
    }
}
unset($_t);

// Live author avatars from profile-app, keyed by WP user id, for card bylines
// (both topic.author_id and content.author_id are WP user ids). One batch call.
$author_ids = [];
foreach ($topics as $_r) if (!empty($_r['author_id'])) $author_ids[] = (int)$_r['author_id'];
$author_profiles = hub_resolve_profiles($author_ids);

// P6 — hide authors the viewer has muted (profile_app.user_mutes via Buck's
// me-mutes GET). We map each card's author_id → uuid (from the profile batch
// above) and drop muted authors. Fails open: anon / endpoint error → no filter.
// NOTE (stub): filters the already-collapsed page, so a heavily-muting viewer
// may see < card_limit cards — a SQL-side prefilter is the production follow-up.
$muted_uuids = hub_viewer_muted_uuids();
if ($muted_uuids) {
    $topics = array_values(array_filter($topics, function ($r) use ($author_profiles, $muted_uuids) {
        $aid  = (int)($r['author_id'] ?? 0);
        $uuid = $aid ? strtolower((string)($author_profiles[$aid]['uuid'] ?? '')) : '';
        return $uuid === '' || !isset($muted_uuids[$uuid]);
    }));
}

// Content (CPT) tag chips — one grouped read of discovery.tag for content cards.
$content_tags = [];
$content_ids  = [];
foreach ($topics as $_r) if (($_r['card_type'] ?? 'topic') === 'content' && !empty($_r['topic_id'])) $content_ids[] = (int)$_r['topic_id'];
if ($content_ids) {
    $idlist = implode(',', array_values(array_unique($content_ids)));
    try {
        $tgst = $db->query("SELECT ct.content_id, t.label FROM discovery.content_tag ct
                              JOIN discovery.tag t ON t.id = ct.tag_id
                             WHERE ct.content_id IN ($idlist) ORDER BY ct.content_id, t.label");
        foreach ($tgst->fetchAll() as $row) $content_tags[(int)$row['content_id']][] = (string)$row['label'];
    } catch (\Throwable $e) { $content_tags = []; } // missing grant -> no tags, never a 500
}

// Author headers: one per selected author (stacked when several are filtered).
$hub_author_headers = [];
if (!empty($GLOBALS['__bb_hub_rail']) && !empty($hub_filters['authors'])) {
    $atph = [];
    foreach ($content_tiers as $i => $t) $atph[] = ':aht' . $i;
    $atin = $atph ? implode(',', $atph) : "''";
    $acs = $db->prepare(
        "SELECT (SELECT count(*) FROM topic WHERE status='publish' AND LOWER(author_name) = LOWER(:an1))
              + (SELECT count(*) FROM discovery.content_item WHERE LOWER(author_name) = LOWER(:an2) AND tier IN ($atin))"
    );
    foreach ($hub_filters['authors'] as $an) {
        $an  = (string)$an;
        $aid = 0;
        // Case-insensitive + CANONICAL display name: show the stored casing
        // ("Dan Erlewine"), not whatever the visitor typed (Ian 2026-06-10).
        foreach ($topics as $_r) {
            if (mb_strtolower((string)($_r['author_name'] ?? '')) === mb_strtolower($an) && !empty($_r['author_id'])) {
                $aid = (int)$_r['author_id'];
                $an  = (string)$_r['author_name'];
                break;
            }
        }
        $acs->bindValue(':an1', $an);
        $acs->bindValue(':an2', $an);
        foreach ($content_tiers as $i => $t) $acs->bindValue(':aht' . $i, $t);
        $acs->execute();
        $hub_author_headers[] = ['name' => $an, 'profile' => $author_profiles[$aid] ?? null, 'count' => (int)$acs->fetchColumn()];
    }
}

// -- Reply TEASER query: only the single newest reply per topic. --
// Perf: rendering every reply for up to 50 cards was the load cost. We now ship
// one teaser stub per card and lazy-load the full thread on "View N replies"
// (see ?replies=<id> → _topic-replies.php + forums.js).
$reply_teaser = []; // topic_id → one reply row
$topic_ids = [];
foreach ($topics as $_r) {
    if (($_r['card_type'] ?? 'topic') === 'topic') $topic_ids[] = (int)$_r['topic_id'];
}
if ($topic_ids) {
    $id_list = '{' . implode(',', $topic_ids) . '}';

    $reply_sql = "
        SELECT DISTINCT ON (r.topic_id)
               r.topic_id, r.id AS reply_id,
               COALESCE(r.author_name, 'Anonymous') AS author_name,
               r.author_id,
               p.slug AS author_slug,
               p.avatar_url AS avatar_url,
               COALESCE(p.discussion_visibility, 'member') AS discussion_visibility,
               r.is_anon::int AS is_anon,
               LEFT(r.content_text, 200) AS excerpt,
               r.content_html,
               r.created_at,
               reply_img.url AS reply_image_url
          FROM reply r
          LEFT JOIN person p ON p.id = r.author_id
          LEFT JOIN LATERAL (
            SELECT url FROM forums.attachment
             WHERE parent_kind = 'reply' AND parent_id = r.id
             ORDER BY id ASC LIMIT 1
          ) reply_img ON true
         WHERE r.topic_id = ANY(:ids::bigint[])
           AND r.status = 'publish'
         -- Teaser = the MOST-ACTIVE reply per topic (Buck [B]): rank by sub-reply
         -- count (parent_reply_id) + reply-reaction count (discovery.card_reactions
         -- post_type='reply'), newest as tie-break. Surfaces the liveliest reply,
         -- not just the latest.
         ORDER BY r.topic_id,
           ( (SELECT count(*) FROM reply cr WHERE cr.parent_reply_id = r.id AND cr.status = 'publish')
           + (SELECT count(*) FROM discovery.card_reactions cx WHERE cx.post_type = 'reply' AND cx.item_id = r.id) ) DESC,
           r.created_at DESC
    ";
    $rstmt = $db->prepare($reply_sql);
    $rstmt->bindValue(':ids', $id_list);
    $rstmt->execute();
    foreach ($rstmt->fetchAll() as $row) {
        lg_bb_mirror_mask_anon($row, $can_mod);   // leak-safe anon mask before render
        lg_bb_mirror_mask_visibility($row, $viewer_logged_in);   // member-only author mask @ logged-out
        $reply_teaser[(int)$row['topic_id']] = $row;
    }
}

// -- Face-pile (fc-facepile): up to 3 distinct recent reply-authors per topic. --
$reply_facepile = []; // topic_id → [ {author_name, author_slug, avatar_url}, … ≤3 ]
if ($topic_ids) {
    $fp_sql = "
        SELECT topic_id, author_name, author_slug, avatar_url, is_anon, discussion_visibility FROM (
          SELECT r.topic_id,
                 COALESCE(r.author_name, 'Anonymous') AS author_name,
                 p.slug AS author_slug, p.avatar_url AS avatar_url,
                 COALESCE(p.discussion_visibility, 'member') AS discussion_visibility,
                 bool_or(r.is_anon)::int AS is_anon,
                 row_number() OVER (PARTITION BY r.topic_id ORDER BY MAX(r.created_at) DESC) AS rn
            FROM reply r
            LEFT JOIN person p ON p.id = r.author_id
           WHERE r.topic_id = ANY(:ids::bigint[]) AND r.status = 'publish'
           GROUP BY r.topic_id, r.author_id, r.author_name, p.slug, p.avatar_url, p.discussion_visibility
        ) x WHERE rn <= 3 ORDER BY topic_id, rn
    ";
    $fpst = $db->prepare($fp_sql);
    $fpst->bindValue(':ids', $id_list);
    $fpst->execute();
    foreach ($fpst->fetchAll() as $row) {
        lg_bb_mirror_mask_anon($row, $can_mod);   // leak-safe anon mask before render
        lg_bb_mirror_mask_visibility($row, $viewer_logged_in);   // member-only author mask @ logged-out
        $reply_facepile[(int)$row['topic_id']][] = $row;
    }
}

// -- Content comment counts (Hub content cards → WP-free comment modal). --
// Content comments live in discovery.comments (pulled out of WP by the comments-db
// lane), keyed (post_type, item_id) — same shape as discovery.likes. One grouped
// read per page (mirrors the reply-teaser fetch above) badges each content card
// with its comment count and powers the modal trigger. bb-mirror reads the store
// read-only. Try/catch so a missing grant degrades to "no count", never a 500.
//
// Covered post types = the comments-db lane's LG_COMMENTS_TYPES. Cards outside this
// set get no comment affordance (their threads still live in WP / aren't pulled).
const LG_HUB_COMMENT_TYPES = [
    'loothprint', 'post-type-videos', 'post-imgcap', 'post',
    'shorty', 'coe-questions', 'ajde_events',
];
$content_comment_counts = []; // "post_type:item_id" => count
$cc_keys = [];                // post_type => [item_id, …]
foreach ($topics as $_r) {
    if (($_r['card_type'] ?? 'topic') !== 'content') continue;
    $cpt = (string)($_r['content_cpt'] ?? '');
    if ($cpt !== '' && in_array($cpt, LG_HUB_COMMENT_TYPES, true)) {
        $cc_keys[$cpt][] = (int)$_r['topic_id'];
    }
}
if ($cc_keys) {
    $cc_parts = []; $cc_binds = []; $cc_i = 0;
    foreach ($cc_keys as $cpt => $ids) {
        $ph = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $k = ':cid' . $cc_i++; $ph[] = $k; $cc_binds[$k] = $id;
        }
        $tk = ':cct' . $cc_i++; $cc_binds[$tk] = $cpt;
        $cc_parts[] = "(post_type = $tk AND item_id IN (" . implode(',', $ph) . "))";
    }
    try {
        $cc_stmt = $db->prepare(
            "SELECT post_type, item_id, COUNT(*) AS n
               FROM discovery.comments
              WHERE status = 'approved' AND (" . implode(' OR ', $cc_parts) . ")
              GROUP BY post_type, item_id"
        );
        $cc_stmt->execute($cc_binds);
        foreach ($cc_stmt->fetchAll() as $row) {
            $content_comment_counts[$row['post_type'] . ':' . (int)$row['item_id']] = (int)$row['n'];
        }
    } catch (\Throwable $e) {
        $content_comment_counts = []; // store unreadable → omit counts, keep the feed
    }
}

// -- Card reaction counts (Hub engagement bar → discovery.card_reactions). --
// Mirrors the comment-count read above: ONE grouped read keyed (post_type, item_id)
// via the comments+reactions engine's count contract (lg_card_reactions_for_items in
// archive-poc/api/v0/_reactions.php). Topics use post_type 'topic'; content uses its
// CPT. bb-mirror has SELECT on discovery.card_reactions. The viewer's own pick + the
// write nonce come client-side from the card-react GET (the WP-cookie door). Try/catch
// so a missing grant degrades to "no reactions", never a 500.
//
// Reactable types = card-react.php's LG_CARD_REACT_TYPES (kept in lockstep here).
const LG_HUB_REACT_TYPES = ['post-imgcap','post-type-videos','sponsor-post','loothprint',
                            'loothcuts','useful_links','member-benefit','topic'];
$card_reaction_counts = []; // "post_type:item_id" => [slug => count]
$rx_items = [];
foreach ($topics as $_r) {
    if (($_r['card_type'] ?? 'topic') === 'content') {
        $rx_pt = (string)($_r['content_cpt'] ?? '');
    } else {
        $rx_pt = 'topic';
    }
    $rx_id = (int)($_r['topic_id'] ?? 0);
    if ($rx_pt !== '' && $rx_id > 0 && in_array($rx_pt, LG_HUB_REACT_TYPES, true)) {
        $rx_items[] = ['post_type' => $rx_pt, 'item_id' => $rx_id];
    }
}
if ($rx_items) {
    try {
        require_once __DIR__ . '/../../../archive-poc/api/v0/_reactions.php'; // count contract + palette
        $card_reaction_counts = lg_card_reactions_for_items($db, $rx_items);
    } catch (\Throwable $e) {
        $card_reaction_counts = []; // store/grant unreadable → omit reactions, keep the feed
    }
}

// -- Reply reaction counts (ec9a30e: 'reply' is a reactable target — same generic
//    card_reactions store, no schema change). Batch-read the teaser replies' counts
//    via the SAME count contract, keyed 'reply:<reply_id>', and stash for the shared
//    reply-stub renderer (_reply-render.php emits feed_reactions_bar per reply; the
//    picker + write are wired generically by forums.js on .fcr). --
$reply_rx_items = [];
foreach ($reply_teaser as $_rt) {
    $rid = (int)($_rt['reply_id'] ?? 0);
    if ($rid > 0) $reply_rx_items[] = ['post_type' => 'reply', 'item_id' => $rid];
}
$GLOBALS['__bb_reply_rx'] = [];
if ($reply_rx_items) {
    try {
        require_once __DIR__ . '/../../../archive-poc/api/v0/_reactions.php';
        $GLOBALS['__bb_reply_rx'] = lg_card_reactions_for_items($db, $reply_rx_items);
    } catch (\Throwable $e) {
        $GLOBALS['__bb_reply_rx'] = []; // unreadable → no chips, picker still works
    }
}

// -- Video facade yt_id (Ian 6/7: play inline in the card). Same source as Archive
//    (_rowlib.php): a cheap YouTube-id regex over the video's body_text, videos
//    only. Batched for the content-video ids on the page → $video_yt[content_id].
//    The facade renders the thumb + a play button; forums.js swaps in the iframe
//    ONLY on click (no embed up front). Videos with no extractable id (or gated)
//    keep their click-through. --
$video_yt = [];
$vid_ids = [];
foreach ($topics as $_r) {
    // shorty = video-link CPT; same inline-play treatment (Buck 6/12 parity).
    if (($_r['card_type'] ?? '') === 'content' && in_array(($_r['content_kind'] ?? ''), ['video', 'shorty'], true)) {
        $vid_ids[] = (int)$_r['topic_id'];
    }
}
if ($vid_ids) {
    try {
        $vph = implode(',', array_fill(0, count($vid_ids), '?'));
        // Prefer the engine's stored yt_id (commit 936b965 — 340/341 coverage, sourced
        // from ACF youtube_link → v2 embed block → post_content at index time). Keep the
        // body_text regex only as a fallback for any not-yet-reindexed row.
        $vst = $db->prepare("SELECT id, yt_id, body_text FROM discovery.content_item WHERE id IN ($vph) AND kind IN ('video','shorty')");
        $vst->execute($vid_ids);
        $yt_re = '~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,15})~i';
        foreach ($vst->fetchAll() as $vr) {
            $yt = trim((string)($vr['yt_id'] ?? ''));
            if ($yt === '' && !empty($vr['body_text']) && preg_match($yt_re, (string)$vr['body_text'], $ym)) {
                $yt = $ym[1];
            }
            if ($yt !== '') $video_yt[(int)$vr['id']] = $yt;
        }
    } catch (\Throwable $e) { $video_yt = []; }
}

// -- Helpers --
// bb_mirror_avatar(), feed_rel_time(), bb_mirror_render_reply_stub() live in
// the shared partial so the lazy ?replies endpoint emits identical markup.
require_once __DIR__ . '/_reply-render.php';

function feed_first_embed_url(?string $html): ?string
{
    if (!$html) return null;
    // First standalone provider URL (YouTube / youtu.be / Vimeo / Instagram / X).
    // Stops at whitespace, quote, or '<' so legacy glue (e.g. "<div>") is excluded.
    $re = '~https?://(?:www\\.|m\\.)?(?:youtube\\.com/(?:watch\\?[^\\s"<]*v=|shorts/|embed/)|youtu\\.be/|vimeo\\.com/(?:video/)?\\d|instagram\\.com/(?:p|reel|tv)/|(?:twitter\\.com|x\\.com)/\\w+/status/)[^\\s"<]+~i';
    return preg_match($re, $html, $m) ? $m[0] : null;
}

function feed_ctx(array $card): string
{
    $title = htmlspecialchars($card['forum_title'] ?? '', ENT_QUOTES, 'UTF-8');
    $parent = $card['parent_forum_title'] ?? null;
    if ($parent) {
        return '<span class="feed-card__ctx-parent">' . htmlspecialchars($parent, ENT_QUOTES, 'UTF-8') . '</span>'
             . ' <span class="feed-card__ctx-sep">&rsaquo;</span> '
             . '<span class="feed-card__ctx-forum">' . $title . '</span>';
    }
    return '<span class="feed-card__ctx-parent">' . $title . '</span>';
}

function feed_topic_url(array $card): string
{
    return htmlspecialchars(
        LG_BB_MIRROR_PUBLIC_PATH . '/' . $card['forum_slug'] . '/' . $card['topic_slug'] . '/'
    );
}

// Parse a Postgres text[] literal (as PDO returns it) into a PHP array of strings.
// e.g. {"Total Vise",workstation,"neck reset"} → ['Total Vise','workstation','neck reset']
function feed_parse_pg_array(?string $lit): array
{
    if ($lit === null || $lit === '' || $lit === '{}') return [];
    $inner = substr($lit, 1, -1); // strip { }
    $out = [];
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $m, PREG_SET_ORDER);
    foreach ($m as $part) {
        $val = isset($part[2]) && $part[2] !== ''
            ? $part[2]
            : str_replace(['\\"', '\\\\'], ['"', '\\'], $part[1]);
        $val = trim($val);
        if ($val !== '') $out[] = $val;
    }
    return $out;
}

// Render forum tag chips (topic tags) — links to a tag search.
function feed_render_tags(array $tags): void
{
    if (!$tags) return;
    echo '<div class="fc-tags feed-card__tags">';
    foreach ($tags as $tag) {
        $url = LG_BB_MIRROR_PUBLIC_PATH . '/?q=' . urlencode($tag);
        echo '<a class="fc-tag tag-chip" href="' . htmlspecialchars($url) . '">'
           . htmlspecialchars($tag) . '</a>';
    }
    echo '</div>';
}

// Server-render the mobile action bar (Like / N replies / Share) that hub-polish.js
// used to inject client-side (the post-paint flash). Markup MUST stay in lockstep
// with hub-polish.js buildActions() — JS now only WIRES the present row, never builds
// it (mobile-only via .lg-card-actions CSS; display:none at desktop). Content cards
// pass $zero_label='Comment' (Buck 6/11 audit H4: they read identically to a 0-reply
// discussion as "Reply", but their tap opens the comments surface, not a composer).
function feed_action_bar(int $reply_count, string $zero_label = 'Reply'): void
{
    // Thumbs-up, not a heart: the Like applies a 👍 reaction so the icon matches
    // (Buck 2026-06-11 — was swapped client-side; canonical now, his replace no-ops).
    static $ICO_LIKE    = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></svg>';
    static $ICO_REPLIES = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/></svg>';
    static $ICO_SHARE   = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M16 6l-4-4-4 4"/><path d="M12 2v13"/></svg>';
    $label = $reply_count === 0 ? $zero_label : ($reply_count === 1 ? '1 reply' : $reply_count . ' replies');
    echo '<div class="feed-card__actions lg-card-actions">'
       . '<span class="lg-act lg-act-like" role="button" tabindex="0">' . $ICO_LIKE . 'Like</span>'
       . '<span class="lg-act lg-act-replies" role="button" tabindex="0">' . $ICO_REPLIES . htmlspecialchars($label) . '</span>'
       . '<span class="lg-act lg-act-share" role="button" tabindex="0">' . $ICO_SHARE . 'Share</span>'
       . '</div>';
}

// Save / bookmark toggle (☆) — binary per-card save → discovery.saved_posts via the
// WP-cookie door (/archive-api/v0/save-post, sibling of card-react). Server-renders the
// inert star button; forums.js hydrates the viewer's saved-state (batch GET resolves
// auth+nonce+my_saves) and wires the optimistic toggle (POST). Logged-out viewers get
// the button but the GET resolves anon → no nonce → it stays inert. Only emitted for
// savable types (LG_HUB_REACT_TYPES == save-post.php's LG_SAVE_TYPES, incl. 'topic').
function feed_save_btn(string $postType, int $itemId): void
{
    static $ICO = '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 2.6l2.95 5.98 6.6.96-4.77 4.65 1.13 6.57L12 17.66 6.09 20.76l1.13-6.57L2.45 9.54l6.6-.96z"/></svg>';
    echo '<button type="button" class="fc-save" data-save data-post-type="' . htmlspecialchars($postType, ENT_QUOTES)
       . '" data-item-id="' . $itemId . '" aria-pressed="false" aria-label="Save" title="Save">'
       . $ICO . '<span class="fc-save__lbl">Save</span></button>';
}

// feed_rx_glyph() + feed_reactions_bar() now live in _reply-render.php (the shared
// partial) so the lazy full-thread endpoint can emit reply reactions too. Required
// below at the "-- Helpers --" include.

// Sponsor rail (Ian 2026-06-12): in STREAM view sponsors move OUT of the feed
// into this fixed right-hand rail (logo tiles linking to /sponsors/<slug>/);
// mosaic keeps the inline spotlight cards (sponsor-cards.js) as discovery.
// Server-rendered ALWAYS, CSS decides per layout — html[data-lg-hublayout] is
// set pre-paint by the boot script, so neither layout flashes the other's
// treatment. The list mirrors sponsor-cards.js SPONSORS (same assets/links).
function hub_render_sponsor_rail(): void
{
    $sponsors = [
        ['slug' => 'total-vise',            'name' => 'Total Vise',            'img' => '/wp-content/uploads/2024/06/Sponor-Banner-Total-Vise-300x108.webp',   'w' => 300, 'h' => 108],
        ['slug' => 'stewmac',               'name' => 'StewMac',               'img' => '/wp-content/uploads/2024/06/Sidebar-Affiliate-Stew-Mac-300x108.webp', 'w' => 300, 'h' => 108],
        ['slug' => 'go-acoustic-audio',     'name' => 'Go Acoustic Audio',     'img' => '/wp-content/uploads/2024/06/Sponsor-Go-Acoustic-300x80.webp',         'w' => 300, 'h' => 80],
        ['slug' => 'strings-micro-factory', 'name' => 'Strings Micro Factory', 'img' => '/wp-content/uploads/2024/06/SMF-Logo-Horizontal-624x192.jpg',         'w' => 624, 'h' => 192],
        ['slug' => 'gluboost',              'name' => 'GluBoost',              'img' => '/wp-content/uploads/2026/04/gluboost-logo-624x163.png',               'w' => 624, 'h' => 163],
    ];
    echo '<aside class="hub-sponsor-rail" aria-label="Our sponsors">';
    echo '<h2 class="hsr-head">Our sponsors</h2>';
    foreach ($sponsors as $s) {
        echo '<a class="hsr-tile" href="/sponsors/' . htmlspecialchars($s['slug'], ENT_QUOTES) . '/">'
           . '<img src="' . htmlspecialchars($s['img'], ENT_QUOTES) . '" alt="' . htmlspecialchars($s['name'], ENT_QUOTES) . '"'
           . ' loading="lazy" width="' . (int)$s['w'] . '" height="' . (int)$s['h'] . '">'
           . '</a>';
    }
    echo '</aside>';
}

// Forum feed URL, appending ?fid=<id> when the slug is shared by >1 forum.
function feed_forum_url(array $f, array $slug_freq): string
{
    $qs = (($slug_freq[$f['slug']] ?? 1) > 1) ? '?fid=' . (int)$f['id'] : '';
    return htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $f['slug'] . '/' . $qs);
}

function feed_op_excerpt(array $topic): string
{
    $raw = trim((string)($topic['op_snippet'] ?? ''));
    if ($raw === '') return '';
    $plain = strip_tags($raw);
    if (mb_strlen($plain) > 200) {
        $plain = mb_substr($plain, 0, 200) . '…';
    }
    return htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
}

function feed_sort_url(string $sort_val, string $forum_slug): string
{
    $base = LG_BB_MIRROR_PUBLIC_PATH . '/';
    $qs_parts = [];
    if ($forum_slug !== '') {
        $qs_parts[] = 'forum_slug=' . urlencode($forum_slug);
    }
    $qs_parts[] = 'sort=' . urlencode($sort_val);
    // Preserve active Hub filters so changing sort doesn't reset Type/Cat/Author/Search.
    foreach (hub_query_params() as $k => $v) $qs_parts[] = $k . '=' . urlencode($v);
    return htmlspecialchars($base . '?' . implode('&', $qs_parts));
}

// -- Render --
$page_title = $scoped_forum ? (string)$scoped_forum['title'] : 'The Hub';
bb_mirror_chrome_header($page_title);

// Posting is authenticated-only (BuddyBoss REST 401s anonymous writes). Gate
// every post/reply affordance on this server-side so anon viewers receive no
// posting markup at all — can't be un-hidden via inspector.
$can_post = lg_bb_mirror_can_post();

$header_title     = $scoped_forum ? (string)$scoped_forum['title'] : 'The Hub';
$has_header_image = ($header_image_url !== null && $header_image_url !== '');

// Category colour key for the header accent (site-wide = neutral 'general').
$header_cat = $scoped_forum
    ? ($_forum_cat_map[(int)$scoped_forum['id']] ?? 'general')
    : 'general';
?>

<div class="page feed-page">

  <!-- Forum header -->
  <header class="forum-header<?= $has_header_image ? ' forum-header--has-image' : '' ?><?= $header_image_explicit ? ' forum-header--explicit-image' : '' ?><?= $scoped_forum ? '' : ' forum-header--hub' ?>"
          data-cat="<?= htmlspecialchars($header_cat) ?>">
    <?php if ($has_header_image): ?>
      <div class="forum-header__bg"
           style="background-image: url('<?= htmlspecialchars(lg_cover_src($header_image_url, 1600), ENT_QUOTES, 'UTF-8') ?>')"></div>
    <?php endif; ?>
    <div class="forum-header__body">
      <?php if ($parent_forum): ?>
        <a class="forum-header__parent"
           href="<?= feed_forum_url($parent_forum, $slug_freq) ?>">&lsaquo; <?= htmlspecialchars($parent_forum['title']) ?></a>
      <?php endif; ?>
      <div class="forum-header__title-row">
        <h1 class="forum-header__title"><?= htmlspecialchars($header_title, ENT_QUOTES, 'UTF-8') ?></h1>
      </div>
      <?php if (!$scoped_forum): ?>
        <span class="lg-hub-tagline">The latest builds, repairs, and conversations from across Looth.</span>
      <?php endif; ?>
      <span class="forum-header__label">Activity</span>
      <button class="forum-header__edit-img" type="button" hidden
              data-forum-id="<?= $scoped_forum ? (int)$scoped_forum['id'] : 0 ?>"
              title="Set header image" aria-label="Set header image">&#9998;</button>
    </div>
    <?php if ($can_post && !$is_postable_forum): // All + category views: post-anywhere CTA (leaf views use the "+ Post here" button in the sort bar) ?>
      <button class="forum-header__new-post" type="button" data-ntm-open aria-haspopup="dialog">+ New post</button>
    <?php endif; ?>
  </header>

  <?php if (!empty($GLOBALS['__bb_hub_rail'])) hub_render_chipbar($hub_filters, $hub_muted, $sort_param, $hub_leaf_labels ?? [], $hub_cat_tree ?? []); ?>

  <!-- Sort bar (+ post button, right-aligned) -->
  <nav class="feed-sort-bar" aria-label="Sort activity" data-lg-bar="1">
    <?php
      // The ACTIVE sort leads the pill row (Ian 2026-06-10); the rest keep
      // their usual order. usort is stable on PHP 8.
      $lg_sort_pills = [
          ['random', 'Random',   'lg-random-tab'],
          ['new',    'Newest',   ''],
          ['hot',    'Trending', ''],
      ];
      usort($lg_sort_pills, function ($a, $b) use ($sort_param) {
          return (int)($b[0] === $sort_param) <=> (int)($a[0] === $sort_param);
      });
      foreach ($lg_sort_pills as [$lg_sid, $lg_slabel, $lg_scls]):
        $lg_cls = trim($lg_scls . ($sort_param === $lg_sid ? ' active' : ''));
    ?>
    <a href="<?= feed_sort_url($lg_sid, $forum_slug) ?>"<?= $lg_cls !== '' ? ' class="' . $lg_cls . '"' : '' ?>><?= $lg_slabel ?></a>
    <?php endforeach; ?>
    <?php if ($viewer_logged_in):
      // Saved pill (Ian 2026-06-11) — a VIEW toggle, not a sort: ?saved=1
      // constrains the union to the viewer's ☆ saves (canonical view). Active
      // = clickable to exit (CSS re-enables pointer-events). The lg-saved-pill
      // class also tells the mobile overlay's ensureSavedPill not to insert
      // its own copy. Logged-in only — anon has no saves.
      $lg_on_saved   = !empty($hub_filters['saved']);
      // feed_sort_url() now folds the active `saved` filter into the URL (via
      // hub_query_params), so strip it to get a clean toggle base — otherwise the
      // ACTIVE pill links straight back to ?saved=1 and Saved can be selected but
      // never un-selected (the toggle-off href pointed at itself).
      $lg_saved_href = preg_replace('/&(?:amp;)?saved=1\b/', '', feed_sort_url($sort_param, $forum_slug));
      $lg_saved_href .= $lg_on_saved ? '' : '&amp;saved=1';
    ?>
    <a href="<?= $lg_saved_href ?>" class="lg-saved-pill<?= $lg_on_saved ? ' active' : '' ?>">Saved</a>
    <?php endif; ?>
    <?php if (!empty($GLOBALS['__bb_hub_rail'])): ?>
      <?php hub_render_toolbar_search($hub_filters, $sort_param); ?>
    <?php endif; ?>
    <?php // View toggles live under the header (in the sort bar) for all views. ?>
    <?php hub_render_view_toggles(); ?>
    <?php if (!empty($GLOBALS['__bb_hub_rail'])) hub_render_shows_chip($db, $hub_filters, $sort_param); /* desktop "Shows" video-type filter; CSS hides <=640 */ ?>
    <button type="button" class="lg-filters-chip" aria-label="Open filters">
      <span class="corner-hamburger__icon" aria-hidden="true">&#9776;</span>
      <span class="lg-filters-chip__tx">Filters</span>
    </button>
    <?php if ($can_post && $is_postable_forum): ?>
      <button class="feed-post-btn" type="button" data-ntm-open
              data-forum-id="<?= (int)$scoped_forum['id'] ?>"
              data-forum-slug="<?= htmlspecialchars($forum_slug) ?>">+ Post here</button>
    <?php endif; ?>
    <?php if ($can_post && !$is_postable_forum): ?>
      <button class="forum-header__new-post lg-newpost" type="button" data-ntm-open aria-haspopup="dialog">+ New post</button>
    <?php endif; ?>
  </nav>

  <?php if (!$scoped_forum) hub_render_sponsor_rail(); // hub front door only; CSS shows it in stream view ?>

  <?php foreach ($hub_author_headers as $_hah) hub_render_author_header($_hah, $hub_filters, $sort_param); ?>

  <div id="hub-feed-results">
  <?php if (!$topics): ?>
    <p class="bb-mirror__empty">No recent activity.</p>

  <?php else: ?>
  <?php /* data-lg-sort: forums.js §9 reads this to pick column fill — deterministic
           sorts (new/old/hot) get round-robin so the mosaic reads in feed order;
           random keeps shortest-column fill (even bottoms, no order to preserve). */ ?>
  <div class="feed" data-lg-sort="<?= htmlspecialchars($sort_param, ENT_QUOTES) ?>">

    <?php foreach ($topics as $topic):
      // ---- Content card (article / video / event / sponsor-post) ----
      // A folded discovery row; deep-links to its standalone archive page.
      // No replies/threading — that's forum-topic-only below.
      if (($topic['card_type'] ?? 'topic') === 'content'):
        $c_url     = (string)($topic['content_url'] ?? '#');
        $c_title   = htmlspecialchars((string)$topic['topic_title']);
        $c_kind    = (string)($topic['content_kind'] ?? 'content');
        $c_img     = lg_cover_src($topic['card_image'] ?? null);
        $c_dims    = lg_cover_dims($topic['card_image'] ?? null);
        $c_time    = $topic['event_time'] ? feed_rel_time($topic['event_time']) : '—';
        $c_author  = htmlspecialchars((string)$topic['author_name']);
        $c_excerpt = feed_op_excerpt($topic);
        // Video-link kinds: an excerpt that's just the pasted provider URL is
        // noise under a playable cover — suppress it (Buck 6/12; pairs with the
        // shorty facade above).
        if (in_array($c_kind, ['video', 'shorty'], true) && preg_match('~^https?://\S+$~', html_entity_decode($c_excerpt))) {
            $c_excerpt = '';
        }
        $c_likes   = (int)$topic['like_count'];
        $c_dur     = (int)($topic['duration_min'] ?? 0);
        $c_tier    = (string)($topic['content_tier'] ?? '');
        $c_tags    = $content_tags[(int)$topic['topic_id']] ?? [];
        $c_cat     = hub_reconcile_cat_key((string)($topic['content_forum_label'] ?? ''));
        // Inline comments: WP-free modal keyed (post_type, item_id) — same shape as
        // likes. topic_id is the content_item id (= wp_posts.ID = comment item_id).
        $c_cpt        = (string)($topic['content_cpt'] ?? '');
        $c_id         = (int)$topic['topic_id'];
        $c_can_comment = in_array($c_cpt, LG_HUB_COMMENT_TYPES, true) && $c_id > 0;
        $c_comments   = $content_comment_counts[$c_cpt . ':' . $c_id] ?? 0;
        $kind_label = [
            'article' => 'Article', 'video' => 'Video', 'event' => 'Event',
            'sponsor-post' => 'Sponsor', 'loothprint' => 'Loothprint',
        ][$c_kind] ?? ucfirst(str_replace('-', ' ', $c_kind));
        // Teaser gating (Ian 6/7): a card is gated when its tier outranks the viewer.
        // Gated cards render title + thumb + lock overlay only — excerpt/play/engagement
        // suppressed below so no payload leaks. The lock CTA + cover link to the
        // standalone page, which carries its own paywall/upgrade gate.
        $rankmap     = $LG_TIER_RANK ?? ['public' => 0, 'lite' => 1, 'pro' => 2];
        $c_is_gated  = (($rankmap[$c_tier] ?? 0) > ($GLOBALS['LG_HUB_VIEWER_RANK'] ?? 0));
        $c_tier_lbl  = ['lite' => 'Lite', 'pro' => 'Pro'][$c_tier] ?? ucfirst((string)$c_tier);
        // Inline-play id only for NON-gated videos (gated → overlay, never the embed).
        $c_yt        = (!$c_is_gated && in_array($c_kind, ['video', 'shorty'], true)) ? ($video_yt[$c_id] ?? null) : null;
    ?>
    <?php /* FLAT card contract (docs/hub-mobile-desktop-split.md): every fc-* region is a
             DIRECT child of .feed-card so desktop (forums.css ≥641) and mobile (Buck's
             mobile-hub.css ≤640) can grid-arrange the SAME markup — no JS reshape. Legacy
             feed-card__* / lg-card-* classes ride along as forums.js behavior hooks. */ ?>
    <article class="feed-card feed-card--content<?= $c_is_gated ? ' feed-card--gated feed-card--gated-' . htmlspecialchars($c_tier) : '' ?>" data-lg-card="1"
             data-id="<?= $c_id ?>" data-type="<?= htmlspecialchars($c_kind) ?>"
             data-href="<?= htmlspecialchars($c_url) ?>" data-gated="<?= $c_is_gated ? '1' : '0' ?>"
             data-cat="<?= htmlspecialchars($c_cat) ?>" data-kind="<?= htmlspecialchars($c_kind) ?>"
             data-post-type="<?= htmlspecialchars($c_cpt, ENT_QUOTES) ?>" data-item-id="<?= $c_id ?>">
      <span class="fc-avatar lg-card-avatar"><?= bb_mirror_avatar($topic['author_name'] ?: 'A', $topic['topic_slug'], 40, $author_profiles[(int)($topic['author_id'] ?? 0)]['avatar_url'] ?? null) ?></span>
      <div class="fc-author">
        <span class="fc-author__name lg-card-author"><?= $c_author ?></span>
        <span class="fc-author__badges"><span class="feed-card__kind-badge feed-card__kind-badge--<?= htmlspecialchars($c_kind) ?>"><?= htmlspecialchars($kind_label) ?></span><?php if (!empty($topic['content_forum_label'])): ?><span class="fc-cat-chip"><?= htmlspecialchars((string)$topic['content_forum_label'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?php if ($c_is_gated): ?><span class="fc-tier-badge fc-tier-badge--<?= htmlspecialchars($c_tier) ?>"><?= htmlspecialchars($c_tier_lbl) ?></span><?php endif; ?></span>
      </div>
      <?php if (!empty($topic['content_forum_label'])): ?>
        <nav class="fc-category lg-card-cat"><?= htmlspecialchars((string)$topic['content_forum_label'], ENT_QUOTES, 'UTF-8') ?></nav>
      <?php endif; ?>
      <time class="fc-time lg-card-time"><?= $c_time ?></time>
      <?php if ($c_yt): /* NON-gated video → facade: thumb + play; forums.js swaps iframe on click (no embed up front) */ ?>
        <div class="fc-cover feed-card__cover fc-cover--video" data-yt-play="<?= htmlspecialchars($c_yt, ENT_QUOTES) ?>" role="button" tabindex="0" aria-label="Play video">
          <?php if (!empty($c_img)): ?><img class="feed-card__cover-img" src="<?= htmlspecialchars($c_img) ?>"<?= lg_cover_srcset($c_img) ?> alt=""<?= $c_dims ?> <?= lg_cover_loading_attrs() ?>><?php endif; ?>
          <button type="button" class="fc-play" aria-label="Play video"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></button>
          <?php if ($c_dur > 0): ?><span class="fc-dur"><?= (int)$c_dur ?> min</span><?php endif; ?>
        </div>
      <?php elseif ($c_is_gated): /* GATED → locked teaser: dimmed thumb + lock overlay; links to the standalone page which carries its own paywall. NO inline play, no excerpt, no engagement. */ ?>
        <a class="fc-cover feed-card__cover fc-cover--gated" href="<?= htmlspecialchars($c_url) ?>" aria-label="<?= htmlspecialchars($c_tier_lbl) ?> members only">
          <?php if (!empty($c_img)): ?><img class="feed-card__cover-img" src="<?= htmlspecialchars($c_img) ?>"<?= lg_cover_srcset($c_img) ?> alt=""<?= $c_dims ?> <?= lg_cover_loading_attrs() ?>><?php endif; ?>
          <span class="fc-gate">
            <span class="fc-gate__lock" aria-hidden="true">&#128274;</span>
            <span class="fc-gate__t"><?= htmlspecialchars($c_tier_lbl) ?> members only</span>
            <span class="fc-gate__cta"><?= $c_kind === 'video' ? 'Unlock video' : 'Unlock' ?></span>
          </span>
        </a>
      <?php elseif (!empty($c_img)): ?>
        <a class="fc-cover feed-card__cover" href="<?= htmlspecialchars($c_url) ?>" aria-label="<?= $c_title ?>">
          <img class="feed-card__cover-img" src="<?= htmlspecialchars($c_img) ?>"<?= lg_cover_srcset($c_img) ?> alt=""<?= $c_dims ?> <?= lg_cover_loading_attrs() ?>>
        </a>
      <?php endif; ?>
      <h3 class="fc-title feed-card__title"><a href="<?= htmlspecialchars($c_url) ?>"><?= $c_title ?></a></h3>
      <?php if (!$c_is_gated): /* gated cards: suppress excerpt/tags/engagement — locked teaser carries no payload */ ?>
        <?php if ($c_excerpt !== ''): ?>
          <div class="fc-excerpt feed-card__op"><a class="feed-card__op-excerpt-link" href="<?= htmlspecialchars($c_url) ?>" tabindex="-1"><p class="feed-card__op-excerpt"><?= $c_excerpt ?></p></a></div>
        <?php endif; ?>
        <?php if ($c_tags) feed_render_tags(array_slice($c_tags, 0, 5)); ?>
        <?php /* fc-actions = the reactions-comments SURFACE lane's engagement-bar slot.
                 Server-rendered (counts + action row) — wired by forums.js / hub-polish.js. */ ?>
        <div class="fc-actions">
          <?php if (in_array($c_cpt, LG_HUB_REACT_TYPES, true)) feed_reactions_bar($c_cpt, $c_id, $card_reaction_counts[$c_cpt . ':' . $c_id] ?? []); ?>
          <?php feed_action_bar(0, 'Comment'); ?>
          <?php if (in_array($c_cpt, LG_HUB_REACT_TYPES, true)) feed_save_btn($c_cpt, $c_id); ?>
          <?php if ($c_can_comment): ?>
            <button type="button" class="feed-card__comments-btn" data-comments
                    data-post-type="<?= htmlspecialchars($c_cpt, ENT_QUOTES) ?>" data-item-id="<?= $c_id ?>"
                    aria-haspopup="dialog" aria-controls="lgc-modal"
                    title="<?= $c_comments > 0 ? 'View comments' : 'Be the first to comment' ?>">
              &#128172; <?= $c_comments > 0 ? $c_comments . ' ' . ($c_comments === 1 ? 'comment' : 'comments') : 'Comment' ?>
            </button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </article>
    <?php continue; endif;
      $turl        = feed_topic_url($topic);
      $ctx         = feed_ctx($topic);
      $rtime       = $topic['event_time'] ? feed_rel_time($topic['event_time']) : '—';
      $start_time  = $topic['created_at'] ? feed_rel_time($topic['created_at']) : '—';
      $author      = htmlspecialchars($topic['author_name']);
      // Author name links to the member's profile (/u/<slug>); plain span if no slug.
      $author_slug = $topic['author_slug'] ?? null;
      $author_link = $author_slug
          ? '<a class="feed-card__op-author" href="/u/' . rawurlencode((string)$author_slug) . '">' . $author . '</a>'
          : '<span class="feed-card__op-author">' . $author . '</span>';
      // Admin/mod reveal (anon-rebuild lane): is_anon rows keep the real author for
      // moderators (lg_bb_mirror_mask_anon set _anon_revealed) + this marker. For
      // everyone else the row was scrubbed above, so $author is "Anonymous" already.
      if (!empty($topic['_anon_revealed'])) {
          $author_link .= ' <span class="lg-anon-marker" title="This member chose to post anonymously">(posted anonymously)</span>';
      }
      // OP excerpt: format from content_html so @mentions + URLs are clickable.
      // Falls back to the plain content_text teaser if there's no HTML.
      $excerpt     = bb_mirror_format_snippet((string)($topic['content_html'] ?? ''), 440, $db); // ~2x (Ian)
      if ($excerpt === '') $excerpt = feed_op_excerpt($topic);
      $topic_id    = (int)$topic['topic_id'];
      $reply_count = (int)$topic['reply_count'];
      $card_image  = lg_cover_src($topic['card_image'] ?? null);
      $card_dims   = lg_cover_dims($topic['card_image'] ?? null);

      // One teaser reply (newest); full thread lazy-loads via ?replies=<id>.
      $teaser    = $reply_teaser[$topic_id] ?? null;
      if ($teaser) {
          $teaser['excerpt_html'] = bb_mirror_format_snippet((string)($teaser['content_html'] ?? ''), 200, $db);
      }
      $has_more  = $reply_count > ($teaser ? 1 : 0);

      // Category color key for this topic's forum
      $cat_key  = $_forum_cat_map[(int)$topic['forum_id']] ?? 'general';

      // Full-body expand: offer "Read more" ONLY when the post actually overflows —
      // i.e. the body has genuinely more text than the (truncated) excerpt renders.
      // A cover image alone no longer forces the control (Ian: drop the read button
      // when there's no overflow). Expansion lazy-loads the full body + attachment
      // gallery (?body=<id> → _topic-body.php).
      $full_html     = (string)($topic['content_html'] ?? '');
      $plain_full    = strip_tags($full_html);
      // The excerpt shown above is the truncated snippet; if the full body is longer
      // than what it renders, there's more to read → show the control. The +8 margin
      // absorbs minor plain-length jitter (entities / the trailing ellipsis).
      $excerpt_plain_len = mb_strlen(strip_tags($excerpt));
      $show_read_more = mb_strlen($plain_full) > $excerpt_plain_len + 8;
      $embed_url     = feed_first_embed_url($full_html);

      // Topic-level reply CTA, rendered inline on the "Started by …" byline row.
      // Authenticated viewers only — anon gets no reply affordance (server 401s).
      $reply_cta = $can_post
        ? '<button class="feed-card__reply-cta feed-card__reply-cta--inline" type="button" data-frm-open'
                 . ' data-topic-id="' . $topic_id . '"'
                 . ' data-forum-id="' . (int)$topic['forum_id'] . '"'
                 . ' data-topic-title="' . htmlspecialchars((string)$topic['topic_title'], ENT_QUOTES) . '">&#8617; Reply</button>'
        : '';
    ?>
    <article class="feed-card feed-card--topic" data-lg-card="1"
             data-id="<?= $topic_id ?>" data-type="discussion" data-href="<?= $turl ?>" data-gated="0"
             data-cat="<?= htmlspecialchars($cat_key) ?>" data-topic-id="<?= $topic_id ?>" data-forum-id="<?= (int)$topic['forum_id'] ?>" data-author-id="<?= (int)($topic['author_id'] ?? 0) ?>" data-reply-count="<?= $reply_count ?>">
      <?php $av_href = $author_slug ? '/u/' . rawurlencode((string)$author_slug) : null;
            $av_t    = bb_mirror_avatar($topic['author_name'] ?: 'A', $topic['author_slug'] ?: $topic['topic_slug'], 40, $author_profiles[(int)($topic['author_id'] ?? 0)]['avatar_url'] ?? null); ?>
      <?php /* aria-label: the avatar <a> has no text content (image/initials only) —
               Lighthouse link-name failure on every discussion card (perf lane 6/11). */ ?>
      <?php if ($av_href): ?><a class="fc-avatar lg-card-avatar" href="<?= htmlspecialchars($av_href) ?>" aria-label="<?= htmlspecialchars(($topic['author_name'] ?: 'Member') . ' — profile', ENT_QUOTES) ?>"><?= $av_t ?></a>
      <?php else: ?><span class="fc-avatar lg-card-avatar"><?= $av_t ?></span><?php endif; ?>
      <div class="fc-author">
        <span class="fc-author__name lg-card-author"><?= $author_link ?></span>
        <span class="fc-author__badges"><span class="feed-card__kind-badge feed-card__kind-badge--discussion">Discussion</span><?php if (!empty($topic['forum_title'])): ?><span class="fc-cat-chip"><?= htmlspecialchars((string)$topic['forum_title'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></span>
      </div>
      <?php if (!empty($topic['forum_title'])): ?>
        <nav class="fc-category lg-card-cat"><?= htmlspecialchars($topic['forum_title'], ENT_QUOTES, 'UTF-8') ?></nav>
      <?php endif; ?>
      <time class="fc-time lg-card-time"><?= $start_time ?></time>
      <?php /* fc-activity beacon removed per Ian 6/7 — the "active … ago" pulse-dot
               was noise on the cards. CSS rules (.fc-activity/.fc-pulse) left in place
               but now unused. */ ?>
      <?php if (!empty($card_image)): ?>
        <a class="fc-cover feed-card__cover" href="<?= $turl ?>" aria-label="<?= htmlspecialchars($topic['topic_title']) ?>">
          <img class="feed-card__cover-img" src="<?= htmlspecialchars($card_image) ?>"<?= lg_cover_srcset($card_image) ?> alt=""<?= $card_dims ?> <?= lg_cover_loading_attrs() ?>>
        </a>
      <?php endif; ?>
      <h3 class="fc-title feed-card__title"><a href="<?= $turl ?>"><?= htmlspecialchars($topic['topic_title']) ?></a></h3>
      <div class="fc-excerpt feed-card__op">
        <?php if ($excerpt !== ''): ?><p class="feed-card__op-excerpt"><?= $can_post ? $excerpt : lg_scrub_anon_contacts((string)$excerpt) ?></p><?php endif; ?>
        <?php if ($show_read_more): ?>
          <div class="feed-card__full-body" hidden></div>
          <button class="feed-card__read-more" type="button" data-topic-id="<?= $topic_id ?>" data-state="collapsed">Read more &#9660;</button>
        <?php endif; ?>
        <?php if ($embed_url): ?><div class="feed-card__embed" data-embed-url="<?= htmlspecialchars($embed_url, ENT_QUOTES, 'UTF-8') ?>"></div><?php endif; ?>
      </div>
      <?php feed_render_tags(feed_parse_pg_array($topic['tags'] ?? null)); ?>
      <?php /* fc-facepile — up to 3 recent repliers + N replies (NEW; desktop-only ≤640). */
      $fp = $reply_facepile[$topic_id] ?? [];
      if ($fp): ?>
        <div class="fc-facepile" data-topic-id="<?= $topic_id ?>" role="button" tabindex="0" aria-label="View replies">
          <span class="fc-facepile__stack"><?php foreach ($fp as $rp): ?><span class="fc-facepile__av"><?= bb_mirror_avatar($rp['author_name'] ?: 'A', $rp['author_slug'] ?: 'r', 26, $rp['avatar_url'] ?? null) ?></span><?php endforeach; ?></span>
          <span class="fc-facepile__count"><?= $reply_count ?> <?= $reply_count === 1 ? 'reply' : 'replies' ?></span>
        </div>
      <?php endif; ?>
      <?php /* fc-actions = the reactions-comments SURFACE lane's engagement-bar slot. */ ?>
      <div class="fc-actions">
        <?php feed_reactions_bar('topic', $topic_id, $card_reaction_counts['topic:' . $topic_id] ?? []); ?>
        <?php feed_action_bar($reply_count); ?>
        <?php feed_save_btn('topic', $topic_id); ?>
        <?= $reply_cta /* card-level CTA: now hidden by CSS (composer is the reply entry, Ian) but KEPT as the topic/forum data-source that nested reply buttons read via frmOpen() */ ?>
        <?php /* expand-all RETIRED (Ian): SPLIT into "Read more" (full post BODY only,
                 in the .fc-excerpt block above) + the reply-count control (.fc-facepile →
                 opens the thread). No single chevron opens both anymore. */ ?>
        <button class="feed-card__compact-expand" type="button" aria-expanded="false" title="Show full post" aria-label="Show full post"><span class="feed-card__compact-expand-icon" aria-hidden="true">&#9662;</span></button>
      </div>
      <?php /* ONE teaser reply on the card face (Buck 2026-06-07 — reverses the earlier
               teaser-free pass, confirmed w/ Ian): show the MOST-ACTIVE reply inline with
               its photo shown inline (collapse_image=false, not the deferred "Show image"
               button), then "View N replies" opens the full thread for the rest. The
               .fc-facepile reply-count control + the lazy target + the (CSS-hidden)
               .feed-card__expand trigger all stay — forums.js .fc-facepile click →
               .feed-card__expand → ?replies=<id>. */ ?>
      <?php if ($reply_count > 0): ?>
        <div class="fc-replies feed-card__replies">
          <?php if ($teaser) bb_mirror_render_reply_stub($teaser, false, false, true); /* inline photo */ ?>
          <!-- Full thread lazy-loads here on click (see forums.js + ?replies=<id>) -->
          <div class="feed-card__replies-full" hidden></div>
          <button class="feed-card__expand" type="button" data-topic-id="<?= $topic_id ?>">View <?= $reply_count ?> <?= $reply_count === 1 ? 'reply' : 'replies' ?> &#9660;</button>
        </div>
      <?php endif; ?>
      <?php /* fc-composer — PERSISTENT reply (the "reply is lost" fix). Authed only;
               posts via the existing /reply path (forums.js). NEW; desktop-only ≤640. */ ?>
      <?php if ($can_post): ?>
        <div class="fc-composer" data-topic-id="<?= $topic_id ?>" data-forum-id="<?= (int)$topic['forum_id'] ?>">
          <span class="fc-composer__av"><?= bb_mirror_avatar('You', 'you', 30, null) ?></span>
          <span class="fc-composer__wrap">
            <input class="fc-composer__input" type="text" placeholder="Add a reply&hellip; &#9998; for formatting &amp; photos" aria-label="Add a reply">
            <?php /* Expand into the full rich (Quill) reply editor — reuses the feed-reply
                     modal (image upload + formatting + nested-reply path). Carries topic/
                     forum so frmOpen() targets this thread. */ ?>
            <button type="button" class="fc-composer__rich" data-topic-id="<?= $topic_id ?>"
                    data-forum-id="<?= (int)$topic['forum_id'] ?>"
                    data-topic-title="<?= htmlspecialchars((string)$topic['topic_title'], ENT_QUOTES) ?>"
                    title="Rich editor (formatting + images)" aria-label="Open the rich reply editor">&#9998;</button>
            <button type="button" class="fc-composer__send" disabled>Reply</button>
          </span>
          <span class="fc-composer__status" role="status"></span>
        </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>

  </div><!-- .feed -->

  <?php
    $has_next = count($topics) >= $card_limit;
    if ($has_next):
      $next_offset = $raw_offset + $card_limit;
      $qs_parts = [];
      if ($forum_slug !== '') $qs_parts[] = 'forum_slug=' . urlencode($forum_slug);
      $qs_parts[] = 'sort=' . urlencode($sort_param);
      $qs_parts[] = 'offset=' . $next_offset;
      // Carry the random-shuffle seed so infinite scroll keeps ONE coherent order.
      if ($sort_param === 'random') $qs_parts[] = 'seed=' . $rand_seed;
      // Carry the frozen hot clock for the same reason (see $hot_now above).
      if ($sort_param === 'hot')    $qs_parts[] = 'hnow=' . $hot_now;
      // Carry active Hub filters into the next page (Type/Cat/Author/Search).
      foreach (hub_query_params() as $k => $v) $qs_parts[] = $k . '=' . urlencode($v);
  ?>
    <div class="feed-more">
      <a class="feed-more__btn"
         href="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/') ?>?<?= implode('&', $qs_parts) ?>">
        Load older activity
      </a>
    </div>
  <?php endif; ?>

  <?php endif; ?>
  </div><!-- #hub-feed-results -->
</div><!-- .page.feed-page -->

<?php bb_mirror_chrome_footer(); ?>
