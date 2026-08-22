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

if (!function_exists('lg_membership_stripe_test_group_emails')) {
/**
 * THE SAME OPTION, READ FOR ITS ADDRESS ENTRIES (#193).
 *
 * Ian 2026-08-22 ruled the tester list takes plain email addresses beside the
 * member ids, so somebody with no account can walk the whole join. The reader
 * above has ALWAYS dropped non-numeric entries, which is why adding addresses
 * to this option was safe before this function existed and is why an old copy
 * of this file cannot be confused by one.
 *
 * Normalization mirrors LGMS\StripeLifecycle::allowlistEmails() exactly:
 * trimmed, lower-cased, and dropped unless it validates. A malformed entry
 * widens nothing.
 *
 * @return string[] the listed addresses, or [] for absent/empty/malformed
 */
function lg_membership_stripe_test_group_emails(): array {
    static $emails = null;
    if ($emails !== null) return $emails;

    $raw = lg_membership_wp_option('lgms_stripe_lifecycle_allowlist', null);
    if ($raw === null || $raw === '') return $emails = [];

    $decoded = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($decoded)) return $emails = [];

    $out = [];
    foreach ($decoded as $v) {
        if (!is_string($v)) continue;
        $e = strtolower(trim($v));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
        $out[$e] = true;
    }
    return $emails = array_keys($out);
}
}

if (!function_exists('lg_membership_user_email')) {
/** This box's email for a WP user id, or '' — one query, cached per request. */
function lg_membership_user_email(int $wpUserId): string {
    static $cache = [];
    if ($wpUserId <= 0) return '';
    if (array_key_exists($wpUserId, $cache)) return $cache[$wpUserId];
    try {
        $stmt = lg_membership_db()->prepare(
            'SELECT user_email FROM ' . LG_MEMBERSHIP_TABLE_PREFIX . 'users WHERE ID = ? LIMIT 1'
        );
        $stmt->execute([$wpUserId]);
        $val = $stmt->fetchColumn();
        $cache[$wpUserId] = ($val === false) ? '' : strtolower(trim((string) $val));
    } catch (\Throwable $e) {
        $cache[$wpUserId] = '';          // a DB error must not ADMIT anyone
    }
    return $cache[$wpUserId];
}
}

