<?php
/**
 * archive-poc/api/v0/save-post.php — save / unsave a feed card (WRITE) + the viewer's
 * saved-state (GET). Mirrors card-react.php: runs on the looth-dev WP pool, gated by the
 * WP login cookie (an unbridged member is anon to /whoami but has a valid WP cookie), CSRF
 * via WP nonce. Binary (no slug): a row = saved.
 *
 *   GET  ?items=pt:id,pt:id  -> { authenticated, wp_user_id?, nonce?, my_saves?:{"pt:id":true} }
 *   POST { post_type, item_id, _wpnonce, unsave?:bool }  -> { ok, post_type, item_id, saved:bool }
 */
declare(strict_types=1);
require_once __DIR__ . '/_saved.php';

if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require LG_ARCHIVE_POC_WP_LOAD;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_sv_json($p, int $c = 200): void {
    http_response_code($c);
    echo json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Savable types = the surfaceable cards (managed CPTs + bbPress topics), same set as reactions.
const LG_SAVE_TYPES = ['post-imgcap','post-type-videos','sponsor-post','loothprint',
                       'loothcuts','useful_links','member-benefit','topic'];

function lg_sv_parse_items(string $csv): array {
    $items = [];
    foreach (explode(',', $csv) as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        $pos = strrpos($tok, ':');
        if ($pos === false) continue;
        $pt = substr($tok, 0, $pos);
        $id = (int) substr($tok, $pos + 1);
        if (in_array($pt, LG_SAVE_TYPES, true) && $id > 0) $items["$pt:$id"] = ['post_type' => $pt, 'item_id' => $id];
    }
    return array_values($items);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid    = (int) get_current_user_id();

if ($method === 'GET') {
    if ($uid <= 0) lg_sv_json(['authenticated' => false]);
    $items = isset($_GET['items']) ? lg_sv_parse_items((string) $_GET['items']) : [];
    $uuid  = lg_comments_uuids_for_wp_ids([$uid])[$uid] ?? null;
    lg_sv_json([
        'authenticated' => true,
        'wp_user_id'    => $uid,
        'nonce'         => wp_create_nonce('lg_save_post'),
        'my_saves'      => (object) lg_saved_state(lg_comments_pdo(), $items, $uid, $uuid),
    ]);
}

if ($method !== 'POST') lg_sv_json(['ok' => false, 'error' => 'method_not_allowed'], 405);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && strcasecmp(parse_url($origin, PHP_URL_HOST) ?: '', LG_ARCHIVE_POC_HOST) !== 0) {
    lg_sv_json(['ok' => false, 'error' => 'bad_origin'], 403);
}
if ($uid <= 0) lg_sv_json(['ok' => false, 'error' => 'auth_required'], 401);

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? ($body['_wpnonce'] ?? '');
if (!wp_verify_nonce((string) $nonce, 'lg_save_post')) lg_sv_json(['ok' => false, 'error' => 'bad_csrf'], 403);

$postType = isset($body['post_type']) ? trim((string) $body['post_type']) : '';
$itemId   = isset($body['item_id']) ? (int) $body['item_id'] : 0;
$unsave   = !empty($body['unsave']);
if (!in_array($postType, LG_SAVE_TYPES, true) || $itemId <= 0) lg_sv_json(['ok' => false, 'error' => 'bad_request'], 400);

$uuid = lg_comments_uuids_for_wp_ids([$uid])[$uid] ?? null;
try {
    $saved = lg_saved_set(lg_comments_pdo(), $postType, $itemId, $uid, $uuid, !$unsave);
} catch (Throwable $e) {
    error_log('[lg-save-post] ' . $e->getMessage());
    lg_sv_json(['ok' => false, 'error' => 'server_error'], 500);
}
lg_sv_json(['ok' => true, 'post_type' => $postType, 'item_id' => $itemId, 'saved' => $saved]);
