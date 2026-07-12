<?php
/**
 * archive-poc/web/index.php — SSR landing page (Variant D + C in one document).
 *
 * Server-renders discovery rows from rows.json into the initial HTML so
 * Googlebot sees a fully populated page on first fetch. archive.js attaches
 * interactive behaviors (search, tab clicks → mode flip to grid).
 *
 * Routed through the archive-poc FPM pool (read-only SQLite). No WP needed.
 */

declare(strict_types=1);
require __DIR__.'/../config.php';

// HTML must never be heuristically cached (no header at all = browsers cache
// at their own discretion — stale front pages after every config edit, Ian
// 6/12). Assets stay long-cached via their own versioned-URL nginx block.
header('Cache-Control: no-cache, must-revalidate');


// DEMO MODE: ?demo=1 loads demo-activity.json instead of the live endpoint.
if (isset($_GET['demo'])) {
    define('LG_ARCHIVE_POC_DEMO_ACTIVITY', __DIR__ . '/demo-activity.json');
}
require_once __DIR__ . '/../api/v0/_rowlib.php';

// ---- DB ------------------------------------------------------------------
// Env-driven backend via lg_archive_poc_pdo(): SQLite legacy index by default,
// Postgres `discovery` schema when LG_ARCHIVE_POC_DSN points at pg.
$db = lg_archive_poc_pdo();
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->exec('PRAGMA query_only = ON');   // read-only guard for the SQLite file
}


/**
 * Activity strip: loopback fetch to the WP REST endpoint. Forwards
 * wordpress_logged_in_* cookie so the endpoint sees the visitor's audience.
 */
const LG_ACTIVITY_CACHE_TTL = 300;  // 5 min — low-traffic dev: keep the strip cache warm between visits

/** Build the cookie header forwarded to the WP activity endpoint. */
function archive_poc_activity_cookie(bool $is_member): string {
    $cookie = (LG_ARCHIVE_POC_GATE_COOKIE ? LG_ARCHIVE_POC_GATE_COOKIE.'='.($_COOKIE[LG_ARCHIVE_POC_GATE_COOKIE]??'').';' : '');
    if ($is_member) {
        foreach ($_COOKIE as $cn => $cv) {
            if (str_starts_with($cn, 'wordpress_logged_in_')) {
                $cookie .= ' ' . $cn . '=' . $cv . ';';
            }
        }
    }
    return $cookie;
}

/** Cache file path for an audience+limit combo. */
function archive_poc_activity_cache_file(bool $is_member, int $limit): string {
    return sys_get_temp_dir() . '/lg_actstrip_' . ($is_member ? 'm' : 'p') . '_' . $limit . '.json';
}

/** Blocking WP fetch → items array (empty on any failure). */
function archive_poc_activity_fetch(int $limit, string $cookie): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/activity?limit=' . $limit,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Host: '.LG_ARCHIVE_POC_HOST, 'Cookie: ' . $cookie],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $payload = ($code === 200 && $body) ? json_decode($body, true) : null;
    return (is_array($payload) && !empty($payload['items'])) ? $payload['items'] : [];
}

/**
 * Stale-while-revalidate activity strip.
 *
 * WordPress REST bootstrap is ~0.8s per call (BuddyBoss + plugins), and a cold
 * worker can blow past any timeout — so a blocking fetch per render makes the
 * page slow AND blanks the strip whenever the fetch is slow/empty.
 *
 * Instead: ALWAYS serve the cached items instantly (even if stale). When stale,
 * queue a background refresh that runs after fastcgi_finish_request() — the user
 * already has the page, and the next visitor gets fresher data. The strip never
 * blanks once it's been populated; no render ever blocks on WP. Only the very
 * first load (no cache yet) fetches synchronously to seed the file.
 */
function archive_poc_run_activity_strip(array $row, bool $is_member): array {
    // DEMO MODE — short-circuit to local JSON when ?demo=1
    if (defined('LG_ARCHIVE_POC_DEMO_ACTIVITY') && file_exists(LG_ARCHIVE_POC_DEMO_ACTIVITY)) {
        $payload = json_decode(file_get_contents(LG_ARCHIVE_POC_DEMO_ACTIVITY), true);
        $items = (is_array($payload) && !empty($payload['items'])) ? $payload['items'] : [];
        return ['title' => $row['title'] ?? '', 'items' => $items, 'layout' => 'activity', 'tag' => null];
    }
    $limit      = (int) ($row['query']['limit'] ?? 15);
    $cache_file = archive_poc_activity_cache_file($is_member, $limit);

    $cached = is_file($cache_file) ? json_decode((string) @file_get_contents($cache_file), true) : null;
    if (is_array($cached) && !empty($cached)) {
        // Serve cache instantly. If stale, queue a post-response refresh — but
        // touch the file FIRST so concurrent requests in this window see it as
        // fresh and don't all queue (prevents a thundering herd of WP fetches
        // on cache expiry; exactly one request refreshes).
        $age = time() - (int) @filemtime($cache_file);
        if ($age >= LG_ACTIVITY_CACHE_TTL) {
            @touch($cache_file);
            $GLOBALS['LG_ACTIVITY_REFRESH'][] = [
                'file'   => $cache_file,
                'limit'  => $limit,
                'cookie' => archive_poc_activity_cookie($is_member),
            ];
        }
        return ['title' => $row['title'] ?? '', 'items' => $cached, 'layout' => 'activity', 'tag' => null];
    }

    // No cache yet (first load) — fetch synchronously to seed the file.
    $items = archive_poc_activity_fetch($limit, archive_poc_activity_cookie($is_member));
    if (!empty($items)) {
        @file_put_contents($cache_file, json_encode($items), LOCK_EX);
    }
    return ['title' => $row['title'] ?? '', 'items' => $items, 'layout' => 'activity', 'tag' => null];
}

/**
 * Run any queued activity-cache refreshes AFTER the response is flushed.
 * Call once at the very end of the page. Keeps the WP fetch entirely off the
 * critical path — the visitor already has their (cached) page.
 */
function archive_poc_flush_activity_refreshes(): void {
    $jobs = $GLOBALS['LG_ACTIVITY_REFRESH'] ?? [];
    if (!$jobs) return;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();   // send response now; keep running
    }
    foreach ($jobs as $job) {
        $items = archive_poc_activity_fetch($job['limit'], $job['cookie']);
        if (!empty($items)) {
            @file_put_contents($job['file'], json_encode($items), LOCK_EX);
        } else {
            // Fetch failed — bump mtime so we serve the existing stale copy for
            // another TTL instead of hammering WP on every render during a slow spell.
            @touch($job['file']);
        }
    }
}

