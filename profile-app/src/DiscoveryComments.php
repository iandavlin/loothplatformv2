<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;

/**
 * DISCOVERY COMMENTS — the cross-DATABASE adapter that lets a native chapter announcement
 * REUSE the platform's ONE comments store instead of standing up a second one.
 *
 * ── WHY THIS FILE IS SHAPED LIKE THIS (the one real seam in the native build) ──────────
 *
 * The brief: "REUSE an existing store — discovery.comments is already owned by archive-poc.
 * Do NOT create a second comments store. Verify and justify."  Verified — and it holds:
 *
 *   ✓ The STORE is genuinely WordPress-free. Comments are addressed polymorphically by
 *     (post_type text, item_id bigint) with NO foreign key to wp_posts, to content_item, or
 *     to anything at all. It already carries a `user_uuid` column — the same shared identity
 *     key profile_app uses. Inserting ('chapter_post', <chapter_post.id>) is legal today and
 *     collides with nothing: post_type namespaces the id space.
 *
 *   ✓ The WordPress assumption lives in archive-poc's WRITE ENDPOINT, not in the store:
 *     archive-poc/api/v0/comment-post.php boots WP and calls get_post($itemId), which would
 *     reject a native row with `bad_target`. It boots WP because the posting gate must read
 *     the WP login cookie (an unbridged member has a valid WP cookie but reads anon on
 *     /whoami — see feedback_gate_posting_on_wp_cookie_not_whoami).
 *
 *     That reason does NOT apply to us, and this is the crux: a chapter member is BY
 *     CONSTRUCTION a profile_app users row (chapter_member.user_uuid is an FK to users.uuid),
 *     so anyone who can join a chapter necessarily has a looth_id JWT. Auth::requireUser() is
 *     therefore a STRICTLY STRONGER gate here than the WP cookie check, not a weaker one.
 *     So we write natively and archive-poc's PHP is untouched by this lane.
 *
 *   ✗ THE COST, stated plainly: discovery.comments lives in the `looth` DATABASE.
 *     profile_app is a SEPARATE DATABASE in the same cluster. postgres_fdw and dblink are
 *     BOTH ABSENT (verified in both DBs). A SQL JOIN from chapter_post to its comments is
 *     therefore IMPOSSIBLE, and this class must open a SECOND PDO CONNECTION.
 *
 *     That is not a hack — it is the established pattern, in the other direction:
 *     archive-poc/web/sitemap.php:91-94 opens a second PDO to profile_app, backed by the
 *     cross-DB grants in tools/cut/sitemap-grants.sql. Our mirror-image grants ship as
 *     profile-app/sql/2026-07-12-chapters-comments-grants.looth.sql.
 *
 *     The alternative — a `chapter_comment` table inside profile_app — was REJECTED. It buys
 *     one saved connection at the price of two comment stores, two reaction stores and two
 *      moderation surfaces, forever. The brief is right to forbid it.
 *
 * CONSEQUENCE FOR CALLERS: never try to JOIN. Fetch the posts from profile_app, then batch
 * their counts/threads through this class in ONE query. No N+1.
 */
final class DiscoveryComments
{
    /** Our namespace in the shared store's polymorphic key. */
    public const POST_TYPE = 'chapter_post';

    private static ?PDO $looth = null;

    /**
     * The second connection. Same unix socket, same peer auth (PG role `profile-app`, from the
     * FPM pool user) — only the dbname differs. The grants migration is what makes this work;
     * without it PDO throws at connect time with a permission error on CONNECT.
     */
    private static function looth(): PDO
    {
        if (self::$looth === null) {
            $shared = function_exists('lg_env') ? lg_env() : [];
            $db     = $shared['pg_db'] ?? 'looth';        // LG_PG_DB in /etc/looth/env
            self::$looth = new PDO(
                'pgsql:host=/var/run/postgresql;dbname=' . $db,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$looth;
    }

    /**
     * Comment counts for a batch of announcements — ONE query, keyed by chapter_post id.
     * Served by the existing idx_comments_item (post_type, item_id, created_at).
     * Returns [postId => count]; missing key means zero.
     */
    public static function countsFor(array $postIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $postIds)));
        if (!$ids) return [];

