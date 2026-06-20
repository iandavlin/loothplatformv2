<?php
require __DIR__.'/../config.php';
require_once __DIR__.'/indexer.php';   // archive_poc_resolve_category() — shared per-post normalization
/**
 * archive-poc/bin/backfill-pg.php — postgres port of backfill.php.
 *
 * Boots WordPress, walks wp_posts, writes a normalized content_item index
 * into the `discovery` schema on postgres. Driver picked by LG_ARCHIVE_POC_DSN.
 *
 * Usage (dev, peer auth):
 *   LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
 *     sudo -u archive-poc php bin/backfill-pg.php
 *
 * READ-ONLY on WordPress. SELECT only.
 *
 * Differences from backfill.php:
 *   - PDO via lg_archive_poc_pdo() (env-driven DSN)
 *   - INSERT OR REPLACE → INSERT ... ON CONFLICT DO UPDATE
 *   - INSERT OR IGNORE  → INSERT ... ON CONFLICT DO NOTHING
 *   - Unix timestamps converted to ISO 8601 for TIMESTAMPTZ binding
 *   - No content_fts rebuild — tag_text is a real column on content_item,
 *     populated during the main insert pass; the tsvector column is
 *     GENERATED STORED, postgres maintains it
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

// --force overrides the shrink safety check (below) for an intentional
// large reduction (e.g. a CPT was retired). Without it, a rebuild that would
// leave content_item at <50% of its prior size aborts and rolls back.
$FORCE = in_array('--force', $argv ?? [], true);

if (!function_exists('wp_get_attachment_image_url')) {
    if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = LG_ARCHIVE_POC_HOST;
    if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    require LG_ARCHIVE_POC_WP_LOAD;
}

global $wpdb;
$wpdb->suppress_errors(true);

// --- CPT → kind mapping (mirrors backfill.php; keep in sync) -------------
$KIND_MAP = [
    'post-imgcap'     => 'article',
    'post-type-videos'=> 'video',
    'loothprint'      => 'loothprint',
    'loothcuts'       => 'loothcuts',
    'document'        => 'document',
    'member-benefit'  => 'benefit',
    'sponsor-product' => 'benefit',
    'sponsor-page'    => 'misc',
    'sponsor-post'    => 'sponsor-post',
    'sponsor'         => 'misc',
    'useful_links'    => 'useful_links',
    'shorty'          => 'shorty',
    'event'           => 'event',
    // 'topic' => 'discussion' intentionally DROPPED (Hub-fold lane, 2026-06-05):
    // forum discussions are served from forums.* in PG; re-indexing them here
    // duplicated 1,263 rows. content_item is now content-only (~708 rows). The
    // discussion-handling branches below are unreachable as a result (kept inert).
];

$TAG_TAXONOMIES = [
    'post_tag','post-tag','topic-tag','hash-tag',
    'video-type','loothprint_type','loothprint-type','useful_link',
    'member-benefit-type','sponsor-post-type',
    'event_type','event-type','shared_category','featured',
    'business-type','language','region','shows',
    'mp2t_knowledge_domains','mp2t_musical_contexts','mp2t_work_on',
];

// --- Open postgres --------------------------------------------------------
$db = lg_archive_poc_pdo();
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    fwrite(STDERR, "backfill-pg.php requires LG_ARCHIVE_POC_DSN with pgsql driver\n");
    exit(1);
}

// Pre-count: how many items the live feed has RIGHT NOW. The TRUNCATE +
// rebuild happens inside one transaction (opened below) so a mid-run failure
// rolls the wipe back and the feed survives; this count additionally guards
// against a *successful* but near-empty rebuild silently blanking the feed
// (e.g. WP returns nothing transiently — no exception, just zero rows).
$pre_count = (int) $db->query('SELECT count(*) FROM content_item')->fetchColumn();
echo "content_item rows before rebuild: $pre_count\n";

// --- Helpers --------------------------------------------------------------

function clean_text(?string $html): string {
    if (!$html) return '';
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function extract_v2_text($blocks): string {
    if (!is_array($blocks)) return '';
    $out = [];
    $walk = function ($node) use (&$walk, &$out) {
        if (!is_array($node)) return;
        $type = $node['type'] ?? null;
        if ($type === 'wysiwyg' && isset($node['html']))         $out[] = clean_text($node['html']);
        if ($type === 'callout' && isset($node['body']))         $out[] = clean_text($node['body']);
        if ($type === 'transcript' && isset($node['text']))      $out[] = clean_text($node['text']);
        if ($type === 'post-header') {
            if (!empty($node['title']))   $out[] = $node['title'];
            if (!empty($node['tagline'])) $out[] = $node['tagline'];
        }
        if ($type === 'section-heading' && isset($node['text'])) $out[] = $node['text'];
        if ($type === 'image' && !empty($node['caption']))       $out[] = clean_text($node['caption']);
        if ($type === 'gallery' && !empty($node['image_text']))  $out[] = clean_text($node['image_text']);
        if ($type === 'columns' && !empty($node['columns'])) {
            foreach ($node['columns'] as $col) {
                if (!empty($col['blocks'])) foreach ($col['blocks'] as $b) $walk($b);
            }
        }
        if (!empty($node['blocks']) && is_array($node['blocks'])) {
            foreach ($node['blocks'] as $b) $walk($b);
        }
    };
    foreach ($blocks as $b) $walk($b);
    return trim(implode(' ', array_filter($out)));
}

function first_image_url(?string $html): ?string {
    if (!$html) return null;
    if (preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m)) return $m[1];
    return null;
}

function first_bp_media_thumb(int $post_id): ?string {
    $ids_csv = (string) get_post_meta($post_id, 'bp_media_ids', true);
    if ($ids_csv === '') return null;
    $ids = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $ids_csv)))));
    if (!$ids) return null;
    global $wpdb;
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "SELECT attachment_id FROM {$wpdb->prefix}bp_media
            WHERE id IN ($ph) ORDER BY FIELD(id, $ph) LIMIT 1";
    $att_id = (int) $wpdb->get_var($wpdb->prepare($sql, ...array_merge($ids, $ids)));
    if (!$att_id) return null;
    return wp_get_attachment_image_url($att_id, 'full') ?: null;
}

function resolve_thumb(int $post_id, string $body_html): ?string {
    $tid = (int) get_post_thumbnail_id($post_id);
    if ($tid > 0) {
        $u = wp_get_attachment_image_url($tid, 'full');
        if ($u) return $u;
    }
    $u = first_bp_media_thumb($post_id);
    if ($u) return $u;
    return first_image_url($body_html);
}

function resolve_tier(int $post_id, string $kind): string {
    global $wpdb;
    $slugs = $wpdb->get_col($wpdb->prepare("
        SELECT t.slug FROM {$wpdb->terms} t
        JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
        JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE tt.taxonomy = 'tier' AND tr.object_id = %d
    ", $post_id));
    if ($slugs) {
        $rank = ['public' => 0, 'looth-lite' => 1, 'lite' => 1, 'looth-pro' => 2, 'pro' => 2];
        $best = 'public'; $best_rank = -1;
        foreach ($slugs as $slug) {
            $r = $rank[$slug] ?? -1;
            if ($r > $best_rank) { $best_rank = $r; $best = $slug; }
        }
        return match ($best) {
            'looth-pro', 'pro'   => 'pro',
            'looth-lite', 'lite' => 'lite',
            default              => 'public',
        };
    }
    if ($kind === 'event') {
        $t = get_post_meta($post_id, 'event_tier_', true);
        if (is_string($t) && $t !== '') return strtolower($t);
    }
    $t = get_post_meta($post_id, '_post_tier', true);
    if (is_string($t) && $t !== '') return strtolower($t);
    return 'public';
}

/** Unix timestamp → ISO 8601 UTC, suitable for TIMESTAMPTZ bind. NULL passes through. */
function ts_iso(?int $ts): ?string {
    if ($ts === null || $ts <= 0) return null;
    return gmdate('c', $ts);
}

