<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Notifications;
use Looth\ProfileApp\Whoami;

/**
 * Hub → bell ingest. The ONE door every hub-side notification comes through
 * (notifications lane, 2026-07-12).
 *
 *   POST /profile-api/v0/internal/notify     (loopback-only at nginx)
 *   Header X-LG-Internal-Auth: <shared secret at /etc/lg-internal-secret>
 *   Body (JSON):
 *     { recipient_wp_id: int,          // who gets the bell
 *       actor_wp_id:     int|null,     // who did it (null = system)
 *       type:            string,       // Notifications::HUB_TYPES
 *       target_kind:     string,       // 'topic' | 'reply' | 'card' | (future: 'chapter_*')
 *       target_id:       int,          // WP post id of the thing
 *       target_url:      string,       // the deep link — CURRENT system, relative path
 *       anchor_id:       int|null }    // the reply to scroll to inside the modal
 *
 * WHY an HTTP hop and not a direct INSERT: the callers (bb-mirror reply.php,
 * archive-poc card-react.php) run on the WP pool as `looth-dev` and talk to the
 * `looth` Postgres database. The bell lives in a DIFFERENT database (`profile_app`,
 * peer-auth as the `profile-app` role). They cannot reach it. This is the same
 * server-to-server channel the whoami shim and the JWT minter already use.
 *
 * Callers pass WP user ids (all they have); the uuid mapping happens HERE, via the
 * same wp_user_bridge the slug resolver uses. An UNBRIDGED recipient (e.g. the
 * shared anonymous-posting account) simply has no bell — it is skipped, not an error.
 *
 * SECURITY: loopback + shared secret only — this endpoint takes the recipient from
 * the CALLER, so it must never be reachable from the internet. The nginx block
 * (allow 127.0.0.1; deny all) is the primary gate; hash_equals is the second.
 *
 * Response: { ok:true, raised:bool, skipped?:"self"|"no_recipient"|"unbridged" }
 * A non-2xx MUST NOT break the caller's write — a reply that posted must not fail
 * because the bell was down. Callers fire-and-forget with a short timeout.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    profile_app_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}
if (!Whoami::clientIsLoopback() || !Whoami::verifyInternalAuth()) {
    profile_app_json(403, ['ok' => false, 'error' => 'forbidden']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['ok' => false, 'error' => 'invalid_json']);

$recipientWp = (int)   ($in['recipient_wp_id'] ?? 0);
$actorWp     = (int)   ($in['actor_wp_id']     ?? 0);
$type        = (string)($in['type']            ?? '');
$targetKind  = (string)($in['target_kind']     ?? '');
$targetId    = (int)   ($in['target_id']       ?? 0);
$targetUrl   = (string)($in['target_url']      ?? '');
$anchorId    = isset($in['anchor_id']) ? (int) $in['anchor_id'] : 0;

if ($recipientWp < 1 || $targetId < 1 || $type === '' || $targetKind === '' || $targetUrl === '') {
    profile_app_json(400, ['ok' => false, 'error' => 'missing_fields']);
}
if (!in_array($type, Notifications::HUB_TYPES, true)) {
    profile_app_json(400, ['ok' => false, 'error' => 'bad_type', 'allowed' => Notifications::HUB_TYPES]);
}
// The link must be a SITE-RELATIVE path. Refuse absolute/scheme-bearing URLs so a
// compromised caller can't plant an off-site link in someone's notification list.
if ($targetUrl[0] !== '/' || str_starts_with($targetUrl, '//')) {
    profile_app_json(400, ['ok' => false, 'error' => 'bad_target_url']);
}
if ($recipientWp === $actorWp) {
    profile_app_json(200, ['ok' => true, 'raised' => false, 'skipped' => 'self']);
}

/** wp_user_id → users.uuid (the bridge the internal slug resolver uses). */
function notify_uuid_for_wp_id(int $wpId): ?string
{
    if ($wpId < 1) return null;
    $st = Db::pg()->prepare(
        'SELECT u.uuid FROM users u JOIN wp_user_bridge b ON b.user_id = u.id WHERE b.wp_user_id = :w'
    );
    $st->execute([':w' => $wpId]);
    $uuid = $st->fetchColumn();
    return ($uuid === false || $uuid === null || $uuid === '') ? null : (string) $uuid;
}

try {
    $recipientUuid = notify_uuid_for_wp_id($recipientWp);
    if ($recipientUuid === null) {
        // No profile identity (anon-posting account, unprovisioned member) → no bell.
        profile_app_json(200, ['ok' => true, 'raised' => false, 'skipped' => 'unbridged']);
    }
    $actorUuid = $actorWp > 0 ? notify_uuid_for_wp_id($actorWp) : null;

    $raised = Notifications::pushHubEvent(
        $recipientUuid,
        $type,
        $targetKind,
        $targetId,
        $targetUrl,
        $actorUuid,
        $anchorId > 0 ? $anchorId : null
    );
} catch (Throwable $e) {
    error_log('[internal-notify] ' . $type . ' → wp:' . $recipientWp . ' failed: ' . $e->getMessage());
    profile_app_json(500, ['ok' => false, 'error' => 'db_error']);
}

profile_app_json(200, ['ok' => true, 'raised' => $raised]);
