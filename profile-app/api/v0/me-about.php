<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
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

// Whether this request touched the About body (drives the author_about mirror).
$bodyTouched = false;

// Rich-text body (the primary edit path). SERVER-SIDE sanitize to the strict
// allowlist — the editor is never trusted. data.text is the tag-stripped
// projection, derived here so it always matches the stored html.
if (array_key_exists('html', $in)) {
    if (!is_string($in['html'])) profile_app_json(400, ['error' => 'html_must_be_string']);
    if (strlen($in['html']) > Block::ABOUT_HTML_MAX) profile_app_json(400, ['error' => 'html_too_long']);
    $clean = Block::sanitizeRichHtml($in['html']);
    $plain = Block::htmlToPlainText($clean);
    if ($plain === '') $clean = '';   // whitespace-only body → truly empty (shows placeholder)
    $data['html'] = $clean;
    $data['text'] = $plain;
    $bodyTouched  = true;
} elseif (array_key_exists('text', $in)) {
    // Legacy plain path — still accepted. Plain text is authoritative here, so the
    // html projection is cleared (render falls back to nl2br(text)).
    if (!is_string($in['text'])) profile_app_json(400, ['error' => 'text_must_be_string']);
    if (strlen($in['text']) > Block::ABOUT_TEXT_MAX) profile_app_json(400, ['error' => 'text_too_long']);
    $data['text'] = $in['text'];
    unset($data['html']);
    $bodyTouched  = true;
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

// Author-box tie (Ian 2026-07-23): mirror the PLAIN-TEXT projection into WP
// usermeta `author_about` for this member's bridged wp_user_id — the same direct
// unix-socket channel me-header uses for `description` (no new channel). Author
// boxes keep their plain look but are now genuinely fed from the profile. Runs
// only when the body changed; best-effort — never blocks the API.
if ($bodyTouched) {
    try {
        $bridge = $pg->prepare('SELECT wp_user_id FROM wp_user_bridge WHERE user_id = :u');
        $bridge->execute([':u' => (int)$user['id']]);
        $wpId = (int) $bridge->fetchColumn();
        if ($wpId > 0) {
            $un = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
            $my = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
                $un, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $val = (string)($data['text'] ?? '');
            // wp_usermeta has no unique (user_id, meta_key) — select then update/insert.
            $sel = $my->prepare("SELECT umeta_id FROM wp_usermeta WHERE user_id = ? AND meta_key = 'author_about' LIMIT 1");
            $sel->execute([$wpId]);
            $umetaId = $sel->fetchColumn();
            if ($umetaId) {
                $my->prepare('UPDATE wp_usermeta SET meta_value = ? WHERE umeta_id = ?')->execute([$val, $umetaId]);
            } else {
                $my->prepare("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, 'author_about', ?)")
                   ->execute([$wpId, $val]);
            }
        }
    } catch (Throwable $e) {
        error_log('[me-about] author_about mirror failed: ' . $e->getMessage());
    }
}

profile_app_json(200, ['ok' => true, 'visibility' => $vis, 'data' => $data]);
