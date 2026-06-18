<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Connections.php';

/**
 * Connections endpoint. Plan: social-layer §4. Backend: src/Connections.php.
 * Identity via Auth::requireUser() (/whoami).
 *
 *   GET   /profile-api/v0/me/connections          → { accepted[], pending_in[], pending_out[] }
 *   GET   /profile-api/v0/me/connections/pending   → { pending_in[] }            (?filter=pending)
 *   POST  /profile-api/v0/connections              → request   body { addressee_uuid }
 *   PATCH /profile-api/v0/connections/<id>         → { action: accept|decline|cancel|block }
 *
 * NOTE TO COORDINATOR — nginx routes (same pattern as me-craft):
 *   rewrite ^/profile-api/v0/me/connections/pending/?$ /profile-api/v0/me-connections.php?filter=pending last;
 *   rewrite ^/profile-api/v0/me/connections/?$         /profile-api/v0/me-connections.php last;
 *   rewrite ^/profile-api/v0/connections/?$            /profile-api/v0/me-connections.php last;          # POST
 *   rewrite ^/profile-api/v0/connections/([0-9]+)/?$   /profile-api/v0/me-connections.php?id=$1 last;     # PATCH
 *   …and add `me-connections` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Connections;

$user   = Auth::requireUser();
$uuid   = $user['uuid'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $groups = Connections::listFor($uuid);
    if (($_GET['filter'] ?? '') === 'pending') {
        profile_app_json(200, ['pending_in' => $groups['pending_in']]);
    }
    profile_app_json(200, $groups);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
$in = is_array($in) ? $in : [];

if ($method === 'POST') {
    $to = trim((string)($in['addressee_uuid'] ?? ''));
    if ($to === '') profile_app_json(400, ['error' => 'addressee_uuid_required']);
    $res = Connections::request($uuid, $to);
    profile_app_json($res['ok'] ? 200 : 409, $res);
}

if ($method === 'PATCH') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id && preg_match('~/connections/(\d+)~', (string)($_SERVER['REQUEST_URI'] ?? ''), $m)) {
        $id = (int)$m[1];
    }
    if (!$id) profile_app_json(400, ['error' => 'connection_id_required']);

    $action = (string)($in['action'] ?? '');
    $res = match ($action) {
        'accept'  => Connections::accept($id, $uuid),
        'decline' => Connections::decline($id, $uuid),
        'cancel'  => Connections::cancel($id, $uuid),
        'block'   => Connections::block($id, $uuid),
        'disconnect' => Connections::disconnect($id, $uuid),
        default   => null,
    };
    if ($res === null) {
        profile_app_json(400, ['error' => 'bad_action', 'allowed' => ['accept', 'decline', 'cancel', 'block', 'disconnect']]);
    }
    profile_app_json($res['ok'] ? 200 : 409, $res);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
