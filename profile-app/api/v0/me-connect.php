<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * connect block — the owner's connections surface (count + preview + pending inbox),
 * one block-level visibility. The connection MUTATIONS (request/accept/decline/block)
 * live in me-connections.php; this owns the assembled block READ + the block VIS.
 *
 *   GET   → the assembled connect block for the owner (Block::loadConnect), vis 'member'.
 *   PATCH → { visibility: 'public'|'member'|'private' }  (the block's pmp / ceiling input)
 *
 * NOTE TO COORDINATOR: NEW endpoint — nginx route + allowlist (mirror me-craft):
 *   rewrite "^/profile-api/v0/me/connect/?$"  /profile-api/v0/me-connect.php  last;
 *   (NB: keep it ABOVE the broader /me/connections rewrite so the exact /me/connect wins.)
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];
$uid    = (int) $user['id'];

if ($method === 'GET') {
    $block = Block::loadConnect($uid, $uid);   // owner viewing own → includes pending counts
    if ($block === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, $block);
}

if ($method !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !array_key_exists('visibility', $in)) {
    profile_app_json(400, ['error' => 'visibility_required']);
}

$vis = Block::saveBlockVisibility($uid, Block::CONNECT_KEY, $in['visibility'], 25);
if ($vis === null) {
    profile_app_json(400, ['error' => 'invalid_visibility', 'allowed' => ['public', 'member', 'private']]);
}

profile_app_json(200, ['ok' => true, 'connect' => Block::loadConnect($uid, $uid)]);
