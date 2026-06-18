<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Cache;
use Looth\ProfileApp\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') profile_app_json(405, ['error' => 'method_not_allowed']);

$user = Auth::requireUser();
$in   = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'bad_json']);

$name = $in['display_name'] ?? null;
if (!is_string($name)) profile_app_json(400, ['error' => 'display_name_required']);
$name = trim($name);
if ($name === '' || mb_strlen($name) > 120) profile_app_json(400, ['error' => 'invalid_length']);

$biz = array_key_exists('business_name', $in) ? $in['business_name'] : null;
$bizProvided = array_key_exists('business_name', $in);
if ($bizProvided) {
    if ($biz === null) {
        // explicit null/clear
    } elseif (is_string($biz)) {
        $biz = trim($biz);
        if (mb_strlen($biz) > 120) profile_app_json(400, ['error' => 'invalid_business_length']);
        if ($biz === '') $biz = null;
    } else {
        profile_app_json(400, ['error' => 'invalid_business_name']);
    }
}

$pg = Db::pg();
if ($bizProvided) {
    $pg->prepare('UPDATE users SET display_name = :n, business_name = :b WHERE id = :u')
       ->execute([':n' => $name, ':b' => $biz, ':u' => (int)$user['id']]);
} else {
    $pg->prepare('UPDATE users SET display_name = :n WHERE id = :u')
       ->execute([':n' => $name, ':u' => (int)$user['id']]);
}

// Identity cleanup (slice 3): mirror display_name to wp_users so wp-admin
// author bylines stay consistent with the profile-app source-of-truth.
$wpId = 0;
try {
    $bridge = $pg->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
    $bridge->execute([':u' => (int)$user['id']]);
    $wpId = (int) $bridge->fetchColumn();
    if ($wpId) {
        $u = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
        $my = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
            $u, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $my->prepare('UPDATE wp_users SET display_name = ? WHERE ID = ?')
           ->execute([$name, $wpId]);
    }
} catch (Throwable $e) {
    // Mirror is best-effort; do not block the API.
    error_log('[me-name] wp_users mirror failed: ' . $e->getMessage());
}

// Slice 3.5: self-purge whoami cache because display_name is in the payload.
// business_name isn't in /whoami today; if it lands there later this still
// covers it. Best-effort — never blocks the API.
if ($wpId > 0) Cache::purgeWhoami($wpId);

$resp = ['ok' => true, 'display_name' => $name];
if ($bizProvided) $resp['business_name'] = $biz;
profile_app_json(200, $resp);
