<?php
/**
 * archive-poc/api/v0/like.php — POST /archive-api/v0/like (toggle).
 *
 * Body (JSON or form): { post_type: string, item_id: int }
 * Headers: X-LG-CSRF: <token from the stream page>
 *
 * Auth: must be authenticated per /whoami (anon → 401). The like is bound to the
 * viewer's user_uuid from whoami — a caller can never act as another user (no
 * user id is accepted from the client; IDOR is structurally impossible).
 * CSRF: stateless HMAC of the viewer uuid (see _likes.php) + same-origin check.
 *
 * Response: { ok: true, count: int, liked: bool }
 */

declare(strict_types=1);
require_once __DIR__ . '/_likes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function like_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    like_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// --- Same-origin guard (defense-in-depth alongside the HMAC token) ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    if (strcasecmp($host, LG_ARCHIVE_POC_HOST) !== 0) {
        like_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
}

// --- Identity from /whoami VERBATIM ----------------------------------------
$who  = lg_archive_poc_whoami();
$auth = is_array($who) && !empty($who['authenticated']);
$uuid = is_array($who) ? ($who['user_uuid'] ?? null) : null;
if (!$auth || !lg_likes_is_uuid($uuid)) {
    // Logged-out (or no resolvable identity): never writes. The client turns this
    // into a login prompt. tier is irrelevant — any authenticated member can like.
    like_json(['ok' => false, 'error' => 'auth_required'], 401);
}

// --- CSRF ------------------------------------------------------------------
$csrf = $_SERVER['HTTP_X_LG_CSRF'] ?? '';
if (!lg_likes_csrf_ok($uuid, $csrf)) {
    like_json(['ok' => false, 'error' => 'bad_csrf'], 403);
}

// --- Input -----------------------------------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;
$postType = isset($body['post_type']) ? trim((string) $body['post_type']) : '';
$itemId   = isset($body['item_id']) ? (int) $body['item_id'] : 0;

// Whitelist the surfaceable post types (the managed CPTs + bbPress topics).
$ALLOWED = ['post-imgcap','post-type-videos','sponsor-post','loothprint','loothcuts',
            'useful_links','member-benefit','topic'];
if ($postType === '' || !in_array($postType, $ALLOWED, true) || $itemId <= 0) {
    like_json(['ok' => false, 'error' => 'bad_request'], 400);
}

// --- Toggle ----------------------------------------------------------------
try {
    $res = lg_likes_toggle(lg_likes_pdo(), $postType, $itemId, (string) $uuid);
} catch (Throwable $e) {
    error_log('[lg-like] ' . $e->getMessage());
    like_json(['ok' => false, 'error' => 'server_error'], 500);
}

like_json(['ok' => true, 'count' => $res['count'], 'liked' => $res['liked']]);
