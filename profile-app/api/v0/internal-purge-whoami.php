<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Cache;
use Looth\ProfileApp\Whoami;

// Internal purge receiver. Called by the WP-side fan-out subscribed to the
// `looth_tier_changed` action (any tier writer: Arbiter, admin role edit,
// refund path, etc). Header `X-LG-Internal-Auth` carries the shared secret
// at /etc/lg-internal-secret. Verified with hash_equals().

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}
if (!Whoami::verifyInternalAuth()) {
    profile_app_json(403, ['error' => 'forbidden']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
$wpUserId = is_array($in) ? (int)($in['wp_user_id'] ?? 0) : 0;
if ($wpUserId < 1) profile_app_json(400, ['error' => 'wp_user_id_required']);

Cache::purgeWhoami($wpUserId);
profile_app_json(204, []);
