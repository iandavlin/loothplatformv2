<?php
/**
 * bb-mirror/lib/materializers.php — shared upsert helpers for sync receiver
 * and reconcile cron.
 *
 * Required by:
 *   - api/v0/_sync.php   (loopback receiver, runs on looth-dev FPM pool)
 *   - bin/reconcile.php  (cron, runs as looth-dev under wp eval-file)
 *
 * Assumes WP is bootstrapped (`$wpdb`, `get_post`, `get_userdata` etc. available)
 * and `bb_mirror_db()` / `bb_mirror_ts()` / `bb_mirror_upsert_sql()` /
 * `bb_mirror_bool()` from config.php are loaded.
 */

if (defined('LG_BB_MIRROR_MATERIALIZERS_LOADED')) return;
define('LG_BB_MIRROR_MATERIALIZERS_LOADED', true);

// ---------- small helpers --------------------------------------------------

function _bb_mirror_decode(?string $s): ?string {
    return $s === null ? null : html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function _bb_mirror_visibility(?string $meta, string $post_status): string {
    if ($meta === 'public' || $meta === 'private' || $meta === 'hidden') return $meta;
    if ($post_status === 'hidden')  return 'hidden';
    if ($post_status === 'private') return 'private';
    return 'public';
}

function _bb_mirror_first_group_id(?string $serialized): ?int {
    if (!$serialized) return null;
    $arr = @unserialize($serialized);
    if (!is_array($arr) || !$arr) return null;
    foreach ($arr as $v) {
        $gid = (int)$v;
        if ($gid > 0) return $gid;
    }
    return null;
}

function bb_mirror_post_meta_all(int $id): array {
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id),
        ARRAY_A
    ) ?: [];
    $out = [];
    foreach ($rows as $r) $out[$r['meta_key']] = $r['meta_value'];
    return $out;
}

function bb_mirror_person_for(int $uid, PDO $db): array {
    if (!$uid) return ['name' => '', 'slug' => ''];
    $u = get_userdata($uid);
    if (!$u) return ['name' => '', 'slug' => ''];

    // Guard: never let a transient test fixture overwrite a mirrored person.
    // WP user IDs are recyclable across DB reloads, so a "staple" fixture (or a
    // single-char placeholder name) occupying an ID at sync time would clobber
    // the real human who later holds that ID — the "T" thread-author bug, 6/2.
    // On skip, preserve any existing mirrored row so the denormalized
    // author_name keeps the real value. See project_bb_mirror_person_staleness.
    $nicename = (string)$u->user_nicename;
    $display  = (string)$u->display_name;
    if (str_starts_with($nicename, 'tst-staple-') || mb_strlen(trim($display)) <= 1) {
        $existing = $db->prepare("SELECT display_name, slug FROM person WHERE id = ?");
        $existing->execute([$uid]);
        if ($row = $existing->fetch()) {
            return ['name' => $row['display_name'], 'slug' => $row['slug']];
        }
        return ['name' => $display, 'slug' => $nicename];
    }

    $sql = bb_mirror_upsert_sql('person',
        ['id','slug','display_name','avatar_url','is_moderator','discussion_visibility','sync_at']);
    $db->prepare($sql)->execute([
        $uid, $u->user_nicename, $u->display_name,
        lg_bb_mirror_safe_avatar(get_avatar_url($uid)),
        bb_mirror_bool(false),
        bb_mirror_discussion_vis($uid),
        bb_mirror_ts(time()),
    ]);
    return ['name' => $u->display_name, 'slug' => $u->user_nicename];
}

/**
 * Discussion-author visibility for a WP user, pulled from profile-app's
 * /profile-api/v0/users batch payload — profile-app OWNS the field (commit
 * e8e44c7); forums.person is the synced cache the Hub feed already JOINs, so
 * carrying it here lets the logged-out author mask ride that JOIN with NO
 * per-render profile-app call (path (a), docs/briefing-discussion-visibility.md).
 *
 *   'public' → logged-out viewers see the real author.
 *   'member' → logged-out viewers get "private member" + the fallback avatar.
 *
 * Defaults to 'member' — profile-app's default AND the leak-SAFE direction: a
 * missing/failed/unbridged lookup HIDES identity, it never exposes a member-only
 * author. Per-uid memoized for the life of the process (one sync run reuses an
 * author's value across its posts). SINGULAR 'member' — must match profile-app.
 */
function bb_mirror_discussion_vis(int $uid): string {
    static $memo = [];
    if ($uid <= 0) return 'member';
    if (array_key_exists($uid, $memo)) return $memo[$uid];
    $map = bb_mirror_discussion_vis_batch([$uid]);
    return $memo[$uid] = ($map[$uid] ?? 'member');
}

