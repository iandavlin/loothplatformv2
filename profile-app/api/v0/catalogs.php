<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Auth.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Practice.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Practice;

$kind   = $_GET['kind'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$pg     = Db::pg();

// Admin-managed chip catalogs (add a not-found item / deactivate one) — front-end picker, admin only.
const LG_ADMIN_CATALOGS = [
    'skills' => 'skill_catalog', 'services' => 'service_catalog',
    'instruments' => 'instrument_catalog', 'genres' => 'genre_catalog',
];

if ($method === 'POST' || $method === 'DELETE') {
    if (!Auth::isAdmin()) profile_app_json(403, ['error' => 'admin_only']);
    $table = LG_ADMIN_CATALOGS[$kind] ?? null;                  // whitelist → safe identifier
    if ($table === null) profile_app_json(404, ['error' => 'unknown_catalog']);

    if ($method === 'POST') {                                   // add (or reactivate) a catalog row
        $in   = json_decode(file_get_contents('php://input') ?: '', true);
        $name = is_array($in) ? trim((string) ($in['name'] ?? '')) : '';
        if ($name === '' || mb_strlen($name) > 80) profile_app_json(400, ['error' => 'name_required']);
        $slug = Practice::slugify($name) ?: substr(md5($name), 0, 12);
        $st = $pg->prepare("INSERT INTO $table (slug, name, active, sort_order) VALUES (:s, :n, true, 50)
                            ON CONFLICT (slug) DO UPDATE SET active = true, name = EXCLUDED.name
                            RETURNING id, slug, name");
        $st->execute([':s' => $slug, ':n' => $name]);
        $row = $st->fetch();
        profile_app_json(200, ['ok' => true, 'item' => ['id' => (int) $row['id'], 'slug' => $row['slug'], 'name' => $row['name']]]);
    }

    $id = (int) ($_GET['id'] ?? 0);                             // DELETE → soft-deactivate by id
    if ($id < 1) profile_app_json(400, ['error' => 'id_required']);
    $pg->prepare("UPDATE $table SET active = false WHERE id = :id")->execute([':id' => $id]);
    profile_app_json(200, ['ok' => true, 'deactivated' => $id]);
}

if ($method !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

header('Cache-Control: public, max-age=300');

switch ($kind) {
    case 'services':
        $rows = $pg->query("SELECT id, slug, name, category, sort_order
                            FROM service_catalog WHERE active=true
                            ORDER BY category, sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'category' => $r['category'],
        ], $rows)]);
    case 'genres':
        $rows = $pg->query("SELECT id, slug, name, sort_order
                            FROM genre_catalog WHERE active=true
                            ORDER BY sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
        ], $rows)]);
    case 'instruments':
        $rows = $pg->query("SELECT id, slug, name, type, subtype, sort_order
                            FROM instrument_catalog WHERE active=true
                            ORDER BY sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
            'type' => $r['type'], 'subtype' => $r['subtype'],
        ], $rows)]);
    case 'skills':
        $rows = $pg->query("SELECT id, slug, name, category, sort_order
                            FROM skill_catalog WHERE active=true
                            ORDER BY category, sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
            'category' => $r['category'],
        ], $rows)]);
    case 'scenes':
        $rows = $pg->query("SELECT slug, name FROM scene_tags WHERE active=true
                            ORDER BY sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => $rows]);
    case 'credentials':
        $q = $_GET['q'] ?? '';
        $params = [];
        $where = 'WHERE active=true';
        if (is_string($q) && trim($q) !== '') {
            $where .= ' AND (issuer ILIKE :q OR program ILIKE :q OR slug ILIKE :q)';
            $params[':q'] = '%' . trim($q) . '%';
        }
        $stmt = $pg->prepare("SELECT id, slug, category, issuer, program, logo_url
                              FROM credential_catalog $where
                              ORDER BY category, issuer, program LIMIT 50");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'category' => $r['category'],
            'issuer' => $r['issuer'], 'program' => $r['program'], 'logo_url' => $r['logo_url'],
        ], $rows)]);
    default:
        profile_app_json(404, ['error' => 'unknown_catalog']);
}
