<?php
/**
 * archive-poc/api/v0/_rowlib.php
 *
 * Server-side row executor. Used by web/index.php (SSR) and (optionally) by an
 * API endpoint if we ever want client-side row fetch. Pure PHP, no WP.
 *
 * Public functions:
 *   archive_poc_load_rows(string $path): array
 *   archive_poc_top_tags(PDO $db, int $limit, array $exclude = []): array
 *   archive_poc_pick_tag(array $candidates, int $slot, string $seed_mode): ?array
 *   archive_poc_run_row(PDO $db, array $row, array $resolved_tags = []): array{title:string, items:array, tag:?array}
 */

if (function_exists('archive_poc_run_row')) return;

function archive_poc_load_rows_full(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException("cannot read $path");
    return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
}

function archive_poc_load_rows(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException("cannot read $path");
    $j = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    return $j['rows'] ?? [];
}

function archive_poc_top_tags(PDO $db, int $limit, array $exclude = []): array {
    $exclude = array_values(array_filter($exclude));
    $where = '';
    $params = [];
    if ($exclude) {
        $ph = implode(',', array_fill(0, count($exclude), '?'));
        $where = "WHERE t.slug NOT IN ($ph)";
        $params = $exclude;
    }
    $sql = "
        SELECT t.id, t.slug, t.label, COUNT(*) AS n
        FROM tag t JOIN content_tag ct ON ct.tag_id = t.id
        $where
        GROUP BY t.id
        ORDER BY n DESC
        LIMIT " . (int)$limit;
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Deterministic tag pick. seed_mode = 'daily' | 'weekly' | 'monthly' → time-bucketed
 * Fisher-Yates shuffle (all viewers in the same bucket see the same pick).
 * 'session' → random per request. Anything else → first-N order.
 * $slot lets multiple rows pick different tags from the same shuffled list.
 */
function archive_poc_pick_tag(array $candidates, int $slot, string $seed_mode): ?array {
    if (!$candidates) return null;
    static $periods = ['daily' => 86400, 'weekly' => 604800, 'monthly' => 2592000];
    if (isset($periods[$seed_mode])) {
        $seed = (int) floor(time() / $periods[$seed_mode]);
        mt_srand($seed);
        $idx = range(0, count($candidates) - 1);
        for ($i = count($idx) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
        }
        mt_srand(); // restore
        return $candidates[$idx[$slot % count($idx)]];
    }
    return $candidates[$slot % count($candidates)] ?? null;
}

/**
 * Execute a row's query against content_item and return items + the resolved tag (if any).
 * Returns ['title' => str, 'items' => [...], 'tag' => ?array, 'layout' => str].
 */
function archive_poc_run_row(PDO $db, array $row, array $resolved_tags = []): array {
    $q = $row['query'] ?? [];
    $layout = $row['layout'] ?? 'rail';
    $title  = $row['title'] ?? '';

    $where  = ['1=1'];
    $params = [];

    // Tag row: resolve from candidate pool with deterministic seed.
    $tag_obj = null;
    if (($row['type'] ?? 'static') === 'tag-random') {
        $slot = (int) ($row['slot'] ?? 0);
        $exclude = $row['exclude'] ?? [];
        $candidates = $resolved_tags ?: archive_poc_top_tags($db, 20, $exclude);
        $tag_obj = archive_poc_pick_tag($candidates, $slot, $row['seed'] ?? 'weekly');
        if (!$tag_obj) return ['title' => $title, 'items' => [], 'tag' => null, 'layout' => $layout];
        $title = str_replace(['{{tag_label}}','{{tag}}'], '#' . $tag_obj['label'], $title);
        $where[] = "ci.id IN (SELECT ct.content_id FROM content_tag ct WHERE ct.tag_id = ?)";
        $params[] = (int) $tag_obj['id'];
    }

    if (!empty($q['kind'])) {
        $where[] = 'ci.kind = ?';
        $params[] = $q['kind'];
    }
    if (!empty($q['exclude_kinds']) && is_array($q['exclude_kinds'])) {
        $ph = implode(',', array_fill(0, count($q['exclude_kinds']), '?'));
        $where[] = "ci.kind NOT IN ($ph)";
        foreach ($q['exclude_kinds'] as $k) $params[] = $k;
    }
    if (!empty($q['max_age_days'])) {
        $cutoff = time() - 86400 * (int) $q['max_age_days'];
        $where[] = lg_ts_epoch($db, 'ci.published_at') . ' >= ?';
        $params[] = $cutoff;
    }
    if (!empty($q['min_likes'])) {
        $where[] = 'ci.like_count >= ?';
        $params[] = (int) $q['min_likes'];
    }
    // Author filter. Accepts int → author_id, string → author_name (exact),
    // or an array of either (mixed) → OR across all values.
    if (isset($q['author']) && $q['author'] !== '' && $q['author'] !== []) {
        $authors = is_array($q['author']) ? $q['author'] : [$q['author']];
        $id_vals = []; $name_vals = [];
        foreach ($authors as $a) {
            if (is_numeric($a)) $id_vals[] = (int) $a;
            elseif (is_string($a) && $a !== '') $name_vals[] = $a;
        }
        $or = [];
        if ($id_vals) {
            $ph = implode(',', array_fill(0, count($id_vals), '?'));
            $or[] = "ci.author_id IN ($ph)";
            foreach ($id_vals as $v) $params[] = $v;
        }
        if ($name_vals) {
            $ph = implode(',', array_fill(0, count($name_vals), '?'));
            $or[] = "ci.author_name IN ($ph)";
            foreach ($name_vals as $v) $params[] = $v;
        }
        if ($or) $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if (!empty($q['tier_in']) && is_array($q['tier_in'])) {
        $ph2 = implode(',', array_fill(0, count($q['tier_in']), '?'));
        $where[] = "ci.tier IN ($ph2)";
        foreach ($q['tier_in'] as $tv) $params[] = $tv;
    }
    // Explicit tag(s) — items must have ALL listed tag slugs.
    // Count is inlined (php-side int, never user input) to avoid PDO's
    // string-binding-int-comparison issue against SQLite COUNT().
    if (!empty($q['tags']) && is_array($q['tags'])) {
        $tag_ph = implode(',', array_fill(0, count($q['tags']), '?'));
        $tag_n  = count($q['tags']);
        $where[] = "ci.id IN (
            SELECT ct.content_id FROM content_tag ct JOIN tag t ON t.id = ct.tag_id
            WHERE t.slug IN ($tag_ph)
            GROUP BY ct.content_id HAVING COUNT(DISTINCT t.id) = $tag_n
        )";
        foreach ($q['tags'] as $ts) $params[] = $ts;
    }
    // Single tag with match mode: 'exact' (default), 'prefix', or 'contains'.
    // Resolves to any tag whose slug matches; item must have at least one.
    if (!empty($q['tag']) && is_string($q['tag'])) {
        $match = $q['tag_match'] ?? 'exact';
        if ($match === 'prefix') {
            $where[] = "ci.id IN (SELECT ct.content_id FROM content_tag ct
                JOIN tag t ON t.id = ct.tag_id WHERE t.slug LIKE ?)";
            $params[] = $q['tag'] . '%';
        } elseif ($match === 'contains') {
            $where[] = "ci.id IN (SELECT ct.content_id FROM content_tag ct
                JOIN tag t ON t.id = ct.tag_id WHERE t.slug LIKE ?)";
            $params[] = '%' . $q['tag'] . '%';
        } else {
            $where[] = "ci.id IN (SELECT ct.content_id FROM content_tag ct
                JOIN tag t ON t.id = ct.tag_id WHERE t.slug = ?)";
            $params[] = $q['tag'];
        }
    }

    $sort = $q['sort'] ?? 'newest';
    switch ($sort) {
        case 'oldest':  $order = 'ci.published_at ASC'; break;
        case 'liked':   $order = 'ci.like_count DESC, ci.published_at DESC'; break;
        case 'active':  $order = 'ci.last_activity DESC, ci.published_at DESC'; break;
        case 'newest':
        default:        $order = 'ci.published_at DESC'; break;
    }
    $limit  = max(1, min(50, (int) ($q['limit']  ?? 10)));
    $offset = max(0, min(500, (int) ($q['offset'] ?? 0)));

    $sql = "
        SELECT ci.id, ci.kind, ci.cpt, ci.title, ci.url, ci.excerpt, ci.body_text,
               ci.thumb_url, " . lg_bool_sel($db, 'ci.thumb_broken', 'thumb_broken') . ", ci.tier,
               ci.author_id, ci.author_name,
               " . lg_ts_sel($db, 'ci.published_at', 'published_at') . ", " . lg_ts_sel($db, 'ci.last_activity', 'last_activity') . ", ci.reply_count,
               ci.like_count, ci.view_count, ci.duration_min, " . lg_bool_sel($db, 'ci.has_download', 'has_download') . ", ci.yt_id
        FROM content_item ci
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order
        LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Fallback if hero/featured came back empty.
    if (!$rows && !empty($row['fallback_when_empty'])) {
        $row['query'] = $row['fallback_when_empty'];
        unset($row['fallback_when_empty']);
        return archive_poc_run_row($db, $row, $resolved_tags);
    }

    // Bulk-load tags for the result set.
    $ids = array_column($rows, 'id');
    $tags_by_id = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT ct.content_id, t.slug, t.label
                            FROM content_tag ct JOIN tag t ON t.id = ct.tag_id
                            WHERE ct.content_id IN ($ph)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tr) {
            $tags_by_id[(int)$tr['content_id']][] = ['slug' => $tr['slug'], 'label' => $tr['label']];
        }
    }
    foreach ($rows as &$r) {
        $r['tags'] = $tags_by_id[(int)$r['id']] ?? [];
        // Video facade: prefer the real yt_id resolved at index time (covers the
        // newest videos whose id lives only in the v2 embed block). Fall back to
        // the legacy body_text regex for any row not yet reindexed. Videos only.
        if (empty($r['yt_id']) && ($r['kind'] ?? '') === 'video' && !empty($r['body_text'])
            && preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,15})~i', (string)$r['body_text'], $ytm)) {
            $r['yt_id'] = $ytm[1];
        }
    }
    unset($r);

    return ['title' => $title, 'items' => $rows, 'tag' => $tag_obj, 'layout' => $layout, 'limit' => $limit];
}



