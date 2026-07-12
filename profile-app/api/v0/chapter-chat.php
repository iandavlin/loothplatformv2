<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Chapters.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/ChapterChat.php';

/**
 * CHAPTER CHAT ROOM — the throwaway half. "anyone actually coming Saturday?"
 * Backend: src/ChapterChat.php, which documents how a ROOM differs from a DM thread.
 *
 *   GET  /profile-api/v0/chapters/<slug>/chat[?since=<id>]  -> { messages, unread, can_post, me }
 *   POST /profile-api/v0/chapters/<slug>/chat               -> { ok, id }     body { body }
 *   POST /profile-api/v0/chapters/<slug>/chat?read=<id>     -> { ok, unread } (advance watermark)
 *   POST /profile-api/v0/chapters/<slug>/chat?mute=1|0      -> { ok, muted }
 *
 * READ is public (chapters are public + browsable — a room you cannot read is a room you
 * cannot decide to join). POST requires membership, and joining is one tap.
 *
 * ⚠️ THIS ENDPOINT NEVER TOUCHES message_recipients, AND NEVER WRITES A NOTIFICATION.
 *   * Membership is DERIVED from chapter_member. A room has zero message_recipients rows,
 *     which is exactly why the DM endpoints reject it and it can never surface in an inbox.
 *   * The unread badge is a COUNT against the read-state watermark, so a send is 1 INSERT
 *     no matter how many members the room has, and there is no fan-out to bound.
 *
 * `me` is returned so the client can render `mine` as (sender_uuid === me) — a ROOM has many
 * senders, so the DM renderer's trick of inferring "mine" from the peer set does not apply.
 * That is the single most important difference in the client seam; see webroot/chapter-chat.js.
 *
 * NOTE TO COORDINATOR — nginx:
 *   rewrite "^/profile-api/v0/chapters/([\w\-]+)/chat/?$" /profile-api/v0/chapter-chat.php?slug=$1&$args last;
 *   + `chapter-chat` in the PUBLIC allowlist regex.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Chapters;
use Looth\ProfileApp\ChapterChat;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$slug   = trim((string)($_GET['slug'] ?? ''));
$ch     = Chapters::bySlug($slug);
if (!$ch) profile_app_json(404, ['error' => 'no_such_chapter']);

$cid  = (int)$ch['id'];
$room = ChapterChat::room($cid);
if (!$room) profile_app_json(404, ['error' => 'no_room']);
$tid = (int)$room['id'];

if ($method === 'GET') {
    $user  = Auth::currentUser();          // anonymous may read
    $uuid  = $user['uuid'] ?? null;
    $since = isset($_GET['since']) ? max(0, (int)$_GET['since']) : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 60;

    profile_app_json(200, [
        'messages' => ChapterChat::messages($tid, $since, $limit),
        'unread'   => ChapterChat::unreadCount($tid, $uuid),
        'can_post' => ChapterChat::canPost($cid, $uuid),
        'muted'    => ChapterChat::isMuted($tid, $uuid),
        'me'       => $uuid,               // the room renderer needs this; a DM's peer trick cannot work here
    ]);
}

if ($method === 'POST') {
    $user = Auth::requireUser();
    $uuid = $user['uuid'];

    // Advance the read watermark. ONE upserted row per member per room — never per message.
    if (isset($_GET['read'])) {
        ChapterChat::markRead($tid, $uuid, max(0, (int)$_GET['read']));
        profile_app_json(200, ['ok' => true, 'unread' => ChapterChat::unreadCount($tid, $uuid)]);
    }

    if (isset($_GET['mute'])) {
        $muted = $_GET['mute'] === '1';
        ChapterChat::setMuted($tid, $uuid, $muted);
        profile_app_json(200, ['ok' => true, 'muted' => $muted]);
    }

    profile_app_rate_gate('chapter-chat:' . $uuid, 60, 300);   // 60 messages / 5 min / member

    $in   = json_decode(file_get_contents('php://input') ?: '', true);
    $in   = is_array($in) ? $in : [];
    $body = (string)($in['body'] ?? '');

    $res = ChapterChat::send($cid, $uuid, $body);
    if (!$res['ok'] && $res['error'] === 'not_a_member') {
        profile_app_json(403, ['error' => 'not_a_member', 'join_required' => true]);
    }
    profile_app_json($res['ok'] ? 200 : 400, $res);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
