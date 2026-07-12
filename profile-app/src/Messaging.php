<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

require_once __DIR__ . '/Connections.php';
require_once __DIR__ . '/Notifications.php';

/**
 * Messaging — thin, async member↔member DMs. threads / messages / recipients on
 * postgres; NOT realtime. Identity via /whoami (uuid). Every write asserts the
 * actor is a thread participant. GATE (Ian, 2026-05-30): CONNECTIONS-ONLY — a new
 * DM requires an accepted mutual connection. Table: sql/2026-05-30-social-layer.sql.
 */
final class Messaging
{
    private const SNIPPET = 140;

    /** Thread list for the messages modal: peers, last snippet, unread, last_message_at. */
    public static function threadsFor(string $uuid, int $limit = 30, int $offset = 0): array
    {
        $pg = Db::pg();
        $st = $pg->prepare(
            "SELECT t.id, t.uuid, t.subject, t.last_message_at, t.is_group, mr.unread_count,
                    lm.body AS last_body, lm.media_url AS last_media,
                    lm.created_at AS last_at, lm.sender_uuid AS last_sender,
                    lm.kind AS last_kind, lm.deleted_at AS last_deleted
               FROM message_recipients mr
               JOIN message_threads t ON t.id = mr.thread_id
               LEFT JOIN LATERAL (
                    SELECT body, media_url, created_at, sender_uuid, kind, deleted_at FROM messages m
                     WHERE m.thread_id = t.id ORDER BY m.created_at DESC LIMIT 1
               ) lm ON true
              WHERE mr.user_uuid = :u AND mr.is_deleted = false
              ORDER BY t.last_message_at DESC
              LIMIT :lim OFFSET :off"
        );
        $st->bindValue(':u', $uuid);
        $st->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $st->bindValue(':off', $offset, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        if (!$rows) return [];

        $peers = self::peersByThread(array_column($rows, 'id'), $uuid);

        return array_map(static function (array $r) use ($peers): array {
            $body = (string)($r['last_body'] ?? '');
            if ($r['last_deleted'] !== null) {
                // Newest message is a tombstone → say so, never leak its old body/media.
                $snippet = 'Message deleted';
            } elseif (($r['last_kind'] ?? 'message') === 'system') {
                // Membership line ("Sharon left") is already a short sentence — show it whole.
                $snippet = mb_strlen($body) > self::SNIPPET ? mb_substr($body, 0, self::SNIPPET) . '…' : $body;
            } elseif (trim($body) === '' && !empty($r['last_media'])) {
                // Image-only last message → a photo glyph snippet (no body text to show).
                $snippet = '📷 Photo';
            } else {
                $snippet = mb_strlen($body) > self::SNIPPET ? mb_substr($body, 0, self::SNIPPET) . '…' : $body;
            }
            return [
                'id'              => (int)$r['id'],
                'uuid'            => $r['uuid'],
                'subject'         => $r['subject'],
                'is_group'        => (bool)$r['is_group'],
                'last_message_at' => $r['last_message_at'],
                'unread_count'    => (int)$r['unread_count'],
                'last_snippet'    => $snippet,
                'last_sender'     => $r['last_sender'],
                'peers'           => $peers[(int)$r['id']] ?? [],
            ];
        }, $rows);
    }

    /**
     * Other-party identities grouped by thread id (for the thread list / header).
     *
     * The ORDER BY is load-bearing, not cosmetic. Without it Postgres is free to return a
     * thread's peers in any order, and it does: the thread LIST calls this with ~30 ids
     * and the thread DETAIL with 1, which are different query plans and came back in
     * different orders. Both surfaces then displayed peers[0] — so the SAME multi-peer
     * thread was listed as "Doug" and opened as "John", on a cold load, with nothing
     * stale and nothing racing (2026-07-10). display_name, then uuid as the tiebreak =
     * one total order every caller sees. Callers must still render ALL peers, never
     * peers[0]; deterministic order makes them AGREE, it does not make one peer "the"
     * peer.
     */
    private static function peersByThread(array $threadIds, string $selfUuid): array
    {
        if (!$threadIds) return [];
        $place = implode(',', array_fill(0, count($threadIds), '?'));
        $st = Db::pg()->prepare(
            "SELECT mr.thread_id, u.uuid, u.display_name, u.slug, u.avatar_url
               FROM message_recipients mr
               JOIN users u ON u.uuid = mr.user_uuid
              WHERE mr.thread_id IN ($place) AND mr.user_uuid <> ?
              ORDER BY mr.thread_id, u.display_name, u.uuid"
        );
        $st->execute([...array_map('intval', $threadIds), $selfUuid]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['thread_id']][] = [
                'uuid'       => $r['uuid'],
                'name'       => $r['display_name'],
                'slug'       => $r['slug'],
                'avatar_url' => $r['avatar_url'],
            ];
        }
        return $out;
    }

