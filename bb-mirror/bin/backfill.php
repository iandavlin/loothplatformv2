<?php
/**
 * bb-mirror/bin/backfill.php — one-shot walk of wp_posts for forums/topics/
 * replies, plus wp_postmeta _bbp_* projection. Writes into the active
 * Postgres-only since the SQLite fallback was retired.
 *
 * Usage (pg):
 *   sudo -u www-data wp eval-file /home/ubuntu/projects/bb-mirror/bin/backfill.php
 *
 * The www-data user (looth-dev pool) peer-auths to postgres as role
 * `looth-dev`, which has INSERT/UPDATE/DELETE granted on schema `forums`.
 *
 * READ-ONLY against the WP DB (SELECT only).
 *
 * Orphan handling: topics/replies referencing non-existent forums (or
 * replies referencing non-existent topics) are skipped at insert time.
 * Final counts surface the skip totals.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u www-data wp eval-file " . __FILE__ . "\n");
    exit(2);
}

global $wpdb;
$wpdb->suppress_errors(true);

$db = bb_mirror_db(readonly: false);
$db->beginTransaction();
$db->exec('SET CONSTRAINTS ALL DEFERRED');

$POSTMETA_KEYS = [
    'forum' => [
        '_bbp_forum_type', '_bbp_status', '_bbp_forum_visibility',
        '_bbp_topic_count', '_bbp_reply_count',
        '_bbp_total_topic_count', '_bbp_total_reply_count',
        '_bbp_last_topic_id', '_bbp_last_reply_id',
        '_bbp_last_active_id', '_bbp_last_active_time',
        '_bbp_group_ids',
    ],
    'topic' => [
        '_bbp_forum_id', '_bbp_topic_status',
        '_bbp_sticky_topics', '_bbp_super_sticky_topics',
        '_bbp_voice_count', '_bbp_reply_count',
        '_bbp_last_reply_id', '_bbp_last_active_id', '_bbp_last_active_time',
        '_bbp_anonymous_name', '_thumbnail_id',
    ],
    'reply' => [
        '_bbp_forum_id', '_bbp_topic_id', '_bbp_reply_to',
        '_bbp_anonymous_name',
    ],
];

$valid_forum_ids = [];   // tracked as we insert
$valid_topic_ids = [];
$author_ids_seen = [];
$skipped_topics  = 0;
$skipped_replies = 0;

// Read `_bbp_group_ids` postmeta (serialized PHP array on the forum); return
// the first group id, or null if unset/empty/unparseable. BB models the
// link 1:N forum→groups but in practice every forum we see is 1:1.
function first_group_id_from_meta(?string $serialized): ?int {
    if (!$serialized) return null;
    $arr = @unserialize($serialized);
    if (!is_array($arr) || !$arr) return null;
    foreach ($arr as $v) {
        $id = (int)$v;
        if ($id > 0) return $id;
    }
    return null;
}

// ---------- helpers --------------------------------------------------------
function ts_in($v): ?int {
    if (!$v) return null;
    if (is_numeric($v)) return (int)$v;
    $t = strtotime((string)$v . ' UTC');
    return $t ?: null;
}

function pluck_meta(int $post_id, array $keys): array {
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $sql = $wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders)",
        array_merge([$post_id], $keys)
    );
    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
    $out = [];
    foreach ($rows as $r) $out[$r['meta_key']] = $r['meta_value'];
    return $out;
}

// Postgres ON CONFLICT upsert builder. Alias of the shared helper in config.php.
function upsert_sql(string $table, array $cols, string $conflict_col = 'id'): string {
    return bb_mirror_upsert_sql($table, $cols, $conflict_col);
}

// ---------- bp_groups ------------------------------------------------------
// Mirror wp_bp_groups BEFORE forums so forum.group_id references something.
// Soft link only (no FK) — the orphan-gate rule means a missing bp_group row
// just falls back to "no gate" at render time, never errors.
echo "Backfilling bp_groups...\n";
// groupmeta.forum_id is a serialized PHP array — must deserialize in PHP,
// SQL CAST returns 0. total_member_count is a plain int meta. We fetch
// raw meta_value strings and parse on the PHP side for both.
$bp_groups = $wpdb->get_results(
    "SELECT g.id, g.slug, g.name, g.description, g.status,
            UNIX_TIMESTAMP(g.date_created) AS created_at,
            (SELECT meta_value FROM wp_bp_groups_groupmeta
              WHERE group_id = g.id AND meta_key = 'forum_id' LIMIT 1) AS forum_id_meta,
            (SELECT meta_value FROM wp_bp_groups_groupmeta
              WHERE group_id = g.id AND meta_key = 'total_member_count' LIMIT 1) AS member_count_meta
       FROM wp_bp_groups g"
);
$bp_group_cols = ['id','slug','name','description','status',
                  'attached_forum_id','member_count','created_at','sync_at'];
$stmt_group = $db->prepare(upsert_sql('bp_group', $bp_group_cols));
$group_count = 0;
foreach ($bp_groups as $g) {
    $stmt_group->execute([
        (int)$g->id,
        (string)$g->slug,
        decode_entities((string)$g->name),
        decode_entities((string)$g->description),
        in_array($g->status, ['public','private','hidden'], true) ? $g->status : 'public',
        first_group_id_from_meta($g->forum_id_meta ?? null),  // serialized PHP array; reuses the same deserializer
        (int)($g->member_count_meta ?? 0),
        bb_mirror_ts((int)$g->created_at),
        bb_mirror_ts(time()),
    ]);
    $group_count++;
}
echo "  $group_count bp_groups\n";

// ---------- forums ---------------------------------------------------------
echo "Backfilling forums...\n";
$forums = $wpdb->get_results(
    "SELECT ID, post_title, post_name, post_content, post_status, post_parent, menu_order,
            UNIX_TIMESTAMP(post_date_gmt) AS created_at,
            UNIX_TIMESTAMP(post_modified_gmt) AS modified_at,
            post_author
       FROM {$wpdb->posts}
      WHERE post_type = 'forum' AND post_status IN ('publish','closed','private','hidden')"
);

// Decode HTML entities in plain-text fields. WP stores literal `&nbsp;` in
// some forum descriptions; storing the entity then htmlspecialchars-escaping
// at render leaves the user staring at `&nbsp;`. Decode once at write.
function decode_entities(?string $s): ?string {
    if ($s === null) return null;
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Derive visibility from BB's _bbp_forum_visibility meta if set; fall back
// to wp_posts.post_status (hidden/private statuses become matching visibility).
function derive_visibility(?string $meta, string $post_status): string {
    if ($meta === 'public' || $meta === 'private' || $meta === 'hidden') return $meta;
    if ($post_status === 'hidden')  return 'hidden';
    if ($post_status === 'private') return 'private';
    return 'public';
}
$forum_cols = ['id','slug','title','description','parent_forum_id','menu_order','group_id',
               'forum_type','status','visibility','tier_gate',
               'topic_count','reply_count','total_topic_count','total_reply_count',
               'last_topic_id','last_reply_id','last_active_id','last_active_at',
               'total_last_active_at','effective_group_id',
               'created_at','modified_at','sync_at'];
$stmt_forum = $db->prepare(upsert_sql('forum', $forum_cols));
$forum_count = 0;
foreach ($forums as $f) {
    $m = pluck_meta((int)$f->ID, $POSTMETA_KEYS['forum']);
    $stmt_forum->execute([
        (int)$f->ID,
        (string)$f->post_name,
        decode_entities((string)$f->post_title),
        wp_kses_post(decode_entities((string)$f->post_content)),  // sanitize in (match materializers.php)
        (int)$f->post_parent ?: null,
        (int)$f->menu_order,
        first_group_id_from_meta($m['_bbp_group_ids'] ?? null),  // real link
        $m['_bbp_forum_type']       ?? 'forum',
        $m['_bbp_status']           ?? 'open',
        derive_visibility($m['_bbp_forum_visibility'] ?? null, (string)$f->post_status),
        'public',
        (int)($m['_bbp_topic_count']        ?? 0),
        (int)($m['_bbp_reply_count']        ?? 0),
        (int)($m['_bbp_total_topic_count']  ?? 0),
        (int)($m['_bbp_total_reply_count']  ?? 0),
        (int)($m['_bbp_last_topic_id']      ?? 0) ?: null,
        (int)($m['_bbp_last_reply_id']      ?? 0) ?: null,
        (int)($m['_bbp_last_active_id']     ?? 0) ?: null,
        bb_mirror_ts(ts_in($m['_bbp_last_active_time'] ?? null)),
        null,  // total_last_active_at — populated in the rollup pass below
        null,  // effective_group_id — populated in the ancestor-chain pass below
        bb_mirror_ts((int)$f->created_at),
        bb_mirror_ts((int)$f->modified_at),
        bb_mirror_ts(time()),
    ]);
    $valid_forum_ids[(int)$f->ID] = true;
    $forum_count++;
}
echo "  $forum_count forums\n";

// ---------- topics ---------------------------------------------------------
echo "Backfilling topics...\n";
$topics = $wpdb->get_results(
    "SELECT ID, post_title, post_name, post_content, post_author,
            post_status,
            UNIX_TIMESTAMP(post_date_gmt) AS created_at,
            UNIX_TIMESTAMP(post_modified_gmt) AS modified_at
       FROM {$wpdb->posts}
      WHERE post_type = 'topic'"
);
$topic_cols = ['id','forum_id','slug','title','content_html','content_text','featured_image_url',
               'author_id','author_name','author_slug','anonymous_name',
               'status','sticky_kind','voice_count','reply_count',
               'last_reply_id','last_active_id','last_active_at',
               'tier_gate','created_at','modified_at','sync_at'];
$stmt_topic = $db->prepare(upsert_sql('topic', $topic_cols));
$topic_count = 0;
foreach ($topics as $t) {
    $m = pluck_meta((int)$t->ID, $POSTMETA_KEYS['topic']);
    $fid = (int)($m['_bbp_forum_id'] ?? 0);
    if (!$fid || !isset($valid_forum_ids[$fid])) { $skipped_topics++; continue; }

    $body_text = wp_strip_all_tags((string)$t->post_content);
    $author_id = (int)$t->post_author;
    if ($author_id) $author_ids_seen[$author_id] = true;

    // Featured image (thumbnail). Resolve to URL via wp_get_attachment_image_url.
    $featured_url = null;
    if (!empty($m['_thumbnail_id'])) {
        $featured_url = wp_get_attachment_image_url((int)$m['_thumbnail_id'], 'medium') ?: null;
    }

    $sticky = !empty($m['_bbp_super_sticky_topics']) ? 'super'
            : (!empty($m['_bbp_sticky_topics']) ? 'forum' : null);

    // TRUE reply count from the source rows — _bbp_reply_count meta drifts
    // (see materializers.php note, Ian 2026-06-10).
    $real_reply_count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='reply' AND post_status='publish' AND post_parent=%d", (int)$t->ID));

    $stmt_topic->execute([
        (int)$t->ID, $fid,
        (string)$t->post_name, decode_entities((string)$t->post_title),
        wp_kses_post(decode_entities((string)$t->post_content)), $body_text,  // sanitize in (match materializers.php)
        $featured_url,
        $author_id ?: null, '', '',
        $m['_bbp_anonymous_name'] ?? null,
        $m['_bbp_topic_status'] ?? (string)$t->post_status,
        $sticky,
        (int)($m['_bbp_voice_count']     ?? 0),
        $real_reply_count,
        (int)($m['_bbp_last_reply_id']   ?? 0) ?: null,
        (int)($m['_bbp_last_active_id']  ?? 0) ?: null,
        bb_mirror_ts(ts_in($m['_bbp_last_active_time'] ?? null)),
        'public',
        bb_mirror_ts((int)$t->created_at),
        bb_mirror_ts((int)$t->modified_at),
        bb_mirror_ts(time()),
    ]);
    $valid_topic_ids[(int)$t->ID] = true;
    $topic_count++;
}
echo "  $topic_count topics ($skipped_topics skipped — orphan forum_id)\n";

// ---------- sticky walk ---------------------------------------------------
// `_bbp_sticky_topics` is on the FORUM as a serialized array of topic IDs.
// Topic-level meta doesn't carry it; the loop above always sets sticky=null.
// Post-pass: walk each forum's sticky list + UPDATE topic.sticky_kind.
// `_bbp_super_sticky_topics` is a site-wide option — same treatment, site-scope.
echo "Walking sticky topic lists...\n";
$sticky_count = 0;
$super_list = get_option('_bbp_super_sticky_topics', []);
if (is_array($super_list) && $super_list) {
    $ids = array_filter(array_map('intval', $super_list));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $db->prepare("UPDATE topic SET sticky_kind = 'super' WHERE id IN ($placeholders)");
        $upd->execute($ids);
        $sticky_count += $upd->rowCount();
    }
}
foreach ($forums as $f) {
    $stickies = get_post_meta((int)$f->ID, '_bbp_sticky_topics', true);
    if (!is_array($stickies) || !$stickies) continue;
    $ids = array_filter(array_map('intval', $stickies));
    if (!$ids) continue;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $upd = $db->prepare("
        UPDATE topic SET sticky_kind = 'forum'
         WHERE id IN ($placeholders)
           AND (sticky_kind IS NULL OR sticky_kind != 'super')
    ");
    $upd->execute($ids);
    $sticky_count += $upd->rowCount();
}
echo "  $sticky_count topic(s) marked sticky\n";

// ---------- replies --------------------------------------------------------
echo "Backfilling replies...\n";
$replies = $wpdb->get_results(
    "SELECT ID, post_content, post_author, post_status,
            UNIX_TIMESTAMP(post_date_gmt) AS created_at,
            UNIX_TIMESTAMP(post_modified_gmt) AS modified_at
       FROM {$wpdb->posts}
      WHERE post_type = 'reply'"
);
$reply_cols = ['id','topic_id','forum_id','parent_reply_id',
               'content_html','content_text',
               'author_id','author_name','author_slug','anonymous_name',
               'status','created_at','modified_at','sync_at'];
$stmt_reply = $db->prepare(upsert_sql('reply', $reply_cols));
$reply_count = 0;
foreach ($replies as $r) {
    $m = pluck_meta((int)$r->ID, $POSTMETA_KEYS['reply']);
    $tid = (int)($m['_bbp_topic_id'] ?? 0);
    $fid = (int)($m['_bbp_forum_id'] ?? 0);
    if (!$tid || !$fid || !isset($valid_topic_ids[$tid]) || !isset($valid_forum_ids[$fid])) {
        $skipped_replies++; continue;
    }

    $body_text = wp_strip_all_tags((string)$r->post_content);
    $author_id = (int)$r->post_author;
    if ($author_id) $author_ids_seen[$author_id] = true;

    $stmt_reply->execute([
        (int)$r->ID, $tid, $fid,
        (int)($m['_bbp_reply_to'] ?? 0) ?: null,
        wp_kses_post(decode_entities((string)$r->post_content)), $body_text,  // sanitize in (match materializers.php)
        $author_id ?: null, '', '',
        $m['_bbp_anonymous_name'] ?? null,
        (string)$r->post_status,
        bb_mirror_ts((int)$r->created_at),
        bb_mirror_ts((int)$r->modified_at),
        bb_mirror_ts(time()),
    ]);
    $reply_count++;
}
echo "  $reply_count replies ($skipped_replies skipped — orphan topic/forum)\n";

// ---------- attachments ---------------------------------------------------
// Post-pass via the shared lib's bb_mirror_sync_attachments() helper so
// backfill and live sync use the same harvest logic. Walks only topics/replies
// known to have bp_media_ids meta to avoid touching the entire post table.
echo "Backfilling attachments...\n";
require_once __DIR__ . '/../lib/materializers.php';
$attach_targets = $wpdb->get_results(
    "SELECT p.ID, p.post_type, p.post_content
       FROM {$wpdb->posts} p
       JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'bp_media_ids'
      WHERE p.post_type IN ('topic','reply')
        AND pm.meta_value != ''"
);
$attach_parent_count = 0;
foreach ($attach_targets as $row) {
    $parent_id = (int)$row->ID;
    $kind = (string)$row->post_type;
    if ($kind === 'topic' && !isset($valid_topic_ids[$parent_id])) continue;
    if ($kind === 'reply') {
        // Cheap reply-validity check: was it inserted? (orphan replies are skipped)
        $exists = (int)$db->query("SELECT 1 FROM reply WHERE id = $parent_id")->fetchColumn();
        if (!$exists) continue;
    }
    bb_mirror_sync_attachments($parent_id, $kind, $db, (string)$row->post_content);
    $attach_parent_count++;
}
$attach_total = (int)$db->query("SELECT COUNT(*) FROM attachment")->fetchColumn();
echo "  $attach_parent_count parent posts processed, $attach_total attachment rows total\n";

// ---------- persons --------------------------------------------------------
echo "Backfilling persons...\n";
$person_cols = ['id','slug','display_name','avatar_url','is_moderator','sync_at'];
$stmt_person = $db->prepare(upsert_sql('person', $person_cols));
$person_count = 0;
foreach (array_keys($author_ids_seen) as $uid) {
    if (!$uid) continue;
    $u = get_userdata($uid);
    if (!$u) continue;
    $stmt_person->execute([
        $uid, $u->user_nicename, $u->display_name,
        get_avatar_url($uid),
        bb_mirror_bool(false),
        bb_mirror_ts(time()),
    ]);
    $person_count++;
}
echo "  $person_count persons\n";

// ---------- denormalize author bylines ------------------------------------
echo "Denormalizing author bylines...\n";
$db->exec("
    UPDATE topic SET
      author_name = COALESCE((SELECT display_name FROM person WHERE id = topic.author_id), ''),
      author_slug = COALESCE((SELECT slug         FROM person WHERE id = topic.author_id), '')
    WHERE author_id IS NOT NULL
");
$db->exec("
    UPDATE reply SET
      author_name = COALESCE((SELECT display_name FROM person WHERE id = reply.author_id), ''),
      author_slug = COALESCE((SELECT slug         FROM person WHERE id = reply.author_id), '')
    WHERE author_id IS NOT NULL
");
// In pg, the topic_search_doc_trigger fires only on UPDATE OF title/content_text/author_name.
// We just updated author_name, so triggers refresh the tsvector — no manual reindex needed.

// ---------- total_last_active_at rollup -----------------------------------
// For each forum, max(topic.last_active_at) across the forum's own topics
// plus topics in every descendant subforum. Lets parent forums display
// "last activity" even when all topics live in sub-forums.
echo "Rolling up last_active_at per forum...\n";
$db->exec("
    WITH RECURSIVE descendants AS (
      SELECT id, id AS root_id FROM forum
      UNION ALL
      SELECT f.id, d.root_id FROM forum f JOIN descendants d ON f.parent_forum_id = d.id
    )
    UPDATE forum f SET total_last_active_at = (
      SELECT MAX(t.last_active_at) FROM topic t
      WHERE t.forum_id IN (SELECT id FROM descendants WHERE root_id = f.id)
    )
");

// effective_group_id — walk parent_forum_id upward until we find a group_id,
// or null if no ancestor has one. Subforums inherit transitively so
// write-gating logic only needs to check `effective_group_id`.
echo "Rolling up effective_group_id per forum...\n";
$db->exec("
    WITH RECURSIVE chain AS (
      SELECT id AS leaf_id, id AS at_id, parent_forum_id, group_id FROM forum
      UNION ALL
      SELECT c.leaf_id, f.id, f.parent_forum_id, f.group_id
        FROM chain c
        JOIN forum f ON f.id = c.parent_forum_id
       WHERE c.group_id IS NULL
    )
    UPDATE forum SET effective_group_id = (
      SELECT group_id FROM chain
       WHERE chain.leaf_id = forum.id AND chain.group_id IS NOT NULL
       LIMIT 1
    )
");

// ---------- bookkeeping ----------------------------------------------------
$bookkeep = $db->prepare(upsert_sql('sync_state', ['key','value','updated_at'], 'key'));
$bookkeep->execute(['last_backfill_at', (string)time(), bb_mirror_ts(time())]);
$bookkeep->execute(['schema_version', '0.1.0-pg', bb_mirror_ts(time())]);

$db->commit();

echo "Backfill complete: $forum_count forums, $topic_count topics, $reply_count replies, $person_count persons.\n";
echo "Skipped: $skipped_topics orphan topics, $skipped_replies orphan replies.\n";
