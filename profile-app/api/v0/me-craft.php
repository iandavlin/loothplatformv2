<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * craft block — instruments + skills + highlights (the search-fuel), one
 * block-level visibility. Data edits stay in me-instruments / me-skills /
 * me-highlights; this owns the assembled READ + the block VIS.
 *
 *   GET   → the assembled craft block (Block::loadCraft), vis normalized to 'member'.
 *   PATCH → { visibility: 'public'|'member'|'private' }  (the block's pmp / ceiling input)
 *
 * NOTE TO COORDINATOR: this is a NEW endpoint — add the nginx route + allowlist:
 *   rewrite "^/profile-api/v0/me/craft/?$"  /profile-api/v0/me-craft.php  last;
 *   …and add `me-craft` to the allowlist regex in strangler-profile-app.conf (~L147).
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $block = Block::loadCraft((int)$user['id']);
    if ($block === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, $block);
}

if ($method !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !array_key_exists('visibility', $in)) {
    profile_app_json(400, ['error' => 'visibility_required']);
}

$vis = Block::saveBlockVisibility((int)$user['id'], Block::CRAFT_KEY, $in['visibility'], 20);
if ($vis === null) {
    profile_app_json(400, ['error' => 'invalid_visibility', 'allowed' => ['public', 'member', 'private']]);
}

profile_app_json(200, ['ok' => true, 'craft' => Block::loadCraft((int)$user['id'])]);