// ---- Audience detection -------------------------------------------------
// First-paint: cookie values for fast initial render before /whoami resolves.
$is_member = false;
foreach (array_keys($_COOKIE) as $name) {
    if (str_starts_with($name, 'wordpress_logged_in_')) { $is_member = true; break; }
}
// Tier comes from /whoami ONLY (server-side loopback) — NEVER from the lg_tier
// cookie. That cookie is a client-forgeable display cache: anon + lg_tier=pro
// ungated every paid card on the front page + rails (Buck paywall audit 6/11).
// Same rule as bb-mirror's hub_content_tiers(). Anon (or whoami-unreachable)
// defaults public — entitlement fails CLOSED.
$whoami = lg_archive_poc_whoami();
$viewer_tier = lg_archive_poc_viewer_tier($whoami);   // anon→public, admin→pro
if (!empty($whoami['authenticated'])) $is_member = true;
$edit_capable = ($whoami['capabilities']['edit_archive_poc'] ?? false) === true;

// Admin preview: ?as=public|lite|pro forces viewer state for QA. DOWNGRADES
// (?as=public) are open to anyone; tier-RAISING previews require the edit
// capability — ?as=pro was an anonymous entitlement bypass otherwise.
$preview_as = $_GET['as'] ?? null;
if ($preview_as === 'public') { $is_member = false; $viewer_tier = 'public'; $edit_capable = false; }
elseif ($preview_as === 'lite' && $edit_capable) { $is_member = true; $viewer_tier = 'lite'; }
elseif ($preview_as === 'pro'  && $edit_capable) { $is_member = true; $viewer_tier = 'pro'; }

// Expose to templates as globals the render closures can capture.
$GLOBALS['LG_VIEWER_TIER']  = $viewer_tier;
$GLOBALS['LG_EDIT_CAPABLE'] = $edit_capable;

// ---- "Report a bug or suggestion" modal POST → email ---------------------
// The destination address lives ONLY here, server-side — it must never appear
// in HTML/JS. On dev, mail lands in the local mailpit catcher (/mailpit/);
// real delivery starts at cutover when the box gets real SMTP.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'feedback') {
    header('Content-Type: application/json');
    // Honeypot: real users never see (or fill) the "website" field.
    if (trim((string)($_POST['website'] ?? '')) !== '') { echo json_encode(['ok' => true]); exit; }
    $kind = ($_POST['kind'] ?? '') === 'bug' ? 'Bug report' : 'Suggestion';
    $msg  = trim((string)($_POST['message'] ?? ''));
    if ($msg === '' || strlen($msg) > 5000) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Write a few words first (5000 chars max).']); exit;
    }
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '?';
    $cool = sys_get_temp_dir() . '/lg_fb_' . md5($ip);
    if (is_file($cool) && time() - (int)@filemtime($cool) < 60) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'One message a minute, please — try again shortly.']); exit;
    }
    @touch($cool);
    $who = !empty($whoami['authenticated'])
        ? sprintf('%s (wp user %s, tier %s)', $whoami['display_name'] ?? 'member', $whoami['wp_user_id'] ?? '?', $viewer_tier)
        : 'anonymous visitor';
    $body = $msg . "\n\n--\nFrom: " . $who
          . "\nPage: " . ($_SERVER['HTTP_REFERER'] ?? '/front-page/')
          . "\nUA: "   . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)
          . "\nIP: "   . $ip . "\nTime: " . gmdate('c');
    $ok = @mail('ian.davlin@gmail.com',
        '[Looth] ' . $kind . ' from the front page',
        $body,
        "From: Looth Group <noreply@loothgroup.com>\r\nReply-To: noreply@loothgroup.com");
    if (!$ok) http_response_code(500);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Could not send — try again later.']);
    exit;
}

// ---- Run all rows --------------------------------------------------------
$rows_full_config = archive_poc_load_rows_full(__DIR__ . '/../rows.json');
$rows_config = $rows_full_config['rows'] ?? [];
$signup_banner = $rows_full_config['signup_banner'] ?? null;
// Dash override: config.json's `rows` key (when present) replaces the rows.json
// default list. Same overlay pattern as the sponsors/CTAs blocks above.
if (defined('LG_ARCHIVE_POC_CONFIG_JSON') && is_file(LG_ARCHIVE_POC_CONFIG_JSON)) {
    $_lg_raw = @file_get_contents(LG_ARCHIVE_POC_CONFIG_JSON);
    $_lg_cfg_rows = $_lg_raw !== false ? json_decode($_lg_raw, true) : null;
    if (is_array($_lg_cfg_rows) && !empty($_lg_cfg_rows['rows']) && is_array($_lg_cfg_rows['rows'])) {
        $rows_config = $_lg_cfg_rows['rows'];
    }
    unset($_lg_raw, $_lg_cfg_rows);
}

// Filter by audience: 'public' rows hide from members, 'members' rows hide from non-members, 'both' always show.
$rows_config = array_values(array_filter($rows_config, function ($r) use ($is_member) {
    $aud = $r['audience'] ?? 'both';
    return $aud === 'both' || ($aud === 'members' && $is_member) || ($aud === 'public' && !$is_member);
}));
$rendered_rows = [];
$top_tags_cache = null;
foreach ($rows_config as $row) {
    $type = $row['type'] ?? 'static';
    if ($type === 'tag-random') {
        $top_tags_cache ??= archive_poc_top_tags($db, 20, $row['exclude'] ?? []);
    }
    $rendered = match ($type) {
        'hero'             => archive_poc_run_hero($db, $row),
        'events-upcoming'  => archive_poc_run_events_upcoming($db, $row),
        'tag-random'       => archive_poc_run_row($db, $row, $top_tags_cache),
        'activity-strip'   => archive_poc_run_activity_strip($row, $is_member),
        // Static config-driven rows (data lives in this file, not the DB).
        'cta-bar'          => ['title' => $row['title'] ?? '', 'items' => [], 'layout' => 'cta-bar',      'tag' => null],
        'local-looths'     => ['title' => $row['title'] ?? '', 'items' => [], 'layout' => 'local-looths', 'tag' => null],
        'sponsors'         => ['title' => $row['title'] ?? '', 'items' => [], 'layout' => 'sponsors',     'tag' => null],
        // Per-row config rides through as `query` (the dash's JSON field) — see _render-main-row.php
        'video-promo'      => ['title' => $row['title'] ?? '', 'items' => [], 'layout' => 'video-promo',  'tag' => null, 'query' => $row['query'] ?? null],
        // Daily Guitardle game — static iframe embed of /archive-poc/guitardle/
        'guitardle'        => ['title' => $row['title'] ?? '', 'items' => [], 'layout' => 'guitardle',    'tag' => null],
        // Discussions row: forum threads live in forums.* (PG), NOT content_item
        // (topic→discussion sync dropped 2026-06-05), so it has its own runner that
        // sources forums.topic + deep-links each card to /hub/<forum>/<topic>/.
        default            => (($row['layout'] ?? '') === 'discussions')
                                ? archive_poc_run_discussions($db, $row, $is_member)
                                : archive_poc_run_row($db, $row),
    };
    $rendered['id'] = $row['id'] ?? '';
    $rendered_rows[] = $rendered;
}
$happening_now = archive_poc_happening_now($db);

