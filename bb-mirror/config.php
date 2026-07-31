<?php
/**
 * bb-mirror env config.
 *
 * Pattern lifted from archive-poc/config.php. Auto-detects live vs dev
 * via HTTP_HOST or hostname() fallback. Override via
 * `LG_BB_MIRROR_ENV=dev` in CLI.
 *
 * Backend: postgres. The earlier SQLite rollback path is retired (the
 * `forums` schema in `looth` has been the canonical store since the
 * postgres migration on 2026-05-28). To reintroduce SQLite, see
 * handoffs/2026-05-28-pre-pg-migration.md for the snapshot.
 */

if (defined('LG_BB_MIRROR_ENV_LOADED')) return;
define('LG_BB_MIRROR_ENV_LOADED', true);

// ---------- env detection ----------
// Prefer the shared /etc/looth/env (one source of truth across every app);
// fall back to this app's own detection when the file is absent (e.g. dev1),
// so any box without it behaves EXACTLY as before. See lg-shared/lg-env.php.
if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
$shared = function_exists('lg_env') ? lg_env() : [];

$env = $shared['env'] ?? getenv('LG_BB_MIRROR_ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    if (str_starts_with((string)$host, 'dev.') || str_contains((string)$host, 'ip-172-31-81-87') || str_contains((string)$host, 'claude.loothgroup')) {
        $env = 'dev';
    } else {
        $env = 'live';
    }
}
define('LG_BB_MIRROR_ENV', $env);

// ---------- env-specific values (PATHS/users only — NOT the host) ----------
// LG_BB_MIRROR_ENV selects the filesystem + WP user the app runs against. The
// browser-facing host is derived separately (below) from the actual request,
// because at the cut the prod box runs ENV=dev — it lives in /var/www/dev +
// looth-dev — while its public host is loothgroup.com. Env and host are
// decoupled so no per-host edit is needed for dev / dev2 / loothgroup.com.
// Per-env DEFAULTS (the fallback when the shared env file omits a key, e.g.
// dev1). The shared /etc/looth/env values (read below) take precedence.
if ($env === 'live') {
    $bb_wp_path_default  = '/var/www/html';
    $bb_wp_user_default  = 'looth-live';
    $bb_app_root_default = '/srv/bb-mirror';
} else { // dev (also the dev2 / prod-at-cut box: /var/www/dev + looth-dev)
    $bb_wp_path_default  = '/var/www/dev';
    $bb_wp_user_default  = 'looth-dev';
    $bb_app_root_default = '/home/ubuntu/projects/bb-mirror';
}
define('LG_BB_MIRROR_WP_PATH', $shared['wp_path'] ?? $bb_wp_path_default);
define('LG_BB_MIRROR_WP_USER', $shared['wp_user'] ?? $bb_wp_user_default);
// APP_ROOT has no shared key — branch-derived (the prod box symlinks /srv/bb-mirror).
define('LG_BB_MIRROR_APP_ROOT', $bb_app_root_default);
// The mount the app believes it is served under. '/hub' everywhere real; a LANE
// PREVIEW mounts the same app under /preview/<lane>/hub so Ian can click a branch
// on the vhost he is already signed into (tools/preview/lane-preview.sh). index.php
// strips this prefix off REQUEST_URI to route, and every internal link is built from
// it — so without this the preview 404s on its own front controller, and any link it
// did render would jump the reader back out to the real /hub/.
//
// Same mechanism as membership-pages' LG_MS_PUBLIC_PATH, deliberately: one preview
// system, not two. A fastcgi_param can only be set by an nginx conf, never by a query
// string. Validated anyway — a mount is a rooted path of safe characters, nothing
// else — because "it can only come from a conf" is an assumption, and this value is
// concatenated into links.
$bb_public_path = '/hub';
$bb_pp_param    = (string) ($_SERVER['LG_BB_MIRROR_PUBLIC_PATH'] ?? '');
if ($bb_pp_param !== '' && preg_match('#^/[A-Za-z0-9/_-]{1,80}$#', $bb_pp_param)
    && strpos($bb_pp_param, '..') === false) {
    $bb_public_path = rtrim($bb_pp_param, '/');
}
define('LG_BB_MIRROR_PUBLIC_PATH', $bb_public_path);

