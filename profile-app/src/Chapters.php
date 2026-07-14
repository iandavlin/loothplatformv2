<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;

/**
 * CHAPTERS — the native chapter model (the strangler test).
 *
 * A chapter depends on NO BuddyBoss and NO WordPress. Identity, membership,
 * discussions and the map are all native Postgres in profile_app.
 * The only thing it cannot shed is AUTH: members still sign in via the WP cookie,
 * which mints the looth_id JWT that Auth reads. That is out of scope; we ride it.
 *
 * IAN'S RULINGS BAKED IN HERE (2026-07-12) — do not re-open:
 *   * Chapters are PUBLIC and browsable. No privacy, no permissions, no access control.
 *     There is deliberately no visibility column, no role column, no approval state.
 *   * Join is ONE TAP, self-serve, no approval. Chapters are opt-IN and start EMPTY.
 *   * Legacy BuddyBoss membership is NOT imported (it is junk).
 *   * DISCUSSIONS are the single chapter content surface (chapter_post) — they carry both
 *     durable announcements and throwaway chatter ("everything can be done from discussions").
 *     The chat ROOM is DEFERRED, not cancelled; nothing room-shaped is built here. There is
 *     NO separate activity feed.
 *   * A chapter is a DATA ROW, not code. Adding "Austin Looths" is an INSERT.
 *     See docs/atlas/CHAPTERS-RUNBOOK.md.
 *
 * LOCATION PRIVACY — READ THIS BEFORE TOUCHING THE MAP.
 * This class contains NO geo query and NO coordinate maths, on purpose. The chapter
 * map is served by the EXISTING clamped path — /profile-api/v0/directory/members
 * ?pins=1&chapter=<slug> — which runs every pin through
 * Visibility::locationPrecision() + Block::locationDisplay(). The chapter filter
 * there is one additive, NARROWING where-clause; the clamp stays the last thing that
 * touches a coordinate. Never select users.lat/users.lng in this file.
 */
final class Chapters
{
    /**
     * ⚠️ THE ONLY UNIT CONVERSION IN THE CHAPTER CODE.
     * chapter.radius_km is kilometres (the product contract). Everything underneath is
     * MILES: earthdistance's <@> returns miles, the pins API's `radius` param is miles,
     * directory-mobile.js clamps 1..500 miles. Convert HERE and nowhere else, or a
     * chapter's drawn circle and its member query will silently disagree by 1.6x.
     */
    private const KM_PER_MI = 1.609344;

    /** The pins API clamps radius to 1..500 miles; mirror it so we never send a value it will silently change. */
    private const RADIUS_MI_MIN = 1;
    private const RADIUS_MI_MAX = 500;

    public static function radiusMi(array $chapter): int
    {
        $mi = (int) round(((float) $chapter['radius_km']) / self::KM_PER_MI);
        return max(self::RADIUS_MI_MIN, min(self::RADIUS_MI_MAX, $mi));
    }

    public static function bySlug(string $slug): ?array
    {
        $st = Db::pg()->prepare(
            'SELECT id, slug, name, description, center_lat, center_lng, radius_km, is_active, created_at
               FROM chapter WHERE slug = :s AND is_active = true'
        );
        $st->execute([':s' => $slug]);
        return $st->fetch() ?: null;
    }

    /** Every active chapter, for an index page and for the "near you" suggestion. */
    public static function all(): array
    {
        return Db::pg()->query(
            'SELECT id, slug, name, description, center_lat, center_lng, radius_km
               FROM chapter WHERE is_active = true ORDER BY name ASC'
        )->fetchAll();
    }