/**
 * Batch resolver: wp_user_id => 'public'|'member' for the ids profile-app could
 * resolve (bridged, non-archived). Unresolved ids are simply absent — callers
 * default them to 'member'. Loopback to /profile-api/v0/users?wp_ids= (the
 * contract route; live has no gate). On dev the cookie gate fronts it, so a gate
 * token is forwarded when available (LG_LOOTHDEV_GATE_TOKEN env, set for the CLI
 * reconcile/backfill; the live server-to-server path on prod needs none).
 * Returns [] on any failure → every caller falls back to the safe 'member'.
 */
function bb_mirror_discussion_vis_batch(array $wpIds): array {
    $out = [];
    foreach (bb_mirror_person_vis_batch($wpIds) as $wid => $vis) {
        $out[$wid] = $vis['discussion'];
    }
    return $out;
}

/**
 * Richer batch resolver: wp_user_id => ['discussion' => 'public'|'member',
 * 'profile' => 'public'|'private']. 'profile' is the MASTER SWITCH
 * (users.profile_visibility, Ian 6/12 visibility refactor) — 'private' = the
 * member is owner-only everywhere; forums.person caches it so the hub search
 * mask rides the same JOIN as the logged-out author mask. Unresolved ids are
 * absent; callers default leak-safe.
 */
function bb_mirror_person_vis_batch(array $wpIds): array {
    $wpIds = array_values(array_filter(array_map('intval', $wpIds), static fn($i) => $i > 0));
    if (!$wpIds) return [];
    $token  = (string) getenv('LG_LOOTHDEV_GATE_TOKEN');
    $cookie = $token !== '' ? ('loothdev_auth=' . $token) : (string) ($_SERVER['HTTP_COOKIE'] ?? '');
    $out = [];
    foreach (array_chunk($wpIds, 100) as $chunk) {
        $hdrs = ['Host: ' . LG_BB_MIRROR_HOST];
        if ($cookie !== '') $hdrs[] = 'Cookie: ' . $cookie;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/users?wp_ids=' . rawurlencode(implode(',', $chunk)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) continue;
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) continue;
        foreach ($data['items'] as $it) {
            $wid = (int) ($it['wp_user_id'] ?? 0);
            if ($wid <= 0) continue;
            $out[$wid] = [
                'discussion' => (($it['discussion_visibility'] ?? 'member') === 'public') ? 'public' : 'member',
                'profile'    => (($it['profile_visibility'] ?? 'public') === 'private') ? 'private' : 'public',
            ];
        }
    }
    return $out;
}

// ---------- rollup refresh -------------------------------------------------

