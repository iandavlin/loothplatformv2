<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * /whoami contract assembler. Identity from postgres. Tier from poller's
 * GET /wp-json/looth-internal/v1/user-context/{wp_user_id} (when available)
 * or stub `public` + `tier_unavailable: true` until poller endpoint lands.
 *
 * 30s Redis cache via Cache::getWhoami / setWhoami / purgeWhoami.
 *
 * Two write triggers invalidate:
 *   - Arbiter (WP-side) calls POST /profile-api/v0/internal/purge-whoami
 *     after any tier-changing write. Fan-out is via the
 *     `looth_tier_changed` WP action — see STRANGLER-COORDINATION.md §2.
 *   - profile-app calls Cache::purgeWhoami($wpUserId) from any /me/*
 *     handler that mutates fields surfaced in /whoami
 *     (display_name, slug, avatar_url, business_name).
 *
 * Contract shape (STRANGLER-COORDINATION.md §2):
 *
 * Anon:
 *   { "authenticated": false, "tier": "public" }
 *
 * Authed:
 *   { "authenticated": true,
 *     "user_uuid": "f20a...",
 *     "wp_user_id": 1,
 *     "slug": "iandavlin",
 *     "display_name": "Ian B Davlin",
 *     "avatar_url": "https://...",
 *     "tier": "pro",
 *     "provenance": "paid",
 *     "tier_unavailable": false,    // present only if true (stub mode)
 *     "capabilities": { ... },
 *     "cache": { "etag": "w/\"...\"", "max_age": 30 } }
 */
final class Whoami
{
    public const POLLER_TIER_URL_BASE  = 'https://127.0.0.1/wp-json/looth-internal/v1/user-context/';
    public const SECRET_FILE           = '/etc/lg-internal-secret';
    public const INTERNAL_AUTH_HEADER  = 'X-LG-Internal-Auth';
    public const WP_USER_ID_HEADER     = 'X-LG-WP-User-Id';

    /**
     * Resolve via a trusted WP-session bridge header. Used by the WP shim
     * to identify users who are logged into WP but lack a `looth_id` JWT
     * cookie. Caller must present BOTH:
     *   - X-LG-Internal-Auth: <shared secret>   (verified with hash_equals)
     *   - X-LG-WP-User-Id: <int>                (mapped to profile-app user)
     *
     * Returns null when the trusted-header path doesn't apply (missing or
     * bad auth, no wp_user_id). Caller falls back to JWT/anon resolve().
     */
    public static function resolveFromTrustedHeader(): ?array
    {
        $hdrName = 'HTTP_' . strtoupper(str_replace('-', '_', self::WP_USER_ID_HEADER));
        $wpRaw   = $_SERVER[$hdrName] ?? '';
        if ($wpRaw === '' || !ctype_digit((string)$wpRaw)) return null;
        if (!self::clientIsLoopback()) return null;   // trusted-header bypass is loopback-only
        if (!self::verifyInternalAuth()) return null;
        $wpUserId = (int) $wpRaw;
        if ($wpUserId < 1) return null;
        return self::buildForWpUserId($wpUserId);
    }

    /**
     * Resolve current viewer's /whoami payload. Tries cache first;
     * cache-miss assembles from postgres + poller.
     */
    public static function resolve(): array
    {
        $claims = Auth::claims();
        if (!$claims || empty($claims['sub'])) {
            return self::anonShape();
        }
        $wpUserId = isset($claims['wp_user_id']) ? (int)$claims['wp_user_id'] : 0;
        if ($wpUserId < 1) return self::anonShape();

        $cached = Cache::getWhoami($wpUserId);
        if ($cached !== null) return $cached;

        $payload = self::buildAuthed($wpUserId, (string)$claims['sub']);
        Cache::setWhoami($wpUserId, $payload);
        return $payload;
    }

