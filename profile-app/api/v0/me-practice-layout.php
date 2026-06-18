<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * practice (business) layout — the owner's storefront block order for a /p/ page.
 * The practice analogue of me-layout. Owner-only (the /me editing context).
 *
 *   GET  ?practice=<id> → { layout:[keys in order], blocks:<PRACTICE_LAYOUT_BLOCKS> }
 *   PUT  ?practice=<id> { order:[...] } → persist; validated subset of registry, de-duped.
 *
 * Order/presence only — never touches block data, so reorder + add/remove are
 * non-destructive.
 *
 * NOTE TO COORDINATOR: NEW endpoint — add the nginx route + allowlist (mirror me-practice-header):
 *   rewrite "^/profile-api/v0/me/practice-layout/?$" /profile-api/v0/me-practice-layout.php last;
 *   …and add `me-practice-layout` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

$practiceId = (isset($_GET['practice']) && ctype_digit((string) $_GET['practice'])) ? (int) $_GET['practice'] : 0;
if ($practiceId <= 0) profile_app_json(400, ['error' => 'practice_required']);

if (!Block::isPracticeOwner($practiceId, (int) $user['id'])) {
    profile_app_json(403, ['error' => 'not_practice_owner']);
}

if ($method === 'GET') {
    profile_app_json(200, ['layout' => Block::practiceLayout($practiceId), 'blocks' => Block::PRACTICE_LAYOUT_BLOCKS]);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['order']) || !is_array($in['order'])) {
    profile_app_json(400, ['error' => 'order_required']);
}

$layout = Block::savePracticeLayout($practiceId, $in['order']);
profile_app_json(200, ['ok' => true, 'layout' => $layout]);