// ---- Helpers -------------------------------------------------------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }

const LG_FALLBACK_IMG = 'https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp';

// --- Static front-page widgets ----
// Defaults live in web/defaults.php (single source of truth, shared with the
// /_config webhook so the dash can pre-populate forms with effective values).
// LG_ARCHIVE_POC_CONFIG_JSON overlays each top-level key. Empty array = render
// nothing; missing key = fall back to defaults.
$_lg_defaults = require __DIR__ . '/defaults.php';
$_lg_overlay  = [];
if (defined('LG_ARCHIVE_POC_CONFIG_JSON') && is_file(LG_ARCHIVE_POC_CONFIG_JSON)) {
    $_lg_raw    = @file_get_contents(LG_ARCHIVE_POC_CONFIG_JSON);
    $_lg_parsed = $_lg_raw !== false ? json_decode($_lg_raw, true) : null;
    if (is_array($_lg_parsed)) $_lg_overlay = $_lg_parsed;
}
define('LG_SPONSORS',     is_array($_lg_overlay['sponsors']     ?? null) ? $_lg_overlay['sponsors']     : $_lg_defaults['sponsors']);
define('LG_LOCAL_LOOTHS', is_array($_lg_overlay['local_looths'] ?? null) ? $_lg_overlay['local_looths'] : $_lg_defaults['local_looths']);
define('LG_CTA_MEMBER',   is_array($_lg_overlay['cta_member']   ?? null) ? $_lg_overlay['cta_member']   : $_lg_defaults['cta_member']);
define('LG_CTA_PUBLIC',   is_array($_lg_overlay['cta_public']   ?? null) ? $_lg_overlay['cta_public']   : $_lg_defaults['cta_public']);
define('LG_FEATURED_MEMBER', is_array($_lg_overlay['featured_member'] ?? null) ? $_lg_overlay['featured_member'] : ($_lg_defaults['featured_member'] ?? []));
define('LG_HUB_TEASER', is_array($_lg_overlay['hub_teaser'] ?? null) ? $_lg_overlay['hub_teaser'] : ($_lg_defaults['hub_teaser'] ?? []));
unset($_lg_defaults, $_lg_overlay, $_lg_raw, $_lg_parsed);

function thumb_url(array $it): string {
    if (!empty($it['thumb_url']) && empty($it['thumb_broken'])) return $it['thumb_url'];
    return LG_FALLBACK_IMG;
}

function tier_label(string $tier): string {
    return match (strtolower($tier)) { 'pro' => 'Pro', 'lite' => 'Lite', default => 'Public' };
}

const KIND_LABELS = [
    'article' => 'Articles', 'video' => 'Videos', 'loothprint' => 'Loothprints', 'loothcuts' => 'Loothcuts', 'document' => 'Documents',
    'event' => 'Events', 'discussion' => 'Discussions', 'profile' => 'Profiles',
    'benefit' => 'Benefits', 'sponsor-post' => 'Sponsor Posts', 'shorty' => 'Shorts', 'useful_links' => 'Useful Links', 'misc' => 'Misc',
];

/** Primary CTA verb per kind. */
function kind_cta(string $kind): string {
    return match ($kind) {
        'video'      => 'Watch',
        'loothprint' => 'Download',
        'event'      => 'RSVP',
        'discussion' => 'Join the discussion',
        default      => 'Read',
    };
}

/** Relative time ago — for discussion 'Active 2h ago' badges. */
function rel_time(int $ts): string {
    $delta = max(1, time() - $ts);
    if ($delta < 60)       return $delta . 's ago';
    if ($delta < 3600)     return (int)floor($delta / 60) . 'm ago';
    if ($delta < 86400)    return (int)floor($delta / 3600) . 'h ago';
    if ($delta < 86400*30) return (int)floor($delta / 86400) . 'd ago';
    return gmdate('M j, Y', $ts);
}

/** Format an event date block: ['mon' => 'JUN', 'day' => '05', 'time' => '10:00 AM', 'rel' => 'in 12 days']. */
function event_date_block(array $it): array {
    $ts = (int) ($it['event_start_at'] ?? 0);
    if (!$ts) return ['mon' => '—', 'day' => '?', 'dow' => '', 'time' => '', 'rel' => ''];
    // Format in the site timezone (ET) so the rail matches the event page,
    // which renders "12:00 PM ET". The web tier is WP-free / UTC by default.
    $dt = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(LG_ARCHIVE_POC_TZ));
    $mon  = strtoupper($dt->format('M'));
    $day  = $dt->format('j');
    $dow  = $dt->format('D');                          // Sun, Mon, Tue, ...
    $time = $dt->format('g:i A') . ' ET';
    $now = time();
    $delta = $ts - $now;
    if ($delta < 0)            $rel = '';
    elseif ($delta < 86400)    $rel = 'today';
    elseif ($delta < 86400*2)  $rel = 'tomorrow';
    elseif ($delta < 86400*30) $rel = 'in ' . (int) round($delta / 86400) . ' days';
    else                       $rel = 'in ' . (int) round($delta / 86400 / 7) . ' weeks';
    return compact('mon','day','dow','time','rel');
}

/** Schema.org @type per kind. */
function schema_type(array $it): string {
    return match ($it['kind']) {
        'article'    => 'BlogPosting',
        'video'      => 'VideoObject',
        'loothprint' => !empty($it['has_download']) ? 'HowTo' : 'CreativeWork',
        'event'      => 'Event',
        'discussion' => 'DiscussionForumPosting',
        'profile'    => 'ProfilePage',
        'benefit'    => 'Offer',
        default      => 'CreativeWork',
    };
}

