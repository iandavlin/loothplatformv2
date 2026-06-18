<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['items']) || !is_array($in['items'])) {
    profile_app_json(400, ['error' => 'items_required']);
}
if (count($in['items']) > Profile::SKILLS_MAX) {
    profile_app_json(400, ['error' => 'too_many', 'max' => Profile::SKILLS_MAX]);
}

$pg = Db::pg();
$validIds = array_flip(array_map('intval', $pg->query("SELECT id FROM skill_catalog WHERE active=true")->fetchAll(PDO::FETCH_COLUMN)));

$clean = [];
foreach ($in['items'] as $i => $item) {
    $id = isset($item['skill_id']) ? (int)$item['skill_id'] : 0;
    if (!isset($validIds[$id])) profile_app_json(400, ['error' => "unknown_skill_at_$i"]);
    $note = isset($item['note']) ? (string)$item['note'] : null;
    if ($note !== null) {
        $note = trim($note);
        if (strlen($note) > Profile::SKILL_NOTE_MAX) profile_app_json(400, ['error' => "note_too_long_at_$i"]);
        if ($note === '') $note = null;
    }
    $clean[] = ['id' => $id, 'note' => $note, 'sort_order' => (int)($item['sort_order'] ?? $i)];
}

$pg->beginTransaction();
try {
    $pg->prepare('DELETE FROM profile_skills WHERE user_id=:u')->execute([':u' => (int)$user['id']]);
    $ins = $pg->prepare('INSERT INTO profile_skills(user_id, skill_id, note, sort_order) VALUES(:u,:k,:n,:s) ON CONFLICT DO NOTHING');
    foreach ($clean as $c) $ins->execute([':u' => (int)$user['id'], ':k' => $c['id'], ':n' => $c['note'], ':s' => $c['sort_order']]);
    $pg->commit();
} catch (Throwable $e) { $pg->rollBack(); profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]); }

profile_app_json(200, ['ok' => true, 'count' => count($clean)]);
