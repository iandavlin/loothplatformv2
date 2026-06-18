<?php
declare(strict_types=1);
/**
 * Hub type-ahead JSON endpoint (routed via index.php on ?suggest=).
 *
 *   /hub/?suggest=hub&q=<text>     -> live search: matching posts + content
 *   /hub/?suggest=author&q=<text>  -> author autocomplete: matching names
 *
 * Unified across forums.topic + discovery.content_item; content is tier-gated to
 * the viewer (same absence model as the feed). Cheap ILIKE substring match —
 * this is a suggest box, not full search (?q= still runs _search.php).
 */

require_once __DIR__ . '/_hub-filters.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$mode = ($_GET['suggest'] ?? '') === 'author' ? 'author' : 'hub';
$q    = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['q' => $q, 'mode' => $mode, 'results' => []]); return; }

$db   = bb_mirror_db();
$like = '%' . $q . '%';

// Allowed content tiers (mirrors _feed.php; admins bypass -> all tiers).
$tiers = hub_content_tiers();
$tph = [];
foreach ($tiers as $i => $t) $tph[] = ':t' . $i;
$tin = $tph ? implode(',', $tph) : "''";

$results = [];

if ($mode === 'author') {
    // VISIBILITY MASK (Ian 6/12 — fork twin of 53f2d9b, which closed the same
    // leak on archive-poc's search-suggest): author identity follows the same
    // flags as everywhere else, read from forums.person (the synced cache;
    // profile-app owns the source fields):
    //   - profile_visibility 'private' (master switch) → never a hit, ANY viewer.
    //   - anon viewers additionally need discussion_visibility = 'public' —
    //     same mask as the Hub feed; a MISSING person row hides the author
    //     from anon (leak-safe), but fails open for the master switch.
    // person.id == WP user id == author_id on both sources. Also joins the
    // avatar for the dropdown.
    $wa     = function_exists('lg_bb_mirror_whoami') ? lg_bb_mirror_whoami() : null;
    $lgAnon = !($wa['authenticated'] ?? false);
    $visWhere = " AND COALESCE(fp.profile_visibility, 'public') <> 'private'";
    if ($lgAnon) $visWhere .= " AND COALESCE(fp.discussion_visibility, 'member') = 'public'";
    // Names group across both sources (the ?author= filter is name-keyed);
    // MAX(author_id) picks the person row for the avatar/visibility join.
    $sql = "
        SELECT z.name, z.n, fp.avatar_url
          FROM (
            SELECT name, MAX(author_id) AS author_id, SUM(n) AS n FROM (
                SELECT author_name AS name, MAX(author_id) AS author_id, count(*) AS n
                  FROM topic
                 WHERE status = 'publish' AND author_name ILIKE :like1
                 GROUP BY author_name
                UNION ALL
                SELECT author_name, MAX(author_id), count(*)
                  FROM discovery.content_item
                 WHERE tier IN ($tin) AND author_name ILIKE :like2
                 GROUP BY author_name
            ) u
             WHERE name IS NOT NULL AND name <> ''
             GROUP BY name
          ) z
          LEFT JOIN forums.person fp ON fp.id = z.author_id
         WHERE 1=1 $visWhere
         ORDER BY z.n DESC, z.name ASC
         LIMIT 8";
    $st = $db->prepare($sql);
    $st->bindValue(':like1', $like);
    $st->bindValue(':like2', $like);
    foreach ($tiers as $i => $t) $st->bindValue(':t' . $i, $t);
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        $results[] = [
            'name'       => (string)$r['name'],
            'n'          => (int)$r['n'],
            'avatar_url' => $r['avatar_url'] !== null ? (string)$r['avatar_url'] : null,
        ];
    }
} else {
    // Live search: topics (build /hub/<forum>/<topic>/) + content (url column).
    $base = LG_BB_MIRROR_PUBLIC_PATH;
    $sql = "
        SELECT kind, title, forum_slug, topic_slug, content_url, ts, item_id FROM (
            SELECT 'discussion' AS kind, t.title, f.slug AS forum_slug, t.slug AS topic_slug,
                   NULL::text AS content_url, t.last_active_at AS ts, t.id AS item_id
              FROM topic t JOIN forum f ON f.id = t.forum_id
             WHERE t.status = 'publish' AND f.visibility = 'public'
               AND t.forum_id NOT IN (3876) AND t.title ILIKE :like1
            UNION ALL
            SELECT kind, title, NULL, NULL, url, COALESCE(last_activity, published_at), id
              FROM discovery.content_item
             WHERE tier IN ($tin) AND title ILIKE :like2
               -- kind='misc' = sponsor plumbing (product/page rows for the
               -- sponsor-page carousels), not user-facing in the Hub (Ian
               -- 6/12: only sponsor-POSTS) — matches the feed union.
               AND kind <> 'misc'
        ) z
         ORDER BY ts DESC NULLS LAST
         LIMIT 8";
    $st = $db->prepare($sql);
    $st->bindValue(':like1', $like);
    $st->bindValue(':like2', $like);
    foreach ($tiers as $i => $t) $st->bindValue(':t' . $i, $t);
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        $url = $r['kind'] === 'discussion'
            ? $base . '/' . $r['forum_slug'] . '/' . $r['topic_slug'] . '/'
            : (string)$r['content_url'];
        // id/topic_id: lets the mobile open-in-place (hub-polish v169) skip its
        // resolve fetch — topic id for discussions, content_item id otherwise
        // (Buck ask 2026-06-11, coord-approved).
        $results[] = ['kind' => (string)$r['kind'], 'title' => (string)$r['title'], 'url' => $url,
                      'id' => (int)$r['item_id'],
                      'topic_id' => $r['kind'] === 'discussion' ? (int)$r['item_id'] : null];
    }
}

echo json_encode(['q' => $q, 'mode' => $mode, 'results' => $results]);
