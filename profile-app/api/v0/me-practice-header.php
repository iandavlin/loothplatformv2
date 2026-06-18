<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * practice-header block — the required header for a /p/ practice page (the
 * practice equivalent of me-header). Owner-only (the /me editing context).
 *
 *   GET   ?practice=<id> | ?slug=<slug>  → the assembled practice-header block.
 *   PATCH ?practice=<id> | ?slug=<slug>  { visibility: 'public'|'member'|'private' }
 *         sets the practice header's ceiling vis (header private → whole practice private).
 *
 * NOTE TO COORDINATOR: NEW endpoint — add the nginx route + allowlist (mirror me-craft):
 *   rewrite "^/profile-api/v0/me/practice-header/?$"  /profile-api/v0/me-practice-header.php  last;
 *   …and add `me-practice-header` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

// Resolve the practice from ?practice=<id> or ?slug=<slug>.
$practiceId = 0;
if (isset($_GET['practice']) && ctype_digit((string) $_GET['practice'])) {
    $practiceId = (int) $_GET['practice'];
} elseif (!empty($_GET['slug'])) {
    $s = Db::pg()->prepare("SELECT id FROM practices WHERE slug = :s AND archived_at IS NULL");
    $s->execute([':s' => (string) $_GET['slug']]);
    $practiceId = (int) $s->fetchColumn();
}
if ($practiceId <= 0) profile_app_json(400, ['error' => 'practice_required']);

// Owner-only editing context (right status code before any work).
if (!Block::isPracticeOwner($practiceId, (int) $user['id'])) {
    profile_app_json(403, ['error' => 'not_practice_owner']);
}

if ($method === 'GET') {
    $block = Block::loadPracticeHeader($practiceId);
    if ($block === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, $block);
}

if ($method !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !array_key_exists('visibility', $in)) {
    profile_app_json(400, ['error' => 'visibility_required']);
}

$result = Block::savePracticeHeader($practiceId, (int) $user['id'], ['visibility' => $in['visibility']]);
if ($result === null) {
    profile_app_json(400, ['error' => 'invalid_visibility', 'allowed' => ['public', 'member', 'private']]);
}

profile_app_json(200, ['ok' => true, 'practice_header' => $result]);
