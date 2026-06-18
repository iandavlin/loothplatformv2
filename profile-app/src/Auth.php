<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class Auth
{
    public const COOKIE      = 'looth_id';
    public const PUBLIC_KEY  = '/etc/looth/jwt-public.pem';

    private static ?array  $cachedUser  = null;
    private static bool    $cacheBuilt  = false;
    private static ?string $publicKey   = null;
    private static ?array  $lastClaims  = null;
    private static ?bool   $isAdmin     = null;

    /** Returns the JWT claims or null if absent/invalid. */
    public static function claims(): ?array
    {
        if (self::$cacheBuilt) return self::$lastClaims;
        self::$cacheBuilt = true;

        $jwt = self::readToken();
        if ($jwt === null) return null;

        if (self::$publicKey === null) {
            self::$publicKey = @file_get_contents(self::PUBLIC_KEY);
            if (!self::$publicKey) {
                error_log('profile-app Auth: cannot read ' . self::PUBLIC_KEY);
                return null;
            }
        }

        try {
            $decoded = JWT::decode($jwt, new Key(self::$publicKey, 'RS256'));
            self::$lastClaims = (array) $decoded;
            return self::$lastClaims;
        } catch (\Throwable $e) {
            // Expired / signature mismatch / malformed — treat as anonymous.
            return null;
        }
    }

    /** Returns the profile-app user row for the bearer, or null if anonymous. */
    public static function currentUser(): ?array
    {
        if (self::$cachedUser !== null) return self::$cachedUser ?: null;

        $claims = self::claims();
        if (!$claims || empty($claims['sub'])) return null;

        $stmt = Db::pg()->prepare('SELECT * FROM users WHERE uuid = :u');
        $stmt->execute([':u' => strtolower((string)$claims['sub'])]);
        $row = $stmt->fetch();

        self::$cachedUser = $row ?: [];
        return $row ?: null;
    }

    /**
     * Endpoints an administrator may drive ON BEHALF OF another member via
     * ?as=<uuid> (front-end admin profile editing, Ian 6/12). PROFILE CONTENT
     * only — social actions (connections, messages, notifications, mutes),
     * claim, and practice endpoints are deliberately NOT actable: admin edit
     * must never impersonate a member socially.
     */
    public const ADMIN_EDIT_AS_ENDPOINTS = [
        'me-about.php', 'me-avatar.php', 'me-banner.php', 'me-catalog.php',
        'me-connect.php',            // the connect-INFO block (read + vis PATCH only)
        'me-craft.php', 'me-credentials.php', 'me-discussion-visibility.php',
        'me-dropoffs.php', 'me-freeform.php', 'me-gallery.php', 'me-header.php',
        'me-highlights.php', 'me-instruments.php', 'me-layout.php', 'me-lights.php',
        'me-location.php', 'me-location-search.php', 'me-name.php', 'me-resume.php',
        'me-scenes.php', 'me-section-order.php', 'me-skills.php', 'me-socials.php',
    ];

    /** Required-auth helper for API endpoints. 401s if no user resolved. */
    public static function requireUser(): array
    {
        $u = self::currentUser();
        if (!$u) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'auth_required']);
            exit;
        }

        // ADMIN FRONT-END EDIT (Ian 6/12): an administrator acting on another
        // member's profile appends ?as=<uuid>; the endpoint then operates on
        // THAT member's row. One choke point, admin-only, allowlisted
        // endpoints only, every use audit-logged. Everyone else: 403.
        $as = $_GET['as'] ?? '';
        if (is_string($as) && $as !== '') {
            $deny = static function (int $code, string $err): void {
                http_response_code($code);
                header('Content-Type: application/json');
                echo json_encode(['error' => $err]);
                exit;
            };
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $as)) {
                $deny(400, 'invalid_as');
            }
            if (!self::isAdmin()) $deny(403, 'admin_only');
            $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
            if (!in_array($script, self::ADMIN_EDIT_AS_ENDPOINTS, true)) $deny(403, 'endpoint_not_actable');
            $stmt = Db::pg()->prepare('SELECT * FROM users WHERE uuid = :u AND archived_at IS NULL');
            $stmt->execute([':u' => strtolower($as)]);
            $subject = $stmt->fetch();
            if (!$subject) $deny(404, 'subject_not_found');
            error_log(sprintf('[admin-edit] admin user %d (%s) acting on user %d via %s %s',
                (int)$u['id'], (string)$u['uuid'], (int)$subject['id'],
                (string)($_SERVER['REQUEST_METHOD'] ?? '?'), $script));
            return $subject;
        }

        return $u;
    }

    /**
     * Whether the bearer is a WordPress administrator. Gates front-end catalog management
     * (admins add/deactivate catalog rows from the picker). The looth_id token carries no
     * role claim today, so this checks wp_capabilities in the WP DB via the peer-auth MySQL
     * socket (same access pattern as me-name's wp_users mirror). Cached per request.
     */
    public static function isAdmin(): bool
    {
        if (self::$isAdmin !== null) return self::$isAdmin;
        self::$isAdmin = false;
        $claims = self::claims();
        $wpId   = (int) ($claims['wp_user_id'] ?? 0);
        if ($wpId < 1) return false;
        try {
            $u  = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
            $my = new \PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
                $u, '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $s = $my->prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'wp_capabilities'");
            $s->execute([$wpId]);
            $caps = (string) $s->fetchColumn();
            self::$isAdmin = $caps !== '' && strpos($caps, '"administrator"') !== false;
        } catch (\Throwable $e) {
            error_log('[Auth::isAdmin] cap check failed: ' . $e->getMessage());
        }
        return self::$isAdmin;
    }

    private static function readToken(): ?string
    {
        if (!empty($_COOKIE[self::COOKIE])) return (string)$_COOKIE[self::COOKIE];
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
        return null;
    }
}
