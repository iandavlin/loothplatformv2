<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../api/v0/_reactions.php';   // palette + card store helpers (require_once _comments.php)
/**
 * archive-poc/bin/migrate-bb-reactions.php — wp_bb_user_reactions → discovery.card_reactions.
 *
 * Backfills the REAL legacy BuddyBoss reactions into the one-store reaction backend
 * (the comments-db pattern) — WP-free reads/writes afterward, zero BuddyBoss coupling.
 * Runs as looth-dev (peer auth) booting WP READ-ONLY (it needs MySQL for the source
 * tables + the bb_reaction palette + the wp_id→uuid bridge), writing Postgres.
 *
 *   sudo -u looth-dev php bin/migrate-bb-reactions.php            # dev fixture (LIMIT)
 *   sudo -u looth-dev php bin/migrate-bb-reactions.php --all      # full backfill
 *
 * SELF-CONTAINED target map (Ian 6/6 — no bb-mirror staging table needed): a legacy
 * reaction's item_id is a wp_bp_activity id; wp_bp_activity.secondary_item_id is the
 * source post/topic id for both card kinds. We resolve the card AUTHORITATIVELY by
 * joining secondary_item_id to OUR OWN stores (so the post_type is content_item.cpt /
 * 'topic', and counts line up to exactly the card the feed renders):
 *   type 'bbp_topic_create'  → forums.topic.id            → ('topic',         id)
 *   type 'new_blog_%'        → discovery.content_item.id  → (content_item.cpt, id)
 * Activities with no such card (activity_update/share, group_details, or a source row
 * that no longer exists) are reported + skipped.
 *
 * SLUG: reaction_id → slug via the bb_reaction CPT menu_order × lg_reactions_palette()
 * (6090=like,19819=ouch,15287=wow,15284=lol,19818=shop,20085=take-my-money,20087=brain).
 * The 3 custom-image reactions are vendored WP-free in web/reactions/ (== bb-reactions-
 * media originals by size).
 *
 * USER: user_id → user_uuid via the profile bridge (lg_comments_uuids_for_wp_ids); a
 * reactor that doesn't bridge keys on 'wp:'+id (the actor_key handles both doors).
 *
 * OUT OF SCOPE: item_type='activity_comment' (468 rows) — these are BuddyBoss
 * ACTIVITY-STREAM comment reactions (component=activity), which have NO row in
 * discovery.comments (that store is CPT/content comments from wp_comments). There is
 * no bp_activity_comment→discovery.comments link, so they cannot be placed. Counted +
 * skipped + flagged for a separate decision (drop, or build an activity-comment store).
 *
 * Idempotent: keyed on card_reactions' actor_key unique (date_created ASC ⇒ latest wins
 * when several activities collapse onto one card). created_at preserved from the source.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$ALL   = in_array('--all', $argv, true);
$LIMIT = 300;

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

// --- reaction_id → slug, from the bb_reaction CPT menu_order × the approved palette --
$palette = lg_reactions_palette();
$slugByReactionId = [];
foreach (($wpdb->get_results(
    "SELECT ID, menu_order FROM {$wpdb->posts} WHERE post_type='bb_reaction' AND post_status='publish'",
    ARRAY_A) ?: []) as $r) {
    $mo = (int) $r['menu_order'];
    if (isset($palette[$mo]['slug'])) $slugByReactionId[(int) $r['ID']] = $palette[$mo]['slug'];
}
fprintf(STDERR, "[migrate-bb-reactions] palette: %d bb_reaction → slug\n", count($slugByReactionId));
if (!$slugByReactionId) { fwrite(STDERR, "no bb_reaction palette — aborting\n"); exit(1); }

// --- activity_comment scope note ----------------------------------------------------
$acN = (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_bb_user_reactions WHERE item_type='activity_comment'");
if ($acN) fprintf(STDERR, "[migrate-bb-reactions] NOTE: %d 'activity_comment' reactions OUT OF SCOPE "
    . "(activity-stream comments ≠ discovery.comments — no target store; flagged)\n", $acN);

// --- Source: activity reactions + their bp_activity type/source ----------------------
$limitSql = $ALL ? '' : ('LIMIT ' . (int) $LIMIT);
$src = $wpdb->get_results(
    "SELECT r.user_id, r.reaction_id, r.date_created,
            a.type AS atype, a.secondary_item_id AS sid
       FROM wp_bb_user_reactions r
       JOIN wp_bp_activity a ON a.id = r.item_id
      WHERE r.item_type = 'activity'
      ORDER BY r.date_created ASC $limitSql", ARRAY_A) ?: [];
fprintf(STDERR, "[migrate-bb-reactions] %s: %d source 'activity' rows (joined to bp_activity)\n",
        $ALL ? 'FULL' : 'fixture', count($src));
if (!$src) exit(0);

// --- Resolve card targets authoritatively against OUR stores ------------------------
$topicSids = []; $replySids = []; $contentSids = [];
foreach ($src as $r) {
    $sid = (int) $r['sid'];
    if ($sid <= 0) continue;
    if ($r['atype'] === 'bbp_topic_create')      $topicSids[$sid]   = true;
    elseif ($r['atype'] === 'bbp_reply_create')   $replySids[$sid]   = true;
    elseif (strpos((string) $r['atype'], 'new_blog_') === 0) $contentSids[$sid] = true;
}
$pgArr = static fn(array $ids) => '{' . implode(',', array_map('intval', array_keys($ids))) . '}';

$validTopics = [];
if ($topicSids) {
    $st = $pdo->prepare('SELECT id FROM forums.topic WHERE id = ANY(?::bigint[])');
    $st->execute([$pgArr($topicSids)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $validTopics[(int) $id] = true;
}
$validReplies = [];
if ($replySids) {
    $st = $pdo->prepare('SELECT id FROM forums.reply WHERE id = ANY(?::bigint[])');
    $st->execute([$pgArr($replySids)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $validReplies[(int) $id] = true;
}
$contentCpt = [];   // content_item.id => cpt
if ($contentSids) {
    $st = $pdo->prepare('SELECT id, cpt FROM discovery.content_item WHERE id = ANY(?::bigint[])');
    $st->execute([$pgArr($contentSids)]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $contentCpt[(int) $row['id']] = (string) $row['cpt'];
}
fprintf(STDERR, "[migrate-bb-reactions] resolved targets: %d topics, %d content items\n",
        count($validTopics), count($contentCpt));
fprintf(STDERR, "[migrate-bb-reactions] resolved targets: %d replies\n", count($validReplies));

// --- User bridge (batch) ------------------------------------------------------------
$wpIds = [];
foreach ($src as $r) $wpIds[(int) $r['user_id']] = true;
$uuidByWp = $wpIds ? lg_comments_uuids_for_wp_ids(array_keys($wpIds)) : [];
fprintf(STDERR, "[migrate-bb-reactions] %d reactors, %d bridged to uuid\n", count($wpIds), count($uuidByWp));

// --- Upsert -------------------------------------------------------------------------
$ins = $pdo->prepare(
    "INSERT INTO discovery.card_reactions (post_type, item_id, user_wp_id, user_uuid, slug, created_at)
     VALUES (?,?,?,?::uuid,?,?)
     ON CONFLICT (post_type, item_id, actor_key) DO UPDATE SET
        slug=EXCLUDED.slug, created_at=EXCLUDED.created_at,
        user_wp_id=COALESCE(card_reactions.user_wp_id, EXCLUDED.user_wp_id),
        user_uuid =COALESCE(card_reactions.user_uuid,  EXCLUDED.user_uuid)");

$migrated = 0; $noSlug = 0; $noCard = 0;
$pdo->beginTransaction();
foreach ($src as $r) {
    $slug = $slugByReactionId[(int) $r['reaction_id']] ?? null;
    if ($slug === null) { $noSlug++; continue; }
    $sid = (int) $r['sid'];
    if ($r['atype'] === 'bbp_topic_create' && isset($validTopics[$sid])) {
        $pt = 'topic';
    } elseif ($r['atype'] === 'bbp_reply_create' && isset($validReplies[$sid])) {
        $pt = 'reply';
    } elseif (strpos((string) $r['atype'], 'new_blog_') === 0 && isset($contentCpt[$sid])) {
        $pt = $contentCpt[$sid];
    } else { $noCard++; continue; }

    $wid     = (int) $r['user_id'];
    $created = gmdate('Y-m-d H:i:sP', strtotime((string) $r['date_created'] . ' UTC'));
    $ins->execute([$pt, $sid, $wid > 0 ? $wid : null, $uuidByWp[$wid] ?? null, $slug, $created]);
    $migrated++;
}
$pdo->commit();
fprintf(STDERR, "[migrate-bb-reactions] done: %d migrated, %d skipped(no card), %d skipped(unknown reaction)\n",
        $migrated, $noCard, $noSlug);

// --- VERIFY: a known card's discovery count == its BuddyBoss count -------------------
// Most-reacted resolved content item in this run, compared to BB distinct-user reactions.
$probe = $pdo->query(
    "SELECT post_type, item_id, COUNT(*) n FROM discovery.card_reactions
      GROUP BY post_type, item_id ORDER BY n DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($probe) {
    $pt = (string) $probe['post_type']; $iid = (int) $probe['item_id']; $disc = (int) $probe['n'];
    // BB distinct reactors for the activities that map to this card (same sid).
    $bb = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT r.user_id)
           FROM wp_bb_user_reactions r JOIN wp_bp_activity a ON a.id=r.item_id
          WHERE r.item_type='activity' AND a.secondary_item_id=%d
            AND (a.type='bbp_topic_create' OR a.type LIKE 'new_blog_%%')", $iid));
    fprintf(STDERR, "[verify] %s:%d discovery=%d  buddyboss(distinct users on sid)=%d  %s\n",
        $pt, $iid, $disc, $bb, $disc === $bb ? 'MATCH ✓' : 'DIFF (check multi-activity/cross-type)');
}