function jsonld_item(array $it): array {
    $obj = [
        '@type'    => schema_type($it),
        '@id'      => $it['url'],
        'name'     => $it['title'],
        'url'      => $it['url'],
        'headline' => $it['title'],
    ];
    // description = baked body prose; emit only when the viewer is entitled.
    // The isAccessibleForFree flag below still marks gated items for crawlers —
    // we just don't ship the gated body text into the structured data.
    if (!empty($it['excerpt']) && !lg_archive_poc_is_gated($it['tier'] ?? 'public', $GLOBALS['LG_VIEWER_TIER'] ?? 'public')) {
        $obj['description'] = mb_substr($it['excerpt'], 0, 280);
    }
    if (!empty($it['thumb_url']) && empty($it['thumb_broken'])) $obj['image'] = $it['thumb_url'];
    if (!empty($it['author_name'])) {
        $obj['author'] = ['@type' => 'Person', 'name' => $it['author_name']];
    }
    if (!empty($it['published_at'])) {
        $obj['datePublished'] = gmdate('c', (int)$it['published_at']);
    }
    if (!empty($it['like_count'])) {
        $obj['interactionStatistic'] = [
            '@type' => 'InteractionCounter',
            'interactionType' => ['@type' => 'LikeAction'],
            'userInteractionCount' => (int)$it['like_count'],
        ];
    }
    // Paywall flag — Google's blessed signal for gated content.
    $obj['isAccessibleForFree'] = (strtolower($it['tier'] ?? 'public') === 'public');
    if (!$obj['isAccessibleForFree']) {
        $obj['hasPart'] = [[
            '@type'      => 'WebPageElement',
            'isAccessibleForFree' => false,
            'cssSelector' => '.card__body',
        ]];
    }
    return $obj;
}

function row_jsonld(array $row): string {
    if (!$row['items']) return '';
    $list = [];
    foreach ($row['items'] as $i => $it) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => jsonld_item($it)];
    }
    $doc = [
        '@context' => 'https://schema.org',
        '@type'    => 'ItemList',
        'name'     => $row['title'],
        'numberOfItems' => count($row['items']),
        'itemListElement' => $list,
    ];
    return json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// Hero image for OG: first item of the first row.
$hero = $rendered_rows[0]['items'][0] ?? null;
$og_image = $hero ? thumb_url($hero) : LG_FALLBACK_IMG;

// Home serves at `/` (with `/front-page/` as an alias). Canonical must be the
// real root `/`, not the alias, so Google consolidates on `/` and doesn't split
// or drop the homepage.
$canonical = LG_ARCHIVE_POC_CANONICAL_BASE.'/';

// Pre-baked window.__ROWS__ so JS can hydrate without re-fetching.
$client_state = [
    'rows' => array_map(fn($r) => [
        'title' => $r['title'],
        'layout' => $r['layout'],
        'item_ids' => array_column($r['items'] ?? [], 'id'),
    ], $rendered_rows),
];

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Looth Group — Lutherie Community</title>
<meta name="description" content="The Looth Group archive — articles, videos, loothprints, events, and discussions from the lutherie community.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= h($canonical) ?>">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="Looth Group">
<meta property="og:description" content="Articles, videos, loothprints, events, and discussions from the lutherie community.">
<meta property="og:url" content="<?= h($canonical) ?>">
<meta property="og:image" content="<?= h($og_image) ?>">
<meta property="og:site_name" content="Looth Group">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Looth Group">
<meta name="twitter:description" content="Articles, videos, loothprints, events, and discussions from the lutherie community.">
<meta name="twitter:image" content="<?= h($og_image) ?>">

<!-- Front-page redesign fonts (Classic Landing pick, 2026-06-11) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php /* DM fonts CSS INLINED (perf lane 2026-06-11): the css2 <link> was ~795ms of
         render-blocking CDN round trip for <5KB of @font-face rules (cascade-
         position-independent => visual no-op). Binaries still stream from
         fonts.gstatic.com (preconnect above). See _fonts-inline.css for refresh. */ ?>
<style><?php @readfile(__DIR__ . '/_fonts-inline.css'); ?></style>
<link rel="stylesheet" href="/archive-poc/archive.css?v=<?= @filemtime(__DIR__.'/archive.css') ?>">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="view-discover<?= $happening_now ? ' has-live' : '' ?><?= $is_member ? ' is-member' : '' ?>">
<?php $lg_active_nav = ''; // front page is none of the nav sections — show all, incl. Archive
require __DIR__ . '/_chrome.php'; ?>
<?php if ($happening_now): $hn = $happening_now;
  // Tier-gate the Join link: the join URL is a members-only Zoom link, so only
  // viewers entitled to the event's tier get it; everyone else sees an upgrade
  // CTA. The banner itself still shows to all (live promo / FOMO).
  $hn_rank       = ['public' => 0, 'lite' => 1, 'pro' => 2];
  $hn_ev_tier    = $hn['tier'] ?: 'public';
  $hn_entitled   = ($hn_rank[$viewer_tier] ?? 0) >= ($hn_rank[$hn_ev_tier] ?? 0);
  $hn_join_url   = $hn_entitled ? ($hn['event_join_url'] ?: $hn['url']) : '/lgjoin';
  $hn_join_label = $hn_entitled ? 'Join →' : 'Upgrade to join →';
?>
<aside class="live-banner" role="status">
  <span class="live-banner__dot">🔴</span>
  <span class="live-banner__label">LIVE NOW</span>
  <span class="live-banner__title"><?= h($hn['title']) ?></span>
  <a class="live-banner__cta" href="<?= h($hn_join_url) ?>" target="_blank" rel="noopener"><?= h($hn_join_label) ?></a>
</aside>
<?php endif; ?>
<?php if (!$is_member && $signup_banner): ?>
<aside class="signup-banner" role="region" aria-label="Sign up">
  <div class="signup-banner__inner">
    <div>
      <strong class="signup-banner__title"><?= h($signup_banner['title']) ?></strong>
      <span class="signup-banner__body"><?= h($signup_banner['body']) ?></span>
    </div>
    <a class="signup-banner__cta" href="<?= h($signup_banner['cta_url']) ?>"><?= h($signup_banner['cta_label']) ?> →</a>
  </div>
