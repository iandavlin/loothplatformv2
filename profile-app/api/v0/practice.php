<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Practice;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$uuid = $_GET['uuid'] ?? '';
if (!is_string($uuid) || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
    profile_app_json(400, ['error' => 'uuid_required']);
}
$practice = Practice::loadByUuid($uuid);
if (!$practice) profile_app_json(404, ['error' => 'not_found']);

$viewer   = Auth::currentUser();
$viewerId = $viewer ? (int)$viewer['id'] : 0;

$rendered = Practice::renderForViewer($practice, $viewerId);
$rendered['members'] = Practice::members($practice['id']);
$rendered['public_url'] = '/p/' . $practice['slug'];

profile_app_json(200, ['ok' => true, 'practice' => $rendered]);
