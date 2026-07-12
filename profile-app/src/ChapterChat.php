<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;

/**
 * CHAPTER CHAT — A ROOM IS NOT A MULTI-RECIPIENT DM.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════
 * HOW A ROOM DIFFERS FROM A DM THREAD — the three things, and why each one matters
 * ══════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. MEMBERSHIP IS DERIVED, NEVER ENUMERATED.
 *    A DM thread answers "may I read this?" by looking for a row in `message_recipients`
 *    (Messaging::isRecipient). A room answers it by asking "am I in chapter_member?".
 *    A room has ZERO message_recipients rows — not "none yet", but never, by design.
 *
 *    This is also the ISOLATION PROOF, and it runs BOTH ways at the data layer:
 *      * Every DM endpoint gates on message_recipients, so it REJECTS a room by
 *        construction (`not_a_recipient`) — a room can never leak into someone's inbox,
 *        and we did not have to touch one line of Messaging.php to guarantee that.
 *      * Every method here refuses a thread whose chapter_id IS NULL (see room()), so a
 *        chapter endpoint can never be pointed at somebody's private DM.
 *    The two models are mutually exclusive in the DATA, not by convention or by review.
 *
 * 2. READ STATE IS A WATERMARK, NOT A COUNTER.
 *    `message_recipients` carries a denormalized `unread_count` per recipient. Sending one
 *    message to a 1,841-member thread under that model is 1,841 UPDATEs. Every message.
 *    That does not survive contact with a real chapter.
 *
 *    Instead: chapter_room_read(user_uuid, thread_id, last_read_message_id, muted).
 *      unread = COUNT(messages WHERE thread_id = room AND id > last_read_message_id)
 *    Rows are written ONLY when a user JOINS (seeded at the current head, so a new member
 *    does not inherit a 4,000-message backlog) and when a user READS. Never on send.
 *
 *      ⇒ A SEND IS 1 INSERT. At any room size. That is the whole trick.
 *
 *    Backed by messages(thread_id, id) — added in this lane's migration, because the
 *    pre-existing idx_messages_thread is (thread_id, created_at) and cannot serve an
 *    `id >` range, which would have quietly turned every badge into a full room scan.
 *
 * 3. NOTIFICATION VOLUME IS BOUNDED BY WRITING NOTHING.
 *    A room does NOT notify every member on every message. It notifies NOBODY, and the
 *    unread badge is computed on demand from the watermark above — so there is no fan-out
 *    to bound, no row-per-member-per-message to prune, and a 1,841-member room CANNOT
 *    become an email/push/bell incident, because there is nothing to deliver.
 *
 *    Verified on the box: profile_app.notifications has no cron, no trigger, no mailer and
 *    no push consumer — it is a pure in-app bell store. So the risk was never email; it was
 *    UNBOUNDED ROWS (neither existing collapsing partial index covers a new type, and
 *    Notifications::prune() is never called). Writing zero rows retires that risk entirely.
 *
 *    Per the board contract with the notifications lane (2026-07-12): that lane owns
 *    notifications.type, the ingest API and the bell. When their ingest lands, chapter
 *    events arrive as ONE call from here — gated by NOTIFY_MAX_MEMBERS below and by the
 *    per-user `muted` flag, both of which are already enforced in this file. See
 *    notifyTargets(). Until then, chapter chat is BADGE-ONLY, which is not a stopgap: it
 *    is the safety default the brief demands.
 *
 * WHAT A ROOM SHARES WITH A DM (deliberately — this is reuse, not duplication):
 *    the `messages` table itself, its media columns, and the whole client-side bubble
 *    renderer. See webroot/chapter-chat.js for the seam.
 * ══════════════════════════════════════════════════════════════════════════════════════
 */
final class ChapterChat
{
    /**
     * The size above which a room will never fan out a per-message notification, even once
     * the notifications lane's ingest exists. Below it, a small room may notify (it behaves
     * like a group DM among people who chose each other). Above it, the unread badge is the
     * only signal, and mentions remain the escape hatch.
     *
     * 50 is the groups-design lane's recommendation and keeper endorsed it as a SAFETY
     * GUARDRAIL, not a preference. DMV starts empty, so it will sit below this for a while.
     */
    public const NOTIFY_MAX_MEMBERS = 50;

    /** Hard cap on a single page of history. */
    private const PAGE_MAX = 200;

    /**
     * Resolve a chapter's room, refusing anything that is not one.
     *
     * ⚠️ THE GUARD. `chapter_id IS NOT NULL` is what makes it impossible to aim a chapter
     * endpoint at a private DM thread. Every public method below goes through here.
     */
    public static function room(int $chapterId): ?array
    {
        $st = Db::pg()->prepare(
            'SELECT id, uuid, chapter_id, subject, last_message_at
               FROM message_threads
              WHERE chapter_id = :c AND chapter_id IS NOT NULL'
        );
        $st->execute([':c' => $chapterId]);
        return $st->fetch() ?: null;
    }

