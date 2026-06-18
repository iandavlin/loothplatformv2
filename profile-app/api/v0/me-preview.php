<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$role = $_GET['as'] ?? 'public';
if (!in_array($role, ['public', 'member', 'friend'], true)) {
    profile_app_json(400, ['error' => 'invalid_role']);
}

$full = Profile::loadFull((int)$user['id']);
// Preview-as-X always passes viewerUserId=0 except for 'friend' (treat as authed).
// The preview is intentionally lossier than the real role — public users would never
// see members-visibility location, so we match that here too.
$viewerId = $role === 'public' ? 0 : (int)$user['id'];
profile_app_json(200, Profile::renderForViewer($full, $role, $viewerId, (int)$user['id']));
