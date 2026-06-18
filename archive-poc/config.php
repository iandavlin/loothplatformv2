<?php
/**
 * archive-poc env config.
 *
 * Auto-detects live vs dev from $_SERVER['HTTP_HOST'] or hostname() fallback.
 * Exposes constants used by web/, bin/, and the mu-plugin.
 *
 * Override at the top of any script by defining LG_ARCHIVE_POC_ENV before
 * including this file (e.g. for CLI: `LG_ARCHIVE_POC_ENV=live php …`).
 */

declare(strict_types=1);

if (defined('LG_ARCHIVE_POC_ENV_LOADED')) return;
define('LG_ARCHIVE_POC_ENV_LOADED', true);

// ---------- env detection ----------
// Prefer the shared /etc/looth/env (one source of truth across every app);
// fall back to this app's own detection when the file is absent (e.g. dev1),
// so any box without it behaves EXACTLY as before. See lg-shared/lg-env.php.
if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
$shared = function_exists('lg_env') ? lg_env() : [];

$env = $shared['env'] ?? getenv('LG_ARCHIVE_POC_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    // dev hostnames start with dev. or are the dev box's internal name
    if (str_starts_with((string)$host, 'dev.') || str_contains((string)$host, 'ip-172-31-81-87') || str_contains((string)$host, 'claude.loothgroup')) {
        $env = 'dev';
    } else {
        $env = 'live';
    }
}
define('LG_ARCHIVE_POC_ENV', $env);

// ---------- env-specific values (PATHS/users only — NOT the host) ----------
// LG_ARCHIVE_POC_ENV selects the filesystem + WP user. The browser-facing host
// is derived separately (below) from the actual request, because at the cut the
// prod box runs ENV=dev (/var/www/dev + looth-dev) while its public host is
// loothgroup.com. Decoupled so dev / dev2 / loothgroup.com need no per-host edit.
// Per-env DEFAULTS (the fallback when the shared env file lacks a key). The
// shared /etc/looth/env (lg_env()) is consulted FIRST for each value so a box
// can override without a per-host edit; the branch below preserves the exact
// prior behavior when the key is absent.
if ($env === 'live') {
    $ap_def_wp_path     = '/var/www/html';
    $ap_def_wp_user     = 'looth-live';
    $ap_def_gate_cookie = '';                 // live has no cookie gate
    $ap_def_app_root    = '/srv/archive-poc';
} else { // dev (also the dev2 / prod-at-cut box: /var/www/dev + looth-dev)
    $ap_def_wp_path     = '/var/www/dev';
    $ap_def_wp_user     = 'looth-dev';
    $ap_def_gate_cookie = 'loothdev_auth';
    $ap_def_app_root    = '/home/ubuntu/projects/archive-poc';
}
// gate_cookie can be a deliberate empty string '' from the env file ("gate off")
// — `??` keeps '' (not null), so use it, NOT `?:`.
define('LG_ARCHIVE_POC_WP_PATH',       $shared['wp_path']     ?? $ap_def_wp_path);
define('LG_ARCHIVE_POC_WP_USER',       $shared['wp_user']     ?? $ap_def_wp_user);
define('LG_ARCHIVE_POC_GATE_COOKIE',   $shared['gate_cookie'] ?? $ap_def_gate_cookie);
define('LG_ARCHIVE_POC_APP_ROOT',      $ap_def_app_root);      // no shared key; /srv/archive-poc is a valid symlink on prod

// ---------- browser-facing host (request-derived) ----------
// Single source of truth for the public host used to build URLs (logo, canonical)
// and the loopback CURL 'Host:' header. Derived from the live request so dev,
// dev2, and loothgroup.com each self-resolve. CLI/cron (no HTTP_HOST) fall back
// to, in order: (1) LG_ARCHIVE_POC_PUBLIC_HOST -- set in the FPM pool + any timer
// env on a box whose public host differs from its env default (dev2, prod-at-cut);
// (2) else the env default below. Sanitized (it feeds a curl 'Host:' header) ->
// strip anything outside a valid hostname[:port] to close Host-header injection.
$ap_host_fallback = getenv('LG_ARCHIVE_POC_PUBLIC_HOST')
    ?: (($env === 'live') ? 'loothgroup.com' : 'dev.loothgroup.com');
$ap_req_host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
// Shared host (if /etc/looth/env present) is authoritative; else request-derived, else fallback.
define('LG_ARCHIVE_POC_HOST',          $shared['host'] ?? ($ap_req_host !== '' ? $ap_req_host : $ap_host_fallback));
define('LG_ARCHIVE_POC_LOGO_URL',      'https://' . LG_ARCHIVE_POC_HOST . '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');
define('LG_ARCHIVE_POC_CANONICAL_BASE','https://' . LG_ARCHIVE_POC_HOST);

