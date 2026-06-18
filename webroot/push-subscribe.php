<?php
/**
 * POST /push/subscribe  (Looth Web Push subscription sink)
 *
 * HOME: docroot /var/www/dev/push-subscribe.php (buck's lane). It bootstraps WP
 * via wp-load.php to get $wpdb + the logged-in WP user, then upserts the browser
 * PushSubscription into the WP-MySQL table wp_lg_push_subscriptions (schema GREENLIT
 * by ubuntu, rowid 142). Dedup key = endpoint_hash = sha256(endpoint).
 *
 * ROUTING: rides the existing docroot `location ~ \.php$` PHP-FPM handler — no new
 * nginx rule needed. Coordinator only has to map the pretty path /push/subscribe ->
 * /push-subscribe.php IF they want the clean URL; pwa.js can POST to the bare
 * /push-subscribe.php directly, so even that is optional. (Confirm with ubuntu
 * before going live — this file is staged in _audit until then.)
 *
 * AUTH: identity is the WP login cookie (same-origin fetch carries it). wp_user_id
 * + user_uuid are recorded when present, but an anonymous claimed device may still
 * subscribe (wp_user_id NULL) so push works pre-login; the sender side decides who
 * to target. No secret is ever emitted here; the VAPID PRIVATE key stays server-side
 * at /etc/lg-vapid/ and is never touched by this endpoint.
 *
 * CONTRACT:
 *   Request  : POST application/json
 *              { endpoint, keys:{ p256dh, auth }, ua? }   (the standard
 *              PushSubscription.toJSON() shape, optional UA string)
 *   Response : 200 {"ok":true,"deduped":bool}  on store
 *              400 {"ok":false,"error":"..."}  on bad payload
 *              405 on non-POST
 *   No emoji. Self-contained. No deps beyond WP core.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
// Same-origin only; never expose the sink cross-site.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// --- Parse + validate the PushSubscription payload -------------------------
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 8192) {
    out(400, ['ok' => false, 'error' => 'empty_or_oversize_body']);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    out(400, ['ok' => false, 'error' => 'bad_json']);
}

$endpoint = isset($data['endpoint']) && is_string($data['endpoint']) ? trim($data['endpoint']) : '';
$keys     = isset($data['keys']) && is_array($data['keys']) ? $data['keys'] : [];
$p256dh   = isset($keys['p256dh']) && is_string($keys['p256dh']) ? trim($keys['p256dh']) : '';
$auth     = isset($keys['auth']) && is_string($keys['auth']) ? trim($keys['auth']) : '';
$ua       = isset($data['ua']) && is_string($data['ua']) ? substr(trim($data['ua']), 0, 255) : '';

// Endpoint must be an https push-service URL; keys must be present.
if ($endpoint === '' || stripos($endpoint, 'https://') !== 0 || strlen($endpoint) > 2048) {
    out(400, ['ok' => false, 'error' => 'bad_endpoint']);
}
if ($p256dh === '' || $auth === '' || strlen($p256dh) > 255 || strlen($auth) > 255) {
    out(400, ['ok' => false, 'error' => 'bad_keys']);
}
// Keys are URL-safe base64 (PushSubscription contract). Reject anything else.
$b64url = '/^[A-Za-z0-9_-]+={0,2}$/';
if (!preg_match($b64url, $p256dh) || !preg_match($b64url, $auth)) {
    out(400, ['ok' => false, 'error' => 'bad_keys']);
}

$endpoint_hash = hash('sha256', $endpoint); // 64-char hex = the dedup key

// --- Bootstrap WP for $wpdb + the logged-in user ----------------------------
// Full bootstrap (no SHORTINIT) so the pluggable auth API is available and
// wp_get_current_user() resolves the same-origin login cookie. A subscribe call
// fires at most once per device, so the heavier load is fine here.
$wp_load = __DIR__ . '/wp-load.php';
if (!is_readable($wp_load)) {
    out(500, ['ok' => false, 'error' => 'wp_unavailable']);
}
require $wp_load;

/** @var wpdb $wpdb */
global $wpdb;
if (!isset($wpdb)) {
    out(500, ['ok' => false, 'error' => 'db_unavailable']);
}

// Identity is best-effort: an anonymous (claimed-but-not-logged-in) device may
// still subscribe so push works pre-login. wp_user_id/user_uuid stay NULL then.
$wp_user_id = null;
$user_uuid  = null;
if (function_exists('wp_get_current_user')) {
    $u = wp_get_current_user();
    if ($u && !empty($u->ID)) {
        $wp_user_id = (int) $u->ID;
        // looth_uuid is cached in WP usermeta by the profile-app JWT bridge.
        $meta = get_user_meta($wp_user_id, 'looth_uuid', true);
        if (is_string($meta) && $meta !== '') {
            $user_uuid = substr($meta, 0, 36);
        }
    }
}

$table = $wpdb->prefix . 'lg_push_subscriptions';
$now   = gmdate('Y-m-d H:i:s');

// wp_user_id is a strict int (cast) or the literal NULL; user_uuid is matched
// against a UUID shape before use, else NULL. Both are therefore injection-safe to
// inline directly, which sidesteps wpdb::prepare()'s inability to bind a NULL.
$uid_sql  = $wp_user_id === null ? 'NULL' : (string) (int) $wp_user_id;
$uuid_sql = ($user_uuid !== null && preg_match('/^[0-9a-fA-F-]{1,36}$/', $user_uuid))
    ? "'" . $user_uuid . "'"
    : 'NULL';

// --- Upsert on endpoint_hash (UNIQUE) --------------------------------------
// Refresh keys + last_seen on a repeat subscription; insert otherwise. Single
// INSERT ... ON DUPLICATE KEY UPDATE so it is race-safe under the unique key.
// Only user-supplied strings go through prepare()'s %s binding; the two identity
// columns are pre-sanitized literals (above).
$sql = $wpdb->prepare(
    "INSERT INTO {$table}
        (endpoint, endpoint_hash, p256dh, auth, wp_user_id, user_uuid, ua, created_at, last_seen_at)
     VALUES (%s, %s, %s, %s, {$uid_sql}, {$uuid_sql}, %s, %s, %s)
     ON DUPLICATE KEY UPDATE
        p256dh = VALUES(p256dh),
        auth = VALUES(auth),
        wp_user_id = VALUES(wp_user_id),
        user_uuid = VALUES(user_uuid),
        ua = VALUES(ua),
        last_seen_at = VALUES(last_seen_at)",
    $endpoint,
    $endpoint_hash,
    $p256dh,
    $auth,
    $ua,
    $now,
    $now
);

$res = $wpdb->query($sql);
if ($res === false) {
    out(500, ['ok' => false, 'error' => 'store_failed']);
}

// $res === 1 -> fresh insert; $res === 2 -> existing row updated (MySQL semantics).
out(200, ['ok' => true, 'deduped' => ((int) $res) === 2]);