function bb_mirror_refresh_effective_group(PDO $db, int $root_forum_id): void {
    $db->prepare("
        WITH RECURSIVE descendants AS (
          SELECT id FROM forum WHERE id = :root
          UNION ALL
          SELECT f.id FROM forum f JOIN descendants d ON f.parent_forum_id = d.id
        ),
        chain AS (
          SELECT id AS leaf_id, id AS at_id, parent_forum_id, group_id
            FROM forum WHERE id IN (SELECT id FROM descendants)
          UNION ALL
          SELECT c.leaf_id, f.id, f.parent_forum_id, f.group_id
            FROM chain c JOIN forum f ON f.id = c.parent_forum_id
           WHERE c.group_id IS NULL
        )
        UPDATE forum SET effective_group_id = sub.gid
          FROM (
            SELECT leaf_id, MAX(group_id) FILTER (WHERE group_id IS NOT NULL) AS gid
              FROM chain GROUP BY leaf_id
          ) sub
         WHERE forum.id = sub.leaf_id
    ")->execute([':root' => $root_forum_id]);
}

// ---------- upserts -------------------------------------------------------

function bb_mirror_unique_forum_slug(PDO $db, string $base, int $id): string {
    // BB allows the same post_name under different parents, so two forums can
    // arrive with an identical slug (Acoustic Repair vs Acoustic Builds). Keep
    // pg slugs unique: lowest-id forum keeps the bare slug, collisions get -N
    // (the same shape BB itself gave electric/electric-2). Stable across re-sync
    // because the occupancy check excludes the row's own id.
    $base = $base !== '' ? $base : ('forum-' . $id);
    $slug = $base; $n = 1;
    $stmt = $db->prepare("SELECT 1 FROM forum WHERE slug = ? AND id <> ? LIMIT 1");
    while (true) {
        $stmt->execute([$slug, $id]);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . (++$n);
    }
}

function bb_mirror_upsert_forum(int $id, PDO $db): void {
    $p = get_post($id);
    if (!$p || $p->post_type !== 'forum') {
        $db->prepare("DELETE FROM forum WHERE id = ?")->execute([$id]);
        return;
    }
    $m = bb_mirror_post_meta_all($id);
    $cols = ['id','slug','title','description','parent_forum_id','menu_order','group_id',
             'forum_type','status','visibility','tier_gate',
             'topic_count','reply_count','total_topic_count','total_reply_count',
             'last_topic_id','last_reply_id','last_active_id','last_active_at',
             'created_at','modified_at','sync_at'];
    $slug = bb_mirror_unique_forum_slug($db, (string)$p->post_name, $id);
    $sql = bb_mirror_upsert_sql('forum', $cols);
    $db->prepare($sql)->execute([
        $id, $slug,
        _bb_mirror_decode($p->post_title),
        wp_kses_post(_bb_mirror_decode($p->post_content)),
        (int)$p->post_parent ?: null, (int)$p->menu_order,
        _bb_mirror_first_group_id($m['_bbp_group_ids'] ?? null),
        $m['_bbp_forum_type']       ?? 'forum',
        $m['_bbp_status']           ?? 'open',
        _bb_mirror_visibility($m['_bbp_forum_visibility'] ?? null, (string)$p->post_status),
        'public',
        (int)($m['_bbp_topic_count']        ?? 0),
        (int)($m['_bbp_reply_count']        ?? 0),
        (int)($m['_bbp_total_topic_count']  ?? 0),
        (int)($m['_bbp_total_reply_count']  ?? 0),
        (int)($m['_bbp_last_topic_id']      ?? 0) ?: null,
        (int)($m['_bbp_last_reply_id']      ?? 0) ?: null,
        (int)($m['_bbp_last_active_id']     ?? 0) ?: null,
        bb_mirror_ts(bb_mirror_ts_in($m['_bbp_last_active_time'] ?? null)),
        bb_mirror_ts(strtotime($p->post_date_gmt . ' UTC')),
        bb_mirror_ts(strtotime($p->post_modified_gmt . ' UTC')),
        bb_mirror_ts(time()),
    ]);
    bb_mirror_refresh_effective_group($db, $id);
}

// An attachment row whose file is gone becomes a BROKEN CARD COVER (the feed
// promotes attachments to covers — Buck audit 6/11 found 36 dead rows, mostly
// test uploads). Stat local uploads before writing the row; URLs outside the
// uploads tree can't be verified and pass through. Runs under WP (www-data),
// which CAN traverse the uploads dirs (the ubuntu user cannot).
function bb_mirror_attachment_file_exists(string $url): bool {
    $up    = wp_get_upload_dir();
    $upath = parse_url($url, PHP_URL_PATH) ?: '';
    $bpath = parse_url((string)$up['baseurl'], PHP_URL_PATH) ?: '';
    if ($bpath === '' || strpos($upath, $bpath) !== 0) return true; // not a local upload
    return file_exists($up['basedir'] . substr($upath, strlen($bpath)));
}

// Harvest BB media attachments + inline <img> URLs for a topic/reply.
// Source priority:
//   1. wp_postmeta.bp_media_ids (CSV of wp_bp_media.id) — BB Platform media uploads
//   2. inline <img src="..."> in post_content (defensive, less common)
// Delete-then-insert pattern keeps the table idempotent without composite PK.
function bb_mirror_sync_attachments(int $parent_id, string $kind, PDO $db, string $content_html): void {
    global $wpdb;

    $db->prepare("DELETE FROM attachment WHERE parent_kind = ? AND parent_id = ?")
       ->execute([$kind, $parent_id]);

    $rows = [];

    // ---- 1. BP media ----
    $csv = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta}
          WHERE post_id = %d AND meta_key = 'bp_media_ids' LIMIT 1", $parent_id));
    if ($csv) {
        $media_ids = array_filter(array_map('intval', explode(',', $csv)));
        if ($media_ids) {
            $placeholders = implode(',', array_fill(0, count($media_ids), '%d'));
            $media_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, attachment_id, title, type, menu_order
                   FROM {$wpdb->prefix}bp_media
                  WHERE id IN ($placeholders)
                  ORDER BY menu_order ASC, id ASC",
                ...$media_ids
            ));
            foreach ($media_rows as $r) {
                $att_id = (int)$r->attachment_id;
                if (!$att_id) continue;
                $url = wp_get_attachment_image_url($att_id, 'large');
                if (!$url) $url = wp_get_attachment_url($att_id);
                if (!$url) continue;
                $meta = wp_get_attachment_metadata($att_id);
                $rows[] = [
                    'url'   => $url,
                    'alt'   => (string)($r->title ?: ''),
                    'mime'  => get_post_mime_type($att_id) ?: null,
                    'width' => isset($meta['width'])  ? (int)$meta['width']  : null,
                    'height'=> isset($meta['height']) ? (int)$meta['height'] : null,
                ];
            }
        }
    }

    // ---- 2. inline <img> in post_content (defensive) ----
    if ($content_html && preg_match_all('#<img[^>]+src=["\']([^"\']+)["\'][^>]*>#i', $content_html, $matches)) {
        $seen_urls = array_column($rows, 'url');
        foreach ($matches[1] as $i => $src) {
            $src = html_entity_decode((string)$src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$src || in_array($src, $seen_urls, true)) continue;
            // Pull alt attribute if present
            $alt = '';
            if (preg_match('#alt=["\']([^"\']*)["\']#i', $matches[0][$i], $am)) $alt = $am[1];
            $rows[] = ['url' => $src, 'alt' => $alt, 'mime' => null, 'width' => null, 'height' => null];
        }
    }

    if (!$rows) return;

    $stmt = $db->prepare(
        "INSERT INTO attachment (parent_kind, parent_id, url, alt, mime, width, height, position, sync_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $pos = 0;
    foreach ($rows as $row) {
        if (!bb_mirror_attachment_file_exists($row['url'])) continue; // dead file = broken cover
        $stmt->execute([
            $kind, $parent_id,
            $row['url'], $row['alt'] ?: null,
            $row['mime'], $row['width'], $row['height'],
            $pos++, bb_mirror_ts(time())
        ]);
    }
}

