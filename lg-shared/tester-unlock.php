<?php
/**
 * tester-unlock.php — THE ANON TESTER'S UNLOCK LINK. Issue #180.
 *
 * Ian, 2026-08-21, verbatim:
 *
 *     "dev2 join goes to stripe rather than patreon with a fresh incognito. I
 *      need it to go to patreon unless the user has some kind of token url or
 *      something to unlock the whitelisted pages."
 *
 * ── THE GAP THIS CLOSES ──────────────────────────────────────────────────────
 * #170 gave the header three audiences, and 'allowlist' — the state both boxes
 * run during the soft launch — recognises a tester by a per-viewer CAPABILITY
 * ($caps['stripe_testgroup']). An anonymous browser carries no capabilities and
 * fails closed, so an anonymous tester is indistinguishable from the public and
 * gets patreon.com. That is the caching law working exactly as designed, and it
 * is also the whole gap: there was no way to hand one anonymous browser the
 * Stripe door without handing it to everybody.
 *
 * This is that way. ONE shareable URL marks ONE browser with a cookie; the
 * header's Join then points at /lgjoin/ for that browser only, and the join-flow
 * door admits it. Everyone else keeps seeing patreon.com, byte for byte.
 *
 * ── WHAT IT DOES *NOT* DO, AND WHY THAT IS THE POINT ─────────────────────────
 * It changes PAGE VISIBILITY ONLY. It touches no checkout path and no signup
 * path, so it cannot widen a purchase surface that is already open. That is not
 * a promise this file makes politely — it is the reason the feature is safe, and
 * it is why Ian's "no one can sign up unless they are white listed" posture
 * survives a forwarded link.
 *
 * ⚠️ THE REFUSAL IT RELIES ON IS NOT A WHITELIST. Measured on both boxes
 * 2026-08-21 while designing this: NOTHING in the signup or checkout path
 * consults the cohort list. What actually stops a marked anonymous browser
 * today is that lgjoin's own JS requires POST /wp-json/lg-member-sync/v1/auth
 * to return ok before it calls checkout, and that route answers an anonymous
 * caller with 401 bb_rest_authorization_required — BuddyBoss's global
 * `bb-enable-private-rest-apis`, re-armed by every DB reload. So the funnel
 * dead-ends at "Sign-in failed" for a token holder, which IS the safety net in
 * practice and IS what a tester will hit. Gate 85 measures that browser-path
 * refusal as it actually is; it does not assert that a whitelist exists,
 * because one does not. The API-level gap is filed as its own go-live issue and
 * is deliberately NOT addressed here.
 *
 * ── SHAPE: THE DEV COOKIE GATE'S, NOT A NEW VOCABULARY ───────────────────────
 * The box already has this pattern — /claim?t=… → loothdev_auth, and
 * /claim-tester → loothdev_tester with a ?next= bounce. This reuses it rather
 * than inventing a second one:
 *
 *   claim   /lgjoin/?lgtester=<token>  → set cookie, 302 to the clean /lgjoin/
 *   clear   /lgjoin/?lgtester=off      → delete cookie, 302 to the clean path
 *
 * The 302 is not cosmetic. It takes the token out of the address bar, out of
 * browser history and out of every onward Referer header, and it means the very
 * next request PROVES the cookie works rather than the parameter working.
 *
 * ── THE STORE HOLDS A HASH, NEVER THE TOKEN ──────────────────────────────────
 * The config carries sha256(token); the cookie carries the token; they are
 * compared with hash_equals. Reading the config hands nobody a working link —
 * the same instinct as _invites.php and the Stripe broker: a store that can be
 * read should not be a store that can be used. The raw token exists only in the
 * URL Ian pastes, and gate 85 asserts it appears nowhere in the repo.
 *
 * ── NO EXPIRY, NO EMAIL MATCH, NO PASSWORD (Ian ruled the simple shape) ──────
 * A forwarded link getting in is ACCEPTABLE, because viewing is not signing up.
 * The controls that exist instead are operational and instant:
 *
 *   rotate   change token_sha256 → every existing cookie stops matching
 *   off      'enabled' => false, or an empty hash → every cookie dies at once
 *   clear    the visitor's own ?lgtester=off
 *
 * Two independent kill switches on purpose: a config that is enabled but has no
 * hash can never match anything, so a half-placed file fails CLOSED.
 *
 * ── WHERE IT IS HONOURED: allowlist ONLY, and that keeps #170's law intact ───
 * 'off' still means NOBODY — an unlock cookie changes nothing there. 'on'
 * already means everybody, so the cookie is redundant. The unlock exists to
 * WIDEN 'allowlist' from "the cohort, signed in" to "the cohort signed in, OR a
 * browser holding a live unlock token". dev2 already runs 'allowlist' and
 * keeper's box-local note says live runs it during the test phase, so both
 * boxes are already in the state this needs — one switch to arm, not two.
 *
 * ── THE DOOR NEEDS NO SECOND WP OPTION, DELIBERATELY ─────────────────────────
 * #165 and #170 were both bitten by the same coupling: a Join button wired
 * perfectly that lands on "This page isn't available yet", because /lgjoin/
 * picks its audience from a DIFFERENT switch. This avoids it structurally by
 * admitting the marked browser inside lg_membership_testgroup_gate_or_exit —
 * the ONE gate both doors delegate to, and exactly where invites already plug
 * in. So arming the unlock opens the button AND the page together, and
 * lgms_stripe_testgroup_pages does not enter into it.
 *
 * ── THE MICROCACHE COUPLING (declared; see platform/nginx/lg-microcache.conf) ─
 * /hub/ microcaches anonymous HTML for 60s. A marked browser is still anonymous,
 * so without the cookie in the $lg_anon_nocache map it would be served a cached
 * page whose Join still points at patreon.com — the feature silently not working
 * on the one surface Ian is most likely to look at. The cookie is in that map.
 * While the flag is off nothing ever sets the cookie, so that line is inert.
 *
 * Emits nothing, defines only functions, and has no closing tag: this is
 * required from lg-shared/site-header.php, which renders on every page of seven
 * independent apps.
 */

