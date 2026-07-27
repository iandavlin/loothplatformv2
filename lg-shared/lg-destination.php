<?php
/**
 * /srv/lg-shared/lg-destination.php
 *
 * ONE helper every door into the site calls.
 *
 * The sign-in modal makes a promise out loud — "you'll come straight back to
 * this discussion." Before this file every door invented its own answer: the
 * password form honored redirect_to, the Patreon button carried nothing, and
 * the chrome "Sign in" on every page — the most-used door on the site — carried
 * nothing at all, so everyone landed on /activity/. This is the single place
 * that captures an intended destination, validates it, and builds the href.
 *
 *   require_once '/srv/lg-shared/lg-destination.php';
 *   $login = lg_dest_login_url(lg_dest_here());       // "bring me back here"
 *   $login = lg_dest_login_url('/hub/gear/thread/');  // an explicit destination
 *
 * ── THIS FILE MUST STAY WORDPRESS-FREE ──────────────────────────────────────
 * profile-app, membership-pages, bb-mirror and archive-poc all render the
 * shared header with no WordPress loaded. ONE wp_* call in here fatals four
 * standalone surfaces. Real host validation (wp_validate_redirect against the
 * site's own home_url) lives in the WP adapter,
 * platform/mu-plugins/lg-login-destination.php, which wraps these functions.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * POSTURE (Ian 2026-07-27, ruling 1): never trust the destination. Same-host,
 * path-only, EMPTY fallback. When a value is hostile or unparseable the door
 * keeps its own default and the user still gets in — a rejected destination is
 * never a rejected login.
 *
 * The JS twin is lg-shared/lg-destination.js (window.lgDest), same rules, for
 * the doors that are built client-side. Keep the two in step; the hostile-value
 * table in tools/gates/dest-capture-test.php covers both.
 *
 * Guard: require_once safe (function_exists check on each function).
 */

declare(strict_types=1);

/**
 * Longest destination we will bind. A path this long is either a mistake or an
 * attempt to blow up a downstream buffer; either way the door's own default is
 * a better answer than binding it.
 */
if (!defined('LG_DEST_MAX_LEN')) {
    define('LG_DEST_MAX_LEN', 512);
}

if (!function_exists('lg_dest_auth_paths')) {
/**
 * Paths we must NEVER land someone on after authenticating — that is the
 * infinite loop (ruling 3). Prefix-matched, so /wp-login.php?action=logout and
 * /patreon-password/ are covered by their bare entries.
 *
 * @return string[]
 */
function lg_dest_auth_paths(): array
{
    return ['/wp-login.php', '/patreon-connect', '/patreon-password'];
}
}

if (!function_exists('lg_dest_same_host')) {
/**
 * Is $host the host serving THIS request?
 *
 * HTTP_HOST is client-supplied, but nginx only routes a request here when the
 * Host matches a server_name, so by the time we run it is one of ours. This is
 * the WP-free floor; the WP adapter re-checks with wp_validate_redirect(), and
 * the value is reduced to a path anyway — so a wrong answer here costs a
 * rejected destination, never an off-host redirect.
 */
function lg_dest_same_host(string $host): bool
{
    $self = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($self === '') {
        return false;
    }
    $bare = static function (string $h): string {
        $h = strtolower(trim($h));
        if ($h === '') {
            return '';
        }
        // IPv6 literal: [::1]:443 — the colons inside the brackets aren't a port.
        if ($h[0] === '[') {
            $end = strpos($h, ']');
            return $end === false ? $h : substr($h, 0, $end + 1);
        }
        $colon = strrpos($h, ':');
        return $colon === false ? $h : substr($h, 0, $colon);
    };
    $a = $bare($host);
    return $a !== '' && $a === $bare($self);
}
}