function bb_mirror_upsert_topic(int $id, PDO $db): void {
    $p = get_post($id);
    if (!$p || $p->post_type !== 'topic') {
        // A SECOND delete path, and not the endpoint's: this is how reconcile
        // (bin/reconcile.php) drops a topic whose WP post vanished or was
        // retyped. Its `attachment` rows — and those of every reply cascaded
        // away underneath it — are removed by the AFTER DELETE triggers in
        // schema.pg.sql. Do not add cleanup here; see the note in
        // api/v0/_sync.php for why a PHP-side purge cannot work.
        $db->prepare("DELETE FROM topic WHERE id = ?")->execute([$id]);
        return;
    }
    $m = bb_mirror_post_meta_all($id);
    $forum_id = (int)($m['_bbp_forum_id'] ?? 0);
    if (!$forum_id) return;

    $body_text = wp_strip_all_tags((string)$p->post_content);
    $person = bb_mirror_person_for((int)$p->post_author, $db);
    // Sticky lookup: `_bbp_sticky_topics` is a serialized array of topic IDs
    // stored on the FORUM (not the topic itself). `_bbp_super_sticky_topics`
    // is a serialized array site-wide option. Check both for our topic id.
    $sticky = null;
    $super_list = get_option('_bbp_super_sticky_topics', []);
    if (is_array($super_list) && in_array($id, array_map('intval', $super_list), true)) {
        $sticky = 'super';
    } else {
        $forum_stickies = get_post_meta($forum_id, '_bbp_sticky_topics', true);
        if (is_array($forum_stickies) && in_array($id, array_map('intval', $forum_stickies), true)) {
            $sticky = 'forum';
        }
    }
    $featured_url = null;
    if (!empty($m['_thumbnail_id'])) {
        $featured_url = wp_get_attachment_image_url((int)$m['_thumbnail_id'], 'medium') ?: null;
    }

    // Topic tags (bbPress 'topic-tag' taxonomy) -> pg text[] literal.
    $tag_names = wp_get_object_terms($id, 'topic-tag', ['fields' => 'names']);
    $tags_literal = (is_array($tag_names) && $tag_names)
        ? '{' . implode(',', array_map(fn($t) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$t) . '"', $tag_names)) . '}'
        : null;

    // REAL reply count (Ian 2026-06-10: a card said "5 replies", the thread had
    // 2 — bbPress's _bbp_reply_count meta drifts and we copied it verbatim).
    // Count published replies at the source instead.
    $real_reply_count = (int)$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='reply' AND post_status='publish' AND post_parent=%d", $id));

    $cols = ['id','forum_id','slug','title','content_html','content_text','featured_image_url',
             'author_id','author_name','author_slug','anonymous_name','is_anon',
             'status','sticky_kind','voice_count','reply_count',
             'last_reply_id','last_active_id','last_active_at',
             'tier_gate','tags','created_at','modified_at','sync_at'];
    $sql = bb_mirror_upsert_sql('topic', $cols);
    $db->prepare($sql)->execute([
        $id, $forum_id, $p->post_name, _bb_mirror_decode($p->post_title),
        wp_kses_post(_bb_mirror_decode($p->post_content)), $body_text, $featured_url,
        (int)$p->post_author ?: null, $person['name'], $person['slug'],
        $m['_bbp_anonymous_name'] ?? null,
        bb_mirror_bool(!empty($m['_lg_anon'])),
        $m['_bbp_topic_status'] ?? $p->post_status,
        $sticky,
        (int)($m['_bbp_voice_count']     ?? 0),
        $real_reply_count,
        (int)($m['_bbp_last_reply_id']   ?? 0) ?: null,
        (int)($m['_bbp_last_active_id']  ?? 0) ?: null,
        bb_mirror_ts(bb_mirror_ts_in($m['_bbp_last_active_time'] ?? null)),
        'public',
        $tags_literal,
        bb_mirror_ts(strtotime($p->post_date_gmt . ' UTC')),
        bb_mirror_ts(strtotime($p->post_modified_gmt . ' UTC')),
        bb_mirror_ts(time()),
    ]);
    bb_mirror_sync_attachments($id, 'topic', $db, (string)$p->post_content);
}

