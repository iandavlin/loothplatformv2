<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';

/**
 * Header status lights (availability widgets).
 *   GET → { lights:[{key,state,label,tone}], registry:<HEADER_LIGHTS>, available:[keys] }
 *   PUT → { key, state }  — set the light's state; state null/'' removes the light.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

$user   = Auth::requireUser();
$uid    = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    profile_app_json(200, [
        'lights'    => Block::loadHeaderLights($uid),
        'registry'  => Block::HEADER_LIGHTS,
        'available' => array_keys(Block::availableLights($uid)),
    ]);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['key']) || !is_string($in['key'])) {
    profile_app_json(400, ['error' => 'key_required']);
}
$state = array_key_exists('state', $in) && $in['state'] !== '' ? (string) $in['state'] : null;

$lights = Block::saveHeaderLight($uid, $in['key'], $state);
if ($lights === null) profile_app_json(400, ['error' => 'invalid_light']);

profile_app_json(200, ['ok' => true, 'lights' => $lights, 'available' => array_keys(Block::availableLights($uid))]);
