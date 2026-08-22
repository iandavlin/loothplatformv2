<?php
/**
 * archive-poc/standalone/render.php
 *
 * Standalone CPT article renderer (layout-standalone lane).
 *
 * Reads a materialized blob from discovery.article_blobs (Postgres), gates
 * per-viewer, runs the portable lg-layout-v2 engine, wraps in the shared
 * site shell. Zero WordPress boot.
 *
 * Routing: nginx passes two fastcgi_param values (available as $_SERVER):
 *   LG_POST_TYPE  — the WP post_type slug (e.g. "post-imgcap")
 *   LG_SLUG       — the post slug extracted from the URL (e.g. "jazz-bass")
 *
 * Admin preview: ?as=public|lite|pro overrides viewer state (same as archive-poc).
 *
 * CLI (gating self-check, one blob by post_id):
 *   LG_POST_TYPE=post-imgcap LG_SLUG=jazz-bass php render.php --proof
 */

declare(strict_types=1);

use LG\LayoutV2\Manifest;
use LG\LayoutV2\Pipeline;
use LG\LayoutV2\Theme;
use LG\LayoutV2\TierResolver;

$DIR = __DIR__;
require $DIR . '/engine/src/Autoload.php';
require $DIR . '/wp-shim.php';

// config.php: lg_archive_poc_pdo() + lg_archive_poc_whoami() + constants.
// Force Postgres DSN before the factory reads getenv() — same trick as materializer.
if (!getenv('LG_ARCHIVE_POC_DSN')) {
    putenv('LG_ARCHIVE_POC_DSN=pgsql:host=/var/run/postgresql;dbname=looth');
}
require_once dirname($DIR) . '/config.php';
// LG_COMMENTS_TYPES — which content types the postgres comment store covers, so
// the modal can point at the WP-free read endpoint for those (and fall back to WP
// for the rest). Definitions only; no side effects.
require_once dirname($DIR) . '/api/v0/_comments.php';

Manifest::configure($DIR . '/engine/blocks');

$IS_CLI = (PHP_SAPI === 'cli');

/* ── Routing ─────────────────────────────────────────────────────────── */
$postType = (string) ($_SERVER['LG_POST_TYPE'] ?? getenv('LG_POST_TYPE') ?? '');
$slug     = (string) ($_SERVER['LG_SLUG']      ?? getenv('LG_SLUG')      ?? '');
$postId   = (int)    ($_SERVER['LG_POST_ID']   ?? getenv('LG_POST_ID')   ?? 0);
$slug     = preg_replace('/[^a-z0-9\-]/i', '', $slug);

if ($postType === '' || ($slug === '' && $postId <= 0)) {
    lg_standalone_fail($IS_CLI, 404, 'missing post_type + (slug or id)');
}