declare(strict_types=1);

/** The browser mark. Host-only, HttpOnly, Secure, SameSite=Lax. */
const LG_TESTER_UNLOCK_COOKIE = 'lg_join_unlock';

/** The URL parameter that claims or clears it. */
const LG_TESTER_UNLOCK_PARAM = 'lgtester';

/** Words that CLEAR rather than claim. A visitor can always un-mark themselves. */
const LG_TESTER_UNLOCK_CLEAR_WORDS = ['off', 'clear', 'no', '0'];

/**
 * The join flow and nothing else — _invites.php's fence 1, for its reason: a
 * token that opened manage-subscription or request-refund would be a pre-launch
 * bypass wearing an unlock's costume, and an unlocked browser has no
 * subscription to manage.
 */
const LG_TESTER_UNLOCK_SCOPE = ['join', 'lgjoin', 'regional-pricing-not-available', 'welcome'];

/**
 * The config, resolved tracked → box-local → env, fail-CLOSED at every step.
 *
 * Returns ['enabled' => bool, 'hash' => string]. 'enabled' true with an empty
 * hash is a DEAD config, not a permissive one — see lg_tester_unlock_armed().
 *
 * __DIR__ resolves through the /srv/lg-shared symlink into the serving checkout
 * (PHP resolves symlinks for __FILE__/__DIR__), so this lands on the tracked
 * file — the same resolution site-header.php relies on for its own flag.
 */
