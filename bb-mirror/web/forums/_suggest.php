<?php
declare(strict_types=1);
/**
 * Hub type-ahead JSON endpoint (routed via index.php on ?suggest=).
 *
 *   /hub/?suggest=hub&q=<text>     -> live search: matching posts + content
 *   /hub/?suggest=author&q=<text>  -> author autocomplete: matching names
 *   /hub/?suggest=tag&q=<text>     -> tag autocomplete: matching REAL tags
 *                                     (cross-world), driving the ?tag= facet
 *
 * Unified across forums.topic + discovery.content_item; content is tier-gated to
 * the viewer (same absence model as the feed). Cheap ILIKE substring match —
 * this is a suggest box, not full search (?q= still runs _search.php).
 */

require_once __DIR__ . '/_hub-filters.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$sg   = (string)($_GET['suggest'] ?? '');
$mode = $sg === 'author' ? 'author' : ($sg === 'tag' ? 'tag' : 'hub');
$q    = trim((string)($_GET['q'] ?? ''));
// Tag field carries a DISPLAY-ONLY leading '#'; strip it before matching (the
// client strips too, this is belt-and-braces) so "#neck" matches "neck reset".
if ($mode === 'tag') $q = ltrim($q, '#');
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
} elseif ($mode === 'tag') {
    // Tag autocomplete — REAL tags only (this drives the exact ?tag= facet;
    // there is NO free-type apply, so we only ever surface tags that exist).
    // CROSS-WORLD + slug reconciliation (TAG-SEARCH-SCOPE.md): content tags carry
    // a canonical slug+label (discovery.tag); forum topic tags are free-text
    // labels normalized to a slug at query time with the SAME rule as
    // hub_slugify(). The data-pick the client applies is the STORED canonical
    // slug, so the 67/1846 slug≠slugify(label) cases ("Gerry's Picks"→
    // gerrys-picks) resolve correctly. Merge by slug in PHP; sum counts; prefer
    // the canonical content label. Returns [{label, slug, n}].
    $bySlug = [];
    // -- content tags (tier-gated; canonical slug + label) --
    $cs = $db->prepare("
        SELECT t.slug, t.label, count(DISTINCT ci.id) AS n
          FROM discovery.tag t
          JOIN discovery.content_tag j ON j.tag_id = t.id
          JOIN discovery.content_item ci ON ci.id = j.content_id
         WHERE ci.tier IN ($tin) AND ci.kind NOT IN ('event','misc')
           AND (t.label ILIKE :clike OR t.slug ILIKE :cslug)
         GROUP BY t.slug, t.label
         ORDER BY n DESC
         LIMIT 40");
    $cs->bindValue(':clike', $like);
    $cs->bindValue(':cslug', $like);
    foreach ($tiers as $i => $t) $cs->bindValue(':t' . $i, $t);
    $cs->execute();
    foreach ($cs->fetchAll() as $r) {
        $slug = (string)$r['slug'];
        if ($slug === '') continue;
        $bySlug[$slug] = ['slug' => $slug, 'label' => (string)$r['label'], 'n' => (int)$r['n']];
    }
    // -- forum topic tags (free-text label → normalized slug; public topics) --
    $ts = $db->prepare("
        SELECT slug, label, count(*) AS n FROM (
            SELECT trim(both '-' from regexp_replace(lower(x), '[^a-z0-9]+', '-', 'g')) AS slug,
                   x AS label
              FROM topic tp JOIN forum f ON f.id = tp.forum_id, unnest(tp.tags) x
             WHERE tp.status = 'publish' AND f.visibility = 'public' AND tp.forum_id NOT IN (3876)
        ) s
         WHERE s.slug <> '' AND (s.label ILIKE :tlike OR s.slug ILIKE :tslug)
         GROUP BY s.slug, s.label
         ORDER BY n DESC
         LIMIT 80");
    $ts->bindValue(':tlike', $like);
    $ts->bindValue(':tslug', $like);
    $ts->execute();
    foreach ($ts->fetchAll() as $r) {
        $slug = (string)$r['slug'];
        if ($slug === '') continue;
        if (isset($bySlug[$slug])) {
            $bySlug[$slug]['n'] += (int)$r['n']; // cross-world: keep canonical content label, sum counts
        } else {
            $bySlug[$slug] = ['slug' => $slug, 'label' => (string)$r['label'], 'n' => (int)$r['n']];
        }
    }
    $rows = array_values($bySlug);
    usort($rows, fn($a, $b) => ($b['n'] <=> $a['n']) ?: strcmp($a['label'], $b['label']));
    foreach (array_slice($rows, 0, 8) as $r) {
        $results[] = ['label' => (string)$r['label'], 'slug' => (string)$r['slug'], 'n' => (int)$r['n']];
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