// ---------- browser-facing / loopback-routing host (request-derived) ----------
// Single source of truth for both (a) the public host used to build URLs and
// (b) the loopback CURL 'Host:' header that picks this box's nginx vhost.
// Derived from the live request so dev, dev2, and loothgroup.com each
// self-resolve. CLI/cron (reconcile, materializers — no HTTP_HOST) and any
// loopback that runs before a request fall back to, in order:
//   1. LG_BB_MIRROR_PUBLIC_HOST — set in the FPM pool + cron env on any box
//      whose public host differs from its env default (dev2, prod-at-cut);
//   2. else the env default below.
// Sanitized: the value is interpolated into curl 'Host:' headers, so strip
// anything outside a valid hostname[:port] to close Host-header injection.
$bb_host_fallback = getenv('LG_BB_MIRROR_PUBLIC_HOST')
    ?: (($env === 'live') ? 'loothgroup.com' : 'dev.loothgroup.com');
$bb_req_host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
// Shared host (if /etc/looth/env present) is authoritative; else request-derived, else fallback.
define('LG_BB_MIRROR_HOST', $shared['host'] ?? ($bb_req_host !== '' ? $bb_req_host : $bb_host_fallback));

// ---------- derived ----------
define('LG_BB_MIRROR_SCHEMA_PG',  LG_BB_MIRROR_APP_ROOT . '/schema.pg.sql');
define('LG_BB_MIRROR_WP_LOAD',    LG_BB_MIRROR_WP_PATH  . '/wp-load.php');

// Forums a member may NOT start a topic in from a composer, beyond what the
// generic rules (public + open + forum_type 'forum' + no sub-forums) already
// exclude. Both of these look perfectly postable to those rules:
//   3876  Quick Questions      — public, open, a leaf. Excluded by product decision.
//   67251 Anonymous Questions  — has its own posting route.
// Lives HERE because two different pools have to agree about it: the add-post
// picker is rendered by web/_chrome.php on the bb-mirror pool (PG), and the topic
// edit PUT enforces it in api/v0/reply.php on the WP pool. When only the picker
// knew, the list was merely "not offered" — a hand-built request could still file
// a post into one, and moving a post there was accepted by the edit endpoint.
define('LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS', [3876, 67251]);

// Postgres (forums schema in shared looth DB)
define('LG_BB_MIRROR_PG_DB',      $shared['pg_db'] ?? 'looth');
define('LG_BB_MIRROR_PG_SCHEMA',  'forums');
define('LG_BB_MIRROR_PG_DSN',     'pgsql:host=/var/run/postgresql;dbname=' . LG_BB_MIRROR_PG_DB);

