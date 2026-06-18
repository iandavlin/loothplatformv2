<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Discussion-author posting visibility — the owner's preference for whether
 * LOGGED-OUT viewers see their real identity on DISCUSSION (forum) posts.
 * Piece #2's persistence half (docs/briefing-discussion-visibility.md).
 *
 *   GET → { discussion_visibility: 'public'|'member' }   (self)
 *   PUT → { discussion_visibility: 'public'|'member' }    sets it; default 'member'.
 *
 * Source of truth lives here; surfaced in /whoami (self) + /users (batch) so the
 * archive-poc person-sync can copy it into forums.person and the Hub mask reads it.
 * Scope = discussions only — CPT author rendering is unaffected.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Cache;
use Looth\ProfileApp\Db;

const ME_DISCUSSION_VIS_ALLOWED = ['public', 'member'];

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];
$pg     = Db::pg();

if ($method === 'GET') {
    $st = $pg->prepare('SELECT discussion_visibility FROM users WHERE id = :i');
    $st->execute([':i' => (int)$user['id']]);
    $vis = $st->fetchColumn();
    if ($vis === false || !in_array($vis, ME_DISCUSSION_VIS_ALLOWED, true)) $vis = 'member';
    profile_app_json(200, ['discussion_visibility' => $vis]);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !array_key_exists('discussion_visibility', $in)) {
    profile_app_json(400, ['error' => 'discussion_visibility_required']);
}
$vis = $in['discussion_visibility'];
if (!is_string($vis) || !in_array($vis, ME_DISCUSSION_VIS_ALLOWED, true)) {
    profile_app_json(400, ['error' => 'invalid_discussion_visibility', 'allowed' => ME_DISCUSSION_VIS_ALLOWED]);
}

$up = $pg->prepare('UPDATE users SET discussion_visibility = :v, updated_at = now() WHERE id = :i');
$up->execute([':v' => $vis, ':i' => (int)$user['id']]);

// discussion_visibility rides BOTH the /whoami self payload AND the forums.person
// cache the Hub feed JOINs (path-a, briefing-discussion-visibility.md). Resolve the
// wp_user_id once, then — best-effort, never fatal — refresh both:
//   1. purge whoami so the next self read is fresh.
//   2. poke bb-mirror to re-sync THIS author's forums.person row NOW. Without the
//      poke the synced cache stays stale on a pure toggle change (old posts
//      masked/exposed wrong) until the user next posts or a full reconcile runs.
$wpId = 0;
try {
    $b = $pg->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
    $b->execute([':u' => (int)$user['id']]);
    $wpId = (int)$b->fetchColumn();
} catch (Throwable $e) {
    error_log('[me-discussion-visibility] wp_user_id lookup failed: ' . $e->getMessage());
}
if ($wpId > 0) {
    try { Cache::purgeWhoami($wpId); }
    catch (Throwable $e) { error_log('[me-discussion-visibility] whoami purge failed: ' . $e->getMessage()); }
    me_discussion_vis_poke_person($wpId);
}

profile_app_json(200, ['ok' => true, 'discussion_visibility' => $vis]);

/**
 * Best-effort poke of bb-mirror to immediately re-sync one author's forums.person
 * row after a discussion-visibility change. We REUSE bb-mirror's loopback sync
 * entrypoint (the same receiver the bbp_* mu-plugin hooks post to) rather than
 * writing forums.person blind — bb-mirror owns that write and re-pulls the value
 * from /profile-api/v0/users. Contract coordinated with the bb-mirror person-sync
 * lane (commit 9046513): it adds a ['person','upsert'] case → bb_mirror_person_for().
 *
 *   POST https://127.0.0.1/bb-mirror-api/v0/_sync
 *   Host: <public host>, X-BB-Mirror-Sync: 1, Content-Type: application/json
 *   { "kind":"person", "id":<wp_user_id>, "action":"upsert" }
 *
 * NON-FATAL by contract: any failure (incl. bb-mirror not yet shipping the person
 * case → 400) is swallowed — the value self-heals on the next person sync, so the
 * toggle must never 500 on a poke failure. On dev the bb-mirror→profile-app /users
 * call sits behind the cookie gate, so we forward our own loothdev_auth gate cookie
 * when present (live has no gate).
 */
function me_discussion_vis_poke_person(int $wpId): void
{
    if ($wpId <= 0) return;
    try {
        $host = $_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com';
        $hdrs = ['Host: ' . $host, 'Content-Type: application/json', 'X-BB-Mirror-Sync: 1'];
        $gate = $_COOKIE['loothdev_auth'] ?? '';
        if ($gate !== '') $hdrs[] = 'Cookie: loothdev_auth=' . $gate;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://127.0.0.1/bb-mirror-api/v0/_sync',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['kind' => 'person', 'id' => $wpId, 'action' => 'upsert']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 5,   // rare owner action; _sync bootstraps WP to materialize
            CURLOPT_HTTPHEADER     => $hdrs,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log("[me-discussion-visibility] person-poke non-200 ($code) wp_id=$wpId — self-heals on next sync");
        }
    } catch (Throwable $e) {
        error_log('[me-discussion-visibility] person-poke failed: ' . $e->getMessage());
    }
}
