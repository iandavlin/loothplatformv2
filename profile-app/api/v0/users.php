<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

// LOCKED DOWN (Ian 6/12): this was an open uuid→identity oracle — anyone with a
// collected uuid could batch-resolve names/avatars/bios anonymously, forever.
// Allowed callers now: logged-in members, and our own server-side consumers
// (archive-poc comments, bb-mirror person-sync / hub-filters), which all call
// via loopback (https://127.0.0.1/... → REMOTE_ADDR 127.0.0.1). Nothing a
// browser legitimately does changes.
$lgUsersViewer   = \Looth\ProfileApp\Visibility::viewer();
$lgUsersInternal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$lgUsersInternal && $lgUsersViewer['id'] === 0) {
    profile_app_json(401, ['error' => 'auth_required']);
}

// Batch user lookup. Resolves author identity for many users at once (BB-mirror
// threads, author bylines, etc.). Single round-trip, cap at 100.
//
//   ?uuids=<csv of uuid>    → key by uuid (original; for surfaces holding uuids)
//   ?wp_ids=<csv of int>    → key by wp_user_id, resolved via wp_user_bridge, and
//                             each item ECHOES wp_user_id so WP-side consumers (which
//                             only know the post author's wp id, not its uuid) can map
//                             results back. Unblocks author-bio single-source inside WP.
//
// uuids wins if both are present.
$rawUuids = $_GET['uuids']  ?? '';
$rawWpIds = $_GET['wp_ids'] ?? '';
$byWp     = (!is_string($rawUuids) || $rawUuids === '') && is_string($rawWpIds) && $rawWpIds !== '';

$shape = static function (array $r) use ($byWp, $lgUsersViewer, $lgUsersInternal): array {
    $isPrivate = (($r['profile_visibility'] ?? 'public') === 'private');
    $item = [
        'uuid'         => $r['uuid'],
        // A private profile's /u/ page 404s for everyone but owner/admin — don't
        // hand out a dead link. Identity fields stay: bylines on forum/comment
        // surfaces are governed by discussion_visibility, not the master switch.
        'slug'         => ($isPrivate && !$lgUsersInternal && empty($lgUsersViewer['admin'])) ? null : ($r['slug'] ?: null),
        'display_name' => $r['display_name'] ?? null,
        'avatar_url'   => $r['avatar_url'] ?? null,
        'bio'          => $r['at_a_glance'] ?? null,   // single-source author bio → bylines/author box
        // Discussion-author mask preference (public|member, default member). Carried so the
        // archive-poc person-sync can copy it into forums.person for the Hub logged-out mask.
        'discussion_visibility' => (($r['discussion_visibility'] ?? 'member') === 'public') ? 'public' : 'member',
        // Master switch passthrough — server-side consumers (person-sync, search)
        // use this to drop private profiles from search/list surfaces.
        'profile_visibility'    => $isPrivate ? 'private' : 'public',
    ];
    if ($byWp) $item['wp_user_id'] = (int) $r['wp_user_id'];   // map back to the post author
    return $item;
};

if ($byWp) {
    $wpIds = [];
    foreach (explode(',', $rawWpIds) as $w) {
        $w = trim($w);
        if ($w !== '' && ctype_digit($w)) $wpIds[(int) $w] = true;
    }
    $wpIds = array_keys($wpIds);
    if (!$wpIds)             profile_app_json(400, ['error' => 'no_valid_wp_ids']);
    if (count($wpIds) > 100) profile_app_json(400, ['error' => 'too_many', 'cap' => 100]);

    $ph = implode(',', array_fill(0, count($wpIds), '?'));
    $st = Db::pg()->prepare("
        SELECT b.wp_user_id, u.uuid, u.slug, u.display_name, u.avatar_url, u.at_a_glance, u.discussion_visibility, u.profile_visibility
        FROM users u
        JOIN wp_user_bridge b ON b.user_id = u.id
        WHERE b.wp_user_id IN ($ph) AND u.archived_at IS NULL
    ");
    $st->execute($wpIds);
} else {
    $raw = $rawUuids;
    if (!is_string($raw) || $raw === '') profile_app_json(400, ['error' => 'uuids_or_wp_ids_required']);

    $uuids = [];
    foreach (explode(',', $raw) as $u) {
        $u = strtolower(trim($u));
        if ($u !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $u)) {
            $uuids[$u] = true;
        }
    }
    $uuids = array_keys($uuids);
    if (!$uuids)             profile_app_json(400, ['error' => 'no_valid_uuids']);
    if (count($uuids) > 100) profile_app_json(400, ['error' => 'too_many', 'cap' => 100]);

    $ph = implode(',', array_fill(0, count($uuids), '?'));
    $st = Db::pg()->prepare("
        SELECT uuid, slug, display_name, avatar_url, at_a_glance, discussion_visibility, profile_visibility
        FROM users
        WHERE uuid IN ($ph) AND archived_at IS NULL
    ");
    $st->execute($uuids);
}

$items = [];
while ($r = $st->fetch()) {
    $items[] = $shape($r);
}

profile_app_json(200, ['items' => $items, 'count' => count($items)]);
