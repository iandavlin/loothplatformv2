<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

require_once __DIR__ . '/Db.php';

/**
 * Slug — the member-controlled @username.
 *
 * One string does three jobs: it is the /u/<slug> profile URL, the handle every
 * @mention renders, and the name other members type to reach you. Until now it was
 * auto-minted at provision (mostly `patreon_<id>`) and nobody could change it.
 *
 * This class owns the rules. The API surface (api/v0/me-slug.php) is a thin shell over it.
 *
 * THE TWO INVARIANTS, and why:
 *
 *   1. A retired handle is NEVER re-issued to a different member. Old handles keep
 *      resolving (u.php 301s them to the current one), so if someone else could take
 *      your old handle they would inherit every historical link and every legacy mention
 *      that pointed at you. That is handle-theft with link-hijacking, and this data has
 *      already been bitten by it once (see sql/2026-07-12-username-slug-history.sql).
 *      Enforced by uq_slug_history_lower, not just by the check in isAvailable().
 *      You can always reclaim your OWN retired handle.
 *
 *   2. Mentions never store the handle. They store the member's uuid and look the handle
 *      up at render (see bb-mirror bb_mirror_resolve_mentions). So renaming is safe: it
 *      cannot retro-actively rewrite who a historical mention referred to.
 */
final class Slug
{
    /** Members may change their handle once every this-many days. */
    public const COOLDOWN_DAYS = 30;

    public const MIN_LEN = 3;
    public const MAX_LEN = 30;

    /**
     * Reserved handles. ENUMERATED from the live nginx route table (2026-07-12), not guessed:
     * every first path segment routed on the box, plus WP core paths, plus the words an
     * impersonator would want.
     *
     * Handles are namespaced under /u/, so none of these can actually collide with a route
     * TODAY. They are reserved for two reasons that do bite:
     *   - impersonation: a mention renders as `@name`. `@admin` / `@support` / `@looth` read
     *     as the platform speaking. That is the whole point of reserving them in a MENTIONS lane.
     *   - it keeps the door open to serving profiles at /<slug> later without a rename amnesty.
     */
    private const RESERVED = [
        // ── platform / impersonation ───────────────────────────────────────────────
        'admin', 'administrator', 'root', 'system', 'sysadmin', 'moderator', 'mod', 'staff',
        'support', 'help', 'official', 'security', 'abuse', 'postmaster', 'webmaster',
        'looth', 'loothgroup', 'loothtool', 'theloothgroup', 'team', 'noreply', 'no-reply',
        'info', 'contact', 'mail', 'billing', 'payments', 'sales', 'legal', 'privacy',
        'null', 'undefined', 'anonymous', 'deleted', 'guest', 'everyone', 'here', 'channel',
        'me', 'you', 'self', 'all', 'none', 'test',

        // ── route segments (enumerated from nginx on dev2, 2026-07-12) ─────────────
        'u', 'p', 'g', 'profile', 'profile-api', 'profile-media', 'profile-media-auth',
        'profile-media-internal', 'message-media', 'message-media-auth', 'message-media-internal',
        'members', 'directory', 'hub', 'forum', 'forums', 'forums-poc', 'all-forums-all-topics',
        'topic-tag', 'groups', 'bb-mirror-api', 'archive', 'archive-poc', 'archive-api',
        'article', 'video', 'videos', 'sponsor', 'sponsors', 'sponsor-page', 'sponsor-post',
        'sponsor-product', 'loothprint', 'loothcuts', 'shorty', 'event', 'events', 'document',
        'documents', 'calendar', 'about', 'stream', 'weekly', 'front-page', 'member-benefit',
        'useful_links', 'post-imgcap', 'post-type-videos', 'mobile-archive-page',
        'membership-pages', 'membership-guide', 'manage-subscription', 'connect-your-patreon',
        'lgjoin', 'lggift', 'lggift-buy', 'my-gifts', 'affiliate-earnings', 'request-refund',
        'join', 'welcome', 'claim', 'thumb', 'shop-feed', 'sitemap', 'robots', 'manifest',
        'lg-shared', 'lg-error', 'gatetest', 'mailpit', 'icons', 'v2',

        // ── WP core ────────────────────────────────────────────────────────────────
        'wp-admin', 'wp-login', 'wp-json', 'wp-content', 'wp-includes', 'wp-cron',
        'wp-signup', 'wp-activate', 'wp-config', 'xmlrpc', 'feed', 'author', 'category',
        'tag', 'page', 'comments', 'embed', 'trackback', 'login', 'logout', 'register',
        'dashboard', 'search', 'my-account', 'cart', 'checkout',
    ];

