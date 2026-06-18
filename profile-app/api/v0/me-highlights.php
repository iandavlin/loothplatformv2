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

if (count($in['items']) > Profile::HIGHLIGHTS_MAX) {
    profile_app_json(400, ['error' => 'too_many', 'max' => Profile::HIGHLIGHTS_MAX]);
}

$pg = Db::pg();
$instIds  = array_flip(array_map('intval', $pg->query("SELECT id FROM instrument_catalog")->fetchAll(PDO::FETCH_COLUMN)));
$skillIds = array_flip(array_map('intval', $pg->query("SELECT id FROM skill_catalog")->fetchAll(PDO::FETCH_COLUMN)));

$clean = [];
$seen = [];
foreach ($in['items'] as $i => $item) {
    $kind = $item['kind'] ?? '';
    $ref  = (int)($item['ref_id'] ?? 0);
    if (!in_array($kind, Profile::HIGHLIGHT_KINDS, true)) profile_app_json(400, ['error' => "bad_kind_at_$i"]);
    $valid = $kind === 'instrument' ? $instIds : $skillIds;
    if (!isset($valid[$ref])) profile_app_json(400, ['error' => "unknown_ref_at_$i"]);
    $key = $kind . ':' . $ref;
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $clean[] = ['kind' => $kind, 'ref' => $ref, 'sort_order' => (int)($item['sort_order'] ?? $i)];
}

$pg->beginTransaction();
try {
    $pg->prepare('DELETE FROM profile_highlights WHERE user_id=:u')->execute([':u' => (int)$user['id']]);
    $ins = $pg->prepare('INSERT INTO profile_highlights(user_id, kind, ref_id, sort_order) VALUES(:u,:k,:r,:s)');
    foreach ($clean as $c) $ins->execute([':u' => (int)$user['id'], ':k' => $c['kind'], ':r' => $c['ref'], ':s' => $c['sort_order']]);
    $pg->commit();
} catch (Throwable $e) { $pg->rollBack(); profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]); }

profile_app_json(200, ['ok' => true, 'count' => count($clean)]);
