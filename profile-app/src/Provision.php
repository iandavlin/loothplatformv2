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
            $current = trim((string) $cur->fetchColumn());

            // A `patreon_<id>` is a PLACEHOLDER, not a handle the member owns — and this
            // guard could not tell the difference. It is neither NULL nor '', so the
            // member is treated as already-slugged and the placeholder is frozen for good.
            //
            // That is the whole recurrence bug. isPatreonJunk() already encodes "a
            // placeholder is not a handle" three lines down at :179, where it refuses one
            // as a slug SOURCE; it was simply never applied to the EXISTING slug. So the
            // name lands (ensure() fills it via COALESCE at :84 on the next poller sweep),
            // ensureSlug is called immediately after at :126 — and returns right here.
            //
            // Flag-gated and OFF by default, so this line behaves exactly as it always has
            // until LG_SLUG_HEAL_PLACEHOLDER is switched on.
            if ($current !== '') {
                if (LG_SLUG_HEAL_PLACEHOLDER && self::isPatreonJunk($current)) {
                    self::healPlaceholderSlug($userId, $current, $displayName);
                }
                return;   // already slugged
            }

            $base = '';
            foreach ([$nicename, $displayName, preg_replace('/\+.*$/', '', explode('@', $email)[0])] as $cand) {
                // Forward-fix (2026-07): a Patreon-import nicename is a numeric
                // placeholder ("patreon_100920474"), never a real handle. Skip it
                // so a new Patreon connection derives its slug from the member's
                // display_name instead of minting fresh junk. (The one-time
                // profile_app backfill already renamed the 1,600+ legacy junk
                // slugs; this stops the source from re-creating them.)
                if (self::isPatreonJunk((string) $cand)) continue;
                $base = self::slugify((string) $cand);
                if ($base !== '') break;
            }
            if ($base === '') $base = 'member';

            // Collision suffix: @steve, @steve2, @steve3 (Ian numbered ruling 7/25,
            // dash dropped — scheme pending his final confirm). A candidate is taken
            // if LIVE on another member OR parked in ANOTHER member's slug_history —
            // retired handles are never re-issued (link-hijack prevention; the old
            // users-only check left that hole open).
            $taken = $pg->prepare('SELECT 1 FROM users WHERE lower(slug) = lower(:s) AND id <> :self
                                   UNION ALL
                                   SELECT 1 FROM slug_history WHERE lower(slug) = lower(:s2) AND user_id <> :self2');
            $candidate = self::slugFit($base);
            for ($i = 2; $i <= 999; $i++) {
                $taken->execute([':s' => $candidate, ':self' => $userId, ':s2' => $candidate, ':self2' => $userId]);
                if (!$taken->fetchColumn()) break;
                // suffix rides INSIDE the 30-cap (slug-fit both halves together)
                $candidate = self::slugFit($base, Slug::MAX_LEN - strlen((string) $i)) . $i;
            }
            if ($i > 999) $candidate = self::slugFit($base, Slug::MAX_LEN - 6) . bin2hex(random_bytes(3));

            $pg->prepare("UPDATE users SET slug = :s WHERE id = :i AND (slug IS NULL OR slug = '')")
               ->execute([':s' => $candidate, ':i' => $userId]);
        } catch (\Throwable $e) {
            error_log('[provision] slug assignment skipped for user_id=' . $userId . ': ' . $e->getMessage());
        }
    }

    /**
     * Display string -> url-safe slug ('Mikelle Davlin' -> 'mikelle-davlin').
     *
     * Delegates to Slug::derive — the ONE derivation shared with the rename sync and
     * bin/backfill-slugs.php. It was a local preg_replace here, which silently
     * mangled every non-ASCII name it touched (`Åke` -> `ke`, `Ellström` ->
     * `ellstr-m`) and emptied CJK names entirely, dropping those members through to
     * the email fallback. Keeping a second copy of this logic is what lets a backfill
     * and a later rename disagree about the same member's URL.
     */
    private static function slugify(string $s): string
    {
        return Slug::derive($s);
    }

    /**
     * Word-boundary trim to the handle cap. Slug::MAX_LEN existed but neither
     * mint site enforced it (identity pass, keeper 02:25 spec 2026-07-26 — the
     * dev2 34-char strays). Delegates to Slug::fit for the same one-copy reason.
     */
    private static function slugFit(string $s, int $max = Slug::MAX_LEN): string
    {
        return Slug::fit($s, $max);
    }

    /**
     * True for a Patreon-import placeholder id — "patreon_100920474" (raw
     * nicename) or its slugified form "patreon-100920474". These are numeric
     * import artifacts, never a handle a member would choose, so both the
     * forward-fix (ensureSlug) and the name-change auto-sync treat them as
     * "no handle set" and derive a real one from the display_name.
     */
    private static function isPatreonJunk(string $s): bool
    {
        return (bool) preg_match('/^patreon[_-]?\d+$/i', trim($s));
    }

    /**
     * Upgrade a `patreon_<id>` PLACEHOLDER to a real handle once a usable human name has
     * arrived — the recurrence closer. Returns the new slug, or null when it declined.
     *
     * The WRITE is delegated to maybeSyncSlugFromName(): that is the one rename
     * implementation, and it already derives, dedupes against live handles AND every other
     * member's slug_history, parks the outgoing handle so **`/u/patreon_<id>` keeps 301ing
     * forever** (u.php step 4 → Slug::currentSlugForRetired), stamps slug_changed_at, and
     * does it all in one transaction. A second copy of that here is precisely the
     * split-brain SLUG-CONTRACT §3 exists to prevent.
     *
     * What this method contributes is the REFUSALS. An automatic heal is licensed to do
     * strictly LESS than a human ruling, never more — it runs unattended and hands out a
     * permanent public URL, so every case that is genuinely a judgement call must fall
     * through to the ruling queue rather than be decided by whichever poller sweep got
     * there first. It declines when:
     *
     *   - there is no honest slug in the name (non-Latin, too short, reserved) — R1/§3:
     *     we never latinize, and `deriveUsable('')` is the signal to surface it, not guess;
     *   - another live member carries the same display_name — that is the duplicate-account
     *     question (SLUG-DUPLICATE-ACCOUNTS.md), and minting a permanent handle plus a
     *     slug_history 301 for an account that may be about to merge is unpickable later;
     *   - the handle is already held, live or retired — R3 bans resolving a collision with
     *     a numeric suffix, and expansion needs Patreon and a human;
     *   - the handle is a BARE first name other members also carry — Ian, 2026-07-29:
     *     `/u/matt` goes to nobody. Measured live 2026-07-31: `matt` ×20, `scott` ×11.
     *     Allocating the site's scarcest handles by which Patreon import happened to carry
     *     a first name only is the one thing that ruling exists to refuse.
     *
     * Consequence, stated plainly: of the 146 members stranded on a placeholder today,
     * this heals **zero**. That is correct — they are held by ruling, not by the bug (see
     * docs/atlas/SLUG-PLACEHOLDER-RECURRENCE.md). This closes the recurrence going forward.
     *
     * Called only from ensureSlug(), only behind LG_SLUG_HEAL_PLACEHOLDER, and only when
     * the current slug is a placeholder — so it is inert until deliberately switched on.
     */
    private static function healPlaceholderSlug(int $userId, string $placeholder, ?string $displayName): ?string
    {
        $name = trim((string) $displayName);
        if ($name === '' || self::isPatreonJunk($name)) return null;

        $want = Slug::deriveUsable($name);
        if ($want === '') return null;

        $pg = Db::pg();

        // Duplicate-account hold (Ian 2026-07-29). Bridged + unarchived only: an archived
        // or unbridged row is not a member and must not veto a live member's handle.
        $dup = $pg->prepare('SELECT 1 FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
                             WHERE u.id <> :self AND u.archived_at IS NULL
                               AND lower(trim(u.display_name)) = lower(trim(:n)) LIMIT 1');
        $dup->execute([':self' => $userId, ':n' => $name]);
        if ($dup->fetchColumn()) return null;

        // Already held — live on someone else, or parked in their history (never re-issued).
        $taken = $pg->prepare('SELECT 1 FROM users WHERE lower(slug) = lower(:s) AND id <> :self
                               UNION ALL
                               SELECT 1 FROM slug_history WHERE lower(slug) = lower(:s2) AND user_id <> :self2');
        $taken->execute([':s' => $want, ':self' => $userId, ':s2' => $want, ':self2' => $userId]);
        if ($taken->fetchColumn()) return null;

        if (!str_contains($want, '-') && self::bareNameIsContested($userId, $want)) return null;

        // The candidate was free a moment ago, so maybeSyncSlugFromName lands on exactly
        // $want. If someone takes it in that window its dedup loop appends a digit rather
        // than failing — a single race-induced `-2` is a bounded, visible outcome and the
        // ruling queue can revisit it. Losing the write entirely would be worse.
        return self::maybeSyncSlugFromName($userId, $placeholder, $name);
    }

    /**
     * Does any OTHER live member's name derive to the same first token as this bare handle?
     *
     * Deliberately runs the real deriver over the member list instead of comparing raw
     * names in SQL: `Ake` and `Åke` are the same first token only after the Latin-ASCII
     * fold, and a SQL LIKE prefilter would silently UNDER-count the contest — which fails
     * in the unsafe direction, releasing a handle Ian ruled nobody gets.
     *
     * The same mistake at population scale is why bin/backfill-slugs.php's
     * --hold-contested-bare counted its own shortlist and quietly withheld ZERO on a
     * re-run (fixed 2026-07-31): a contest is a property of the membership, not of the
     * batch you happen to be looking at.
     *
     * Full scan, but only reached when a placeholder is healing AND the candidate is a
     * single token — rare, and this path already writes to the database.
     */
    private static function bareNameIsContested(int $userId, string $bare): bool
    {
        $st = Db::pg()->prepare('SELECT u.display_name FROM users u
                                 JOIN wp_user_bridge b ON b.user_id = u.id
                                 WHERE u.id <> :self AND u.archived_at IS NULL
                                   AND u.display_name IS NOT NULL');
        $st->execute([':self' => $userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $other) {
            $d = Slug::deriveUsable((string) $other);
            if ($d !== '' && explode('-', $d)[0] === $bare) return true;
        }
        return false;
    }

    /**
     * Move a member's public @handle (users.slug) to follow a display_name change.
     *
     * PRODUCT RULING (Ian, 2026-07-19, binding): the profile URL ALWAYS follows the
     * profile name — there are NO member-editable handles. So this sync is
     * UNCONDITIONAL: every rename re-derives the slug (IG-style). The earlier
     * "only while still the system default" heuristic guarded a hand-picked-handle
     * feature that will not exist; it is removed, and the coordinated slug_custom /
     * locked flag is resolved to "there is no such flag". Mentions stay uuid-anchored
     * (bb-mirror data-lg-uuid) so an unconditional rename never breaks a past mention.
     *
     * The one thing this never does is clobber ANOTHER member: the desired slug is
     * deduped against live slugs with a -2/-3 suffix, exactly like ensureSlug(). The
     * released handle is parked in slug_history so /u/<old-slug> 301-forwards
     * (u.php step 4 → Slug::currentSlugForRetired). Best-effort + fully guarded: a
     * slug is non-critical, so any failure only logs and the name change (which the
     * caller already committed) still stands.
     *
     * $oldDisplayName is retained for the caller's signature + logging; the
     * unconditional rule no longer branches on it.
     *
     * Returns the new slug if it changed, else null.
     */
    public static function maybeSyncSlugFromName(int $userId, string $oldDisplayName, string $newDisplayName): ?string
    {
        try {
            // A PLACEHOLDER NAME MUST NEVER OVERWRITE A REAL HANDLE.
            //
            // This is the one path in the system that can DESTROY a good slug rather than
            // merely fail to mint one, and it was unguarded. `patreon_188933584` is not
            // filtered out by the `=== ''` check below — it slugifies to
            // `patreon-188933584`, which passes checkShape() cleanly (not all-digits, not
            // reserved, legal charset). So a member whose display_name got set to a
            // placeholder would have their human handle overwritten AND parked in
            // slug_history, where it can never be re-issued to them automatically.
            //
            // ensureSlug() has always had this guard (:179) and the isPatreonJunk()
            // docblock already claimed BOTH mint sites applied it. They did not — only
            // ensureSlug did. Verified unexploited on live 2026-07-31 (zero slugs matching
            // `^patreon-[0-9]+$`, zero placeholder-shaped display_names), so this closes a
            // latent hole rather than repairing damage. Checked on the derived form too,
            // since derive() folds `patreon_1` and `patreon-1` to the same string.
            if (self::isPatreonJunk($newDisplayName)) return null;

            $newBase = self::slugFit(self::slugify($newDisplayName));
            if ($newBase === '') return null;   // new name has no slug-able chars; leave handle as-is
            if (self::isPatreonJunk($newBase))  return null;

            $pg  = Db::pg();
            $cur = $pg->prepare('SELECT slug FROM users WHERE id = :i');
            $cur->execute([':i' => $userId]);
            $currentSlug = trim((string) $cur->fetchColumn());

            // Already matches the new name (case-insensitive) — nothing to do.
            if ($currentSlug !== '' && strcasecmp($currentSlug, $newBase) === 0) return null;

            // Dedup against live slugs AND every other member's slug_history —
            // a retired handle is never re-issued (the prior users-only check let an
            // auto-rename inherit another member's old links; found + closed 7/25).
            // Suffix scheme: @steve, @steve2, @steve3 (Ian numbered ruling 7/25,
            // pending final confirm), mirroring ensureSlug().
            $taken = $pg->prepare('SELECT 1 FROM users WHERE lower(slug) = lower(:s) AND id <> :self
                                   UNION ALL
                                   SELECT 1 FROM slug_history WHERE lower(slug) = lower(:s2) AND user_id <> :self2');
            $candidate = $newBase;
            for ($i = 2; $i <= 999; $i++) {
                $taken->execute([':s' => $candidate, ':self' => $userId, ':s2' => $candidate, ':self2' => $userId]);
                if (!$taken->fetchColumn()) break;
                // suffix rides INSIDE the 30-cap (newBase is already slug-fit)
                $candidate = self::slugFit($newBase, Slug::MAX_LEN - strlen((string) $i)) . $i;
            }
            if ($i > 999) $candidate = self::slugFit($newBase, Slug::MAX_LEN - 6) . bin2hex(random_bytes(3));
            if ($currentSlug !== '' && strcasecmp($currentSlug, $candidate) === 0) return null;

            $pg->beginTransaction();
            try {
                // Reclaiming a handle this member previously held (rename A→B→A):
                // drop it from history so it is never simultaneously "live" and
                // "retired". Mirrors Slug::change(). Own-history only — another
                // member's parked handle was already excluded by the dedup above.
                $pg->prepare('DELETE FROM slug_history WHERE user_id = :u AND lower(slug) = lower(:s)')
                   ->execute([':u' => $userId, ':s' => $candidate]);
                // Park the released handle for the history-301. Unique on
                // lower(slug) across all history → ignore if already parked.
                if ($currentSlug !== '') {
                    $pg->prepare('INSERT INTO slug_history (user_id, slug) VALUES (:u, :s)
                                  ON CONFLICT (lower(slug)) DO NOTHING')
                       ->execute([':u' => $userId, ':s' => $currentSlug]);
                }
                $pg->prepare('UPDATE users SET slug = :s, slug_changed_at = now() WHERE id = :i')
                   ->execute([':s' => $candidate, ':i' => $userId]);
                $pg->commit();
            } catch (Throwable $e) {
                $pg->rollBack();
                throw $e;
            }
            return $candidate;
        } catch (\Throwable $e) {
            error_log('[provision] slug auto-sync skipped for user_id=' . $userId . ': ' . $e->getMessage());
            return null;
        }
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