        // PDO cannot bind an array; build the placeholder list. (Postgres's = ANY(:x) would
        // need an array type PDO won't send natively without emulation.)
        $ph = [];
        $params = [':pt' => self::POST_TYPE];
        foreach ($ids as $i => $id) {
            $ph[] = ":i$i";
            $params[":i$i"] = $id;
        }

        $st = self::looth()->prepare(
            'SELECT item_id, count(*) AS n
               FROM discovery.comments
              WHERE post_type = :pt AND status = \'approved\'
                AND item_id IN (' . implode(',', $ph) . ')
              GROUP BY item_id'
        );
        $st->execute($params);

        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['item_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * The comment thread for ONE announcement, oldest first (matches the archive-poc modal's
     * ordering, so the two surfaces read the same way).
     *
     * Author identity is NOT joined — it cannot be, users lives in the other database. The
     * caller stitches it from profile_app; see Chapters/g.php. `author_name` is the
     * store's own denormalized fallback, used only when the uuid resolves to nothing.
     */
    public static function thread(int $postId): array
    {
        $st = self::looth()->prepare(
            'SELECT id, parent_id, user_uuid, author_name, body, created_at, edited_at
               FROM discovery.comments
              WHERE post_type = :pt AND item_id = :i AND status = \'approved\'
              ORDER BY created_at ASC, id ASC'
        );
        $st->execute([':pt' => self::POST_TYPE, ':i' => $postId]);
        return $st->fetchAll() ?: [];
    }

    /**
     * Add a comment to an announcement.
     *
     * The caller MUST have already resolved the author via Auth::requireUser() and checked
     * chapter membership — this class does not authorize, it stores. author_wp_id is left
     * NULL on purpose: this is the native path and there is no WordPress user in it. That
     * NULL is, in a small way, the whole point of the lane.
     */
    public static function add(int $postId, string $userUuid, string $authorName, string $body, ?int $parentId = null): array
    {
        $body = trim($body);
        if ($body === '') return ['ok' => false, 'error' => 'empty_body'];

        // A reply must belong to the SAME announcement, or a crafted parent_id could graft a
        // chapter reply onto some other item's comment. (The archive-poc insert helper enforces
        // the same rule; we re-enforce it rather than trusting the client.)
        if ($parentId !== null) {
            $chk = self::looth()->prepare(
                'SELECT 1 FROM discovery.comments WHERE id = :id AND post_type = :pt AND item_id = :i'
            );
            $chk->execute([':id' => $parentId, ':pt' => self::POST_TYPE, ':i' => $postId]);
            if (!$chk->fetchColumn()) return ['ok' => false, 'error' => 'bad_parent'];
        }

        $st = self::looth()->prepare(
            'INSERT INTO discovery.comments (post_type, item_id, parent_id, user_uuid, author_name, body)
             VALUES (:pt, :i, :parent, :u, :name, :b)
             RETURNING id, created_at'
        );
        $st->execute([
            ':pt'     => self::POST_TYPE,
            ':i'      => $postId,
            ':parent' => $parentId,
            ':u'      => $userUuid,
            ':name'   => $authorName,
            ':b'      => $body,
        ]);
        $row = $st->fetch();

        return ['ok' => true, 'id' => (int) $row['id'], 'created_at' => $row['created_at']];
    }

    /**
     * Remove a comment. Author or admin only (ownership, not a permission system).
     * Soft — sets status, matching how the archive-poc modal treats removal, so a moderator
     * can still see what was said.
     */
    public static function remove(int $commentId, string $actorUuid, bool $isAdmin): bool
    {
        $sql = "UPDATE discovery.comments SET status = 'deleted'
                 WHERE id = :id AND post_type = :pt AND status = 'approved'";
        $params = [':id' => $commentId, ':pt' => self::POST_TYPE];
        if (!$isAdmin) {
            $sql .= ' AND user_uuid = :u';
            $params[':u'] = $actorUuid;
        }
        $st = self::looth()->prepare($sql);
        $st->execute($params);
        return $st->rowCount() > 0;
    }
}
