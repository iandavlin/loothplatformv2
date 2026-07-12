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
            "SELECT t.id, t.uuid, t.subject, t.last_message_at, mr.unread_count,
                    lm.body AS last_body, lm.media_url AS last_media,
                    lm.created_at AS last_at, lm.sender_uuid AS last_sender
               FROM message_recipients mr
               JOIN message_threads t ON t.id = mr.thread_id
               LEFT JOIN LATERAL (
                    SELECT body, media_url, created_at, sender_uuid FROM messages m
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
            // Image-only last message → a photo glyph snippet (no body text to show).
            $snippet = trim($body) === '' && !empty($r['last_media'])
                ? '📷 Photo'
                : (mb_strlen($body) > self::SNIPPET ? mb_substr($body, 0, self::SNIPPET) . '…' : $body);
            return [
                'id'              => (int)$r['id'],
                'uuid'            => $r['uuid'],
                'subject'         => $r['subject'],
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

    /** One thread's messages (asc). Marks read for $viewerUuid as a side effect. */
    public static function thread(string $viewerUuid, int $threadId, int $limit = 200): array
    {
        $pg = Db::pg();
        if (!self::isRecipient($viewerUuid, $threadId)) {
            return ['ok' => false, 'error' => 'not_a_recipient'];
        }
        $meta = $pg->prepare('SELECT id, uuid, subject, last_message_at FROM message_threads WHERE id = :t');
        $meta->execute([':t' => $threadId]);
        $thread = $meta->fetch();
        if (!$thread) return ['ok' => false, 'error' => 'not_found'];

        $msgs = $pg->prepare(
            "SELECT id, sender_uuid, body, created_at, media_url, media_mime, media_w, media_h FROM messages
              WHERE thread_id = :t ORDER BY created_at ASC LIMIT :lim"
        );
        $msgs->bindValue(':t', $threadId, \PDO::PARAM_INT);
        $msgs->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $msgs->execute();

        self::markRead($viewerUuid, $threadId);

        return [
            'ok'       => true,
            'thread'   => $thread,
            'peers'    => self::peersByThread([$threadId], $viewerUuid)[$threadId] ?? [],
            'messages' => $msgs->fetchAll(),
        ];
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
            $tid = self::findPairThread($senderUuid, $toUuid) ?? self::createThread([$senderUuid, $toUuid]);
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
        // 1:1 reply must still satisfy the connection gate (peer may have blocked).
        $peers = self::recipientUuids($threadId, $senderUuid);
        if (count($peers) === 1 && !Connections::canMessage($senderUuid, $peers[0])) {
            return ['ok' => false, 'error' => 'not_connected'];
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
            $tid = self::findPairThread($senderUuid, $toUuid) ?? self::createThread([$senderUuid, $toUuid]);
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
     */
    private static function findPairThread(string $a, string $b): ?int
    {
        $st = Db::pg()->prepare(
            "SELECT mr.thread_id
               FROM message_recipients mr
               JOIN message_recipients mr2 ON mr2.thread_id = mr.thread_id
               JOIN message_threads t ON t.id = mr.thread_id
              WHERE mr.user_uuid = :a AND mr2.user_uuid = :b
                AND (SELECT count(*) FROM message_recipients r
                      WHERE r.thread_id = mr.thread_id) = 2
              ORDER BY t.last_message_at DESC LIMIT 1"
        );
        $st->execute([':a' => $a, ':b' => $b]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Create a thread with the given participant uuids (all unread_count 0). */
    private static function createThread(array $participantUuids): int
    {
        $pg = Db::pg();
        $st = $pg->query('INSERT INTO message_threads DEFAULT VALUES RETURNING id');
        $tid = (int)$st->fetchColumn();
        $ins = $pg->prepare(
            'INSERT INTO message_recipients (thread_id, user_uuid) VALUES (:t, :u)'
        );
        foreach (array_unique($participantUuids) as $u) {
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
}
