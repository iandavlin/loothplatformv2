<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || $slug === '') { http_response_code(404); echo 'not found'; exit; }

$pg = Db::pg();
$row = null;
$s = $pg->prepare('SELECT uuid FROM users WHERE slug = :s');
$s->execute([':s' => $slug]);
$row = $s->fetch();
if (!$row && ctype_digit($slug)) {
    $s = $pg->prepare('SELECT uuid FROM users WHERE id = :i');
    $s->execute([':i' => (int)$slug]);
    $row = $s->fetch();
}
if (!$row) { http_response_code(404); echo 'not found'; exit; }

$viewer = Auth::currentUser();
if ($viewer && strtolower($viewer['uuid']) === strtolower($row['uuid'])) {
    header('Location: /profile/edit'); exit;
}
http_response_code(403);
header('Content-Type: text/plain');
echo "forbidden — you can only edit your own profile.\n";
