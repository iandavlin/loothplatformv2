<?php
/**
 * membership-pages — standalone surface config.
 *
 * Pattern lifted from events/archive-poc/bb-mirror: standalone PHP served on
 * its own nginx route + FPM pool, NO WordPress boot. Reads page-content data
 * directly from WP's MySQL (read-only), renders on /srv/lg-shared/ chrome.
 * Viewer state for the header comes from a cached /whoami loopback. Listing
 * DATA never calls into WP.
 *
 * DB credentials: /etc/lg-membership-db (mode 640, never committed —
 * MANIFEST secret convention). Format = KEY=VALUE lines:
 *   DB_NAME=…  DB_USER=…  DB_PASSWORD=…  DB_HOST=localhost
 *
 * For first deploy on dev the events secret at /etc/lg-events-db is a valid
 * fallback (both surfaces read the same wp_options table read-only). See
 * SESSION-HANDOFF for the provisioning checklist.
 */

declare(strict_types=1);

if (defined('LG_MEMBERSHIP_ENV_LOADED')) return;
define('LG_MEMBERSHIP_ENV_LOADED', true);

/* ---------- env detection ---------- */
// Prefer the shared /etc/looth/env (one source of truth across every app);
// fall back to this app's own detection when the file is absent (e.g. dev1),
// so any box without it behaves EXACTLY as before. See lg-shared/lg-env.php.
if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
$shared = function_exists('lg_env') ? lg_env() : [];

$env = $shared['env'] ?? getenv('LG_MEMBERSHIP_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    $env = ( str_starts_with((string)$host, 'dev.')
          || str_contains((string)$host, 'claude.loothgroup')
          || str_contains((string)$host, 'ip-172-31-81-87') ) ? 'dev' : 'live';
}
define('LG_MEMBERSHIP_ENV', $env);

// Shared host (if /etc/looth/env present) is authoritative; else the env default.
// (This host was env-only before, so on dev2 it pinned dev.loothgroup.com — the
// shared file now resolves it to dev2.loothgroup.com.)
if (isset($shared['host'])) {
    define('LG_MEMBERSHIP_HOST', $shared['host']);
} elseif ($env === 'live') {
    define('LG_MEMBERSHIP_HOST', 'loothgroup.com');
} else {
    define('LG_MEMBERSHIP_HOST', 'dev.loothgroup.com');
}

define('LG_MEMBERSHIP_PUBLIC_PATH', '/membership-pages');   // assets mount
define('LG_MEMBERSHIP_TABLE_PREFIX', 'wp_');
define('LG_MEMBERSHIP_UPLOADS_BASE', 'https://' . LG_MEMBERSHIP_HOST . '/wp-content/uploads/');
define('LG_MEMBERSHIP_LOGO',
    'https://' . LG_MEMBERSHIP_HOST . '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');

/* ---------- DB secret with events-secret fallback (dev only) ---------- */
$db_secret_path = '/etc/lg-membership-db';
if (!is_readable($db_secret_path) && is_readable('/etc/lg-events-db')) {
    // Dev convenience: both surfaces read wp_options read-only. Live MUST
    // have its own /etc/lg-membership-db per the secret-isolation convention.
    $db_secret_path = '/etc/lg-events-db';
}
define('LG_MEMBERSHIP_DB_SECRET', $db_secret_path);

/* ---------- read-only WP-MySQL connection (no WP boot) ---------- */
if (!function_exists('lg_membership_db')) {
function lg_membership_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $raw = @file_get_contents(LG_MEMBERSHIP_DB_SECRET);
    if ($raw === false) {
        throw new RuntimeException('membership-pages: cannot read DB secret at ' . LG_MEMBERSHIP_DB_SECRET);
    }
    $c = ['DB_HOST' => 'localhost', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASSWORD' => ''];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = strtoupper(trim($k));
        if (array_key_exists($k, $c)) $c[$k] = trim($v);
    }
    $dsn = "mysql:host={$c['DB_HOST']};dbname={$c['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
}

