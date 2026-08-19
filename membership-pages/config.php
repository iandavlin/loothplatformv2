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

// Assets mount. Overridable by the SERVER, never by a request: a lane preview
// serves this surface from a path prefix (/preview/<lane>/…), and the CSS/JS
// links must follow it or the preview renders unstyled — which reads as "the
// branch is broken" when it is only mounted somewhere else. fastcgi_param, so
// it can only be set by an nginx block on this box; $_GET can never reach it.
// Unset (every normal request, on dev and live) → byte-identical to before.
define('LG_MEMBERSHIP_PUBLIC_PATH',
    (isset($_SERVER['LG_MS_PUBLIC_PATH']) && $_SERVER['LG_MS_PUBLIC_PATH'] !== '')
        ? rtrim((string) $_SERVER['LG_MS_PUBLIC_PATH'], '/')
        : '/membership-pages');
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

/* ---------- Stripe Test Group (the soft-launch list) ---------- *
 * Ian ruled the soft launch runs through the EXISTING member pages, unlocked
 * for a hand-picked list, rather than a bespoke page
 * (docs/STRIPE-TEST-VIA-EXISTING-PAGES.md). These three helpers are the READ
 * side of that list for the standalone pages; the WRITE side is the poller's
 * admin dash (LGMS\CohortAllowlist), and both address the SAME wp_option, so
 * they cannot drift.
 *
 * TWO locks, and either one alone keeps the pages shut:
 *   1. `lgms_stripe_testgroup_pages` — off/absent (the default) means the
 *      Test Group unlocks NOTHING and every page behaves exactly as it does
 *      today: administrator-only. This is the house flag rule.
 *   2. the list itself — absent, empty or malformed means NOBODY, which is
 *      the same fail-safe the membership grant already uses.
 *
 * The option is a PHP-SERIALIZED array (that is how WordPress stores an array
 * option) — reading it as JSON silently finds nothing, which would read as
 * "the list is empty" and fail open on a populated list if this were ever the
 * only lock. Numeric strings are accepted because a hand-set list written with
 * `wp option update ... --format=json` arrives that way.
 */
if (!function_exists('lg_membership_stripe_testgroup_pages')) {
function lg_membership_stripe_testgroup_pages(): bool {
    return lg_membership_wp_option('lgms_stripe_testgroup_pages', '0') === '1';
}
}

if (!function_exists('lg_membership_stripe_test_group_ids')) {
/** @return int[] the Test Group, or [] for absent/empty/malformed (= nobody) */
function lg_membership_stripe_test_group_ids(): array {
    static $ids = null;
    if ($ids !== null) return $ids;

    $raw = lg_membership_wp_option('lgms_stripe_lifecycle_allowlist', null);
    if ($raw === null || $raw === '') return $ids = [];

    $decoded = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($decoded)) return $ids = [];   // a string/int/bool option = nobody

    $out = [];
    foreach ($decoded as $v) {
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            $n = (int) $v;
            if ($n > 0) $out[] = $n;
        }
    }
    return $ids = array_values(array_unique($out));
}
}

if (!function_exists('lg_membership_in_stripe_test_group')) {
function lg_membership_in_stripe_test_group(int $wpUserId): bool {
    if ($wpUserId <= 0) return false;                        // anon is never listed
    if (!lg_membership_stripe_testgroup_pages()) return false;   // lock 1
    return in_array($wpUserId, lg_membership_stripe_test_group_ids(), true);  // lock 2
}
}

/* ---------- one payment source per member (#150) ---------- *
 * Ian, 2026-08-19, verbatim: "We should disallow double payment source for the
 * same user." A member whose membership is already being charged on Patreon
 * gets a banner and a pointer here instead of buy buttons.
 *
 * THIS IS THE THIRD READER OF ONE SWITCH. The poller reads
 * `lgms_double_pay_block` with get_option(); the Slim billing app cannot read
 * WordPress at all, so it asks the poller at a route that only exists while the
 * flag is on; and this app reads the SAME wp_options row over SQL, the way it
 * already reads lgms_stripe_testgroup_pages. One row, three readers, nothing to
 * drift.
 *
 * The verdict logic below MIRRORS LGMS\Membership\PatreonStanding, because this
 * app never boots WordPress and cannot call it. Kept honest by gate 74, which
 * compares the member-facing copy sentence for sentence against the poller's
 * and checks this file names the same option.
 *
 * NEVER `payment_source`: one slot, descriptive only, and the two rails
 * overwrite each other in it (docs/domains/MEMBERSHIP.md). It is not read here.
 */
if (!function_exists('lg_membership_double_pay_block')) {
function lg_membership_double_pay_block(): bool {
    return lg_membership_wp_option('lgms_double_pay_block', '0') === '1';
}
}

