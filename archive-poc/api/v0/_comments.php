<?php
/**
 * archive-poc/api/v0/_comments.php — content-comments store (comments lane).
 *
 * Pulls content comments OUT of WordPress. The modal read path (comments.php) runs
 * on the archive-poc FPM pool and reads this table directly — no WP boot, ~50ms —
 * replacing the WP-booting deploy/lg-comments-frame.php (~1–3s). The write path
 * (comment-post.php) runs on the looth-dev WP pool so it can validate the WP login
 * cookie (the posting gate — per the gate-on-WP-cookie-not-/whoami rule: an
 * unbridged member has a valid WP cookie but /whoami reads them anon, so gating on
 * /whoami would lock real members out of posting).
 *
 * Storage = Postgres `discovery` schema (alongside likes, article_blobs,
 * content_item). Keyed (post_type, item_id) exactly like discovery.likes, where
 * item_id mirrors wp_posts.ID. Author identity = the shared user_uuid contract,
 * resolved to a LIVE profile card at read time via /profile-api/v0/users (so renames
 * / new avatars follow); legacy anonymous commenters carry a frozen author_name with
 * a NULL user_uuid.
 *
 * shared by: comments.php (read), comment-post.php (write), bin/backfill-comments.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../../config.php';   // PDO DSN env + LG_ARCHIVE_POC_HOST

/**
 * Content post-types whose comments live in this store (Ian, 2026-06-04). shop_order
 * (WooCommerce order notes) is deliberately EXCLUDED — internal order data, not
 * community comments. Forum replies stay in bb-mirror. Single source of truth for
 * the read/write allowlist AND the backfill filter.
 *
 * NOTE (flagged to coordinator): the stream surfaces four more content types that
 * also carry comments — loothcuts, useful_links, member-benefit, sponsor-post
 * (~21 rows on dev). They are NOT in Ian's explicit list; left out pending a call.
 * Adding them later = extend this array (+ re-run backfill); nothing else changes.
 */
if (!defined('LG_COMMENTS_TYPES')) {
    define('LG_COMMENTS_TYPES', [
        'loothprint', 'post-type-videos', 'post-imgcap', 'post',
        'shorty', 'coe-questions', 'ajde_events',
        'loothcuts', 'useful_links', 'member-benefit',  // widened 2026-06-06 (Ian): types that also carry comments
    ]);
}

/**
 * Comment-reactions palette (Ian-approved 2026-06-05, sourced from the BuddyBoss
 * bb_reaction set — 7 reactions, menu_order 0-6). Single source of truth for the
 * read render (comments.php), the write validation (comment-react.php) AND the
 * client picker. Reactions are stored BY SLUG (stable across re-skins), one per
 * (comment, user).
 *
 *  - type 'emoji' → rendered from `char` (unicode), no asset needed.
 *  - type 'image' → served WP-free as a static file from web/reactions/ at the URL
 *    LG_REACTIONS_ASSET_BASE . file (the dev cookie gate sits in front; the modal
 *    iframe always carries the cookie).
 *
 * NOTE on 'like': on CONTENT cards "like" is the existing discovery.likes path
 * (whoami-identity). A COMMENT is not a content item, so it cannot key into
 * discovery.likes — here 'like' is stored like any other reaction in
 * discovery.comment_reactions. Kept first in the palette to match the BB order.
 */
if (!defined('LG_REACTIONS_ASSET_BASE')) {
    define('LG_REACTIONS_ASSET_BASE', '/archive-poc/reactions/');
}
if (!function_exists('lg_reactions_palette')) {
function lg_reactions_palette(): array {
    return [
        ['slug' => 'like',          'label' => 'Like',          'type' => 'emoji', 'char' => '👍'],
        ['slug' => 'ouch',          'label' => 'Ouch',          'type' => 'image', 'file' => 'ouch.png'],
        ['slug' => 'wow',           'label' => 'Wow',           'type' => 'emoji', 'char' => '😮'],
        ['slug' => 'lol',           'label' => 'LOL',           'type' => 'emoji', 'char' => '😂'],
        ['slug' => 'shop',          'label' => 'Optimum',       'type' => 'image', 'file' => 'shop.png'],   // label renamed Shop->Optimum (Ian 2026-06-11); slug stays so stored reactions keep counting
        ['slug' => 'take-my-money', 'label' => 'Take my money', 'type' => 'image', 'file' => 'take-my-money.png'],
        ['slug' => 'brain',         'label' => 'Brain',         'type' => 'emoji', 'char' => '🧠'],
    ];
}
}
if (!function_exists('lg_reactions_slugs')) {
/** Flat allowlist of valid reaction slugs (write validation). */
function lg_reactions_slugs(): array {
    static $s = null;
    if ($s === null) $s = array_map(static fn($r) => $r['slug'], lg_reactions_palette());
    return $s;
}
}

