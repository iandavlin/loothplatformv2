<?php
/**
 * archive-poc/api/v0/_reactions.php — reactions ON feed CARDS (topics + content).
 *
 * The engine half of Hub card-reactions. Sibling of the comment-reaction helpers in
 * _comments.php, but the target is a CONTENT ITEM (post_type, item_id) — the same
 * key shape as discovery.comments / discovery.likes — not a comment surrogate id.
 *
 * THE LIKE FOLD: a "like" is just slug='like' here. discovery.likes folds into this
 * store, so _likes.php delegates to lg_card_reactions_set()/_for_items() (slug='like')
 * to keep its {count,liked} contract. ONE store, no parallel like system.
 *
 * TWO DOORS, ONE ROW: dedup is on a normalized actor_key
 *   = COALESCE(user_uuid, 'wp:'||user_wp_id)
 * so a bridged member collapses to one row across the Hub door (WP-cookie, wp_id +
 * uuid) and the standalone door (/whoami, uuid only). See sql/card-reactions.pg.sql.
 *
 * Palette + PDO + the wp_id→uuid bridge are reused from _comments.php (single source
 * of truth — lg_reactions_palette()/lg_reactions_slugs()/lg_comments_pdo()).
 *
 * shared by: card-react.php (Hub write+GET), _likes.php (fold), _feed.php SSR (read,
 * via lg_card_reactions_for_items on the bb-mirror SELECT grant).
 */

declare(strict_types=1);
require_once __DIR__ . '/_comments.php';   // palette/slugs + lg_comments_pdo + uuid bridge

if (!function_exists('lg_card_actor_key')) {
/**
 * The dedup actor key, computed exactly like the generated column so PHP lookups
 * (SELECT/DELETE by actor_key) match what Postgres stored. Bridged → lowercase
 * uuid (Postgres renders uuid::text lowercase); unbridged → 'wp:'+id.
 */
function lg_card_actor_key(?int $wpId, ?string $uuid): ?string {
    if (is_string($uuid) && lg_likes_is_uuid_loose($uuid)) return strtolower($uuid);
    if ($wpId !== null && $wpId > 0) return 'wp:' . $wpId;
    return null;
}
}

if (!function_exists('lg_likes_is_uuid_loose')) {
/** Local uuid shape check (avoids a hard dep on _likes.php load order). */
function lg_likes_is_uuid_loose(?string $s): bool {
    return is_string($s) && (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}
}

if (!function_exists('lg_card_reactions_for_items')) {
/**
 * Count-read contract for the SURFACE lane's _feed.php SSR. ONE grouped query over
 * the wanted (post_type,item_id) pairs.
 *
 * @param array $items list of ['post_type'=>string,'item_id'=>int]
 * @return array<string,array<string,int>> "post_type:item_id" => [slug => count]
 *         (only non-zero slugs present). like_count for a card = result[k]['like'] ?? 0.
 */
function lg_card_reactions_for_items(PDO $pdo, array $items): array {
    $out = [];
    $pairs = [];
    foreach ($items as $it) {
        $pt = (string) ($it['post_type'] ?? '');
        $id = (int) ($it['item_id'] ?? 0);
        if ($pt === '' || $id <= 0) continue;
        $pairs["$pt:$id"] = [$pt, $id];
    }
    if (!$pairs) return $out;

    $ph = []; $args = [];
    foreach ($pairs as $p) { $ph[] = '(?::text, ?::bigint)'; $args[] = $p[0]; $args[] = $p[1]; }
    $st = $pdo->prepare(
        'SELECT post_type, item_id, slug, COUNT(*) AS c
           FROM discovery.card_reactions
          WHERE (post_type, item_id) IN (' . implode(',', $ph) . ')
          GROUP BY post_type, item_id, slug');
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['post_type'] . ':' . (int) $r['item_id']][(string) $r['slug']] = (int) $r['c'];
    }
    return $out;
}
}

if (!function_exists('lg_card_reactions_mine')) {
/**
 * The viewer's own pick per card (for highlight). Matches on actor_key so it works
 * for both the bridged (uuid) and unbridged (wp:id) cases.
 *
 * @param array $items list of ['post_type'=>string,'item_id'=>int]
 * @return array<string,string> "post_type:item_id" => slug
 */
function lg_card_reactions_mine(PDO $pdo, array $items, ?int $wpId, ?string $uuid): array {
    $out = [];
    $actor = lg_card_actor_key($wpId, $uuid);
    if ($actor === null) return $out;
    $pairs = [];
    foreach ($items as $it) {
        $pt = (string) ($it['post_type'] ?? '');
        $id = (int) ($it['item_id'] ?? 0);
        if ($pt === '' || $id <= 0) continue;
        $pairs["$pt:$id"] = [$pt, $id];
    }
    if (!$pairs) return $out;

    $ph = []; $args = [];
    foreach ($pairs as $p) { $ph[] = '(?::text, ?::bigint)'; $args[] = $p[0]; $args[] = $p[1]; }
    $args[] = $actor;
    $st = $pdo->prepare(
        'SELECT post_type, item_id, slug FROM discovery.card_reactions
          WHERE (post_type, item_id) IN (' . implode(',', $ph) . ') AND actor_key = ?');
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['post_type'] . ':' . (int) $r['item_id']] = (string) $r['slug'];
    }
    return $out;
}
}

