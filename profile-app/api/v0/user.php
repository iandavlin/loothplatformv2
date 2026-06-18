<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$uuid = $_GET['uuid'] ?? '';
if (!is_string($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    profile_app_json(400, ['error' => 'invalid_uuid']);
}
$uuid = strtolower($uuid);

$idStmt = Db::pg()->prepare('SELECT id, profile_visibility FROM users WHERE uuid = :u');
$idStmt->execute([':u' => $uuid]);
$idRow = $idStmt->fetch();
if (!$idRow) profile_app_json(404, ['error' => 'not_found']);
$id = (int)$idRow['id'];

// MASTER SWITCH (Visibility module, Ian 6/12): a private profile answers exactly
// like a uuid that doesn't exist — owner + admin excepted.
$vArr = \Looth\ProfileApp\Visibility::viewer();
if (!\Looth\ProfileApp\Visibility::profileVisible($vArr, ['id' => $id, 'profile_visibility' => (string)$idRow['profile_visibility']])) {
    profile_app_json(404, ['error' => 'not_found']);
}

$full = Profile::loadFull($id);

// Viewer role from the one module: me | admin | member | public.
$viewer = Auth::currentUser();
$role   = \Looth\ProfileApp\Visibility::role($vArr, $id);

profile_app_json(200, Profile::renderForViewer($full, $role, $viewer ? (int)$viewer['id'] : 0, (int)$id));
