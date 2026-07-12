<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Chapters.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/DiscoveryComments.php';

/**
 * Comments on a chapter ANNOUNCEMENT — stored in the platform's ONE comments store
 * (discovery.comments), NOT in a second one. Backend: src/DiscoveryComments.php, which
 * explains the cross-database seam in full.
 *
 *   GET    /profile-api/v0/chapter-posts/<id>/comments      -> { comments: [...] }  (public)
 *   POST   /profile-api/v0/chapter-posts/<id>/comments      -> { ok, id }           (members)
 *          body { body, parent_id? }
 *   DELETE /profile-api/v0/chapter-posts/<id>/comments?cid=N -> { ok }              (author/admin)
 *
 * Author identity is STITCHED here, not joined: the comments are in the `looth` DB and
 * users is in `profile_app`, and there is no fdw/dblink on this cluster. We collect the
 * distinct uuids from the thread and resolve them in ONE query against profile_app.
 * A comment whose author no longer resolves falls back to the store's denormalized
 * author_name, so a deleted account degrades to a name rather than a crash.
 *
 * NOTE TO COORDINATOR — nginx:
 *   rewrite "^/profile-api/v0/chapter-posts/([0-9]+)/comments/?$" /profile-api/v0/chapter-comments.php?post_id=$1&$args last;
 *   + `chapter-comments` in the PUBLIC allowlist regex.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Chapters;
use Looth\ProfileApp\DiscoveryComments;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Visibility;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$postId = (int)($_GET['post_id'] ?? 0);
if ($postId <= 0) profile_app_json(400, ['error' => 'bad_post_id']);

$post = Chapters::post($postId);
if (!$post) profile_app_json(404, ['error' => 'no_such_post']);
$cid = (int)$post['chapter_id'];

if ($method === 'GET') {
    $rows = DiscoveryComments::thread($postId);
    if (!$rows) profile_app_json(200, ['comments' => []]);

    // Stitch author identity from the OTHER database (one query, not N).
    $uuids = array_values(array_unique(array_filter(array_map(
        static fn ($r) => $r['user_uuid'],
        $rows
    ))));
    $people = [];
    if ($uuids) {
        $ph = [];
        $params = [];
        foreach ($uuids as $i => $u) { $ph[] = ":u$i"; $params[":u$i"] = $u; }
        $st = Db::pg()->prepare(
            'SELECT uuid, display_name, slug, avatar_url FROM users
              WHERE uuid IN (' . implode(',', $ph) . ')'
        );
        $st->execute($params);
        foreach ($st->fetchAll() as $p) $people[$p['uuid']] = $p;
    }

    $out = [];
    foreach ($rows as $r) {
        $p = $people[$r['user_uuid']] ?? null;
        $out[] = [
            'id'         => (int)$r['id'],
            'parent_id'  => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'body'       => $r['body'],
            'created_at' => $r['created_at'],
            'edited_at'  => $r['edited_at'],
            'author'     => [
                'uuid'       => $r['user_uuid'],
                // Fall back to the store's denormalized name for an author who no longer resolves.
                'name'       => $p['display_name'] ?? ($r['author_name'] ?: 'Someone'),
                'slug'       => $p['slug']       ?? null,
                'avatar_url' => $p['avatar_url'] ?? null,
            ],
        ];
    }
    profile_app_json(200, ['comments' => $out]);
}

if ($method === 'POST') {
    $user = Auth::requireUser();
    $uuid = $user['uuid'];
    if (!Chapters::isMember($cid, $uuid)) {
        profile_app_json(403, ['error' => 'not_a_member', 'join_required' => true]);
    }
    profile_app_rate_gate('chapter-comment:' . $uuid, 30, 3600);

    $in       = json_decode(file_get_contents('php://input') ?: '', true);
    $in       = is_array($in) ? $in : [];
    $body     = (string)($in['body'] ?? '');
    $parentId = isset($in['parent_id']) && $in['parent_id'] !== null ? (int)$in['parent_id'] : null;

    $res = DiscoveryComments::add(
        $postId,
        $uuid,
        (string)($user['display_name'] ?? ''),
        $body,
        $parentId
    );
    profile_app_json($res['ok'] ? 200 : 400, $res);
}

if ($method === 'DELETE') {
    $user = Auth::requireUser();
    $commentId = (int)($_GET['cid'] ?? 0);
    if ($commentId <= 0) profile_app_json(400, ['error' => 'bad_comment_id']);

    $ok = DiscoveryComments::remove($commentId, $user['uuid'], (bool)Visibility::viewer()['admin']);
    profile_app_json($ok ? 200 : 403, $ok ? ['ok' => true] : ['error' => 'not_yours']);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
