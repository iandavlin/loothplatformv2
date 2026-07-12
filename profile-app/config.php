<?php
/**
 * profile-app env config.
 *
 * Slice-zero: identity backbone only. Postgres-backed. Mirrors archive-poc's
 * env-detection pattern.
 */

declare(strict_types=1);

if (defined('LG_PROFILE_APP_ENV_LOADED')) return;
define('LG_PROFILE_APP_ENV_LOADED', true);

/**
 * Mint-bounce for WEB surfaces. A viewer with a valid WP session cookie but no
 * looth_id is bounced ONCE through the WP /issue endpoint to mint a looth_id,
 * then 302'd back. Genuine anonymous viewers (no WP cookie) and stale/expired
 * sessions fall through via a one-shot guard cookie and render anonymously.
 *
 * Generalizes web/edit.php's inline hop so every strangled surface mints
 * identity for logged-in WP users even when no WP-rendered page (which fires the
 * init-mint hook) was visited. Call at the TOP of a web entrypoint, before any
 * output. NEVER call from an API endpoint — those must return JSON 401.
 */
function looth_issue_bounce_if_needed(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    $hasWp = false;
    foreach ($_COOKIE as $n => $_v) { if (strncmp($n, 'wordpress_logged_in_', 20) === 0) { $hasWp = true; break; } }
    if (!$hasWp) return;                                       // genuine anonymous viewer — no bounce
    if (!empty($_COOKIE['looth_id'])) {
        // A PRESENT token only counts if it VERIFIES. An expired (or
        // pre-key-rotation) token sitting in the cookie used to block the
        // re-mint forever — a logged-in member rendered as a stranger on
        // their own profile, no editor (Danny West, 6/12). Clear it and
        // fall through to the bounce; the one-shot guard still stops loops.
        if (class_exists('\Looth\ProfileApp\Auth') && \Looth\ProfileApp\Auth::claims() !== null) return;
        setcookie('looth_id', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }
    if (!empty($_COOKIE['looth_issue_tried'])) return;         // one-shot guard: stale session / mint failure can't loop
    setcookie('looth_issue_tried', '1', ['expires' => time() + 120, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    $ret = $_SERVER['REQUEST_URI'] ?? '/';
    if ($ret === '' || $ret[0] !== '/') $ret = '/';
    // Non-REST mint endpoint (wp-auth lane, 7821c3e): the old REST route
    // /wp-json/looth/auth/issue is broken for a plain navigation — BuddyBoss's
    // REST gate (re-armed every DB reload) 401s it, and WP REST cookie-auth
    // needs a nonce a navigation never carries. The plain path authenticates
    // off the logged_in cookie and isn't under BB's REST gate.
    header('Location: /looth-auth/issue?return=' . rawurlencode($ret));
    exit;
}

// Prefer the shared /etc/looth/env (one source of truth across every app); fall
// back to this app's own detection when the file is absent (e.g. dev1), so any
// box without it behaves EXACTLY as before. The env drives the per-branch host +
// WP/DB paths below (profile-app already handles 'dev2'). See lg-shared/lg-env.php.
if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
$shared = function_exists('lg_env') ? lg_env() : [];

$env = $shared['env'] ?? getenv('LG_PROFILE_APP_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    // dev2 = the prod-candidate box (dev2.loothgroup.com / priv ip-172-31-47-205).
    // Same stack as dev (WP at /var/www/dev, MariaDB `looth_import`) but its own
    // host — must be matched BEFORE the bare 'live' fallthrough, else its
    // dev2.loothgroup.com host is read as live and tier/cap lookups hit the
    // nonexistent `looth_live` DB. At the cut this box becomes loothgroup.com
    // and correctly resolves 'live'.
    if (str_starts_with((string)$host, 'dev2.') || str_contains((string)$host, 'ip-172-31-47-205')) {
        $env = 'dev2';
    } elseif (str_starts_with((string)$host, 'dev.') || str_contains((string)$host, '.dev.loothgroup.com') || str_contains((string)$host, 'ip-172-31-81-87') || str_contains((string)$host, 'claude.loothgroup')) {
        $env = 'dev';
    } else {
        $env = 'live';
    }
}
define('LG_PROFILE_APP_ENV', $env);

// Per-env DEFAULTS. These are the fallbacks: every value below is read from the
// shared env ($shared, from /etc/looth/env) FIRST, dropping to the branch literal
// only when the shared key is absent. This kills the trap where $env='live' would
// otherwise hard-wire /var/www/html + looth_live on a box (e.g. the prod candidate
// pre-flip) whose real layout the shared env already describes.
if ($env === 'live') {
    $d_host       = 'loothgroup.com';
    $d_wp_path    = '/var/www/html';
    $d_mysql_db   = 'looth_live';
    $d_billing_db = 'lg_membership';
} elseif ($env === 'dev2') {
    // Prod-candidate box: identical stack to dev, only the host differs.
    $d_host       = 'dev2.loothgroup.com';
    $d_wp_path    = '/var/www/dev';
    $d_mysql_db   = 'looth_import';
    $d_billing_db = 'lg_membership';
} else {
    $d_host       = 'dev.loothgroup.com';
    $d_wp_path    = '/var/www/dev';
    $d_mysql_db   = 'looth_import';
    $d_billing_db = 'lg_membership';
}

// Shared env wins; branch default is the ?? fallback.
define('LG_PROFILE_APP_HOST',             $shared['host']             ?? $d_host);
define('LG_PROFILE_APP_WP_PATH',          $shared['wp_path']          ?? $d_wp_path);
// APP_ROOT self-resolves to the tree ACTUALLY SERVING this request. __DIR__ is
// symlink-resolved by PHP, so a script executed via /srv/profile-app/... resolves
// to the checkout /srv points at; a preview-slot clone resolves to ITSELF. This is
// the true-preview isolation keystone (926cd44) landed in main: src/* classes and
// vendor/ ALWAYS load from the same tree that served the view — never from a
// per-branch pinned path. (The old ~/projects/profile-app pin was a hand-synced
// shadow tree; it 500'd live on 2026-07-06 when a deploy added a class the pin
// didn't have. vendor/ is committed for this app — .gitignore exception — so every
// checkout is class-complete with no composer step.)
define('LG_PROFILE_APP_APP_ROOT',         __DIR__);
define('LG_PROFILE_APP_PG_DSN',           'pgsql:host=/var/run/postgresql;dbname=' . ($shared['pg_db_profile'] ?? 'profile_app'));
define('LG_PROFILE_APP_MYSQL_DB',         $shared['mysql_db']         ?? $d_mysql_db);
define('LG_PROFILE_APP_MYSQL_BILLING_DB', $shared['mysql_billing_db'] ?? $d_billing_db);

// ── Launch gating ──────────────────────────────────────────────────
// Profile blocks deferred past the initial launch: hidden from the builder
// palette AND skipped at render (existing placements won't show). Re-enable by
// removing the key. 'services' -> business (practice) page; 'socials' -> links now
// live in the profile header + an Edit-links modal, so no standalone Links section.
if (!defined('LG_PROFILE_APP_LAUNCH_HIDDEN_BLOCKS')) {
    define('LG_PROFILE_APP_LAUNCH_HIDDEN_BLOCKS', ['services', 'socials']);
}
// Owner "Business" entry pill (the /p/ storefront affordance) — deferred until
// the business page ships. Flip to true to restore it under the profile header.
if (!defined('LG_PROFILE_APP_LAUNCH_SHOW_BUSINESS')) {
    define('LG_PROFILE_APP_LAUNCH_SHOW_BUSINESS', false);
}
// Location address/hours/note extras — deferred to a post-launch Pro feature.
// Hidden in BOTH the visitor display and the owner editor. Flip true to restore.
if (!defined('LG_PROFILE_APP_LAUNCH_SHOW_LOCATION_DETAILS')) {
    define('LG_PROFILE_APP_LAUNCH_SHOW_LOCATION_DETAILS', false);
}

// Canonical, env-correct site logo. Passed to the shared header/footer
// (per the site-header.php contract) as an absolute URL so it resolves from
// preview/sibling hosts too, not just the canonical origin.
define('LG_PROFILE_APP_LOGO_URL',
    'https://' . LG_PROFILE_APP_HOST . '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');

// Hardcoded identity namespace — DO NOT CHANGE. The whole point of v5 is that
// the same email computes the same UUID across services. lg-stripe-billing
// will use this same constant. Rotating it would orphan every previously-
// computed identity.
define('LOOTH_IDENTITY_NAMESPACE', 'eaef23f7-9bc9-4a95-ac49-ffff632e6646');

// Shared webhook secret. Lives outside source at /etc/lg-profile-app-secret
// (mode 640). Mu-plugin reads from wp_options['profile_hook_secret'] which
// must match.
if (!defined('LG_PROFILE_APP_HOOK_SECRET')) {
    $_s = @file_get_contents('/etc/lg-profile-app-secret');
    define('LG_PROFILE_APP_HOOK_SECRET', $_s !== false ? trim($_s) : '');
    unset($_s);
}

require_once LG_PROFILE_APP_APP_ROOT . '/vendor/autoload.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Identity.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Db.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Auth.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Profile.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Practice.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/GeoIP.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Cache.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Slug.php';   // @username ownership + mention resolution (needs Db)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Whoami.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/R2.php';      // R2 (S3) client for profile media originals
require_once LG_PROFILE_APP_APP_ROOT . '/src/MessageR2.php'; // R2 (S3) client for DM image attachments (SEPARATE bucket)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Media.php';   // confined GC of an owned media file + resizer cache twins
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // profile-2.0 spine (centralized per coordinator ask)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Visibility.php'; // THE visibility decision point (Ian 6/12 refactor)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Mint.php';    // looth_id signing (shim-replacement)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Connections.php';   // social layer
require_once LG_PROFILE_APP_APP_ROOT . '/src/Messaging.php';     // social layer
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php'; // social layer
require_once LG_PROFILE_APP_APP_ROOT . '/src/Mutes.php';         // social layer (author mute)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Social.php';        // social layer widget + counts
require_once LG_PROFILE_APP_APP_ROOT . '/src/EraseUser.php';     // user-lifecycle teardown (Phase 1)
require_once LG_PROFILE_APP_APP_ROOT . '/src/Provision.php';     // user-lifecycle create + email-change (G4/G7)