/* ── Blob lookup ─────────────────────────────────────────────────────── */
/* By post_id when the permalink is id-based (e.g. /document/<id>/); else by slug. */
try {
    $db = lg_archive_poc_pdo();
    if ($postId > 0) {
        $stmt = $db->prepare('SELECT blob FROM article_blobs WHERE post_type = :pt AND post_id = :id LIMIT 1');
        $stmt->execute([':pt' => $postType, ':id' => $postId]);
    } else {
        $stmt = $db->prepare('SELECT blob FROM article_blobs WHERE post_type = :pt AND slug = :sl ORDER BY materialized_at DESC, post_id DESC LIMIT 1');
        $stmt->execute([':pt' => $postType, ':sl' => $slug]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('lg-render: db error: ' . $e->getMessage());
    lg_standalone_fail($IS_CLI, 500, 'db error');
}

if (!$row) {
    /* Blob-miss fallback: not every managed-CPT post has a standalone blob (e.g.
       only a fraction of videos/sponsors are materialized). Rather than 404 the
       uncovered majority, hand the ORIGINAL permalink back to WordPress via an
       internal nginx location (X-Accel-Redirect). nginx re-runs the WP front
       controller for this REQUEST_URI; covered posts render standalone-fast,
       uncovered ones get the normal WP page. CLI keeps the hard 404 (proof/debug). */
    if (!$IS_CLI) {
        $origPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        // Visibility into uncovered posts: every blob-miss that falls back to WP
        // is logged so we can see which managed-CPT permalinks still lack a blob.
        error_log('lg-render: blob-miss -> WP fallback: ' . $postType . '/'
            . ($postId > 0 ? "#$postId" : $slug) . ' (' . $origPath . ')');
        if ($origPath !== false && $origPath !== '') {
            header('X-Accel-Redirect: /__wp_render' . $origPath);
            exit;
        }
    }
    lg_standalone_fail($IS_CLI, 404, "not found: $postType/" . ($postId > 0 ? "#$postId" : $slug));
}

$blob = json_decode((string) $row['blob'], true);
if (!is_array($blob) || !is_array($blob['layout'] ?? null) || !is_array($blob['post_context'] ?? null)) {
    lg_standalone_fail($IS_CLI, 500, 'blob malformed');
}
$layout      = lg_standalone_normalize_blocks($blob['layout']);
$postContext = $blob['post_context'];
// CPT slug isn't stored inside post_context; inject it from the route
// so get_post_type() (post-header type badge) resolves correctly.
$postContext['post_type'] = $postType;

// SEO meta-description source: the curated content_item.excerpt (one indexed
// read by permalink). Far cleaner than scraping the rendered article, whose
// first text is the post-header type/tier badge chrome + a repeated title.
$postContext['_seo_excerpt'] = '';
try {
    $exStmt = lg_archive_poc_pdo()->prepare('SELECT excerpt FROM content_item WHERE url = :u LIMIT 1');
    $exStmt->execute([':u' => (string) ($postContext['permalink'] ?? '')]);
    $postContext['_seo_excerpt'] = (string) ($exStmt->fetchColumn() ?: '');
} catch (\Throwable $e) {
    // Non-fatal: lg_standalone_page() falls back to stripping $articleHtml.
    error_log('lg-render: seo excerpt lookup: ' . $e->getMessage());
}

/* ── Proof mode (CLI only) ───────────────────────────────────────────── */
if ($IS_CLI && in_array('--proof', $argv ?? [], true)) {
    exit(lg_standalone_proof($layout, $postContext, $blob));
}

/* ── Viewer ──────────────────────────────────────────────────────────── */
$previewAs = '';
if (!$IS_CLI) {
    $pa = $_GET['as'] ?? '';
    if (in_array($pa, ['public', 'lite', 'pro'], true)) {
        // Preview override is an ADMIN/EDITOR tool ONLY. Honoring it for any
        // visitor lets a logged-out user append ?as=pro to a gated post and
        // recover the embed URL/ID from the DOM (data-yt-id / i.ytimg). Gate it
        // behind the same edit_archive_poc cap that surfaces the Edit pill.
        $whoPrev = lg_archive_poc_whoami();   // static-cached this request
        if (($whoPrev['capabilities']['edit_archive_poc'] ?? false) === true) $previewAs = $pa;
    }
}

if ($previewAs !== '') {
    [$viewer, $authed, $shellTier, $viewerName] = lg_standalone_viewer_from_preview($previewAs);
} else {
    [$viewer, $authed, $shellTier, $viewerName] = lg_standalone_viewer_from_whoami();
}

/* ── Edit affordance ─────────────────────────────────────────────────────
   Admins/editors (edit_archive_poc cap) or the post's own author may jump to the
   WordPress FE editor via ?lg_edit=1 — nginx routes that flagged URL to WP, where
   the real plugin editor + capability check take over. Hidden in preview mode. */
$editUrl = '';
$composeUrl = '';
if (!$IS_CLI && $previewAs === '') {
    $who = lg_archive_poc_whoami();   // static-cached this request — no second HTTP call
    if (!empty($who['authenticated'])) {
        $capEdit  = (($who['capabilities']['edit_archive_poc'] ?? false) === true);
        $vid      = (int) ($who['wp_user_id'] ?? 0);
        $isAuthor = $vid > 0 && $vid === (int) ($postContext['author']['id'] ?? -1);
        if ($capEdit || $isAuthor) {
            $editUrl = rtrim((string) ($postContext['permalink'] ?? ''), '/') . '/?lg_edit=1';
            /* IAN SUPERSEDED OPTION A, 2026-08-21: "I don't think we need the option
               for text and data." The two-line menu is gone; $composeUrl is now the
               Edit pill's own destination rather than one row of a choice.

               ONLY FOR TYPES THE COMPOSE ROUTE ACTUALLY RENDERS. An Edit pill
               pointing at a compose form that does not exist for this type would be
               a control that 404s, which is the UI-lies class Ian has ruled against
               repeatedly — so the pill falls back to ?lg_edit=1 instead. */
            if ($postType === 'loothprint' && lg_standalone_compose_on()) {
                /* post_id, NOT id — the established key here (see the comments
                   affordance and the reactions block, which both read post_id).
                   'id' is absent from post_context and would have produced a
                   link to id=0, i.e. a compose form for nothing. */
                $lgpid = (int) ($postContext['post_id'] ?? $postId);
                if ($lgpid > 0) {
                    $composeUrl = '/compose/?type=loothprint&id=' . $lgpid;
                }
            }
        }
    }
}

/**
 * Is the front-end compose route switched on? Reads THE SAME PAIR the WP side does.
 *
 * platform/config/frontend-compose.php's own header argues that this feature must
 * have ONE flag read by both runtimes, because two can disagree in both directions
 * — and a "Details & files" link pointing at a route that is switched off is
 * exactly the disagreement it warns about. So this does not keep its own copy of
 * the answer: tracked config first, then the untracked per-box override, same
 * order and same rules as lg_fc_enabled().
 *
 * The path is __DIR__-relative and that RESOLVES, verified rather than assumed:
 * /srv/archive-poc is a symlink into the serving checkout, so ../../platform/config
 * lands on the very files WordPress reads. (The usual trap runs the other way — a
 * symlinked docroot script whose __DIR__ escapes into the repo. Here that is the
 * point.)
 *
 * Fails CLOSED: anything unreadable or malformed means no link.
 */
function lg_standalone_compose_on(): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    $on   = false;
    $base = __DIR__ . '/../../platform/config/';
    if (is_readable($base . 'frontend-compose.php')) {
        $raw = @include $base . 'frontend-compose.php';
        $on  = (is_array($raw) && ($raw['enabled'] ?? false) === true);
    }
    if (is_readable($base . 'frontend-compose.local.php')) {
        $lraw = @include $base . 'frontend-compose.local.php';
        if (is_array($lraw) && array_key_exists('enabled', $lraw)) {
            $on = ($lraw['enabled'] === true);
        }
    }
    return $on;
}

/* ── Comments affordance ─────────────────────────────────────────────────
   Count baked at materialize. The modal iframes the WP comments-only view
   (?lg_comments=1). Shown when there are comments OR the thread is open; logged-out
   users still see the count (teaser) and the read-only thread + a login prompt. */
$commentsCount  = (int) ($postContext['comments_count'] ?? 0);
$commentsOpen   = !empty($postContext['comments_open']);
$commentsItemId = (int) ($postContext['post_id'] ?? $postId);
// Covered content types read from the postgres store via the WP-free endpoint
// (~30ms, no WP boot); uncovered managed CPTs (sponsor-post, etc.) keep the old
// WP comments-frame path until the store's scope widens.
$commentsCovered = in_array($postType, LG_COMMENTS_TYPES, true) && $commentsItemId > 0;
// For covered types over HTTP, read the live badge count straight from
// discovery.comments — the WP-baked comments_count only re-bakes when the post's
// content changes, so it goes stale as members post. One cheap COUNT(*), no WP
// boot (~same pool as the modal read). Fall back to the baked value on any error.
if ($commentsCovered && !$IS_CLI) {
    try {
        $commentsCount = lg_comments_count(lg_comments_pdo(), $postType, $commentsItemId);
    } catch (Throwable $e) {
        error_log('[lg-comments] live count fallback: ' . $e->getMessage());
    }
}
$commentsUrl   = (!$IS_CLI && ($commentsCount > 0 || $commentsOpen))
    ? ($commentsCovered
        ? '/archive-api/v0/comments?post_type=' . rawurlencode($postType) . '&item_id=' . $commentsItemId
        : rtrim((string) ($postContext['permalink'] ?? ''), '/') . '/?lg_comments=1')
    : '';

/* ── Render ──────────────────────────────────────────────────────────── */
$articleHtml = lg_standalone_render_article($layout, $postContext, $viewer, $authed);
$css         = $GLOBALS['LG_STANDALONE_LAST_CSS'] ?? '';

if (!$IS_CLI) header('Content-Type: text/html; charset=utf-8');
echo lg_standalone_page($postContext, $articleHtml, $css, $authed, $shellTier, $viewerName, $previewAs, $editUrl, $commentsUrl, $commentsCount, $composeUrl);


/* ════════════════════════════════════════════════════════════════════════
   Helpers
   ════════════════════════════════════════════════════════════════════════ */

function lg_standalone_viewer_from_whoami(): array {
    $whoami = lg_archive_poc_whoami();
    $authed = !empty($whoami['authenticated']);
    $tier   = $authed && in_array($whoami['tier'] ?? '', ['public', 'lite', 'pro'], true)
              ? $whoami['tier'] : 'public';
    $name   = $authed ? (string) ($whoami['display_name'] ?? '') : '';
    // Admins/editors (edit_archive_poc cap — same signal that shows the Edit pill)
    // bypass tier gates with a preview badge, instead of getting walled out.
    $isAdmin = ($whoami['capabilities']['edit_archive_poc'] ?? false) === true;
    [$viewer] = lg_standalone_build_viewer($authed, $tier, $isAdmin);
    return [$viewer, $authed, $tier, $name];
}

function lg_standalone_viewer_from_preview(string $as): array {
    $authed = ($as !== 'public');
    // Preview mode deliberately views AS a tier — never admin, so the gate shows as a member sees it.
    [$viewer] = lg_standalone_build_viewer($authed, $as, false);
    return [$viewer, $authed, $as, $authed ? 'Preview' : ''];
}

function lg_standalone_build_viewer(bool $authed, string $tier, bool $isAdmin = false): array {
    $TAX = ['lite' => 'looth-lite', 'pro' => 'looth-pro'];
    if (!$authed) return [TierResolver::anonymous()];
    $tiers = isset($TAX[$tier]) ? [$TAX[$tier]] : [];
    return [['is_admin' => $isAdmin, 'is_delinquent' => false, 'tiers' => $tiers, 'preview_role' => null]];
}

/** Live sponsor-page carousel loop: newest sponsor-post / sponsor-product cards
 *  for an author, straight from the discovery index (no WP boot). Drives the
 *  featured-products + recent-posts blocks so published content flows to the
 *  page automatically. Returns null on error so the blocks fall back to the
 *  baked items carried in the blob. */
function lg_standalone_sponsor_feed(string $cpt, int $authorId, int $limit): ?array {
    if ($authorId <= 0 || $limit <= 0) return [];
    if (!in_array($cpt, ['sponsor-post', 'sponsor-product'], true)) return [];
    try {
        $db = lg_archive_poc_pdo();
        $st = $db->prepare(
            'SELECT title, url, thumb_url, excerpt, published_at
               FROM content_item
              WHERE cpt = :cpt AND author_id = :aid
              ORDER BY published_at DESC
              LIMIT :lim'
        );
        $st->bindValue(':cpt', $cpt);
        $st->bindValue(':aid', $authorId, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = strtotime((string) ($r['published_at'] ?? ''));
            $out[] = [
                'title'   => (string) ($r['title'] ?? ''),
                'url'     => (string) ($r['url'] ?? ''),
                'image'   => (string) ($r['thumb_url'] ?? ''),
                'excerpt' => (string) ($r['excerpt'] ?? ''),
                'date'    => $ts ? date('M j, Y', $ts) : '',
            ];
        }
        return $out;
    } catch (\Throwable $e) {
        error_log('lg_standalone_sponsor_feed: ' . $e->getMessage());
        return null;
    }
}

function lg_standalone_render_article(array $layout, array $pc, array $viewer, bool $authed): string {
    $GLOBALS['LG_PC']          = $pc + ['layout' => $layout];
    $GLOBALS['LG_VIEWER_AUTH'] = $authed;
    $ctx = [
        'viewer'         => $viewer,
        'editor_mode'    => false,
        'can_edit'       => false,
        'media_resolver' => 'lg_standalone_media_resolver',
        'post_id'        => (int) ($pc['post_id'] ?? 0),
        /* post_type drives Renderer::autoGateTypes() — a Loothprint gates its
           FILE, a video post gates its VIDEO. Set on $postContext at the top of
           this file from LG_POST_TYPE, so it is always present here. */
        'post_type'      => (string) ($pc['post_type'] ?? ''),
        'post_tier'      => (string) ($pc['post_tier'] ?? ''),
        // Live feed loop for the sponsor-page carousels (featured-products /
        // recent-posts): queries the discovery index by author + cpt at render
        // time, so a newly published sponsor post/product appears with no
        // re-materialize. Returns null on error -> blocks fall back to baked items.
        'sponsor_feed'   => 'lg_standalone_sponsor_feed',
    ];
    // Brand tokens + dash style overrides from the materialized dash snapshot
    // (dash-theme.json — global lg_layout_v2_brand_palette + _block_styles, kept
    // fresh by the materializer / dash-save hook). The WP renderer reads these
    // from wp_options every render; standalone reads the snapshot (no WP boot).
    [$brandTokens, $dashOverrides] = lg_standalone_theme();
    $result = Pipeline::run($layout, $brandTokens, $dashOverrides, $ctx);
    $GLOBALS['LG_STANDALONE_LAST_CSS'] = (string) ($result['css'] ?? '');
    return lg_standalone_lazyload_imgs((string) ($result['html'] ?? ''));
}

/** Mirror WP's wp_filter_content_tags: give content <img>s lazy-loading + async
 *  decoding so the browser doesn't eagerly fetch two-dozen images on load. Only
 *  touches imgs that don't already declare a loading strategy — so the post-header
 *  hero (loading="eager" fetchpriority="high") and the avatar (already lazy) are
 *  left as-is. srcset/sizes is a separate, materializer-side concern (size variants
 *  aren't in the blob yet). */
function lg_standalone_lazyload_imgs(string $html): string {
    if (strpos($html, '<img') === false) return $html;
    return (string) preg_replace_callback(
        '~<img\b(?![^>]*\bloading=)[^>]*>~i',
        function (array $m): string {
            return preg_replace('~\s*/?>$~', '', $m[0]) . ' loading="lazy" decoding="async">';
        },
        $html
    );
}

/** Load the dash theme snapshot → [resolved brand tokens, dash style overrides].
 *  Falls back to engine defaults if the snapshot is missing/unreadable. */
function lg_standalone_theme(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $brand = []; $styles = [];
    $f = __DIR__ . '/dash-theme.json';
    if (is_readable($f)) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j)) {
            $brand  = is_array($j['brand']  ?? null) ? $j['brand']  : [];
            $styles = is_array($j['styles'] ?? null) ? $j['styles'] : [];
        }
    }
    $cache = [Theme::resolve($brand), $styles];
    return $cache;
}

