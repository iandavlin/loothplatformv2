<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Notifications — the bell backend. profile-app owns the DATA + counts; lg-shell
 * renders the bell + modal, which READ these via api/v0/me-notifications +
 * me-social-counts. Table: sql/2026-05-30-social-layer.sql → `notifications`.
 *
 * RULINGS (Ian, 2026-05-30):
 *  - START FRESH: no BB history port. The crib seeds only CURRENT-UNREAD state at
 *    cut — one row per unread DM thread + one per pending connection request — so
 *    the bell isn't empty. (49,603 BP rows are NOT migrated.)
 *  - 9+ BADGE: unreadCount() returns the TRUE integer; the "9+" cap is a DISPLAY
 *    concern (me-social-counts), not stored here.
 *  - 30-DAY RETENTION: prune() (cron, NOT the request path) deletes by age.
 *
 * Types: 'message' | 'connection_request' | 'connection_accept'. Dedup is via the
 * partial unique indexes (uq_notifications_message / _connection): push() ON
 * CONFLICT collapses to ONE unread row per (user, thread) / (user, connection).
 *
 * HUB EVENTS (notifications lane, 2026-07-12) — pushHubEvent():
 *  - Forum replies, @mentions and reactions ring THIS bell. They are NOT a second
 *    store: same table, same counts, same modal. One store, one writer.
 *  - Referent is (target_kind, target_id, anchor_id) + a target_url stamped by the
 *    caller. profile_app can't FK to bbPress (MySQL) or to the `looth` PG database,
 *    and only WP knows the forum/topic SLUGS the deep link needs — so the link is
 *    denormalized in at push time. Schema: sql/2026-07-12-notifications-hub-events.sql.
 *  - target_kind is an OPEN vocabulary ('topic'|'reply'|'card' today). A future lane
 *    (dmv-native chapters) adds 'chapter_post' with no schema change.
 */
final class Notifications
{
    public const TYPES = ['message', 'connection_request', 'connection_accept'];

    /** Hub-event types — a (kind,id) target + a deep link, no FK. */
    public const HUB_TYPES = [
        'forum.reply_to_topic',
        'forum.reply_to_reply',
        'forum.mention',
        'reaction.on_post',
    ];

    /**
     * Raise (or refresh) a notification, deduped via upsert.
     * $refId is thread_id for 'message', else connection_id. A re-fire bumps the
     * existing unread row to the top rather than piling up.
     */
    public static function push(string $userUuid, string $type, int $refId, ?string $actorUuid = null): void
    {
        if (!in_array($type, self::TYPES, true)) return;
        $pg = Db::pg();

        if ($type === 'message') {
            $st = $pg->prepare(
                "INSERT INTO notifications (user_uuid, actor_uuid, type, thread_id)
                 VALUES (:u, :actor, 'message', :ref)
                 ON CONFLICT (user_uuid, thread_id) WHERE type = 'message'
                 DO UPDATE SET is_read = false, created_at = now(),
                               actor_uuid = EXCLUDED.actor_uuid, read_at = NULL"
            );
        } else {
            $st = $pg->prepare(
                "INSERT INTO notifications (user_uuid, actor_uuid, type, connection_id)
                 VALUES (:u, :actor, :type, :ref)
                 ON CONFLICT (user_uuid, connection_id) WHERE connection_id IS NOT NULL
                 DO UPDATE SET is_read = false, created_at = now(),
                               actor_uuid = EXCLUDED.actor_uuid, type = EXCLUDED.type, read_at = NULL"
            );
        }
        $params = [':u' => $userUuid, ':actor' => $actorUuid, ':ref' => $refId];
        if ($type !== 'message') $params[':type'] = $type;
        $st->execute($params);
    }

    /**
     * Raise (or coalesce) a HUB notification — reply / mention / reaction.
     *
     * Dedup + coalesce ride uq_notifications_target_unread, scoped to UNREAD rows:
     *  - the same event re-firing (double-submit, a sync replay) bumps the row, it
     *    does not pile up, and the same actor twice does NOT inflate actor_count;
     *  - a SECOND actor on the same target merges into that one row: latest actor
     *    wins, actor_count += 1 → "Alice and 1 other reacted to your post";
     *  - once the row is READ, a later event rings a FRESH row (count back to 1)
     *    rather than being silently swallowed into a row you already dismissed.
     *
     * Never notifies you about your own action (the caller drops self, and this is
     * the belt-and-braces check). Returns false when the event was not raised.
     */
    public static function pushHubEvent(
        string $userUuid,
        string $type,
        string $targetKind,
        int $targetId,
        string $targetUrl,
        ?string $actorUuid = null,
        ?int $anchorId = null
    ): bool {
        if (!in_array($type, self::HUB_TYPES, true)) return false;
        if ($targetKind === '' || $targetId < 1 || $targetUrl === '') return false;
        if ($actorUuid !== null && $actorUuid === $userUuid) return false;   // no self-notify

        $st = Db::pg()->prepare(
            "INSERT INTO notifications
                    (user_uuid, actor_uuid, type, target_kind, target_id, anchor_id, target_url)
             VALUES (:u, :actor, :type, :kind, :tid, :anchor, :url)
             ON CONFLICT (user_uuid, type, target_kind, target_id, COALESCE(anchor_id, 0))
                     WHERE target_kind IS NOT NULL AND is_read = false
             DO UPDATE SET
                    actor_uuid  = EXCLUDED.actor_uuid,
                    actor_count = CASE
                        WHEN notifications.actor_uuid IS DISTINCT FROM EXCLUDED.actor_uuid
                        THEN notifications.actor_count + 1
                        ELSE notifications.actor_count END,
                    target_url  = EXCLUDED.target_url,
                    created_at  = now(),
                    is_read     = false,
                    read_at     = NULL"
        );
        $st->execute([
            ':u'      => $userUuid,
            ':actor'  => $actorUuid,
            ':type'   => $type,
            ':kind'   => $targetKind,
            ':tid'    => $targetId,
            ':anchor' => $anchorId,
            ':url'    => $targetUrl,
        ]);
        return true;
    }