// ---------- DB connection ----------
if (!function_exists('bb_mirror_db')) {
function bb_mirror_db(bool $readonly = true): PDO {
    $pdo = new PDO(LG_BB_MIRROR_PG_DSN, null, null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET search_path = " . LG_BB_MIRROR_PG_SCHEMA . ", public");
    return $pdo;
}
}

// ---------- time-column helpers ----------
// Postgres TIMESTAMPTZ writes accept ISO 8601 strings; helpers normalize.
if (!function_exists('bb_mirror_ts')) {
function bb_mirror_ts(?int $unix): ?string {
    if ($unix === null || $unix <= 0) return null;
    return gmdate('Y-m-d\TH:i:s\Z', $unix);
}
}

if (!function_exists('bb_mirror_ts_in')) {
function bb_mirror_ts_in($v): ?int {
    if (!$v) return null;
    if (is_numeric($v)) return (int)$v;
    $t = strtotime((string)$v . ' UTC');
    return $t ?: null;
}
}

// ---------- upsert SQL builder ----------
// Postgres ON CONFLICT (<col>) DO UPDATE pattern. $conflict_col can be a
// composite list like 'user_id, target_kind, target_id' for forum_subscription.
if (!function_exists('bb_mirror_upsert_sql')) {
function bb_mirror_upsert_sql(string $table, array $cols, string $conflict_col = 'id'): string {
    $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $collist      = '(' . implode(',', $cols) . ')';
    $setters = [];
    foreach ($cols as $c) {
        if ($c === $conflict_col) continue;
        $setters[] = "$c = EXCLUDED.$c";
    }
    return "INSERT INTO $table $collist VALUES $placeholders " .
           "ON CONFLICT ($conflict_col) DO UPDATE SET " . implode(', ', $setters);
}
}

if (!function_exists('bb_mirror_bool')) {
function bb_mirror_bool(bool $b): string {
    return $b ? 'true' : 'false';
}
}

// ---------- viewer + tier filter (single source of truth) ----------
//
// Reads are NOT tier-gated today — visibility filter on forum is the only
// read gate. tier_clause() machinery remains for future write-eligibility
// checks (reply form gating once /whoami ships).

if (!function_exists('bb_mirror_viewer_tiers')) {
function bb_mirror_viewer_tiers(): array {
    return ['public'];
}
}

if (!function_exists('bb_mirror_tier_clause')) {
function bb_mirror_tier_clause(string $column): array {
    $tiers = bb_mirror_viewer_tiers();
    return [
        'sql'  => $column . ' IN (' . implode(',', array_fill(0, count($tiers), '?')) . ')',
        'bind' => $tiers,
    ];
}
}


// ---------- /whoami — viewer identity (cached per request) ----------
// Option 3: try the fast JWT endpoint first, fall back to the WP shim.
//
//  1. Fast path — /profile-api/v0/whoami keys off the visitor's `looth_id`
//     JWT (~5ms, no WP boot). If it returns authenticated:true, use it.
//  2. Shim fallback — /wp-json/looth/v1/whoami bridges the WP login session
//     (validates wordpress_logged_in_* + adds trusted headers) and catches
//     members who have no JWT yet (the unbridged-member gap). Slow (boots WP,
//     ~687ms), so it fires only when the fast path returned anon AND the
//     visitor actually has a WP login cookie — a cookieless visitor can't be a
//     logged-in member the shim could rescue, so we skip it and stay anon fast.
//
// Self-healing: the login lanes (bridge enabled 2026-06-04) hand every member a
// JWT, so over time almost everyone hits the fast path and the shim rarely fires.
// Both endpoints return the same shape; tier_unavailable:true (poller down) →
// tier='public' (fail open). Returns null on failure; callers fall back to anon.
if (!function_exists('lg_bb_mirror_whoami')) {
function lg_bb_mirror_whoami(): ?array {
    static $fetched = false, $result = null;
    if ($fetched) return $result;
    $fetched = true;
    if (PHP_SAPI === 'cli') return null;

    // --- perf cache (2026-05-29) ---------------------------------------------
    // Caches the *resolved* identity per viewer in tmpfs so even a shim
    // fallback bites only on a miss. TTL-only, NOT wired to PurgeNotifier — a
    // tier/name change becomes visible within WHOAMI_CACHE_TTL. Keyed by BOTH
    // the WP session cookie and the looth_id JWT, so two distinct identities
    // can never collide on a key ("anon" for visitors with neither).
    $WHOAMI_CACHE_TTL = 45;
    $sess = '';
    foreach ($_COOKIE as $k => $v) {
        if (strpos($k, 'wordpress_logged_in_') === 0) { $sess = (string)$v; break; }
    }
    $jwt = (string)($_COOKIE['looth_id'] ?? '');
    $cacheKey  = ($sess !== '' || $jwt !== '') ? hash('sha256', $sess . '|' . $jwt) : 'anon';
    $cacheFile = '/dev/shm/bb-whoami-' . $cacheKey . '.json';
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $WHOAMI_CACHE_TTL) {
        $hit = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($hit) && array_key_exists('v', $hit)) {
            $result = is_array($hit['v']) ? $hit['v'] : null;
            return $result;
        }
    }
    // -------------------------------------------------------------------------

    // Loopback call forwarding the visitor's own cookies (so their looth_id JWT
    // / WP session ride along). Returns [http_code, decoded_array|null].
    $call = function (string $url): array {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Host: ' . LG_BB_MIRROR_HOST,
                'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = ($code === 200 && $body) ? json_decode($body, true) : null;
        if (is_array($data) && !empty($data['tier_unavailable'])) {
            $data['tier'] = 'public';   // poller down → fail open
        }
        return [$code, is_array($data) ? $data : null];
    };

    // 1. Fast path.
    [$code, $data] = $call('https://127.0.0.1/profile-api/v0/whoami');

    // 2. Shim fallback — only if fast didn't recognize an authenticated viewer
    //    AND there's a WP login cookie the shim could actually bridge.
    if (($data['authenticated'] ?? false) !== true && $sess !== '') {
        [$code, $data] = $call('https://127.0.0.1/wp-json/looth/v1/whoami');
    }

    $result = $data;

    // Cache only definitive results (clean 200). Transient failures (timeout,
    // 5xx) are NOT cached, so the next render retries instead of pinning null.
    if ($code === 200) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode(['v' => $result])) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $cacheFile);
        }
    }
    return $result;
}
}