if (!function_exists('lg_membership_in_stripe_test_group')) {
function lg_membership_in_stripe_test_group(int $wpUserId): bool {
    if ($wpUserId <= 0) return false;                        // anon is never listed
    if (!lg_membership_stripe_testgroup_pages()) return false;   // lock 1
    if (in_array($wpUserId, lg_membership_stripe_test_group_ids(), true)) return true;  // lock 2

    /* #193 — LISTED BY ADDRESS. Without this leg a tester whose account was
       created by the join is admitted to CHECKOUT and refused the join page
       itself the moment they arrive on a browser without #180's unlock cookie —
       a second device, a cleared cookie jar, or simply /manage-subscription/
       after they have paid. That is the "wired perfectly and lands nowhere"
       shape this file already carries two warnings about, and it would land in
       the middle of a real-money test.

       NOTHING BELOW RUNS ON A LIST OF PLAIN IDS: no addresses listed means no
       query and behaviour identical to before. */
    $listed = lg_membership_stripe_test_group_emails();
    if ($listed === []) return false;

    /* NORMALIZED HERE, AT THE COMPARE, not only in the lookup that feeds it.
       Gate 34b found this: the first draft leaned on lg_membership_user_email()
       to lower-case, so the door was correct only for as long as that one
       helper stayed the only caller. A predicate whose correctness lives in
       somebody else's function is one refactor from a silent miss, and the
       miss here reads as "this tester is not on the list". */
    $email = strtolower(trim(lg_membership_user_email($wpUserId)));
    return $email !== '' && in_array($email, $listed, true);
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
 * app never boots WordPress and cannot call it. Kept honest by gate 75, which
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

/* ---------- the direction we cannot block (#149/#150) ---------- *
 * A member paying here who then pledges on patreon.com cannot be stopped —
 * nothing of ours runs at Patreon's door. Ian sees them on the Dual Payers
 * admin tab; THIS is the other half of that ruling, the member's own copy, so
 * the first person to notice the double charge is the person paying it and not
 * an audit six weeks later.
 *
 * Behind the SAME `lgms_double_pay_block` row as everything else, so with the
 * flag off nothing is queried and nothing is shown.
 */
if (!function_exists('lg_membership_active_stripe_sub_for_user')) {
/**
 * The member's live Stripe subscription, or null.
 *
 * Matched by wp_user_bridge FIRST and by email only as a fallback — the census
 * for #149 found ELEVEN dual payers on dev2 through the bridge and ZERO by
 * email, because a member's Stripe address need not be their WP address. An
 * email-only lookup here would under-report the same way.
 *
 * @return array{customer_id:int,status:string,tier:?string,amount_cents:?int,interval:?string,matched_by:string}|null
 */
function lg_membership_active_stripe_sub_for_user(int $wpUserId, string $email): ?array {
    if ($wpUserId <= 0) return null;
    try {
        $pdo = lg_membership_poller_db();
        $st = $pdo->prepare(
            "SELECT s.status, c.id AS customer_id, pr.unit_amount_cents, pr.`interval` AS itv,
                    prod.ref AS tier,
                    CASE WHEN b.wp_user_id IS NULL THEN 'email' ELSE 'bridge' END AS matched_by
               FROM subscriptions s
               JOIN customers  c    ON c.id = s.customer_id AND c.deleted_at IS NULL
          LEFT JOIN wp_user_bridge b ON b.customer_id = c.id AND b.wp_user_id = ?
          LEFT JOIN prices     pr   ON pr.stripe_price_id = s.stripe_price_id
          LEFT JOIN products   prod ON prod.id = pr.product_id
              WHERE s.status IN ('active','trialing','past_due')
                AND ( b.wp_user_id IS NOT NULL OR (? <> '' AND c.email = ?) )
           ORDER BY (b.wp_user_id IS NOT NULL) DESC, s.id DESC
              LIMIT 1"
        );
        $st->execute([$wpUserId, $email, $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('lg_membership_active_stripe_sub_for_user: ' . $e->getMessage());
        return null;   // unknown is not a second payment
    }
    if (!is_array($row) || $row === []) return null;
    return [
        'customer_id'  => (int) $row['customer_id'],
        'status'       => (string) $row['status'],
        'tier'         => $row['tier'] !== null ? (string) $row['tier'] : null,
        'amount_cents' => $row['unit_amount_cents'] !== null ? (int) $row['unit_amount_cents'] : null,
        'interval'     => $row['itv'] !== null ? (string) $row['itv'] : null,
        'matched_by'   => (string) $row['matched_by'],
    ];
}
}

if (!function_exists('lg_membership_is_dual_payer')) {
/** True only when BOTH rails are actively charging this member. */
function lg_membership_is_dual_payer(int $wpUserId, string $email): bool {
    if (!lg_membership_double_pay_block()) return false;
    if ($wpUserId <= 0) return false;
    if (lg_membership_patreon_standing($wpUserId)['active'] !== true) return false;
    return lg_membership_active_stripe_sub_for_user($wpUserId, $email) !== null;
}
}

if (!function_exists('lg_membership_dual_payer_message')) {
/**
 * What we say to somebody paying twice.
 *
 * It leads with the fact, not with an apology, because the member's first
 * question is "am I being charged twice" and the answer is yes. It does not
 * pick which one they should cancel — that is theirs — and it promises no
 * automatic refund, because none happens automatically.
 */
function lg_membership_dual_payer_message(): string {
    return 'This account is paying for membership twice: there is an active pledge on Patreon AND'
         . ' an active subscription here. You only need one of them. Cancel whichever you prefer and'
         . ' your access carries on through the other — nothing is interrupted. If you would like the'
         . ' overlap refunded, ask us and we will sort it out.';
}
}

/* ---------- shared helpers ---------- */
if (!function_exists('lg_membership_h')) {
function lg_membership_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}
}
