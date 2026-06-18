<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Mutes.php';

/**
 * Mutes endpoint. Backend: src/Mutes.php. Identity via Auth::requireUser().
 *   GET    /profile-api/v0/me/mutes          -> { muted: [uuid, ...] }
 *   POST   /profile-api/v0/me/mutes          -> mute    body { uuid }
 *   DELETE /profile-api/v0/me/mutes/<uuid>   -> unmute
 * Routes + table provisioned by the coordinator (POST/GET /me/mutes, DELETE /me/mutes/<uuid>).
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Mutes;

$user   = Auth::requireUser();
$uuid   = $user['uuid'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    profile_app_json(200, ['muted' => Mutes::listMutedBy($uuid)]);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    $in = is_array($in) ? $in : [];
    $target = trim((string)($in['uuid'] ?? ''));
    if ($target === '') profile_app_json(400, ['error' => 'uuid_required']);
    $res = Mutes::mute($uuid, $target);
    profile_app_json($res['ok'] ? 200 : 400, $res);
}

if ($method === 'DELETE') {
    $target = '';
    if (preg_match('~/mutes/([0-9a-fA-F-]{36})~', (string)($_SERVER['REQUEST_URI'] ?? ''), $m)) {
        $target = $m[1];
    }
    if ($target === '' && trim((string)($_GET['uuid'] ?? '')) !== '') {
        $target = trim((string)$_GET['uuid']);
    }
    if ($target === '') profile_app_json(400, ['error' => 'uuid_required']);
    $res = Mutes::unmute($uuid, $target);
    profile_app_json($res['ok'] ? 200 : 400, $res);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