// ---------- derived ----------
define('LG_ARCHIVE_POC_SQLITE',   LG_ARCHIVE_POC_APP_ROOT . '/index.sqlite');
define('LG_ARCHIVE_POC_ROWS_JSON',LG_ARCHIVE_POC_APP_ROOT . '/rows.json');
define('LG_ARCHIVE_POC_WP_LOAD',  LG_ARCHIVE_POC_WP_PATH . '/wp-load.php');

// Site display timezone (matches WP's America/New_York). The web tier runs
// WP-free (default UTC), so event times are formatted against this explicitly.
if (!defined('LG_ARCHIVE_POC_TZ')) define('LG_ARCHIVE_POC_TZ', 'America/New_York');

// Dash-driven front-page config (sponsors, looths, CTAs). JSON file written
// atomically by the /_config webhook (lg-layout-v2 dash → loopback). Falls
// back to PHP-constant defaults baked into index.php when missing.
define('LG_ARCHIVE_POC_CONFIG_JSON', LG_ARCHIVE_POC_APP_ROOT . '/config.json');

// Shared secret for the /_config webhook. Lives outside source at
// /etc/lg-archive-poc-secret (mode 640, root:www-data + ACL for archive-poc).
// Empty if file missing — webhook refuses all requests in that state.
if (!defined('LG_ARCHIVE_POC_CONFIG_SECRET')) {
    $_lg_secret = @file_get_contents('/etc/lg-archive-poc-secret');
    define('LG_ARCHIVE_POC_CONFIG_SECRET', $_lg_secret !== false ? trim($_lg_secret) : '');
    unset($_lg_secret);
}

// ---------- PDO connection ----------
// LG_ARCHIVE_POC_DSN env var picks the backend. Default = sqlite at the
// legacy path. Postgres in-flight per docs/STRANGLER-COORDINATION.md §3i.
//   Example (dev pg, peer auth):
//     LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
//       sudo -u archive-poc php bin/backfill-pg.php
if (!function_exists('lg_archive_poc_pdo')) {
function lg_archive_poc_pdo(): PDO {
    $dsn = getenv('LG_ARCHIVE_POC_DSN');
    if (!$dsn) $dsn = 'sqlite:' . LG_ARCHIVE_POC_SQLITE;
    $pdo = new PDO($dsn, null, null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    } elseif ($driver === 'pgsql') {
        // Pin search_path here, not via ALTER ROLE — looth-dev is shared
        // across stranglers and can't have a single per-role default.
        $pdo->exec('SET search_path = discovery, public');
    }
    return $pdo;
}
}

// ---------- /whoami — viewer identity (cached per request) ----------
// Calls profile-app directly at /profile-api/v0/whoami (bypasses WP shim).
// WP shim adds ~6s boot cost per cold FPM worker; profile-app direct is ~100ms.
// The shim's WP-session bridge (get_current_user_id resolution) is a profile-app
// build item — until it ships, both paths return anon for WP-only users anyway.
// When profile-app ships the trusted-header bridge, update this URL back to the
// shim OR call profile-app directly with X-LG-WP-User-Id + X-LG-Internal-Auth.
// Returns null on failure; callers fall back to cookie-only values in that case.
// tier_unavailable:true (poller down) is treated as tier=public (fail open).
if (!function_exists('lg_archive_poc_whoami')) {
function lg_archive_poc_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/whoami',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_ARCHIVE_POC_HOST,
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = ($code === 200 && $body) ? json_decode($body, true) : null;
    if (is_array($data) && !empty($data['tier_unavailable'])) {
        $data['tier'] = 'public';
    }
    $result = is_array($data) ? $data : null;
    return $result;
}
}

// Viewer gate-bucket from a whoami payload — THE one tier rule for archive-poc
// (mirrors bb-mirror hub_content_tiers): anon fails CLOSED to public; the
// forgeable lg_tier cookie is never consulted; ADMINS resolve to pro ("admin
// sees all", TIER-TAXONOMY.md) via their capabilities, since the poller's
// user-context reports an administrator's ROLE tier as public.
if (!function_exists('lg_archive_poc_viewer_tier')) {
function lg_archive_poc_viewer_tier(?array $whoami): string {
    if (empty($whoami['authenticated'])) return 'public';
    $caps = (array)($whoami['capabilities'] ?? []);
    foreach (['manage_options', 'administrator', 'edit_others_posts', 'activate_plugins'] as $c) {
        if (!empty($caps[$c]) || in_array($c, $caps, true)) return 'pro';
    }
    $t = $whoami['tier'] ?? '';
    return in_array($t, ['public', 'lite', 'pro'], true) ? $t : 'public';
}
}

