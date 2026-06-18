<?php
/**
 * archive-poc/api/v0/comment-post.php — comment compose gate + write.
 *
 * Runs on the looth-dev WP FPM pool (NOT the archive-poc pool) so it can validate
 * the WP login cookie and mint/verify a WP nonce. This is the deliberate split: the
 * modal READ (comments.php) is WP-free for speed; the WRITE boots WP because the
 * posting gate is the WP login cookie — an unbridged member has a valid WP cookie
 * but /whoami reads them anon, so gating writes on /whoami (like likes do) would lock
 * real members out of commenting. Mirrors bb-mirror's auth.php pattern.
 *
 *   GET  ?post_type=&item_id=  → { authenticated, wp_user_id?, display_name?, nonce?,
 *                                  my_reactions?:{comment_id:slug} }
 *   POST { post_type, item_id, parent_id?, body, _wpnonce }
 *        → { ok, comment:{ id, parent_id, author_name, slug, avatar_url, body, when } }
 *
 * IDOR-proof like like.php: the author is taken from the validated session
 * (get_current_user_id) — never from the client. The client supplies only the
 * content target + body text.
 */

declare(strict_types=1);
require_once __DIR__ . '/_comments.php';   // store + profile bridge helpers (WP-free)

// Boot WordPress (looth-dev pool) for cookie/session + nonce.
if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require LG_ARCHIVE_POC_WP_LOAD;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_cp_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid    = (int) get_current_user_id();

// ---- GET: composer auth state + nonce ------------------------------------
if ($method === 'GET') {
    if ($uid <= 0) lg_cp_json(['authenticated' => false]);
    $u = wp_get_current_user();
    // The viewer's own reactions across this item's thread, so the modal can
    // highlight "my" chips on load. Counts themselves come WP-free from comments.php.
    $mine = [];
    $gType = isset($_GET['post_type']) ? trim((string) $_GET['post_type']) : '';
    $gItem = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
    if (in_array($gType, LG_COMMENTS_TYPES, true) && $gItem > 0) {
        try {
            $pdo  = lg_comments_pdo();
            $cids = $pdo->prepare('SELECT id FROM comments WHERE post_type=? AND item_id=?');
            $cids->execute([$gType, $gItem]);
            $ids  = array_map('intval', $cids->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $mine = lg_reactions_mine($pdo, $ids, $uid);
        } catch (Throwable $e) {
            error_log('[lg-comment-post] my_reactions: ' . $e->getMessage());
        }
    }
    lg_cp_json([
        'authenticated' => true,
        'wp_user_id'    => $uid,
        'display_name'  => (string) $u->display_name,
        'nonce'         => wp_create_nonce('lg_comment'),
        'my_reactions'  => (object) $mine,
        // Lets the modal reveal edit/delete on EVERY comment for moderators (the
        // delete/edit endpoints enforce the same check server-side).
        'can_moderate'  => current_user_can('moderate_comments'),
    ]);
}

if ($method !== 'POST') lg_cp_json(['ok' => false, 'error' => 'method_not_allowed'], 405);

// ---- Same-origin guard (defense-in-depth) --------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    if (strcasecmp($host, LG_ARCHIVE_POC_HOST) !== 0) {
        lg_cp_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
}

// ---- Gate: must be a logged-in WP user (the WP login cookie) --------------
if ($uid <= 0) lg_cp_json(['ok' => false, 'error' => 'auth_required'], 401);

// ---- Input ----------------------------------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

// CSRF: WP nonce, from header or body.
$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? ($body['_wpnonce'] ?? '');
if (!wp_verify_nonce((string) $nonce, 'lg_comment')) {
    lg_cp_json(['ok' => false, 'error' => 'bad_csrf'], 403);
}

$postType = isset($body['post_type']) ? trim((string) $body['post_type']) : '';
$itemId   = isset($body['item_id']) ? (int) $body['item_id'] : 0;
$parentId = isset($body['parent_id']) ? (int) $body['parent_id'] : 0;
$text     = isset($body['body']) ? trim((string) $body['body']) : '';

if (!in_array($postType, LG_COMMENTS_TYPES, true) || $itemId <= 0) {
    lg_cp_json(['ok' => false, 'error' => 'bad_request'], 400);
}
// The content item must really exist as a post of that type (don't accept comments
// on arbitrary ids).
$target = get_post($itemId);
if (!$target || $target->post_type !== $postType) {
    lg_cp_json(['ok' => false, 'error' => 'bad_target'], 400);
}

// Plain text only: strip tags, collapse, cap length. The reader escapes + nl2br.
$text = wp_strip_all_tags($text, false);
$text = preg_replace("/\r\n?/", "\n", $text);
$text = trim((string) $text);
if ($text === '') lg_cp_json(['ok' => false, 'error' => 'empty'], 400);
if (mb_strlen($text) > 6000) $text = mb_substr($text, 0, 6000);

// ---- Author identity (server-derived) ------------------------------------
$wpUser   = wp_get_current_user();
$authorNm = (string) $wpUser->display_name;
$uuidMap  = lg_comments_uuids_for_wp_ids([$uid]);   // bridge → uuid (empty if unbridged)
$uuid     = $uuidMap[$uid] ?? null;

// ---- Insert ---------------------------------------------------------------
try {
    $pdo = lg_comments_pdo();
    $id  = lg_comments_insert($pdo, [
        'post_type'    => $postType,
        'item_id'      => $itemId,
        'parent_id'    => $parentId,
        'user_uuid'    => $uuid,
        'author_wp_id' => $uid,
        'author_name'  => $authorNm,
        'body'         => $text,
    ]);
} catch (Throwable $e) {
    error_log('[lg-comment-post] ' . $e->getMessage());
    lg_cp_json(['ok' => false, 'error' => 'server_error'], 500);
}

// ---- Response: the rendered comment (live author card if bridged) ---------
$card = $uuid ? (lg_comments_author_cards([$uuid])[$uuid] ?? null) : null;
$when = (new DateTime('now', new DateTimeZone(LG_ARCHIVE_POC_TZ)))->format('M j, Y · g:ia');

lg_cp_json(['ok' => true, 'comment' => [
    'id'          => $id,
    'parent_id'   => $parentId > 0 ? $parentId : 0,
    'author_name' => $card && $card['display_name'] !== '' ? $card['display_name'] : $authorNm,
    'slug'        => $card['slug'] ?? '',
    'avatar_url'  => $card['avatar_url'] ?? '',
    'body'        => $text,
    'when'        => $when,
]]);
