<?php
require __DIR__ . '/_bootstrap.php';

$ALLOWED_KINDS = ['article','video','loothprint','loothcuts','document','discussion','profile','benefit','sponsor-post','shorty','useful_links','misc'];
$ALLOWED_TIERS = ['public','lite','pro'];
$ALLOWED_SORTS = ['newest','oldest','liked','active','relevance','viewed','least_viewed','discussed','random'];

$q        = param_str('q');
$kind     = param_str('kind');
$subkind  = param_str('subkind');
$tiers    = array_values(array_intersect(param_csv('tier'), $ALLOWED_TIERS));
$tags     = param_csv('tag');               // tag slugs
$authorId = param_int('author_id');
$sort     = param_str('sort', $q !== '' ? 'relevance' : 'newest');
if (!in_array($sort, $ALLOWED_SORTS, true)) $sort = 'newest';
$limit    = max(1, min(100, param_int('limit', 24)));
$offset   = max(0, param_int('offset', 0));

// Build WHERE clauses + params.
$where  = ['1=1', "ci.kind != 'event'"];
$params = [];

if ($kind !== '' && in_array($kind, $ALLOWED_KINDS, true)) {
    $where[] = 'ci.kind = ?';
    $params[] = $kind;
}
if ($subkind !== '') {
    $where[] = 'ci.subkind = ?';
    $params[] = $subkind;
}
if ($tiers) {
    $where[] = 'ci.tier IN (' . implode(',', array_fill(0, count($tiers), '?')) . ')';
    foreach ($tiers as $t) $params[] = $t;
}
if ($authorId > 0) {
    $where[] = 'ci.author_id = ?';
    $params[] = $authorId;
}
if ($tags) {
    // posts that have ALL specified tags. Tag-count is inlined (safe — it's
    // count() of a php array, never user input) because PDO binds ints as
    // strings on SQLite by default, which makes HAVING COUNT() = ? compare
    // int vs string and silently return zero rows.
    $tag_placeholders = implode(',', array_fill(0, count($tags), '?'));
    $tag_count = count($tags);
    $where[] = "ci.id IN (
        SELECT ct.content_id FROM content_tag ct JOIN tag t ON t.id=ct.tag_id
        WHERE t.slug IN ($tag_placeholders)
        GROUP BY ct.content_id HAVING COUNT(DISTINCT t.id) = $tag_count
    )";
    foreach ($tags as $t) $params[] = $t;
}

// Full-text search (driver-aware). Prepend the FTS predicate so its bound
// param leads the positional list; the filter params follow.
$fts = ($q !== '') ? archive_fts($db, $q) : null;
if ($q !== '' && $fts === null) {
    // query reduced to no searchable tokens — return a well-formed empty result
    send_json([
        'total' => 0, 'limit' => $limit, 'offset' => $offset, 'sort' => $sort,
        'items' => [], 'people' => [], 'people_total' => 0,
        'facets' => ['kind'=>[], 'tier'=>[], 'tag'=>[], 'author'=>[]],
        'meta' => ['elapsed_ms' => 0],
    ]);
}
$fts_join     = '';
$select_extra = '';
if ($fts) {
    $fts_join     = $fts['join'];
    $select_extra = $fts['rank_select'];
    array_unshift($where,  $fts['where']);
    array_unshift($params, $fts['param']);
}

$where_sql = implode(' AND ', $where);

// Sort
switch ($sort) {
    case 'oldest':       $order_sql = 'ci.published_at ASC';  break;
    case 'liked':        $order_sql = 'ci.like_count DESC, ci.published_at DESC'; break;
    case 'active':       $order_sql = 'ci.last_activity DESC, ci.published_at DESC'; break;
    case 'relevance':    $order_sql = $fts ? $fts['rank_order'] : 'ci.published_at DESC'; break;
    case 'viewed':       $order_sql = 'ci.view_count DESC, ci.published_at DESC'; break;
    case 'least_viewed': $order_sql = 'ci.view_count ASC, ci.published_at DESC'; break;
    case 'discussed':    $order_sql = 'ci.reply_count DESC, ci.last_activity DESC'; break;
    case 'random':       $order_sql = lg_archive_poc_is_pg($db) ? 'random()' : 'RANDOM()'; break;
    case 'newest':
    default:             $order_sql = 'ci.published_at DESC'; break;
}

