<?php
/**
 * lg-profile-bio — ONE resolver for "what does this member's PROFILE say about
 * them", for every surface that used to reach into WP usermeta `author_about`.
 *
 * Ian, 2026-08-16: "What ever it is, it should just be flipped to the about from
 * the profile." The phantom bio he reported was `author_about` on his admin
 * account — a member-editable WP field that nothing keeps in step with the
 * profile, which is why it drifted to naming a SoCal chapter while his profile
 * says Ridgefield Park NJ.
 *
 * ── THE RULES ARE GATE 58'S, NOT NEW ONES ───────────────────────────────────
 * A surface may only repeat what the member's own profile PUBLISHES:
 *   - About  : profile_sections key='about', visibility='public', AND 'about' in
 *              users.profile_layout. A public About row is NOT enough — the
 *              profile only RENDERS about when it is in the resolved layout, and
 *              Block::defaultLayout() never includes it for anyone who has never
 *              customised theirs. Stored, public, and invisible is still invisible.
 *   - Glance : users.at_a_glance, ONLY when the header block is public. Its
 *              ceiling defaults to 'members' (Block::HEADER_DEFAULT) when the
 *              member has no header row, and an anonymous visitor never sees it.
 *   - else   : business_name, which the profile publishes anyway.
 *
 * ── READS THE TRUTH, DOES NOT MIRROR IT ─────────────────────────────────────
 * This queries profile_app directly rather than copying the text into usermeta.
 * A mirror is exactly the disease being cured: author_about WAS a copy, and it
 * drifted. One source, read at render time, cannot drift. The connection is
 * static and the answer is memoised per request, the same pattern the featured
 * card already uses in archive-poc/web/index.php.
 *
 * ── NO WORDPRESS ────────────────────────────────────────────────────────────
 * Deliberately uses no WP functions: the same blocks are rendered by
 * archive-poc/standalone/engine with no WP loaded at all, and a helper that
 * only works on one of the two renderers would put them back out of step.
 *
 * ⚠️ FLAG-INERT BY RETURN VALUE. With the flag off this returns NULL, meaning
 * "I have no opinion" — every caller keeps its existing behaviour untouched.
 * It never returns '' to mean off, because '' is a real answer (a member with
 * nothing public to say) and conflating the two would blank author boxes the
 * moment the file landed.
 */

if (!function_exists('lg_profile_bio_enabled')) {
    function lg_profile_bio_enabled(): bool
    {
        static $on = null;
        if ($on !== null) return $on;
        foreach ([getenv('LG_PROFILE_BIO'), $_SERVER['LG_PROFILE_BIO'] ?? false] as $o) {
            if ($o !== false && $o !== '') return $on = ($o === '1' || $o === 'true');
        }
        $path = __DIR__ . '/../config/profile-bio.php';
        if (!is_readable($path)) {
            return $on = false;                    // fail closed: no config => off
        }
        $cfg = require $path;
        return $on = (is_array($cfg) && ($cfg['enabled'] ?? false) === true);
    }
}

if (!function_exists('lg_profile_bio')) {
    /**
     * @return array{bio:string,glance:string,business:string}|null
     *         NULL when the flag is off or the member has no profile row.
     */
    function lg_profile_bio(int $wpUserId): ?array
    {
        if ($wpUserId < 1 || !lg_profile_bio_enabled()) return null;

        static $pdo = null, $memo = [];
        if (array_key_exists($wpUserId, $memo)) return $memo[$wpUserId];

        try {
            if ($pdo === null) {
                $pdo = new PDO('pgsql:host=/var/run/postgresql;dbname=profile_app', null, null,
                               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
            $st = $pdo->prepare(
                "SELECT
                   coalesce((SELECT trim(ps.data->>'text') FROM profile_sections ps
                              WHERE ps.user_id = u.id AND ps.key = 'about'
                                AND ps.visibility = 'public'
                                AND u.profile_layout @> '[\"about\"]'::jsonb), '') AS about,
                   CASE WHEN coalesce((SELECT h.visibility FROM profile_sections h
                                        WHERE h.user_id = u.id AND h.key = 'header'), 'members') = 'public'
                        THEN coalesce(trim(u.at_a_glance), '') ELSE '' END          AS glance,
                   coalesce(trim(u.business_name), '')                             AS business
                 FROM users u
                 JOIN wp_user_bridge b ON b.user_id = u.id
                WHERE b.wp_user_id = :w AND u.profile_visibility = 'public'"
            );
            $st->execute([':w' => $wpUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // An unreachable profile store must not blank an author box: fall
            // back to the caller's own behaviour, exactly like the flag being off.
            error_log('[lg-profile-bio] ' . $e->getMessage());
            return $memo[$wpUserId] = null;
        }
        if (!$row) return $memo[$wpUserId] = null;

        return $memo[$wpUserId] = [
            'bio'      => (string) $row['about'],
            'glance'   => (string) $row['glance'],
            'business' => (string) $row['business'],
        ];
    }
}