/** Defensive layout normalization: the engine reads block props flattened onto the
 *  block node ($args = $block), but a few posts store them under a nested {props:{…}}
 *  wrapper the engine never looks inside (so variant/tagline/etc. silently vanish).
 *  Flatten any such wrapper here so both formats render identically. No-op for the
 *  already-flattened majority. Recurses into container children (blocks / columns). */
function lg_standalone_normalize_blocks(array $layout): array {
    $walk = function (array $blocks) use (&$walk): array {
        foreach ($blocks as &$b) {
            if (!is_array($b)) continue;
            if (isset($b['props']) && is_array($b['props'])) { $b += $b['props']; unset($b['props']); }
            if (isset($b['blocks']) && is_array($b['blocks'])) $b['blocks'] = $walk($b['blocks']);
            if (isset($b['columns']) && is_array($b['columns'])) {
                foreach ($b['columns'] as &$col) {
                    if (is_array($col) && isset($col['blocks']) && is_array($col['blocks'])) $col['blocks'] = $walk($col['blocks']);
                }
                unset($col);
            }
        }
        unset($b);
        return $blocks;
    };
    if (isset($layout['blocks']) && is_array($layout['blocks'])) $layout['blocks'] = $walk($layout['blocks']);
    return $layout;
}

/** Externalize the engine CSS bundle to a content-hashed, cacheable file under
 *  /archive-poc/assets/ and return its URL. The bundle is global + deterministic
 *  (CssBuilder over Manifest::all() + the shared theme), so it's byte-identical for
 *  every standalone page → written once, then served from browser cache on every
 *  subsequent navigation. Replaces re-inlining ~105KB per page. Returns '' on write
 *  failure so the caller can fall back to inline. */
function lg_standalone_css_href(string $css): string {
    if ($css === '') return '';
    $dir  = __DIR__ . '/../web/assets';
    $hash = substr(md5($css), 0, 16);
    $file = "$dir/lg-v2-bundle.$hash.css";
    $url  = "/archive-poc/assets/lg-v2-bundle.$hash.css";
    if (is_file($file)) return $url;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return '';
    // Atomic write: a concurrent reader never sees a half-written bundle.
    $tmp = "$file.tmp." . getmypid();
    if (@file_put_contents($tmp, $css) === false) return '';
    if (!@rename($tmp, $file)) { @unlink($tmp); return is_file($file) ? $url : ''; }
    return $url;
}

