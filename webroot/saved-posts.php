<?php
/**
 * Saved posts sink + list  (Looth "Save post" — Instagram-style bookmark)
 *
 * HOME: docroot /var/www/dev/saved-posts.php (buck's lane). Mirrors
 * push-subscribe.php: bootstraps WP via wp-load.php for $wpdb + the logged-in
 * user, reads/writes the WP-MySQL table wp_lg_saved_posts (created via wp db
 * query from the buck login). Per-user bookmarks, deduped by url_hash.
 *
 * ROUTING: rides the existing docroot `location ~ \.php$` PHP-FPM handler — no
 * new nginx rule. Client (hub-polish.js) calls /saved-posts.php directly.
 *
 * AUTH: identity is the WP login cookie (same-origin fetch carries it). Saving
 * REQUIRES a logged-in user (a bookmark belongs to an account). Anonymous GET
 * returns an empty list so the client can fall back to its localStorage cache.
 *
 * CONTRACT:
 *   GET  /saved-posts.php            -> 200 {"ok":true,"items":[{url,title,cover,kind,saved_at}]}  (newest first)
 *   POST application/json
 *     { action:"save",  url, title?, cover?, kind? } -> 200 {"ok":true,"saved":true}
 *     { action:"unsave", url }                        -> 200 {"ok":true,"saved":false}
 *   401 {"ok":false,"error":"auth_required"} on POST when not logged in
 *   400 on bad payload. No emoji. Self-contained, WP core only.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function sp_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Bootstrap WP for $wpdb + the logged-in user ----------------------------
$wp_load = __DIR__ . '/wp-load.php';
if (!is_readable($wp_load)) {
    sp_out(500, ['ok' => false, 'error' => 'wp_unavailable']);
}
require $wp_load;

/** @var wpdb $wpdb */
global $wpdb;
if (!isset($wpdb)) {
    sp_out(500, ['ok' => false, 'error' => 'db_unavailable']);
}

$wp_user_id = null;
$user_uuid  = null;
if (function_exists('wp_get_current_user')) {
    $u = wp_get_current_user();
    if ($u && !empty($u->ID)) {
        $wp_user_id = (int) $u->ID;
        $meta = get_user_meta($wp_user_id, 'looth_uuid', true);
        if (is_string($meta) && $meta !== '') {
            $user_uuid = substr($meta, 0, 36);
        }
    }
}

$table = $wpdb->prefix . 'lg_saved_posts';

// --- GET: list this user's saved posts, newest first ------------------------
if ($method === 'GET') {
    if ($wp_user_id === null) {
        sp_out(200, ['ok' => true, 'items' => []]);   // anon → empty, client uses local cache
    }
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_url AS url, title, cover, kind, saved_at
               FROM {$table}
              WHERE wp_user_id = %d
              ORDER BY saved_at DESC, id DESC
              LIMIT 500",
            $wp_user_id
        ),
        ARRAY_A
    );
    sp_out(200, ['ok' => true, 'items' => is_array($rows) ? $rows : []]);
}

if ($method !== 'POST') {
    sp_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// --- POST: save / unsave ----------------------------------------------------
if ($wp_user_id === null) {
    sp_out(401, ['ok' => false, 'error' => 'auth_required']);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 8192) {
    sp_out(400, ['ok' => false, 'error' => 'empty_or_oversize_body']);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    sp_out(400, ['ok' => false, 'error' => 'bad_json']);
}

$action = isset($data['action']) && is_string($data['action']) ? $data['action'] : '';
$url    = isset($data['url']) && is_string($data['url']) ? trim($data['url']) : '';
if ($url === '' || strlen($url) > 2048) {
    sp_out(400, ['ok' => false, 'error' => 'bad_url']);
}
// Same-origin posts only: accept a relative path or our own host.
if ($url[0] !== '/' && stripos($url, 'https://') !== 0) {
    sp_out(400, ['ok' => false, 'error' => 'bad_url']);
}
$url_hash = hash('sha256', $url);

$uuid_sql = ($user_uuid !== null && preg_match('/^[0-9a-fA-F-]{1,36}$/', $user_uuid))
    ? "'" . $user_uuid . "'"
    : 'NULL';

if ($action === 'unsave') {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE wp_user_id = %d AND url_hash = %s",
        $wp_user_id, $url_hash
    ));
    sp_out(200, ['ok' => true, 'saved' => false]);
}

if ($action === 'save') {
    $title = isset($data['title']) && is_string($data['title']) ? substr(trim($data['title']), 0, 512) : '';
    $cover = isset($data['cover']) && is_string($data['cover']) ? substr(trim($data['cover']), 0, 2048) : '';
    $kind  = isset($data['kind']) && is_string($data['kind']) ? substr(trim($data['kind']), 0, 32) : '';
    if ($cover !== '' && stripos($cover, 'http') !== 0 && $cover[0] !== '/') $cover = '';
    $now = gmdate('Y-m-d H:i:s');
    $sql = $wpdb->prepare(
        "INSERT INTO {$table}
            (wp_user_id, user_uuid, post_url, url_hash, title, cover, kind, saved_at)
         VALUES (%d, {$uuid_sql}, %s, %s, %s, %s, %s, %s)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title), cover = VALUES(cover), kind = VALUES(kind), saved_at = VALUES(saved_at)",
        $wp_user_id, $url, $url_hash, $title, $cover, $kind, $now
    );
    $res = $wpdb->query($sql);
    if ($res === false) {
        sp_out(500, ['ok' => false, 'error' => 'store_failed']);
    }
    sp_out(200, ['ok' => true, 'saved' => true]);
}

sp_out(400, ['ok' => false, 'error' => 'bad_action', 'allowed' => ['save', 'unsave']]);
