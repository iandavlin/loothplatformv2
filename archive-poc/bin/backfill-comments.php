<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../api/v0/_comments.php';
/**
 * archive-poc/bin/backfill-comments.php — wp_comments → discovery.comments.
 *
 * Boots WordPress (READ-ONLY) and copies CONTENT comments into the postgres store.
 * Runs as the shared write-side role looth-dev (peer auth) — same pattern as
 * backfill-pg.php / article_blobs:
 *
 *   sudo -u looth-dev php bin/backfill-comments.php            # small dev FIXTURE
 *   sudo -u looth-dev php bin/backfill-comments.php --all      # full run (CUTOVER)
 *
 * Scope: LG_COMMENTS_TYPES only (shop_order excluded), comment_approved=1.
 * Idempotent: keyed on legacy_wp_id (= wp_comments.comment_ID) ON CONFLICT DO UPDATE,
 * so re-running never duplicates. Threading is preserved in a second pass that maps
 * wp comment_parent → the new comments.id. Author user_id → user_uuid via the profile
 * bridge; anonymous legacy commenters (user_id=0) keep comment_author with NULL uuid.
 *
 * Dev = fixture only (per the dev-fixtures-only rule); the full migration runs at
 * cutover. Fixture = every comment on the few most-discussed items (whole threads,
 * so threading + author resolution are actually testable).
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$ALL = in_array('--all', $argv, true);
$FIXTURE_POSTS = 6;   // dev fixture: this many top-discussed items, full threads each

if (!function_exists('wp_get_current_user')) {
    if (!isset($_SERVER['HTTP_HOST']))   $_SERVER['HTTP_HOST']   = LG_ARCHIVE_POC_HOST;
    if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    require LG_ARCHIVE_POC_WP_LOAD;
}
global $wpdb;
$wpdb->suppress_errors(true);

$pdo = lg_comments_pdo();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    fwrite(STDERR, "requires LG_ARCHIVE_POC_DSN with pgsql driver\n"); exit(1);
}

$types   = LG_COMMENTS_TYPES;
$typePh  = implode(',', array_fill(0, count($types), '%s'));

// --- Select source comments (content types, approved) ----------------------
if ($ALL) {
    $sql = $wpdb->prepare(
        "SELECT c.comment_ID, c.comment_post_ID, c.comment_parent, c.user_id,
                c.comment_author, c.comment_content, c.comment_date_gmt, p.post_type
         FROM {$wpdb->comments} c
         JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
         WHERE c.comment_approved = '1' AND p.post_type IN ($typePh)
         ORDER BY c.comment_ID ASC", ...$types);
} else {
    // Fixture: pick the most-discussed content items, then pull their whole threads.
    $topPosts = $wpdb->get_col($wpdb->prepare(
        "SELECT c.comment_post_ID
         FROM {$wpdb->comments} c
         JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
         WHERE c.comment_approved = '1' AND p.post_type IN ($typePh)
         GROUP BY c.comment_post_ID
         ORDER BY COUNT(*) DESC, c.comment_post_ID ASC
         LIMIT %d", ...array_merge($types, [$FIXTURE_POSTS])));
    if (!$topPosts) { fwrite(STDERR, "no content comments found\n"); exit(0); }
    // Guarantee every covered type is represented in the dev fixture: add the single
    // most-discussed item of each type, so widened types (e.g. loothcuts/useful_links/
    // member-benefit) are testable even when they don't crack the global top-N.
    foreach ($types as $t) {
        $rep = $wpdb->get_var($wpdb->prepare(
            "SELECT c.comment_post_ID
             FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
             WHERE c.comment_approved='1' AND p.post_type=%s
             GROUP BY c.comment_post_ID
             ORDER BY COUNT(*) DESC, c.comment_post_ID ASC LIMIT 1", $t));
        if ($rep) $topPosts[] = (int) $rep;
    }
    $topPosts = array_values(array_unique(array_map('intval', $topPosts)));
    $postPh = implode(',', array_fill(0, count($topPosts), '%d'));
    $sql = $wpdb->prepare(
        "SELECT c.comment_ID, c.comment_post_ID, c.comment_parent, c.user_id,
                c.comment_author, c.comment_content, c.comment_date_gmt, p.post_type
         FROM {$wpdb->comments} c
         JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
         WHERE c.comment_approved = '1' AND c.comment_post_ID IN ($postPh)
         ORDER BY c.comment_ID ASC", ...$topPosts);
}
$src = $wpdb->get_results($sql, ARRAY_A) ?: [];
fprintf(STDERR, "[backfill-comments] %s: %d source rows\n", $ALL ? 'FULL' : 'fixture', count($src));
if (!$src) exit(0);

// --- Resolve author uuids in batch (registered users only) -----------------
$wpIds = [];
foreach ($src as $r) if ((int) $r['user_id'] > 0) $wpIds[(int) $r['user_id']] = true;
$uuidByWp = $wpIds ? lg_comments_uuids_for_wp_ids(array_keys($wpIds)) : [];
fprintf(STDERR, "[backfill-comments] %d distinct authors, %d bridged to uuid\n",
        count($wpIds), count($uuidByWp));

// --- Pass 1: upsert each comment (parent_id NULL for now) ------------------
$ins = $pdo->prepare(
    "INSERT INTO comments
        (post_type, item_id, parent_id, user_uuid, author_wp_id, author_name, body, status, created_at, legacy_wp_id)
     VALUES (?,?,NULL,?::uuid,?,?,?, 'approved', ?, ?)
     ON CONFLICT (legacy_wp_id) DO UPDATE SET
        post_type=EXCLUDED.post_type, item_id=EXCLUDED.item_id,
        user_uuid=EXCLUDED.user_uuid, author_wp_id=EXCLUDED.author_wp_id,
        author_name=EXCLUDED.author_name, body=EXCLUDED.body, created_at=EXCLUDED.created_at
     RETURNING id");
$idByLegacy = [];   // wp comment_ID => new comments.id
$pdo->beginTransaction();
foreach ($src as $r) {
    $wid  = (int) $r['user_id'];
    $uuid = $wid > 0 ? ($uuidByWp[$wid] ?? null) : null;
    // Legacy comment_author / comment_content hold HTML-encoded text (e.g. "&amp;").
    // Decode to clean UTF-8 once here so the reader (which escapes on output) doesn't
    // double-encode. Net-new writes already store literal plain text.
    $name = trim(html_entity_decode((string) $r['comment_author'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($name === '') $name = 'Member';
    $bodyTxt = trim(html_entity_decode(
        wp_strip_all_tags((string) $r['comment_content'], false), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // comment_date_gmt is UTC; bind as ISO with explicit +00 for TIMESTAMPTZ.
    $created = gmdate('Y-m-d H:i:sP', strtotime((string) $r['comment_date_gmt'] . ' UTC'));
    $ins->execute([
        (string) $r['post_type'], (int) $r['comment_post_ID'],
        $uuid, $wid > 0 ? $wid : null, $name, $bodyTxt, $created, (int) $r['comment_ID'],
    ]);
    $idByLegacy[(int) $r['comment_ID']] = (int) $ins->fetchColumn();
}
$pdo->commit();

// --- Pass 2: wire threading (comment_parent → new parent_id) ---------------
$upd = $pdo->prepare('UPDATE comments SET parent_id = ? WHERE id = ?');
$threaded = 0;
$pdo->beginTransaction();
foreach ($src as $r) {
    $par = (int) $r['comment_parent'];
    if ($par <= 0) continue;
    $childId  = $idByLegacy[(int) $r['comment_ID']] ?? 0;
    $parentId = $idByLegacy[$par] ?? 0;   // parent must be in this batch (same item → it is)
    if ($childId && $parentId) { $upd->execute([$parentId, $childId]); $threaded++; }
}
$pdo->commit();

fprintf(STDERR, "[backfill-comments] done: %d upserted, %d threaded\n", count($idByLegacy), $threaded);
