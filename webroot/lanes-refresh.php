<?php
/**
 * lanes-refresh.php — #143's refresh button. POST touches one pre-created
 * trigger file; a systemd path unit sees the mtime change and fires the
 * page renderer as ubuntu. No sudo, no token, no state — the web user's
 * entire power is "please redraw". Trigger lives OUTSIDE the docroot and
 * outside /tmp (php-fpm's PrivateTmp would hide /tmp from the path unit).
 */
declare(strict_types=1);
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"ok":false,"error":"POST only"}';
    exit;
}
$ok = @touch('/home/ubuntu/.lanes-refresh-request');
echo json_encode(['ok' => $ok]);
