<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Provision;

/**
 * WP email-change receiver — profile-app side of identity stability (G4).
 * Called by the Profile-app Sync mu-plugin on the WP `profile_update` hook
 * when a member's email actually changed.
 *
 *   POST /profile-api/v0/hooks/email-changed   (loopback-only at nginx)
 *   Header X-Hook-Secret: <wp_options['profile_hook_secret']>  (same channel
 *          as hooks/user-created; verified with hash_equals())
 *   Body (JSON): { "wp_user_id": int, "email": string }
 *
 * Keeps users.uuid STABLE — adds the new email as an alias + moves
 * primary_email, never re-keys identity off email. The looth_id JWT carries
 * the stored uuid, so the member stays authed as the same identity across the
 * change (fixes the silent logout-as-stranger bug). Idempotent.
 *
 * Response: { "ok":true, "wp_user_id":N, "uuid":"…", "email_changed":bool,
 *             "created":bool, "email_conflict":bool } | { "ok":false, "error":"…" }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_app_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$secret = $_SERVER['HTTP_X_HOOK_SECRET'] ?? '';
if (LG_PROFILE_APP_HOOK_SECRET === '' || !hash_equals(LG_PROFILE_APP_HOOK_SECRET, $secret)) {
    profile_app_json(401, ['ok' => false, 'error' => 'bad_secret']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['ok' => false, 'error' => 'invalid_json']);

$wpId  = isset($in['wp_user_id']) ? (int) $in['wp_user_id'] : 0;
$email = isset($in['email']) ? (string) $in['email'] : '';
if ($wpId < 1 || $email === '') profile_app_json(400, ['ok' => false, 'error' => 'missing_fields']);

try {
    $res = Provision::applyEmailChange($wpId, $email);
} catch (Throwable $e) {
    error_log('[email-changed] failed for wp_user_id=' . $wpId . ': ' . $e->getMessage());
    profile_app_json(500, ['ok' => false, 'error' => 'db_error', 'detail' => $e->getMessage()]);
}

profile_app_json(200, [
    'ok'             => true,
    'wp_user_id'     => $wpId,
    'uuid'           => $res['uuid'],
    'email_changed'  => $res['email_changed'],
    'created'        => $res['created'],
    'email_conflict' => $res['email_conflict'] ?? false,
]);
