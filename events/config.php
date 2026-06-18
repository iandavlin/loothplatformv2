<?php
/**
 * events — standalone events-landing surface config.
 *
 * Pattern lifted from bb-mirror/archive-poc: a standalone PHP surface served
 * on its own nginx route + FPM pool, NO WordPress boot. Reads the `event` CPT
 * data directly from WP's MySQL (read-only), and renders on the shared
 * /srv/lg-shared/ chrome. Viewer state for the header comes from a cached
 * /whoami loopback (same as the other surfaces); the listing DATA never calls
 * into WP.
 *
 * DB credentials: read from /etc/lg-events-db (mode 640, never committed —
 * MANIFEST secret convention). Format = KEY=VALUE lines:
 *   DB_NAME=…  DB_USER=…  DB_PASSWORD=…  DB_HOST=localhost
 */

declare(strict_types=1);

if (defined('LG_EVENTS_ENV_LOADED')) return;
define('LG_EVENTS_ENV_LOADED', true);

/* ---------- env detection ---------- */
// Prefer the shared /etc/looth/env (one source of truth across every app);
// fall back to this app's own detection when the file is absent (e.g. dev1),
// so any box without it behaves EXACTLY as before. See lg-shared/lg-env.php.
if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
$shared = function_exists('lg_env') ? lg_env() : [];

$env = $shared['env'] ?? getenv('LG_EVENTS_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    $env = ( str_starts_with((string)$host, 'dev.')
          || str_contains((string)$host, 'claude.loothgroup')
          || str_contains((string)$host, 'ip-172-31-81-87') ) ? 'dev' : 'live';
}
define('LG_EVENTS_ENV', $env);

/* ---------- browser-facing / loopback-routing host (request-derived) ----------
 * Decoupled from env (which selects PATHS only): at the cut the prod box runs
 * ENV=dev but its public host is loothgroup.com, and dev2 is neither default.
 * Derive from the live request so dev / dev2 / loothgroup.com each self-resolve;
 * CLI/cron (no HTTP_HOST) fall back to LG_EVENTS_PUBLIC_HOST, else the env default.
 * Sanitized — the value feeds a curl 'Host:' header (close Host-header injection). */
$ev_host_fallback = getenv('LG_EVENTS_PUBLIC_HOST')
    ?: (($env === 'live') ? 'loothgroup.com' : 'dev.loothgroup.com');
$ev_req_host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
// Shared host (if /etc/looth/env present) is authoritative; else request-derived, else fallback.
define('LG_EVENTS_HOST', $shared['host'] ?? ($ev_req_host !== '' ? $ev_req_host : $ev_host_fallback));
define('LG_EVENTS_PUBLIC_PATH',  '/events');                 // nginx mount (takes over /events/)
define('LG_EVENTS_TABLE_PREFIX', 'wp_');
define('LG_EVENTS_DB_SECRET',    '/etc/lg-events-db');
define('LG_EVENTS_UPLOADS_BASE', 'https://' . LG_EVENTS_HOST . '/wp-content/uploads/');
define('LG_EVENTS_EVENT_BASE',   'https://' . LG_EVENTS_HOST . '/event/'); // pretty permalink /event/<slug>/
define('LG_EVENTS_LOGO',
    'https://' . LG_EVENTS_HOST . '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');

/* ---------- read-only WP-MySQL connection (no WP boot) ---------- */
if (!function_exists('lg_events_db')) {
function lg_events_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $raw = @file_get_contents(LG_EVENTS_DB_SECRET);
    if ($raw === false) {
        throw new RuntimeException('events: cannot read DB secret at ' . LG_EVENTS_DB_SECRET);
    }
    $c = ['DB_HOST' => 'localhost', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => ''];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = strtoupper(trim($k));
        if (array_key_exists($k, $c)) $c[$k] = trim($v);
    }

    // DB_HOST 'localhost' → MySQL uses the unix socket; pass it through PDO.
    $host = $c['DB_HOST'];
    $dsn  = "mysql:host={$host};dbname={$c['DB_NAME']};charset=utf8mb4";
    $pdo  = new PDO($dsn, $c['DB_USER'], $c['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
}

/* ---------- viewer state for the shared header (cached /whoami loopback) ----------
 * Mirrors bb-mirror: the listing is public, but the header still reflects the
 * viewer (avatar/name/tier) when logged in. Cached in tmpfs per WP session so
 * we don't pay the WP-bootstrap tax on every render. NOT used for listing data. */
if (!function_exists('lg_events_whoami')) {
function lg_events_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;

    $ttl  = 45;
    $sess = '';
    foreach ($_COOKIE as $k => $v) {
        if (strpos($k, 'wordpress_logged_in_') === 0) { $sess = (string)$v; break; }
    }
    $key   = $sess !== '' ? hash('sha256', $sess) : 'anon';
    $cache = '/dev/shm/lg-events-whoami-' . $key . '.json';
    if (is_readable($cache) && (time() - (int)filemtime($cache)) < $ttl) {
        $hit = json_decode((string)file_get_contents($cache), true);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            return $result = (is_array($hit['v']) ? $hit['v'] : null);
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/whoami',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_EVENTS_HOST,
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = ($code === 200 && is_string($body)) ? (json_decode($body, true) ?: null) : null;
    @file_put_contents($cache, json_encode(['v' => $result]), LOCK_EX);
    return $result;
}
}