if (!function_exists('lg_comments_pdo')) {
/**
 * Postgres handle for the comments store. Identical shape to lg_likes_pdo(): the
 * pg role comes from the FPM pool's OS user via peer auth — archive-poc (read /
 * owner) on the modal pool, looth-dev (write) on the WP pool. search_path pinned to
 * discovery.
 */
function lg_comments_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = getenv('LG_ARCHIVE_POC_DSN') ?: 'pgsql:host=/var/run/postgresql;dbname=looth';
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $pdo->exec('SET search_path = discovery, public');
    }
    return $pdo;
}
}

if (!function_exists('lg_comments_is_uuid')) {
function lg_comments_is_uuid(?string $s): bool {
    return is_string($s) && (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}
}

if (!function_exists('lg_comments_thread')) {
/**
 * All visible comments for one content item, oldest-first. Flat rows; the renderer
 * assembles the parent/child tree. Only `approved` status is returned to readers.
 *
 * @return array<int,array> rows with keys: id, parent_id, user_uuid, author_name,
 *         body, created_at (unix int)
 */
function lg_comments_thread(PDO $pdo, string $postType, int $itemId): array {
    $st = $pdo->prepare(
        "SELECT id, parent_id, user_uuid, author_wp_id, author_name, body,
                EXTRACT(EPOCH FROM created_at)::bigint AS created_at,
                EXTRACT(EPOCH FROM edited_at)::bigint  AS edited_at
         FROM comments
         WHERE post_type = ? AND item_id = ? AND status = 'approved'
         ORDER BY created_at ASC, id ASC");
    $st->execute([$postType, $itemId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
}

if (!function_exists('lg_comments_count')) {
/** Fast approved-comment count for one content item (modal header / card badge). */
function lg_comments_count(PDO $pdo, string $postType, int $itemId): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM comments WHERE post_type=? AND item_id=? AND status='approved'");
    $st->execute([$postType, $itemId]);
    return (int) $st->fetchColumn();
}
}

if (!function_exists('lg_comments_insert')) {
/**
 * Insert one net-new comment. Identity is resolved server-side by the caller (the
 * write endpoint, from the validated WP cookie) — a client never supplies the
 * author, so IDOR is structurally impossible, mirroring like.php.
 *
 * @param array $f { post_type, item_id, parent_id?:?int, user_uuid?:?string,
 *                   author_wp_id?:?int, author_name?:?string, body }
 * @return int new comment id
 */
function lg_comments_insert(PDO $pdo, array $f): int {
    $parent = isset($f['parent_id']) && $f['parent_id'] > 0 ? (int) $f['parent_id'] : null;
    // A reply must thread to a comment ON THE SAME content item — otherwise drop the
    // parent (top-level) rather than splice a foreign thread.
    if ($parent !== null) {
        $chk = $pdo->prepare('SELECT 1 FROM comments WHERE id=? AND post_type=? AND item_id=?');
        $chk->execute([$parent, (string) $f['post_type'], (int) $f['item_id']]);
        if (!$chk->fetchColumn()) $parent = null;
    }
    $uuid = lg_comments_is_uuid($f['user_uuid'] ?? null) ? strtolower((string) $f['user_uuid']) : null;
    $st = $pdo->prepare(
        'INSERT INTO comments (post_type, item_id, parent_id, user_uuid, author_wp_id, author_name, body)
         VALUES (?,?,?,?::uuid,?,?,?) RETURNING id');
    $st->execute([
        (string) $f['post_type'],
        (int) $f['item_id'],
        $parent,
        $uuid,
        isset($f['author_wp_id']) && $f['author_wp_id'] > 0 ? (int) $f['author_wp_id'] : null,
        isset($f['author_name']) && $f['author_name'] !== '' ? (string) $f['author_name'] : null,
        (string) $f['body'],
    ]);
    return (int) $st->fetchColumn();
}
}

if (!function_exists('lg_comments_get')) {
/**
 * Fetch one comment row by id (any status), for the edit/delete authz gate.
 * @return ?array { id, post_type, item_id, parent_id, user_uuid, author_wp_id, body, status }
 */
function lg_comments_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare(
        'SELECT id, post_type, item_id, parent_id, user_uuid, author_wp_id, body, status
           FROM comments WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
}

if (!function_exists('lg_comment_author_match')) {
/**
 * Is the caller the author of this comment? Net-new comments stamp author_wp_id;
 * legacy/backfilled rows may carry only user_uuid. Match on EITHER. The caller's
 * identity is server-derived (validated WP cookie + profile bridge) — never client
 * input — so this is IDOR-proof, like the write path.
 */
function lg_comment_author_match(array $row, int $uid, ?string $uuid): bool {
    if ($uid > 0 && (int) ($row['author_wp_id'] ?? 0) === $uid) return true;
    $rowUuid = strtolower((string) ($row['user_uuid'] ?? ''));
    if (lg_comments_is_uuid($uuid) && lg_comments_is_uuid($rowUuid)
        && strtolower((string) $uuid) === $rowUuid) return true;
    return false;
}
}

if (!function_exists('lg_comments_set_status')) {
/**
 * Flip a comment's moderation status (soft-delete = 'trash', restore = 'approved').
 * Reversible — never hard-DELETEs, so the parent_id ON DELETE CASCADE never fires
 * and a trashed comment keeps its subtree intact for restore. Authz is enforced by
 * the caller (the endpoint), having already loaded the row.
 */
function lg_comments_set_status(PDO $pdo, int $id, string $status): void {
    $st = $pdo->prepare('UPDATE comments SET status = ? WHERE id = ?');
    $st->execute([$status, $id]);
}
}

if (!function_exists('lg_comments_update_body')) {
/**
 * Replace a comment's body and stamp edited_at. Text is sanitized by the caller
 * (the endpoint) exactly like the compose path. Authz enforced by the caller.
 */
function lg_comments_update_body(PDO $pdo, int $id, string $body): void {
    $st = $pdo->prepare('UPDATE comments SET body = ?, edited_at = now() WHERE id = ?');
    $st->execute([$body, $id]);
}
}

if (!function_exists('lg_comments_profile_lookup')) {
/**
 * Loopback batch call to /profile-api/v0/users. $key is 'uuids' or 'wp_ids';
 * $vals is the CSV-able list (cap 100 per call, chunked here). Returns the raw
 * `items` array (each: uuid, slug, display_name, avatar_url, bio[, wp_user_id]).
 * The endpoint itself is an unauthenticated batch card lookup, but on dev the
 * cookie gate sits in front of it — so we forward the current request's cookie
 * (exactly like lg_archive_poc_whoami()), which browser-driven read/write paths
 * always carry. CLI callers (the backfill) have none and simply get [] for the
 * bridge → callers fall back to the stored author_name. On live there is no gate.
 * Returns [] on any failure.
 */
function lg_comments_profile_lookup(string $key, array $vals): array {
    $vals = array_values(array_unique(array_filter($vals, static fn($v) => $v !== '' && $v !== null)));
    if (!$vals) return [];
    $hdrs = ['Host: ' . LG_ARCHIVE_POC_HOST];
    if (!empty($_SERVER['HTTP_COOKIE'])) $hdrs[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE'];
    $out = [];
    foreach (array_chunk($vals, 100) as $chunk) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/users?' . $key . '=' . rawurlencode(implode(',', $chunk)),
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
        if ($code === 200 && $body) {
            $data = json_decode($body, true);
            if (is_array($data) && is_array($data['items'] ?? null)) {
                foreach ($data['items'] as $it) $out[] = $it;
            }
        }
    }
    return $out;
}
}

if (!function_exists('lg_comments_author_cards')) {
/**
 * Map author uuid → live profile card for a set of uuids.
 * @return array<string,array> lc-uuid => ['display_name','slug','avatar_url']
 */
function lg_comments_author_cards(array $uuids): array {
    $uuids = array_filter(array_map('strtolower', $uuids), 'lg_comments_is_uuid');
    $cards = [];
    foreach (lg_comments_profile_lookup('uuids', $uuids) as $it) {
        $u = strtolower((string) ($it['uuid'] ?? ''));
        if ($u === '') continue;
        $cards[$u] = [
            'display_name' => (string) ($it['display_name'] ?? ''),
            'slug'         => (string) ($it['slug'] ?? ''),
            'avatar_url'   => (string) ($it['avatar_url'] ?? ''),
        ];
    }
    return $cards;
}
}

if (!function_exists('lg_comments_uuids_for_wp_ids')) {
/**
 * Map WP user ids → user_uuid via the profile bridge. Used by the write endpoint
 * (single id) and the backfill (batch) to stamp the author uuid on a comment.
 * @return array<int,string> wp_user_id => lc-uuid (only the ones that bridge)
 */
function lg_comments_uuids_for_wp_ids(array $wpIds): array {
    $wpIds = array_values(array_filter(array_map('intval', $wpIds), static fn($i) => $i > 0));
    $map = [];
    foreach (lg_comments_profile_lookup('wp_ids', $wpIds) as $it) {
        $wid = (int) ($it['wp_user_id'] ?? 0);
        $u   = strtolower((string) ($it['uuid'] ?? ''));
        if ($wid > 0 && lg_comments_is_uuid($u)) $map[$wid] = $u;
    }
    return $map;
}
}

/* ── Reactions on comments ───────────────────────────────────────────────────
   Stored in discovery.comment_reactions, keyed (comment_id, user_wp_id): ONE
   reaction per user per comment (BuddyBoss model — choosing a new reaction
   replaces the old). Identity is the WP user id (the comment-lane participation
   gate is the WP login cookie, not /whoami — so unbridged members can react);
   user_uuid is stored too when the member bridges, for future cross-surface use.
   Counts read WP-free on the archive-poc pool; the viewer's own pick is resolved
   on the WP pool (comment-post.php GET) where the cookie is validated. */

if (!function_exists('lg_reactions_for_comments')) {
/**
 * Aggregate reaction counts for a set of comment ids.
 * @return array<int,array<string,int>> comment_id => [slug => count] (only non-zero)
 */
function lg_reactions_for_comments(PDO $pdo, array $commentIds): array {
    $ids = array_values(array_filter(array_map('intval', $commentIds), static fn($i) => $i > 0));
    if (!$ids) return [];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare(
        "SELECT comment_id, slug, COUNT(*) AS c
           FROM comment_reactions
          WHERE comment_id IN ($ph)
          GROUP BY comment_id, slug");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['comment_id']][(string) $r['slug']] = (int) $r['c'];
    }
    return $out;
}
}

if (!function_exists('lg_reactions_mine')) {
/**
 * The viewer's own reaction per comment (one slug each, where present).
 * @return array<int,string> comment_id => slug
 */
function lg_reactions_mine(PDO $pdo, array $commentIds, int $wpUserId): array {
    $ids = array_values(array_filter(array_map('intval', $commentIds), static fn($i) => $i > 0));
    if (!$ids || $wpUserId <= 0) return [];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare(
        "SELECT comment_id, slug FROM comment_reactions
          WHERE user_wp_id = ? AND comment_id IN ($ph)");
    $st->execute(array_merge([$wpUserId], $ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['comment_id']] = (string) $r['slug'];
    return $out;
}
}

if (!function_exists('lg_reactions_set')) {
/**
 * Set / change / clear the viewer's reaction on one comment. Idempotent toggle:
 * picking the slug you already have removes it; picking a different slug replaces
 * it; otherwise inserts. The comment must exist (FK enforces it anyway).
 *
 * @return array{counts:array<string,int>, mine:?string} post-write state for the comment
 */
function lg_reactions_set(PDO $pdo, int $commentId, int $wpUserId, ?string $uuid, string $slug): array {
    if (!in_array($slug, lg_reactions_slugs(), true)) {
        throw new InvalidArgumentException('bad_slug');
    }
    $uuid = lg_comments_is_uuid($uuid) ? strtolower((string) $uuid) : null;
    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT slug FROM comment_reactions WHERE comment_id=? AND user_wp_id=?');
        $cur->execute([$commentId, $wpUserId]);
        $existing = $cur->fetchColumn();
        if ($existing === $slug) {
            // same reaction → toggle off
            $pdo->prepare('DELETE FROM comment_reactions WHERE comment_id=? AND user_wp_id=?')
                ->execute([$commentId, $wpUserId]);
            $mine = null;
        } else {
            // insert or switch — keyed on (comment_id, user_wp_id)
            $up = $pdo->prepare(
                'INSERT INTO comment_reactions (comment_id, user_wp_id, user_uuid, slug)
                 VALUES (?,?,?::uuid,?)
                 ON CONFLICT (comment_id, user_wp_id)
                 DO UPDATE SET slug = EXCLUDED.slug, user_uuid = EXCLUDED.user_uuid, created_at = now()');
            $up->execute([$commentId, $wpUserId, $uuid, $slug]);
            $mine = $slug;
        }
        // fresh counts for just this comment
        $cnt = $pdo->prepare('SELECT slug, COUNT(*) c FROM comment_reactions WHERE comment_id=? GROUP BY slug');
        $cnt->execute([$commentId]);
        $counts = [];
        foreach ($cnt->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[(string) $r['slug']] = (int) $r['c'];
        $pdo->commit();
        return ['counts' => $counts, 'mine' => $mine];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
}
