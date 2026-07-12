<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Chapters.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/ChapterChat.php';

/**
 * Chapter detail. Backend: src/Chapters.php.
 *
 *   GET /profile-api/v0/chapters/<slug>   -> { chapter, member_count, is_member, room, unread }
 *   GET /profile-api/v0/chapters          -> { chapters: [...] }   (index)
 *
 * PUBLIC — anonymous is a first-class audience (chapters are public + browsable; Ian
 * 2026-07-12). Anonymous simply gets is_member=false and unread=0.
 *
 * NOTE TO COORDINATOR — nginx (platform/nginx/strangler-profile-app.conf):
 *   rewrite "^/profile-api/v0/chapters/?$"           /profile-api/v0/chapter.php last;
 *   rewrite "^/profile-api/v0/chapters/([\w\-]+)/?$" /profile-api/v0/chapter.php?slug=$1 last;
 *   ...and add `chapter` to the PUBLIC allowlist regex (the directory-members one), NOT the
 *   /me one — the /me allowlist 403s anonymous, which would defeat "public and browsable".
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Chapters;
use Looth\ProfileApp\ChapterChat;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

$user = Auth::currentUser();            // null = anonymous, and that is fine here
$uuid = $user['uuid'] ?? null;

$slug = trim((string)($_GET['slug'] ?? ''));

// Index.
if ($slug === '') {
    $out = [];
    foreach (Chapters::all() as $c) {
        $out[] = [
            'slug'         => $c['slug'],
            'name'         => $c['name'],
            'description'  => $c['description'],
            'center'       => ['lat' => (float)$c['center_lat'], 'lng' => (float)$c['center_lng']],
            'radius_km'    => (int)$c['radius_km'],
            'member_count' => Chapters::memberCount((int)$c['id']),
            'is_member'    => Chapters::isMember((int)$c['id'], $uuid),
        ];
    }
    profile_app_json(200, ['chapters' => $out]);
}

$ch = Chapters::bySlug($slug);
if (!$ch) profile_app_json(404, ['error' => 'no_such_chapter']);

$cid  = (int)$ch['id'];
$room = ChapterChat::room($cid);

profile_app_json(200, [
    'chapter' => [
        'slug'        => $ch['slug'],
        'name'        => $ch['name'],
        'description' => $ch['description'],
        'center'      => ['lat' => (float)$ch['center_lat'], 'lng' => (float)$ch['center_lng']],
        'radius_km'   => (int)$ch['radius_km'],
        'radius_mi'   => Chapters::radiusMi($ch),   // the ONE conversion site
    ],
    // The count comes from chapter_member, never from the map: a member with no location,
    // or with location hidden, has no pin but still counts.
    'member_count' => Chapters::memberCount($cid),
    'is_member'    => Chapters::isMember($cid, $uuid),
    'can_post'     => ChapterChat::canPost($cid, $uuid),
    'room'         => $room ? ['uuid' => $room['uuid'], 'last_message_at' => $room['last_message_at']] : null,
    // Badge from the read-state WATERMARK. No notification rows exist for this, by design.
    'unread'       => $room ? ChapterChat::unreadCount((int)$room['id'], $uuid) : 0,
    'muted'        => $room ? ChapterChat::isMuted((int)$room['id'], $uuid) : false,
]);
