<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';

/**
 * Generic practice storefront block endpoint (owner-only writes).
 *   GET  /me/practice-block?practice=<id>&block=<name> -> the assembled block.
 *   PUT  same query, JSON body per block -> persist, return the post-save shape.
 *
 * Data lives in the OWNER's profile_sections under Block::practiceBlockKey(
 * <block>, <id>) — no new schema. One route serves the whole JSONB-blob family;
 * each `block` dispatches to its generalized Block loader/saver.
 *
 * NOTE TO COORDINATOR (ubuntu/root): this file needs one nginx rewrite + one
 * allowlist entry in strangler-profile-app.conf:
 *   rewrite "^/profile-api/v0/me/practice-block/?$" /profile-api/v0/me-practice-block.php last;
 * and add `me-practice-block` to the me-* allowlist regex. No DB migration.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

const PRACTICE_BLOCKS = ['dropoffs', 'location', 'hours', 'links'];

$user   = Auth::requireUser();
$uid    = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

$pid   = (int) ($_GET['practice'] ?? 0);
$block = (string) ($_GET['block'] ?? '');
if ($pid <= 0)                              profile_app_json(400, ['error' => 'practice_required']);
if (!in_array($block, PRACTICE_BLOCKS, true)) profile_app_json(400, ['error' => 'unknown_block', 'allowed' => PRACTICE_BLOCKS]);

// Owner-only: writes (and reads) target the owner's namespaced rows.
if (!Block::isPracticeOwner($pid, $uid)) profile_app_json(403, ['error' => 'not_practice_owner']);
$ownerId = Block::practiceOwnerId($pid);
if ($ownerId === null) profile_app_json(404, ['error' => 'practice_not_found']);

$key = Block::practiceBlockKey($block, $pid);

if ($method === 'GET') {
    if ($block === 'dropoffs') profile_app_json(200, Block::loadDropoffs($ownerId, $key) ?? ['error' => 'not_found']);
    if ($block === 'location') profile_app_json(200, Block::loadPracticeLocation($ownerId, $pid));
    if ($block === 'hours')    profile_app_json(200, Block::loadPracticeHours($ownerId, $pid));
    if ($block === 'links')    profile_app_json(200, Block::loadPracticeLinks($ownerId, $pid));
    profile_app_json(400, ['error' => 'unknown_block']);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

if ($block === 'dropoffs') {
    // items only replaced when present; a vis-only PUT keeps the list.
    $items = (array_key_exists('items', $in) && is_array($in['items']))
        ? $in['items']
        : (Block::loadDropoffs($ownerId, $key)['items'] ?? []);
    $vis = array_key_exists('visibility', $in) ? $in['visibility'] : null;
    $res = Block::saveDropoffs($ownerId, $items, $vis, $key);
    if ($res === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, ['ok' => true, 'dropoffs' => $res]);
}

if ($block === 'location') {
    profile_app_json(200, ['ok' => true, 'location' => Block::savePracticeLocation($ownerId, $pid, $in)]);
}

if ($block === 'hours') {
    profile_app_json(200, ['ok' => true, 'hours' => Block::savePracticeHours($ownerId, $pid, $in)]);
}

if ($block === 'links') {
    $items = (array_key_exists('items', $in) && is_array($in['items']))
        ? $in['items']
        : (Block::loadPracticeLinks($ownerId, $pid)['items'] ?? []);
    $vis = array_key_exists('visibility', $in) ? $in['visibility'] : null;
    profile_app_json(200, ['ok' => true, 'links' => Block::savePracticeLinks($ownerId, $pid, $items, $vis)]);
}

profile_app_json(400, ['error' => 'unknown_block']);
