<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Provision;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

$secret = $_SERVER['HTTP_X_HOOK_SECRET'] ?? '';
if (LG_PROFILE_APP_HOOK_SECRET === '' || !hash_equals(LG_PROFILE_APP_HOOK_SECRET, $secret)) {
    profile_app_json(401, ['error' => 'bad_secret']);
}

$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$wpId   = isset($in['wp_user_id']) ? (int)$in['wp_user_id'] : 0;
$email  = isset($in['email']) ? (string)$in['email'] : '';
$name   = isset($in['display_name']) ? (string)$in['display_name'] : null;
// Optional: the WP user_nicename, the preferred /u/ slug source. Absent today
// (poller sends id/email/display_name); Provision falls back to display_name/email.
$nice   = isset($in['nicename']) ? (string)$in['nicename'] : null;
if ($wpId <= 0 || $email === '') profile_app_json(400, ['error' => 'missing_fields']);

// Idempotent, self-healing create+bridge (G7). Safe for the poller's blocking
// provision() to retry until it sticks. autoClaim=true: this is the onboard path,
// so mark the profile claimed (skip the "Start your profile" interstitial) — the
// public claim endpoint stays the path for legacy/admin rows.
try {
    $res = Provision::ensure($wpId, $email, $name, $nice, true);
} catch (Throwable $e) {
    profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]);
}

profile_app_json(200, ['ok' => true, 'uuid' => $res['uuid'], 'user_id' => $res['user_id'], 'created' => $res['created']]);