    /**
     * Member count. This is the ONE source of the number — it comes from chapter_member,
     * NEVER from the map. A member with no location, or with location hidden, has no pin
     * but still counts here. (Brief, §2: "simply has no pin (but still counts as a member)".)
     *
     * GHOST CONTAINMENT (main 2026-07-13): an unbridged identity (no wp_user_bridge row —
     * cannot log in, accept, or read a DM) is NOT A MEMBER anywhere else, so it must not be
     * counted here either. In practice a ghost can never join (join() needs an authed
     * session, which needs a bridge) so this changes nothing today; it exists so that if
     * identity-reconcile purges or unbridges an identity that somehow holds a chapter_member
     * row, the count SELF-HEALS and never reports a population the directory/map can't show.
     * Same rule as directory-members' shared $wheres: exactly "has a bridge row".
     */
    public static function memberCount(int $chapterId): int
    {
        $st = Db::pg()->prepare(
            'SELECT count(*) FROM chapter_member cm
               JOIN users u ON u.uuid = cm.user_uuid
              WHERE cm.chapter_id = :c
                AND EXISTS (SELECT 1 FROM wp_user_bridge b WHERE b.user_id = u.id)'
        );
        $st->execute([':c' => $chapterId]);
        return (int) $st->fetchColumn();
    }

    public static function isMember(int $chapterId, ?string $userUuid): bool
    {
        if (!$userUuid) return false;
        $st = Db::pg()->prepare('SELECT 1 FROM chapter_member WHERE chapter_id = :c AND user_uuid = :u');
        $st->execute([':c' => $chapterId, ':u' => $userUuid]);
        return (bool) $st->fetchColumn();
    }