if (!function_exists('lg_tester_unlock_config')) {
function lg_tester_unlock_config(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $enabled = false;
    $hash    = '';

    /* One config array → applied, or ignored entirely when it says nothing.
       Defensive about every shape a wrong config can take — empty, non-array,
       missing key, returning nothing, unreadable — because this partial renders
       on every page of every surface. The ONE shape it cannot defend against is
       a PHP SYNTAX ERROR: `@` suppresses warnings, not parse errors. That is the
       house pattern's property, not this flag's defect, and the mitigation is
       operational and takes two seconds:

           php -l platform/config/tester-unlock.local.php

       Do it before you place the file. A typo here is a site-wide 500, not one
       quiet feature. */
    $apply = static function ($cfg) use (&$enabled, &$hash): void {
        if (!is_array($cfg)) { return; }
        if (array_key_exists('enabled', $cfg)) { $enabled = ($cfg['enabled'] === true); }
        if (array_key_exists('token_sha256', $cfg)) {
            $h = is_string($cfg['token_sha256']) ? strtolower(trim($cfg['token_sha256'])) : '';
            /* A malformed hash is an empty hash, never a guess: anything that is
               not exactly 64 hex characters cannot be a sha256 and must fail to
               "nothing matches" rather than to some looser comparison. */
            $hash = preg_match('/^[a-f0-9]{64}$/', $h) === 1 ? $h : '';
        }
    };

    $apply(@include __DIR__ . '/../platform/config/tester-unlock.php');
    /* Per-box override, gitignored (platform/config/*.local.php). This is the
       DEPLOY MECHANISM — dev2's FPM pool files are symlinks into the serving
       checkout, so an env[] flip would dirty a tracked file in the one checkout
       that must only ever pull. */
    $apply(@include __DIR__ . '/../platform/config/tester-unlock.local.php');

    /* Previews and gate red-first legs ONLY — never a deploy mechanism. Read
       from getenv() AND $_SERVER, deliberately: a fastcgi_param lands in
       $_SERVER but not reliably in the environment, so a getenv()-only reader
       serves the OFF path on the very preview URL built for someone to click. */
    foreach ([getenv('LG_TESTER_UNLOCK'), $_SERVER['LG_TESTER_UNLOCK'] ?? false] as $o) {
        if ($o === false || $o === '') { continue; }
        $o = strtolower(trim((string) $o));
        $enabled = ($o === '1' || $o === 'true' || $o === 'on');
    }
    foreach ([getenv('LG_TESTER_UNLOCK_SHA256'), $_SERVER['LG_TESTER_UNLOCK_SHA256'] ?? false] as $o) {
        if ($o === false || $o === '') { continue; }
        $o = strtolower(trim((string) $o));
        $hash = preg_match('/^[a-f0-9]{64}$/', $o) === 1 ? $o : '';
    }

    return $cache = ['enabled' => $enabled, 'hash' => $hash];
}
}

/**
 * Is the unlock ARMED on this box? Enabled AND holding a usable hash.
 *
 * Both halves are required so that a config which is half-placed — enabled with
 * no token yet, or a token left behind after being disabled — is dead rather
 * than ambiguous. Nothing downstream needs to remember to check both.
 */
if (!function_exists('lg_tester_unlock_armed')) {
function lg_tester_unlock_armed(): bool
{
    $cfg = lg_tester_unlock_config();
    return $cfg['enabled'] === true && $cfg['hash'] !== '';
}
}

/**
 * Does the raw token this request presents match the armed one?
 *
 * hash_equals, not ===, because this compares a secret-derived value against
 * attacker-supplied input on a public endpoint. Returns false before reading
 * anything when the unlock is not armed.
 */
if (!function_exists('lg_tester_unlock_token_matches')) {
function lg_tester_unlock_token_matches(string $token): bool
{
    if (!lg_tester_unlock_armed()) { return false; }
    if ($token === '') { return false; }
    $cfg = lg_tester_unlock_config();
    return hash_equals($cfg['hash'], hash('sha256', $token));
}
}

/**
 * IS THIS BROWSER MARKED? The one question the header and the door both ask.
 *
 * Reads the cookie only. The claim parameter is deliberately NOT honoured here:
 * a claim sets the cookie and redirects, so by the time anything asks this
 * question the answer comes from the cookie or not at all. That keeps "marked"
 * a property of the BROWSER rather than of one URL, which is what makes the
 * mark survive navigation to the other six apps.
 */
if (!function_exists('lg_tester_unlock_marked')) {
function lg_tester_unlock_marked(): bool
{
    if (!lg_tester_unlock_armed()) { return false; }   // not armed = today's site, everywhere
    return lg_tester_unlock_token_matches((string) ($_COOKIE[LG_TESTER_UNLOCK_COOKIE] ?? ''));
}
}

/**
 * The requested slug, resolved EXACTLY as router.php and _invites.php resolve
 * it — same source, same fallback. Not "similarly": a third way of answering
 * "which page is this" is how two doors drift apart.
 */
if (!function_exists('lg_tester_unlock_slug')) {
function lg_tester_unlock_slug(): string
{
    $slug = (string) ($_SERVER['LG_MS_SLUG'] ?? '');
    if ($slug === '') {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        $slug = trim($path, '/');
        if (str_contains($slug, '/')) { $slug = explode('/', $slug)[0]; }
    }
    return $slug;
}
}

/**
 * The current path with the claim parameter stripped — where a claim lands.
 *
 * Built from REQUEST_URI's PATH and its OTHER query parameters, never from
 * anything the visitor can point elsewhere: this is a redirect target on a
 * public endpoint, so it is host-relative by construction and cannot be turned
 * into an off-site bounce.
 */
