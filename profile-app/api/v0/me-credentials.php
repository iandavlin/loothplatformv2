<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

$method = $_SERVER['REQUEST_METHOD'];
$user = Auth::requireUser();
$pg = Db::pg();

function parse_body(): array {
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);
    return $in;
}

function clean_cred(array $in, ?int $catalogIdLookupHint = null): array {
    $catalogId = isset($in['catalog_id']) && $in['catalog_id'] !== null ? (int)$in['catalog_id'] : null;
    $rawIssuer  = trim((string)($in['raw_issuer']  ?? ''));
    $rawProgram = trim((string)($in['raw_program'] ?? ''));
    if ($rawIssuer === '' || $rawProgram === '') {
        profile_app_json(400, ['error' => 'raw_issuer_and_program_required']);
    }
    $vis = $in['visibility'] ?? 'members';
    if (!in_array($vis, Profile::VIS_VALUES, true)) profile_app_json(400, ['error' => 'invalid_visibility']);
    $ident = isset($in['identifier']) ? trim((string)$in['identifier']) ?: null : null;
    $iss = isset($in['issued_at'])    ? ($in['issued_at']  ?: null) : null;
    $exp = isset($in['expires_at'])   ? ($in['expires_at'] ?: null) : null;
    $url = isset($in['evidence_url']) ? trim((string)$in['evidence_url']) ?: null : null;
    $sort = isset($in['sort_order'])  ? (int)$in['sort_order'] : 0;
    return compact('catalogId','rawIssuer','rawProgram','ident','iss','exp','url','vis','sort');
}

if ($method === 'POST') {
    $in = parse_body();
    $c  = clean_cred($in);
    $cnt = $pg->prepare("SELECT count(*) FROM profile_credentials WHERE owner_type='profile' AND owner_id=:u");
    $cnt->execute([':u' => (int)$user['id']]);
    if ((int)$cnt->fetchColumn() >= Profile::CREDENTIALS_MAX) {
        profile_app_json(400, ['error' => 'too_many', 'max' => Profile::CREDENTIALS_MAX]);
    }
    $stmt = $pg->prepare("INSERT INTO profile_credentials
        (owner_type, owner_id, catalog_id, raw_issuer, raw_program, identifier,
         issued_at, expires_at, evidence_url, visibility, sort_order)
        VALUES ('profile', :u, :cat, :ri, :rp, :idt, :iss, :exp, :url, :v, :s)
        RETURNING id");
    $stmt->execute([
        ':u' => (int)$user['id'], ':cat' => $c['catalogId'], ':ri' => $c['rawIssuer'],
        ':rp' => $c['rawProgram'], ':idt' => $c['ident'], ':iss' => $c['iss'],
        ':exp' => $c['exp'], ':url' => $c['url'], ':v' => $c['vis'], ':s' => $c['sort'],
    ]);
    profile_app_json(201, ['ok' => true, 'id' => (int)$stmt->fetchColumn()]);
}

if ($method === 'PATCH' || $method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) profile_app_json(400, ['error' => 'id_required']);
    $own = $pg->prepare("SELECT 1 FROM profile_credentials WHERE id=:i AND owner_type='profile' AND owner_id=:u");
    $own->execute([':i' => $id, ':u' => (int)$user['id']]);
    if (!$own->fetchColumn()) profile_app_json(404, ['error' => 'not_found']);

    if ($method === 'DELETE') {
        $pg->prepare("DELETE FROM profile_credentials WHERE id=:i")->execute([':i' => $id]);
        profile_app_json(200, ['ok' => true, 'deleted' => $id]);
    }

    $in = parse_body();
    $c  = clean_cred($in);
    $pg->prepare("UPDATE profile_credentials SET
        catalog_id=:cat, raw_issuer=:ri, raw_program=:rp, identifier=:idt,
        issued_at=:iss, expires_at=:exp, evidence_url=:url, visibility=:v, sort_order=:s
        WHERE id=:i")
        ->execute([':cat' => $c['catalogId'], ':ri' => $c['rawIssuer'], ':rp' => $c['rawProgram'],
                   ':idt' => $c['ident'], ':iss' => $c['iss'], ':exp' => $c['exp'],
                   ':url' => $c['url'], ':v' => $c['vis'], ':s' => $c['sort'], ':i' => $id]);
    profile_app_json(200, ['ok' => true, 'id' => $id]);
}

profile_app_json(405, ['error' => 'method_not_allowed']);
