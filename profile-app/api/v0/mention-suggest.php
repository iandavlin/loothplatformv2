<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Visibility;

/**
 * @mention autocomplete.
 *
 *   GET /profile-api/v0/mention-suggest?q=<text>
 *       200 { items: [ {uuid, slug, display_name, avatar_url}, … ] }   (max 8)
 *
 * WHY THIS LIVES HERE and not in bb-mirror's existing /hub/?suggest=author:
 * that endpoint reads `forums.person`, which is a CACHE of the WP user_nicename. The
 * nicename is NOT the handle a member controls — the handle is profile_app.users.slug.
 * Suggesting from the cache would hand the composer a stale name and re-create the exact
 * bug this lane exists to fix. Mention suggestions must come from the identity store, live.
 *
 * MATCHES on slug and display_name — a member types "@mark" and finds "markus" whether
 * they are thinking of the handle or the human. Prefix matches rank above substring ones,
 * so "@mar" offers "markus" before "guitars-by-mar".
 *
 * VISIBILITY: private profiles are never suggested (their /u/ page 404s — offering them
 * would be a dead mention and an identity leak). Members-only is fine: this endpoint is
 * members-only itself. Archived members are excluded.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

// Members only. Same reasoning as api/v0/users.php: an open handle→identity endpoint is a
// scrapeable member directory. Loopback (our own server-side consumers) is exempt.
$viewer   = Visibility::viewer();
$internal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$internal && (int) ($viewer['id'] ?? 0) === 0) {
    profile_app_json(401, ['error' => 'auth_required']);
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$q = ltrim($q, '@');                       // the composer may send the '@' along
if (mb_strlen($q) < 2) profile_app_json(200, ['items' => [], 'q' => $q]);
if (mb_strlen($q) > 40) $q = mb_substr($q, 0, 40);

// Escape LIKE wildcards so a member typing "%" doesn't match everyone.
$esc  = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q));
$pre  = $esc . '%';
$mid  = '%' . $esc . '%';

$st = Db::pg()->prepare("
    SELECT uuid, slug, display_name, avatar_url,
           CASE WHEN lower(slug) LIKE :pre1 THEN 0
                WHEN lower(display_name) LIKE :pre2 THEN 1
                ELSE 2 END AS rank
    FROM users
    WHERE archived_at IS NULL
      AND slug IS NOT NULL
      AND profile_visibility <> 'private'
      AND (lower(slug) LIKE :mid1 ESCAPE '\\' OR lower(display_name) LIKE :mid2 ESCAPE '\\')
    ORDER BY rank, length(slug), lower(slug)
    LIMIT 8
");
$st->execute([':pre1' => $pre, ':pre2' => $pre, ':mid1' => $mid, ':mid2' => $mid]);

$items = [];
while ($r = $st->fetch()) {
    $items[] = [
        'uuid'         => (string) $r['uuid'],
        'slug'         => (string) $r['slug'],
        'display_name' => $r['display_name'] !== null ? (string) $r['display_name'] : null,
        'avatar_url'   => $r['avatar_url']   !== null ? (string) $r['avatar_url']   : null,
    ];
}

profile_app_json(200, ['items' => $items, 'q' => $q, 'count' => count($items)]);