if (!function_exists('lg_tester_unlock_clean_target')) {
function lg_tester_unlock_clean_target(): string
{
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    if ($path === '' || $path[0] !== '/') { $path = '/' . $path; }

    $query = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
    $keep  = [];
    if ($query !== '') {
        parse_str($query, $parsed);
        unset($parsed[LG_TESTER_UNLOCK_PARAM]);
        $keep = $parsed;
    }
    $qs = $keep === [] ? '' : ('?' . http_build_query($keep));

    return $path . $qs;
}
}

/**
 * CLAIM or CLEAR, then redirect. Call EARLY — before a byte of output.
 *
 * A no-op when the parameter is absent, which is every request but the one that
 * carries the link. Safe to call from more than one door for that reason, and it
 * IS called from more than one:
 *
 *   - router.php, before the visibility decision (the routed path, how /lgjoin/
 *     is actually served), and
 *   - lg_membership_testgroup_gate_or_exit(), BEFORE its administrator
 *     early-return.
 *
 * That second call site is not belt-and-braces, it is the fix for a specific
 * trap: an administrator returns early from every gate, so a claim handled only
 * inside the gates would silently do nothing for the one person most likely to
 * click the link to check it works. Ian is an administrator.
 *
 * A WRONG token is treated exactly like no token: it strips the parameter and
 * redirects with no cookie set and nothing said. Nothing here tells a prober
 * whether a token exists or is merely wrong.
 */
if (!function_exists('lg_tester_unlock_handle_claim')) {
function lg_tester_unlock_handle_claim(): void
{
    if (!array_key_exists(LG_TESTER_UNLOCK_PARAM, $_GET)) { return; }   // the common case
    if (headers_sent()) { return; }                                     // cannot act; never fatal

    $raw = $_GET[LG_TESTER_UNLOCK_PARAM];
    $val = is_string($raw) ? trim($raw) : '';

    /* CLEARING NEEDS NO TOKEN AND NO ARMED FLAG. A visitor must always be able
       to un-mark their own browser — including after the token has been rotated
       out from under them, when they could not present a matching one even if
       they wanted to. */
    if (in_array(strtolower($val), LG_TESTER_UNLOCK_CLEAR_WORDS, true)) {
        lg_tester_unlock_write_cookie('', true);
        lg_tester_unlock_redirect(lg_tester_unlock_clean_target());
    }

    /* Fence 1: the join flow only. Checked BEFORE the token so that presenting a
       perfectly good token at manage-subscription is as inert as presenting a
       bad one. */
    if (!in_array(lg_tester_unlock_slug(), LG_TESTER_UNLOCK_SCOPE, true)) { return; }

    if (lg_tester_unlock_token_matches($val)) {
        lg_tester_unlock_write_cookie($val, false);
    }

    /* Matched or not, the parameter comes off the URL. A visitor who mistypes
       the token gets the ordinary page for an unmarked browser, and the failed
       token does not linger in history or in a Referer. */
    lg_tester_unlock_redirect(lg_tester_unlock_clean_target());
}
}

/** Set or delete the mark. HttpOnly so no script on any of the seven apps can read it. */
if (!function_exists('lg_tester_unlock_write_cookie')) {
function lg_tester_unlock_write_cookie(string $value, bool $delete): void
{
    setcookie(LG_TESTER_UNLOCK_COOKIE, $delete ? '' : $value, [
        'expires'  => $delete ? 1 : (time() + 31536000),   // 1s past the epoch = delete; else 1 year
        'path'     => '/',
        /* No 'domain': HOST-ONLY on purpose. A dotted cookie would be a second
           copy alongside any host-only one, and the shared chrome profile has
           already produced exactly that duplicate-cookie confusion once. */
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',   // Lax, not Strict: the link is pasted/followed from elsewhere
    ]);
}
}

/** 302 to a host-relative target and stop. Never emits a body. */
if (!function_exists('lg_tester_unlock_redirect')) {
function lg_tester_unlock_redirect(string $target): void
{
    if ($target === '' || $target[0] !== '/') { $target = '/'; }
    /* Host-RELATIVE, so it stays on the requesting host even if siteurl drifts —
       the same reason looth-auth-issue.php emits a relative bounce. */
    header('Location: ' . $target, true, 302);
    header('Cache-Control: no-store, private');   // a Set-Cookie response must never be cached
    exit;
}
}
