<?php
/**
 * bb-mirror/api/v0/set-forum-image.php — admin sets a forum's header image.
 *
 * POST /bb-mirror-api/v0/set-forum-image   (JSON: {forum_id, url})  → set
 *   url empty/null → clears it (revert to auto-derived).
 *
 * Runs on the WP FPM pool (looth-dev) for $current_user + nonce verification.
 * Writes forums.forum.header_image_url in postgres directly (this column is NOT
 * synced from WP — it's bb-mirror-local presentation state).
 *
 * Auth: requires a valid X-WP-Nonce (wp_rest) AND edit_others_topics/admin —
 * same capability gate as edit-all. Cookie-authed (same origin).
 */

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function lg_sfi_fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') lg_sfi_fail(405, 'POST only');

$uid = get_current_user_id();
if (!$uid) lg_sfi_fail(401, 'not signed in');

$nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
if (!wp_verify_nonce($nonce, 'wp_rest')) lg_sfi_fail(403, 'bad nonce');

if (!current_user_can('edit_others_topics') && !current_user_can('manage_options')) {
    lg_sfi_fail(403, 'not allowed');
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$forum_id  = (int)($body['forum_id'] ?? 0);
$upload_id = (int)($body['upload_id'] ?? 0);
$url       = trim((string)($body['url'] ?? ''));
if ($forum_id < 0) lg_sfi_fail(400, 'bad forum_id');

// Preferred path: resolve a clean, public wp-content URL from the upload's
// attachment ID (the raw /media/upload preview URL is access-gated and won't
// render as a background). upload_id is the WP attachment post ID.
if ($upload_id > 0) {
    $resolved = wp_get_attachment_image_url($upload_id, 'large') ?: wp_get_attachment_url($upload_id);
    if (!$resolved) lg_sfi_fail(400, 'could not resolve upload');
    $url = (string)$resolved;
}

// Only allow absolute https URLs (no javascript: etc.) on our OWN origin. Empty
// = clear. Host allowlist closes the stored-SSRF/arbitrary-host hole: header
// images are CSS backgrounds, and every legit source (wp-content uploads, the
// /img.php resizer, the upload_id path resolved above) lives on the site host.
$lg_sfi_allowed_hosts = [strtolower(LG_BB_MIRROR_HOST), 'www.' . strtolower(LG_BB_MIRROR_HOST)];
if ($url !== '') {
    $lg_sfi_host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!preg_match('#^https://#i', $url) || !in_array($lg_sfi_host, $lg_sfi_allowed_hosts, true)) {
        lg_sfi_fail(400, 'url host not allowed');
    }
}

$db = bb_mirror_db(false);

// forum_id 0 = the site-wide "All Forums" header (no forum row); store in the
// sync_state kv so the pg-only feed can read it.
if ($forum_id === 0) {
    if ($url === '') {
        $db->prepare("DELETE FROM sync_state WHERE key = 'site_header_image'")->execute();
    } else {
        $up = $db->prepare("INSERT INTO sync_state (key, value, updated_at)
                            VALUES ('site_header_image', :url, now())
                            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()");
        $up->bindValue(':url', $url);
        $up->execute();
    }
    echo json_encode(['ok' => true, 'forum_id' => 0, 'url' => $url ?: null]);
    exit;
}

// Confirm the forum exists + is public before writing.
$chk = $db->prepare("SELECT 1 FROM forum WHERE id = ? AND visibility = 'public' LIMIT 1");
$chk->execute([$forum_id]);
if (!$chk->fetch()) lg_sfi_fail(404, 'forum not found');

$upd = $db->prepare("UPDATE forum SET header_image_url = :url WHERE id = :id");
$upd->bindValue(':url', $url === '' ? null : $url);
$upd->bindValue(':id', $forum_id, PDO::PARAM_INT);
$upd->execute();

echo json_encode(['ok' => true, 'forum_id' => $forum_id, 'url' => $url ?: null]);
