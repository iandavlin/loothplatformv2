<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Messaging.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Connections.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';

/**
 * Lightweight badge counts for the shared header. Feeds the lazy-loaded messages /
 * friends / bell badges. Cheap; cache ~30s. Counts are TRUE integers — lg-shell
 * renders the "9+" display cap (Ian, 2026-05-30). Plan: social-layer §4.
 *   GET → { messages_unread: int, requests_pending: int, notifications_unread: int }
 *
 * NOTE TO COORDINATOR — nginx route + lg-shell contract:
 *   rewrite ^/profile-api/v0/me/social-counts/?$ /profile-api/v0/me-social-counts.php last;
 *   add `me-social-counts` to the allowlist regex in strangler-profile-app.conf.
 *   lg-shell owns the "9+" cap on these raw integers (per Ian's stub — this
 *   supersedes the relay's display-object spec).
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Messaging;
use Looth\ProfileApp\Connections;
use Looth\ProfileApp\Notifications;

$user = Auth::requireUser();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$uuid = $user['uuid'];
profile_app_json(200, [
    'messages_unread'      => Messaging::unreadCount($uuid),
    'requests_pending'     => Connections::pendingCount($uuid),
    'notifications_unread' => Notifications::unreadCount($uuid),
]);