// ---------- can-post signal: WP login cookie presence (NOT whoami) -----------
//
// Ian's standing rule: POSTING ABILITY gates on the WP login session, never on
// /whoami. /whoami returns anon for a logged-in member whose JWT-uuid doesn't
// resolve to a profile-app identity (unbridged / minter-decision-2), so a real
// admin or post-snapshot member would otherwise be told to "sign in" while the
// header shows them signed in (header reads the JWT directly — the two diverge).
//
// This runs in the bb-mirror FPM pool, which never boots WP, so is_user_logged_in()
// isn't available here — we read the wordpress_logged_in_* cookie's PRESENCE, the
// same signal bb_mirror_chrome_header() already uses for the display-name fallback.
// It is a UX gate only: the real lock is the BB-REST nonce + server-side caps
// re-check on /bb-mirror-api/v0/reply (and auth.php mints the nonce off
// get_current_user_id() on the WP pool). True anon (no cookie) still fails closed.
if (!function_exists('lg_bb_mirror_wp_logged_in')) {
function lg_bb_mirror_wp_logged_in(): bool {
    foreach ($_COOKIE as $k => $_) {
        if (strpos($k, 'wordpress_logged_in_') === 0) return true;
    }
    return false;
}
}
// ---------- anonymous-posting: viewer-moderator check + author mask ----------
//
// The per-post "Post anonymously" feature (anon-rebuild lane). A topic/reply
// carrying is_anon renders as "Anonymous" + generic avatar to everyone EXCEPT
// admins/mods, who see the real author + a "(posted anonymously)" marker.
//
// Reveal authz is server-enforced (contract: admin/mod only). We read the SAME
// capability set the tier-gate bypass uses (lg_bb_mirror_whoami caps), so a
// plain member can't self-elevate. moderate_comments is the canonical mod cap
// (matches the comment-delete authz pattern); the others are admin/editor.
if (!function_exists('lg_bb_mirror_can_moderate')) {
function lg_bb_mirror_can_moderate(): bool {
    static $can = null;
    if ($can !== null) return $can;
    $can  = false;
    $wa   = function_exists('lg_bb_mirror_whoami') ? lg_bb_mirror_whoami() : null;
    $caps = is_array($wa) ? (array)($wa['capabilities'] ?? []) : [];
    foreach (['moderate_comments', 'manage_options', 'administrator',
              'edit_others_posts', 'activate_plugins'] as $c) {
        if (!empty($caps[$c]) || in_array($c, $caps, true)) { $can = true; break; }
    }
    return $can;
}
}

