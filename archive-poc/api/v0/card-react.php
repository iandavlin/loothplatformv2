<?php
/**
 * archive-poc/api/v0/card-react.php — feed-card reaction toggle (WRITE) + viewer
 * state (GET). The Hub door of card-reactions.
 *
 * Runs on the looth-dev WP FPM pool (NOT the archive-poc pool), exactly like
 * comment-react.php: the participation gate is the WP login cookie, because an
 * unbridged member is anon to /whoami but has a valid WP cookie — gating on
 * /whoami (like the standalone like.php door does) would lock real members out of
 * reacting. The feed COUNTS stay WP-free: _feed.php SSR reads them directly via
 * lg_card_reactions_for_items() on the bb-mirror SELECT grant. Only the write and
 * the viewer's own picks/nonce (this file's GET) boot WP.
 *
 *   GET  ?items=pt:id,pt:id  (or ?post_type=&item_id=)
 *        → { authenticated, wp_user_id?, nonce?, my_reactions?:{"pt:id":slug},
 *            counts?:{"pt:id":{slug:int}} }
 *   POST { post_type, item_id, slug, _wpnonce }   (slug ∈ lg_reactions_palette())
 *        → { ok, post_type, item_id, counts:{slug:int}, mine:slug|null }
 *
 * IDOR-proof like comment-react.php: the reactor is taken from the validated session
 * (get_current_user_id) — never from the client. One reaction per user per card;
 * re-posting the same slug toggles it off, a different slug switches. 'like' is just
 * a slug here (the discovery.likes fold), so liking a card and picking a reaction
 * are the SAME slot.
 */

declare(strict_types=1);
require_once __DIR__ . '/_reactions.php';   // card store + palette helpers (require_once _comments.php)

// Boot WordPress (looth-dev pool) for cookie/session + nonce.
if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require LG_ARCHIVE_POC_WP_LOAD;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_kr_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Whitelist the surfaceable post types (managed CPTs + bbPress topics) — same set
// as the standalone like.php door, since a like is now one of these reactions.
const LG_CARD_REACT_TYPES = ['post-imgcap','post-type-videos','sponsor-post','loothprint',
                             'loothcuts','useful_links','member-benefit','topic','reply'];

/** Parse ?items=pt:id,pt:id into [['post_type'=>,'item_id'=>], …] (whitelisted). */
function lg_kr_parse_items(string $csv): array {
    $items = [];
    foreach (explode(',', $csv) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        $pos = strrpos($tok, ':');
        if ($pos === false) continue;
        $pt = substr($tok, 0, $pos);
        $id = (int) substr($tok, $pos + 1);
        if (in_array($pt, LG_CARD_REACT_TYPES, true) && $id > 0) {
            $items["$pt:$id"] = ['post_type' => $pt, 'item_id' => $id];
        }
    }
    return array_values($items);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid    = (int) get_current_user_id();

// ---- GET: viewer state (nonce + my_reactions + optional counts) -----------
if ($method === 'GET') {
    if ($uid <= 0) lg_kr_json(['authenticated' => false]);

    // Accept a batch (?items=) or a single (?post_type=&item_id=).
    $items = [];
    if (isset($_GET['items'])) {
        $items = lg_kr_parse_items((string) $_GET['items']);
    } else {
        $pt = isset($_GET['post_type']) ? trim((string) $_GET['post_type']) : '';
        $id = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
        if (in_array($pt, LG_CARD_REACT_TYPES, true) && $id > 0) {
            $items = [['post_type' => $pt, 'item_id' => $id]];
        }
    }

    $mine = []; $counts = [];
    if ($items) {
        try {
            $pdo  = lg_comments_pdo();
            $uuid = lg_comments_uuids_for_wp_ids([$uid])[$uid] ?? null;
            $mine   = lg_card_reactions_mine($pdo, $items, $uid, $uuid);
            $counts = lg_card_reactions_for_items($pdo, $items);
        } catch (Throwable $e) {
            error_log('[lg-card-react] GET: ' . $e->getMessage());
        }
    }
    lg_kr_json([
        'authenticated' => true,
        'wp_user_id'    => $uid,
        'nonce'         => wp_create_nonce('lg_card_react'),
        'my_reactions'  => (object) $mine,
        'counts'        => (object) $counts,
    ]);
}

if ($method !== 'POST') {
    lg_kr_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// ---- Same-origin guard (defense-in-depth) ---------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST) ?: '';
    if (strcasecmp($host, LG_ARCHIVE_POC_HOST) !== 0) {
        lg_kr_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
}

// ---- Gate: must be a logged-in WP user (the WP login cookie) --------------
if ($uid <= 0) lg_kr_json(['ok' => false, 'error' => 'auth_required'], 401);

// ---- Input ----------------------------------------------------------------
$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

// CSRF: WP nonce, from header or body.
$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? ($body['_wpnonce'] ?? '');
if (!wp_verify_nonce((string) $nonce, 'lg_card_react')) {
    lg_kr_json(['ok' => false, 'error' => 'bad_csrf'], 403);
}

$postType = isset($body['post_type']) ? trim((string) $body['post_type']) : '';
$itemId   = isset($body['item_id']) ? (int) $body['item_id'] : 0;
$slug     = isset($body['slug']) ? trim((string) $body['slug']) : '';
if (!in_array($postType, LG_CARD_REACT_TYPES, true) || $itemId <= 0
    || !in_array($slug, lg_reactions_slugs(), true)) {
    lg_kr_json(['ok' => false, 'error' => 'bad_request'], 400);
}

// ---- Reactor identity (server-derived) ------------------------------------
$uuid = lg_comments_uuids_for_wp_ids([$uid])[$uid] ?? null;   // bridge → uuid (null if unbridged)

// ---- Apply ----------------------------------------------------------------
try {
    $res = lg_card_reactions_set(lg_comments_pdo(), $postType, $itemId, $uid, $uuid, $slug);
} catch (Throwable $e) {
    error_log('[lg-card-react] ' . $e->getMessage());
    lg_kr_json(['ok' => false, 'error' => 'server_error'], 500);
}

lg_kr_json([
    'ok'        => true,
    'post_type' => $postType,
    'item_id'   => $itemId,
    'counts'    => (object) $res['counts'],   // {} not [] when empty
    'mine'      => $res['mine'],
]);
