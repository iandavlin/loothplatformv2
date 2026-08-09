<?php
/**
 * notif-endpoint-request.php — issue ONE real request at me-notifications.php.
 *
 *   php notif-endpoint-request.php <repo-root> <token> <METHOD> [id=N|all=1] [json-body]
 *
 * notif-bridge lane, 2026-08-09. The runner behind tools/gates/notif-endpoint-gate.sh.
 *
 * WHY IN-PROCESS AND NOT curl: the served copy of profile-app is /srv/profile-app,
 * which symlinks into the SERVING checkout — so every HTTP request tests `main`,
 * whatever branch you are on (trap-harness-and-serve-answer-from-main). This
 * includes the endpoint file from the REPO ROOT it is given, so the code under test
 * is the code in the working tree.
 *
 * It is a real request in every way that matters: the endpoint's own _bootstrap runs,
 * Auth::requireUser() verifies a genuinely-minted looth_id, and the CSRF guard is
 * exercised (it deliberately allows an absent Origin so non-browser harnesses work —
 * _bootstrap.php:68-74). What it does NOT reproduce is the FPM SAPI; that matters
 * only for code that branches on PHP_SAPI, and the flag reader deliberately does not.
 *
 * One request per process, because profile_app_json() exits.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$root   = $argv[1] ?? '';
$token  = $argv[2] ?? '';
$method = strtoupper($argv[3] ?? 'GET');
$query  = $argv[4] ?? '';
$body   = $argv[5] ?? '';

$endpoint = rtrim($root, '/') . '/profile-app/api/v0/me-notifications.php';
if (!is_file($endpoint)) { fwrite(STDERR, "no endpoint at $endpoint\n"); exit(2); }

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['HTTP_HOST']      = 'dev2.loothgroup.com';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_URI']    = '/profile-api/v0/me/notifications/' . ($query !== '' ? '?' . $query : '');
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
// Deliberately NO Origin and NO Referer — see the CSRF note above.
unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);

parse_str($query, $_GET);
$_COOKIE['looth_id'] = $token;

// A DELETE body is read with file_get_contents('php://input'), which cannot be
// faked from CLI — so this runner drives the QUERY form of the contract. That is
// the belt the endpoint itself documents as primary ("some proxies drop DELETE
// bodies, so the query is the belt", me-notifications.php:63), and it is the form
// both surfaces actually send.
if ($body !== '') { fwrite(STDERR, "note: body ignored; use the query form\n"); }

require $endpoint;