// Leak-safe anon mask. Mutates an author-bearing row IN PLACE before render:
//   • non-moderator + is_anon  → identity ABSENT: name→"Anonymous", slug/avatar/
//     author_id nulled so no /u/ link, no real avatar, no profile resolution.
//     (Same discipline as gated teasers — suppressed server-side, not CSS-hidden.)
//   • moderator + is_anon      → identity KEPT, $row['_anon_revealed']=true so the
//     renderer can append the "(posted anonymously)" marker.
// Recognizes the standard author columns; absent keys are skipped. is_anon is
// selected as ::int ('1'/'0') so the truthy check is reliable across PDO casts.
// Returns true when the row was anonymous (either masked or revealed).
if (!function_exists('lg_bb_mirror_mask_anon')) {
function lg_bb_mirror_mask_anon(array &$row, bool $can_mod): bool {
    $v = $row['is_anon'] ?? null;
    $is_anon = ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true');
    if (!$is_anon) return false;
    if ($can_mod) {
        $row['_anon_revealed'] = true;   // keep real identity; renderer shows marker
        return true;
    }
    $row['author_name'] = 'Anonymous';
    if (array_key_exists('author_slug', $row)) $row['author_slug'] = null;
    if (array_key_exists('avatar_url',  $row)) $row['avatar_url']  = null;
    if (array_key_exists('author_id',   $row)) $row['author_id']   = null;
    $row['_anon_masked'] = true;
    return true;
}
}


// Leak-safe DISCUSSION-AUTHOR visibility mask (discussion_visibility briefing 6/7).
// Mutates a discussion (forum) author row IN PLACE before identity resolution.
// The author's profile preference discussion_visibility ('public'|'member', DB
// default 'member') x the viewer's login state:
//   - viewer LOGGED-IN          -> no-op, returns false FIRST. Members always see
//     the real author; the logged-in path never reads the column (zero added cost,
//     per the perf rule -- masking is logged-out-only).
//   - logged-out + 'member'      -> identity ABSENT: name->"Private member", slug/
//     avatar/author_id/user_uuid nulled, so no /u/ link, no avatar URL, no profile
//     resolution. Same discipline as gated teasers -- server-side, never CSS-hidden.
//   - logged-out + 'public'      -> real author (returns false).
// Value is the singular 'member' (load-bearing). Callers SELECT
// COALESCE(p.discussion_visibility,'member') so a NULL (no person row yet) defaults
// to masked. Discussion authors ONLY -- callers guard on card_type so CPT authors
// are unaffected. Returns true when the row was masked.
if (!function_exists('lg_bb_mirror_mask_visibility')) {
function lg_bb_mirror_mask_visibility(array &$row, bool $viewer_logged_in): bool {
    if ($viewer_logged_in) return false;                          // logged-in: never read it
    if (($row['discussion_visibility'] ?? null) !== 'member') return false;
    $row['author_name'] = 'Private member';
    if (array_key_exists('author_slug', $row)) $row['author_slug'] = null;
    if (array_key_exists('avatar_url',  $row)) $row['avatar_url']  = null;
    if (array_key_exists('author_id',   $row)) $row['author_id']   = null;
    if (array_key_exists('user_uuid',   $row)) $row['user_uuid']   = null;
    $row['_visibility_masked'] = true;
    return true;
}
}

// ---------- pagination ----------
if (!defined('LG_BB_MIRROR_PER_PAGE')) define('LG_BB_MIRROR_PER_PAGE', 15);

if (!function_exists('bb_mirror_page')) {
function bb_mirror_page(): int {
    $p = (int)($_GET['page'] ?? 1);
    return $p < 1 ? 1 : $p;
}
}

// ---------- avatar fallback (non-gated default) ----------
// get_avatar_url()/whoami return gravatar URLs whose d= fallback points at a
// dev-gated BuddyBoss bp-full image gravatar can't fetch -> broken avatar for
// users without a gravatar. Force a non-gated default. Swap the const to a
// gate-exempt local asset later for branding (one line).
if (!defined('LG_BB_MIRROR_DEFAULT_AVATAR')) define('LG_BB_MIRROR_DEFAULT_AVATAR', 'mp');
if (!function_exists('lg_bb_mirror_safe_avatar')) {
function lg_bb_mirror_safe_avatar(?string $url): ?string {
    if (!$url) return $url;
    if (!preg_match('~^https?://[^/]*gravatar\\.com/~i', $url)) return $url;
    $p = parse_url($url);
    parse_str($p['query'] ?? '', $q);
    $q['d'] = LG_BB_MIRROR_DEFAULT_AVATAR;   // overrides the gated bp-full d=
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'gravatar.com')
         . ($p['path'] ?? '') . '?' . http_build_query($q);
}
}