</aside>
<?php elseif ($is_member):
    // Personal greeting for logged-in viewers — symmetric to the anon signup
    // banner. display_name comes from /whoami (server-side). Use FIRST name only:
    // the legacy name-field system backfilled business names into profile
    // display_name for many members (e.g. "Buck Van Laarhoven VL Guitar Repair"),
    // so the first word is the only token that's reliably the person, not the
    // business. (Source cleanup of profile-app display_name is a separate task.)
    // Falls back to a name-less greeting if whoami is unreachable.
    $lg_greet = trim((string) ($whoami['display_name'] ?? ''));
    $lg_greet = $lg_greet !== '' ? preg_split('~\s+~', $lg_greet)[0] : '';
?>
<aside class="signup-banner signup-banner--member" role="region" aria-label="Welcome">
  <div class="signup-banner__inner">
    <div>
      <strong class="signup-banner__title">Welcome back<?= $lg_greet !== '' ? ', ' . h($lg_greet) : '' ?>.</strong>
      <span class="signup-banner__body">Here&rsquo;s what&rsquo;s new in the Looth community.</span>
    </div>
  </div>
</aside>
<?php endif; ?>
<main class="arc-page" id="main">

  <form class="topbar topbar--standalone" id="topbar" autocomplete="off" onsubmit="event.preventDefault(); return false">
    <svg class="topbar__icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b6f6b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input id="q" name="q" type="search" placeholder="Search or just browse…">
    <button type="button" class="search-close" aria-label="Close search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    <select id="sort" class="sort" hidden>
      <option value="newest">Newest</option>
      <option value="oldest">Oldest</option>
      <option value="liked">Most liked</option>
      <option value="viewed">Most viewed</option>
      <option value="least_viewed">Least viewed</option>
      <option value="discussed">Most discussed</option>
      <option value="active">Most recently active</option>
      <option value="random">Random</option>
      <option value="relevance">Best match</option>
    </select>
  </form>

  <!-- Grid-mode 2-column layout (rail + content). Discover view hides the whole thing. -->
  <div class="grid-layout">
    <button class="rail-toggle" type="button" id="rail-toggle" aria-expanded="false" aria-controls="grid-rail">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
      <span>Filters</span>
    </button>

    <aside class="grid-rail" id="grid-rail" aria-label="Filters">
      <button class="rail-close" type="button" id="rail-close" aria-label="Close filters">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>

      <button class="rail-clear" type="button" id="rail-clear" hidden>
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        Clear all filters
      </button>

      <section class="rail-sec">
        <h3 class="rail-h">Type</h3>
        <nav class="tabs" id="tabs" aria-label="Content type"></nav>
      </section>

      <section class="rail-sec" id="rail-sec-tag" hidden>
        <h3 class="rail-h">Tags</h3>
        <div class="pills" id="tag-pills"></div>
      </section>

      <section class="rail-sec" id="rail-sec-author" hidden>
        <h3 class="rail-h">Author</h3>
        <div class="pills" id="author-pills"></div>
      </section>

      <section class="rail-sec" id="rail-sec-tier" hidden>
        <h3 class="rail-h">Membership</h3>
        <div class="pills" id="tier-pills"></div>
      </section>
    </aside>

    <div class="grid-content">
      <div class="grid-sort">
        <label for="sort-rail" class="grid-sort__label">Sort:</label>
        <select id="sort-rail" class="grid-sort__select">
          <option value="newest">Newest</option>
          <option value="oldest">Oldest</option>
          <option value="liked">Most liked</option>
          <option value="viewed">Most viewed</option>
          <option value="least_viewed">Least viewed</option>
          <option value="discussed">Most discussed</option>
          <option value="active">Most recently active</option>
          <option value="random">Random</option>
          <option value="relevance">Best match</option>
        </select>
      </div>
      <aside class="author-banner" id="author-banner" hidden></aside>
      <div class="lg-modal author-bio-modal" id="author-bio-modal" hidden aria-hidden="true">
        <div class="lg-modal__backdrop" data-author-bio-close></div>
        <div class="lg-modal__panel author-bio-modal__panel" role="dialog" aria-modal="true" aria-labelledby="author-bio-name">
          <button type="button" class="lg-modal__close" data-author-bio-close aria-label="Close">×</button>
          <div class="author-bio-modal__body" id="author-bio-body"></div>
        </div>
      </div>
      <p class="results-meta" id="results-meta" hidden></p>
      <div class="active-filters" id="active-filters" hidden></div>
      <section class="cards" id="cards" aria-live="polite" hidden></section>
      <nav class="pagination" id="pagination" aria-label="Pagination" hidden></nav>
      <div class="loadmore" hidden><span id="loadmore-status"></span></div>
    </div>
  </div>

  <!-- Legacy subfilters wrapper kept as a no-op anchor; some JS may reference it. -->
  <div id="subfilters" hidden></div>

  <!-- Discover mode: SSR'd rows, two-column layout -->
<?php
    // Split into main vs sidebar columns. Static layouts (cta-bar/looths/sponsors)
    // never have $row['items'] — exempt them from the empty-skip.
    $static_layouts = ['cta-bar','local-looths','sponsors','video-promo','guitardle'];
    $activity_row = null;
    $main_rows = [];
    $left_rows = [];
    $right_rows = [];
    foreach ($rendered_rows as $r) {
        $lay = $r['layout'] ?? 'rail';
        // The discussions rail is now rendered under the hub-teaser heading
        // ("What members are talking about"); suppress the standalone row so it
        // doesn't appear twice (Ian 6/14).
        if ($lay === 'discussions') continue;
        if (empty($r['items']) && !in_array($lay, $static_layouts, true)) continue;
        $col = 'main';
        foreach ($rows_config as $rc) {
            if (($rc['id'] ?? '') === ($r['id'] ?? '')) { $col = $rc['column'] ?? 'main'; break; }
        }
        if      ($col === 'left')      $left_rows[]  = $r;
        elseif  ($col === 'right')     $right_rows[] = $r;
        elseif  ($lay === 'activity')  $activity_row = $r;   // pulled out for sidebar-flanked band
        else                           $main_rows[]  = $r;
    }

    // Render closure for one row's HTML — same dispatch chain we already had
    // below, factored out so we can call it from inside the activity band's
    // center pane AND from the regular below-band flow.
    $render_main_row = function(array $row) use ($is_member) {
        $layout = $row['layout'] ?? 'rail';
        $row_id = $row['id'] ?? '';
        ob_start();
        include __DIR__ . '/_render-main-row.php';
        return ob_get_clean();
    };
?>

<?php if ($activity_row): ?>
  <div class="activity-band">
    <aside class="band-pane band-pane--left">
