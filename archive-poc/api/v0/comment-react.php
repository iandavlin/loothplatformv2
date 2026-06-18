<?php
/**
 * archive-poc/api/v0/comment-react.php — comment reaction toggle (WRITE).
 *
 * Runs on the looth-dev WP FPM pool (NOT the archive-poc pool), exactly like
 * comment-post.php: the participation gate is the WP login cookie, because an
 * unbridged member is anon to /whoami but has a valid WP cookie — gating on
 * /whoami (like content-likes do) would lock real members out of reacting. The
 * modal READ + reaction COUNTS stay WP-free on the archive-poc pool; only the
 * write (and the viewer's own pick, served by comment-post.php GET) boots WP.
 *
 *   POST { comment_id, slug, _wpnonce }  (slug ∈ lg_reactions_palette())
 *        → { ok, comment_id, counts:{slug:int}, mine:slug|null }
 *
 * IDOR-proof like comment-post.php: the reactor is taken from the validated
 * session (get_current_user_id) — never from the client. One reaction per user
 * per comment; re-posting the same slug toggles it off, a different slug switches.
 */

declare(strict_types=1);
require_once __DIR__ . '/_comments.php';   // store + palette helpers (WP-free)

// Boot WordPress (looth-dev pool) for cookie/session + nonce.
if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require LG_ARCHIVE_POC_WP_LOAD;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_cr_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lg_cr_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// ---- Same-origin guard (defense-in-depth) --------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    if (strcasecmp($host, LG_ARCHIVE_POC_HOST) !== 0) {
        lg_cr_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
}

// ---- Gate: must be a logged-in WP user (the WP login cookie) --------------
$uid = (int) get_current_user_id();
if ($uid <= 0) lg_cr_json(['ok' => false, 'error' => 'auth_required'], 401);

// ---- Input ----------------------------------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

// CSRF: WP nonce (same action the composer mints), from header or body.
$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? ($body['_wpnonce'] ?? '');
if (!wp_verify_nonce((string) $nonce, 'lg_comment')) {
    lg_cr_json(['ok' => false, 'error' => 'bad_csrf'], 403);
}

$commentId = isset($body['comment_id']) ? (int) $body['comment_id'] : 0;
$slug      = isset($body['slug']) ? trim((string) $body['slug']) : '';
if ($commentId <= 0 || !in_array($slug, lg_reactions_slugs(), true)) {
    lg_cr_json(['ok' => false, 'error' => 'bad_request'], 400);
}

// ---- Reactor identity (server-derived) -----------------------------------
$uuidMap = lg_comments_uuids_for_wp_ids([$uid]);   // bridge → uuid (empty if unbridged)
$uuid    = $uuidMap[$uid] ?? null;

// ---- Apply ----------------------------------------------------------------
try {
    $pdo = lg_comments_pdo();
    // Clean 400 if the comment doesn't exist (the FK would otherwise 500).
    $chk = $pdo->prepare('SELECT 1 FROM comments WHERE id = ?');
    $chk->execute([$commentId]);
    if (!$chk->fetchColumn()) lg_cr_json(['ok' => false, 'error' => 'bad_target'], 400);

    $res = lg_reactions_set($pdo, $commentId, $uid, $uuid, $slug);
} catch (Throwable $e) {
    error_log('[lg-comment-react] ' . $e->getMessage());
    lg_cr_json(['ok' => false, 'error' => 'server_error'], 500);
}

lg_cr_json([
    'ok'         => true,
    'comment_id' => $commentId,
    'counts'     => (object) $res['counts'],   // {} not [] when empty
    'mine'       => $res['mine'],
]);
