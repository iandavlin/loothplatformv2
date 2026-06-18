<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in   = json_decode(file_get_contents('php://input') ?: 'null', true);
$via  = is_array($in) ? ($in['via'] ?? null) : null;

$allowed = ['menu','banner','public_view','direct','import'];
if ($via !== null && !in_array($via, $allowed, true)) {
    profile_app_json(400, ['error' => 'invalid_via']);
}
$via = $via ?: 'direct';

if (Profile::hasClaimed((int)$user['id'])) {
    profile_app_json(200, ['claimed' => false, 'existing' => true]);
}

Profile::claim((int)$user['id'], $via);
profile_app_json(200, ['claimed' => true, 'via' => $via]);
