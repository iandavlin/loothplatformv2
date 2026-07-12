<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Cache;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Slug;

/**
 * The member-controlled @username.
 *
 *   GET   /profile-api/v0/me/slug?q=<candidate>   → live availability check (the editor
 *                                                    calls this debounced, as you type)
 *         200 {available:true,  slug, cooldown_days_left}
 *         200 {available:false, error:<code>, ...}     ← 200, not 4xx: "taken" is an ANSWER,
 *                                                        not a failure. Only auth/shape errors 4xx.
 *
 *   PATCH /profile-api/v0/me/slug   {"slug":"kevin-smith"}
 *         200 {ok:true, slug, previous}
 *         409 {error:'taken'|'reserved'|'impersonation'|'too_soon', ...}
 *         400 {error:'too_short'|'too_long'|'invalid_charset'|'numeric_not_allowed'|...}
 *
 * Rules live in src/Slug.php — this is a shell over it. See that file for WHY a retired
 * handle is never re-issued (link-hijacking) and why numeric handles are banned (they
 * shadow /u/<id>).
 */

$user   = Auth::requireUser();
$userId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

/** Human copy for each machine code. The editor shows these verbatim. */
$say = static function (array $r): string {
    switch ($r['error'] ?? '') {
        case 'slug_required':       return 'Pick a username.';
        case 'too_short':           return 'Usernames are at least ' . Slug::MIN_LEN . ' characters.';
        case 'too_long':            return 'Usernames are at most ' . Slug::MAX_LEN . ' characters.';
        case 'invalid_charset':     return 'Letters, numbers, hyphens and underscores only — no spaces.';
        case 'numeric_not_allowed': return 'Usernames can’t be only numbers.';
        case 'edge_punctuation':    return 'Usernames can’t start or end with a hyphen or underscore.';
        case 'reserved':            return 'That username is reserved.';
        case 'taken':               return 'That username is taken.';
        case 'impersonation':       return 'That’s another member’s name.';
        case 'too_soon':            return 'You changed your username recently. You can change it again in '
                                         . (int) ($r['days_left'] ?? 0) . ' day'
                                         . (((int) ($r['days_left'] ?? 0)) === 1 ? '' : 's') . '.';
        default:                    return 'That username isn’t available.';
    }
};

// ── GET: availability probe ────────────────────────────────────────────────────
if ($method === 'GET') {
    $q = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';
    $cooldown = Slug::cooldownDaysLeft($userId);

    if (trim($q) === '') {
        // No candidate — just report where the member stands.
        $st = Db::pg()->prepare('SELECT slug FROM users WHERE id = :u');
        $st->execute([':u' => $userId]);
        profile_app_json(200, [
            'slug'               => $st->fetchColumn() ?: null,
            'cooldown_days_left' => $cooldown,
            'min'                => Slug::MIN_LEN,
            'max'                => Slug::MAX_LEN,
        ]);
    }

    $r = Slug::check($userId, $q);

    // Cooldown is reported alongside, but it does NOT mask the availability answer:
    // a member on cooldown should still be able to see whether the name they want is free.
    if (!$r['ok']) {
        profile_app_json(200, [
            'available'          => false,
            'error'              => $r['error'],
            'message'            => $say($r),
            'cooldown_days_left' => $cooldown,
        ] + (isset($r['member']) ? ['member' => $r['member']] : []));
    }

    profile_app_json(200, [
        'available'          => true,
        'slug'               => trim($q),
        'unchanged'          => !empty($r['unchanged']),
        'cooldown_days_left' => $cooldown,
        'message'            => !empty($r['unchanged'])
            ? 'That’s your username already.'
            : '“' . trim($q) . '” is yours.',
    ]);
}

// ── PATCH: take it ─────────────────────────────────────────────────────────────
if ($method !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in))            profile_app_json(400, ['error' => 'bad_json']);
$raw = $in['slug'] ?? null;
if (!is_string($raw))          profile_app_json(400, ['error' => 'slug_required']);

$r = Slug::change($userId, $raw);

if (!$r['ok']) {
    // 400 = the string itself is wrong (fix your input).
    // 409 = the string is fine but you can't have it (someone else does / not yet).
    $conflict = ['taken', 'reserved', 'impersonation', 'too_soon'];
    $code = in_array($r['error'], $conflict, true) ? 409 : 400;
    profile_app_json($code, [
        'error'   => $r['error'],
        'message' => $say($r),
    ] + (isset($r['days_left']) ? ['days_left' => $r['days_left']] : [])
      + (isset($r['member'])    ? ['member'    => $r['member']]    : []));
}

// The slug is in the /whoami payload AND in the looth_id JWT's `slug` claim. Purge the
// cached whoami or the shared site header keeps linking the member to their OLD /u/<slug>
// (which now 301s — so it would still work, but it would look broken). Same cleanup
// me-name.php does for display_name. Best-effort: never block the save.
try {
    $st = Db::pg()->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
    $st->execute([':u' => $userId]);
    $wpId = (int) $st->fetchColumn();
    if ($wpId > 0) Cache::purgeWhoami($wpId);
} catch (\Throwable $e) {
    error_log('[me-slug] whoami purge failed for user_id=' . $userId . ': ' . $e->getMessage());
}

profile_app_json(200, [
    'ok'       => true,
    'slug'     => $r['slug'],
    'previous' => $r['previous'] ?? null,
    'url'      => '/u/' . rawurlencode((string) $r['slug']),
]);
