<?php
/**
 * profile-api-router.php — nginx's /profile-api/v0/me/* rewrites, for php -S.
 *
 * Dispatches into the BRANCH's api/v0 by absolute path. That matters: config.php
 * sets LG_PROFILE_APP_APP_ROOT = __DIR__, so requiring the branch's endpoint makes
 * the branch's src/ answer too. Requiring /srv's copy would silently test main —
 * the "a lane verifying on dev2 is usually testing MAIN" trap.
 */
$BR = getenv('RRT_BRANCH') ?: '/home/ubuntu/worktrees/recap-read-timer';
$API = $BR . '/profile-app/api/v0';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$map = [
    '#^/profile-api/v0/me/notifications/?$#' => 'me-notifications.php',
    '#^/profile-api/v0/me/social-counts/?$#' => 'me-social-counts.php',
    '#^/profile-api/v0/whoami/?$#'           => 'whoami.php',
];
foreach ($map as $re => $file) {
    if (preg_match($re, $path)) {
        $f = $API . '/' . $file;
        if (!is_file($f)) { http_response_code(500); echo json_encode(['error' => 'missing', 'f' => $f]); return true; }
        require $f;
        return true;
    }
}
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'no_route', 'path' => $path]);
return true;
