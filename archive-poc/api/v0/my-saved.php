<?php
/**
 * archive-poc/api/v0/my-saved.php — the viewer's saved posts (READ), newest first, with
 * enough to render cards (content: title/url/thumb/kind/tier/author; topic: title). WP-pool,
 * WP-cookie gated (no nonce — it's a read of your own saves).
 *
 *   GET -> { authenticated, items:[{post_type,item_id,saved_at,title,url,thumb_url,kind,tier,author_name}] }
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

$uid = (int) get_current_user_id();
if ($uid <= 0) { echo json_encode(['authenticated' => false, 'items' => []]); exit; }

$uuid  = lg_comments_uuids_for_wp_ids([$uid])[$uid] ?? null;
try {
    $items = lg_saved_list(lg_comments_pdo(), $uid, $uuid, 100);
} catch (Throwable $e) {
    error_log('[lg-my-saved] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['authenticated' => true, 'items' => [], 'error' => 'server_error']);
    exit;
}
echo json_encode(['authenticated' => true, 'items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
