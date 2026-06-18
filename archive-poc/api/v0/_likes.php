<?php
/**
 * archive-poc/api/v0/_likes.php — the standalone /stream + like.php "like" door.
 *
 * AS OF 2026-06-06 likes are FOLDED INTO card_reactions (slug='like'). A like is
 * just one slug of the BuddyBoss palette, so there is ONE reaction store for cards,
 * not two parallel like systems. This file keeps its historical {count,liked}
 * contract (used by the /stream/ SSR + like.php) but now DELEGATES to the card
 * store in _reactions.php. discovery.likes is retained read-only (un-dropped) for
 * revert safety until the coordinator retires it.
 *
 * Identity here is still `user_uuid` from /whoami VERBATIM (the standalone door's
 * gate, unchanged). The Hub door (card-react.php) adds the WP-cookie/wp_id path for
 * unbridged members; a bridged member dedups to one row across both via actor_key.
 *
 * FOLD SEMANTICS (consequence of one-reaction-per-card): liking a card and picking
 * a non-like reaction are the SAME slot. If a member already reacted '🤯' and then
 * "likes", the like REPLACES the prior reaction (and vice-versa). 'liked' = the
 * member's current reaction is exactly 'like'.
 *
 * CSRF: a stateless HMAC of the viewer's uuid (rendered into the stream page for
 * authenticated viewers only). Unchanged.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../config.php';   // PDO + whoami + LG_ARCHIVE_POC_CONFIG_SECRET
require_once __DIR__ . '/_reactions.php';     // card_reactions store — likes fold in as slug='like'

if (!function_exists('lg_likes_pdo')) {
/**
 * Postgres handle for the likes/reactions store (the `discovery` schema). Opened as
 * the archive-poc role (peer auth) — the schema owner, so it can write card_reactions
 * directly for the standalone like door. search_path pinned to discovery.
 */
function lg_likes_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = getenv('LG_ARCHIVE_POC_DSN') ?: 'pgsql:host=/var/run/postgresql;dbname=looth';
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $pdo->exec('SET search_path = discovery, public');
    }
    return $pdo;
}
}

if (!function_exists('lg_likes_is_uuid')) {
function lg_likes_is_uuid(?string $s): bool {
    return is_string($s) && (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}
}

if (!function_exists('lg_likes_csrf_token')) {
/**
 * Stateless per-viewer CSRF token. HMAC(uuid|like, secret). Empty string if the
 * viewer has no uuid (anon) or the secret is missing — callers treat "" as
 * "no like UI / reject write".
 */
function lg_likes_csrf_token(?string $userUuid): string {
    if (!lg_likes_is_uuid($userUuid)) return '';
    $secret = defined('LG_ARCHIVE_POC_CONFIG_SECRET') ? LG_ARCHIVE_POC_CONFIG_SECRET : '';
    if ($secret === '') return '';
    return hash_hmac('sha256', strtolower((string) $userUuid) . '|like', $secret);
}
}

if (!function_exists('lg_likes_csrf_ok')) {
/** Constant-time verify of the X-LG-CSRF header against the viewer's expected token. */
function lg_likes_csrf_ok(?string $userUuid, ?string $presented): bool {
    $expected = lg_likes_csrf_token($userUuid);
    if ($expected === '' || !is_string($presented) || $presented === '') return false;
    return hash_equals($expected, $presented);
}
}

if (!function_exists('lg_likes_counts')) {
/**
 * Batch like-counts + viewer-liked-state for a page of items. Now derived from
 * card_reactions (slug='like') so the standalone read agrees with the Hub door.
 *
 * @param array $items list of ['post_type'=>string,'item_id'=>int]
 * @param ?string $viewerUuid liked-state computed only when a valid uuid is given
 * @return array keyed "post_type:item_id" => ['count'=>int,'liked'=>bool]
 */
function lg_likes_counts(PDO $pdo, array $items, ?string $viewerUuid): array {
    $out = [];
    $pairs = [];
    foreach ($items as $it) {
        $pt = (string) ($it['post_type'] ?? '');
        $id = (int) ($it['item_id'] ?? 0);
        if ($pt === '' || $id <= 0) continue;
        $pairs["$pt:$id"] = ['post_type' => $pt, 'item_id' => $id];
    }
    if (!$pairs) return $out;

    $counts = lg_card_reactions_for_items($pdo, array_values($pairs));
    $mine   = lg_likes_is_uuid($viewerUuid)
            ? lg_card_reactions_mine($pdo, array_values($pairs), null, $viewerUuid)
            : [];
    foreach ($pairs as $k => $_) {
        $out[$k] = [
            'count' => (int) ($counts[$k]['like'] ?? 0),
            'liked' => (($mine[$k] ?? null) === 'like'),
        ];
    }
    return $out;
}
}

if (!function_exists('lg_likes_toggle')) {
/**
 * Idempotent like toggle, now backed by card_reactions (slug='like'). Returns
 * ['count'=>int,'liked'=>bool] after the write. Toggling 'like' when the member's
 * current reaction is already 'like' removes it; otherwise it sets 'like' (replacing
 * any prior non-like reaction — see FOLD SEMANTICS in the file header).
 */
function lg_likes_toggle(PDO $pdo, string $postType, int $itemId, string $userUuid): array {
    $res = lg_card_reactions_set($pdo, $postType, $itemId, null, $userUuid, 'like');
    return [
        'count' => (int) ($res['counts']['like'] ?? 0),
        'liked' => ($res['mine'] === 'like'),
    ];
}
}