function extract_event_fields(int $post_id): array {
    $out = ['start' => null, 'end' => null, 'region' => null, 'join_url' => null];
    $srow = get_post_meta($post_id, 'evcal_srow', true);
    $erow = get_post_meta($post_id, 'evcal_erow', true);
    if (is_numeric($srow) && (int)$srow > 0) $out['start'] = (int) $srow;
    if (is_numeric($erow) && (int)$erow > 0) $out['end']   = (int) $erow;
    if ($out['start'] === null) {
        $sd = (string) get_post_meta($post_id, 'events_start_date_and_time_', true);
        $st = (string) get_post_meta($post_id, 'time_of_event', true);
        if ($sd !== '') {
            $date = (strlen($sd) === 8) ? substr($sd,0,4).'-'.substr($sd,4,2).'-'.substr($sd,6,2) : $sd;
            $time = $st !== '' ? $st : '00:00:00';
            // ET->UTC: event meta is local (site TZ = America/New_York); parse in
            // wp_timezone() so the stored TIMESTAMPTZ is true UTC. Matches
            // indexer.php archive_poc_extract_event_fields (the live sync). A bare
            // strtotime("... UTC") mis-stamps ET times as UTC (1pm ET -> 13:00Z).
            $ts = ($d = date_create("$date $time", wp_timezone())) ? $d->getTimestamp() : false;
            if ($ts) $out['start'] = $ts;
        }
    }
    if ($out['end'] === null) {
        $ed = (string) get_post_meta($post_id, 'events_end_date_and_time', true);
        if ($ed !== '') {
            $date = (strlen($ed) === 8) ? substr($ed,0,4).'-'.substr($ed,4,2).'-'.substr($ed,6,2) : $ed;
            $ts = ($d = date_create("$date 23:59:59", wp_timezone())) ? $d->getTimestamp() : false;
            if ($ts) $out['end'] = $ts;
        }
        if ($out['end'] === null && $out['start'] !== null) $out['end'] = $out['start'] + 7200;
    }
    $rid = get_post_meta($post_id, 'region', true);
    if (is_numeric($rid) && (int)$rid > 0) {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->terms} WHERE term_id = %d", (int)$rid));
        if ($name) $out['region'] = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $zoom = (string) get_post_meta($post_id, 'zoom_url_for_looth_group_virtual_event', true);
    if ($zoom !== '') $out['join_url'] = $zoom;
    return $out;
}