$sql = "
    SELECT ci.id, ci.kind, ci.subkind, ci.cpt, ci.title, ci.url, ci.excerpt, ci.forum_label, ci.subforum_label,
           ci.thumb_url, " . lg_bool_sel($db, 'ci.thumb_broken', 'thumb_broken') . ", ci.tier,
           ci.author_id, ci.author_name,
           " . lg_ts_sel($db, 'ci.published_at', 'published_at') . ", " . lg_ts_sel($db, 'ci.last_activity', 'last_activity') . ", ci.reply_count,
           ci.like_count, ci.view_count, ci.duration_min, " . lg_bool_sel($db, 'ci.has_download', 'has_download') . ", ci.yt_id
           $select_extra
    FROM content_item ci
    $fts_join
    WHERE $where_sql
    ORDER BY $order_sql
    LIMIT $limit OFFSET $offset
";

// PG relevance re-binds the needle in ORDER BY (trailing positional param).
$main_params = ($fts && $fts['pg'] && $sort === 'relevance')
    ? array_merge($params, [$fts['rank_param']])
    : $params;

$t0 = microtime(true);
$stmt = $db->prepare($sql);
$stmt->execute($main_params);
$rows = $stmt->fetchAll();

// Total count (without limit/offset). Cheap re-run with COUNT(*).
$count_sql = "SELECT COUNT(*) FROM content_item ci $fts_join WHERE $where_sql";
$cs = $db->prepare($count_sql);
$cs->execute($params);
$total = (int) $cs->fetchColumn();

// Bulk-load tags for the returned items.
$ids = array_column($rows, 'id');
$tags_by_id = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $tsql = "SELECT ct.content_id, t.slug, t.label
             FROM content_tag ct JOIN tag t ON t.id = ct.tag_id
             WHERE ct.content_id IN ($ph)";
    $ts = $db->prepare($tsql);
    $ts->execute($ids);
    foreach ($ts->fetchAll() as $tr) {
        $tags_by_id[(int)$tr['content_id']][] = ['slug' => $tr['slug'], 'label' => $tr['label']];
    }
}

// Viewer tier (anon→public, admin→pro). Gates the prose `excerpt` per item so a
// viewer below the content's tier never receives gated body text — the excerpt
// is baked from the first 220 chars of the full body (and, for videos, can carry
// the raw youtube embed URL). One whoami call, statically cached for the request.
$viewer_tier = lg_archive_poc_viewer_tier(lg_archive_poc_whoami());

// Shape items for the frontend.
$items = [];
foreach ($rows as $r) {
    $rid = (int)$r['id'];
    $items[] = lg_archive_poc_gate_payload([
        'id'            => $rid,
        'kind'          => $r['kind'],
        'subkind'       => $r['subkind'],
        'cpt'           => $r['cpt'],
        'title'         => $r['title'],
        'url'           => $r['url'],
        'excerpt'       => $r['excerpt'],
        'thumb_url'     => $r['thumb_url'] ?: null,
        'thumb_broken'  => (int)$r['thumb_broken'] === 1,
        'tier'          => $r['tier'],
        'author'        => $r['author_id'] ? [
            'id' => (int)$r['author_id'], 'name' => $r['author_name'] ?: '',
        ] : null,
        'published_at'  => (int)$r['published_at'],
        'last_activity' => $r['last_activity'] !== null ? (int)$r['last_activity'] : null,
        'reply_count'   => (int)$r['reply_count'],
        'like_count'    => (int)$r['like_count'],
        'view_count'    => (int)$r['view_count'],
        'duration_min'  => $r['duration_min'] !== null ? (int)$r['duration_min'] : null,
        'has_download'  => (int)$r['has_download'] === 1,
        'forum'         => $r['forum_label']    ?: null,
        'subforum'      => $r['subforum_label'] ?: null,
        'tags'          => $tags_by_id[$rid] ?? [],
    ], $viewer_tier);
}

// Facets are derived from the FULL filtered set (not just current page).
// Re-query the id set without limit/offset and aggregate.
$facet_sql_base = "
    SELECT ci.id, ci.kind, ci.tier
    FROM content_item ci
    $fts_join
    WHERE $where_sql
";
$fs = $db->prepare($facet_sql_base);
$fs->execute($params);
$id_set = [];
$kind_counts = [];
$tier_counts = [];
foreach ($fs->fetchAll() as $r) {
    $id_set[] = (int)$r['id'];
    $kind_counts[$r['kind']] = ($kind_counts[$r['kind']] ?? 0) + 1;
    if ($r['tier'] !== null && $r['tier'] !== '') $tier_counts[$r['tier']] = ($tier_counts[$r['tier']] ?? 0) + 1;
}

