<?php
/**
 * profile-app — DEV-ONLY looth_id token minter (CLI).
 *
 * Unblocks authed /me HTTP-testing on dev WITHOUT the nginx /mint-token route,
 * the shared secret, or a real WP login. Run it as the `profile-app` role —
 * the one context that can BOTH read the RS256 private key
 * (/etc/looth/jwt-private.pem, now root:profile-app 0640) AND reach the
 * profile_app DB via peer-auth — and it prints a signed looth_id JWT for a
 * given wp_user_id:
 *
 *   sudo -u profile-app php bin/mint-dev-token.php <wp_user_id>
 *
 * Token → STDOUT (only the token, so `TOKEN=$(...)` captures it cleanly).
 * Summary + errors → STDERR.
 *
 * Uses the SAME Mint::mintForWpUserId() the internal HTTP endpoint uses, so a
 * token minted here verifies identically to one minted over the wire. Present
 * it as either the `looth_id` cookie or `Authorization: Bearer <token>`.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("mint-dev-token: CLI only\n");
}

require_once __DIR__ . '/../config.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Mint.php';

use Looth\ProfileApp\Mint;

$wpUserId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($wpUserId < 1) {
    fwrite(STDERR, "usage: php bin/mint-dev-token.php <wp_user_id>\n");
    exit(2);
}

try {
    $minted = Mint::mintForWpUserId($wpUserId);
} catch (\Throwable $e) {
    fwrite(STDERR, "[mint-dev-token] sign failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "  key must be readable as this user — run: sudo -u profile-app php bin/mint-dev-token.php $wpUserId\n");
    exit(1);
}

if ($minted === null) {
    fwrite(STDERR, "[mint-dev-token] no profile-app identity bridged to wp_user_id=$wpUserId\n");
    fwrite(STDERR, "  (run bin/reconcile-bridge.php if this WP user should have a profile row)\n");
    exit(3);
}

fwrite(STDERR, sprintf(
    "minted looth_id for wp_user_id=%d  exp=%d (%s)\n",
    $wpUserId, $minted['exp'], gmdate('c', $minted['exp'])
));
fwrite(STDOUT, $minted['token'] . "\n");
exit(0);