<?php foreach ($left_rows as $row):
        $layout = $row['layout'] ?? '';
        $row_id = $row['id'] ?? '';
        if ($layout === 'cta-bar'):
            $buttons = $is_member ? LG_CTA_MEMBER : LG_CTA_PUBLIC;
?>
      <section class="side-row side-row--cta" data-row-id="<?= h($row_id) ?>">
<?php foreach ($buttons as $b): ?>
        <a class="cta-btn cta-btn--<?= h($b['style']) ?>" href="<?= h($b['url']) ?>"<?= !empty($b['action']) ? ' data-action="' . h($b['action']) . '"' : '' ?><?= !empty($b['attr']) ? ' ' . $b['attr'] : '' ?>><?= !empty($b['icon']) ? $b['icon'] : '' ?><?= h($b['label']) ?></a>
<?php endforeach; ?>
      </section>
<?php elseif ($layout === 'events'): ?>
      <section class="side-row side-row--events" data-row-id="<?= h($row_id) ?>">
        <h3 class="side-row__title"><?= h($row['title'] ?: 'Upcoming events') ?></h3>
        <ul class="side-events">
<?php foreach ($row['items'] as $it):
            $blk = event_date_block($it);
            $tier = strtolower((string)($it['tier'] ?? 'public'));
            // Always link to the event post page — never leak the Zoom URL anonymously.
            $href = $it['url'] ?: '#';
?>
          <li><a class="side-event" href="<?= h($href) ?>">
            <div class="side-event__date">
              <span class="side-event__dow"><?= h($blk['dow']) ?></span>
              <span class="side-event__day"><?= h($blk['day']) ?></span>
              <span class="side-event__mon"><?= h($blk['mon']) ?></span>
            </div>
            <div class="side-event__body">
              <h4 class="side-event__title"><?= h($it['title']) ?></h4>
              <div class="side-event__meta">
                <span class="side-event__time"><?= h($blk['time']) ?></span>
<?php if (!empty($blk['rel'])): ?><span class="side-event__rel"><?= h($blk['rel']) ?></span><?php endif; ?>
              </div>
            </div>
          </a></li>
<?php endforeach; ?>
        </ul>
      </section>
<?php elseif ($layout === 'local-looths'): ?>
      <section class="side-row side-row--looths" data-row-id="<?= h($row_id) ?>">
        <h3 class="side-row__title"><?= h($row['title'] ?: 'Local Looths') ?></h3>
        <ul class="side-looths">
<?php foreach (LG_LOCAL_LOOTHS as $l): ?>
          <li><a href="<?= h($l['url']) ?>">
            <img src="<?= h($l['avatar']) ?>" alt="" loading="lazy" width="36" height="36"
                 onerror="this.onerror=null;this.src='<?= h(LG_FALLBACK_IMG) ?>'">
            <span><?= h($l['name']) ?></span>
          </a></li>
<?php endforeach; ?>
        </ul>
      </section>
<?php endif; endforeach; ?>
    </aside>

    <div class="band-pane band-pane--center">
      <?= $render_main_row($activity_row) ?>
    </div>

    <aside class="band-pane band-pane--right">
<?php foreach ($right_rows as $row):
        $layout = $row['layout'] ?? '';
        $row_id = $row['id'] ?? '';
        if ($layout === 'local-looths'): ?>
      <section class="side-row side-row--looths" data-row-id="<?= h($row_id) ?>">
        <h3 class="side-row__title"><?= h($row['title'] ?: 'Local Looths') ?></h3>
        <ul class="side-looths">
<?php foreach (LG_LOCAL_LOOTHS as $l): ?>
          <li><a href="<?= h($l['url']) ?>">
            <img src="<?= h($l['avatar']) ?>" alt="" loading="lazy" width="36" height="36"
                 onerror="this.onerror=null;this.src='<?= h(LG_FALLBACK_IMG) ?>'">
            <span><?= h($l['name']) ?></span>
          </a></li>
<?php endforeach; ?>
        </ul>
      </section>
<?php elseif ($layout === 'sponsors'): ?>
      <section class="side-row side-row--sponsors" data-row-id="<?= h($row_id) ?>">
        <h3 class="side-row__title"><?= h($row['title'] ?: 'Our Sponsors') ?></h3>
        <div class="side-sponsors">
<?php foreach (LG_SPONSORS as $s):
            $bg = !empty($s['bg']) ? 'style="background:' . h($s['bg']) . ';"' : '';
?>
          <a class="side-sponsor" href="<?= h($s['url']) ?>" target="_blank" rel="noopener" <?= $bg ?>>
            <img src="<?= h($s['logo']) ?>" alt="<?= h($s['name']) ?>" loading="lazy">
          </a>
<?php endforeach; ?>
        </div>
      </section>
<?php endif; endforeach; ?>
    </aside>
  </div><!-- /.activity-band -->
<?php endif; ?>

  <div class="rows" id="rows">
<?php
// Bento logged-in layout (Buck's pick, Ian-greenlit 2026-06-11): members get a
// featured-member band after the welcome promo, and the events row pairs with
// a member-map tile in a two-up bento grid, Loothalong link pinned on top.
// Featured-member band shows BOTH audiences (Ian 6/12) — it follows whichever
// welcome promo the viewer got (member What's-New / public Classic Landing).
$lg_fm = (defined('LG_FEATURED_MEMBER') && !empty(LG_FEATURED_MEMBER['enabled'])) ? LG_FEATURED_MEMBER : null;
foreach ($main_rows as $row):
    $layout = $row['layout'] ?? 'rail';
    $row_id = $row['id'] ?? '';
?>