// ---------- reply media cap ----------
// Max images on a single reply (Ian 2026-07-27, keeper-relayed: "MAX 6 IMAGES
// PER REPLY"). ONE constant so the renderer and the write endpoint cannot drift:
// `web/forums/_topic-replies.php` slices the gallery to it, and `api/v0/reply.php`
// rejects past it on both create and edit. A client-only cap is not a cap.
//
// History worth keeping: before this, nothing capped reply images ANYWHERE on the
// way in — the effective limit was a `LIMIT 1` in the render, so 229 replies had
// been quietly holding 2-6 images that nobody had ever seen. See
// docs/atlas/REPLY-IMAGE-COUNT-CEILING.md.
if (!defined('LG_REPLY_IMG_MAX')) define('LG_REPLY_IMG_MAX', 6);

// ---------- LG_FOLLOW_CADENCE_LIVE — the account-level email cadence ------------
// DEFAULT OFF, and the name states the CONDITION: it turns on when follow-digest's
// batcher genuinely delivers Daily and Weekly, not when the control is merely built.
// Ian, 2026-07-31, asked the sequencing question and answered it himself: show the
// frequency control only when the batcher lands, so nobody picks Daily and receives
// instant mail. The JS twin is FREQ_BATCHER_LIVE (forums.js) — same condition, and the
// pair must move together.
//
// SEAM (docs/atlas/FOLLOW-DIGEST-DESIGN.md §2.2-2.3, agreed on the board 2026-07-31):
//   store  WP usermeta lg_disc_email_cadence ∈ instant|daily|weekly, absent ⇒ instant
//   write  THIS lane, through follow.php, self-scoped from the session
//   read   follow-digest's sender
// While this is false, `cadence` is ABSENT from the GET envelope entirely — not null,
// not a default — so a dark control cannot render a live-looking value, and the
// absence is gateable. follow-digest gates it from their side too.
//
// NEITHER LANE FLIPS THIS ALONE: follow-digest reports a working sender, keeper takes
// it to Ian, and both flags move in that window.
if (!defined('LG_FOLLOW_CADENCE_LIVE')) {
    define('LG_FOLLOW_CADENCE_LIVE',
        getenv('LG_BB_MIRROR_CADENCE') === '1'
        || (($_SERVER['LG_BB_MIRROR_CADENCE'] ?? '') === '1'));
}

// ---------- LG_THREAD_FOLLOW_ENABLED — the thread-follow exposure gate ----------
// DEFAULT OFF, and it stays off until Ian turns it on himself on live, having looked
// at the running thing. Pattern copied from LG_AUTHOR_SOCIALS_ALL_MEMBERS
// (platform/mu-plugins/lg-author-socials.php:48).
//
// WHY IT EXISTS: the follow work merged to main before Ian had clicked it, and it then
// BLOCKED A ONE-MERGE BYLINE FIX from deploying — a member-facing feature he had not
// approved was sitting in front of an unrelated change he needed shipped. A flag is
// what lets work merge before approval instead of holding the queue behind it.
//
// SCOPE: every FOLLOW AFFORDANCE — the 🔔 bell, the ✉ envelope, the consolidated pill
// and its settings modal — on every surface: hub feed cards, the standalone topic page,
// the desktop reader modal and the mobile sheet. OFF must mean a member sees exactly
// what they saw before this lane existed.
//
// DELIBERATELY NOT IN SCOPE: the two live migrations and the two mu-plugins. They are
// inert without the UI — no control means nothing writes forums.topic_follow and
// nothing raises a forum.followed_topic notification — so gating them would only make
// the deploy more fragile, not less.
//
// ONE READ POINT: lg_thread_follow_enabled() in web/forums/_reply-render.php. Nothing
// else may test this constant; if you need it somewhere new, call that function.
// Override without editing the repo: LG_BB_MIRROR_FOLLOW=1 in the pool environment.
// Two sources, one meaning. getenv() is how a pool or a CLI harness turns it on;
// $_SERVER is how a SINGLE nginx location does — tools/preview/lane-preview.sh gives
// a branch a URL by setting fastcgi_param, and a fastcgi_param lands in $_SERVER but
// not reliably in the process environment. Reading both is what lets the lane preview
// run the feature ON for Ian while it stays OFF everywhere else on the same vhost.
// A fastcgi_param can only be set by an nginx conf, never by a query string, so this
// is not a way for a visitor to switch the feature on.
if (!defined('LG_THREAD_FOLLOW_ENABLED')) {
    define('LG_THREAD_FOLLOW_ENABLED',
        getenv('LG_BB_MIRROR_FOLLOW') === '1'
        || (($_SERVER['LG_BB_MIRROR_FOLLOW'] ?? '') === '1'));
}

