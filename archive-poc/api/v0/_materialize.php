<?php
/**
 * archive-poc/api/v0/_materialize.php — single-post blob materialize endpoint.
 *
 * Loopback-only (nginx restricts $remote_addr to 127.0.0.1; defense-in-depth
 * here too). Runs on the looth-dev FPM pool so it has BOTH a WordPress boot
 * (to resolve PostContext) AND peer-auth to the `looth-dev` pg role (to write
 * the `discovery.article_blobs` table). Same shape as _sync.php.
 *
 * Request body (JSON or form-encoded):
 *   { "post_id": <int>, "action": "upsert" | "delete" }
 *
 * 200 {ok:true,…} on success, 4xx/5xx {error} otherwise.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'loopback only']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($body)) $body = $_POST;

$post_id     = (int) ($body['post_id'] ?? 0);
$sync_action = (string) ($body['action'] ?? 'upsert');
if ($post_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'post_id required']);
    exit;
}

require __DIR__ . '/../../config.php';   // lg_archive_poc_pdo()

// Boot WP on the looth-dev pool — same docroot autodetect as _sync.php.
if (!function_exists('get_post')) {
    $wp_candidates = [
        '/var/www/html/wp-load.php' => 'loothgroup.com',
        '/var/www/dev/wp-load.php'  => 'dev.loothgroup.com',
    ];
    $wp_load = null; $wp_host = null;
    foreach ($wp_candidates as $path => $host) {
        if (is_file($path)) { $wp_load = $path; $wp_host = $host; break; }
    }
    if ($wp_load === null) {
        http_response_code(500);
        echo json_encode(['error' => 'no wp-load.php found']);
        exit;
    }
    // Prefer the shared /etc/looth/env host (dev2 etc.) over the path-derived
    // default; falls back to $wp_host when the file is absent (dev1 unchanged).
    if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
    $lg_shared = function_exists('lg_env') ? lg_env() : [];
    if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = $lg_shared['host'] ?? $wp_host;
    if (!defined('WP_USE_THEMES'))     define('WP_USE_THEMES', false);
    require $wp_load;
}

require_once __DIR__ . '/../../bin/materializer.php';

try {
    $db = lg_materialize_pdo();
    if ($sync_action === 'delete') {
        lg_materialize_delete($db, $post_id);
        $result = ['action' => 'delete'];
    } else {
        $result = lg_materialize_upsert($db, $post_id);
    }
} catch (Throwable $e) {
    error_log('lg-materialize: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'materialize failure', 'detail' => $e->getMessage()]);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true, 'post_id' => $post_id] + $result);
