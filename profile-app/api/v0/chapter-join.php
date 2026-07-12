<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Chapters.php';

/**
 * Join / leave a chapter. ONE TAP, self-serve, NO APPROVAL (Ian 2026-07-12).
 * Backend: src/Chapters.php.
 *
 *   POST   /profile-api/v0/chapters/<slug>/join   -> { ok, is_member: true,  member_count }
 *   DELETE /profile-api/v0/chapters/<slug>/join   -> { ok, is_member: false, member_count }
 *
 * There is no pending state, no request, no approval and no role — deliberately. Join is
 * idempotent (ON CONFLICT DO NOTHING), so a double-tap is a no-op rather than an error.
 *
 * CSRF: inherited from _bootstrap.php (Origin check + SameSite=Lax). No nonce needed.
 *
 * NOTE TO COORDINATOR — nginx:
 *   rewrite "^/profile-api/v0/chapters/([\w\-]+)/join/?$" /profile-api/v0/chapter-join.php?slug=$1 last;
 *   + add `chapter-join` to the PUBLIC allowlist regex (auth is enforced HERE, by
 *     requireUser(), so it does not need the /me allowlist's blanket 403).
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Chapters;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' && $method !== 'DELETE') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

$user = Auth::requireUser();            // 401 for anonymous
$uuid = $user['uuid'];

$slug = trim((string)($_GET['slug'] ?? ''));
$ch   = Chapters::bySlug($slug);
if (!$ch) profile_app_json(404, ['error' => 'no_such_chapter']);

$cid = (int)$ch['id'];

if ($method === 'POST') {
    Chapters::join($cid, $uuid);
} else {
    Chapters::leave($cid, $uuid);
}

profile_app_json(200, [
    'ok'           => true,
    'is_member'    => $method === 'POST',
    'member_count' => Chapters::memberCount($cid),
]);