$tag_counts = [];
$author_counts = [];
if ($id_set) {
    $ph = implode(',', array_fill(0, count($id_set), '?'));
    $tsql = "SELECT t.slug, t.label, COUNT(*) AS n
             FROM content_tag ct JOIN tag t ON t.id=ct.tag_id
             WHERE ct.content_id IN ($ph)
             GROUP BY t.id ORDER BY n DESC LIMIT 40";
    $ts = $db->prepare($tsql);
    $ts->execute($id_set);
    foreach ($ts->fetchAll() as $tr) {
        $tag_counts[] = ['v' => $tr['slug'], 'label' => $tr['label'], 'n' => (int)$tr['n']];
    }

    // Join person for avatar + slug so the "People" tab can render rich author
    // cards (matches the search modal's People section).
    $asql = "SELECT ci.author_id, ci.author_name, COUNT(*) AS n,
                    p.avatar_url, p.slug
             FROM content_item ci
             LEFT JOIN person p ON p.id = ci.author_id
             WHERE ci.id IN ($ph) AND ci.author_id IS NOT NULL AND ci.author_id > 0
             GROUP BY ci.author_id, ci.author_name, p.avatar_url, p.slug
             ORDER BY n DESC LIMIT 20";
    $as = $db->prepare($asql);
    $as->execute($id_set);
    foreach ($as->fetchAll() as $ar) {
        $author_counts[] = [
            'v' => (int)$ar['author_id'],
            'label' => $ar['author_name'] ?: '(unknown)',
            'n' => (int)$ar['n'],
            'avatar_url' => $ar['avatar_url'] ?: null,
            'slug' => $ar['slug'] ?: null,
        ];
    }
}

// ---- People (authors with posts or discussions) ------------------------
// A "person" in the People tab is an author who has at least one matching item
// that's a post or a discussion — i.e. NOT a sponsor benefit or an event.
// people_total is always computed (drives the People tab count); the paginated
// list is only built when ?people=1 (i.e. the People tab is active).
$want_people  = (param_int('people') === 1);
$people       = [];
$people_total = 0;
if ($id_set) {
    $ph_p = implode(',', array_fill(0, count($id_set), '?'));
    $ct = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT ci.author_id
            FROM content_item ci
            WHERE ci.id IN ($ph_p) AND ci.author_id > 0
              AND ci.kind NOT IN ('benefit','event')
            GROUP BY ci.author_id
        ) sub
    ");
    $ct->execute($id_set);
    $people_total = (int) $ct->fetchColumn();

    if ($want_people) {
        $psql = "SELECT ci.author_id, ci.author_name, COUNT(*) AS n,
                        p.avatar_url, p.slug
                 FROM content_item ci
                 LEFT JOIN person p ON p.id = ci.author_id
                 WHERE ci.id IN ($ph_p) AND ci.author_id > 0
                   AND ci.kind NOT IN ('benefit','event')
                 GROUP BY ci.author_id, ci.author_name, p.avatar_url, p.slug
                 ORDER BY n DESC
                 LIMIT $limit OFFSET $offset";
        $ps = $db->prepare($psql);
        $ps->execute($id_set);
        foreach ($ps->fetchAll() as $pr) {
            $people[] = [
                'v'          => (int)$pr['author_id'],
                'label'      => $pr['author_name'] ?: '(unknown)',
                'n'          => (int)$pr['n'],
                'avatar_url' => $pr['avatar_url'] ?: null,
                'slug'       => $pr['slug'] ?: null,
            ];
        }
    }
}

$facet_kind = [];
foreach ($kind_counts as $v => $n) $facet_kind[] = ['v' => $v, 'n' => $n];
usort($facet_kind, fn($a, $b) => $b['n'] <=> $a['n']);

$facet_tier = [];
foreach ($tier_counts as $v => $n) $facet_tier[] = ['v' => $v, 'n' => $n];
usort($facet_tier, fn($a, $b) => $b['n'] <=> $a['n']);

$elapsed_ms = (int) round((microtime(true) - $t0) * 1000);

send_json([
    'total'        => $total,
    'limit'        => $limit,
    'offset'       => $offset,
    'sort'         => $sort,
    'items'        => $items,
    'people'       => $people,        // populated when ?people=1
    'people_total' => $people_total,  // always — drives the People tab count
    'facets'  => [
        'kind'   => $facet_kind,
        'tier'   => $facet_tier,
        'tag'    => $tag_counts,
        'author' => $author_counts,
    ],
    'meta'    => ['elapsed_ms' => $elapsed_ms],
]);