    /** Recent-first feed for the modal, with actor identity hydrated for render. */
    public static function listFor(string $uuid, int $limit = 30, int $offset = 0): array
    {
        $st = Db::pg()->prepare(
            "SELECT n.id, n.type, n.thread_id, n.connection_id, n.is_read, n.created_at,
                    n.target_kind, n.target_id, n.anchor_id, n.target_url, n.actor_count,
                    a.uuid AS actor_uuid, a.display_name AS actor_name,
                    a.slug AS actor_slug, a.avatar_url AS actor_avatar
               FROM notifications n
               LEFT JOIN users a ON a.uuid = n.actor_uuid
              WHERE n.user_uuid = :u
              ORDER BY n.created_at DESC
              LIMIT :lim OFFSET :off"
        );
        $st->bindValue(':u', $uuid);
        $st->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $st->bindValue(':off', $offset, \PDO::PARAM_INT);
        $st->execute();

        return array_map(static function (array $r): array {
            $isHub = $r['target_kind'] !== null;
            return [
                'id'        => (int)$r['id'],
                'type'      => $r['type'],
                'is_read'   => (bool)$r['is_read'],
                'created_at'=> $r['created_at'],
                // Hub rows point at a (kind,id) thing; legacy rows keep their typed referent.
                'ref'       => $isHub
                    ? ['kind' => $r['target_kind'], 'id' => (int)$r['target_id'],
                       'anchor' => $r['anchor_id'] !== null ? (int)$r['anchor_id'] : null]
                    : ($r['type'] === 'message'
                        ? ['kind' => 'thread', 'id' => $r['thread_id'] !== null ? (int)$r['thread_id'] : null]
                        : ['kind' => 'connection', 'id' => $r['connection_id'] !== null ? (int)$r['connection_id'] : null]),
                // The click-through. Present ONLY on rows that have somewhere to land —
                // the surfaces make a row clickable iff `link` is non-null, so a legacy
                // row can never navigate to a wrong/legacy URL.
                'link'        => $isHub ? (string)$r['target_url'] : null,
                'actor_count' => (int)$r['actor_count'],
                'actor'     => $r['actor_uuid'] ? [
                    'uuid'       => $r['actor_uuid'],
                    'name'       => $r['actor_name'],
                    'slug'       => $r['actor_slug'],
                    'avatar_url' => $r['actor_avatar'],
                ] : null,
            ];
        }, $st->fetchAll());
    }

    /** True unread count → me-social-counts (display caps at 9+, not here). */
    public static function unreadCount(string $uuid): int
    {
        $st = Db::pg()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_uuid = :u AND is_read = false'
        );
        $st->execute([':u' => $uuid]);
        return (int)$st->fetchColumn();
    }

    /** Mark one notification read (must belong to $viewerUuid). */
    public static function markRead(string $viewerUuid, int $id): void
    {
        $st = Db::pg()->prepare(
            'UPDATE notifications SET is_read = true, read_at = now()
              WHERE id = :id AND user_uuid = :v'
        );
        $st->execute([':id' => $id, ':v' => $viewerUuid]);
    }

    /** Mark all of a user's notifications read (modal "mark all read"). */
    public static function markAllRead(string $viewerUuid): void
    {
        $st = Db::pg()->prepare(
            'UPDATE notifications SET is_read = true, read_at = now()
              WHERE user_uuid = :v AND is_read = false'
        );
        $st->execute([':v' => $viewerUuid]);
    }

    /**
     * Delete ONE notification (must belong to $viewerUuid). Owner-scoped by the
     * same WHERE user_uuid clause markRead() uses — a row that isn't the viewer's
     * matches nothing. Returns true iff a row was actually removed, so the endpoint
     * can 404 a non-owner / already-gone id (same deny model as everywhere else:
     * "not yours" and "doesn't exist" are indistinguishable to the caller).
     */
    public static function delete(string $viewerUuid, int $id): bool
    {
        $st = Db::pg()->prepare(
            'DELETE FROM notifications WHERE id = :id AND user_uuid = :v'
        );
        $st->execute([':id' => $id, ':v' => $viewerUuid]);
        return $st->rowCount() > 0;
    }

    /**
     * Delete ALL of a user's notifications (the "Clear all" both surfaces now
     * DELETE server-side instead of the retired client watermark). Scoped to the
     * viewer; never touches the underlying DM/connection/hub thread. Returns rows
     * removed (for a client toast / no-op detection).
     */
    public static function deleteAll(string $viewerUuid): int
    {
        $st = Db::pg()->prepare(
            'DELETE FROM notifications WHERE user_uuid = :v'
        );
        $st->execute([':v' => $viewerUuid]);
        return $st->rowCount();
    }

    /**
     * Retention prune (30-day ruling). Called by cron (bin/prune-notifications),
     * NOT on the request path. Deletes by age regardless of read state; the
     * underlying DM/connection is untouched. Returns rows deleted (for the cron log).
     */
    public static function prune(int $olderThanDays = 30): int
    {
        $st = Db::pg()->prepare(
            "DELETE FROM notifications WHERE created_at < now() - make_interval(days => :d)"
        );
        $st->bindValue(':d', $olderThanDays, \PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount();
    }
}