/**
 * Active-discussions row. Discussions are NOT in content_item: the topic→discussion
 * sync was DROPPED 2026-06-05 (Hub-fold lane) — forum threads now live only in
 * forums.* in PG. So we source the cards straight from forums.topic, and each card's
 * href is the single-topic Hub deep-link /hub/<forum_slug>/<topic_slug>/ — built to
 * match bb-mirror feed_topic_url() exactly so it lands on the same view as clicking
 * the thread inside the Hub. The Hub enforces visibility server-side on that page, so
 * the deep-link is leak-safe; the card itself only emits title + (tier-gated) excerpt.
 *
 * PG-only: the SQLite revert path has no forums schema → return an empty row.
 * $is_member mirrors the Hub's logged-out author mask (member-visibility authors
 * show as "Private member" to anon, same as lg_bb_mirror_mask_visibility()).
 */
function archive_poc_run_discussions(PDO $db, array $row, bool $is_member = false): array {
    $title  = $row['title'] ?? 'Active discussions';
    $layout = $row['layout'] ?? 'discussions';
    $q      = is_array($row['query'] ?? null) ? $row['query'] : [];
    $limit  = max(1, min(50, (int) ($q['limit'] ?? 8)));

    if (!lg_archive_poc_is_pg($db)) {
        return ['title' => $title, 'items' => [], 'tag' => null, 'layout' => $layout, 'limit' => $limit];
    }

    $order = (($q['sort'] ?? 'active') === 'newest')
        ? 'ORDER BY t.created_at DESC NULLS LAST, t.id DESC'
        : 'ORDER BY t.last_active_at DESC NULLS LAST, t.id DESC';

    // forum 3876 is the Hub's excluded admin/suggestion forum (mirrors _feed.php).
    $sql = "
        SELECT t.id,
               t.title,
               LEFT(t.content_text, 240)                       AS excerpt,
               COALESCE(t.author_name, 'Member')               AS author_name,
               t.author_id,
               t.reply_count,
               " . lg_ts_sel($db, 't.last_active_at', 'last_activity') . ",
               t.tier_gate                                     AS tier,
               COALESCE(p.discussion_visibility, 'member')     AS discussion_visibility,
               '/hub/' || f.slug || '/' || t.slug || '/'       AS url
          FROM forums.topic t
          JOIN forums.forum f  ON f.id = t.forum_id
          LEFT JOIN forums.person p ON p.id = t.author_id
         WHERE t.status = 'publish'
           AND f.visibility = 'public'
           AND t.forum_id <> 3876
         $order
         LIMIT $limit";
    $items = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Logged-out author mask for member-visibility threads (Hub parity).
    if (!$is_member) {
        foreach ($items as &$it) {
            if (($it['discussion_visibility'] ?? '') === 'member') {
                $it['author_name'] = 'Private member';
                $it['author_id']   = 0;
            }
        }
        unset($it);
    }

    return ['title' => $title, 'items' => $items, 'tag' => null, 'layout' => $layout, 'limit' => $limit];
}


