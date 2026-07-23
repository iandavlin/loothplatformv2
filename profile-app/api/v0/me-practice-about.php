<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * practice (business) About block — inline text + visibility for a /p/ page.
 * Owner-only. Block data lives in the OWNER's profile_sections under the
 * practice-namespaced key 'about:p<id>' (same no-migration convention as
 * practice-header:<id>).
 *
 *   PATCH ?practice=<id> { text?: string, visibility?: 'public'|'members'|'private' }
 *
 * NOTE TO COORDINATOR: NEW endpoint — add the nginx route + allowlist (mirror me-practice-header):
 *   rewrite "^/profile-api/v0/me/practice-about/?$" /profile-api/v0/me-practice-about.php last;
 *   …and add `me-practice-about` to the allowlist regex in strangler-profile-app.conf.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

$practiceId = (isset($_GET['practice']) && ctype_digit((string) $_GET['practice'])) ? (int) $_GET['practice'] : 0;
if ($practiceId <= 0) profile_app_json(400, ['error' => 'practice_required']);

if (!Block::isPracticeOwner($practiceId, (int) $user['id'])) {
    profile_app_json(403, ['error' => 'not_practice_owner']);
}
if ($method !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$ownerId = Block::practiceOwnerId($practiceId);
if ($ownerId === null) profile_app_json(404, ['error' => 'practice_not_found']);
$key = Block::practiceBlockKey('about', $practiceId);

$pg  = Db::pg();
$row = $pg->prepare("SELECT data, visibility FROM profile_sections WHERE user_id=:u AND key=:k");
$row->execute([':u' => $ownerId, ':k' => $key]);
$existing = $row->fetch();

$data = $existing ? (json_decode($existing['data'], true) ?: []) : [];
$vis  = $existing ? $existing['visibility'] : 'members';

// Rich-text body (server-side sanitize; the editor is never trusted). data.text is
// the tag-stripped projection, derived here. A practice isn't a WP author, so there
// is no author_about mirror — this is the /p/ storefront About only.
if (array_key_exists('html', $in)) {
    if (!is_string($in['html'])) profile_app_json(400, ['error' => 'html_must_be_string']);
    if (strlen($in['html']) > Block::ABOUT_HTML_MAX) profile_app_json(400, ['error' => 'html_too_long']);
    $clean = Block::sanitizeRichHtml($in['html']);
    $plain = Block::htmlToPlainText($clean);
    if ($plain === '') $clean = '';   // whitespace-only body → truly empty (shows placeholder)
    $data['html'] = $clean;
    $data['text'] = $plain;
} elseif (array_key_exists('text', $in)) {
    if (!is_string($in['text'])) profile_app_json(400, ['error' => 'text_must_be_string']);
    if (strlen($in['text']) > Block::ABOUT_TEXT_MAX) profile_app_json(400, ['error' => 'text_too_long']);
    $data['text'] = $in['text'];
    unset($data['html']);
}
if (array_key_exists('visibility', $in)) {
    if (!in_array($in['visibility'], Profile::VIS_VALUES, true)) {
        profile_app_json(400, ['error' => 'invalid_visibility']);
    }
    $vis = $in['visibility'];
}

$pg->prepare("
    INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
    VALUES (:u, :k, :v, :d::jsonb, 10)
    ON CONFLICT (user_id, key) DO UPDATE
       SET visibility = EXCLUDED.visibility,
           data       = EXCLUDED.data,
           updated_at = now()
")->execute([
    ':u' => $ownerId,
    ':k' => $key,
    ':v' => $vis,
    ':d' => json_encode($data, JSON_UNESCAPED_SLASHES),
]);

profile_app_json(200, ['ok' => true, 'visibility' => $vis, 'data' => $data]);