    /** Build /whoami for a specific wp_user_id (used by WP shim too). */
    public static function buildForWpUserId(int $wpUserId): array
    {
        if ($wpUserId < 1) return self::anonShape();
        $cached = Cache::getWhoami($wpUserId);
        if ($cached !== null) return $cached;

        $stmt = Db::pg()->prepare('
            SELECT u.uuid, u.slug, u.display_name, u.avatar_url, u.discussion_visibility
            FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
            WHERE b.wp_user_id = :w
        ');
        $stmt->execute([':w' => $wpUserId]);
        $row = $stmt->fetch();
        if (!$row) return self::anonShape();

        $payload = self::shapeFromRow($wpUserId, $row);
        Cache::setWhoami($wpUserId, $payload);
        return $payload;
    }

    private static function buildAuthed(int $wpUserId, string $userUuid): array
    {
        $stmt = Db::pg()->prepare('
            SELECT u.uuid, u.slug, u.display_name, u.avatar_url, u.discussion_visibility
            FROM users u WHERE u.uuid = :u
        ');
        $stmt->execute([':u' => strtolower($userUuid)]);
        $row = $stmt->fetch();
        if (!$row) return self::anonShape();

        return self::shapeFromRow($wpUserId, $row);
    }

    private static function shapeFromRow(int $wpUserId, array $row): array
    {
        $tier        = 'public';
        $provenance  = 'new';
        $unavailable = true;
        $pollerCaps  = [];

        $polled = self::fetchPollerTier($wpUserId);
        if ($polled !== null) {
            $tier        = $polled['tier']       ?? 'public';
            $provenance  = $polled['provenance'] ?? 'new';
            $pollerCaps  = is_array($polled['capabilities'] ?? null) ? $polled['capabilities'] : [];
            $unavailable = false;
        }

        $payload = [
            'authenticated' => true,
            'user_uuid'     => $row['uuid'],
            'wp_user_id'    => $wpUserId,
            'slug'          => $row['slug'] ?: null,
            'display_name'  => $row['display_name'] ?? null,
            'avatar_url'    => $row['avatar_url'] ?? null,
            // Discussion-author mask preference (public|member). Default member (Ian 6/7).
            // The Hub reads this (via forums.person sync) to mask member-only authors
            // from logged-out viewers; scope is discussions only.
            'discussion_visibility' => (($row['discussion_visibility'] ?? 'member') === 'public') ? 'public' : 'member',
            'tier'          => $tier,
            'provenance'    => $provenance,
            'capabilities'  => self::capabilitiesFor($wpUserId, $tier, $pollerCaps),
            'cache'         => [
                'etag'    => 'W/"' . substr(sha1(json_encode([$row, $tier, $provenance, $pollerCaps])), 0, 16) . '"',
                'max_age' => Cache::WHOAMI_TTL,
            ],
        ];
        if ($unavailable) $payload['tier_unavailable'] = true;

        return $payload;
    }

    private static function anonShape(): array
    {
        return ['authenticated' => false, 'tier' => 'public'];
    }

    /**
     * Capability map. WP-side caps (manage_options, edit_posts,
     * moderate_forums, edit_archive_poc) come from the poller's
     * authoritative response. profile-app layers in its own caps
     * (edit_own_profile = always true for authed users with a row).
     *
     * Start narrow per §2: only emit flags a consumer actually checks.
     */
    private static function capabilitiesFor(int $wpUserId, string $tier, array $pollerCaps): array
    {
        $caps = [
            'edit_own_profile' => true,
            'manage_options'   => (bool)($pollerCaps['manage_options']   ?? false),
            'edit_archive_poc' => (bool)($pollerCaps['edit_archive_poc'] ?? false),
        ];
        // Pass through any additional WP-side caps the poller reports
        // (edit_posts, moderate_forums, etc) — useful for strangler
        // consumers but not authoritative on profile-app's side.
        foreach (['edit_posts', 'moderate_forums'] as $k) {
            if (array_key_exists($k, $pollerCaps)) $caps[$k] = (bool)$pollerCaps[$k];
        }
        return $caps;
    }

    /**
     * Call the poller's internal tier endpoint. Returns null on any error
     * (caller stubs to public + tier_unavailable). Short timeout — this is
     * a cache-miss hot-path, not a retry candidate.
     */
    private static function fetchPollerTier(int $wpUserId): ?array
    {
        $secret = @file_get_contents(self::SECRET_FILE);
        if (!is_string($secret) || $secret === '') return null;
        $secret = trim($secret);

        $url = self::POLLER_TIER_URL_BASE . $wpUserId;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,   // loopback to local nginx self-signed cert
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,  // skip h2/ALPN latency on loopback
            CURLOPT_HTTPHEADER     => [
                self::INTERNAL_AUTH_HEADER . ': ' . $secret,
                'Host: ' . LG_PROFILE_APP_HOST,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr   = curl_error($ch);
        curl_close($ch);

        if ($status !== 200 || !is_string($body)) {
            error_log("[whoami] poller fetch failed: status=$status err=$cerr url=$url");
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d) || empty($d['tier'])) return null;
        return $d;
    }

    /**
     * True only when the request originates from the loopback interface.
     *
     * The trusted X-LG-WP-User-Id bypass impersonates any member by id, so it
     * must never be reachable from the public internet — yet /whoami is itself
     * a PUBLIC endpoint (it also serves anon/JWT viewers), so nginx can't
     * allow/deny the whole location. The genuine caller is the WP shim, which
     * always reaches profile-app over https://127.0.0.1 (with a Host header),
     * so a real trusted-header request has REMOTE_ADDR = 127.0.0.1 / ::1. An
     * external client can present a guessed or leaked shared secret but cannot
     * source from loopback — so the secret alone no longer grants the bypass.
     * Defense-in-depth layered behind hash_equals(); request a /whoami
     * limit_req zone from the infra lane for the brute-force surface.
     */
    public static function clientIsLoopback(): bool
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip === '127.0.0.1' || $ip === '::1' || $ip === '::ffff:127.0.0.1';
    }

    /**
     * Verify a request carries the shared internal secret. Used by the
     * inbound purge endpoint and any future internal API.
     */
    public static function verifyInternalAuth(): bool
    {
        $hdr = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', self::INTERNAL_AUTH_HEADER))] ?? '';
        if (!is_string($hdr) || $hdr === '') return false;
        $secret = @file_get_contents(self::SECRET_FILE);
        if (!is_string($secret) || $secret === '') return false;
        return hash_equals(trim($secret), $hdr);
    }
}
