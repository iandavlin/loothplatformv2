<?php
declare(strict_types=1);

/**
 * Push queue drainer (root cron). Reads pending rows from wp_lg_push_queue, sends
 * each via the sender core, marks them sent. The publish hook (and anything else)
 * only ENQUEUES; this is the single place that reads the VAPID key + delivers.
 *
 *   sudo php /srv/lg-push/run-queue.php [--limit N] [--dry]
 */

require_once __DIR__ . '/lib.php';

$o     = getopt('', ['limit:', 'dry']);
$limit = isset($o['limit']) ? max(1, (int) $o['limit']) : 100;
$dry   = isset($o['dry']);

$pdo    = lgpush_db();
$prefix = $GLOBALS['lgpush_prefix'] ?? 'wp_';
$qt     = $prefix . 'lg_push_queue';

$st = $pdo->prepare("SELECT id, payload, target_type, target_id FROM {$qt} WHERE status='pending' ORDER BY id ASC LIMIT {$limit}");
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($rows as $r) {
    $payload = json_decode((string) $r['payload'], true);
    if (!is_array($payload)) {
        $pdo->prepare("UPDATE {$qt} SET status='failed', attempts=attempts+1 WHERE id=?")->execute([$r['id']]);
        $results[] = ['id' => (int) $r['id'], 'error' => 'bad_payload'];
        continue;
    }
    $where = null;
    $params = [];
    if (($r['target_type'] ?? 'all') === 'user' && $r['target_id'] !== null) {
        $where = 'wp_user_id = ?';
        $params[] = (int) $r['target_id'];
    }
    if ($dry) {
        $results[] = ['id' => (int) $r['id'], 'would_target' => lgpush_count($where, $params), 'payload' => $payload];
        continue;
    }
    $res = lgpush_send($payload, $where, $params);
    $pdo->prepare("UPDATE {$qt} SET status='sent', attempts=attempts+1, sent_at=UTC_TIMESTAMP() WHERE id=?")->execute([$r['id']]);
    $results[] = ['id' => (int) $r['id']] + $res;
}

echo json_encode(['drained' => count($rows), 'dry' => $dry, 'results' => $results], JSON_PRETTY_PRINT) . "\n";