    /**
     * One thread's messages (asc). Marks read for $viewerUuid as a side effect.
     *
     * $viewerIsAdmin (site admin per Auth::isAdmin(), passed in by the endpoint that owns
     * the request context) feeds `can_manage`: the viewer may remove OTHER members when
     * they started the thread OR they are a site admin (Ian 2026-07-12). Full history is
     * returned regardless of when a member joined — Ian ruled added members see everything.
     */
    public static function thread(string $viewerUuid, int $threadId, bool $viewerIsAdmin = false, int $limit = 200): array
    {
        $pg = Db::pg();
        if (!self::isRecipient($viewerUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        $meta = $pg->prepare('SELECT id, uuid, subject, last_message_at, is_group, created_by FROM message_threads WHERE id = :t');
        $meta->execute([':t' => $threadId]);
        $thread = $meta->fetch();
        if (!$thread) return ['ok' => false, 'error' => 'not_found'];

        $msgs = $pg->prepare(
            "SELECT id, sender_uuid, body, created_at, media_url, media_mime, media_w, media_h,
                    kind, edited_at, deleted_at
               FROM messages
              WHERE thread_id = :t ORDER BY created_at ASC LIMIT :lim"
        );
        $msgs->bindValue(':t', $threadId, \PDO::PARAM_INT);
        $msgs->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $msgs->execute();

        self::markRead($viewerUuid, $threadId);

        $isGroup   = (bool)$thread['is_group'];
        $createdBy = $thread['created_by'];
        return [
            'ok'         => true,
            'thread'     => $thread,
            'is_group'   => $isGroup,
            'created_by' => $createdBy,
            // Creator or site admin may remove others; everyone may always leave.
            'can_manage' => $viewerIsAdmin || ($createdBy !== null && $createdBy === $viewerUuid),
            'peers'      => self::peersByThread([$threadId], $viewerUuid)[$threadId] ?? [],
            'members'    => self::threadMembers($threadId),
            'messages'   => array_map([self::class, 'shapeMessage'], $msgs->fetchAll()),
        ];
    }

    /**
     * Wire shape for one message row: a soft-deleted message is a TOMBSTONE — its body and
     * any media reference are withheld from the payload entirely (the bytes are already GC'd
     * server-side on delete), leaving only `deleted:true` so both surfaces render "Message
     * deleted" without ever exposing the old content. `edited:true` drives the "(edited)"
     * marker; `kind` distinguishes a membership system line from a real message.
     */
    private static function shapeMessage(array $m): array
    {
        $deleted = $m['deleted_at'] !== null;
        return [
            'id'          => (int)$m['id'],
            'sender_uuid' => $m['sender_uuid'],
            'kind'        => $m['kind'] ?? 'message',
            'created_at'  => $m['created_at'],
            'edited'      => $m['edited_at'] !== null,
            'deleted'     => $deleted,
            'body'        => $deleted ? '' : (string)$m['body'],
            'media_url'   => $deleted ? null : $m['media_url'],
            'media_mime'  => $deleted ? null : $m['media_mime'],
            'media_w'     => $deleted ? null : $m['media_w'],
            'media_h'     => $deleted ? null : $m['media_h'],
        ];
    }

    /** Every recipient identity of a thread (INCLUDING the viewer) — for the member manager. */
    private static function threadMembers(int $threadId): array
    {
        $st = Db::pg()->prepare(
            "SELECT u.uuid, u.display_name AS name, u.slug, u.avatar_url
               FROM message_recipients mr
               JOIN users u ON u.uuid = mr.user_uuid
              WHERE mr.thread_id = :t
              ORDER BY u.display_name, u.uuid"
        );
        $st->execute([':t' => $threadId]);
        return $st->fetchAll();
    }

    /** The once-a-group flag (NOT the live recipient count — a shrunk group stays a group). */
    private static function isGroupThread(int $threadId): bool
    {
        $st = Db::pg()->prepare('SELECT is_group FROM message_threads WHERE id = :t');
        $st->execute([':t' => $threadId]);
        return (bool)$st->fetchColumn();
    }

    private static function isRecipient(string $uuid, int $threadId): bool
    {
        $st = Db::pg()->prepare(
            'SELECT 1 FROM message_recipients WHERE thread_id = :t AND user_uuid = :u'
        );
        $st->execute([':t' => $threadId, ':u' => $uuid]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Send a message. $threadId set → reply in that thread; else start/find the
     * 1:1 thread with $toUuid. Connections-only gate on NEW conversations.
     */
    public static function send(string $senderUuid, ?int $threadId, ?string $toUuid, string $body, ?array $media = null): array
    {
        $body = trim($body);
        // Empty body is allowed ONLY when an image is attached (image-only message).
        if ($body === '' && !$media) return ['ok' => false, 'error' => 'empty_body'];
        $pg = Db::pg();

        if ($threadId !== null) {
            $gate = self::canSendTo($senderUuid, $threadId);
            if (!$gate['ok']) return $gate;
            return self::insertMessage($threadId, $senderUuid, $body, $media);
        }

        if (!$toUuid) return ['ok' => false, 'error' => 'no_recipient'];
        if (!Connections::canMessage($senderUuid, $toUuid)) {
            return ['ok' => false, 'error' => 'not_connected'];
        }

        $pg->beginTransaction();
        try {
            $tid = self::findPairThread($senderUuid, $toUuid) ?? self::createThread([$senderUuid, $toUuid], $senderUuid);
            $res = self::insertMessage($tid, $senderUuid, $body, $media);
            $pg->commit();
            return $res;
        } catch (\Throwable $e) {
            $pg->rollBack();
            return ['ok' => false, 'error' => 'send_failed'];
        }
    }

    /**
     * Reply gate for an EXISTING thread (recipient + 1:1 connection check), without
     * inserting. Extracted so the image upload endpoint can authorize a reply BEFORE
     * it stores any bytes (never store an attachment for a non-participant). send()
     * uses the same gate, so the two paths can never diverge.
     */
    public static function canSendTo(string $senderUuid, int $threadId): array
    {
        if (!self::isRecipient($senderUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        // The connection gate is a 1:1 concern only. A GROUP (incl. one that shrank to two
        // members) is never gated on a mutual connection — migrated groups routinely hold
        // non-connections, and requiring one would silence a live group. isGroupThread()
        // reads the once-a-group flag, not the live count, so a shrunk group stays a group.
        if (!self::isGroupThread($threadId)) {
            $peers = self::recipientUuids($threadId, $senderUuid);
            if (count($peers) === 1 && !Connections::canMessage($senderUuid, $peers[0])) {
                return ['ok' => false, 'error' => 'not_connected'];
            }
        }
        return ['ok' => true];
    }

    /** Is $viewerUuid a participant of $threadId? Public gate for the media proxy. */
    public static function isParticipant(string $viewerUuid, int $threadId): bool
    {
        return self::isRecipient($viewerUuid, $threadId);
    }

    /**
     * Find/create the 1:1 thread with $toUuid WITHOUT sending — used by the image
     * upload endpoint to learn the thread uuid (for the R2 key + served path)
     * before the bytes are stored. Connections gate identical to send().
     * Returns ['ok'=>true,'thread_id','thread_uuid'] or an error array.
     */
    public static function ensurePairThread(string $senderUuid, string $toUuid): array
    {
        if (!Connections::canMessage($senderUuid, $toUuid)) {
            return ['ok' => false, 'error' => 'not_connected'];
        }
        $pg = Db::pg();
        $pg->beginTransaction();
        try {
            $tid = self::findPairThread($senderUuid, $toUuid) ?? self::createThread([$senderUuid, $toUuid], $senderUuid);
            $pg->commit();
        } catch (\Throwable $e) {
            $pg->rollBack();
            return ['ok' => false, 'error' => 'thread_failed'];
        }
        $st = $pg->prepare('SELECT uuid FROM message_threads WHERE id = :t');
        $st->execute([':t' => $tid]);
        return ['ok' => true, 'thread_id' => $tid, 'thread_uuid' => (string)$st->fetchColumn()];
    }

    /** Recipient uuids of a thread other than $exceptUuid. */
    private static function recipientUuids(int $threadId, string $exceptUuid): array
    {
        $st = Db::pg()->prepare(
            'SELECT user_uuid FROM message_recipients WHERE thread_id = :t AND user_uuid <> :s'
        );
        $st->execute([':t' => $threadId, ':s' => $exceptUuid]);
        return array_map('strval', array_column($st->fetchAll(), 'user_uuid'));
    }

    /**
     * The existing 1:1 thread between two users (most recent), or null.
     *
     * The recipient-count clause is a PRIVACY gate, not an optimisation. "Both A and B
     * are recipients" also matches a GROUP thread that happens to contain them, so
     * without it, pressing "Message" on Doug's profile and typing resolved to the
     * 4-person BuddyBoss thread and delivered a private-looking DM to three other
     * people. A pair thread is a thread with exactly TWO recipients; anything else is a
     * group and must never be reached by a 1:1 send (2026-07-10). ensurePairThread()
     * (image upload) shares this resolver, so both send paths are gated in one place.
     *
     * The `t.is_group = false` clause is the second lock (2026-07-12 group-mgmt lane): a
     * group that LATER shrinks to two members would again satisfy count(*)=2, so the
     * once-a-group-always flag keeps it unreachable by a 1:1 send. A true 1:1 is never
     * flagged is_group, so both clauses agree for the pair case.
     */
    private static function findPairThread(string $a, string $b): ?int
    {
        $st = Db::pg()->prepare(
            "SELECT mr.thread_id
               FROM message_recipients mr
               JOIN message_recipients mr2 ON mr2.thread_id = mr.thread_id
               JOIN message_threads t ON t.id = mr.thread_id
              WHERE mr.user_uuid = :a AND mr2.user_uuid = :b
                AND t.is_group = false
                AND (SELECT count(*) FROM message_recipients r
                      WHERE r.thread_id = mr.thread_id) = 2
              ORDER BY t.last_message_at DESC LIMIT 1"
        );
        $st->execute([':a' => $a, ':b' => $b]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Create a thread with the given participant uuids (all unread_count 0). is_group is
     * set from the DISTINCT participant count (>2 = a group, once-a-group-always), and
     * $createdBy records the starter so the group-management remove rule (creator/admin/
     * self) has an anchor. A 1:1 (2 participants) records created_by too — harmless, and
     * it keeps a single code path — but stays is_group = false so the pair gate can reach
     * it. $createdBy stays null only for callers that don't have a starter (none today).
     */
    private static function createThread(array $participantUuids, ?string $createdBy = null): int
    {
        $pg = Db::pg();
        $members = array_values(array_unique($participantUuids));
        $isGroup = count($members) > 2;
        $st = $pg->prepare(
            'INSERT INTO message_threads (is_group, created_by) VALUES (:g, :c) RETURNING id'
        );
        $st->execute([':g' => $isGroup ? 'true' : 'false', ':c' => $createdBy]);
        $tid = (int)$st->fetchColumn();
        $ins = $pg->prepare(
            'INSERT INTO message_recipients (thread_id, user_uuid) VALUES (:t, :u)'
        );
        foreach ($members as $u) {
            $ins->execute([':t' => $tid, ':u' => $u]);
        }
        return $tid;
    }

    /**
     * Insert a message, bump the thread, and fan out unread to every OTHER recipient
     * (the message badge). Sender's own recipient row stays read. DMs do NOT raise a
     * bell notification — the message badge is the sole DM signal (Ian, 2026-05-31).
     */
    private static function insertMessage(int $threadId, string $senderUuid, string $body, ?array $media = null): array
    {
        $pg = Db::pg();

        $st = $pg->prepare(
            'INSERT INTO messages (thread_id, sender_uuid, body, media_url, media_mime, media_w, media_h)
             VALUES (:t, :s, :b, :mu, :mm, :mw, :mh) RETURNING id, created_at'
        );
        $st->execute([
            ':t'  => $threadId,
            ':s'  => $senderUuid,
            ':b'  => $body,
            ':mu' => $media['url']  ?? null,
            ':mm' => $media['mime'] ?? null,
            ':mw' => isset($media['w']) ? (int)$media['w'] : null,
            ':mh' => isset($media['h']) ? (int)$media['h'] : null,
        ]);
        // (kind defaults to 'message' — real user messages only reach this path; membership
        //  system lines go through insertSystemLine, which does NOT fan out unread.)
        $msg = $st->fetch();

        $pg->prepare('UPDATE message_threads SET last_message_at = now() WHERE id = :t')
           ->execute([':t' => $threadId]);

        // bump unread for everyone but the sender; un-delete their view (new activity).
        // NOTE: DMs are signalled ONLY by the message badge (messages_unread), NOT the
        // bell (Ian, 2026-05-31: no double-notify). The bell carries connection events
        // only — so there is intentionally no Notifications::push('message', …) here.
        $pg->prepare(
            'UPDATE message_recipients
                SET unread_count = unread_count + 1, is_deleted = false
              WHERE thread_id = :t AND user_uuid <> :s'
        )->execute([':t' => $threadId, ':s' => $senderUuid]);

        return [
            'ok'         => true,
            'thread_id'  => $threadId,
            'message_id' => (int)$msg['id'],
            'created_at' => $msg['created_at'],
            'media_url'  => $media['url'] ?? null,
        ];
    }

    /** Mark a thread read for the viewer (clears their unread, stamps last_read_at). */
    public static function markRead(string $viewerUuid, int $threadId): void
    {
        $st = Db::pg()->prepare(
            'UPDATE message_recipients
                SET unread_count = 0, last_read_at = now()
              WHERE thread_id = :t AND user_uuid = :v AND unread_count > 0'
        );
        $st->execute([':t' => $threadId, ':v' => $viewerUuid]);
    }

    /** Total unread across threads → header messages badge. */
    public static function unreadCount(string $uuid): int
    {
        $st = Db::pg()->prepare(
            'SELECT COALESCE(SUM(unread_count), 0) FROM message_recipients
              WHERE user_uuid = :u AND is_deleted = false'
        );
        $st->execute([':u' => $uuid]);
        return (int)$st->fetchColumn();
    }

    // ── group management (Ian 2026-07-12: start / add / remove / leave) ──────────────
    //
    // Membership is the PRESENCE of a message_recipients row. Remove/leave DELETE the row,
    // which inherits the existing deny model unchanged (isRecipient → false → 404) — the
    // thread vanishes from the ex-member's list, their already-sent messages stay attributed
    // (messages.sender_uuid is independent of the row), and a re-add is a fresh row. Every
    // change writes a SYSTEM LINE into the thread (transparency instead of roles — flat, per
    // Ian). The audit trail is those lines, not a permissions table.

    /** All recipient uuids of a thread (no exclusions). */
    private static function allRecipientUuids(int $threadId): array
    {
        $st = Db::pg()->prepare('SELECT user_uuid FROM message_recipients WHERE thread_id = :t');
        $st->execute([':t' => $threadId]);
        return array_map('strval', array_column($st->fetchAll(), 'user_uuid'));
    }

    /** Display name for a uuid, or 'Member' when missing — used to render system lines. */
    private static function displayName(string $uuid): string
    {
        $st = Db::pg()->prepare('SELECT display_name FROM users WHERE uuid = :u');
        $st->execute([':u' => $uuid]);
        $n = (string)($st->fetchColumn() ?: '');
        return $n === '' ? 'Member' : $n;
    }

    /**
     * A membership event line ("Ian added Doug", "Sharon left"). kind='system'; centered,
     * never owned, never editable. Bumps last_message_at so the change surfaces the thread,
     * and un-deletes each member's view (a re-activated group resurfaces) — but does NOT fan
     * out unread (D11: membership churn must not light the message badge). The actor's uuid
     * is the sender; it needs no recipient row (a "left" line outlives its author's row).
     */
    private static function insertSystemLine(int $threadId, string $actorUuid, string $text): void
    {
        $pg = Db::pg();
        $pg->prepare(
            "INSERT INTO messages (thread_id, sender_uuid, body, kind) VALUES (:t, :s, :b, 'system')"
        )->execute([':t' => $threadId, ':s' => $actorUuid, ':b' => $text]);
        $pg->prepare('UPDATE message_threads SET last_message_at = now() WHERE id = :t')
           ->execute([':t' => $threadId]);
        $pg->prepare('UPDATE message_recipients SET is_deleted = false WHERE thread_id = :t')
           ->execute([':t' => $threadId]);
    }

    /**
     * Start a GROUP thread (>2 people) and post its first message. $memberUuids are the
     * OTHER members; the creator is added automatically and recorded as created_by (the
     * remove-rights anchor). Connections-only gate: you may only start a group with people
     * you are connected to. Fewer than 2 others is not a group — the 1:1 send path owns that.
     */
    public static function startGroup(string $creatorUuid, array $memberUuids, string $body, ?array $media = null): array
    {
        $body = trim($body);
        if ($body === '' && !$media) return ['ok' => false, 'error' => 'empty_body'];
        $members = array_values(array_filter(
            array_unique(array_map('strval', $memberUuids)),
            static fn ($u) => $u !== '' && $u !== $creatorUuid
        ));
        if (count($members) < 2) return ['ok' => false, 'error' => 'need_group'];
        foreach ($members as $u) {
            if (!Connections::canMessage($creatorUuid, $u)) {
                return ['ok' => false, 'error' => 'not_connected', 'uuid' => $u];
            }
        }
        $pg = Db::pg();
        $pg->beginTransaction();
        try {
            $tid = self::createThread(array_merge([$creatorUuid], $members), $creatorUuid);
            self::insertSystemLine($tid, $creatorUuid, self::displayName($creatorUuid) . ' started the group');
            $res = self::insertMessage($tid, $creatorUuid, $body, $media);
            $pg->commit();
        } catch (\Throwable $e) {
            $pg->rollBack();
            return ['ok' => false, 'error' => 'group_failed'];
        }
        $u = $pg->prepare('SELECT uuid FROM message_threads WHERE id = :t');
        $u->execute([':t' => $tid]);
        $res['thread_uuid'] = (string)$u->fetchColumn();
        return $res;
    }

    /**
     * Add members to a thread. ANY participant may add someone they are connected to.
     *
     * FORK RULE (D4): adding to a 1:1 (is_group=false) NEVER converts it — a private DM must
     * stay private. Instead a NEW group thread is forked (the two existing peers + the added
     * people), and the old 1:1 is left untouched. Adding to a thread that is already a group
     * adds in place. Returns forked:true + the new thread uuid when it forks.
     */
    public static function addMembers(string $actorUuid, int $threadId, array $newUuids): array
    {
        if (!self::isRecipient($actorUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        $newUuids = array_values(array_filter(array_unique(array_map('strval', $newUuids)), static fn ($u) => $u !== ''));
        if (!$newUuids) return ['ok' => false, 'error' => 'no_members'];
        foreach ($newUuids as $u) {
            if (!Connections::canMessage($actorUuid, $u)) {
                return ['ok' => false, 'error' => 'not_connected', 'uuid' => $u];
            }
        }
        $cur   = self::allRecipientUuids($threadId);
        $toAdd = array_values(array_diff($newUuids, $cur));
        if (!$toAdd) return ['ok' => false, 'error' => 'already_members'];

        $pg = Db::pg();

        if (!self::isGroupThread($threadId)) {
            // 1:1 → fork a new group; the private DM is never mutated.
            $pg->beginTransaction();
            try {
                $tid = self::createThread(array_merge($cur, $toAdd), $actorUuid);
                self::insertSystemLine($tid, $actorUuid, self::displayName($actorUuid) . ' started the group');
                $pg->commit();
            } catch (\Throwable $e) {
                $pg->rollBack();
                return ['ok' => false, 'error' => 'fork_failed'];
            }
            $uu = $pg->prepare('SELECT uuid FROM message_threads WHERE id = :t');
            $uu->execute([':t' => $tid]);
            return ['ok' => true, 'forked' => true, 'thread_id' => $tid, 'thread_uuid' => (string)$uu->fetchColumn()];
        }

        $pg->beginTransaction();
        try {
            $ins = $pg->prepare(
                'INSERT INTO message_recipients (thread_id, user_uuid) VALUES (:t, :u) ON CONFLICT DO NOTHING'
            );
            foreach ($toAdd as $u) {
                $ins->execute([':t' => $threadId, ':u' => $u]);
                self::insertSystemLine($threadId, $actorUuid, self::displayName($actorUuid) . ' added ' . self::displayName($u));
            }
            $pg->commit();
        } catch (\Throwable $e) {
            $pg->rollBack();
            return ['ok' => false, 'error' => 'add_failed'];
        }
        return ['ok' => true, 'thread_id' => $threadId];
    }

    /**
     * Remove ANOTHER member. Ian's ruling (2026-07-12): only the thread CREATOR or a site
     * admin may remove someone else; anyone may always remove THEMSELVES (routed to leave()).
     * Legacy/migrated threads have created_by=NULL → only an admin (or the member's own
     * leave) can remove. $actorIsAdmin is passed by the endpoint (it owns Auth::isAdmin()).
     */
    public static function removeMember(string $actorUuid, int $threadId, string $targetUuid, bool $actorIsAdmin): array
    {
        if (!self::isRecipient($actorUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        if ($targetUuid === $actorUuid) return self::leave($actorUuid, $threadId);

        $st = Db::pg()->prepare('SELECT created_by FROM message_threads WHERE id = :t');
        $st->execute([':t' => $threadId]);
        $createdBy = $st->fetchColumn();
        $isCreator = $createdBy !== false && $createdBy !== null && $createdBy === $actorUuid;
        if (!$isCreator && !$actorIsAdmin) return ['ok' => false, 'error' => 'forbidden'];
        if (!self::isRecipient($targetUuid, $threadId)) return ['ok' => false, 'error' => 'not_a_member'];

        $pg = Db::pg();
        $pg->beginTransaction();
        try {
            $pg->prepare('DELETE FROM message_recipients WHERE thread_id = :t AND user_uuid = :u')
               ->execute([':t' => $threadId, ':u' => $targetUuid]);
            self::insertSystemLine($threadId, $actorUuid, self::displayName($actorUuid) . ' removed ' . self::displayName($targetUuid));
            $pg->commit();
        } catch (\Throwable $e) {
            $pg->rollBack();
            return ['ok' => false, 'error' => 'remove_failed'];
        }
        return ['ok' => true, 'thread_id' => $threadId];
    }

    /** Remove YOURSELF from a thread. Always allowed for a participant. */
    public static function leave(string $actorUuid, int $threadId): array
    {
        if (!self::isRecipient($actorUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        $pg = Db::pg();
        $pg->beginTransaction();
        try {
            // Line first (so the leaver still resolves as a member for its text), then delete.
            self::insertSystemLine($threadId, $actorUuid, self::displayName($actorUuid) . ' left');
            $pg->prepare('DELETE FROM message_recipients WHERE thread_id = :t AND user_uuid = :u')
               ->execute([':t' => $threadId, ':u' => $actorUuid]);
            $pg->commit();
        } catch (\Throwable $e) {
            $pg->rollBack();
            return ['ok' => false, 'error' => 'leave_failed'];
        }
        return ['ok' => true, 'left' => true];
    }

    // ── edit / delete own message (Ian 2026-07-12) ──────────────────────────────────

    /** Edit OWN message text. Owner-only, live real message only (never a system line or a
     *  tombstone). Sets edited_at → the "(edited)" marker. Non-owner → forbidden (4xx). */
    public static function editMessage(string $actorUuid, int $threadId, int $messageId, string $body): array
    {
        $body = trim($body);
        if ($body === '') return ['ok' => false, 'error' => 'empty_body'];
        if (!self::isRecipient($actorUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        $pg = Db::pg();
        $st = $pg->prepare('SELECT sender_uuid, kind, deleted_at FROM messages WHERE id = :m AND thread_id = :t');
        $st->execute([':m' => $messageId, ':t' => $threadId]);
        $row = $st->fetch();
        if (!$row) return ['ok' => false, 'error' => 'not_found'];
        if ($row['sender_uuid'] !== $actorUuid) return ['ok' => false, 'error' => 'forbidden'];
        if ($row['kind'] !== 'message' || $row['deleted_at'] !== null) return ['ok' => false, 'error' => 'not_editable'];

        $pg->prepare('UPDATE messages SET body = :b, edited_at = now() WHERE id = :m')
           ->execute([':b' => $body, ':m' => $messageId]);
        return ['ok' => true, 'message_id' => $messageId, 'body' => $body, 'edited' => true];
    }

    /**
     * Soft-delete OWN message → a "Message deleted" tombstone (the row stays so thread flow
     * survives). Owner-only. Blanks the body AND strips the media reference in the DB (the
     * old content is truly gone, not merely hidden) and RETURNS the old media_url so the
     * endpoint can GC the object via the MessageR2 abstraction / local store. Idempotent.
     */
    public static function deleteMessage(string $actorUuid, int $threadId, int $messageId): array
    {
        if (!self::isRecipient($actorUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        $pg = Db::pg();
        $st = $pg->prepare('SELECT sender_uuid, kind, deleted_at, media_url FROM messages WHERE id = :m AND thread_id = :t');
        $st->execute([':m' => $messageId, ':t' => $threadId]);
        $row = $st->fetch();
        if (!$row) return ['ok' => false, 'error' => 'not_found'];
        if ($row['sender_uuid'] !== $actorUuid) return ['ok' => false, 'error' => 'forbidden'];
        if ($row['kind'] !== 'message')  return ['ok' => false, 'error' => 'not_deletable'];
        if ($row['deleted_at'] !== null) return ['ok' => true, 'message_id' => $messageId, 'deleted' => true, 'media_url' => null];

        $old = $row['media_url'];
        $pg->prepare(
            "UPDATE messages
                SET deleted_at = now(), body = '', media_url = NULL, media_mime = NULL, media_w = NULL, media_h = NULL
              WHERE id = :m"
        )->execute([':m' => $messageId]);
        return ['ok' => true, 'message_id' => $messageId, 'deleted' => true, 'media_url' => $old];
    }
}
