<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Practice;

$user   = Auth::requireUser();
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];
$uuid   = $_GET['uuid'] ?? null;

if ($method === 'GET') {
    $out = [];
    foreach (Practice::forUser($userId) as $p) {
        $out[] = [
            'uuid'     => $p['uuid'],
            'slug'     => $p['slug'],
            'name'     => $p['name'],
            'tagline'  => $p['tagline'],
            'about'    => $p['about'],
            'website'  => $p['website'],
            'avatar_url'    => $p['avatar_url'],
            'location_text' => $p['location_text'],
            'location_visibility' => $p['location_visibility'],
            'role'     => $p['role'],
            'public_url' => '/p/' . $p['slug'],
        ];
    }
    profile_app_json(200, ['ok' => true, 'practices' => $out]);
}

if ($method === 'POST') {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) profile_app_json(400, ['error' => 'bad_json']);
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) profile_app_json(400, ['error' => 'invalid_name']);

    $tagline = isset($in['tagline']) ? trim((string)$in['tagline']) : null;
    $locText = isset($in['location_text']) ? trim((string)$in['location_text']) : null;
    $lat = isset($in['lat']) && $in['lat'] !== '' ? (float)$in['lat'] : null;
    $lng = isset($in['lng']) && $in['lng'] !== '' ? (float)$in['lng'] : null;
    $locVis = $in['location_visibility'] ?? 'public';
    if (!in_array($locVis, Practice::LOCATION_VIS_VALUES, true)) profile_app_json(400, ['error' => 'invalid_visibility']);

    $slug = Practice::uniqueSlug($name);
    $pg = Db::pg();
    $pg->beginTransaction();
    try {
        $s = $pg->prepare('
            INSERT INTO practices (slug, name, tagline, location_text, lat, lng, location_visibility, created_by)
            VALUES (:slug, :name, :tag, :loc, :lat, :lng, :vis, :uid)
            RETURNING id, uuid
        ');
        $s->execute([
            ':slug' => $slug, ':name' => $name, ':tag' => $tagline,
            ':loc'  => $locText, ':lat' => $lat, ':lng' => $lng,
            ':vis'  => $locVis, ':uid' => $userId,
        ]);
        $row = $s->fetch();
        $practiceId = (int)$row['id'];
        $pg->prepare('INSERT INTO practice_members (practice_id, user_id, role) VALUES (:p, :u, \'owner\')')
           ->execute([':p' => $practiceId, ':u' => $userId]);
        $pg->commit();
    } catch (Throwable $e) {
        $pg->rollBack();
        profile_app_json(500, ['error' => 'db_error', 'message' => $e->getMessage()]);
    }
    profile_app_json(201, ['ok' => true, 'uuid' => $row['uuid'], 'slug' => $slug, 'public_url' => '/p/' . $slug]);
}

// Beyond here, uuid in URL is required.
if (!$uuid || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
    profile_app_json(400, ['error' => 'uuid_required']);
}
$practice = Practice::loadByUuid($uuid);
if (!$practice) profile_app_json(404, ['error' => 'not_found']);
$role = Practice::userRole($practice['id'], $userId);
if ($role === null) profile_app_json(403, ['error' => 'not_a_member']);

if ($method === 'PATCH') {
    if ($role !== 'owner') profile_app_json(403, ['error' => 'owner_only']);
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) profile_app_json(400, ['error' => 'bad_json']);

    $sets = [];
    $params = [':id' => $practice['id']];
    foreach (['name','tagline','about','website','location_text','avatar_url'] as $f) {
        if (array_key_exists($f, $in)) {
            $v = $in[$f];
            if ($v !== null) $v = trim((string)$v);
            if ($f === 'name' && ($v === null || $v === '' || mb_strlen($v) > 160)) {
                profile_app_json(400, ['error' => 'invalid_name']);
            }
            $sets[] = "$f = :$f";
            $params[":$f"] = $v === '' ? null : $v;
        }
    }
    foreach (['lat','lng'] as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "$f = :$f";
            $params[":$f"] = ($in[$f] === '' || $in[$f] === null) ? null : (float)$in[$f];
        }
    }
    if (array_key_exists('location_visibility', $in)) {
        if (!in_array($in['location_visibility'], Practice::LOCATION_VIS_VALUES, true)) {
            profile_app_json(400, ['error' => 'invalid_visibility']);
        }
        $sets[] = "location_visibility = :location_visibility";
        $params[':location_visibility'] = $in['location_visibility'];
    }
    if (!$sets) profile_app_json(400, ['error' => 'no_fields']);

    // If name changed, re-slug? Keep slugs stable for now (URL is canonical).
    Db::pg()->prepare('UPDATE practices SET ' . implode(', ', $sets) . ' WHERE id = :id')
        ->execute($params);
    profile_app_json(200, ['ok' => true]);
}

if ($method === 'DELETE') {
    // "Leave" — remove the user from the roster. If they were the last member,
    // the practice persists as an orphan (slice 3.5 may add cleanup).
    Db::pg()->prepare('DELETE FROM practice_members WHERE practice_id = :p AND user_id = :u')
        ->execute([':p' => $practice['id'], ':u' => $userId]);
    profile_app_json(200, ['ok' => true]);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
