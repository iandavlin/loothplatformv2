<?php
// Faceted search-suggest for the modal: returns authors, posts, discussions
// separately from a single query. Used by the chrome search modal only.
// Driver-aware FTS: SQLite content_fts MATCH / PG tsv @@ websearch_to_tsquery,
// built by archive_fts() in _bootstrap.php. $db from lg_archive_poc_pdo() (env DSN).
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['error' => 'method_not_allowed'], 405);
}

$q = param_str('q');
if (strlen(trim($q)) < 2) {
    send_json(['q'=>$q,'authors'=>[],'posts'=>[],'posts_total'=>0,'discussions'=>[],'discussions_total'=>0]);
}

$fts      = archive_fts($db, $q);
$nameLike = lg_archive_poc_is_pg($db) ? 'ILIKE' : 'LIKE';
$LIMIT    = 3;

// ---- Author name search ------------------------------------------------
// Fuzzy match on person.display_name. Weighted by how many posts they have.
//
// VISIBILITY MASK (Ian 6/12, visibility refactor): author identity follows
// the same rules as everywhere else. The flags live on forums.person (the
// Hub's synced cache, profile-app owns the source fields):
//   - profile_visibility 'private' (master switch) -> never a search hit,
//     for ANY viewer. Missing row fails open to 'public' for this flag but
//     CLOSED for the anon mask below.
//   - logged-out viewers only see authors who chose PUBLIC discussion
//     identity (discussion_visibility) -- same mask as the Hub feed; a
//     missing forums.person row hides the author from anon (leak-safe).
// PG-only (the SQLite lane has no forums schema; PG is the live backend).
$authors = [];
$lgAnon  = !(lg_archive_poc_whoami()['authenticated'] ?? false);
$visJoin = ''; $visWhere = '';
if (lg_archive_poc_is_pg($db)) {
    $visJoin  = 'LEFT JOIN forums.person fp ON fp.id = p.id';
    $visWhere = " AND COALESCE(fp.profile_visibility, 'public') <> 'private'";
    if ($lgAnon) $visWhere .= " AND COALESCE(fp.discussion_visibility, 'member') = 'public'";
}
$as = $db->prepare("
    SELECT p.id, p.display_name, p.slug, p.avatar_url,
           COUNT(ci.id) AS post_count
    FROM person p
    $visJoin
    LEFT JOIN content_item ci ON ci.author_id = p.id
    WHERE p.display_name $nameLike ? $visWhere
    GROUP BY p.id
    ORDER BY post_count DESC
    LIMIT ?
");
$as->execute(['%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%', $LIMIT]);
foreach ($as->fetchAll() as $r) {
    $authors[] = [
        'id'         => (int)$r['id'],
        'name'       => $r['display_name'],
        'slug'       => $r['slug'],
        'avatar_url' => $r['avatar_url'] ?: null,
        'post_count' => (int)$r['post_count'],
    ];
}

// ---- Posts (everything except discussions) -----------------------------
$posts       = [];
$posts_total = 0;
if ($fts) {
    $ps = $db->prepare("
        SELECT ci.id, ci.kind, ci.title, ci.url,
               ci.thumb_url, " . lg_bool_sel($db, 'ci.thumb_broken', 'thumb_broken') . ", ci.tier, ci.author_name
               {$fts['rank_select']}
        FROM content_item ci
        {$fts['join']}
        WHERE {$fts['where']}
          AND ci.kind NOT IN ('discussion','event')
        ORDER BY {$fts['rank_order']}
        LIMIT ?
    ");
    $ps->execute($fts['pg'] ? [$fts['param'], $fts['rank_param'], $LIMIT] : [$fts['param'], $LIMIT]);
    foreach ($ps->fetchAll() as $r) {
        $posts[] = [
            'id'          => (int)$r['id'],
            'kind'        => $r['kind'],
            'title'       => $r['title'],
            'url'         => $r['url'],
            'thumb_url'   => $r['thumb_url'] ?: null,
            'thumb_broken'=> (int)$r['thumb_broken'] === 1,
            'tier'        => $r['tier'],
            'author_name' => $r['author_name'] ?: null,
        ];
    }
    $pc = $db->prepare("
        SELECT COUNT(*) FROM content_item ci
        {$fts['join']}
        WHERE {$fts['where']} AND ci.kind NOT IN ('discussion','event')
    ");
    $pc->execute([$fts['param']]);
    $posts_total = (int)$pc->fetchColumn();
}

// ---- Discussions --------------------------------------------------------
$discussions       = [];
$discussions_total = 0;
// Discussions are NOT indexed in PG (the Hub owns forum search); this returns
// empty on Postgres by construction. Kept driver-aware for the SQLite fallback.
if ($fts) {
    $ds = $db->prepare("
        SELECT ci.id, ci.title, ci.url, ci.reply_count, " . lg_ts_sel($db, 'ci.last_activity', 'last_activity') . "
               {$fts['rank_select']}
        FROM content_item ci
        {$fts['join']}
        WHERE {$fts['where']}
          AND ci.kind = 'discussion'
        ORDER BY {$fts['rank_order']}
        LIMIT ?
    ");
    $ds->execute($fts['pg'] ? [$fts['param'], $fts['rank_param'], $LIMIT] : [$fts['param'], $LIMIT]);
    foreach ($ds->fetchAll() as $r) {
        $discussions[] = [
            'id'            => (int)$r['id'],
            'title'         => $r['title'],
            'url'           => $r['url'],
            'reply_count'   => (int)$r['reply_count'],
            'last_activity' => $r['last_activity'] !== null ? (int)$r['last_activity'] : null,
        ];
    }
    $dc = $db->prepare("
        SELECT COUNT(*) FROM content_item ci
        {$fts['join']}
        WHERE {$fts['where']} AND ci.kind = 'discussion'
    ");
    $dc->execute([$fts['param']]);
    $discussions_total = (int)$dc->fetchColumn();
}

send_json([
    'q'                  => $q,
    'authors'            => $authors,
    'posts'              => $posts,
    'posts_total'        => $posts_total,
    'discussions'        => $discussions,
    'discussions_total'  => $discussions_total,
]);