if (!function_exists('lg_membership_patreon_refusal_message')) {
/** The copy the member reads. Byte-identical to PatreonStanding::refusalMessage(). */
function lg_membership_patreon_refusal_message(): string {
    return 'Your membership is already paid through Patreon, so buying here would charge you twice.'
         . ' To move your billing to the site, cancel your pledge on Patreon first — your Patreon'
         . ' membership keeps running to the end of the period you have already paid for — then come'
         . ' back and join here once it lapses.';
}
}

if (!function_exists('lg_membership_patreon_manage_url')) {
function lg_membership_patreon_manage_url(): string {
    $u = trim((string) (lg_membership_wp_option('lgpo_patreon_link', '') ?? ''));
    return $u !== '' ? $u : 'https://www.patreon.com/';
}
}

if (!function_exists('lg_membership_patreon_standing')) {
/**
 * Is this member being CHARGED by Patreon right now?
 *
 * @return array{active:bool,tier:?string,tier_label:?string,reason:string}
 *
 * Same three facts the sweep decides a role from: the Patreon link, the entitled
 * tier through lgpo_tier_map, and the live patron_status snapshot. A tier that
 * is missing from the map grants no role and still bills the member every
 * month, so a positive entitled amount counts as paying on its own — the
 * question here is whether money is moving, not what role it buys.
 *
 * `synced_at` is deliberately not consulted: it is last-CHANGED, not
 * last-checked, so the steadiest patrons carry the oldest rows and a freshness
 * test would unblock exactly the wrong members.
 */
function lg_membership_patreon_standing(int $wpUserId): array {
    $none = ['active' => false, 'tier' => null, 'tier_label' => null, 'reason' => 'no_patreon_link'];
    if ($wpUserId <= 0) return $none;

    try {
        $st = lg_membership_db()->prepare(
            'SELECT meta_key, meta_value FROM ' . LG_MEMBERSHIP_TABLE_PREFIX . 'usermeta
              WHERE user_id = ? AND meta_key IN (?, ?)'
        );
        $st->execute([$wpUserId, 'lgpo_patreon_user_id', 'lgpo_patreon_tier_id']);
        $meta = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $meta[(string) $r['meta_key']] = trim((string) $r['meta_value']);
        }
    } catch (Throwable $e) {
        error_log('lgjoin patreon standing (usermeta): ' . $e->getMessage());
        return $none;   // unknown is not a payment
    }

    if (($meta['lgpo_patreon_user_id'] ?? '') === '') return $none;

    try {
        $st = lg_membership_poller_db()->prepare(
            'SELECT patron_status, currently_entitled_amount_cents, tier_label
               FROM lg_patreon_members WHERE wp_user_id = ? LIMIT 1'
        );
        $st->execute([$wpUserId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('lgjoin patreon standing (snapshot): ' . $e->getMessage());
        return $none;
    }
    if (!is_array($row) || $row === []) {
        return ['active' => false, 'tier' => null, 'tier_label' => null, 'reason' => 'no_patreon_record'];
    }

    // A row that says NOTHING is not a row that says PAYING.
    $status = ($row['patron_status'] ?? null) !== null ? (string) $row['patron_status'] : null;
    $label  = ($row['tier_label'] ?? null) !== null ? (string) $row['tier_label'] : null;
    if ($status !== 'active_patron') {
        return ['active' => false, 'tier' => null, 'tier_label' => $label, 'reason' => 'not_active_patron'];
    }

    // lgpo_tier_map is a WordPress array option, so it is PHP-SERIALIZED in the
    // table. Reading it as JSON silently finds nothing, which here would read as
    // "no mapped tier" and lean on the amount alone.
    $tier = null;
    $rawMap = lg_membership_wp_option('lgpo_tier_map', null);
    if (is_string($rawMap) && $rawMap !== '' && ($meta['lgpo_patreon_tier_id'] ?? '') !== '') {
        $map = @unserialize($rawMap, ['allowed_classes' => false]);
        if (is_array($map) && isset($map[$meta['lgpo_patreon_tier_id']])) {
            $mapped = (string) $map[$meta['lgpo_patreon_tier_id']];
            if ($mapped === 'looth2' || $mapped === 'looth3') $tier = $mapped;
        }
    }

    $cents  = ($row['currently_entitled_amount_cents'] ?? null) !== null
        ? (int) $row['currently_entitled_amount_cents'] : null;
    $paying = $tier !== null || ($cents !== null && $cents > 0);

    return [
        'active'     => $paying,
        'tier'       => $tier,
        'tier_label' => $label,
        'reason'     => $paying ? 'active_paid_patron' : 'active_patron_not_paying',
    ];
}
}

/* ---------- shared helpers ---------- */
if (!function_exists('lg_membership_h')) {
function lg_membership_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
}
