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

    /** Tracked config (config/notifications.php), memoised. */
    private static ?array $cfg = null;

    /**
     * The tracked config, read through __DIR__ so it resolves in FPM, CLI and cron
     * alike. A missing/malformed file falls back to the SHIPPED DEFAULTS rather than
     * throwing: the read path must not 500 because a config file went absent, and the
     * default is the conservative one (sweep-everything, i.e. today's behaviour).
     */
    private static function cfg(): array
    {
        if (self::$cfg !== null) return self::$cfg;
        $defaults = ['read_seen_only' => false, 'max_ids' => 200];
        // A CONSTANT, deliberately, not an env var: it lets notif-read-seen-gate.py
        // exercise BOTH flag values without editing the tracked file, and a constant
        // cannot be set by a request, nor stripped by sudo the way a flag-ON gate run
        // once silently exercised the OFF path. Production never defines it.
        $path = defined('LG_NOTIF_CONFIG_PATH')
            ? (string)LG_NOTIF_CONFIG_PATH
            : __DIR__ . '/../config/notifications.php';
        $got  = is_file($path) ? @include $path : null;
        self::$cfg = is_array($got) ? ($got + $defaults) : $defaults;
        return self::$cfg;
    }

    /**
     * Is marking-read scoped to the rows the member actually SAW?
     *
     * THE ONE READER of the flag, so the endpoint and any future consumer cannot
     * drift the way the recap's two registers did on 2026-07-29 (Recap::OUTSTANDING
     * exists for exactly that reason). See docs/RECAP-READ-TIMER.md.
     */
    public static function readSeenOnly(): bool
    {
        return (bool)self::cfg()['read_seen_only'];
    }

    /** Ceiling on ids per read_seen call and on the feed's ?limit=. */
    public static function maxIds(): int
    {
        return max(1, (int)self::cfg()['max_ids']);
    }

    /** Hub-event types — a (kind,id) target + a deep link, no FK. */
    public const HUB_TYPES = [
        'forum.reply_to_topic',
        'forum.reply_to_reply',
        'forum.mention',
        'reaction.on_post',
        // thread-follow lane (2026-07-28), SPEC §3.3. The FOURTH and LEAST-SPECIFIC
        // rung of the reply ladder: "a thread I chose to watch but am not otherwise
        // part of". The three above are authorship/mention-based and fire for people
        // holding zero subscriptions; this one requires a deliberate opt-in and is
        // claimed LAST, after mention > reply_to_reply > reply_to_topic have taken
        // their recipients (notify-bridge.php, the $notified set).
        // Carries anchor_id = NULL → COALESCE(...,0) → ONE coalesced row per topic.
        // Must stay in lockstep with notifications_type_check
        // (sql/2026-07-28-followed-topic.sql).
        'forum.followed_topic',
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
            // Connection rows get a click-through to the actor's profile stamped here
            // so a fresh row is never blank. listFor() ALSO resolves this at render from
            // the live actor slug and that path is AUTHORITATIVE (it revives the rows
            // that predate this column, and it stays correct if the actor later changes
            // their slug) — this stamp just keeps the stored data honest. No slug
            // (unclaimed actor) → NULL, rendered as plain text, never a dead /u/ link.
            $targetUrl = null;
            if ($actorUuid !== null) {
                $slugSt = $pg->prepare('SELECT slug FROM users WHERE uuid = :a');
                $slugSt->execute([':a' => $actorUuid]);
                $slug = $slugSt->fetchColumn();
                if (is_string($slug) && $slug !== '') $targetUrl = '/u/' . rawurlencode($slug);
            }
            $st = $pg->prepare(
                "INSERT INTO notifications (user_uuid, actor_uuid, type, connection_id, target_url)
                 VALUES (:u, :actor, :type, :ref, :url)
                 ON CONFLICT (user_uuid, connection_id) WHERE connection_id IS NOT NULL
                 DO UPDATE SET is_read = false, created_at = now(),
                               actor_uuid = EXCLUDED.actor_uuid, type = EXCLUDED.type,
                               target_url = EXCLUDED.target_url, read_at = NULL"
            );
        }
        $params = [':u' => $userUuid, ':actor' => $actorUuid, ':ref' => $refId];
        if ($type !== 'message') { $params[':type'] = $type; $params[':url'] = $targetUrl; }
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
            // The click-through target. Hub rows carry a stamped target_url. Connection
            // rows (accept/request) shipped with NONE — dead text — so resolve one at
            // RENDER from the actor's profile slug: connection_accept → the member who
            // accepted; connection_request → the requester's profile, which is exactly
            // where you act on it (Social::renderProfileActions renders Accept/Decline
            // for a pending_in edge). Resolving here revives every pre-existing
            // connection row with no backfill/migration. An actor with no slug
            // (missing / never-claimed user) gets NO /u/ link → the surfaces render the
            // name as plain text, same rule as the DM all-peer header — never a dead
            // /u/ link.
            $link = null;
            if ($isHub) {
                $link = (string)$r['target_url'];
            } elseif (($r['type'] === 'connection_accept' || $r['type'] === 'connection_request')
                      && !empty($r['actor_slug'])) {
                $link = '/u/' . rawurlencode((string)$r['actor_slug']);
            }
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
                'link'        => $link,
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
     * Apply the read-scoping POLICY to a set of ids a surface says it showed.
     *
     * THE FLAG IS BRANCHED HERE, next to the function that reads it, and nowhere
     * else — the endpoint is transport and holds no policy. Recap::OUTSTANDING is
     * the precedent and the warning: the recap's two registers each expressed the
     * same rule in their own shape and drifted apart on 2026-07-29, costing a member
     * their digest. One shape, one place.
     *
     * marked = -1 under 'all' because a sweep has no meaningful per-id count; the
     * endpoint omits the key rather than reporting a number that means something else.
     *
     * @param int[] $ids
     * @return array{policy: string, marked: int}
     */
    public static function applySeenRead(string $viewerUuid, array $ids): array
    {
        if (self::readSeenOnly()) {
            return ['policy' => 'seen', 'marked' => self::markReadMany($viewerUuid, $ids)];
        }
        // OFF: the SAME sweep read_all has always performed, whatever ids arrived.
        // That is what makes OFF a provable no-op rather than an argued one.
        self::markAllRead($viewerUuid);
        return ['policy' => 'all', 'marked' => -1];
    }

    /**
     * Mark an EXPLICIT SET of notifications read — the rows a surface can name
     * because it actually rendered them. Returns how many rows changed.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     * markAllRead() was the only mark-read-in-bulk verb, and the mobile sheet used
     * it to clear a badge after rendering EIGHT rows. So one 700ms glance marked a
     * member's whole store read, including rows that never entered the DOM — and
     * because the weekly recap is "what you missed, unread only" with empty meaning
     * no email, that glance cancelled their digest. Measured on dev2 2026-08-07: a
     * member holding 12 unread rows opened the sheet, 8 rendered, 12 went read.
     * Recap::OUTSTANDING already refused to trust `is_read` for connection rows for
     * this exact reason, naming this timer; hub rows had no such protection because
     * `is_read` is the only resolution signal they have.
     *
     * Owner-scoped by the same `WHERE user_uuid` clause markRead() uses, so a
     * foreign id simply matches nothing — ids arrive from a client and are not
     * trusted to belong to the caller. Ints are cast and the list is capped
     * (Notifications::maxIds()) so a client cannot hand us an unbounded IN list.
     *
     * @param int[] $ids
     */
    public static function markReadMany(string $viewerUuid, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $i): bool => $i > 0
        )));
        if (!$ids) return 0;
        $ids = array_slice($ids, 0, self::maxIds());

        // Postgres array binding keeps this ONE prepared statement whatever the id
        // count is — a built-up IN(?,?,…) list would reprepare per distinct length.
        $st = Db::pg()->prepare(
            'UPDATE notifications SET is_read = true, read_at = now()
              WHERE user_uuid = :v AND is_read = false
                AND id = ANY(string_to_array(:ids, \',\')::bigint[])'
        );
        $st->execute([':v' => $viewerUuid, ':ids' => implode(',', $ids)]);
        return $st->rowCount();
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
