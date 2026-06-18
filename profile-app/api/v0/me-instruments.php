<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in   = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !isset($in['items']) || !is_array($in['items'])) {
    profile_app_json(400, ['error' => 'items_required']);
}
if (count($in['items']) > Profile::INSTRUMENTS_MAX) {
    profile_app_json(400, ['error' => 'too_many', 'max' => Profile::INSTRUMENTS_MAX]);
}

$pg = Db::pg();
$validIds = array_map('intval', $pg->query("SELECT id FROM instrument_catalog WHERE active=true")->fetchAll(PDO::FETCH_COLUMN));
$validSet = array_flip($validIds);

$clean = [];
foreach ($in['items'] as $i => $item) {
    $id = isset($item['instrument_id']) ? (int)$item['instrument_id'] : 0;
    if (!isset($validSet[$id])) profile_app_json(400, ['error' => "unknown_instrument_at_$i"]);
    $clean[] = ['id' => $id, 'sort_order' => (int)($item['sort_order'] ?? $i)];
}

$pg->beginTransaction();
try {
    $pg->prepare('DELETE FROM profile_instruments WHERE user_id=:u')->execute([':u' => (int)$user['id']]);
    $ins = $pg->prepare('INSERT INTO profile_instruments(user_id, instrument_id, sort_order) VALUES(:u,:i,:s) ON CONFLICT DO NOTHING');
    foreach ($clean as $c) $ins->execute([':u' => (int)$user['id'], ':i' => $c['id'], ':s' => $c['sort_order']]);
    $pg->commit();
} catch (Throwable $e) { $pg->rollBack(); profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]); }

profile_app_json(200, ['ok' => true, 'count' => count($clean)]);