/**
 * WHY A ROW WAS NOT WRITTEN — the pipe announcing its own failures.
 *
 * ⚠️ THE MATERIALIZERS RETURN void AND SKIP SILENTLY BY DESIGN, and that design is
 * right about the throwing (an uncaught PDOException here wedged live's reconcile
 * for 11 days) and wrong about the silence. A skip is indistinguishable from a
 * write to every caller, so the realtime receiver answered 200 for both — which is
 * precisely why the 2026-08-09 access-log analysis found all 290 _sync POSTs
 * returning 200 and could not tell the 11 dropped replies from the 61 that landed.
 * Reply 71432 then sat wrong for over two months and only an EXTERNAL watcher
 * noticed.
 *
 * So the skip is recorded here rather than swallowed. Callers decide what to do
 * with it: reconcile already prints a skip report, and _sync now answers 202 and
 * logs, so the drop is visible in nginx's own access log the day it happens.
 *
 * Deliberately a global rather than a return-type change: these functions have
 * several callers and a signature change is a bigger blast radius than this fix
 * deserves. Cleared at the START of every upsert so a stale reason can never be
 * read as a fresh failure.
 */
function bb_mirror_note_skip(string $kind, int $id, string $reason): void {
    $GLOBALS['bb_mirror_last_skip'] = ['kind' => $kind, 'id' => $id, 'reason' => $reason];
    error_log("[bb-mirror] SKIP $kind#$id: $reason (no row written)");
}

/** The skip recorded since the last bb_mirror_clear_skip(), or null. */
function bb_mirror_last_skip(): ?array {
    return $GLOBALS['bb_mirror_last_skip'] ?? null;
}

function bb_mirror_clear_skip(): void {
    $GLOBALS['bb_mirror_last_skip'] = null;
}

function bb_mirror_upsert_reply(int $id, PDO $db): void {
    bb_mirror_clear_skip();
    $p = get_post($id);
    if (!$p || $p->post_type !== 'reply') {
        // Reconcile's reply-delete path (see bb_mirror_upsert_topic above).
        // The reply's `attachment` rows go by AFTER DELETE trigger.
        $db->prepare("DELETE FROM reply WHERE id = ?")->execute([$id]);
        return;
    }
    $m = bb_mirror_post_meta_all($id);
    $topic_id = (int)($m['_bbp_topic_id'] ?? 0);
    $forum_id = (int)($m['_bbp_forum_id'] ?? 0);
    if (!$topic_id || !$forum_id) {
        // Live carries exactly 2 of these (71432, 71433) — replies with no
        // _bbp_topic_id row at all. Unmirrorable until the WP data is repaired.
        bb_mirror_note_skip('reply', $id, sprintf(
            'missing parentage meta (topic_id=%d forum_id=%d)', $topic_id, $forum_id));
        return;
    }

    // SELF-HEAL the reply->topic FK before we hit it.
    //
    // reply.topic_id is a FOREIGN KEY. Two ways to arrive here with no parent row:
    //   1. RACE (real, and the whole point of this block): the realtime dispatch is
    //      fire-and-forget, so a topic's sync POST and its first reply's can land out
    //      of order — or the topic's can be dropped outright. The reply then has a
    //      perfectly good topic that simply is not mirrored YET. Materializing the
    //      parent on the spot fixes the reply AND the missing discussion.
    //   2. BROKEN PARENTAGE: _bbp_topic_id points at something that is not a topic at
    //      all. Live carries 4 of these from June (71720/71722 -> 71685, an
    //      ATTACHMENT; 71728 -> 71671, absent; 71433 -> nothing). No amount of
    //      retrying invents a topic; the row is unmirrorable until the WP data is
    //      repaired, so we return quietly and let the caller's skip-report and the
    //      lag tripwire carry it. What we must NOT do is throw, because an uncaught
    //      PDOException here is exactly what wedged live's reconcile for 11 days
    //      (2026-07-29 23:20 UTC -> 2026-08-09, see tools/mirror-sync/).
    $has_topic = $db->prepare("SELECT 1 FROM topic WHERE id = ?");
    $has_topic->execute([$topic_id]);
    if (!$has_topic->fetchColumn()) {
        bb_mirror_upsert_topic($topic_id, $db);
        $has_topic->execute([$topic_id]);
        if (!$has_topic->fetchColumn()) {
            // case 2 — unmirrorable, not fatal. Live carries exactly 3 (71720,
            // 71722 -> 71685 an ATTACHMENT; 71728 -> 71671, absent).
            bb_mirror_note_skip('reply', $id, sprintf(
                'topic %d is not a mirrorable topic', $topic_id));
            return;
        }
    }

    // SELF-HEAL the reply->parent_reply FK, the same way and for the same reasons.
    // reply.parent_reply_id is ALSO a foreign key, so a reply-to-reply whose parent
    // is unmirrored throws exactly as hard as a missing topic did (reproduced on
    // dev2 2026-08-09: reply_parent_reply_id_fkey, parent 71422 absent).
    //
    // Ordering here is the reverse of the topic case: the parent is a sibling row,
    // so ONE non-recursive attempt to materialize it is worth making — but if it
    // still will not land we drop the thread link rather than the reply. A reply
    // that renders at the top level is a cosmetic defect; a reply that does not
    // render at all is the defect we are here to kill. The link is not lost for
    // good either: this is read fresh from _bbp_reply_to on every later upsert.
    $parent_reply_id = (int)($m['_bbp_reply_to'] ?? 0) ?: null;
    if ($parent_reply_id !== null) {
        static $healing_parent = [];   // no A->B->A cascade, and no deep recursion
        $has_parent = $db->prepare("SELECT 1 FROM reply WHERE id = ?");
        $has_parent->execute([$parent_reply_id]);
        if (!$has_parent->fetchColumn()) {
            if (!isset($healing_parent[$parent_reply_id])) {
                $healing_parent[$parent_reply_id] = true;
                try { bb_mirror_upsert_reply($parent_reply_id, $db); } catch (Throwable) {}
                unset($healing_parent[$parent_reply_id]);
                $has_parent->execute([$parent_reply_id]);
            }
            if (!$has_parent->fetchColumn()) { $parent_reply_id = null; }
        }
    }

    $body_text = wp_strip_all_tags((string)$p->post_content);
    $person = bb_mirror_person_for((int)$p->post_author, $db);

    $cols = ['id','topic_id','forum_id','parent_reply_id','content_html','content_text',
             'author_id','author_name','author_slug','anonymous_name','is_anon',
             'status','created_at','modified_at','sync_at'];
    $sql = bb_mirror_upsert_sql('reply', $cols);
    $db->prepare($sql)->execute([
        $id, $topic_id, $forum_id, $parent_reply_id,
        wp_kses_post(_bb_mirror_decode($p->post_content)), $body_text,
        (int)$p->post_author ?: null, $person['name'], $person['slug'],
        $m['_bbp_anonymous_name'] ?? null,
        bb_mirror_bool(!empty($m['_lg_anon'])),
        $p->post_status,
        bb_mirror_ts(strtotime($p->post_date_gmt . ' UTC')),
        bb_mirror_ts(strtotime($p->post_modified_gmt . ' UTC')),
        bb_mirror_ts(time()),
    ]);
    bb_mirror_sync_attachments($id, 'reply', $db, (string)$p->post_content);
}

