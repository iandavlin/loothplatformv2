<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\EraseUser;
use Looth\ProfileApp\Whoami;

/**
 * Internal user-erase receiver — profile-app half of the cross-store
 * teardown (USER-LIFECYCLE-AUDIT.md, Phase 1). Called by the poller-side
 * UserLifecycle::teardown() fan-out.
 *
 *   POST /profile-api/v0/internal/erase-user
 *   Header X-LG-Internal-Auth: <shared secret at /etc/lg-internal-secret>
 *          (verified with hash_equals(), exactly like internal-purge-whoami)
 *   Body (JSON): { "wp_user_id": int, "mode": "nuke"|"tombstone", "dry_run": bool }
 *
 * Deletes the profile-app identity + on-disk media for that user. nuke and
 * tombstone behave identically here (profile-app holds identity, not authored
 * content) — mode is accepted for logging/symmetry only. dry_run:true returns
 * the counts it WOULD delete and deletes nothing. Idempotent: an unknown user
 * returns ok with all-zero counts (not an error).
 *
 * Response: { "ok":true, "wp_user_id":N, "uuid":"…", "mode":"…",
 *             "deleted":{ "users":1, "profile_rows":N, "social_rows":N, "media_files":N } }
 *           or { "ok":false, "error":"…" }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_app_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (!Whoami::verifyInternalAuth()) {
    profile_app_json(403, ['ok' => false, 'error' => 'forbidden']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['ok' => false, 'error' => 'invalid_json']);

$wpUserId = (int) ($in['wp_user_id'] ?? 0);
if ($wpUserId < 1) profile_app_json(400, ['ok' => false, 'error' => 'wp_user_id_required']);

$mode = (string) ($in['mode'] ?? 'nuke');
if (!in_array($mode, ['nuke', 'tombstone'], true)) {
    profile_app_json(400, ['ok' => false, 'error' => 'invalid_mode', 'allowed' => ['nuke', 'tombstone']]);
}
$dryRun = !empty($in['dry_run']);

try {
    $result = EraseUser::run($wpUserId, $mode, $dryRun);
} catch (Throwable $e) {
    error_log('[erase-user] failed for wp_user_id=' . $wpUserId . ': ' . $e->getMessage());
    profile_app_json(500, ['ok' => false, 'error' => 'erase_failed', 'detail' => $e->getMessage()]);
}

profile_app_json(200, $result);
