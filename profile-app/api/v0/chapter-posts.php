<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Chapters.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/DiscoveryComments.php';

/**
 * DISCUSSIONS — the single chapter content surface (Ian 2026-07-12: "everything can be done
 * from discussions"). One row = one discussion topic; it carries both the durable announcement
 * and the throwaway chatter.  Backend: src/Chapters.php.
 *
 *   GET    /profile-api/v0/chapters/<slug>/posts        -> { posts: [...] }   (public)
 *   POST   /profile-api/v0/chapters/<slug>/posts        -> { ok, id }          (members only)
 *          body { title?, body }
 *   DELETE /profile-api/v0/chapters/<slug>/posts?id=N   -> { ok }              (author or admin)
 *
 * Read is PUBLIC (chapters are public + browsable). Starting a discussion requires membership,
 * and joining is one tap — read = anyone, post = members (recommended rule, pending Ian).
 *
 * comment_count on each discussion is its REPLY count, from discovery.comments in the OTHER
 * database, batched into ONE query by DiscoveryComments::countsFor(). Never N+1, never a
 * cross-DB JOIN (there is no fdw/dblink on this cluster — a JOIN is not slow, it is impossible).
 *
 * NOTE TO COORDINATOR — nginx:
 *   rewrite "^/profile-api/v0/chapters/([\w\-]+)/posts/?$" /profile-api/v0/chapter-posts.php?slug=$1&$args last;
 *   + `chapter-posts` in the PUBLIC allowlist regex.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Chapters;
use Looth\ProfileApp\Visibility;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$slug   = trim((string)($_GET['slug'] ?? ''));
$ch     = Chapters::bySlug($slug);
if (!$ch) profile_app_json(404, ['error' => 'no_such_chapter']);
$cid = (int)$ch['id'];

if ($method === 'GET') {
    $limit  = isset($_GET['limit'])  ? max(1, min(50, (int)$_GET['limit'])) : 20;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    profile_app_json(200, ['posts' => Chapters::posts($cid, $limit, $offset)]);
}

if ($method === 'POST') {
    $user = Auth::requireUser();
    $uuid = $user['uuid'];
    if (!Chapters::isMember($cid, $uuid)) {
        // Not a 403-with-a-shrug: the client turns this into the one-tap Join prompt.
        profile_app_json(403, ['error' => 'not_a_member', 'join_required' => true]);
    }
    profile_app_rate_gate('chapter-post:' . $uuid, 10, 3600);   // 10 discussions/hour/member

    $in    = json_decode(file_get_contents('php://input') ?: '', true);
    $in    = is_array($in) ? $in : [];
    $title = isset($in['title']) ? (string)$in['title'] : null;
    $body  = (string)($in['body'] ?? '');

    $res = Chapters::createPost($cid, $uuid, $title, $body);
    profile_app_json($res['ok'] ? 200 : 400, $res);
}

if ($method === 'DELETE') {
    $user = Auth::requireUser();
    $id   = (int)($_GET['id'] ?? 0);
    if ($id <= 0) profile_app_json(400, ['error' => 'bad_id']);

    $ok = Chapters::deletePost($id, $user['uuid'], (bool)Visibility::viewer()['admin']);
    profile_app_json($ok ? 200 : 403, $ok ? ['ok' => true] : ['error' => 'not_yours']);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
