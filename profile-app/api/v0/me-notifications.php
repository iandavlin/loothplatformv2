<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';

/**
 * Notifications endpoint (bell feed + mark-read + delete). Plan: social-layer §4.
 * Backend: src/Notifications.php. lg-shell's bell + modal call this; profile-app
 * owns the data. Identity via Auth::requireUser() (/whoami).
 *   GET    → { items: [ { id, type, actor{uuid,name,avatar_url,slug}, ref{kind,id},
 *                         is_read, created_at } ], unread: int }   (recent-first)
 *   POST   → { action: 'read', id }  | { action: 'read_all' }      → marks read
 *   DELETE → { id }  (or ?id=)   → delete ONE (404 if not the caller's / gone)
 *          → { all: true } (or ?all=1) → delete ALL of the caller's (Clear-all)
 *            An id-less / all-less DELETE is 400 — never mass-delete by omission.
 * DELETE rides the SAME collection route as GET/POST (id/all in query or body),
 * so no new nginx path-capture is needed. Owner scoping is in the model
 * (WHERE user_uuid); a non-owner id simply deletes nothing → 404. This is the
 * REAL delete that retired the mobile client "watermark" (cleared = gone
 * server-side, on every device), and the desktop per-row × now removes for good.
 * Counts for the header badge come from me-social-counts.
 * Retention: 30-day prune is a cron (bin/prune-notifications), NOT this endpoint.
 *
 * NOTE TO COORDINATOR — nginx route (unchanged; DELETE reuses the collection route):
 *   rewrite ^/profile-api/v0/me/notifications/?$ /profile-api/v0/me-notifications.php last;
 *   …and add `me-notifications` to the allowlist regex in strangler-profile-app.conf.
 *   The route must NOT limit_except GET/POST — DELETE has to reach PHP.
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

if ($method === 'DELETE') {
    // id/all may arrive in the query (?id= / ?all=1) or a JSON body — accept both;
    // some proxies drop DELETE bodies, so the query is the belt.
    $in  = json_decode(file_get_contents('php://input') ?: '', true);
    $in  = is_array($in) ? $in : [];
    $all = !empty($_GET['all']) || !empty($in['all']);
    $id  = (int)($_GET['id'] ?? $in['id'] ?? 0);

    if ($all) {
        // Clear-all: DELETEs server-side (the retired watermark's real replacement).
        profile_app_json(200, ['ok' => true, 'deleted' => Notifications::deleteAll($uuid)]);
    }
    if ($id > 0) {
        // Owner-scoped in the model; not-yours / already-gone are one 404 (deny model).
        if (!Notifications::delete($uuid, $id)) profile_app_json(404, ['error' => 'not_found']);
        profile_app_json(200, ['ok' => true]);
    }
    // Neither id nor all → refuse, so a malformed request can never wipe the list.
    profile_app_json(400, ['error' => 'id_or_all_required']);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