/** Externalize the engine front-end JS to a content-hashed, cacheable file under
 *  /archive-poc/assets/ and return its URL. This is the SAME assets/lg-front.js
 *  the WP plugin enqueues (vendored into engine/assets/), so the standalone path
 *  wires the identical front-end behaviors — the image-block LIGHTBOX above all,
 *  plus embed-facade click-to-play, share-copy, gallery carousel, and the
 *  broken-image placeholder. Without it the lightbox markup (data-lg-lightbox)
 *  and CSS (.lg-lightbox, already in the bundle) are present but inert. Mirrors
 *  lg_standalone_css_href: global + deterministic → written once, then browser-
 *  cached. Returns '' on read/write failure so the caller can fall back to inline. */
function lg_standalone_front_js_href(): string {
    $src = __DIR__ . '/engine/assets/lg-front.js';
    $js  = @file_get_contents($src);
    if ($js === false || $js === '') return '';
    $dir  = __DIR__ . '/../web/assets';
    $hash = substr(md5($js), 0, 16);
    $file = "$dir/lg-v2-front.$hash.js";
    $url  = "/archive-poc/assets/lg-v2-front.$hash.js";
    if (is_file($file)) return $url;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return '';
    $tmp = "$file.tmp." . getmypid();
    if (@file_put_contents($tmp, $js) === false) return '';
    if (!@rename($tmp, $file)) { @unlink($tmp); return is_file($file) ? $url : ''; }
    return $url;
}

