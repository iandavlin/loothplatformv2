<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;
use Throwable;

/**
 * Identity provisioning + reconciliation — the profile-app side of the
 * user-lifecycle CREATE / email-change contract (USER-LIFECYCLE-AUDIT.md
 * gaps G4 + G7; briefing-login-identity.md).
 *
 * Two operations, both keyed on **wp_user_id** (the stable WP account id),
 * both fully idempotent so the poller's blocking provision() can retry them
 * until they stick:
 *
 *   ensure()           — create-or-heal: a new WP user always ends up with a
 *                        users row + a wp_user_bridge row (+ email alias).
 *                        Safe to call repeatedly; self-heals a recycled
 *                        wp_user_id (WP reuses ids after a delete) by moving
 *                        the unique bridge to the current identity.
 *
 *   applyEmailChange() — on a WP email change, KEEP users.uuid stable (never
 *                        re-key identity off email — that is the G4 silent-
 *                        logout bug). Update primary_email + add the new email
 *                        as an alias; the stored uuid the JWT carries is
 *                        untouched, so the member stays authed as the same
 *                        identity. Falls back to ensure() if somehow unbridged.
 *
 * uuid is UUIDv5(namespace, normalized-email) ONLY at first create — it is the
 * seed, never recomputed thereafter. The stored users.uuid is the identity.
 */
final class Provision
{
    /**
     * Default fallback avatar for photo-less members (Ian 2026-06-14): the branded
     * "Optimum" emoji, shown across ALL surfaces (Hub cards, profile, directory)
     * instead of each surface's own empty-state (Hub silhouette / initials letters).
     * It's a STATIC local asset — NOT the old per-user Gravatar-placeholder guess
     * that rotted (1,300+ fake-gravatar rows the BB backfill had to repair); a fixed
     * image can't go stale. Real avatars overwrite it (me-avatar.php upload, the
     * BB-upload + gravatar backfills). Relative path so it resolves on dev/dev2/prod.
     */
    public const DEFAULT_AVATAR_URL = '/wp-content/uploads/2024/11/Optimum.png';