<?php if ($row_id === 'upcoming-events'): ?>
      <div class="lg-bento">
        <section class="lg-bento__map" id="lg-fp-map" aria-label="Member map">
          <div class="lg-fp-map__canvas"></div>
          <img class="lg-bento__map-img" src="/archive-poc/member-map-teaser.webp" alt="" loading="lazy">
          <div class="lg-bento__map-copy">
            <h2 class="lg-bento__map-title">Luthiers near you</h2>
            <p class="lg-bento__map-sub"><?= $is_member ? 'You&rsquo;re on the map. The closest luthiers and shops:' : 'Find members and shops near you.' ?></p>
            <div class="lg-fp-map__list"></div>
            <button type="button" class="lg-bento__map-btn" data-action="open-member-map">Open the member map</button>
          </div>
        </section>
        <script defer src="/archive-poc/fp-map.js?v=<?= @filemtime(__DIR__ . '/fp-map.js') ?>"></script>
        <div class="lg-bento__events">
          <?php if ($is_member): ?>
          <a class="lg-loothalong<?= $happening_now ? ' is-live' : '' ?>" href="/loothalong.php" target="_blank" rel="noopener">
            <span class="lg-loothalong__glow"></span>
            <span class="lg-loothalong__live"><span class="lg-loothalong__dot"></span><?= $happening_now ? 'Live now' : 'Open 24/7' ?></span>
            <span class="lg-loothalong__txt">Loothalong<small>A 24-hour workbench full of friends.</small></span>
            <span class="lg-loothalong__go">Pull up a bench<svg class="lg-loothalong__arr" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13"/><path d="M13 6l6 6-6 6"/></svg></span>
          </a>
          <?php endif; ?>
          <?php if ($is_member): ?>
          <?php /* One-click event-reminder signup (Ian 6/12): logged-in only —
                   we know the member's email; mu-plugin lg-event-reminders.php
                   adds them to the FluentCRM Event Reminder list. */ ?>
          <button type="button" class="lg-bento__remind" id="lg-ev-remind" data-on="0">&#128276; Email me event reminders</button>
          <script>
          (function(){var b=document.getElementById('lg-ev-remind');if(!b)return;
            var AJ='/wp-admin/admin-ajax.php';
            function paint(on){b.dataset.on=on?'1':'0';b.classList.toggle('is-on',on);
              b.textContent=on?'\u2713 Event reminders on \u2014 tap to turn off':'\uD83D\uDD14 Email me event reminders';b.disabled=false;}
            // real CRM state on load (toggle = source of truth, not localStorage)
            fetch(AJ+'?action=lg_event_reminder_state',{credentials:'same-origin'})
              .then(function(r){return r.json()}).then(function(j){if(j&&j.ok)paint(!!j.on);}).catch(function(){});
            b.addEventListener('click',function(){
              var want=b.dataset.on!=='1';b.disabled=true;b.textContent=want?'Signing you up\u2026':'Turning off\u2026';
              fetch(AJ,{method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'action=lg_event_reminder_signup&on='+(want?'1':'0')})
              .then(function(r){return r.json()}).then(function(j){
                if(j&&j.ok)paint(!!j.on);
                else{b.textContent='Something went wrong \u2014 tap to retry';b.disabled=false;}
              }).catch(function(){b.textContent='Something went wrong \u2014 tap to retry';b.disabled=false;});
            });})();
          </script>
          <?php endif; ?>
          <?php /* Weekly Digest entry — BOTH audiences (Ian 6/12); /weekly/ is
                   members-gated server-side, so anon clicking through gets the
                   sign-in card (a join nudge, not a leak). */ ?>
          <?php if (!$is_member): ?>
          <?php /* Weekly-email signup on the front page (Ian 6/12 "offer it to
                   logged out"): non-members capture their email into the CRM
                   with double opt-in via the live lg_weekly_signup handler
                   (mu-plugins/lg-event-reminders.php, FluentCRM list 7).
                   Members are auto-subscribed at registration, so they see only
                   the Read link below. Mirrors the /weekly/ page subscribe bar
                   (the old WP [weekly_digest] shortcode modal does not render on
                   this standalone front page, so the button was missing here). */ ?>
          <form class="lg-bento__wksub" id="lg-wk-fp-sub">
            <div class="lg-bento__wksub-txt"><b>&#128236; Get the Weekly Digest by email</b>
              <small>Free &mdash; luthier events, new videos, and shop talk, every week.</small></div>
            <input type="text" name="website" class="lg-bento__wksub-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div class="lg-bento__wksub-row">
              <input type="email" name="email" required placeholder="you@example.com" aria-label="Email address">
              <button type="submit">Subscribe</button>
            </div>
          </form>
          <script>
          (function(){var f=document.getElementById('lg-wk-fp-sub');if(!f)return;
            f.addEventListener('submit',function(e){e.preventDefault();
              var b=f.querySelector('button');b.disabled=true;b.textContent='Subscribing\u2026';
              var data=new URLSearchParams();data.set('action','lg_weekly_signup');
              data.set('email',f.email.value);data.set('website',f.website.value);
              fetch('/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data.toString()})
              .then(function(r){return r.json()}).then(function(j){
                if(j&&j.ok){f.innerHTML='<div class="lg-bento__wksub-txt"><b>'+
                  (j.state==='subscribed'?'You\u2019re subscribed \u2713':'Check your inbox \u2709')+'</b><small>'+
                  (j.state==='subscribed'?'The next digest will land in your inbox.':'Click the confirmation link we just sent and you\u2019re in.')+'</small></div>';}
                else{b.disabled=false;b.textContent='Subscribe';alert(j&&j.error==='bad_email'?'That email doesn\u2019t look right.':'Could not subscribe \u2014 try again.');}
              }).catch(function(){b.disabled=false;b.textContent='Subscribe';});
            });})();
          </script>
          <?php endif; ?>
          <a class="lg-bento__weekly" href="/weekly/">
            <span class="lg-bento__weekly-ico" aria-hidden="true">&#128236;</span>
            <span class="lg-bento__weekly-txt"><b>Weekly Digest</b>
              <small><?= $is_member ? 'Catch up on everything from this week.' : 'The members&rsquo; weekly round-up.' ?></small></span>
            <span class="lg-bento__weekly-go">Read &rarr;</span>
          </a>
          <?php include __DIR__ . "/_render-main-row.php"; ?>
        </div>
      </div>
<?php /* Hub teaser shows BOTH audiences (Ian 6/12), sitting after the bento =
         just before the rails in both row orders. Join-flavored copy is
         anon-only; members get neutral lines.

         LIVE (front-page-discussion-modal lane, Ian 2026-06-14): the cards are
         now sourced from live forums.topic (reusing archive_poc_run_discussions)
         so each deep-links to its thread + opens the front-page discussion modal
         (fp-discuss.js). Falls back to the curated snapshot (LG_HUB_TEASER items,
         non-clickable) if the DB read is empty (e.g. the SQLite revert path).
         Leak-safe for anon: archive_poc_run_discussions masks member-visibility
         authors, and gated excerpts are dropped below the viewer's tier. */ ?>
<?php
// Live discussions under the curated heading. The standalone 'discussions' row
// is suppressed in the bucket loop above — its rail is rendered here instead,
// under the "What members are talking about" heading (Ian 6/14). archive_poc_run_discussions
// masks member-visibility authors for anon (leak-safe); _bare emits just the rail.
$lg_ht_enabled = defined('LG_HUB_TEASER') && !empty(LG_HUB_TEASER['enabled']);
$lg_ht_disc    = [];
if ($lg_ht_enabled) {
    try {
        $lg_ht_live = archive_poc_run_discussions($db, ['query' => ['limit' => 12, 'sort' => 'active']], $is_member);
        $lg_ht_disc = $lg_ht_live['items'] ?? [];
    } catch (\Throwable $e) { $lg_ht_disc = []; }
}
?>
<?php if ($lg_ht_enabled && $lg_ht_disc): ?>
      <section class="row row--hub-teaser" data-row-id="hub-teaser">
        <div class="lg-hubt__head">
          <p class="lg-hubt__eyebrow">The Hub</p>
          <h2 class="lg-hubt__title">What members are talking about</h2>
          <p class="lg-hubt__sub"><?= $is_member
            ? 'Real bench problems, candid answers — jump in.'
            : 'Real bench problems, candid answers. Join to reply and see who you&rsquo;re talking to.' ?></p>
        </div>
<?= $render_main_row(['layout' => 'discussions', 'items' => $lg_ht_disc, '_bare' => true, 'id' => 'hub-teaser', 'title' => '']) ?>
        <div class="lg-hubt__cta"><a class="lg-hubt__more" href="/hub/">See the full Hub &rarr;</a></div>
      </section>
<?php endif; ?>
<?php else: ?>
<?php include __DIR__ . "/_render-main-row.php"; ?>
<?php endif; ?>
<?php if ($lg_fm && ($row_id === 'video-promo-members' || $row_id === 'video-promo-public')): ?>
      <section class="row row--featured-member" data-row-id="featured-member">
        <div class="lg-fm">
          <span class="lg-fm__badge">Featured member</span>
          <span class="lg-fm__avi"><img src="<?= h((string)($lg_fm['avatar'] ?? '')) ?>" alt="" loading="lazy"></span>
          <div class="lg-fm__body">
            <h2 class="lg-fm__name"><?= h((string)($lg_fm['name'] ?? '')) ?></h2>
            <div class="lg-fm__role"><?= h((string)($lg_fm['role'] ?? '')) ?></div>
            <?php /* Location is members-visibility profile data (per-user privacy
                     ruling) — never render it to the logged-out page. */ ?>
            <?php if ($is_member && !empty($lg_fm['where'])): ?><div class="lg-fm__where"><?= h((string)$lg_fm['where']) ?></div><?php endif; ?>
            <?php if (!empty($lg_fm['bio'])): ?><p class="lg-fm__bio"><?= h((string)$lg_fm['bio']) ?></p><?php endif; ?>
          </div>
          <?php if (!empty($lg_fm['cta_href'])): ?>
          <div class="lg-fm__act"><a class="lg-fm__cta" href="<?= h((string)$lg_fm['cta_href']) ?>"><?= h((string)($lg_fm['cta_label'] ?? 'View')) ?></a></div>
          <?php endif; ?>
        </div>
      </section>
<?php endif; ?>
<?php endforeach; /* /main_rows */ ?>
  </div><!-- /.rows -->
  </div><!-- /.arc-pane--main -->
  </div><!-- /.arc-app -->
  <?php /* Right-column rows (sponsors, looths) are rendered inside the
           activity band; the duplicate bottom-stacked render was removed. */ ?>
</main>
<!-- Search modal (opened by [data-action="open-search-modal"]) -->
<div class="search-modal" id="search-modal" hidden aria-hidden="true">
  <div class="search-modal__backdrop" data-search-modal-close></div>
  <div class="search-modal__dialog" role="dialog" aria-modal="true" aria-label="Search the archive">
    <form class="search-modal__form" onsubmit="event.preventDefault();">
      <svg class="search-modal__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" id="search-modal-q" class="search-modal__input" placeholder="Search articles, videos, loothprints, discussions…" autocomplete="off">
      <button type="button" class="search-modal__close" data-search-modal-close aria-label="Close">×</button>
    </form>
    <div class="search-modal__results" id="search-modal-results"></div>
    <div class="search-modal__foot">
      <button type="button" class="search-modal__more" id="search-modal-more" hidden>See all results →</button>
    </div>
  </div>
</div>
<!-- Member Map modal (opened by [data-action="open-member-map"]) -->
<div class="lg-modal member-map-modal" id="member-map-modal" hidden aria-hidden="true">
  <div class="lg-modal__backdrop" data-member-map-close></div>
  <div class="lg-modal__dialog" role="dialog" aria-modal="true" aria-label="Member map">
    <div class="lg-modal__head">
      <svg class="lg-modal__title-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/>
        <circle cx="12" cy="10" r="3"/>
      </svg>
      <h2 class="lg-modal__title">Member Map</h2>
      <span class="lg-modal__count" id="member-map-count" aria-live="polite"></span>
      <button type="button" class="lg-modal__close" data-member-map-close aria-label="Close">×</button>
    </div>
    <div class="lg-modal__body">
      <div id="member-map" class="member-map" role="application" aria-label="Map of Looth Group members"></div>
      <div class="member-map__status" id="member-map-status">Loading map…</div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_chrome-footer.php'; ?>
<script>window.__ROWS__ = <?= json_encode($client_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
window.__LG_VIEWER_TIER__ = <?= json_encode($viewer_tier) ?>;</script>
<script src="/archive-poc/archive.js?v=<?= @filemtime(__DIR__.'/archive.js') ?>"></script>
<?php /* Front-page discussion modal: a discussion card opens the thread + composer
         in place. Tiny on its own; the heavy bb-mirror composer assets lazy-load
         only on the first card click (composer-on-intent, CRAFT-STANDARD).
         Asset versions are exposed so the lazy loader can cache-bust the modal's
         CSS (fp-discuss.css is max-age=30d, not immutable). */ ?>
<script>window.__FPD_V__ = {
  css: <?= json_encode((string) @filemtime(__DIR__ . '/fp-discuss.css')) ?>,
  forumsCss: <?= json_encode((string) @filemtime('/srv/bb-mirror/web/forums.css')) ?>,
  forumsJs: <?= json_encode((string) @filemtime('/srv/bb-mirror/web/forums.js')) ?>
};</script>
<script defer src="/archive-poc/fp-discuss.js?v=<?= @filemtime(__DIR__.'/fp-discuss.js') ?>"></script>
</body>
</html>
<?php
// Refresh any stale activity-strip caches AFTER the response is flushed, so the
// WP fetch never blocks the visitor's page render.
archive_poc_flush_activity_refreshes();