/**
 * Hero billboard. Returns a single item:
 *   1. First post in featured_post_ids that exists in the index.
 *   2. Otherwise the fallback query (newest public, recent).
 */
function archive_poc_run_hero(PDO $db, array $row): array {
    $title = $row['title'] ?? '';
    $layout = $row['layout'] ?? 'billboard';

    $pins = $row['featured_post_ids'] ?? [];
    if ($pins) {
        $ph = implode(',', array_fill(0, count($pins), '?'));
        // Preserve pin order via CASE/WHEN
        $caseParts = [];
        foreach ($pins as $i => $id) $caseParts[] = "WHEN " . (int)$id . " THEN $i";
        $caseSql = implode(' ', $caseParts);
        $sql = "SELECT " . lg_card_select($db) . " FROM content_item ci WHERE ci.id IN ($ph)
                ORDER BY CASE ci.id $caseSql END LIMIT 1";
        $st = $db->prepare($sql);
        $st->execute($pins);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $r['tags'] = [];
            return ['title' => $title, 'items' => [$r], 'layout' => $layout, 'tag' => null];
        }
    }
    $q = $row['fallback_when_empty'] ?? ($row['query'] ?? []);
    $q['limit'] = 1;
    $stub = ['type' => 'static', 'query' => $q, 'layout' => $layout, 'title' => $title];
    return archive_poc_run_row($db, $stub);
}