// Is $content_tier ABOVE $viewer_tier? (anon already failed CLOSED to 'public'
// in lg_archive_poc_viewer_tier). THE one tier_rank map — every render + API
// surface calls this instead of re-inlining its own {public,lite,pro} ladder,
// so the gate can never drift between surfaces.
if (!function_exists('lg_archive_poc_is_gated')) {
function lg_archive_poc_is_gated(?string $content_tier, ?string $viewer_tier): bool {
    static $rank = ['public' => 0, 'lite' => 1, 'pro' => 2];
    $c = $rank[strtolower((string)$content_tier)] ?? 0;
    $v = $rank[strtolower((string)$viewer_tier)] ?? 0;
    return $c > $v;
}
}

// Leak guard for JSON card/item payloads: when the viewer is below the content's
// tier, NULL the prose fields (excerpt / body_preview) and the embedded video id
// (yt_id — and an excerpt can itself carry a raw youtube embed URL, so stripping
// it covers that too). Viewer-RELATIVE: an entitled viewer keeps the full
// payload. Only keys already present are touched, so it's safe on any card
// shape. THE single choke point for /archive-api/v0/{search,item}; SSR templates
// call lg_archive_poc_is_gated() directly to guard their <p>excerpt</p> emit.
if (!function_exists('lg_archive_poc_gate_payload')) {
function lg_archive_poc_gate_payload(array $item, string $viewer_tier): array {
    if (!lg_archive_poc_is_gated($item['tier'] ?? 'public', $viewer_tier)) return $item;
    foreach (['excerpt', 'body_preview', 'yt_id'] as $k) {
        if (array_key_exists($k, $item)) $item[$k] = null;
    }
    return $item;
}
}

// ---------- hostname → filesystem map (for thumb localization) ----------
if (!function_exists('lg_archive_poc_host_to_path_map')) {
function lg_archive_poc_host_to_path_map(): array {
    return [
        'https://' . LG_ARCHIVE_POC_HOST . '/' => LG_ARCHIVE_POC_WP_PATH . '/',
        'http://'  . LG_ARCHIVE_POC_HOST . '/' => LG_ARCHIVE_POC_WP_PATH . '/',
    ];
}
}

// ---------- driver-aware read shims (PG TIMESTAMPTZ/BOOLEAN → legacy SQLite shape) ----------
// The archive render layer + archive.js consume timestamps as unix-epoch ints
// and thumb_broken/has_download as 0|1. The PG content_item deliberately KEEPS
// proper TIMESTAMPTZ/BOOLEAN columns (right for the Hub's cross-schema UNION
// sort); these read shims cast back to the legacy int/0-1 shape *in SQL* so the
// renderers stay untouched. On SQLite they are no-ops (columns already int/0-1).
// See docs/STRANGLER-COORDINATION.md §3i.
if (!function_exists('lg_archive_poc_is_pg')) {
function lg_archive_poc_is_pg(PDO $db): bool {
    return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
}
}
/** SELECT-list expr → epoch-bigint on PG, plain column on SQLite. Aliased to $alias. */
if (!function_exists('lg_ts_sel')) {
function lg_ts_sel(PDO $db, string $expr, string $alias): string {
    return lg_archive_poc_is_pg($db) ? "EXTRACT(epoch FROM $expr)::bigint AS $alias" : "$expr AS $alias";
}
}
/** SELECT-list expr → 0|1 int on PG, plain column on SQLite. Aliased to $alias. */
if (!function_exists('lg_bool_sel')) {
function lg_bool_sel(PDO $db, string $expr, string $alias): string {
    return lg_archive_poc_is_pg($db) ? "($expr)::int AS $alias" : "$expr AS $alias";
}
}
/** Bare scalar (WHERE/ORDER/CASE) → epoch-bigint of a ts col on PG, plain on SQLite. */
if (!function_exists('lg_ts_epoch')) {
function lg_ts_epoch(PDO $db, string $expr): string {
    return lg_archive_poc_is_pg($db) ? "EXTRACT(epoch FROM $expr)::bigint" : $expr;
}
}
/** Standard content_item card SELECT list (driver-aware casts), for `SELECT ci.*` sites. */
if (!function_exists('lg_card_select')) {
function lg_card_select(PDO $db, string $a = 'ci'): string {
    return "$a.id, $a.source, $a.kind, $a.subkind, $a.cpt, $a.title, $a.slug, $a.url, "
         . "$a.excerpt, $a.body_text, $a.thumb_url, " . lg_bool_sel($db, "$a.thumb_broken", 'thumb_broken') . ", "
         . "$a.author_id, $a.author_name, $a.tier, "
         . lg_ts_sel($db, "$a.published_at", 'published_at') . ", " . lg_ts_sel($db, "$a.last_activity", 'last_activity') . ", "
         . "$a.reply_count, $a.like_count, $a.view_count, $a.duration_min, " . lg_bool_sel($db, "$a.has_download", 'has_download') . ", "
         . lg_ts_sel($db, "$a.event_start_at", 'event_start_at') . ", " . lg_ts_sel($db, "$a.event_end_at", 'event_end_at') . ", "
         . "$a.event_region, $a.event_join_url, $a.forum_label, $a.subforum_label, $a.yt_id";
}
}
