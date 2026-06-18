<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';

/**
 * Generic catalog-chip block selection (skills / services / instruments / music). Which block
 * is set by the `block` query param (nginx rewrite from /me/<block>).
 *   GET → the assembled block (Block::loadCatalogBlock).
 *   PUT → { ids: [catalog_id, …] }  (also accepts { items:[{id}] }) → replace the selection.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

$user  = Auth::requireUser();
$uid   = (int) $user['id'];
$block = (string) ($_GET['block'] ?? '');
if (!isset(Block::CATALOG_BLOCKS[$block])) profile_app_json(404, ['error' => 'unknown_block']);

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    profile_app_json(200, Block::loadCatalogBlock($uid, $block));
}
if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$hasVis = array_key_exists('visibility', $in);
$hasIds = (isset($in['ids']) && is_array($in['ids'])) || (isset($in['items']) && is_array($in['items']));
if (!$hasVis && !$hasIds) profile_app_json(400, ['error' => 'ids_or_visibility_required']);

// Block-level privacy chip (pmp) → profile_sections key=<block> visibility.
if ($hasVis) {
    if (Block::visFromInput($in['visibility']) === null) {
        profile_app_json(400, ['error' => 'invalid_visibility', 'allowed' => ['public', 'member', 'private']]);
    }
    Block::saveBlockVisibility($uid, $block, $in['visibility'], 20);
}

// Selection replace (the picker).
if ($hasIds) {
    $ids = isset($in['ids']) && is_array($in['ids'])
        ? $in['ids']
        : array_map(static fn($x) => is_array($x) ? ($x['id'] ?? 0) : $x, $in['items']);
    Block::saveCatalogSelection($uid, $block, $ids);
}

profile_app_json(200, ['ok' => true, 'block' => Block::loadCatalogBlock($uid, $block)]);