/**
 * bb_mirror_walk_ids — run a materializer over a list of ids so that ONE BAD ROW
 * CANNOT WEDGE THE WALK.
 *
 * THE DEFECT THIS ENCODES (live, 2026-07-29 23:20 UTC -> 2026-08-09, 11 days):
 * bin/reconcile.php ran the delta walk as a bare `foreach { upsert($id); }` and
 * ended with the `last_reconcile_at` bookmark write. On 07-29 23:20 the bookmark
 * was rewound to 2026-06-01, which widened the walk to include reply 71720 —
 * whose _bbp_topic_id points at an ATTACHMENT. The FK threw, the exception was
 * uncaught, the process died at row 109 of 385, and because the bookmark write
 * lives AFTER the walk it never ran. So the next run rewalked the same window,
 * hit the same row, and died again — every 10 minutes for 11 days. The ghost
 * sweep, the reply_count rollup and both forum rollups never ran either.
 *
 * The compounding part is the silence: reconcile is the ONLY safety net under a
 * fire-and-forget realtime dispatch, so from the moment it wedged, a dropped sync
 * POST became permanent. 11 of 70 replies posted in that window (16%) went
 * invisible on the hub, and every single one was rescued only by its author
 * happening to EDIT the post — up to 2d22h later.
 *
 * So: a throwing row is DATA, not control flow. Skip it, record it, keep walking,
 * and always reach the bookmark. Callers report $skipped; the tripwire
 * (tools/mirror-sync/watch-mirror-sync.sh) is what makes it loud.
 *
 * Returns ['done' => int, 'skipped' => [id => error string, ...]].
 *
 * Pass $db so a row that throws mid-transaction cannot poison the rest of the
 * walk: Postgres fails every subsequent statement on an aborted transaction with
 * "current transaction is aborted", which would turn one bad row into a whole
 * dead run again by a different route.
 */
function bb_mirror_walk_ids(array $ids, callable $fn, ?PDO $db = null): array {
    $done = 0;
    $skipped = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        try {
            $fn($id);
            $done++;
        } catch (Throwable $e) {
            if ($db !== null && $db->inTransaction()) {
                try { $db->rollBack(); } catch (Throwable) { /* already unwound */ }
            }
            $skipped[$id] = $e->getMessage();
        }
    }
    return ['done' => $done, 'skipped' => $skipped];
}

