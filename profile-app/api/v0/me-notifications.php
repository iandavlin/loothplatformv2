<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Notifications.php';

/**
 * Notifications endpoint (bell feed + mark-read + delete). Plan: social-layer §4.
 * Backend: src/Notifications.php. lg-shell's bell + modal call this; profile-app
 * owns the data. Identity via Auth::requireUser() (/whoami).
 *   GET    → { items: [ { id, type, actor{uuid,name,avatar_url,slug}, ref{kind,id},
 *                         is_read, created_at } ], unread: int,
 *              read_policy: 'seen'|'all' }                    (recent-first; ?limit=)
 *   POST   → { action: 'read', id }                                → marks ONE read
 *          → { action: 'read_seen', ids: [int] }  → marks the rows a surface SHOWED
 *          → { action: 'read_all' }               → marks the WHOLE store read
 *     read_seen is what the mobile sheet fires now. read_all used to be, on a 700ms
 *     timer, which marked rows the member never saw and — because the weekly recap
 *     is unread-only and empty means no email — cancelled their digest. See
 *     docs/RECAP-READ-TIMER.md; the scoping is flagged in config/notifications.php.
 *   DELETE → { id }  (or ?id=)   → delete ONE (404 if not the caller's / gone)
 *          → { all: true } (or ?all=1) → delete ALL of the caller's (Clear-all)
 *            An id-less / all-less DELETE is 400 — never mass-delete by omission.
 * DELETE rides the SAME collection route as GET/POST (id/all in query or body),
 * so no new nginx path-capture is needed. Owner scoping is in the model
 * (WHERE user_uuid); a non-owner id simply deletes nothing → 404. This is the
 * REAL delete that retired the mobile client "watermark" (cleared = gone
 * server-side, on every device), and the desktop per-row × now removes for good.
 * Counts for the header badge come from me-social-counts.
 * Retention: 30-day prune is a cron (bin/prune-notifications), NOT this endpoint.
 *
 * NOTE TO COORDINATOR — nginx route (unchanged; DELETE reuses the collection route):
 *   rewrite ^/profile-api/v0/me/notifications/?$ /profile-api/v0/me-notifications.php last;
 *   …and add `me-notifications` to the allowlist regex in strangler-profile-app.conf.
 *   The route must NOT limit_except GET/POST — DELETE has to reach PHP.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Notifications;

$user   = Auth::requireUser();
$uuid   = $user['uuid'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // ?limit= lets a surface ask for the WHOLE list rather than the default page.
    // The mobile sheet's "See all notifications" needs that: once marking-read is
    // scoped to rendered rows, a row the sheet can never render is a row whose
    // unread badge the member can never clear by reading. Clamped to maxIds().
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
    $limit = max(1, min(Notifications::maxIds(), $limit));
    profile_app_json(200, [
        'items'  => Notifications::listFor($uuid, $limit),
        'unread' => Notifications::unreadCount($uuid),
        // The read policy travels WITH the rows it governs, in the same response,
        // so the client needs no second round-trip and no flag of its own. A client
        // that predates this key sees `undefined` and keeps its old behaviour, which
        // is exactly the OFF behaviour — so an unversioned cached bottom-nav.js is
        // safe. 'all' => sweep the store (today). 'seen' => only what was rendered.
        'read_policy' => Notifications::readSeenOnly() ? 'seen' : 'all',
    ]);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    $in = is_array($in) ? $in : [];
    $action = (string)($in['action'] ?? '');
    if ($action === 'read') {
        $id = (int)($in['id'] ?? 0);
        if (!$id) profile_app_json(400, ['error' => 'id_required']);
        Notifications::markRead($uuid, $id);
        profile_app_json(200, ['ok' => true]);
    }
    // read_seen — "mark read the rows I actually SHOWED the member", named as ids.
    //
    // Transport only: the policy branch is Notifications::applySeenRead, next to the
    // function that reads the flag, so this endpoint cannot drift from it. Under
    // read_seen_only = false that runs the SAME markAllRead() SQL read_all has always
    // run, so OFF is a no-op on the store no matter what a client sends — which is
    // what makes the OFF state provable rather than argued.
    // notif-read-seen-gate.py asserts both directions, including the absent half:
    // that rows the member did NOT see are STILL UNREAD.
    if ($action === 'read_seen') {
        $ids = $in['ids'] ?? [];
        if (!is_array($ids)) profile_app_json(400, ['error' => 'ids_must_be_array']);
        $r = Notifications::applySeenRead($uuid, $ids);
        $body = ['ok' => true, 'policy' => $r['policy']];
        if ($r['marked'] >= 0) $body['marked'] = $r['marked'];
        profile_app_json(200, $body);
    }
    if ($action === 'read_all') {
        // Kept, unconditionally, as the EXPLICIT verb: "I mean all of them." It is
        // no longer fired by a timer — see webroot/bottom-nav.js — so reaching it now
        // means a caller genuinely asked to sweep the store.
        Notifications::markAllRead($uuid);
        profile_app_json(200, ['ok' => true]);
    }
    profile_app_json(400, ['error' => 'bad_action',
                           'allowed' => ['read', 'read_seen', 'read_all']]);
}

if ($method === 'DELETE') {
    // id/all may arrive in the query (?id= / ?all=1) or a JSON body — accept both;
    // some proxies drop DELETE bodies, so the query is the belt.
    $in  = json_decode(file_get_contents('php://input') ?: '', true);
    $in  = is_array($in) ? $in : [];
    $all = !empty($_GET['all']) || !empty($in['all']);
    $id  = (int)($_GET['id'] ?? $in['id'] ?? 0);

    // DELETE = DISMISS (Ian, 2026-08-08), behind config/notifications.php.
    //
    // The WIRE CONTRACT IS UNCHANGED in both states — same methods, same params, same
    // `{ok, deleted:N}` / `{ok}` / 404 shapes — because the surfaces (bottom-nav.js's
    // sheet, social-modals.js's modal) must not need to know which state the box is
    // in. Only the row's fate changes: gone, or kept-and-hidden. Keeping the
    // `deleted` key name when the flag is on is deliberate and not sloppiness — a
    // renamed key would be a silent client break the moment the flag flips, and from
    // the member's side "deleted" is still exactly what happened to it.
    $dismiss = Notifications::dismissEnabled();
    if ($all) {
        // Clear-all. This is the tap that used to destroy a member's whole week.
        $n = $dismiss ? Notifications::dismissAll($uuid) : Notifications::deleteAll($uuid);
        profile_app_json(200, ['ok' => true, 'deleted' => $n]);
    }
    if ($id > 0) {
        // Owner-scoped in the model; not-yours / already-gone are one 404 (deny model).
        $ok = $dismiss ? Notifications::dismiss($uuid, $id) : Notifications::delete($uuid, $id);
        if (!$ok) profile_app_json(404, ['error' => 'not_found']);
        profile_app_json(200, ['ok' => true]);
    }
    // Neither id nor all → refuse, so a malformed request can never wipe the list.
    profile_app_json(400, ['error' => 'id_or_all_required']);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