    /**
     * WHO MAY READ: anyone, including logged-out.
     * Chapters are PUBLIC and browsable (Ian, 2026-07-12: no privacy, no permissions, no
     * access control). A room you cannot read is a room you cannot decide to join.
     */
    public static function canRead(): bool
    {
        return true;
    }

    /**
     * WHO MAY POST: members only — and joining is ONE TAP.
     *
     * ⚠️ RECOMMENDED, NOT YET RULED. Asked on the board 2026-07-12; this is the default
     * until Ian says otherwise, and it is a one-line change if he does.
     * The reasoning: it is consistent with "no access control" (nothing is HIDDEN from
     * anyone), it makes membership mean something, it gives a spam floor, and the cost to a
     * willing participant is a single tap they were going to make anyway. It is also what
     * the groups-design lane independently recommended ("read yes, POST needs the one-tap
     * join").
     */
    public static function canPost(int $chapterId, ?string $userUuid): bool
    {
        return $userUuid !== null && Chapters::isMember($chapterId, $userUuid);
    }

    /**
     * A page of room history, newest-last (chat order).
     *
     * $sinceId turns this into a cheap poll: the client sends the highest id it has and gets
     * only what is new, so a 30-message-per-minute room costs one index range scan per poll,
     * not a re-fetch of the visible page.
     */
    public static function messages(int $threadId, ?int $sinceId = null, int $limit = 60): array
    {
        $limit = max(1, min(self::PAGE_MAX, $limit));

        if ($sinceId !== null) {
            $st = Db::pg()->prepare(
                'SELECT m.id, m.sender_uuid, m.body, m.created_at,
                        m.media_url, m.media_mime, m.media_w, m.media_h,
                        u.display_name AS sender_name, u.slug AS sender_slug, u.avatar_url AS sender_avatar
                   FROM messages m
                   JOIN users u ON u.uuid = m.sender_uuid
                  WHERE m.thread_id = :t AND m.id > :since
                  ORDER BY m.id ASC
                  LIMIT :lim'
            );
            $st->bindValue(':since', $sinceId, PDO::PARAM_INT);
        } else {
            // Newest N, then flip to chat order. The subquery keeps us on the (thread_id, id)
            // index instead of sorting the whole room.
            $st = Db::pg()->prepare(
                'SELECT * FROM (
                    SELECT m.id, m.sender_uuid, m.body, m.created_at,
                           m.media_url, m.media_mime, m.media_w, m.media_h,
                           u.display_name AS sender_name, u.slug AS sender_slug, u.avatar_url AS sender_avatar
                      FROM messages m
                      JOIN users u ON u.uuid = m.sender_uuid
                     WHERE m.thread_id = :t
                     ORDER BY m.id DESC
                     LIMIT :lim
                 ) s ORDER BY s.id ASC'
            );
        }
        $st->bindValue(':t', $threadId, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll();
        foreach ($rows as &$r) $r['id'] = (int) $r['id'];
        return $rows;
    }

    /**
     * SEND — one INSERT, whatever the room size.
     *
     * Note what is NOT here: no recipient enumeration, no per-member unread UPDATE, no
     * notification fan-out. Compare Messaging::insertMessage(), which must bump
     * message_recipients.unread_count for every recipient. That difference is the entire
     * reason a room can hold 1,841 people.
     */
    public static function send(int $chapterId, string $senderUuid, string $body): array
    {
        if (!self::canPost($chapterId, $senderUuid)) {
            return ['ok' => false, 'error' => 'not_a_member'];
        }
        $room = self::room($chapterId);
        if (!$room) return ['ok' => false, 'error' => 'no_room'];

        $body = trim($body);
        if ($body === '')            return ['ok' => false, 'error' => 'empty_body'];
        if (mb_strlen($body) > 4000) return ['ok' => false, 'error' => 'body_too_long'];

        $tid = (int) $room['id'];
        $pg  = Db::pg();
        $pg->beginTransaction();
        try {
            $st = $pg->prepare(
                'INSERT INTO messages (thread_id, sender_uuid, body)
                 VALUES (:t, :s, :b) RETURNING id, created_at'
            );
            $st->execute([':t' => $tid, ':s' => $senderUuid, ':b' => $body]);
            $row = $st->fetch();

            // Keep the room's own recency in step (the DM list sorts on this column too).
            $up = $pg->prepare('UPDATE message_threads SET last_message_at = now() WHERE id = :t');
            $up->execute([':t' => $tid]);

            // The sender has, by definition, read their own message. Advancing their own
            // watermark here means their badge never lights up for their own send.
            self::markRead($tid, $senderUuid, (int) $row['id'], $pg);

            $pg->commit();
        } catch (\Throwable $e) {
            $pg->rollBack();
            return ['ok' => false, 'error' => 'send_failed'];
        }

        // ── notification seam (INERT until the notifications lane's ingest lands) ────────
        // Deliberately AFTER the commit and deliberately doing nothing today. When their
        // ingest exists this becomes one call with the targets computed below — already
        // size-gated and mute-aware, so wiring it in cannot reintroduce the fan-out risk.
        //   Notifications::ingest('chapter.chat', $targets, ...);   // see notifyTargets()

        return ['ok' => true, 'id' => (int) $row['id'], 'created_at' => $row['created_at']];
    }