    /**
     * Idempotent create-or-heal for a WP user. Returns
     * ['user_id'=>int, 'uuid'=>string, 'created'=>bool].
     *
     * New rows get DEFAULT_AVATAR_URL (the Optimum fallback); existing rows keep
     * whatever avatar they have (ON CONFLICT never overwrites avatar_url).
     *
     * $nicename (optional) is the WP user_nicename the user-created hook may carry.
     * It's the preferred public-slug source — using it keeps a later xprofile
     * backfill a no-op. When absent we derive the slug from display_name/email
     * instead, so every provision still lands with a resolvable /u/<slug>.
     *
     * $autoClaim marks the profile claimed (so the onboard skips the "Start your
     * profile" interstitial and lands straight in the editor). ONLY the onboard
     * hook sets it — legacy/backfilled/admin-created rows leave it false and still
     * go through the interstitial.
     */
    public static function ensure(int $wpUserId, string $email, ?string $displayName, ?string $nicename = null, bool $autoClaim = false): array
    {
        if ($wpUserId < 1) {
            throw new \InvalidArgumentException('ensure: wp_user_id required');
        }
        $normalized = Identity::normalizeEmail($email);
        if ($normalized === '') {
            throw new \InvalidArgumentException('ensure: email required');
        }
        $uuid = Identity::computeUuid($email);

        $pg = Db::pg();
        $pg->beginTransaction();
        try {
            // Identity row, keyed on the stable uuid seed. Re-create is a no-op
            // beyond filling in a missing display_name.
            $stmt = $pg->prepare('
                INSERT INTO users (uuid, primary_email, billing_email, contact_email, display_name, avatar_url)
                VALUES (:uuid, :email, :email, :email, :name, :avatar)
                ON CONFLICT (uuid) DO UPDATE
                    SET display_name = COALESCE(users.display_name, EXCLUDED.display_name)
                RETURNING id, (xmax = 0) AS inserted
            ');
            $stmt->execute([':uuid' => $uuid, ':email' => $normalized, ':name' => $displayName, ':avatar' => self::DEFAULT_AVATAR_URL]);
            $row      = $stmt->fetch();
            $userId   = (int) $row['id'];
            $inserted = (bool) $row['inserted'];

            // Self-heal a recycled wp_user_id: the bridge's wp_user_id is UNIQUE,
            // so if it currently points at a DIFFERENT (stale) identity, free it
            // before we (re)bind it to this one. WP ids are unique among live
            // accounts, so a collision means the other row is stale.
            $pg->prepare('DELETE FROM wp_user_bridge WHERE wp_user_id = :wp AND user_id <> :uid')
               ->execute([':wp' => $wpUserId, ':uid' => $userId]);

            $pg->prepare('
                INSERT INTO wp_user_bridge (user_id, wp_user_id)
                VALUES (:uid, :wp)
                ON CONFLICT (user_id) DO UPDATE
                    SET wp_user_id = EXCLUDED.wp_user_id, synced_at = now()
            ')->execute([':uid' => $userId, ':wp' => $wpUserId]);

            $pg->prepare('
                INSERT INTO email_aliases (email_normalized, user_id, source)
                VALUES (:e, :u, :s)
                ON CONFLICT (email_normalized) DO NOTHING
            ')->execute([':e' => $normalized, ':u' => $userId, ':s' => 'wp']);

            $pg->commit();
        } catch (Throwable $e) {
            $pg->rollBack();
            throw $e;
        }

        // Mint a resolvable public slug for every provision. Historically the
        // INSERT left users.slug NULL — only the one-time xprofile backfill seeded
        // it (slug <- user_nicename) — so a live-provisioned member (every new
        // Patreon connection, every poller dedupe survivor) landed slug-less:
        // Whoami returns slug=null and the shared header degrades the "Profile"
        // button to legacy /members/ instead of /u/<slug>. Post-commit + guarded
        // so a slug-unique race only logs and is retried next provision — it can
        // never roll back or fail the identity create.
        self::ensureSlug($userId, $nicename, $displayName, $normalized);

        // Auto-claim onboard-hook provisions: provisioning already builds the
        // profile (now with a slug), so mark it claimed too and the new member
        // skips the "Start your profile" interstitial — they land straight in the
        // editor / on their /u/. Scoped to the hook path ($autoClaim); legacy,
        // backfilled and admin-created rows never set it and still see the
        // interstitial. Idempotent: Profile::claim() is ON CONFLICT DO NOTHING,
        // so an already-claimed profile is left untouched. Best-effort.
        if ($autoClaim) {
            try {
                Profile::claim($userId, 'onboard');
            } catch (\Throwable $e) {
                error_log('[provision] auto-claim skipped for user_id=' . $userId . ': ' . $e->getMessage());
            }
        }

        // Provision just wrote the slug (and maybe the claim). Invalidate any
        // /whoami cached slug-less earlier in THIS onboard request — otherwise the
        // post-onboard landing serves the stale slug=null payload and the shared
        // header degrades "My Profile" to /profile/edit until some other /me purge
        // fires (the "wrong on 1st click, right on 2nd" bug). Best-effort: a cache
        // miss just re-assembles fresh from Postgres.
        try { Cache::purgeWhoami($wpUserId); }
        catch (\Throwable $e) { error_log('[provision] whoami purge skipped for wp_user_id=' . $wpUserId . ': ' . $e->getMessage()); }

        return ['user_id' => $userId, 'uuid' => $uuid, 'created' => $inserted];
    }

    /**
     * Fill an EMPTY users.slug with a unique, URL-safe slug. Preference order:
     * WP nicename (matches the backfill scheme) -> display_name -> email
     * local-part -> "member"; deduped against users.slug with a numeric suffix.
     * Never overwrites an existing slug, so nicename-seeded slugs stand and a
     * re-provision is idempotent. Best-effort: a slug is non-critical, so failure
     * only logs — identity creation already committed and must not be undone.
     */
    private static function ensureSlug(int $userId, ?string $nicename, ?string $displayName, string $email): void
    {
        try {
            $pg  = Db::pg();
            $cur = $pg->prepare('SELECT slug FROM users WHERE id = :i');
            $cur->execute([':i' => $userId]);
            if (trim((string) $cur->fetchColumn()) !== '') return;   // already slugged

            $base = '';
            foreach ([$nicename, $displayName, explode('@', $email)[0]] as $cand) {
                $base = self::slugify((string) $cand);
                if ($base !== '') break;
            }
            if ($base === '') $base = 'member';

            $taken     = $pg->prepare('SELECT 1 FROM users WHERE slug = :s AND id <> :self');
            $candidate = $base;
            for ($i = 2; $i <= 999; $i++) {
                $taken->execute([':s' => $candidate, ':self' => $userId]);
                if (!$taken->fetchColumn()) break;
                $candidate = $base . '-' . $i;
            }
            if ($i > 999) $candidate = $base . '-' . bin2hex(random_bytes(3));

            $pg->prepare("UPDATE users SET slug = :s WHERE id = :i AND (slug IS NULL OR slug = '')")
               ->execute([':s' => $candidate, ':i' => $userId]);
        } catch (\Throwable $e) {
            error_log('[provision] slug assignment skipped for user_id=' . $userId . ': ' . $e->getMessage());
        }
    }

    /** Display string -> url-safe slug ('Mikelle Davlin' -> 'mikelle-davlin'). */
    private static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /**
     * Reconcile a WP email change WITHOUT re-keying identity. Returns
     * ['user_id'=>int, 'uuid'=>string, 'email_changed'=>bool, 'created'=>bool].
     *
     * `email_changed` = we updated an existing bridged identity in place.
     * `created`       = no bridge existed, so we self-healed via ensure()
     *                   (uuid is then seeded from the new email — first-create
     *                   semantics, not a re-key).
     */
    public static function applyEmailChange(int $wpUserId, string $email): array
    {
        if ($wpUserId < 1) {
            throw new \InvalidArgumentException('applyEmailChange: wp_user_id required');
        }
        $normalized = Identity::normalizeEmail($email);
        if ($normalized === '') {
            throw new \InvalidArgumentException('applyEmailChange: email required');
        }

        $pg = Db::pg();
        $stmt = $pg->prepare('
            SELECT u.id, u.uuid
            FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
            WHERE b.wp_user_id = :w
        ');
        $stmt->execute([':w' => $wpUserId]);
        $found = $stmt->fetch();

        if (!$found) {
            // Unbridged — heal by creating. uuid seeds from the new email.
            $res = self::ensure($wpUserId, $email, null);
            return [
                'user_id'       => $res['user_id'],
                'uuid'          => $res['uuid'],
                'email_changed' => false,
                'created'       => $res['created'],
            ];
        }

        $userId = (int) $found['id'];
        $uuid   = strtolower((string) $found['uuid']);   // STABLE — never reassigned

        // primary_email is UNIQUE + NOT NULL: if another (stale) row already
        // holds this email we can't move it here. Identity (uuid) is what
        // matters — keep our primary_email as-is, still record the alias, and
        // flag the conflict for the coordinator rather than failing the change.
        $owner = $pg->prepare('SELECT id FROM users WHERE primary_email = :e');
        $owner->execute([':e' => $normalized]);
        $emailOwner = $owner->fetchColumn();
        $emailTaken = ($emailOwner !== false && (int) $emailOwner !== $userId);

        $pg->beginTransaction();
        try {
            if (!$emailTaken) {
                $pg->prepare('UPDATE users SET primary_email = :e WHERE id = :uid')
                   ->execute([':e' => $normalized, ':uid' => $userId]);
            }

            // Add the new email as an alias (keep the old alias as history).
            // Re-point the alias to us if it lingered on a stale identity.
            $pg->prepare('
                INSERT INTO email_aliases (email_normalized, user_id, source)
                VALUES (:e, :u, :s)
                ON CONFLICT (email_normalized) DO UPDATE SET user_id = EXCLUDED.user_id
            ')->execute([':e' => $normalized, ':u' => $userId, ':s' => 'wp']);

            $pg->commit();
        } catch (Throwable $e) {
            $pg->rollBack();
            throw $e;
        }

        if ($emailTaken) {
            error_log("[provision] email-change conflict: '$normalized' held by user_id=$emailOwner, "
                . "kept primary_email on live user_id=$userId (uuid stable); alias re-pointed");
        }

        return [
            'user_id'        => $userId,
            'uuid'           => $uuid,
            'email_changed'  => true,
            'created'        => false,
            'email_conflict' => $emailTaken,
        ];
    }
}
