<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in   = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['order']) || !is_array($in['order'])) {
    profile_app_json(400, ['error' => 'order_required']);
}

// Implicit-claim is fine here — saving an order presumes the user is engaged.
if (!Profile::hasClaimed((int)$user['id'])) Profile::claim((int)$user['id'], 'direct');

$saved = Profile::setSectionOrder((int)$user['id'], $in['order']);
profile_app_json(200, ['ok' => true, 'order' => $saved]);