if (!function_exists('lg_card_reactions_users')) {
/**
 * WHO-REACTED read for ONE card: the reactor list grouped by slug, with live
 * profile identity resolved. Reads the SAME discovery.card_reactions store as the
 * counts (lg_card_reactions_for_items) — NO second tally: each group's user-count
 * equals that slug's rendered chip count. Newest reactor first within a group;
 * groups ordered by descending count (so the modal reads most-reacted first).
 *
 * Identity resolution mirrors the comment lane: bridged rows carry user_uuid →
 * resolved via lg_comments_author_cards(); unbridged rows carry only user_wp_id →
 * resolved via the wp_ids profile lookup. A reactor whose card does not resolve
 * (unbridged/no profile, or the dev cookie-gate on an anon read) is still LISTED
 * with name '' so the list length never diverges from the count — the client
 * renders a neutral placeholder for those.
 *
 * @return array{
 *   order:  array<int,string>,                          // slugs present, count desc
 *   groups: array<string, array{                        // slug =>
 *     count: int,
 *     users: array<int, array{name:string, slug:string, avatar_url:string}>
 *   }>
 * }
 */
function lg_card_reactions_users(PDO $pdo, string $postType, int $itemId): array {
    $empty = ['order' => [], 'groups' => []];
    if ($postType === '' || $itemId <= 0) return $empty;

    $st = $pdo->prepare(
        'SELECT slug, user_wp_id, user_uuid
           FROM discovery.card_reactions
          WHERE post_type = ? AND item_id = ?
          ORDER BY created_at DESC');
    $st->execute([$postType, $itemId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $empty;

    // Batch-resolve identity once: bridged rows by uuid, unbridged by wp id.
    $uuids = []; $wpIds = [];
    foreach ($rows as $r) {
        $u = strtolower((string) ($r['user_uuid'] ?? ''));
        if ($u !== '') { $uuids[$u] = true; }
        else { $w = (int) ($r['user_wp_id'] ?? 0); if ($w > 0) $wpIds[$w] = true; }
    }
    $byUuid = $uuids ? lg_comments_author_cards(array_keys($uuids)) : [];
    $byWp   = [];
    if ($wpIds) {
        foreach (lg_comments_profile_lookup('wp_ids', array_keys($wpIds)) as $it) {
            $w = (int) ($it['wp_user_id'] ?? 0);
            if ($w <= 0) continue;
            $byWp[$w] = [
                'display_name' => (string) ($it['display_name'] ?? ''),
                'slug'         => (string) ($it['slug'] ?? ''),
                'avatar_url'   => (string) ($it['avatar_url'] ?? ''),
            ];
        }
    }

    $groups = [];
    foreach ($rows as $r) {
        $slug = (string) $r['slug'];
        $u    = strtolower((string) ($r['user_uuid'] ?? ''));
        $w    = (int) ($r['user_wp_id'] ?? 0);
        $card = ($u !== '' && isset($byUuid[$u])) ? $byUuid[$u]
              : (($w > 0 && isset($byWp[$w])) ? $byWp[$w] : null);
        if (!isset($groups[$slug])) $groups[$slug] = ['count' => 0, 'users' => []];
        $groups[$slug]['count']++;
        $groups[$slug]['users'][] = [
            'name'       => (string) ($card['display_name'] ?? ''),
            'slug'       => (string) ($card['slug'] ?? ''),
            'avatar_url' => (string) ($card['avatar_url'] ?? ''),
        ];
    }
    // Slug order = descending count (matches the chip row's most-reacted-first read).
    // PHP 8 usort is stable, so equal-count groups keep their first-seen (newest) order.
    $order = array_keys($groups);
    usort($order, static fn($a, $b) => $groups[$b]['count'] <=> $groups[$a]['count']);
    return ['order' => $order, 'groups' => $groups];
}
}

if (!function_exists('lg_card_reactions_set')) {
/**
 * Idempotent toggle/switch/off for one card, keyed on the normalized actor_key.
 * Re-posting the same slug toggles it OFF; a different slug switches; one row per
 * actor per card. Returns post-write state for that single card.
 *
 * @return array{counts:array<string,int>, mine:?string}
 */
function lg_card_reactions_set(PDO $pdo, string $postType, int $itemId,
                               ?int $wpId, ?string $uuid, string $slug): array {
    $actor = lg_card_actor_key($wpId, $uuid);
    if ($actor === null) throw new RuntimeException('no actor identity');
    $uuidNorm = (is_string($uuid) && lg_likes_is_uuid_loose($uuid)) ? strtolower($uuid) : null;

    $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare('SELECT slug FROM discovery.card_reactions
                              WHERE post_type=? AND item_id=? AND actor_key=?');
        $cur->execute([$postType, $itemId, $actor]);
        $existing = $cur->fetchColumn();

        if ($existing === $slug) {
            // same reaction → toggle off
            $pdo->prepare('DELETE FROM discovery.card_reactions
                           WHERE post_type=? AND item_id=? AND actor_key=?')
                ->execute([$postType, $itemId, $actor]);
            $mine = null;
        } else {
            // insert or switch — conflict target is the actor_key unique constraint
            $pdo->prepare(
                'INSERT INTO discovery.card_reactions (post_type, item_id, user_wp_id, user_uuid, slug)
                 VALUES (?,?,?,?::uuid,?)
                 ON CONFLICT (post_type, item_id, actor_key)
                 DO UPDATE SET slug = EXCLUDED.slug,
                               user_wp_id = COALESCE(card_reactions.user_wp_id, EXCLUDED.user_wp_id),
                               user_uuid  = COALESCE(card_reactions.user_uuid,  EXCLUDED.user_uuid),
                               created_at = now()')
                ->execute([$postType, $itemId, $wpId ?: null, $uuidNorm, $slug]);
            $mine = $slug;
        }

        // Post-write counts for this card (one grouped read).
        $cnt = $pdo->prepare('SELECT slug, COUNT(*) AS c FROM discovery.card_reactions
                              WHERE post_type=? AND item_id=? GROUP BY slug');
        $cnt->execute([$postType, $itemId]);
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