/**
 * Upcoming events: event_start_at > now, sorted ASC.
 */
function archive_poc_run_events_upcoming(PDO $db, array $row): array {
    $limit = (int) ($row['query']['limit'] ?? 10);
    $sql = "
        SELECT " . lg_card_select($db) . " FROM content_item ci
        WHERE ci.kind = 'event'
          AND ci.event_start_at IS NOT NULL
          AND " . lg_ts_epoch($db, 'ci.event_start_at') . " > ?
        ORDER BY ci.event_start_at ASC
        LIMIT " . max(1, min(50, $limit));
    $st = $db->prepare($sql);
    $st->execute([time()]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$r) $r['tags'] = [];
    unset($r);
    return ['title' => $row['title'] ?? 'Upcoming events', 'items' => $items, 'layout' => $row['layout'] ?? 'events', 'tag' => null];
}

/**
 * Any event currently between start and end. Returns 0 or 1 row.
 */
function archive_poc_happening_now(PDO $db): ?array {
    $st = $db->prepare("
        SELECT " . lg_card_select($db) . " FROM content_item ci
        WHERE ci.kind='event'
          AND ci.event_start_at IS NOT NULL AND ci.event_end_at IS NOT NULL
          AND " . lg_ts_epoch($db, 'ci.event_start_at') . " <= ?
          AND " . lg_ts_epoch($db, 'ci.event_end_at')   . " >  ?
        ORDER BY ci.event_start_at DESC LIMIT 1");
    $now = time();
    $st->execute([$now, $now]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
