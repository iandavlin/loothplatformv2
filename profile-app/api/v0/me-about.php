<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in   = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$pg = Db::pg();
$row = $pg->prepare("SELECT data, visibility FROM profile_sections WHERE user_id=:u AND key='about'");
$row->execute([':u' => (int)$user['id']]);
$existing = $row->fetch();

$data = $existing ? (json_decode($existing['data'], true) ?: []) : [];
$vis  = $existing ? $existing['visibility'] : 'members';

if (array_key_exists('text', $in)) {
    if (!is_string($in['text'])) profile_app_json(400, ['error' => 'text_must_be_string']);
    if (strlen($in['text']) > 8000) profile_app_json(400, ['error' => 'text_too_long']);
    $data['text'] = $in['text'];
}
if (array_key_exists('visibility', $in)) {
    if (!in_array($in['visibility'], Profile::VIS_VALUES, true)) {
        profile_app_json(400, ['error' => 'invalid_visibility']);
    }
    $vis = $in['visibility'];
}

$pg->prepare("
    INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
    VALUES (:u, 'about', :v, :d::jsonb, 10)
    ON CONFLICT (user_id, key) DO UPDATE
       SET visibility = EXCLUDED.visibility,
           data       = EXCLUDED.data,
           updated_at = now()
")->execute([
    ':u' => (int)$user['id'],
    ':v' => $vis,
    ':d' => json_encode($data, JSON_UNESCAPED_SLASHES),
]);

profile_app_json(200, ['ok' => true, 'visibility' => $vis, 'data' => $data]);
