<?php
/**
 * archive-poc/api/v0/comment-edit.php — edit a comment's body (WRITE).
 *
 * Runs on the looth-dev WP FPM pool (NOT the archive-poc pool), exactly like
 * comment-post.php / comment-react.php / comment-delete.php: the participation gate
 * is the WP login cookie (an unbridged member is anon to /whoami but has a valid WP
 * cookie). The modal READ (comments.php) stays WP-free; only this write boots WP.
 *
 *   POST { comment_id, body, _wpnonce }
 *        → { ok, comment_id, body, edited:true, when }
 *
 * Body is sanitized exactly like the compose path (plain text: tags stripped,
 * newlines normalized, capped at 6000). The edit stamps edited_at, which the read
 * surfaces so the renderer can show an "(edited)" marker.
 *
 * Authz: comment AUTHOR (author_wp_id or bridged user_uuid match) OR a moderator
 * (current_user_can('moderate_comments') — admins + editors). Everyone else → 403.
 * Identity is server-derived (validated cookie + profile bridge), never client
 * input — IDOR-proof like the write path.
 */

declare(strict_types=1);
require_once __DIR__ . '/_comments.php';   // store + authz helpers (WP-free)

// Boot WordPress (looth-dev pool) for cookie/session + nonce.
if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require LG_ARCHIVE_POC_WP_LOAD;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_ce_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    lg_ce_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// ---- Same-origin guard (defense-in-depth) --------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    if (strcasecmp($host, LG_ARCHIVE_POC_HOST) !== 0) {
        lg_ce_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
}

// ---- Gate: must be a logged-in WP user (the WP login cookie) --------------
$uid = (int) get_current_user_id();
if ($uid <= 0) lg_ce_json(['ok' => false, 'error' => 'auth_required'], 401);

// ---- Input ----------------------------------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

// CSRF: WP nonce (same action the composer mints), from header or body.
$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? ($body['_wpnonce'] ?? '');
if (!wp_verify_nonce((string) $nonce, 'lg_comment')) {
    lg_ce_json(['ok' => false, 'error' => 'bad_csrf'], 403);
}

$commentId = isset($body['comment_id']) ? (int) $body['comment_id'] : 0;
$text      = isset($body['body']) ? trim((string) $body['body']) : '';
if ($commentId <= 0) lg_ce_json(['ok' => false, 'error' => 'bad_request'], 400);

// Plain text only: strip tags, normalize newlines, cap length. (Same as compose.)
$text = wp_strip_all_tags($text, false);
$text = preg_replace("/\r\n?/", "\n", $text);
$text = trim((string) $text);
if ($text === '') lg_ce_json(['ok' => false, 'error' => 'empty'], 400);
if (mb_strlen($text) > 6000) $text = mb_substr($text, 0, 6000);

// ---- Caller identity (server-derived) ------------------------------------
$uuidMap = lg_comments_uuids_for_wp_ids([$uid]);   // bridge → uuid (empty if unbridged)
$uuid    = $uuidMap[$uid] ?? null;
$isMod   = current_user_can('moderate_comments');

// ---- Authz + action --------------------------------------------------------
try {
    $pdo = lg_comments_pdo();
    $row = lg_comments_get($pdo, $commentId);
    if (!$row) lg_ce_json(['ok' => false, 'error' => 'not_found'], 404);
    // Can't edit a trashed comment back into existence via the edit path — restore first.
    if (($row['status'] ?? '') !== 'approved') {
        lg_ce_json(['ok' => false, 'error' => 'not_editable'], 409);
    }
    if (!$isMod && !lg_comment_author_match($row, $uid, $uuid)) {
        lg_ce_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    lg_comments_update_body($pdo, $commentId, $text);
} catch (Throwable $e) {
    error_log('[lg-comment-edit] ' . $e->getMessage());
    lg_ce_json(['ok' => false, 'error' => 'server_error'], 500);
}

$when = (new DateTime('now', new DateTimeZone(LG_ARCHIVE_POC_TZ)))->format('M j, Y · g:ia');
lg_ce_json([
    'ok'         => true,
    'comment_id' => $commentId,
    'body'       => $text,
    'edited'     => true,
    'when'       => $when,
]);