/* ---------- read-only POLLER-MySQL connection (lg_membership DB) ---------- *
 * Separate from lg_membership_db() which reads the WP DB. The poller owns its
 * own MySQL database (lg_membership) with subscription / patron / entitlement
 * tables. Read-only here — mutations stay in the poller plugin.
 *
 * Secret: /etc/lg-poller-db (KEY=VAL lines: DB_NAME, DB_USER, DB_PASSWORD, DB_HOST).
 * Dev fallback: when the secret file is missing, read the credentials from WP's
 *               wp_options table (the poller stashes them there as lgms_db_*).
 *               That keeps first-deploy on dev painless — same as the events-DB
 *               fallback above.
 */
if (!function_exists('lg_membership_poller_db')) {
function lg_membership_poller_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $c = ['DB_HOST' => '127.0.0.1', 'DB_NAME' => 'lg_membership', 'DB_USER' => '', 'DB_PASSWORD' => ''];
    $secret_path = '/etc/lg-poller-db';

    if (is_readable($secret_path)) {
        $raw = (string) @file_get_contents($secret_path);
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = strtoupper(trim($k));
            if (array_key_exists($k, $c)) $c[$k] = trim($v);
        }
    } else {
        // Dev fallback — pull poller DB creds from WP options. Single cold read
        // per request; the WP DB itself is the events-fallback secret.
        try {
            $stmt = lg_membership_db()->prepare(
                "SELECT option_name, option_value FROM " . LG_MEMBERSHIP_TABLE_PREFIX .
                "options WHERE option_name IN ('lgms_db_host','lgms_db_port','lgms_db_name','lgms_db_user','lgms_db_pass')"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                switch ($row['option_name']) {
                    case 'lgms_db_host': $c['DB_HOST']     = (string) $row['option_value']; break;
                    case 'lgms_db_name': $c['DB_NAME']     = (string) $row['option_value']; break;
                    case 'lgms_db_user': $c['DB_USER']     = (string) $row['option_value']; break;
                    case 'lgms_db_pass': $c['DB_PASSWORD'] = (string) $row['option_value']; break;
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException('membership-pages: cannot read poller DB secret at ' . $secret_path . ' and WP-options fallback failed: ' . $e->getMessage());
        }
    }

    if ($c['DB_USER'] === '') {
        throw new RuntimeException('membership-pages: poller DB creds unresolved (no ' . $secret_path . ' and no lgms_db_user in wp_options)');
    }

    $dsn = "mysql:host={$c['DB_HOST']};dbname={$c['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
}

/* ---------- read-only WP-option reader (no WP boot) ---------- *
 * Reads a single row from wp_options via the read-only WP-MySQL connection.
 * Per-request static cache so repeated reads of the same option are free.
 * Returns $default when the option is absent or the read fails (fail-safe:
 * callers like the Stripe-pages live toggle default to the SAFE pre-launch
 * state when the DB is unreachable).
 */
if (!function_exists('lg_membership_wp_option')) {
function lg_membership_wp_option(string $name, ?string $default = null): ?string {
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];
    try {
        $stmt = lg_membership_db()->prepare(
            'SELECT option_value FROM ' . LG_MEMBERSHIP_TABLE_PREFIX .
            'options WHERE option_name = ? LIMIT 1'
        );
        $stmt->execute([$name]);
        $val = $stmt->fetchColumn();
        $cache[$name] = ($val === false) ? $default : (string) $val;
    } catch (\Throwable $e) {
        $cache[$name] = $default;
    }
    return $cache[$name];
}
}

/* ---------- Stripe purchase-pages live toggle ---------- *
 * Admin-flippable switch (wp_option `lgms_stripe_pages_live`, written from the
 * poller's WP-admin settings page). OFF (default) = purchase pages stay
 * admin-only while Ian builds the Stripe op pre-launch. ON = they serve their
 * real public/member visibility. Fail-safe: any non-'1' value (incl. unset or
 * DB error) keeps the pages locked down. See router.php.
 */
if (!function_exists('lg_membership_stripe_pages_live')) {
function lg_membership_stripe_pages_live(): bool {
    return lg_membership_wp_option('lgms_stripe_pages_live', '0') === '1';
}
}

/* ---------- shared helpers ---------- */
if (!function_exists('lg_membership_h')) {
function lg_membership_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
}