/**
 * Recompute a single topic's stored reply_count from the authoritative source
 * (WP published replies — the SAME source as bb_mirror_upsert_topic's
 * $real_reply_count above).
 *
 * Why this is needed: bbPress does NOT bump a topic's post_modified_gmt when a
 * reply is added/removed — it only touches the reply post + the topic's
 * _bbp_* meta. So neither the per-reply _sync (which upserts the reply row but
 * never the parent topic) nor bin/reconcile.php's modified-since delta-walk
 * re-materializes the parent topic, and topic.reply_count drifts. The card then
 * shows "0 replies" while the live facepile (a direct query over the reply
 * table) shows avatars. Call this on every reply mutation. WP-sourced (not a
 * COUNT over the pg reply table) so orphaned pg reply rows — replies trashed in
 * WP whose delete never propagated — can't inflate the count.
 */
// GHOST SWEEP — the reverse pass. Everything else in this file is driven from
// wp_posts, so it can only repair rows that STILL EXIST in WordPress. A row that
// exists ONLY in the mirror — its WP post deleted without the mirror hearing —
// can never be reached that way, and nothing else looks for it. Those are
// GHOSTS, and unlike an orphan a ghost is `status='publish'` under a living
// topic, so IT STILL RENDERS: members see replies that were deleted. Measured on
// live 2026-07-28: 13 ghost replies + 2 ghost topics. No existing check could see
// them — the orphan census reads 0 for a ghost by definition, because its
// attachment rows still have a parent row.
//
// Two paths produce them, neither fixable by a database constraint:
//   * bb-mirror-sync.php hooks bbp_deleted_topic/reply and NO WP-native delete
//     hook, so wp-admin bulk delete, wp_delete_post(), WP-CLI and direct SQL all
//     remove the post silently.
//   * the dispatch is wp_remote_post(..., 'blocking' => false, timeout 1) — fire
//     and forget: no response read, no retry, no log.
//
// $wp_ids_for('topic'|'reply') must return ALL WordPress ids of that type.
// Returns a per-kind report; caller decides how to print it.
//
// It is deliberately hard to make this delete a lot:
//   - $apply defaults to false. Report first, sweep once the numbers are trusted.
//   - an EMPTY WordPress id set aborts that kind. If wp_posts returns nothing
//     while the mirror is full, that is a broken query, not an empty forum —
//     deleting the mirror because a SELECT failed is the one catastrophic
//     outcome available here.
//   - a blast-radius cap: more than $cap_abs rows, or more than $cap_pct of the
//     table, refuses and asks for a human.
// With the attachment purge triggers installed (schema.pg.sql), deleting a ghost
// row also removes its attachment rows, so the repair is complete rather than
// trading a ghost for an orphan.
function bb_mirror_sweep_ghosts(
    PDO $db, callable $wp_ids_for, bool $apply = false,
    int $cap_abs = 100, int $cap_pct = 5
): array {
    $report = [];
    // REPLIES FIRST, THEN TOPICS. Deleting a topic row CASCADES to its replies
    // (reply.topic_id is ON DELETE CASCADE), and a cascaded reply that still
    // exists in WordPress would silently vanish from the forum — the delta walk
    // would not bring it back, because it only looks at posts modified inside
    // the bookmark window and these are old. Sweeping replies first means that
    // by the time a ghost topic is considered, every reply still under it is one
    // that EXISTS IN WORDPRESS, and the guard below can simply refuse.
    // Caught by the test fixture for the retyped case, not by inspection.
    foreach (['reply', 'topic'] as $kind) {
        $wp_ids = $wp_ids_for($kind);
        if (!$wp_ids) {
            $report[$kind] = ['status' => 'abort_empty_wp', 'ghosts' => 0, 'total' => 0, 'ids' => [], 'held' => []];
            continue;
        }
        $wp_have    = array_flip(array_map('intval', $wp_ids));
        $mirror_ids = $db->query("SELECT id FROM $kind")->fetchAll(PDO::FETCH_COLUMN);
        $total      = count($mirror_ids);

        $ghosts = [];
        foreach ($mirror_ids as $mid) {
            $mid = (int)$mid;
            // A RETYPED post is a ghost for this table and correct to remove:
            // live 71433 is a topic in the mirror but a reply in WordPress, so
            // the topic row must go and the reply upsert re-creates it properly.
            if (!isset($wp_have[$mid])) $ghosts[] = $mid;
        }
        $n = count($ghosts);
        if ($n === 0) {
            $report[$kind] = ['status' => 'clean', 'ghosts' => 0, 'total' => $total, 'ids' => [], 'held' => []];
            continue;
        }

        $allowed = max($cap_abs, (int)ceil($total * $cap_pct / 100));
        if ($n > $allowed) {
            $report[$kind] = ['status' => 'refused_cap', 'ghosts' => $n, 'total' => $total,
                              'ids' => $ghosts, 'allowed' => $allowed, 'held' => []];
            continue;
        }
        // A ghost TOPIC whose replies still exist in WordPress must not be
        // touched: the FK cascade would take those live replies with it. Hold it
        // for a human instead. (On live 2026-07-28 no ghost topic had any mirror
        // replies, so this holds nothing today — it is here because this runs on
        // a timer forever and the retyped case can produce exactly this shape.)
        $held = [];
        if ($kind === 'topic' && $ghosts) {
            $wp_reply_ids  = array_flip(array_map('intval', $wp_ids_for('reply')));
            $stmt = $db->prepare("SELECT id FROM reply WHERE topic_id = ?");
            foreach ($ghosts as $i => $gid) {
                $stmt->execute([$gid]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                    if (isset($wp_reply_ids[(int)$rid])) { $held[] = $gid; unset($ghosts[$i]); break; }
                }
            }
            $ghosts = array_values($ghosts);
            $n = count($ghosts);
        }

        if (!$apply) {
            $report[$kind] = ['status' => 'report_only', 'ghosts' => $n, 'total' => $total,
                              'ids' => $ghosts, 'held' => $held];
            continue;
        }

        $del = $db->prepare("DELETE FROM $kind WHERE id = ?");
        foreach ($ghosts as $gid) $del->execute([$gid]);
        $report[$kind] = ['status' => 'swept', 'ghosts' => $n, 'total' => $total,
                          'ids' => $ghosts, 'held' => $held];
    }
    return $report;
}

