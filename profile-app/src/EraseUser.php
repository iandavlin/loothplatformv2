<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;
use Throwable;

/**
 * Canonical profile-app identity teardown — the profile-app half of the
 * cross-store user-lifecycle erase (USER-LIFECYCLE-AUDIT.md, Phase 1).
 *
 * Driven by the internal endpoint POST /profile-api/v0/internal/erase-user,
 * which the poller-side UserLifecycle::teardown() fans out to. profile-app
 * holds *identity*, not authored content, so nuke and tombstone behave the
 * same here — a full identity + media erase either way; the mode is accepted
 * for logging/symmetry only.
 *
 * What gets deleted, keyed on wp_user_id → (user_id, uuid) via wp_user_bridge:
 *   - The `users` row → ON DELETE CASCADE clears the id-keyed identity tables
 *     (profiles, profile_sections/socials/instruments/skills/scenes/credentials/
 *     highlights/genres/services, email_aliases, wp_user_bridge, practice_members).
 *   - The uuid-keyed social tables do NOT cascade off users (they reference
 *     users(uuid) with NO ACTION), so they are deleted EXPLICITLY first inside
 *     the same transaction: connections (either side), messages (sender),
 *     message_recipients (participant), notifications (recipient OR actor).
 *   - practices.created_by (NO ACTION, would otherwise block the users delete)
 *     is NULLed — a practice is a shared band entity, not this user's identity,
 *     so it survives with no owner; the user's practice_members rows cascade.
 *   - On-disk media under /srv/profile-app-media/{avatars,banners,gallery,
 *     resumes}/<uuid>/ is removed recursively.
 *
 * Idempotent: an unknown/missing wp_user_id returns ok with all-zero counts.
 */
final class EraseUser
{
    private const MEDIA_ROOT  = '/srv/profile-app-media';
    private const MEDIA_DIRS  = ['avatars', 'banners', 'gallery', 'resumes'];

    /**
     * id-keyed tables that CASCADE off users(id) (FK ON DELETE CASCADE) —
     * counted as profile_rows, cleared automatically by the users delete.
     * NB: profile_credentials is NOT here — it has a polymorphic
     * (owner_type, owner_id) key with NO FK to users, so it neither cascades
     * nor blocks; it is counted + deleted explicitly (see below).
     */
    private const PROFILE_TABLES = [
        'profiles', 'profile_sections', 'profile_socials', 'profile_instruments',
        'profile_skills', 'profile_scenes', 'profile_highlights',
        'profile_genres', 'profile_services', 'email_aliases', 'wp_user_bridge',
        'practice_members',
    ];