    /**
     * UNREAD — the badge, computed on demand. Zero rows written anywhere to support it.
     *
     * Your own messages are never unread to you. A non-member gets 0 (there is nothing to
     * badge if you are not in the room).
     */
    public static function unreadCount(int $threadId, ?string $userUuid): int
    {
        if (!$userUuid) return 0;
        $st = Db::pg()->prepare(
            'SELECT count(*) FROM messages m
              WHERE m.thread_id = :t1
                AND m.sender_uuid <> :u1
                AND m.id > COALESCE((SELECT r.last_read_message_id
                                       FROM chapter_room_read r
                                      WHERE r.thread_id = :t2 AND r.user_uuid = :u2), 0)'
        );
        $st->execute([':t1' => $threadId, ':t2' => $threadId, ':u1' => $userUuid, ':u2' => $userUuid]);
        return (int) $st->fetchColumn();
    }

    /**
     * Advance the watermark. ONE row per member per room, upserted. Never moves backwards —
     * a slow poll arriving late must not un-read messages the member has already seen.
     *
     * $pg lets send() run this inside its own transaction.
     */
    public static function markRead(int $threadId, string $userUuid, int $lastMessageId, ?PDO $pg = null): void
    {
        $pg ??= Db::pg();
        $st = $pg->prepare(
            'INSERT INTO chapter_room_read (thread_id, user_uuid, last_read_message_id, updated_at)
             VALUES (:t, :u, :m, now())
             ON CONFLICT (thread_id, user_uuid) DO UPDATE
                SET last_read_message_id = GREATEST(chapter_room_read.last_read_message_id,
                                                    EXCLUDED.last_read_message_id),
                    updated_at = now()'
        );
        $st->execute([':t' => $threadId, ':u' => $userUuid, ':m' => $lastMessageId]);
    }

    public static function isMuted(int $threadId, ?string $userUuid): bool
    {
        if (!$userUuid) return false;
        $st = Db::pg()->prepare(
            'SELECT muted FROM chapter_room_read WHERE thread_id = :t AND user_uuid = :u'
        );
        $st->execute([':t' => $threadId, ':u' => $userUuid]);
        return (bool) $st->fetchColumn();
    }

    public static function setMuted(int $threadId, string $userUuid, bool $muted): void
    {
        $st = Db::pg()->prepare(
            'INSERT INTO chapter_room_read (thread_id, user_uuid, muted, updated_at)
             VALUES (:t, :u, :m, now())
             ON CONFLICT (thread_id, user_uuid) DO UPDATE SET muted = EXCLUDED.muted, updated_at = now()'
        );
        $st->execute([':t' => $threadId, ':u' => $userUuid, ':m' => $muted ? 1 : 0]);
    }

    /**
     * WHO WOULD BE NOTIFIED for a room message — the safety valve, implemented and tested
     * now so that wiring in the notifications lane's ingest later is a one-liner that cannot
     * reintroduce an incident.
     *
     * Returns [] — notify NOBODY — when the room is larger than NOTIFY_MAX_MEMBERS. That is
     * the "off by default above a small threshold" rule, enforced at the source rather than
     * left to the consumer to remember. Muted members and the sender are always excluded.
     *
     * An unread badge still appears for everyone regardless (unreadCount() above), because
     * the badge does not depend on notifications at all.
     */
    public static function notifyTargets(int $chapterId, string $senderUuid): array
    {
        if (Chapters::memberCount($chapterId) > self::NOTIFY_MAX_MEMBERS) {
            return [];   // a big room signals by badge only. No fan-out, ever.
        }
        $room = self::room($chapterId);
        if (!$room) return [];

        $st = Db::pg()->prepare(
            'SELECT cm.user_uuid
               FROM chapter_member cm
               LEFT JOIN chapter_room_read r
                      ON r.thread_id = :t AND r.user_uuid = cm.user_uuid
              WHERE cm.chapter_id = :c
                AND cm.user_uuid <> :s
                AND COALESCE(r.muted, false) = false'
        );
        $st->execute([':t' => (int) $room['id'], ':c' => $chapterId, ':s' => $senderUuid]);
        return array_map(static fn ($r) => $r['user_uuid'], $st->fetchAll());
    }
}