// ---------- LG_NOTIF_QUICKREPLY_ENABLED — the tap-to-reply exposure gate ----------
// DEFAULT OFF. Ian picked layout A on 2026-07-30 (quote their reply + composer +
// full-post link; NOT the variant that also renders the member's own post), and it
// still merges dark: it reaches the dev2 serve harmlessly, gets verified there on the
// running thing, and only then does he turn it on. Pattern copied from
// LG_AUTHOR_SOCIALS_ALL_MEMBERS (platform/mu-plugins/lg-author-socials.php:48).
//
// WHY THE DEFAULT MATTERS MORE THAN USUAL HERE: live pulls all of main, so one
// unflagged half-finished member-facing feature blocks every other deploy behind it.
// A 190-commit queue formed that way on 7/30.
//
// ONE FLAG, ONE READ POINT PER LAYER, greppable:
//   bb-mirror/config.php    this define, override LG_BB_MIRROR_NOTIF_QUICKREPLY=1
//                           via EITHER the pool environment OR a fastcgi_param
//   api/v0/topic.php        lg_notif_quickreply_enabled() — gates the ?reply_context=
//                           read, so OFF means the branch is UNREACHABLE, not merely
//                           uncalled. A read path that only nothing-happens-to-call is
//                           not off; this one 404s.
//   web/_chrome.php         body[data-lg-notifreply] — the seam into the client,
//                           because the modal is built in JS and a server gate cannot
//                           reach it
//   webroot/pwa.js          reads that attribute and does not even REQUEST
//                           notif-reply.js when it is 0 — flag OFF ships no bytes
//   webroot/notif-reply.js  lgNqrEnabled() — the only read in that file
//   bottom-nav.js / social-modals.js  both fall back to today's navigation when the
//                           modal is absent, which is also what happens off /hub
// The client reads default to OFF when the attribute is absent, so a stale cached
// shell degrades to "feature off" rather than to a half-wired feature.
// TWO SOURCES, ONE MEANING — and the second one is not optional here. getenv() is how
// a pool or a CLI harness turns this on. $_SERVER is how a SINGLE nginx location does:
// platform/nginx/lane-preview-notif-quickreply.conf hands Ian a URL by setting
// `fastcgi_param LG_BB_MIRROR_NOTIF_QUICKREPLY 1`, and a fastcgi_param lands in
// $_SERVER, not reliably in the process environment.
//
// THIS WAS A REAL BUG, caught rebasing onto thread-follow's merged flag on 2026-07-31:
// this define read getenv() ALONE while the preview conf fed it a fastcgi_param, so the
// preview URL — the one artifact whose entire purpose is to let Ian click the real
// control — would have served the flag OFF. A page that looks right and does nothing,
// which is exactly the failure I flagged on the board and then shipped anyway.
// A fastcgi_param can only be set by an nginx conf, never by a query string, so this is
// not a way for a visitor to switch the feature on.
if (!defined('LG_NOTIF_QUICKREPLY_ENABLED')) {
    define('LG_NOTIF_QUICKREPLY_ENABLED',
        getenv('LG_BB_MIRROR_NOTIF_QUICKREPLY') === '1'
        || (($_SERVER['LG_BB_MIRROR_NOTIF_QUICKREPLY'] ?? '') === '1'));
}
if (!function_exists('lg_notif_quickreply_enabled')) {
    /** The ONE read of the tap-to-reply gate. Filterable for a staged rollout. */
    function lg_notif_quickreply_enabled(): bool {
        return (bool) LG_NOTIF_QUICKREPLY_ENABLED;
    }
}
