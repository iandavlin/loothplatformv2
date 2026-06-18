<?php
declare(strict_types=1);

/**
 * CLI staging tool for the Looth web-push sender.
 *
 *   php send-test.php --count
 *   php send-test.php --title "Hi" --body "..." --url /hub/ [--user N | --endpoint URL] [--dry]
 *
 * --dry only counts the target set (no send). Run as root so the VAPID key is readable.
 */

require_once __DIR__ . '/lib.php';

$o = getopt('', ['count', 'dry', 'title:', 'body:', 'url:', 'user:', 'endpoint:']);

$where = null;
$params = [];
if (isset($o['user'])) {
    $where = 'wp_user_id = ?';
    $params[] = (int) $o['user'];
} elseif (isset($o['endpoint'])) {
    $where = 'endpoint = ?';
    $params[] = (string) $o['endpoint'];
}

if (isset($o['count'])) {
    echo json_encode(['subscriptions' => lgpush_count($where, $params)], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

if (isset($o['dry'])) {
    echo json_encode(['would_target' => lgpush_count($where, $params)], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

$payload = [
    'title' => $o['title'] ?? 'Looth',
    'body'  => $o['body']  ?? 'Test notification from Looth.',
    'url'   => $o['url']   ?? '/hub/',
    'icon'  => '/icons/icon-192.png',
    'tag'   => 'looth-test',
];

$res = lgpush_send($payload, $where, $params);
echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
