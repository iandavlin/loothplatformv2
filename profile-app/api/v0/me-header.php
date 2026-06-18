<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * profile-header (identity) block — the header-specific editable bits:
 *   • at_a_glance  — the single-source author bio (also mirrored to WP
 *                    `description` so the "about author" box stays in sync).
 *   • visibility   — the header's OWN vis = the profile's ceiling (section cap).
 * display_name → me-name; avatar → avatar endpoint; socials → me-socials.
 *
 *   GET   → the assembled header block (Block::loadHeader), vis normalized to 'member'.
 *   PATCH → { at_a_glance?: string|null, visibility?: 'public'|'member'|'private' }
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Cache;
use Looth\ProfileApp\Db;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $block = Block::loadHeader((int)$user['id']);
    if ($block === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, $block);
}

if ($method !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'bad_json']);

$fields = [];

if (array_key_exists('at_a_glance', $in)) {
    $bio = $in['at_a_glance'];
    if ($bio !== null && !is_string($bio)) profile_app_json(400, ['error' => 'at_a_glance_must_be_string']);
    if (is_string($bio) && mb_strlen($bio) > 500) profile_app_json(400, ['error' => 'at_a_glance_too_long']);
    $fields['at_a_glance'] = ($bio === null) ? null : trim($bio);
}

if (array_key_exists('visibility', $in)) {
    if (Block::visFromInput($in['visibility']) === null) {
        profile_app_json(400, ['error' => 'invalid_visibility', 'allowed' => ['public', 'member', 'private']]);
    }
    $fields['visibility'] = $in['visibility'];
}

if (!$fields) profile_app_json(400, ['error' => 'nothing_to_update']);

$result = Block::saveHeader((int)$user['id'], $fields);

// ONE DIAL (Ian 6/12): the profile-visibility chip IS the master switch.
// 'private' = owner-only EVERYWHERE (page, directory, map, search, gated
// files — enforced via Visibility); 'public'/'members' = listed, with the
// chip acting as the section ceiling as before. Written together so the
// chip and the master column can never disagree.
if (array_key_exists('visibility', $fields)) {
    Db::pg()->prepare("UPDATE users SET profile_visibility = :pv, updated_at = now() WHERE id = :i")
        ->execute([
            ':pv' => Block::visFromInput($fields['visibility']) === 'private' ? 'private' : 'public',
            ':i'  => (int)$user['id'],
        ]);
}

// Single-source author bio: mirror at_a_glance → WP user `description` (the
// "about author" box). Best-effort — never blocks the API (mirrors me-name).
$wpId = 0;
try {
    $pg = Db::pg();
    $bridge = $pg->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
    $bridge->execute([':u' => (int)$user['id']]);
    $wpId = (int) $bridge->fetchColumn();
    if ($wpId && array_key_exists('at_a_glance', $fields)) {
        $un = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
        $my = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
            $un, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $val = (string)($fields['at_a_glance'] ?? '');
        // wp_usermeta has no unique (user_id, meta_key) — select then update/insert.
        $sel = $my->prepare("SELECT umeta_id FROM wp_usermeta WHERE user_id = ? AND meta_key = 'description' LIMIT 1");
        $sel->execute([$wpId]);
        $umetaId = $sel->fetchColumn();
        if ($umetaId) {
            $my->prepare('UPDATE wp_usermeta SET meta_value = ? WHERE umeta_id = ?')->execute([$val, $umetaId]);
        } else {
            $my->prepare("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, 'description', ?)")
               ->execute([$wpId, $val]);
        }
    }
} catch (Throwable $e) {
    error_log('[me-header] wp description mirror failed: ' . $e->getMessage());
}

// at_a_glance + ceiling are part of the author-identity card — self-purge whoami.
if ($wpId > 0) Cache::purgeWhoami($wpId);

profile_app_json(200, ['ok' => true, 'header' => $result]);
