<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Thin Redis wrapper for the /whoami 30s cache (and any future caches that
 * want the same semantics). Key strategy: prefix every key with `pa:` so we
 * can share a Redis instance with other strangler surfaces without collisions.
 *
 * Whoami keys:
 *   pa:whoami:user:{wp_user_id}  → JSON-encoded response body, 30s TTL
 *   pa:whoami:anon:{token_hash}  → for unauth callers (rare; we may skip)
 *
 * If Redis is unreachable, all methods silently no-op (get returns null,
 * set/del are best-effort). A broken cache must never break the API.
 */
final class Cache
{
    private const PREFIX     = 'pa:';
    public  const WHOAMI_TTL = 30;

    private static ?\Redis $r = null;
    private static bool    $tried = false;

    private static function r(): ?\Redis
    {
        if (self::$tried) return self::$r;
        self::$tried = true;
        try {
            $r = new \Redis();
            // Local unix socket if available, else 127.0.0.1:6379 fallback.
            if (file_exists('/run/redis/redis-server.sock')) {
                $r->connect('/run/redis/redis-server.sock');
            } else {
                $r->connect('127.0.0.1', 6379, 0.5);
            }
            self::$r = $r;
        } catch (\Throwable $e) {
            error_log('profile-app Cache: redis unavailable — ' . $e->getMessage());
            self::$r = null;
        }
        return self::$r;
    }

    public static function getWhoami(int $wpUserId): ?array
    {
        $r = self::r();
        if (!$r) return null;
        try {
            $v = $r->get(self::PREFIX . 'whoami:user:' . $wpUserId);
            if (!is_string($v) || $v === '') return null;
            $d = json_decode($v, true);
            return is_array($d) ? $d : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function setWhoami(int $wpUserId, array $payload): void
    {
        $r = self::r();
        if (!$r) return;
        try {
            $r->setex(self::PREFIX . 'whoami:user:' . $wpUserId,
                      self::WHOAMI_TTL,
                      json_encode($payload, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    public static function purgeWhoami(int $wpUserId): void
    {
        $r = self::r();
        if (!$r) return;
        try {
            $r->del(self::PREFIX . 'whoami:user:' . $wpUserId);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
