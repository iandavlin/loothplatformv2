<?php
/**
 * _invites.php — email pre-authorisation and one-time invite links.
 *
 * Ian found the hole, 2026-08-16: the Test Group takes only EXISTING wp users,
 * so the most important pre-cutover rehearsal — a fresh recruit going from
 * nothing to a paid membership — was untestable. A fresh person cannot even SEE
 * the join page, because it only reveals itself to logged-in listed members.
 *
 * He ruled the mechanism the same evening: a URL token, single-use, working
 * across devices.
 *
 * ┌─ THE FOUR FENCES ────────────────────────────────────────────────────────┐
 * │ 1. SCOPE. A token admits the JOIN FLOW and nothing else. Not a general    │
 * │    pre-launch bypass: manage-subscription and request-refund stay shut,   │
 * │    because an invitee has no subscription to manage and a token opening   │
 * │    those is a bypass wearing an invite's costume.                         │
 * │ 2. SINGLE-USE MEANS ONE ACCOUNT, not one page view. Burning it on first   │
 * │    open dies on a refresh or a back button — a support ticket, not a      │
 * │    fence. It is consumed when the account is created on email match.      │
 * │ 3. EXPIRY is stamped at mint and checked on every hit, so an old link in  │
 * │    an inbox is dead even though nobody revoked it.                        │
 * │ 4. AUDIT. The account is stamped invite-created and auto-listed on email  │
 * │    match, so HOW a member got in is answerable later rather than guessed. │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * OFF BY DEFAULT AND BYTE-IDENTICAL WHEN OFF. With the flag absent — which is
 * how it merges — every function here returns "no" before reading anything, so
 * the pages behave exactly as they do today. That is what lets a gate bypass
 * reach the serve harmlessly and be looked at before it is ever armed.
 *
 * THE TOKEN IS NEVER STORED. The option holds a SHA-256 of it, so reading the
 * database hands nobody a working invite — the same instinct as the Stripe
 * broker: a store that can be read should not be a store that can be used.
 */

declare(strict_types=1);

const LG_MS_INVITE_OPT   = 'lgms_stripe_invites';
const LG_MS_INVITE_FLAG  = 'lgms_stripe_invites_on';
const LG_MS_INVITE_PARAM = 'lginv';

/** Join flow only. Every other page is outside an invitee's business. */
const LG_MS_INVITE_SCOPE = ['join', 'lgjoin', 'regional-pricing-not-available', 'welcome'];

if (!function_exists('lg_membership_invites_enabled')) {
function lg_membership_invites_enabled(): bool
{
    $v = lg_membership_wp_option(LG_MS_INVITE_FLAG, null);
    return $v === '1' || $v === 'true';
}
}

/**
 * The requested slug, resolved EXACTLY as router.php resolves it.
 *
 * Not "similarly" — exactly, and from the same source. `$ctx` does not carry the
 * slug, and the tempting fix was to add an argument to the page gate, which
 * every page file calls: changing that signature in six places is how one page
 * gets missed and silently keeps the old rule. Mirroring the resolution here
 * means both doors answer the same question without any call site changing.
 */
if (!function_exists('lg_membership_invite_slug')) {
function lg_membership_invite_slug(): string
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

/** @return array<string,array> token-hash => record */
if (!function_exists('lg_membership_invite_all')) {
function lg_membership_invite_all(): array
{
    $raw = lg_membership_wp_option(LG_MS_INVITE_OPT, null);
    if ($raw === null || $raw === '') { return []; }
    // WP stores arrays PHP-serialized. Unserialized with allowed_classes FALSE:
    // this value is read from a database, and a serialized string that can
    // instantiate objects is a gadget waiting for a class to land.
    $v = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($v)) { $v = json_decode($raw, true); }
    return is_array($v) ? $v : [];
}
}

/** The record for a raw token, or null. Never logs or returns the token itself. */
if (!function_exists('lg_membership_invite_record')) {
function lg_membership_invite_record(string $token): ?array
{
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/', $token)) { return null; }
    $rec = lg_membership_invite_all()[hash('sha256', $token)] ?? null;
    return is_array($rec) ? $rec : null;
}
}

/**
 * Does this request carry a live invite for THIS page?
 *
 * Called from the ONE gate both doors delegate to. Every failure returns false
 * and the caller falls through to today's stub, so a bad token is
 * indistinguishable from no token — nothing here tells a prober whether a token
 * exists, is expired or is spent.
 */
if (!function_exists('lg_membership_invite_admits')) {
function lg_membership_invite_admits(): bool
{
    if (!lg_membership_invites_enabled()) { return false; }              // fence: off = today's site
    if (!in_array(lg_membership_invite_slug(), LG_MS_INVITE_SCOPE, true)) { return false; }  // fence 1: scope

    $token = (string) ($_GET[LG_MS_INVITE_PARAM] ?? '');
    $rec   = lg_membership_invite_record($token);
    if ($rec === null) { return false; }

    if (!empty($rec['used_at'])) { return false; }                        // fence 2: spent
    $exp = (int) ($rec['expires'] ?? 0);
    if ($exp > 0 && $exp < time()) { return false; }                      // fence 3: expired

    return true;
}
}

/** The email an invite pre-authorises — the account created must match it. */
if (!function_exists('lg_membership_invite_email')) {
function lg_membership_invite_email(string $token): ?string
{
    $rec = lg_membership_invite_record($token);
    $e   = is_array($rec) ? (string) ($rec['email'] ?? '') : '';
    return $e !== '' ? strtolower($e) : null;
}
}