    /**
     * @return array Contract response body (see the endpoint docblock).
     */
    public static function run(int $wpUserId, string $mode, bool $dryRun): array
    {
        $pg = Db::pg();

        // Resolve identity. Missing → idempotent all-zero success.
        $stmt = $pg->prepare('
            SELECT u.id, u.uuid
            FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
            WHERE b.wp_user_id = :w
        ');
        $stmt->execute([':w' => $wpUserId]);
        $row = $stmt->fetch();

        if (!$row) {
            return [
                'ok'         => true,
                'wp_user_id' => $wpUserId,
                'uuid'       => null,
                'mode'       => $mode,
                'deleted'    => ['users' => 0, 'profile_rows' => 0, 'social_rows' => 0, 'media_files' => 0],
            ];
        }

        $userId = (int) $row['id'];
        $uuid   = strtolower((string) $row['uuid']);

        $profileRows = self::countProfileRows($pg, $userId);
        $socialRows  = self::countSocialRows($pg, $uuid);
        $mediaFiles  = self::countMediaFiles($uuid);

        if ($dryRun) {
            return [
                'ok'         => true,
                'wp_user_id' => $wpUserId,
                'uuid'       => $uuid,
                'mode'       => $mode,
                'dry_run'    => true,
                'deleted'    => [
                    'users'        => 1,
                    'profile_rows' => $profileRows,
                    'social_rows'  => $socialRows,
                    'media_files'  => $mediaFiles,
                ],
            ];
        }

        // --- DB teardown (one transaction) ---
        $pg->beginTransaction();
        try {
            // uuid-keyed social footprint (NO-ACTION FKs) — delete before users.
            $pg->prepare('DELETE FROM connections WHERE requester_uuid = :u OR addressee_uuid = :u')
               ->execute([':u' => $uuid]);
            $pg->prepare('DELETE FROM messages WHERE sender_uuid = :u')
               ->execute([':u' => $uuid]);
            $pg->prepare('DELETE FROM message_recipients WHERE user_uuid = :u')
               ->execute([':u' => $uuid]);
            $pg->prepare('DELETE FROM notifications WHERE user_uuid = :u OR actor_uuid = :u')
               ->execute([':u' => $uuid]);

            // profile_credentials: polymorphic owner key, no FK → won't cascade.
            // Delete only this user's profile-owned credentials (leave practice-owned).
            $pg->prepare("DELETE FROM profile_credentials WHERE owner_type = 'profile' AND owner_id = :i")
               ->execute([':i' => $userId]);

            // practices the user created survive ownerless (shared band entity).
            $pg->prepare('UPDATE practices SET created_by = NULL WHERE created_by = :i')
               ->execute([':i' => $userId]);

            // The users row → CASCADE clears the id-keyed identity tables.
            $pg->prepare('DELETE FROM users WHERE id = :i')->execute([':i' => $userId]);

            $pg->commit();
        } catch (Throwable $e) {
            $pg->rollBack();
            throw $e;
        }

        // --- media teardown (best-effort; DB is the source of truth) ---
        $mediaDeleted = self::deleteMedia($uuid);

        // Drop any cached /whoami so mirrors stop resolving the dead identity.
        try {
            Cache::purgeWhoami($wpUserId);
        } catch (Throwable $e) {
            error_log('[erase-user] whoami purge failed: ' . $e->getMessage());
        }

        return [
            'ok'         => true,
            'wp_user_id' => $wpUserId,
            'uuid'       => $uuid,
            'mode'       => $mode,
            'deleted'    => [
                'users'        => 1,
                'profile_rows' => $profileRows,
                'social_rows'  => $socialRows,
                'media_files'  => $mediaDeleted,
            ],
        ];
    }

    private static function countProfileRows(PDO $pg, int $userId): int
    {
        $total = 0;
        foreach (self::PROFILE_TABLES as $t) {
            // Table names are a fixed in-code allowlist, never user input.
            $stmt = $pg->prepare("SELECT count(*) FROM {$t} WHERE user_id = :i");
            $stmt->execute([':i' => $userId]);
            $total += (int) $stmt->fetchColumn();
        }
        // profile_credentials — polymorphic owner key, counted explicitly.
        $stmt = $pg->prepare("SELECT count(*) FROM profile_credentials WHERE owner_type = 'profile' AND owner_id = :i");
        $stmt->execute([':i' => $userId]);
        $total += (int) $stmt->fetchColumn();
        return $total;
    }

    private static function countSocialRows(PDO $pg, string $uuid): int
    {
        $q = [
            'SELECT count(*) FROM connections WHERE requester_uuid = :u OR addressee_uuid = :u',
            'SELECT count(*) FROM messages WHERE sender_uuid = :u',
            'SELECT count(*) FROM message_recipients WHERE user_uuid = :u',
            'SELECT count(*) FROM notifications WHERE user_uuid = :u OR actor_uuid = :u',
        ];
        $total = 0;
        foreach ($q as $sql) {
            $stmt = $pg->prepare($sql);
            $stmt->execute([':u' => $uuid]);
            $total += (int) $stmt->fetchColumn();
        }
        return $total;
    }

    private static function countMediaFiles(string $uuid): int
    {
        $n = 0;
        foreach (self::MEDIA_DIRS as $d) {
            $n += self::countFilesUnder(self::MEDIA_ROOT . '/' . $d . '/' . $uuid);
        }
        return $n;
    }

    private static function countFilesUnder(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $n = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) $n++;
        }
        return $n;
    }

    /** Recursively remove every per-user media dir; returns files removed. */
    private static function deleteMedia(string $uuid): int
    {
        $removed = 0;
        foreach (self::MEDIA_DIRS as $d) {
            $dir = self::MEDIA_ROOT . '/' . $d . '/' . $uuid;
            if (!is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isDir()) {
                    @rmdir($f->getPathname());
                } elseif (@unlink($f->getPathname())) {
                    $removed++;
                }
            }
            @rmdir($dir);
        }
        return $removed;
    }
}
