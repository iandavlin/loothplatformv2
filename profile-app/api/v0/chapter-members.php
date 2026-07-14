<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Chapters.php';

/**
 * Chapter member ROSTER (CHAPTER-V2 ask 2, Ian 2026-07-14). Backend: Chapters::members().
 *
 *   GET /profile-api/v0/chapters/<slug>/members?page=N
 *     -> { members:[{uuid,display_name,slug,avatar_url}], page, has_more, member_count }
 *
 * PUBLIC read (chapters are public + browsable). Visibility is enforced SERVER-SIDE inside the
 * query (Chapters::members): ghost containment (unbridged = not a person) + the master switch +
 * the header ceiling, resolved through the same audience truth table the directory uses. Anon
 * sees only public-header members; a signed-in member also sees members-header; owner/admin all.
 * Identity only — no location, no coordinate is ever returned here (that is the clamped pins path).
 *
 * NOTE TO COORDINATOR — nginx (platform/nginx/strangler-profile-app.conf):
 *   rewrite "^/profile-api/v0/chapters/([\w\-]+)/members/?$" /profile-api/v0/chapter-members.php?slug=$1&$args last;
 *   MUST sit ABOVE the generic "chapters/([\w\-]+)/?$" -> chapter.php rewrite (more specific first).
 *   + add `chapter-members` to the PUBLIC allowlist regex (NOT the /me one — anon must read).
 */

use Looth\ProfileApp\Chapters;
use Looth\ProfileApp\Visibility;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

$slug = trim((string)($_GET['slug'] ?? ''));
$ch   = Chapters::bySlug($slug);
if (!$ch) profile_app_json(404, ['error' => 'no_such_chapter']);
$cid  = (int)$ch['id'];

$vArr         = Visibility::viewer();   // ['id','uuid','admin']; id=0 for anonymous
$viewerUserId = (int)$vArr['id'];
$isAdmin      = (bool)$vArr['admin'];

$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = isset($_GET['page_size']) ? max(1, min(100, (int)$_GET['page_size'])) : 60;
$offset   = ($page - 1) * $pageSize;

// Fetch one extra row to derive has_more without a second COUNT query.
$rows     = Chapters::members($cid, $viewerUserId, $isAdmin, $pageSize + 1, $offset);
$has_more = count($rows) > $pageSize;
if ($has_more) array_pop($rows);

profile_app_json(200, [
    'members'      => $rows,
    'page'         => $page,
    'has_more'     => $has_more,
    // The count is the full population (Chapters::memberCount, ghost-gated) — it can exceed the
    // number of rows returned, because members-only / private-header members are counted but not
    // listed to this viewer. Same list-vs-count split we ship for map pins.
    'member_count' => Chapters::memberCount($cid),
]);
