<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['slugs']) || !is_array($in['slugs'])) {
    profile_app_json(400, ['error' => 'slugs_required']);
}
if (count($in['slugs']) > Profile::SCENES_MAX) {
    profile_app_json(400, ['error' => 'too_many', 'max' => Profile::SCENES_MAX]);
}

$pg = Db::pg();
$valid = array_flip($pg->query("SELECT slug FROM scene_tags WHERE active=true")->fetchAll(PDO::FETCH_COLUMN));

$clean = [];
foreach ($in['slugs'] as $i => $slug) {
    if (!is_string($slug) || !isset($valid[$slug])) profile_app_json(400, ['error' => "unknown_scene_at_$i"]);
    $clean[$slug] = true;
}

$pg->beginTransaction();
try {
    $pg->prepare('DELETE FROM profile_scenes WHERE user_id=:u')->execute([':u' => (int)$user['id']]);
    $ins = $pg->prepare('INSERT INTO profile_scenes(user_id, scene_slug) VALUES(:u,:s) ON CONFLICT DO NOTHING');
    foreach (array_keys($clean) as $slug) $ins->execute([':u' => (int)$user['id'], ':s' => $slug]);
    $pg->commit();
} catch (Throwable $e) { $pg->rollBack(); profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]); }

profile_app_json(200, ['ok' => true, 'slugs' => array_keys($clean)]);