// --- Pre-pass: engagement counts -----------------------------------------
echo "loading engagement counts...\n";
$like_counts = [];
$rows = $wpdb->get_results("
    SELECT post_id, SUM(CASE WHEN status='like' THEN 1 WHEN status='unlike' THEN -1 ELSE 0 END) AS c
    FROM {$wpdb->prefix}ulike GROUP BY post_id
", ARRAY_A);
foreach ($rows as $r) $like_counts[(int)$r['post_id']] = max(0, (int)$r['c']);

$view_counts = [];
$rows = $wpdb->get_results("
    SELECT page_id, COUNT(*) AS c
    FROM {$wpdb->prefix}burst_statistics
    WHERE page_id > 0 GROUP BY page_id
", ARRAY_A);
foreach ($rows as $r) $view_counts[(int)$r['page_id']] = (int)$r['c'];

$topic_active = [];
$topic_replies = [];
$rows = $wpdb->get_results("
    SELECT post_id, meta_key, meta_value FROM {$wpdb->prefix}postmeta
    WHERE meta_key IN ('_bbp_last_active_time','_bbp_reply_count')
", ARRAY_A);
foreach ($rows as $r) {
    if ($r['meta_key'] === '_bbp_last_active_time') $topic_active[(int)$r['post_id']]  = strtotime($r['meta_value']) ?: null;
    if ($r['meta_key'] === '_bbp_reply_count')      $topic_replies[(int)$r['post_id']] = (int)$r['meta_value'];
}

// --- Persons --------------------------------------------------------------
echo "indexing persons...\n";
$users = $wpdb->get_results("SELECT ID, user_login, display_name FROM {$wpdb->users}", ARRAY_A);
$ins_person = $db->prepare('
    INSERT INTO person (id, display_name, slug, avatar_url) VALUES (?, ?, ?, ?)
    ON CONFLICT (id) DO UPDATE SET
        display_name = EXCLUDED.display_name,
        slug         = EXCLUDED.slug,
        avatar_url   = EXCLUDED.avatar_url
');
foreach ($users as $u) {
    $avatar = get_avatar_url((int)$u['ID']);
    $ins_person->execute([(int)$u['ID'], $u['display_name'] ?: $u['user_login'], $u['user_login'], $avatar ?: null]);
}
echo "  persons: " . count($users) . "\n";

// --- Tags -----------------------------------------------------------------
// SQLite version reused term_id as tag.id. Postgres tag.id is GENERATED
// IDENTITY, so we let postgres assign and keep a term_id → tag.id map.
echo "indexing tags...\n";
$placeholders = implode(',', array_fill(0, count($TAG_TAXONOMIES), '%s'));
$rows = $wpdb->get_results($wpdb->prepare("
    SELECT tt.term_taxonomy_id AS ttid, t.term_id, t.name, t.slug, tt.taxonomy
    FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
    WHERE tt.taxonomy IN ($placeholders)
", ...$TAG_TAXONOMIES), ARRAY_A);

$ins_tag = $db->prepare('
    INSERT INTO tag (slug, label) VALUES (?, ?)
    ON CONFLICT (slug) DO UPDATE SET label = EXCLUDED.label
    RETURNING id
');
$ttid_to_tag_id = [];   // wp term_taxonomy_id → our tag.id
$term_to_tag_id = [];   // wp term_id → our tag.id (multiple ttids may share a term_id; same tag)
$tag_labels = [];       // tag.id → label
foreach ($rows as $r) {
    $term_id = (int)$r['term_id'];
    if (isset($term_to_tag_id[$term_id])) {
        $ttid_to_tag_id[(int)$r['ttid']] = $term_to_tag_id[$term_id];
        continue;
    }
    $ins_tag->execute([$r['slug'], $r['name']]);
    $new_id = (int) $ins_tag->fetchColumn();
    $ttid_to_tag_id[(int)$r['ttid']] = $new_id;
    $term_to_tag_id[$term_id] = $new_id;
    $tag_labels[$new_id] = $r['name'];
}
echo "  tags: " . count($tag_labels) . " (term_taxonomy mappings: " . count($ttid_to_tag_id) . ")\n";

echo "loading post→tag relationships...\n";
$post_tags = [];        // post_id → [tag_id => true]
$post_tag_labels = [];  // post_id → [label, ...]
$rels = $wpdb->get_results("SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships}", ARRAY_A);
foreach ($rels as $r) {
    $ttid = (int)$r['term_taxonomy_id'];
    if (!isset($ttid_to_tag_id[$ttid])) continue;
    $tag_id = $ttid_to_tag_id[$ttid];
    $oid = (int)$r['object_id'];
    if (isset($post_tags[$oid][$tag_id])) continue;
    $post_tags[$oid][$tag_id] = true;
    $post_tag_labels[$oid][] = $tag_labels[$tag_id] ?? '';
}
echo "  posts with ≥1 tag: " . count($post_tags) . "\n";

// --- Main pass ------------------------------------------------------------
$kinds_csv = implode(',', array_map(fn($k) => "'" . esc_sql($k) . "'", array_keys($KIND_MAP)));

$ins_item = $db->prepare('
    INSERT INTO content_item
    (id, source, kind, subkind, cpt, title, slug, url, excerpt, body_text,
     thumb_url, thumb_broken, author_id, author_name, tier, published_at,
     last_activity, reply_count, like_count, view_count, duration_min, has_download,
     event_start_at, event_end_at, event_region, event_join_url,
     forum_label, subforum_label, tag_text, yt_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$ins_ctag = $db->prepare('
    INSERT INTO content_tag (content_id, tag_id) VALUES (?, ?)
    ON CONFLICT DO NOTHING
');

$db->beginTransaction();

// Wipe + rebuild atomically. PG TRUNCATE is transactional, so it lives INSIDE
// the open transaction: if anything in the rebuild loop throws, the txn is
// rolled back (PDO rolls back an open txn when the script dies) and the wipe is
// undone — the live feed is left exactly as it was. (Pre-fix this TRUNCATE ran
// before beginTransaction, so a mid-run error blanked the feed permanently.)
// CASCADE walks the FK chain; RESTART IDENTITY is skipped because looth-dev (the
// writer) doesn't own the tag sequence — sequence drift is inconsequential,
// nothing external pins tag.id values.
// NB: tag + person are NOT truncated — they're upserted (ON CONFLICT) BEFORE this
// transaction opens, so truncating them mid-txn would wipe the very rows the loop's
// content_tag inserts then FK-reference. Only the content tables are rebuilt; stale
// tag/person rows are harmless (the idempotent upsert keeps them current).
$db->exec('TRUNCATE content_tag, content_item CASCADE');

$count_by_kind = [];
$total = 0;
$errs  = 0;   // per-tag savepoint skips orphaned-tag FK links without killing the rebuild

$batch_size = 500;
$offset = 0;
while (true) {
    $posts = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, p.post_type, p.post_title, p.post_name, p.post_excerpt,
               p.post_content, p.post_author, p.post_date_gmt, p.post_status,
               p.post_parent,
               u.display_name
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author
        WHERE p.post_status = 'publish'
          AND p.post_type IN ($kinds_csv)
        ORDER BY p.ID
        LIMIT %d OFFSET %d
    ", $batch_size, $offset), ARRAY_A);
    if (!$posts) break;
    foreach ($posts as $p) {
        $pid = (int)$p['ID'];
        $cpt = $p['post_type'];
        $kind = $KIND_MAP[$cpt] ?? null;
        if (!$kind) continue;

        $v2_raw = get_post_meta($pid, '_lg_layout_v2', true);
        $body_text = '';
        $v2 = is_array($v2_raw) ? $v2_raw : (is_string($v2_raw) && $v2_raw !== '' ? json_decode($v2_raw, true) : null);
        if (is_array($v2) && !empty($v2['blocks'])) {
            $body_text = extract_v2_text($v2['blocks']);
        }
        if ($body_text === '') {
            $extra = '';
            if ($kind === 'profile') {
                $extra .= ' ' . (string) get_post_meta($pid, 'short_bio', true);
                $extra .= ' ' . (string) get_post_meta($pid, 'long_bio', true);
            }
            if ($kind === 'benefit') {
                $extra .= ' ' . (string) get_post_meta($pid, 'introduction', true);
                $extra .= ' ' . (string) get_post_meta($pid, 'member_benefits_full_details', true);
            }
            $body_text = clean_text($p['post_content'] . ' ' . $extra);
        }

        $excerpt = trim((string)$p['post_excerpt']);
        if ($excerpt === '') {
            $excerpt = mb_substr($body_text, 0, 220);
            if (mb_strlen($body_text) > 220) $excerpt .= '…';
        }

        $thumb = resolve_thumb($pid, $p['post_content']);
        $tier  = resolve_tier($pid, $kind);
        $url   = get_permalink($pid) ?: '';

        // Video play-button facade (see archive_poc_extract_yt_id in indexer.php).
        $yt_id = ($kind === 'video')
            ? archive_poc_extract_yt_id($pid, $v2, (string) $p['post_content'])
            : null;

        $last_active = $kind === 'discussion' ? ($topic_active[$pid] ?? null) : null;
        $reply_count = $kind === 'discussion' ? ($topic_replies[$pid] ?? 0) : 0;

        $forum_label = null; $subforum_label = null;
        if ($kind === 'discussion' && (int)$p['post_parent'] > 0) {
            $immediate  = (int) $p['post_parent'];
            $imm_parent = (int) (wp_get_post_parent_id($immediate) ?: 0);
            if ($imm_parent > 0) {
                $forum_label    = (string) (get_the_title($imm_parent) ?: '');
                $subforum_label = (string) (get_the_title($immediate)  ?: '');
            } else {
                $forum_label    = (string) (get_the_title($immediate) ?: '');
            }
            // bb-mirror reader URL, not legacy BB permalink (slugs == bbPress post_name).
            $fslug = (string) get_post_field('post_name', $immediate);
            $tslug = (string) ($p['post_name'] ?? '');
            if ($fslug !== '' && $tslug !== '') {
                $url = '/hub/' . $fslug . '/' . $tslug . '/';
            }
        } else {
            // Content rows: forum/subforum from the hierarchical shared_category
            // taxonomy (parents line up with the forums). Discussions are dropped
            // from PG, so this is every row here.
            $cat = archive_poc_resolve_category($pid);
            $forum_label    = $cat['forum'];
            $subforum_label = $cat['subforum'];
        }

        $has_download = false;
        if ($kind === 'loothprint') {
            if (get_post_meta($pid, '3d_file', true) || get_post_meta($pid, 'file_upload', true) || get_post_meta($pid, 'pdf_url', true) || get_post_meta($pid, 'download_url', true)) {
                $has_download = true;
            }
        }

        $published_at = strtotime($p['post_date_gmt']) ?: time();

        $ev = ($kind === 'event')
            ? extract_event_fields($pid)
            : ['start' => null, 'end' => null, 'region' => null, 'join_url' => null];

        $tag_text = !empty($post_tag_labels[$pid])
            ? implode(' ', array_filter($post_tag_labels[$pid]))
            : '';

        $ins_item->execute([
            $pid,
            'wp',
            $kind,
            null,                                 // subkind
            $cpt,
            $p['post_title'] ?: '(untitled)',
            $p['post_name'] ?: ('p-' . $pid),
            $url,
            $excerpt,
            $body_text,
            $thumb,
            $thumb_broken_default = 'false',      // BOOLEAN, default
            (int)$p['post_author'],
            $p['display_name'] ?: null,
            $tier,
            ts_iso($published_at),
            ts_iso($last_active),
            $reply_count,
            $like_counts[$pid] ?? 0,
            $view_counts[$pid] ?? 0,
            null,                                 // duration_min
            $has_download ? 'true' : 'false',
            ts_iso($ev['start']),
            ts_iso($ev['end']),
            $ev['region'],
            $ev['join_url'],
            $forum_label,
            $subforum_label,
            $tag_text,
            $yt_id,
        ]);
        $count_by_kind[$kind] = ($count_by_kind[$kind] ?? 0) + 1;
        $total++;

        if (!empty($post_tags[$pid])) {
            foreach (array_keys($post_tags[$pid]) as $tid) {
                $db->exec('SAVEPOINT sp_tag');
                try {
                    $ins_ctag->execute([$pid, $tid]);
                    $db->exec('RELEASE SAVEPOINT sp_tag');
                } catch (\Throwable $e) {
                    $db->exec('ROLLBACK TO SAVEPOINT sp_tag');   // orphaned/deleted tag → skip the link, keep the post
                    $errs++;
                }
            }
        }
    }
    $offset += $batch_size;
    if ($total % 2000 < $batch_size) echo "  processed: $total\n";
}

// Refuse to leave the feed emptier than we found it. A zero-row rebuild over
// existing content is always a bug; a >50% shrink is suspicious enough to
// require --force. Either way we ROLL BACK (undoing the TRUNCATE), so the old
// data is untouched.
if ($pre_count > 0 && $total === 0) {
    $db->rollBack();
    fwrite(STDERR, "ABORT: rebuild produced 0 rows over $pre_count existing — rolled back, feed untouched.\n");
    exit(1);
}
if (!$FORCE && $pre_count > 0 && $total < (int)($pre_count / 2)) {
    $db->rollBack();
    fwrite(STDERR, "ABORT: rebuild would shrink content_item $pre_count → $total (>50% loss). "
                 . "Rolled back, feed untouched. Re-run with --force if intentional.\n");
    exit(1);
}

if ($errs > 0) fwrite(STDERR, "  note: skipped $errs orphaned tag-link(s); all posts indexed.\n");
$db->commit();

// tsvector is GENERATED STORED on content_item, so the FTS index is already
// populated. No rebuild step needed.

echo "\n=== DONE ===\ntotal: $total\n";
ksort($count_by_kind);
foreach ($count_by_kind as $k => $n) printf("  %-12s %d\n", $k, $n);
