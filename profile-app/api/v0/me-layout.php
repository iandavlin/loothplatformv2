<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * Composable profile layout — the owner's block order (header pinned, excluded).
 *
 *   GET → { layout: [keys in order], blocks: <LAYOUT_BLOCKS registry> }
 *         (registry powers the caddy: label + removable per available block)
 *   PUT → { order: ["about","craft",…] } → persist; validated ⊂ registry, de-duped.
 *
 * Order/presence only — never touches block data (sections / users columns / Connections),
 * so reorder + (later) add/remove are non-destructive.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

$user   = Auth::requireUser();
$uid    = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    profile_app_json(200, ['layout' => Block::profileLayout($uid), 'blocks' => Block::LAYOUT_BLOCKS]);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['order']) || !is_array($in['order'])) {
    profile_app_json(400, ['error' => 'order_required']);
}

$layout = Block::saveProfileLayout($uid, $in['order']);
profile_app_json(200, ['ok' => true, 'layout' => $layout]);
