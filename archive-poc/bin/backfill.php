<?php
require __DIR__.'/../config.php';
/**
 * archive-poc/bin/backfill.php — Scope A, throwaway-grade.
 *
 * Boots /var/www/dev WordPress, walks wp_posts, and writes a normalized
 * content_item index into ../index.sqlite.
 *
 * Usage:
 *   sudo -u www-data php bin/backfill.php
 *
 * READ-ONLY on looth_dev. SELECT only.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

// Boot WP unless wp-cli already did.
if (!function_exists('wp_get_attachment_image_url')) {
    if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = LG_ARCHIVE_POC_HOST;
    if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    require LG_ARCHIVE_POC_WP_LOAD;
}

global $wpdb;
$wpdb->suppress_errors(true);

$SQLITE_PATH = __DIR__ . '/../index.sqlite';

// --- CPT → kind mapping ---------------------------------------------------
// Authoritative — source of truth is the live ACF manifest at
// /home/ubuntu/projects/acf-export-2026-05-25.json. Any CPT not listed here
// is intentionally excluded (defunct, WP/BB internal, or not yet onboarded).
// `topic` is bbPress; included because discussions are part of the index.
// MUST stay in sync with archive_poc_kind_map() in indexer.php.
$KIND_MAP = [
    // ACF-managed CPTs (13)
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
    // bbPress (not ACF)
    'topic'           => 'discussion',
];

// Taxonomies considered "tags" for surfacing in the index.
// 'category' is deliberately excluded — this site doesn't use WP categories.
$TAG_TAXONOMIES = [
    'post_tag','post-tag','topic-tag','hash-tag',
    'video-type','loothprint_type','loothprint-type','useful_link',
    'member-benefit-type','sponsor-post-type',
    'event_type','event-type','shared_category','featured',
    'business-type','language','region','shows',
    'mp2t_knowledge_domains','mp2t_musical_contexts','mp2t_work_on',
];

// --- Open SQLite ----------------------------------------------------------
if (!file_exists($SQLITE_PATH)) {
    fwrite(STDERR, "missing $SQLITE_PATH — run: sqlite3 index.sqlite < schema.sql\n");
    exit(1);
}
$db = new PDO('sqlite:' . $SQLITE_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA synchronous = NORMAL');

// Idempotent rebuild: wipe content tables, keep schema.
$db->exec('DELETE FROM content_tag');
$db->exec('DELETE FROM content_item');
$db->exec('DELETE FROM tag');
$db->exec('DELETE FROM person');
$db->exec("DELETE FROM content_fts");

// --- Helpers --------------------------------------------------------------

/** Strip HTML, collapse whitespace, trim. */
function clean_text(?string $html): string {
    if (!$html) return '';
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

/** Recursively walk lg-layout-v2 blocks and collect text. */
function extract_v2_text($blocks): string {
    if (!is_array($blocks)) return '';
    $out = [];
    $walk = function ($node) use (&$walk, &$out) {
        if (!is_array($node)) return;
        $type = $node['type'] ?? null;
        // text-bearing fields by block type
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
        // recurse into columns or nested children
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

/** First image URL in HTML body, or null. */
function first_image_url(?string $html): ?string {
    if (!$html) return null;
    if (preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $m)) return $m[1];
    return null;
}

/** Resolve thumb URL per fallback chain: featured → first body img → null. */
/** First BuddyBoss media attachment for a post — see indexer.php's
 *  archive_poc_first_bp_media_thumb. */
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

/** Map slug-of-kind → tier value from postmeta (events) or default 'public'. */
function resolve_tier(int $post_id, string $kind): string {
    // Source of truth is the 'tier' WP taxonomy. Query wpdb directly because
    // wp_get_object_terms() returns WP_Error when --skip-plugins skips the
    // taxonomy registration during bulk reindexing.
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
    // Fallbacks for legacy meta storage (events ACF + old _post_tier)
    if ($kind === 'event') {
        $t = get_post_meta($post_id, 'event_tier_', true);
        if (is_string($t) && $t !== '') return strtolower($t);
    }
    $t = get_post_meta($post_id, '_post_tier', true);
    if (is_string($t) && $t !== '') return strtolower($t);
    return 'public';
}

// --- Pre-pass: load engagement counts in bulk -----------------------------
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

// Topic activity / reply count
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

// --- Person table (bulk) --------------------------------------------------
echo "indexing persons...\n";
$users = $wpdb->get_results("SELECT ID, user_login, display_name FROM {$wpdb->users}", ARRAY_A);
$ins_person = $db->prepare('INSERT OR REPLACE INTO person(id, display_name, slug, avatar_url) VALUES(?,?,?,?)');
foreach ($users as $u) {
    $avatar = get_avatar_url((int)$u['ID']);
    $ins_person->execute([(int)$u['ID'], $u['display_name'] ?: $u['user_login'], $u['user_login'], $avatar ?: null]);
}
echo "  persons: " . count($users) . "\n";

// --- Tag table (bulk, allowlist) ------------------------------------------
echo "indexing tags...\n";
$placeholders = implode(',', array_fill(0, count($TAG_TAXONOMIES), '%s'));
$rows = $wpdb->get_results($wpdb->prepare("
    SELECT tt.term_taxonomy_id AS ttid, t.term_id, t.name, t.slug, tt.taxonomy
    FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
    WHERE tt.taxonomy IN ($placeholders)
", ...$TAG_TAXONOMIES), ARRAY_A);
$ttid_to_tag_id = [];   // term_taxonomy_id → our internal tag.id (= term_id)
$ins_tag = $db->prepare('INSERT OR IGNORE INTO tag(id, slug, label) VALUES(?,?,?)');
foreach ($rows as $r) {
    $tag_id = (int)$r['term_id'];
    $ttid_to_tag_id[(int)$r['ttid']] = $tag_id;
    $ins_tag->execute([$tag_id, $r['slug'], $r['name']]);
}
echo "  tags: " . count($ttid_to_tag_id) . "\n";

// Pre-load term_relationships for the post set (cheaper than per-post queries)
echo "loading post→tag relationships...\n";
$post_tags = [];   // post_id → [tag_id, ...]
$post_tag_labels = []; // post_id → [label, ...]
$rels = $wpdb->get_results("
    SELECT tr.object_id, tr.term_taxonomy_id
    FROM {$wpdb->term_relationships} tr
", ARRAY_A);
$tag_labels = [];
foreach ($rows as $r) $tag_labels[(int)$r['term_id']] = $r['name'];
foreach ($rels as $r) {
    $ttid = (int)$r['term_taxonomy_id'];
    if (!isset($ttid_to_tag_id[$ttid])) continue;
    $tag_id = $ttid_to_tag_id[$ttid];
    $oid = (int)$r['object_id'];
    $post_tags[$oid][$tag_id] = true;
    $post_tag_labels[$oid][] = $tag_labels[$tag_id] ?? '';
}
echo "  posts with ≥1 tag: " . count($post_tags) . "\n";

// --- Main pass: stream posts ---------------------------------------------
$kinds_csv = implode(',', array_map(fn($k) => "'" . esc_sql($k) . "'", array_keys($KIND_MAP)));
$ins_item = $db->prepare("
    INSERT INTO content_item
    (id, source, kind, subkind, cpt, title, slug, url, excerpt, body_text,
     thumb_url, thumb_broken, author_id, author_name, tier, published_at,
     last_activity, reply_count, like_count, view_count, duration_min, has_download,
     event_start_at, event_end_at, event_region, event_join_url,
     forum_label, subforum_label)
    VALUES (?, 'wp', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

/** Extract event meta (start/end/region/join_url). Mirrors indexer.php's
 *  archive_poc_extract_event_fields. Returns ['start','end','region','join_url'],
 *  any may be null. */
function extract_event_fields(int $post_id): array {
    $out = ['start' => null, 'end' => null, 'region' => null, 'join_url' => null];
    // Legacy ajde_events shape — direct unix timestamps
    $srow = get_post_meta($post_id, 'evcal_srow', true);
    $erow = get_post_meta($post_id, 'evcal_erow', true);
    if (is_numeric($srow) && (int)$srow > 0) $out['start'] = (int) $srow;
    if (is_numeric($erow) && (int)$erow > 0) $out['end']   = (int) $erow;
    // Current ACF event CPT — Ymd date + HH:MM:SS time
    if ($out['start'] === null) {
        $sd = (string) get_post_meta($post_id, 'events_start_date_and_time_', true);
        $st = (string) get_post_meta($post_id, 'time_of_event', true);
        if ($sd !== '') {
            $date = (strlen($sd) === 8) ? substr($sd,0,4).'-'.substr($sd,4,2).'-'.substr($sd,6,2) : $sd;
            $time = $st !== '' ? $st : '00:00:00';
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
    // Region (term id → term name)
    $rid = get_post_meta($post_id, 'region', true);
    if (is_numeric($rid) && (int)$rid > 0) {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->terms} WHERE term_id = %d", (int)$rid));
        if ($name) $out['region'] = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    // Join URL (Zoom etc.)
    $zoom = (string) get_post_meta($post_id, 'zoom_url_for_looth_group_virtual_event', true);
    if ($zoom !== '') $out['join_url'] = $zoom;
    return $out;
}
$ins_ctag = $db->prepare('INSERT OR IGNORE INTO content_tag(content_id, tag_id) VALUES(?,?)');

$db->beginTransaction();
$count_by_kind = [];
$total = 0;

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

        // Body extraction
        $v2_raw = get_post_meta($pid, '_lg_layout_v2', true);
        $body_text = '';
        $v2 = null;
        if (is_array($v2_raw)) {
            $v2 = $v2_raw;
        } elseif (is_string($v2_raw) && $v2_raw !== '') {
            $v2 = json_decode($v2_raw, true);
        }
        if (is_array($v2) && !empty($v2['blocks'])) {
            $body_text = extract_v2_text($v2['blocks']);
        }
        if ($body_text === '') {
            // ACF body fields by kind (best-effort)
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

        // Excerpt
        $excerpt = trim((string)$p['post_excerpt']);
        if ($excerpt === '') {
            $excerpt = mb_substr($body_text, 0, 220);
            if (mb_strlen($body_text) > 220) $excerpt .= '…';
        }

        // Thumb
        $thumb = resolve_thumb($pid, $p['post_content']);

        // Tier
        $tier = resolve_tier($pid, $kind);

        // URL
        $url = get_permalink($pid) ?: '';

        // Discussion-specific
        $last_active = $kind === 'discussion' ? ($topic_active[$pid] ?? null) : null;
        $reply_count = $kind === 'discussion' ? ($topic_replies[$pid] ?? 0) : 0;

        // Forum hierarchy for discussions. Mirror indexer.php:
        //   topic.post_parent = immediate forum; if that forum has a non-zero
        //   post_parent it's a sub-forum nested under a top-level forum.
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
            // Link to the bb-mirror reader, not the legacy BB permalink.
            // /forum/<immediate-forum-slug>/<topic-slug>/ — slugs match bbPress
            // post_name (verified), so build directly. Fall back if missing.
            $fslug = (string) get_post_field('post_name', $immediate);
            $tslug = (string) ($p['post_name'] ?? '');
            if ($fslug !== '' && $tslug !== '') {
                $url = '/hub/' . $fslug . '/' . $tslug . '/';
            }
        }

        // has_download for loothprint-ish
        $has_download = 0;
        if ($kind === 'loothprint') {
            if (get_post_meta($pid, '3d_file', true) || get_post_meta($pid, 'file_upload', true) || get_post_meta($pid, 'pdf_url', true) || get_post_meta($pid, 'download_url', true)) {
                $has_download = 1;
            }
        }

        $published_at = strtotime($p['post_date_gmt']) ?: time();

        $ev = ($kind === 'event')
            ? extract_event_fields($pid)
            : ['start' => null, 'end' => null, 'region' => null, 'join_url' => null];
        $ins_item->execute([
            $pid, $kind, null, $cpt,
            $p['post_title'] ?: '(untitled)',
            $p['post_name'] ?: ('p-' . $pid),
            $url, $excerpt, $body_text,
            $thumb, (int)$p['post_author'], $p['display_name'] ?: null,
            $tier, $published_at,
            $last_active, $reply_count,
            $like_counts[$pid] ?? 0,
            $view_counts[$pid] ?? 0,
            null, $has_download,
            $ev['start'], $ev['end'], $ev['region'], $ev['join_url'],
            $forum_label, $subforum_label,
        ]);
        $count_by_kind[$kind] = ($count_by_kind[$kind] ?? 0) + 1;
        $total++;

        if (!empty($post_tags[$pid])) {
            foreach (array_keys($post_tags[$pid]) as $tid) {
                $ins_ctag->execute([$pid, $tid]);
            }
        }
    }
    $offset += $batch_size;
    if ($total % 2000 < $batch_size) echo "  processed: $total\n";
}
$db->commit();

// --- Rebuild FTS ----------------------------------------------------------
echo "rebuilding FTS...\n";
$db->exec("INSERT INTO content_fts(rowid, title, body_text, author_name, tag_text)
           SELECT ci.id, ci.title, ci.body_text, ci.author_name,
                  COALESCE((SELECT GROUP_CONCAT(t.label, ' ')
                            FROM content_tag ct JOIN tag t ON t.id=ct.tag_id
                            WHERE ct.content_id = ci.id), '')
           FROM content_item ci");

echo "\n=== DONE ===\ntotal: $total\n";
ksort($count_by_kind);
foreach ($count_by_kind as $k => $n) printf("  %-12s %d\n", $k, $n);