function lg_standalone_page(array $pc, string $articleHtml, string $css, bool $authed, string $tier, string $viewerName, string $previewAs, string $editUrl = '', string $commentsUrl = '', int $commentsCount = 0, string $composeUrl = ''): string {
    // Title: the stored title is ALREADY HTML-entity-encoded (e.g. a curly
    // apostrophe arrives as `&#8217;`). htmlspecialchars() alone re-escapes the
    // `&` → `&amp;#8217;`, which shows as literal garbage in the <title> and
    // search snippet. Decode once to the real character, THEN escape once.
    $titleRaw = html_entity_decode((string) ($pc['title'] ?? 'Looth Group'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title    = htmlspecialchars($titleRaw, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

    // ── SEO <head> data (dependency-free; covers the 642 indexable content URLs) ──
    $seoPath  = parse_url((string) ($pc['permalink'] ?? ''), PHP_URL_PATH) ?: '/';
    $seoCanon = LG_ARCHIVE_POC_CANONICAL_BASE . $seoPath;        // per-env baked host
    // Description: prefer the curated excerpt (set in the routing flow above);
    // else flatten the rendered article to plain text. For gated posts
    // $articleHtml is the public teaser (and the page is noindex anyway), so
    // nothing private leaks. Trim to ~155 chars below.
    $seoDescRaw = trim(html_entity_decode((string) ($pc['_seo_excerpt'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($seoDescRaw === '') {
        $seoDescRaw = trim(preg_replace('/\s+/', ' ',
            html_entity_decode(strip_tags($articleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
    $seoDescRaw = trim(preg_replace('/\s+/', ' ', $seoDescRaw));
    if ($seoDescRaw === '') $seoDescRaw = $titleRaw;
    if (function_exists('mb_strlen') && mb_strlen($seoDescRaw) > 160) {
        $seoDescRaw = rtrim(mb_substr($seoDescRaw, 0, 157)) . '…';
    }
    $seoDesc   = htmlspecialchars($seoDescRaw, ENT_QUOTES, 'UTF-8');
    $seoImg    = (string) ($pc['featured_image']['url'] ?? '');
    $seoImgEsc = htmlspecialchars($seoImg, ENT_QUOTES, 'UTF-8');
    $seoCanEsc = htmlspecialchars($seoCanon, ENT_QUOTES, 'UTF-8');
    $seoAuthor = (string) ($pc['author']['display_name'] ?? '');
    $seoIsVideo = ($pc['post_type'] ?? '') === 'post-type-videos';
    $seoOgType  = $seoIsVideo ? 'video.other' : 'article';
    $seoDateIso = '';
    if (!empty($pc['date'])) { $ts = strtotime((string) $pc['date']); if ($ts) $seoDateIso = date('c', $ts); }
    // JSON-LD: VideoObject needs name+description+thumbnailUrl+uploadDate to be
    // valid, so only use it when all are present; else Article (always valid).
    $seoLdType = ($seoIsVideo && $seoImg !== '' && $seoDateIso !== '') ? 'VideoObject' : 'Article';
    $seoLd = [
        '@context' => 'https://schema.org',
        '@type'    => $seoLdType,
        ($seoLdType === 'VideoObject' ? 'name' : 'headline') => $titleRaw,
        'url'              => $seoCanon,
        'mainEntityOfPage' => $seoCanon,
    ];
    if ($seoDescRaw !== '')   $seoLd['description'] = $seoDescRaw;
    if ($seoImg !== '')       $seoLd[$seoLdType === 'VideoObject' ? 'thumbnailUrl' : 'image'] = $seoImg;
    if ($seoDateIso !== '')   $seoLd[$seoLdType === 'VideoObject' ? 'uploadDate' : 'datePublished'] = $seoDateIso;
    if ($seoAuthor !== '')    $seoLd['author'] = ['@type' => 'Person', 'name' => $seoAuthor];
    $seoLd['publisher'] = ['@type' => 'Organization', 'name' => 'The Looth Group'];
    // Embed/modal mode (?embed=1): render the article + comments WITHOUT the shared
    // site-header/footer chrome, so the Hub can iframe a content card into a modal with
    // no double header. Default OFF — the normal full standalone page (and the desktop
    // click-through, which hits this same renderer) is unchanged.
    $embed = !empty($_GET['embed']);

    require_once '/srv/lg-shared/site-header.php';
    require_once '/srv/lg-shared/site-footer.php';

    ob_start();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?> — Looth Group</title>
<meta name="robots" content="<?= $tier === 'public' ? 'index, follow' : 'noindex, follow' ?>">
<meta name="description" content="<?= $seoDesc ?>">
<link rel="canonical" href="<?= $seoCanEsc ?>">
<meta property="og:type" content="<?= $seoOgType ?>">
<meta property="og:title" content="<?= $title ?>">
<meta property="og:description" content="<?= $seoDesc ?>">
<meta property="og:url" content="<?= $seoCanEsc ?>">
<?php if ($seoImg !== ''): ?><meta property="og:image" content="<?= $seoImgEsc ?>">
<?php endif; ?><meta property="og:site_name" content="Looth Group">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $title ?>">
<meta name="twitter:description" content="<?= $seoDesc ?>">
<?php if ($seoImg !== ''): ?><meta name="twitter:image" content="<?= $seoImgEsc ?>">
<?php endif; ?>
<script type="application/ld+json"><?= json_encode($seoLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<?php $cssHref = lg_standalone_css_href($css); ?>
<?php if ($cssHref !== ''): ?>
<link rel="stylesheet" href="<?= $cssHref ?>">
<?php else: /* write failed (e.g. assets dir not writable) — fall back to inline */ ?>
<style>
<?= $css ?>
</style>
<?php endif; ?>
<style>
body { margin: 0; background: #f0eee8; color: #323532;
       font-family: 'Jost', system-ui, -apple-system, sans-serif; }
/* No width clamp here — the engine's .lg-article wrapper owns the readable-column
   width (var(--lg-article-max), dash-adjustable) and centers itself, and the hero
   full-bleeds out of it. A max-width here just bottlenecks the dash. */
/* No top padding: the first block is always the full-bleed post-header hero, which
   should sit flush under the nav. A top pad here leaves a band above the banner.
   (Bottom pad stays for breathing room before the site footer.) */
.lg-standalone-main { padding-block: 0 64px; }
/* ── THE EDIT DOOR MOVED INTO THE DOCK (Ian, 2026-08-21) ──────────────────────
   "could we put it in the bottom stack of sticky buttons ?" — and, superseding
   his own Option A of 8/16: "I don't think we need the option for text and data.
   It can be one button that kicks to the form they filled out."

   So the fixed bottom-RIGHT control and its two-line menu are DELETED, not
   hidden: ~30 lines of menu CSS, the menu markup and its open/close script all
   go, because a control that is merely display:none is one CSS edit away from
   coming back and reads to the next person as a thing still in service.

   Edit now reuses .lg-dock__btn exactly as Save did before it, so the dock stays
   ONE row of ONE shape — the reason recorded on .lg-dock__save, applied again.

   ⚠️ ONE CONTROL FOR EVERYONE, INCLUDING ADMINS — and this reverses an answer
   given hours earlier. A second "Page text" pill was built for edit_archive_poc
   holders, on the argument that collapsing to one door would leave the layout
   editor with no click-path (the site header's Edit goes to /wp-admin/,
   measured). Ian was offered that from a DESCRIPTION and took it; then he looked
   at the running page and reversed it, 2026-08-21:

       "I was thinking we were moving away from the micro modal and just haveing
        the edit button kick to the form page."
       "The text is handled on the form."
       "I don't know how we got to the modal thing."

   He is right, and his second sentence is why: the compose form carries the
   write-up as a rich-text field ("Tell people about it"), so the page-text editor
   is not a second thing somebody might want — it is a second answer to a question
   that now has one. Deleted rather than hidden, and gate 69 asserts its absence
   FOR A CAP-HOLDER specifically, since that is the only viewer for whom it could
   have survived. */
.lg-dock__edit svg { width: 15px; height: 15px; flex: 0 0 auto; }
.lg-standalone-comments { position: fixed; left: 18px; bottom: 18px; z-index: 50;
  display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; background: #fff;
  color: #323532; border: 1px solid #d8d2c4; border-radius: 999px; font-size: 14px;
  font-weight: 600; text-decoration: none; box-shadow: 0 2px 10px rgba(0,0,0,.15); }
.lg-standalone-comments:hover { background: #f4f1e8; }
.lg-cmodal[hidden] { display: none; }
.lg-cmodal { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center;
  padding: 20px; box-sizing: border-box; }
.lg-cmodal__backdrop { position: absolute; inset: 0; background: rgba(26,29,26,.5); -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px); }
/* Panel hugs its content (frame is auto-sized to the comments view via a
   postMessage height handshake — see the script below + lg-comments-frame.php),
   so a sparse thread no longer floats in a tall empty box. Caps at 86vh. */
.lg-cmodal__panel { position: relative; width: min(640px,96vw); max-height: min(86vh,920px); background: #fff;
  border-radius: 16px; overflow: hidden; box-shadow: 0 16px 48px rgba(0,0,0,.28), 0 4px 12px rgba(0,0,0,.12);
  display: flex; flex-direction: column; }
.lg-cmodal__head { display: flex; align-items: center; gap: 10px; padding: 14px 18px;
  border-bottom: 1px solid #eee7da; background: #f7f5f2; }
.lg-cmodal__head-title { font-weight: 700; font-size: 16px; color: #1a1d1a; }
.lg-cmodal__head-count { font-size: 13px; font-weight: 600; color: #87986a; }
.lg-cmodal__close { margin-left: auto; background: none; border: 0; width: 32px; height: 32px;
  display: inline-flex; align-items: center; justify-content: center; border-radius: 8px;
  font-size: 22px; line-height: 1; cursor: pointer; color: #6b6b66; transition: background .15s, color .15s; }
.lg-cmodal__close:hover { background: #ece7db; color: #1a1d1a; }
.lg-cmodal__frame { width: 100%; border: 0; height: 320px; background: #fff; transition: height .2s ease; }
/* ── Sticky bottom-left dock: Back-to-Hub + post React + Comments, together
      (Ian 2026-06-11: "sticky with the comments"). Replaces the in-flow footer
      bar on the standalone page. */
.lg-standalone-dock { position: fixed; left: 18px; bottom: 18px; z-index: 50;
  display: flex; align-items: center; gap: 8px; }
.lg-dock__btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px;
  background: #fff; color: #323532; border: 1px solid #d8d2c4; border-radius: 999px;
  font-size: 14px; font-weight: 600; line-height: 1; text-decoration: none; cursor: pointer;
  box-shadow: 0 2px 10px rgba(0,0,0,.15); transition: background .15s, border-color .15s; }
.lg-dock__btn:hover { background: #f4f1e8; }
.lg-dock__back svg { width: 15px; height: 15px; }
/* SAVE reuses .lg-dock__btn's pill so the dock stays ONE row of one shape —
   inventing a fourth shape for the newest control is how a row stops reading
   as a row. Lit state matches the rust used for saved/followed elsewhere. */
.lg-dock__save svg { width: 15px; height: 15px; flex: 0 0 auto; }
.lg-dock__save.is-on { color: #c66845; border-color: #c66845; background: #fdf1ec; }
.lg-dock__save.is-on svg { fill: currentColor; }
/* the dock's Comments button reuses .lg-dock__btn (drop the old standalone pos). */
.lg-standalone-dock .lg-standalone-comments { position: static; left: auto; bottom: auto; box-shadow: none; }
.lg-dock__react { position: relative; }
.lg-pf-react__btn { display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
  font: 600 14px/1 inherit; color: #323532; padding: 9px 14px; border-radius: 999px;
  border: 1px solid #d8d2c4; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.15);
  transition: background .15s, border-color .15s; }
.lg-pf-react__btn:hover { background: #f4f1e8; }
.lg-pf-react__btn.is-on { border-color: #c08a2f; background: #fdf6e9; }
.lg-pf-react__em { font-size: 16px; line-height: 1; }
.lg-pf-react__btn img { display: block; }
.lg-pf-react__n { font-weight: 700; color: #b8842b; }
.lg-pf-react__pop { position: absolute; bottom: calc(100% + 8px); left: 0; z-index: 60;
  display: flex; gap: 2px; padding: 6px 8px; background: #fff; border: 1px solid #d8d2c4;
  border-radius: 999px; box-shadow: 0 8px 26px rgba(26,29,26,.18); }
.lg-pf-react__opt { border: 0; background: none; cursor: pointer; padding: 4px 6px;
  border-radius: 50%; font-size: 22px; line-height: 1; transition: transform .12s ease; }
.lg-pf-react__opt:hover { transform: scale(1.3) translateY(-2px); }
.lg-pf-react__opt img { width: 24px; height: 24px; display: block; }
.lg-pf-react__opt.is-on { background: #fdf6e9; }
/* ── ONE PILL FAMILY AT EVERY WIDTH (Ian, 2026-08-21) ─────────────────────────
   "Floating-button style consistency: pill family at every width." MEASURED
   before the change, signed in, on an authored loothprint:

       390   row   343x44   45x35 · 64x44 · 84x35 · 126x34   (Save/Comments worded)
       900   col    64x166  45x35 · 64x44 · 45x35 ·  47x34
       1280  col    64x166  45x35 · 64x44 · 45x35 ·  47x34
       1600  row   421x44   83x35 · 105x44 · 84x35 · 126x34

   Three separate things were wrong and only the first is obvious: the heights
   disagree (34 / 35 / 44), the react control is a WRAPPER DIV with square corners
   whose inner button carried the pill, and on a phone the row was already 343px
   inside a ~354px budget — so the fifth pill Ian asked for DID NOT FIT until the
   Save/Comments words came off there too, exactly as they already were at middle
   widths. The counts stay in every case: dropping a number to make a row fit is
   removing information, which is a different defect from the one being fixed.

   WIDTH IS NOT EQUALISED, deliberately. The wide look this is matching runs
   83/105/84/126, so equal width was never the family rule; equal HEIGHT, one
   radius and a shared baseline are. */
@media (max-width: 1500px) {
  .lg-dock__back span, .lg-pf-react__lbl, .lg-dock__word { display: none; }
  .lg-standalone-dock > * { box-sizing: border-box; border-radius: 999px; }
  .lg-standalone-dock .lg-dock__btn,
  .lg-standalone-dock .lg-pf-react__btn { box-sizing: border-box; }
  /* the react control is a wrapper; give the WRAPPER the family's geometry so it
     stops being the odd one out at the level anything measures the dock. */
  .lg-dock__react { display: inline-flex; align-items: center; }
}
/* A PHONE IS A TOUCH TARGET: 36px, and the row still fits inside 390 with five
   pills (~278 against a ~354 budget). */
@media (max-width: 640px) {
  .lg-standalone-dock > *,
  .lg-standalone-dock .lg-pf-react__btn { height: 36px; }
  .lg-standalone-dock .lg-dock__btn,
  .lg-standalone-dock .lg-pf-react__btn { padding: 0 11px; }
}
/* GH #53 / HK-027: at 641-1500px the horizontal dock (~310px) overlapped the
   centered article column (content left ~x178 at 1280 -> ~150px of body text
   covered). Collapse to a compact VERTICAL stack in the left gutter: word
   labels hidden (counts stay), so the dock's widest button fits inside the
   column margin instead of under the text.
   ⚠️ 30px PILLS AND A 4px GAP ARE ARITHMETIC, NOT TASTE. Five pills at 30 + four
   gaps at 4 is 166px — EXACTLY the height the four-pill stack occupies today. A
   taller stack reaches up into the post-header chip row: measured at 1100 and
   1280, a naive 34px pill put the stack's top 41px higher and grazed the chips by
   13-15px for the first ~25px of scroll. Keeping the total unchanged means the
   fifth pill costs nothing that was previously clear.
   (A cap-holder's SIXTH pill does take the stack to ~200px and can graze at the
   very top of the page. Accepted knowingly: that is Ian and the editors, not
   members, and shrinking every member's pill to protect an admin's would be the
   wrong trade.) */
@media (min-width: 641px) and (max-width: 1500px) {
  .lg-standalone-dock { flex-direction: column; align-items: flex-start; gap: 4px; }
  .lg-standalone-dock > *,
  .lg-standalone-dock .lg-pf-react__btn { height: 30px; }
  .lg-standalone-dock .lg-dock__btn,
  .lg-standalone-dock .lg-pf-react__btn { padding: 0 9px; }
}
/* DARK. The dock has been a row of WHITE pills in dark since it shipped —
   measured 2026-08-21 by gate 69's new dark leg: relative luminance 1.0, i.e.
   pure white furniture floating on a dark article. Fixed here rather than filed,
   because #179 is restyling these exact rules and leaving them would mean
   touching every one of them and choosing not to. */
html[data-lguser-theme="dark"] .lg-dock__btn,
html[data-lguser-theme="dark"] .lg-pf-react__btn,
html[data-lguser-theme="dark"] .lg-pf-react__pop {
  background: #1a1d20; border-color: #2c312d; color: #e5e7e1; }
html[data-lguser-theme="dark"] .lg-dock__btn:hover,
html[data-lguser-theme="dark"] .lg-pf-react__btn:hover { background: #232729; }
html[data-lguser-theme="dark"] .lg-dock__save.is-on { background: #2b1f1a; border-color: #c66845; }
html[data-lguser-theme="dark"] .lg-pf-react__btn.is-on { background: #2a2416; border-color: #c08a2f; }
html[data-lguser-theme="dark"] .lg-pf-react__opt.is-on { background: #2a2416; }
</style>
</head>
<body<?= $embed ? ' class="lg-embed"' : '' ?>>
<?php
    // Identity from /whoami VERBATIM, mirroring archive-poc/web/_chrome.php (the
    // shared-header contract). lg_archive_poc_whoami() is static-cached this
    // request — no second HTTP call. Previously avatar_url/capabilities were
    // hardcoded null/[] here, so CPT headers showed an initial avatar + no admin
    // affordances, diverging from /archive/ and /hub/.
    $who = lg_archive_poc_whoami() ?: [];
    if (!$embed) lg_shared_render_site_header([
        'authenticated' => $authed,
        'tier'          => $tier,
        'display_name'  => $viewerName,
        'avatar_url'    => $who['avatar_url'] ?? null,
        'capabilities'  => (array) ($who['capabilities'] ?? []),
        'msg_unread'    => null,
        'notif_unread'  => null,
        'active_nav'    => '',
        'logout_url'    => '/logout',   // one-click endpoint, no WP interstitial (GH #55)
        'profile_url'   => !empty($who['slug'])
            ? '/u/' . rawurlencode((string) $who['slug'])
            : '/profile/edit',
    ]);
?>
<?php
    // Sticky dock: Back-to-Hub + post React + Comments float together, bottom-left
    // (Ian 2026-06-11). React shares the Hub card's card_reactions store (same
    // post_type:id key) so post + card jive. Use the RESOLVED post id from the blob
    // context. Inside lg_standalone_page() the post context is the $pc param (the
    // caller's $postContext/$postType/$postId are out of scope here).
    $reactId = (int) ($pc['post_id'] ?? 0);
    $reactPt = (string) ($pc['post_type'] ?? '');
    /* ⚠️ THE GUARD TAKES $editUrl TOO, and it has to. The Edit control used to be
       fixed to the page independently of this block; now that it lives in the dock,
       a post that resolves no post_id would have taken the author's only way in
       down with the react buttons. Rare, and silent — which is why it is stated. */
    if ($reactId > 0 || $editUrl !== ''):
?>
<div class="lg-standalone-dock">
  <a class="lg-dock__btn lg-dock__back" href="/hub/" data-lg-hub-back title="Back to the Hub">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg><span>Hub</span>
  </a>
<?php if ($reactPt !== ''): ?>
  <div class="lg-dock__react" data-lg-react data-pt="<?= htmlspecialchars($reactPt, ENT_QUOTES, 'UTF-8') ?>" data-id="<?= $reactId ?>"></div>
<?php /* SAVE — Ian, 2026-07-30: "On cpts we should get a save button. We dont' have
         robust comments on the cpts." A managed CPT's OWN page had NO save affordance
         at all: the Hub card could be saved, the post itself could not. Measured on
         2026-07-30 as a logged-in member at 1280 and 390 — video, loothprint and
         imgcap singles all returned save=0.

         NOT the variant-A relocation. That rule (Save stays inline on non-topic post
         types) is correct and holds on the Hub; this page simply never had one.

         SAME STORE, SAME ENDPOINT, SAME KEY as the Hub card's .fc-save and as the
         react dock beside it: /archive-api/v0/save-post keyed post_type:item_id. A
         second write path would let a post read "saved" on its page and not on its
         card — the drift Ian ruled against for reactions ("the reaction in post and on
         card should jive"). Gated on $reactPt for the same reason the react dock is:
         it is the resolved post_type, and without it there is nothing to key on. */ ?>
  <button type="button" class="lg-dock__btn lg-dock__save" data-lg-save data-pt="<?= htmlspecialchars($reactPt, ENT_QUOTES, 'UTF-8') ?>" data-id="<?= $reactId ?>" aria-pressed="false" aria-label="Save" title="Save">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.6l2.95 5.98 6.6.96-4.77 4.65 1.13 6.57L12 17.66 6.09 20.76l1.13-6.57L2.45 9.54l6.6-.96z"/></svg><span class="lg-dock__word">Save</span>
  </button>
<?php endif; ?>
<?php if ($commentsUrl !== ''): ?>
  <button type="button" class="lg-dock__btn lg-standalone-comments" id="lg-comments-btn" aria-haspopup="dialog" aria-controls="lg-cmodal">
    &#128172; <?php if ($commentsCount > 0): ?><span><?= number_format($commentsCount) ?></span><span class="lg-dock__word">&nbsp;comment<?= $commentsCount === 1 ? '' : 's' ?></span><?php else: ?><span class="lg-dock__word">Comments</span><?php endif; ?>
  </button>
<?php endif; ?>
<?php /* EDIT — Ian, 2026-08-21: one button, straight to the form the member filled
         out. Not a menu, and not in the far corner: "could we put it in the bottom
         stack of sticky buttons ?"

         THE HREF FALLS BACK ON PURPOSE. $composeUrl is set only for a type the
         compose route actually renders AND only when the compose flag is on, so
         with the flag off this pill goes to the page editor it has always gone to
         rather than pointing at a switched-off route. That is the UI-lies class
         Ian has ruled against, and gate 69 asserts the two AGREE either way. */
      if ($editUrl !== ''): ?>
  <a class="lg-dock__btn lg-dock__edit" data-lg-edit href="<?= htmlspecialchars($composeUrl !== '' ? $composeUrl : $editUrl, ENT_QUOTES, 'UTF-8') ?>" title="Edit this post">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg><span class="lg-dock__word">Edit</span>
  </a>
<?php endif; ?>
</div>
<?php if ($commentsUrl !== ''): ?>
<div class="lg-cmodal" id="lg-cmodal" role="dialog" aria-modal="true" aria-label="Comments" hidden>
  <div class="lg-cmodal__backdrop" data-lg-cmodal-close></div>
  <div class="lg-cmodal__panel">
    <div class="lg-cmodal__head">
      <span class="lg-cmodal__head-title">Comments</span>
      <?php if ($commentsCount > 0): ?><span class="lg-cmodal__head-count"><?= number_format($commentsCount) ?></span><?php endif; ?>
      <button type="button" class="lg-cmodal__close" data-lg-cmodal-close aria-label="Close">&times;</button>
    </div>
    <iframe class="lg-cmodal__frame" id="lg-cmodal-frame" title="Comments" data-src="<?= htmlspecialchars($commentsUrl, ENT_QUOTES, 'UTF-8') ?>"></iframe>
  </div>
</div>
<script>
(function(){
  var btn=document.getElementById('lg-comments-btn'),
      modal=document.getElementById('lg-cmodal'),
      frame=document.getElementById('lg-cmodal-frame');
  if(!btn||!modal||!frame)return;
  function openModal(){ if(!frame.src) frame.src=frame.getAttribute('data-src'); modal.hidden=false; document.body.style.overflow='hidden'; }
  function closeModal(){ modal.hidden=true; document.body.style.overflow=''; }
  btn.addEventListener('click',openModal);
  modal.addEventListener('click',function(e){ if(e.target.hasAttribute('data-lg-cmodal-close'))closeModal(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&!modal.hidden)closeModal(); });
  /* Auto-size the iframe to the comments view's content height (posted by
     lg-comments-frame.php), so the panel hugs the thread instead of leaving a
     tall void. Clamp to 82vh; taller threads scroll inside the iframe. */
  window.addEventListener('message',function(e){
    if(e.origin!==location.origin||!e.data||typeof e.data.lgCommentsHeight!=='number')return;
    var cap=Math.round(window.innerHeight*0.82);
    frame.style.height=Math.max(220,Math.min(e.data.lgCommentsHeight,cap))+'px';
  });
})();
</script>
<?php endif; /* commentsUrl modal */ ?>
<script>
(function(){
  if (window.__lgPfBar) return; window.__lgPfBar = 1;
  /* Back to the Hub: native back-nav restores the reader's prior hub scroll +
     sort + filter when they arrived from the hub ("remember hub state"); else the
     href="/hub/" sends them fresh (sort persists via lg_hub_sort). */
  document.addEventListener('click', function(e){
    var a = e.target.closest && e.target.closest('[data-lg-hub-back]');
    if (!a) return;
    try { var r = document.referrer;
      if (r) { var u = new URL(r);
        if (u.origin === location.origin && /^\/hub(\/|$)/.test(u.pathname)) { e.preventDefault(); history.back(); }
      }
    } catch(_){}
  });
  /* Post SAVE — SAME /archive-api/v0/save-post store and wire format the Hub card's
     .fc-save uses (post_type:item_id key), so saving here and on the card are ONE
     saved item. Copied exactly rather than reinvented: batch GET resolves auth +
     nonce + my_saves; POST carries post_type/item_id/unsave/_wpnonce with the nonce
     also in X-WP-Nonce. Anon viewers get no nonce, so the button stays inert exactly
     as it does on a card — visible, never lying about what it will do. */
  (function(){
    var SEP = '/archive-api/v0/save-post';
    var btn = document.querySelector('[data-lg-save]');
    if (!btn) return;
    var pt = btn.getAttribute('data-pt'), id = parseInt(btn.getAttribute('data-id'),10);
    if (!pt || !id) return;
    var key = pt + ':' + id, nonce = '', authed = false;
    function paint(on){
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.classList.toggle('is-on', !!on);
      var l = btn.querySelector('.lg-dock__word');
      if (l) l.textContent = on ? 'Saved' : 'Save';
      btn.setAttribute('aria-label', on ? 'Saved' : 'Save');
      btn.setAttribute('title', on ? 'Saved' : 'Save');
    }
    fetch(SEP + '?items=' + encodeURIComponent(key), {
      credentials:'same-origin', headers:{ 'Accept':'application/json' }
    }).then(function(r){ return r.ok ? r.json() : Promise.reject(); })
      .then(function(d){
        if (!d || !d.authenticated || !d.nonce) return;   // anon → inert
        nonce = d.nonce; authed = true;
        if (d.my_saves && d.my_saves[key]) paint(true);
      }).catch(function(){});
    btn.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      if (!authed) return;
      var was = btn.getAttribute('aria-pressed') === 'true';
      paint(!was);                                        // optimistic
      fetch(SEP, {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ post_type: pt, item_id: id, unsave: was, _wpnonce: nonce })
      }).then(function(r){ return r.json(); })
        .then(function(j){ paint(j && j.ok ? j.saved : was); })   // reconcile / revert
        .catch(function(){ paint(was); });                        // revert on failure
    });
  })();

  /* Post React — SAME /archive-api/v0/card-react store the Hub card uses
     (post_type:id key), so post + card share one count ("should jive"). */
  var EP = '/archive-api/v0/card-react', RBASE = '/archive-poc/reactions/';
  var PALETTE = [
    {slug:'like',label:'Like',char:'👍'}, {slug:'ouch',label:'Ouch',img:'ouch.png'},
    {slug:'wow',label:'Wow',char:'😮'}, {slug:'lol',label:'LOL',char:'😂'},
    {slug:'shop',label:'Optimum',img:'shop.png'}, {slug:'take-my-money',label:'Take my money',img:'take-my-money.png'},
    {slug:'brain',label:'Brain',char:'🧠'}
  ];
  function bySlug(s){ for (var i=0;i<PALETTE.length;i++) if (PALETTE[i].slug===s) return PALETTE[i]; return null; }
  function glyph(p){ return p.img ? '<img src="'+RBASE+p.img+'" alt="" width="18" height="18">' : '<span class="lg-pf-react__em">'+p.char+'</span>'; }
  document.querySelectorAll('[data-lg-react]').forEach(function(box){
    var pt = box.getAttribute('data-pt'), id = parseInt(box.getAttribute('data-id'),10), key = pt+':'+id;
    var st = { nonce:'', authed:false, mine:null, counts:{} };
    function total(){ var t=0; for (var k in st.counts) t += st.counts[k]||0; return t; }
    function render(){
      var t = total(), m = st.mine ? bySlug(st.mine) : null;
      box.innerHTML = '<button type="button" class="lg-pf-react__btn'+(m?' is-on':'')+'" aria-label="React">'
        + (m ? glyph(m) : '<span class="lg-pf-react__em">🙂</span>')
        + '<span class="lg-pf-react__lbl">'+(m?m.label:'React')+'</span>'
        + (t ? '<span class="lg-pf-react__n">'+t+'</span>' : '') + '</button>';
    }
    function openPicker(){
      if (box.querySelector('.lg-pf-react__pop')) return;
      var pop = document.createElement('div'); pop.className='lg-pf-react__pop';
      PALETTE.forEach(function(p){
        var b = document.createElement('button'); b.type='button';
        b.className='lg-pf-react__opt'+(st.mine===p.slug?' is-on':''); b.title=p.label; b.innerHTML=glyph(p);
        b.addEventListener('click', function(ev){ ev.stopPropagation(); pop.remove(); pick(p.slug); });
        pop.appendChild(b);
      });
      box.appendChild(pop);
      setTimeout(function(){ document.addEventListener('click', function h(ev){ if(!box.contains(ev.target)){ pop.remove(); document.removeEventListener('click',h); } }); }, 0);
    }
    function pick(slug){
      if (!st.authed){ location.href = '/wp-login.php?redirect_to='+encodeURIComponent(location.href); return; }
      fetch(EP, { method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-WP-Nonce': st.nonce },
        body: JSON.stringify({ post_type: pt, item_id: id, slug: slug, _wpnonce: st.nonce }) })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d && d.ok){ st.counts = d.counts||{}; st.mine = d.mine||null; render(); } })
        .catch(function(){});
    }
    box.addEventListener('click', function(e){ if (e.target.closest('.lg-pf-react__btn')) openPicker(); });
    render();
    fetch(EP+'?items='+encodeURIComponent(key), { credentials:'same-origin', headers:{ 'Accept':'application/json' } })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){
        if (d){ st.authed = !!(d.authenticated && d.nonce); st.nonce = d.nonce||'';
          st.mine = (d.my_reactions && d.my_reactions[key]) || null;
          st.counts = (d.counts && d.counts[key]) || {}; }
        render();
      }).catch(function(){});
  });
})();
</script>
<?php endif; /* postId > 0 dock */ ?>
<main class="lg-standalone-main" id="lg-main">
<?= $articleHtml ?>
</main>
<?php /* Front-end behaviors — the SAME assets/lg-front.js the WP plugin enqueues,
         externalized + browser-cached. This is what wires the image-block lightbox
         on public standalone pages (the markup + CSS are already present but inert
         without it); it also covers embed-facade click-to-play — which the
         standalone renderer used to re-implement inline here — plus share-copy,
         gallery carousel, and the broken-image placeholder. Deferred so it never
         blocks paint, matching WpAssets. */ ?>
<?php $frontJsHref = lg_standalone_front_js_href(); ?>
<?php if ($frontJsHref !== ''): ?>
<script src="<?= $frontJsHref ?>" defer></script>
<?php else: /* externalize failed (e.g. assets dir not writable) — inline as a fallback */ ?>
<script>
<?= (string) @file_get_contents(__DIR__ . '/engine/assets/lg-front.js') ?>
</script>
<?php endif; ?>
<?php if (!$embed) lg_shared_render_site_footer(); ?>
</body>
</html>
<?php
    return (string) ob_get_clean();
}

function lg_standalone_proof(array $layout, array $pc, array $blob): int {
    $gatedUrl = '';
    foreach ($layout['blocks'] ?? [] as $b) {
        if (is_array($b) && ($b['type'] ?? '') === 'embed' && !empty($b['gated_tier'])) {
            $gatedUrl = (string) ($b['url'] ?? '');
            break;
        }
    }
    $ytId = '';
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})~', $gatedUrl, $m)) {
        $ytId = $m[1];
    }

    [$pubViewer, $pubAuth] = lg_standalone_viewer_from_preview('public');
    $pub = lg_standalone_render_article($layout, $pc, $pubViewer, $pubAuth);
    [$proViewer, $proAuth] = lg_standalone_viewer_from_preview('pro');
    $pro = lg_standalone_render_article($layout, $pc, $proViewer, $proAuth);

    $checks = [];
    if ($ytId !== '') {
        $checks[] = ['gated payload ABSENT in public HTML',  strpos($pub, $ytId) === false];
        $checks[] = ['gated payload PRESENT in pro HTML',    strpos($pro, $ytId) !== false];
    }
    $checks[] = ['no editor markers leak (public)',   strpos($pub, '<lg-edit') === false];
    $checks[] = ['raw blob not on the wire',          strpos($pub, 'post_context') === false && strpos($pro, 'post_context') === false];

    $allPass = true;
    $out = ['layout-standalone proof — ' . ($pc['title'] ?? '?'), str_repeat('─', 60)];
    foreach ($checks as [$label, $ok]) {
        $allPass = $allPass && $ok;
        $out[] = ($ok ? '  PASS  ' : '  FAIL  ') . $label;
    }
    $out[] = str_repeat('─', 60);
    $out[] = $allPass ? 'RESULT: ALL PASS' : 'RESULT: FAILURES ABOVE';
    $out[] = sprintf('  public: %d bytes · pro: %d bytes', strlen($pub), strlen($pro));
    fwrite(STDOUT, implode("\n", $out) . "\n");
    return $allPass ? 0 : 1;
}

function lg_standalone_fail(bool $isCli, int $code, string $msg): void {
    if ($isCli) { fwrite(STDERR, "render-standalone: $msg\n"); exit($code >= 500 ? 2 : 1); }
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo "render-standalone: $msg\n";
    exit;
}
