<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use Firebase\JWT\JWT;

/**
 * looth_id JWT minter (the signing side — counterpart to Auth's verify side).
 *
 * profile-app is the identity authority and now the sole holder of the RS256
 * PRIVATE key; WP no longer signs in-process — it calls
 * POST /profile-api/v0/internal/mint-token and gets a token back. See
 * docs/design-shim-replacement.md (§0c claim shape, ratified).
 *
 * Claim shape (STRANGLER-COORDINATION §0c): identity + STABLE display only.
 *   - iss, sub (user_uuid), wp_user_id        ← identity
 *   - display_name, avatar_url, slug          ← stable display (rarely change)
 *   - iat, exp
 * Deliberately NOT in the token: `tier`/`provenance` (volatile — lives in the
 * lg_tier cookie; a 30-day token would lie) and `capabilities` (reconciled via
 * /whoami only when a sensitive gate is hit).
 *
 * `sub` is the STORED users.uuid (not recomputed from email), so an email
 * change in WP can't drift the token's subject away from the stored identity —
 * a deliberate improvement over the WP-side minter's email-recompute.
 */
final class Mint
{
    public const PRIVATE_KEY = '/etc/looth/jwt-private.pem';
    public const TTL_SECONDS = 30 * 24 * 60 * 60;   // 30 days — matches looth_id cookie

    private static ?string $privateKey = null;

    /**
     * Mint a looth_id JWT for a WP user.
     *
     * @return array{token:string,exp:int}|null  null when no profile-app
     *         identity is bridged to this wp_user_id (caller → 404). Throws on
     *         a signing failure (unreadable key, encode error) so the caller
     *         can return 502 and the WP login hook degrades gracefully.
     */
    /**
     * Canonical `sub` for a WP user: the STORED users.uuid reached via
     * wp_user_bridge. Deliberately email-INDEPENDENT — it never recomputes
     * UUIDv5(email), so a WP email change cannot drift the token subject away
     * from the stored identity (the G4 silent-logout bug). Returns null when no
     * profile-app identity is bridged to this wp_user_id.
     *
     * THE single source of truth for the token subject. The WP-side minter
     * (profile-auth.php, Decision 2 option (b)) MUST resolve `sub` to this same
     * value — never to looth_auth_compute_uuid($email) — or a member who
     * changed their email gets a token whose sub ≠ stored uuid and renders
     * silently anonymous on their own profile. Pinned by bin/test-identity.php.
     */
    public static function subForWpUserId(int $wpUserId): ?string
    {
        if ($wpUserId < 1) return null;
        $stmt = Db::pg()->prepare('
            SELECT u.uuid
            FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
            WHERE b.wp_user_id = :w
        ');
        $stmt->execute([':w' => $wpUserId]);
        $uuid = $stmt->fetchColumn();
        return $uuid === false ? null : strtolower((string)$uuid);
    }

    public static function mintForWpUserId(int $wpUserId): ?array
    {
        if ($wpUserId < 1) return null;

        // Identity-only read. Intentionally NOT Whoami::buildForWpUserId():
        // that resolves tier via a poller call we don't want in the token and
        // would couple minting to poller availability (mint must work even
        // when the poller is down). Same SELECT, no tier hop.
        $stmt = Db::pg()->prepare('
            SELECT u.uuid, u.slug, u.display_name, u.avatar_url
            FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
            WHERE b.wp_user_id = :w
        ');
        $stmt->execute([':w' => $wpUserId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $now = time();
        $exp = $now + self::TTL_SECONDS;
        $payload = [
            'iss'          => 'https://' . LG_PROFILE_APP_HOST,
            'sub'          => strtolower((string)$row['uuid']),
            'wp_user_id'   => $wpUserId,
            'display_name' => $row['display_name'] ?? null,
            'avatar_url'   => $row['avatar_url'] ?? null,
            'slug'         => $row['slug'] ?: null,
            'iat'          => $now,
            'exp'          => $exp,
        ];

        $token = JWT::encode($payload, self::privateKey(), 'RS256');
        return ['token' => $token, 'exp' => $exp];
    }

    private static function privateKey(): string
    {
        if (self::$privateKey === null) {
            $pk = @file_get_contents(self::PRIVATE_KEY);
            if ($pk === false || $pk === '') {
                throw new \RuntimeException('mint: private key unreadable at ' . self::PRIVATE_KEY);
            }
            self::$privateKey = $pk;
        }
        return self::$privateKey;
    }
}
