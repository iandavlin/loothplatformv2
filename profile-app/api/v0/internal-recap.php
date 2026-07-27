<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Recap;
use Looth\ProfileApp\Whoami;

/**
 * Bell → weekly digest. The read-side twin of internal-notify.php.
 *
 *   POST /profile-api/v0/internal/recap     (loopback-only at nginx)
 *   Header X-LG-Internal-Auth: <shared secret at /etc/lg-internal-secret>
 *   Body (JSON):
 *     { wp_user_ids: int[],     // 1..500 members to fetch in one round trip
 *       days:        int }      // lookback window, default 7, clamped 1..90
 *
 * Response:
 *   { ok:true, days:int, recaps: { "<wp_user_id>": { notifications:[…], dms:[…] } } }
 *
 * WHY AN HTTP HOP AND NOT A DIRECT SELECT: identical to the writer. The caller
 * (lg-weekly-digest, on the WP pool as `looth-dev`) has zero grants on the
 * `profile_app` database. Granting it SELECT would have been fewer moving parts at
 * send time, and was rejected: the bridge deliberately keeps profile-app the only
 * process that touches its own store, and one lane should not punch a hole in that
 * boundary to save a loopback call on a weekly cron.
 *
 * WHY BATCH: the digest goes to ~1,700 subscribers. One request per recipient at
 * send time is ~1,700 round trips inside a cron run; the array form lets the caller
 * prime a whole chunk at once. Single-id calls are still legal (an array of one) —
 * that is what the per-subscriber smart code falls back to on a cache miss.
 *
 * SECURITY: loopback + shared secret only. This endpoint takes the SUBJECT from the
 * caller, so it must never be reachable from the internet — it would otherwise hand
 * anyone another member's unread activity. The nginx block (allow 127.0.0.1; deny
 * all) is the primary gate; hash_equals is the second. It is strictly read-only: no
 * INSERT, no UPDATE, and in particular it does NOT mark anything read.
 *
 * NEVER RETURNS CONTENT — counts, actor names and links only (Ian's ruling). See
 * Recap.php; the SELECT lists are where that is enforced.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    profile_app_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (!Whoami::clientIsLoopback() || !Whoami::verifyInternalAuth()) {
    profile_app_json(403, ['ok' => false, 'error' => 'forbidden']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) {
    profile_app_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$ids = $in['wp_user_ids'] ?? null;
if (!is_array($ids) || !$ids) {
    profile_app_json(400, ['ok' => false, 'error' => 'missing_wp_user_ids']);
}
if (count($ids) > 500) {
    // A bounded request keeps one call's memory and query time predictable. The
    // caller chunks; it is not silently truncated here, because a recap that
    // quietly covered 500 of 1,700 members would look like a success.
    profile_app_json(400, ['ok' => false, 'error' => 'too_many_ids', 'max' => 500]);
}

$days = isset($in['days']) ? (int) $in['days'] : 7;

try {
    $recaps = Recap::forWpIds($ids, $days);
} catch (Throwable $e) {
    error_log('[internal-recap] failed: ' . $e->getMessage());
    profile_app_json(500, ['ok' => false, 'error' => 'db_error']);
}

// Keys are ints; json_encode turns them into strings, which is what the caller
// expects (PHP array keys from json_decode come back as strings too).
profile_app_json(200, ['ok' => true, 'days' => max(1, min(90, $days)), 'recaps' => $recaps]);
