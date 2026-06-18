<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Mint.php';

use Looth\ProfileApp\Mint;
use Looth\ProfileApp\Whoami;

// Internal mint endpoint. WP login hooks call this at session establishment
// with { "wp_user_id": <int> } and set the returned token as the looth_id
// cookie. profile-app holds the RS256 private key; WP no longer signs.
// Shared-secret authed via X-LG-Internal-Auth (same channel as purge-whoami),
// localhost-only at the nginx layer. See docs/design-shim-replacement.md.
//
// Graceful-degradation contract: any non-200 here MUST NOT block WP login —
// the hook logs and proceeds, the looth_id cookie is simply absent, and
// consumers fall back to the shim. So failures are signalled by status code,
// never by anything that the caller must treat as fatal.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}
if (!Whoami::verifyInternalAuth()) {
    profile_app_json(403, ['error' => 'forbidden']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
$wpUserId = is_array($in) ? (int)($in['wp_user_id'] ?? 0) : 0;
if ($wpUserId < 1) profile_app_json(400, ['error' => 'wp_user_id_required']);

try {
    $minted = Mint::mintForWpUserId($wpUserId);
} catch (\Throwable $e) {
    // Unreadable key / encode failure. Caller degrades gracefully.
    error_log('[mint-token] sign failed for wp_user_id=' . $wpUserId . ': ' . $e->getMessage());
    profile_app_json(502, ['error' => 'mint_failed']);
}

if ($minted === null) {
    // No profile-app identity bridged to this WP user yet.
    profile_app_json(404, ['error' => 'no_bridge']);
}

profile_app_json(200, $minted);
