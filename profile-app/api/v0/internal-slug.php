<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Whoami;

// Internal slug resolver. The WP-side JWT minter (profile-auth.php) calls this
// at mint time to populate the looth_id `slug` claim (§0c shape) — WP cannot
// read Postgres, where the slug lives, so it asks profile-app for it.
//
// Why a dedicated GATE-EXEMPT endpoint and not /whoami: /whoami sits behind the
// dev cookie gate, and a server-to-server mint call carries no gate cookie (it
// 403s). This route lives under /profile-api/v0/internal/ — locked to localhost
// at the nginx layer and authed in PHP via the X-LG-Internal-Auth shared secret
// (/etc/lg-internal-secret, hash_equals). It reads users.slug fresh from PG via
// the wp_user_bridge, so it never goes stale.
//
// Contract: GET /profile-api/v0/internal/slug?wp_user_id=<int>
//   200 { "slug": "<slug>" }   — bridged identity with a slug
//   200 { "slug": null }       — bridged identity, slug not yet assigned
//   400 { "error": ... }       — missing/invalid wp_user_id
//   403 { "error": "forbidden" } — bad/absent internal secret
// A non-200 MUST NOT block the WP mint — the caller logs and omits the claim.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}
if (!Whoami::verifyInternalAuth()) {
    profile_app_json(403, ['error' => 'forbidden']);
}

$wpUserId = isset($_GET['wp_user_id']) ? (int) $_GET['wp_user_id'] : 0;
if ($wpUserId < 1) profile_app_json(400, ['error' => 'wp_user_id_required']);

try {
    $stmt = Db::pg()->prepare('
        SELECT u.slug
        FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
        WHERE b.wp_user_id = :w
    ');
    $stmt->execute([':w' => $wpUserId]);
    $slug = $stmt->fetchColumn();
} catch (\Throwable $e) {
    error_log('[internal-slug] lookup failed for wp_user_id=' . $wpUserId . ': ' . $e->getMessage());
    profile_app_json(502, ['error' => 'lookup_failed']);
}

profile_app_json(200, ['slug' => ($slug === false || $slug === '') ? null : (string) $slug]);
