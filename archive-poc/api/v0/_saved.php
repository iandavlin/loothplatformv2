<?php
/**
 * archive-poc/api/v0/_saved.php — discovery.saved_posts store (account-synced
 * "save a post"). Binary sibling of _reactions.php: same actor_key dedup
 * (COALESCE(user_uuid,'wp:'||user_wp_id)). Reuses lg_comments_pdo(),
 * lg_card_actor_key(), lg_comments_uuids_for_wp_ids() from _reactions/_comments.
 */
declare(strict_types=1);
require_once __DIR__ . '/_reactions.php';

if (!function_exists('lg_saved_set')) {

/** Save (true) or unsave (false) one item for an actor. Returns resulting saved bool. */
function lg_saved_set(PDO $pdo, string $postType, int $itemId, ?int $wpId, ?string $uuid, bool $save): bool {
    $actor = lg_card_actor_key($wpId, $uuid);
    if ($actor === null) return false;
    if ($save) {
        $pdo->prepare(
            'INSERT INTO discovery.saved_posts (post_type, item_id, user_wp_id, user_uuid, actor_key)
             VALUES (?,?,?,?,?) ON CONFLICT (post_type, item_id, actor_key) DO NOTHING'
        )->execute([$postType, $itemId, $wpId, $uuid, $actor]);
        return true;
    }
    $pdo->prepare('DELETE FROM discovery.saved_posts WHERE post_type=? AND item_id=? AND actor_key=?')
        ->execute([$postType, $itemId, $actor]);
    return false;
}

/** Viewer's saved-state for a batch of items -> { "pt:id": true } (for Save-button fill). */
function lg_saved_state(PDO $pdo, array $items, ?int $wpId, ?string $uuid): array {
    $actor = lg_card_actor_key($wpId, $uuid);
    if ($actor === null || !$items) return [];
    $ph = []; $args = [];
    foreach ($items as $it) { $ph[] = '(?,?)'; $args[] = $it['post_type']; $args[] = (int)$it['item_id']; }
    $args[] = $actor;
    $st = $pdo->prepare(
        'SELECT post_type, item_id FROM discovery.saved_posts
          WHERE (post_type, item_id) IN (' . implode(',', $ph) . ') AND actor_key = ?');
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['post_type'] . ':' . $r['item_id']] = true;
    return $out;
}

/** A user's saved posts, newest first, with enough to render cards (content rich; topic = title). */
function lg_saved_list(PDO $pdo, ?int $wpId, ?string $uuid, int $limit = 100): array {
    $actor = lg_card_actor_key($wpId, $uuid);
    if ($actor === null) return [];
    $sql = "
      SELECT s.post_type, s.item_id, s.created_at AS saved_at,
             c.title, c.url, c.thumb_url, c.kind, c.tier, c.author_name
        FROM discovery.saved_posts s
        JOIN discovery.content_item c ON c.id = s.item_id
       WHERE s.actor_key = :ak AND s.post_type <> 'topic'
      UNION ALL
      SELECT s.post_type, s.item_id, s.created_at AS saved_at,
             t.title, ('/hub/#topic-' || s.item_id)::text,
             NULLIF(t.featured_image_url, ''), 'topic'::text, NULL::text, NULL::text
        FROM discovery.saved_posts s
        JOIN forums.topic t ON t.id = s.item_id
       WHERE s.actor_key = :ak2 AND s.post_type = 'topic'
       ORDER BY saved_at DESC
       LIMIT :lim";
    $st = $pdo->prepare($sql);
    $st->bindValue(':ak',  $actor);
    $st->bindValue(':ak2', $actor);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

}
