<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
// Slice 1.5: no auto-claim here. /me/claim is the explicit action; the editor
// renders a claim interstitial when ['claimed' => false] comes back.
profile_app_json(200, Profile::loadFull((int)$user['id']));