    /**
     * The member ROSTER — names + avatars for the chapter page (CHAPTER-V2 ask 2, Ian 2026-07-14).
     * Identity only (uuid/name/slug/avatar); NO location and NO coordinate ever leaves here — the
     * roster is "who is in this chapter", not the map (the map stays the clamped pins path).
     *
     * Two visibility gates, applied SERVER-SIDE in one query so pagination is exact and a hidden
     * member can never leak onto a later page:
     *   1. GHOST CONTAINMENT — a member with no wp_user_bridge row is not a person and never
     *      appears (identical rule to directory-members' shared $wheres and to memberCount).
     *   2. VISIBILITY — the master switch (profile_visibility private = owner/admin only) AND the
     *      finer header ceiling (profile_sections key='header': public|members|private, default
     *      'members'), resolved through the same audience×visibility truth table the directory
     *      uses (Visibility::audienceCanSee): anon sees only 'public' headers, a signed-in member
     *      also sees 'members', owner/admin see all.
     * A member hidden by either gate drops out of the LIST but is STILL counted by memberCount —
     * the same list-vs-count split we already ship for map pins.
     */
    public static function members(int $chapterId, int $viewerUserId, bool $isAdmin,
                                   int $limit = 60, int $offset = 0): array
    {
        $st = Db::pg()->prepare(
            "SELECT u.uuid, u.display_name, u.slug, u.avatar_url
               FROM chapter_member cm
               JOIN users u ON u.uuid = cm.user_uuid
               LEFT JOIN profile_sections ps ON ps.user_id = u.id AND ps.key = 'header'
              WHERE cm.chapter_id = :cid
                AND u.archived_at IS NULL
                AND EXISTS (SELECT 1 FROM wp_user_bridge b WHERE b.user_id = u.id)  -- ghost gate
                AND (u.profile_visibility = 'public' OR u.id = :vuid OR :vadmin = 1) -- master switch
                AND (                                                               -- header ceiling
                     u.id = :vuid OR :vadmin = 1
                     OR COALESCE(ps.visibility, 'members') = 'public'
                     OR (:is_member = 1 AND COALESCE(ps.visibility, 'members') = 'members')
                    )
              ORDER BY cm.joined_at ASC, u.id ASC
              LIMIT :lim OFFSET :off"
        );
        $st->bindValue(':cid', $chapterId, PDO::PARAM_INT);
        $st->bindValue(':vuid', $viewerUserId, PDO::PARAM_INT);
        $st->bindValue(':vadmin', $isAdmin ? 1 : 0, PDO::PARAM_INT);
        $st->bindValue(':is_member', $viewerUserId !== 0 ? 1 : 0, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * JOIN — one tap, idempotent, no approval. A single INSERT, then a room-membership SYNC:
     * the chat room (CHAPTER-V2 ask 3) reuses group messaging via ENUMERATE (Ian 2026-07-14),
     * so a chapter member IS a message_recipients row in the room. ensureRoom() lazily creates
     * the room on the first join and backfills existing members.
     */
    public static function join(int $chapterId, string $userUuid): void
    {
        $st = Db::pg()->prepare(
            'INSERT INTO chapter_member (chapter_id, user_uuid) VALUES (:c, :u)
             ON CONFLICT (chapter_id, user_uuid) DO NOTHING'
        );
        $st->execute([':c' => $chapterId, ':u' => $userUuid]);

        $roomId = self::ensureRoom($chapterId);
        Db::pg()->prepare(
            'INSERT INTO message_recipients (thread_id, user_uuid) VALUES (:t, :u)
             ON CONFLICT (thread_id, user_uuid) DO NOTHING'
        )->execute([':t' => $roomId, ':u' => $userUuid]);
    }

    /**
     * LEAVE — one tap. Discussions, replies AND chat messages the member wrote STAY (leaving a
     * chapter is not retracting what you said). We only drop their room recipiency so the room
     * stops appearing in their messenger and stops counting them.
     */
    public static function leave(int $chapterId, string $userUuid): void
    {
        Db::pg()->prepare('DELETE FROM chapter_member WHERE chapter_id = :c AND user_uuid = :u')
            ->execute([':c' => $chapterId, ':u' => $userUuid]);

        $roomId = self::roomId($chapterId);
        if ($roomId !== null) {
            Db::pg()->prepare('DELETE FROM message_recipients WHERE thread_id = :t AND user_uuid = :u')
                ->execute([':t' => $roomId, ':u' => $userUuid]);
        }
    }

    // ── CHAT ROOM (CHAPTER-V2 ask 3) ─────────────────────────────────────────────────
    // ENUMERATE-v1 (Ian 2026-07-14): one group message_thread bound to the chapter via
    // message_threads.chapter_id; membership mirrors chapter_member into message_recipients so
    // the SHIPPED group messaging (thread view, send, poll, image attach) works unchanged. The
    // room is SYSTEM-owned (created_by NULL, is_group=true) — it has no human owner, and it must
    // NOT be person-managed (add/remove/leave via the member-manager) or it desyncs from
    // chapter_member. That guard lives in the messaging management endpoints (see the board note).

    /** The room's internal thread id, or null if no room exists yet. */
    public static function roomId(int $chapterId): ?int
    {
        $st = Db::pg()->prepare('SELECT id FROM message_threads WHERE chapter_id = :c');
        $st->execute([':c' => $chapterId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** The room's public thread uuid (what the messenger/pane address it by), or null. */
    public static function roomUuid(int $chapterId): ?string
    {
        $st = Db::pg()->prepare('SELECT uuid FROM message_threads WHERE chapter_id = :c');
        $st->execute([':c' => $chapterId]);
        $u = $st->fetchColumn();
        return $u === false ? null : (string) $u;
    }

    /**
     * For a MEMBER viewing the chapter: guarantee the room exists AND that this member is a
     * recipient, then return the room uuid. Self-heals members who joined before the room feature
     * (their old join() never synced) and anyone the creation-time backfill missed. Idempotent.
     */
    public static function ensureMemberRoom(int $chapterId, string $userUuid): string
    {
        $roomId = self::ensureRoom($chapterId);
        Db::pg()->prepare(
            'INSERT INTO message_recipients (thread_id, user_uuid) VALUES (:t, :u)
             ON CONFLICT (thread_id, user_uuid) DO NOTHING'
        )->execute([':t' => $roomId, ':u' => $userUuid]);
        return (string) self::roomUuid($chapterId);
    }

    /**
     * Ensure the chapter's room thread exists; return its id. Idempotent and race-safe via the
     * unique partial index on message_threads(chapter_id). On first creation it backfills every
     * current chapter member as a recipient (so an existing chapter's members all get the room).
     */
    public static function ensureRoom(int $chapterId): int
    {
        $existing = self::roomId($chapterId);
        if ($existing !== null) return $existing;

        $pg   = Db::pg();
        $name = $pg->prepare('SELECT name FROM chapter WHERE id = :c');
        $name->execute([':c' => $chapterId]);
        $subject = (string) ($name->fetchColumn() ?: 'Chapter');

        $pg->beginTransaction();
        try {
            $ins = $pg->prepare(
                "INSERT INTO message_threads (is_group, created_by, subject, chapter_id)
                 VALUES (true, NULL, :s, :c)
                 ON CONFLICT (chapter_id) WHERE chapter_id IS NOT NULL DO NOTHING
                 RETURNING id"
            );
            $ins->execute([':s' => $subject, ':c' => $chapterId]);
            $id = $ins->fetchColumn();

            if ($id === false) {
                $pg->commit();                       // lost the create race — someone else has it
                return (int) self::roomId($chapterId);
            }
            // Backfill current members as recipients.
            $pg->prepare(
                "INSERT INTO message_recipients (thread_id, user_uuid)
                 SELECT :t, cm.user_uuid FROM chapter_member cm WHERE cm.chapter_id = :c
                 ON CONFLICT (thread_id, user_uuid) DO NOTHING"
            )->execute([':t' => (int) $id, ':c' => $chapterId]);
            $pg->commit();
            return (int) $id;
        } catch (\Throwable $e) {
            $pg->rollBack();
            $again = self::roomId($chapterId);        // a concurrent txn may have created it
            if ($again !== null) return $again;
            throw $e;
        }
    }

    // ── DISCUSSIONS ──────────────────────────────────────────────────────────────────
    // The single chapter content surface (chapter_post). One row = one discussion topic; it
    // carries the durable announcement ("DMV meetup Saturday, here's the address") AND the
    // throwaway chatter ("anyone actually coming?"). Its replies are discovery.comments rows.

    /**
     * The discussion list, with author identity and reply counts.
     *
     * Reply counts come from discovery.comments in the OTHER database, so they are
     * fetched in ONE batched query by DiscoveryComments::countsFor() and stitched in
     * here — never N+1, and never a (impossible) cross-DB JOIN.
     */
    public static function posts(int $chapterId, int $limit = 20, int $offset = 0): array
    {
        $st = Db::pg()->prepare(
            "SELECT p.id, p.title, p.body, p.body_html, p.created_at, p.edited_at,
                    p.author_uuid, u.display_name AS author_name, u.slug AS author_slug,
                    u.avatar_url AS author_avatar
               FROM chapter_post p
               JOIN users u ON u.uuid = p.author_uuid
              WHERE p.chapter_id = :c AND p.deleted_at IS NULL
              ORDER BY p.created_at DESC
              LIMIT :lim OFFSET :off"
        );
        $st->bindValue(':c', $chapterId, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        if (!$rows) return [];

        $counts = DiscoveryComments::countsFor(array_map(static fn ($r) => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['comment_count'] = $counts[$r['id']] ?? 0;
        }
        return $rows;
    }

    public static function post(int $postId): ?array
    {
        $st = Db::pg()->prepare(
            "SELECT p.id, p.chapter_id, p.title, p.body, p.body_html, p.created_at, p.edited_at, p.author_uuid,
                    u.display_name AS author_name, u.slug AS author_slug, u.avatar_url AS author_avatar,
                    c.slug AS chapter_slug
               FROM chapter_post p
               JOIN users u   ON u.uuid = p.author_uuid
               JOIN chapter c ON c.id   = p.chapter_id
              WHERE p.id = :p AND p.deleted_at IS NULL"
        );
        $st->execute([':p' => $postId]);
        return $st->fetch() ?: null;
    }

    /**
     * Start a discussion. Caller MUST have already checked membership.
     *
     * CHAPTER-V2 ask 1: if $bodyHtml (Quill output) is given it is run through the allowlist
     * sanitizer and stored in body_html; the plain-text `body` is then DERIVED from that SAFE
     * html (never trusted from the client) for the list preview/title/search. With no html the
     * discussion is plain text exactly as before (body_html stays NULL).
     */
    public static function createPost(int $chapterId, string $authorUuid, ?string $title, string $body,
                                      ?string $bodyHtml = null): array
    {
        $title = $title !== null ? trim($title) : null;
        if ($title === '') $title = null;

        $html = null;
        if ($bodyHtml !== null && trim($bodyHtml) !== '') {
            $html = HtmlSanitize::chapterHtml($bodyHtml);
            $body = HtmlSanitize::toPlainText($html);   // plaintext from the SANITIZED html, not the client
        } else {
            $body = trim($body);
        }
        if ($body === '') return ['ok' => false, 'error' => 'empty_body'];
        if ($html === '') $html = null;                 // formatting-only input collapses to plain

        $st = Db::pg()->prepare(
            'INSERT INTO chapter_post (chapter_id, author_uuid, title, body, body_html)
             VALUES (:c, :a, :t, :b, :h) RETURNING id, created_at'
        );
        $st->execute([':c' => $chapterId, ':a' => $authorUuid, ':t' => $title, ':b' => $body, ':h' => $html]);
        $row = $st->fetch();

        return ['ok' => true, 'id' => (int) $row['id'], 'created_at' => $row['created_at']];
    }

    /**
     * Soft-delete a discussion. SOFT because the replies live in another database
     * and a hard delete would orphan them beyond this transaction's reach.
     * Author or admin only — that is OWNERSHIP, not a permission system.
     */
    public static function deletePost(int $postId, string $actorUuid, bool $isAdmin): bool
    {
        $sql = 'UPDATE chapter_post SET deleted_at = now() WHERE id = :p AND deleted_at IS NULL';
        $params = [':p' => $postId];
        if (!$isAdmin) {
            $sql .= ' AND author_uuid = :a';
            $params[':a'] = $actorUuid;
        }
        $st = Db::pg()->prepare($sql);
        $st->execute($params);
        return $st->rowCount() > 0;
    }

    // ── "YOU'RE NEAR DMV LOOTHS — JOIN?" ─────────────────────────────────────────────

    /**
     * The chapter whose catchment circle contains this member, nearest centre first.
     *
     * ⚠️ This runs on the member's OWN stored coordinates to decide what to suggest to
     * THEM about THEMSELVES. It never returns another member's position and never emits
     * a coordinate to the client — only a chapter. That is why it is allowed to read
     * users.lat/lng directly: the viewer is the subject, so there is nothing to clamp.
     * (`aud === 'owner'` in the Visibility model = full precision by definition.)
     */
    public static function suggestionFor(string $userUuid): ?array
    {
        $st = Db::pg()->prepare(
            'SELECT c.id, c.slug, c.name,
                    (point(c.center_lng, c.center_lat) <@> point(u.lng, u.lat)) AS distance_mi
               FROM chapter c, users u
              WHERE u.uuid = :u
                AND u.lat IS NOT NULL AND u.lng IS NOT NULL
                AND c.is_active = true
                AND (point(c.center_lng, c.center_lat) <@> point(u.lng, u.lat))
                    <= (c.radius_km / ' . self::KM_PER_MI . ')
                AND NOT EXISTS (SELECT 1 FROM chapter_member cm
                                 WHERE cm.chapter_id = c.id AND cm.user_uuid = u.uuid)
              ORDER BY distance_mi ASC
              LIMIT 1'
        );
        $st->execute([':u' => $userUuid]);
        $row = $st->fetch();
        if (!$row) return null;

        return [
            'id'   => (int) $row['id'],
            'slug' => $row['slug'],
            'name' => $row['name'],
        ];  // distance deliberately NOT returned — it is derived from the member's exact point.
    }
}