function bb_mirror_refresh_topic_reply_count(int $topic_id, PDO $db): void {
    if ($topic_id <= 0) return;
    $real = (int)$GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts}
          WHERE post_type='reply' AND post_status='publish' AND post_parent=%d", $topic_id));
    $db->prepare("UPDATE topic SET reply_count = ?, sync_at = ? WHERE id = ?")
       ->execute([$real, bb_mirror_ts(time()), $topic_id]);
}

/**
 * Sitewide reply_count rollup: recompute every topic's stored reply_count from
 * WP published replies in a single grouped query, writing only the rows that
 * drifted. Used by bin/reconcile.php (belt-and-suspenders) and
 * bin/backfill-reply-count.php (one-time historical catch-up). Idempotent;
 * returns the number of topics corrected.
 */
function bb_mirror_refresh_all_reply_counts(PDO $db): int {
    $rows = $GLOBALS['wpdb']->get_results(
        "SELECT post_parent AS topic_id, COUNT(*) AS c
           FROM {$GLOBALS['wpdb']->posts}
          WHERE post_type='reply' AND post_status='publish'
          GROUP BY post_parent", ARRAY_A);
    $real = [];
    foreach ($rows as $r) $real[(int)$r['topic_id']] = (int)$r['c'];

    $upd = $db->prepare("UPDATE topic SET reply_count = ?, sync_at = ? WHERE id = ?");
    $ts  = bb_mirror_ts(time());
    $fixed = 0;
    foreach ($db->query("SELECT id, reply_count FROM topic")->fetchAll() as $t) {
        $want = $real[(int)$t['id']] ?? 0;     // topics with no published replies → 0
        if ((int)$t['reply_count'] !== $want) {
            $upd->execute([$want, $ts, (int)$t['id']]);
            $fixed++;
        }
    }
    return $fixed;
}

function bb_mirror_upsert_bp_group(int $id, PDO $db): void {
    global $wpdb;
    $g = $wpdb->get_row($wpdb->prepare(
        "SELECT g.id, g.slug, g.name, g.description, g.status,
                UNIX_TIMESTAMP(g.date_created) AS created_at
           FROM {$wpdb->prefix}bp_groups g WHERE g.id = %d", $id));
    if (!$g) {
        $db->prepare("DELETE FROM bp_group WHERE id = ?")->execute([$id]);
        return;
    }
    $forum_id_meta = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}bp_groups_groupmeta
          WHERE group_id = %d AND meta_key = 'forum_id' LIMIT 1", $id));
    $forum_id = _bb_mirror_first_group_id($forum_id_meta);
    $members = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->prefix}bp_groups_groupmeta
          WHERE group_id = %d AND meta_key = 'total_member_count' LIMIT 1", $id));

    $cols = ['id','slug','name','description','status',
             'attached_forum_id','member_count','created_at','sync_at'];
    $sql = bb_mirror_upsert_sql('bp_group', $cols);
    $db->prepare($sql)->execute([
        $id, $g->slug,
        _bb_mirror_decode($g->name),
        _bb_mirror_decode($g->description),
        in_array($g->status, ['public','private','hidden'], true) ? $g->status : 'public',
        $forum_id ?: null,
        $members,
        bb_mirror_ts((int)$g->created_at),
        bb_mirror_ts(time()),
    ]);
}
