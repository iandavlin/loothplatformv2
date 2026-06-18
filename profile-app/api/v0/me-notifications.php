<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';

/**
 * Notifications endpoint (bell feed + mark-read). Plan: social-layer §4.
 * Backend: src/Notifications.php. lg-shell's bell + modal call this; profile-app
 * owns the data. Identity via Auth::requireUser() (/whoami).
 *   GET  → { items: [ { id, type, actor{uuid,name,avatar_url,slug}, ref{kind,id},
 *                       is_read, created_at } ], unread: int }   (recent-first)
 *   POST → { action: 'read', id }  | { action: 'read_all' }      → marks read
 * Counts for the header badge come from me-social-counts.
 * Retention: 30-day prune is a cron (bin/prune-notifications), NOT this endpoint.
 *
 * NOTE TO COORDINATOR — nginx route:
 *   rewrite ^/profile-api/v0/me/notifications/?$ /profile-api/v0/me-notifications.php last;
 *   …and add `me-notifications` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Notifications;

$user   = Auth::requireUser();
$uuid   = $user['uuid'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    profile_app_json(200, [
        'items'  => Notifications::listFor($uuid),
        'unread' => Notifications::unreadCount($uuid),
    ]);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    $in = is_array($in) ? $in : [];
    $action = (string)($in['action'] ?? '');
    if ($action === 'read') {
        $id = (int)($in['id'] ?? 0);
        if (!$id) profile_app_json(400, ['error' => 'id_required']);
        Notifications::markRead($uuid, $id);
        profile_app_json(200, ['ok' => true]);
    }
    if ($action === 'read_all') {
        Notifications::markAllRead($uuid);
        profile_app_json(200, ['ok' => true]);
    }
    profile_app_json(400, ['error' => 'bad_action', 'allowed' => ['read', 'read_all']]);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