    /** Normalise for comparison. Handles are stored as typed but compared case-insensitively. */
    public static function norm(string $s): string
    {
        return strtolower(trim($s));
    }

    /**
     * Shape rules only — no DB. Returns null if OK, else a machine error code.
     *
     * Charset is deliberately a SUBSET of the nginx route charset (`^/u/([\w\-]+)/?$`):
     * a handle that the route cannot match would 404 on its own profile. It is also ASCII-only,
     * which is a free anti-homoglyph property — nobody can register a Cyrillic 'а' lookalike
     * of another member's handle.
     */
    public static function checkShape(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '')                                return 'slug_required';
        if (mb_strlen($s) < self::MIN_LEN)            return 'too_short';
        if (mb_strlen($s) > self::MAX_LEN)            return 'too_long';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $s))    return 'invalid_charset';

        // A handle of only digits would shadow another member: u.php falls back to
        // `WHERE id = :i` for a numeric slug, so `/u/42` means "member #42" today.
        if (preg_match('/^[0-9]+$/', $s))             return 'numeric_not_allowed';

        // Leading/trailing punctuation reads as a typo and invites near-duplicates.
        if (preg_match('/^[-_]|[-_]$/', $s))          return 'edge_punctuation';

        if (in_array(self::norm($s), self::RESERVED, true)) return 'reserved';

        return null;
    }

    /**
     * Full availability check for $userId taking $raw.
     * Returns ['ok'=>true] or ['ok'=>false,'error'=>code, ...detail].
     */
    public static function check(int $userId, string $raw): array
    {
        $shape = self::checkShape($raw);
        if ($shape !== null) return ['ok' => false, 'error' => $shape];

        $s   = trim($raw);
        $low = self::norm($s);
        $pg  = Db::pg();

        // Already yours (case may differ) — a no-op rename, always allowed.
        $st = $pg->prepare('SELECT lower(slug) FROM users WHERE id = :u');
        $st->execute([':u' => $userId]);
        $current = (string) ($st->fetchColumn() ?: '');
        if ($current === $low) return ['ok' => true, 'unchanged' => true];

        // Held by another member right now?
        $st = $pg->prepare('SELECT 1 FROM users WHERE lower(slug) = :s AND id <> :u');
        $st->execute([':s' => $low, ':u' => $userId]);
        if ($st->fetchColumn()) return ['ok' => false, 'error' => 'taken'];

        // Retired by ANOTHER member? Never re-issue (see class docblock).
        // Retired by THIS member? Fine — reclaiming your own old handle.
        $st = $pg->prepare('SELECT user_id FROM slug_history WHERE lower(slug) = :s');
        $st->execute([':s' => $low]);
        $histOwner = $st->fetchColumn();
        if ($histOwner !== false && (int) $histOwner !== $userId) {
            return ['ok' => false, 'error' => 'taken'];   // deliberately indistinguishable from live-taken
        }

        // Impersonation: is this the exact slugified display name of a DIFFERENT member?
        // Your own name is always yours to take.
        $st = $pg->prepare("
            SELECT display_name FROM users
            WHERE id <> :u AND archived_at IS NULL AND display_name IS NOT NULL
              AND regexp_replace(regexp_replace(lower(display_name), '[^a-z0-9]+', '-', 'g'), '^-+|-+$', '', 'g') = :s
            LIMIT 1
        ");
        $st->execute([':u' => $userId, ':s' => $low]);
        $clash = $st->fetchColumn();
        if ($clash !== false) {
            return ['ok' => false, 'error' => 'impersonation', 'member' => (string) $clash];
        }

        return ['ok' => true];
    }

    /**
     * Days remaining on the cooldown for $userId. 0 = may change now.
     * NULL slug_changed_at (never changed) => free, because every member is currently
     * sitting on an auto-minted handle they did not pick.
     */
    public static function cooldownDaysLeft(int $userId): int
    {
        $st = Db::pg()->prepare('
            SELECT GREATEST(0, CEIL(EXTRACT(EPOCH FROM (
                       slug_changed_at + (:d || \' days\')::interval - now()
                   )) / 86400))::int
            FROM users WHERE id = :u AND slug_changed_at IS NOT NULL
        ');
        $st->execute([':d' => self::COOLDOWN_DAYS, ':u' => $userId]);
        $left = $st->fetchColumn();
        return $left === false ? 0 : max(0, (int) $left);
    }

    /**
     * Change $userId's handle to $raw.
     *
     * Retires the old handle into slug_history (so /u/<old> keeps 301ing to them) and
     * takes the new one, in ONE transaction with the row locked — two members racing for
     * the same free handle must not both win. The unique indexes are the final backstop:
     * a lost race surfaces as 'taken', not as a 500.
     */
    public static function change(int $userId, string $raw): array
    {
        $check = self::check($userId, $raw);
        if (!$check['ok']) return $check;
        if (!empty($check['unchanged'])) {
            return ['ok' => true, 'slug' => trim($raw), 'unchanged' => true];
        }

        $left = self::cooldownDaysLeft($userId);
        if ($left > 0) {
            return ['ok' => false, 'error' => 'too_soon', 'days_left' => $left];
        }

        $s  = trim($raw);
        $pg = Db::pg();

        try {
            $pg->beginTransaction();

            // Lock the row: re-read the old handle under the lock so a concurrent change
            // can't make us retire a handle the member no longer holds.
            $st = $pg->prepare('SELECT slug FROM users WHERE id = :u FOR UPDATE');
            $st->execute([':u' => $userId]);
            $old = $st->fetchColumn();
            $old = ($old === false || $old === null || $old === '') ? null : (string) $old;

            // Reclaiming my own retired handle: drop it from history so it is never
            // simultaneously "live" and "retired".
            $pg->prepare('DELETE FROM slug_history WHERE user_id = :u AND lower(slug) = :s')
               ->execute([':u' => $userId, ':s' => self::norm($s)]);

            $pg->prepare('UPDATE users SET slug = :s, slug_changed_at = now() WHERE id = :u')
               ->execute([':s' => $s, ':u' => $userId]);

            // Retire the old handle. Never released to anyone else.
            if ($old !== null) {
                $pg->prepare('
                    INSERT INTO slug_history (user_id, slug) VALUES (:u, :s)
                    ON CONFLICT (lower(slug)) DO UPDATE
                        SET user_id = EXCLUDED.user_id, released_at = now()
                ')->execute([':u' => $userId, ':s' => $old]);
            }

            $pg->commit();
        } catch (\Throwable $e) {
            if ($pg->inTransaction()) $pg->rollBack();
            // 23505 = unique_violation: someone took it between check() and commit().
            if (($e->getCode() ?? '') === '23505') return ['ok' => false, 'error' => 'taken'];
            error_log('[slug] change failed for user_id=' . $userId . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'save_failed'];
        }

        return ['ok' => true, 'slug' => $s, 'previous' => $old];
    }

    /**
     * Resolve a handle that nobody currently holds to the member who used to hold it.
     * Powers the /u/<old-slug> 301. Returns the CURRENT slug, or null.
     */
    public static function currentSlugForRetired(string $raw): ?string
    {
        $st = Db::pg()->prepare('
            SELECT u.slug
            FROM slug_history h JOIN users u ON u.id = h.user_id
            WHERE lower(h.slug) = :s AND u.archived_at IS NULL AND u.slug IS NOT NULL
            ORDER BY h.released_at DESC
            LIMIT 1
        ');
        $st->execute([':s' => self::norm($raw)]);
        $slug = $st->fetchColumn();
        return $slug === false ? null : (string) $slug;
    }
}
