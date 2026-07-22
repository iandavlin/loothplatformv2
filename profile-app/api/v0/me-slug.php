<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Slug;

/**
 * Read-only display of the member's @username (handle).
 *
 *   GET   /profile-api/v0/me/slug   → display the member's current handle
 *         200 {slug, min, max}
 *
 * PRODUCT RULING (Ian, 2026-07-19): Handles are read-only and derived from the profile
 * name (IG-style). Members no longer edit their handles. The @handle displays on the
 * profile; mention resolution uses the current handle for the mentioned user. See
 * Provision::maybeSyncSlugFromName in profile-app/src/Provision.php for the
 * name-change sync behavior. Mentions stay uuid-anchored so rename never breaks
 * past mentions.
 *
 * Rules live in src/Slug.php — see that file for WHY retired handles are never
 * re-issued (link-hijacking prevention) and why numeric handles are banned.
 */

if ($_SERVER[‘REQUEST_METHOD’] !== ‘GET’) {
    profile_app_json(405, [‘error’ => ‘method_not_allowed’]);
}

$user   = Auth::requireUser();
$userId = (int) $user[‘id’];

// Return the member’s current handle.
$st = Db::pg()->prepare(‘SELECT slug FROM users WHERE id = :u’);
$st->execute([‘:u’ => $userId]);
profile_app_json(200, [
    ‘slug’ => $st->fetchColumn() ?: null,
    ‘min’  => Slug::MIN_LEN,
    ‘max’  => Slug::MAX_LEN,
]);