if (!function_exists('lg_dest_is_auth_path')) {
/**
 * Would landing here bounce the user straight back into authentication?
 */
function lg_dest_is_auth_path(string $candidate): bool
{
    $q    = strpos($candidate, '?');
    $path = $q === false ? $candidate : substr($candidate, 0, $q);
    $path = rtrim(strtolower($path), '/');
    if ($path === '') {
        return false;
    }
    foreach (lg_dest_auth_paths() as $auth) {
        $a = rtrim(strtolower($auth), '/');
        if ($path === $a || strncmp($path, $a . '/', strlen($a) + 1) === 0) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('lg_dest_capture')) {
/**
 * Reduce a candidate destination to a path we are willing to bind, or '' when
 * there is nothing trustworthy to bind.
 *
 * Accepts:
 *   - an absolute path, query preserved:  /hub/?topic=x&y=2
 *   - an absolute SAME-HOST http(s) URL, reduced to path+query
 *
 * Rejects (each one returns '' so the caller keeps its own default):
 *   - empty / non-string / longer than LG_DEST_MAX_LEN
 *   - anything not starting with '/' that isn't a same-host http(s) URL —
 *     this is what kills javascript:, data:, mailto: and off-host absolutes
 *   - scheme-relative //evil.example (an off-host URL wearing a path's clothes)
 *   - ANY raw backslash: browsers fold '\' to '/', so /\evil.example is
 *     scheme-relative too, and no legitimate path on this site carries one raw
 *   - any control character or newline (header-injection / log-splitting)
 *   - userinfo in the authority (https://ourhost@evil.example/)
 *   - the auth paths — landing there is the infinite loop (ruling 3)
 *
 * Fragments are dropped: the server never sees them, so binding one is a lie.
 *
 * @param mixed $raw
 */
function lg_dest_capture($raw): string
{
    if (!is_string($raw) || $raw === '' || strlen($raw) > LG_DEST_MAX_LEN) {
        return '';
    }

    // Control chars, newlines and raw backslashes are checked on the RAW value,
    // before any trimming or parsing — "\n/hub/" must not become "/hub/".
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $raw) === 1) {
        return '';
    }

    if ($raw[0] === '/') {
        // Scheme-relative: //evil.example is not a path, it is a host.
        if (strncmp($raw, '//', 2) === 0) {
            return '';
        }
        $parts = parse_url($raw);
        // A path-only value must not have somehow produced a scheme or host.
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            return '';
        }
    } else {
        $parts = parse_url($raw);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        // https://ourhost.example@evil.example/ parses with host=evil.example,
        // but reject userinfo outright rather than trusting that every parser
        // downstream agrees with this one.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        if (!lg_dest_same_host((string) $parts['host'])) {
            return '';
        }
    }

    $path  = (string) ($parts['path'] ?? '');
    $query = (string) ($parts['query'] ?? '');
    $candidate = $path . ($query !== '' ? '?' . $query : '');

    if ($candidate === '' || $candidate[0] !== '/' || strncmp($candidate, '//', 2) === 0) {
        return '';
    }
    if (strlen($candidate) > LG_DEST_MAX_LEN) {
        return '';
    }
    if (lg_dest_is_auth_path($candidate)) {
        return '';
    }

    return $candidate;
}
}

if (!function_exists('lg_dest_here')) {
/**
 * The current request as a capturable path+query — "bring me back HERE".
 * Returns '' when the current URL is itself unbindable (e.g. we're already on
 * an auth page), which makes lg_dest_login_url() fall back to a bare login.
 */
function lg_dest_here(): string
{
    return lg_dest_capture((string) ($_SERVER['REQUEST_URI'] ?? ''));
}
}

if (!function_exists('lg_dest_login_url')) {
/**
 * Build a sign-in href.
 *
 * With no destination — or a hostile one — this returns $base unchanged, BYTE
 * IDENTICAL to what every door emitted before this lane (ruling 5: a bare login
 * is untouched, BuddyBoss's /activity/ default keeps winning).
 *
 * rawurlencode, never add_query_arg: add_query_arg does NOT encode its values,
 * so a destination carrying its own query (/hub/?topic=x&y=2) would be split
 * into sibling params and arrive truncated.
 *
 * @param string $dest Candidate destination (raw; validated here).
 * @param string $base Login endpoint — may be absolute for cross-host surfaces
 *                     (profile-app, membership-pages) and may already carry a
 *                     query string (?action=…).
 */
function lg_dest_login_url(string $dest = '', string $base = '/wp-login.php'): string
{
    $d = lg_dest_capture($dest);
    if ($d === '') {
        return $base;
    }
    return $base . (strpos($base, '?') === false ? '?' : '&')
        . 'redirect_to=' . rawurlencode($d);
}
}
