<?php
declare(strict_types=1);

/**
 * Event-reminder cron (root). Sends a push N minutes before each upcoming WP `event`
 * CPT start, deduped via the postmeta flag _lg_push_reminded. Data source confirmed
 * by coordinator: post_type=event, start time in meta `_events_start_date_and_time_`
 * (same data the events standalone reads). Run every few minutes.
 *
 *   sudo php /srv/lg-push/run-event-reminders.php [--lead MIN] [--dry]
 *
 * --dry lists what WOULD be reminded (no send, no dedupe write) — for self-test.
 */

require_once __DIR__ . '/lib.php';

const LGPUSH_EVENT_START_META = '_events_start_date_and_time_';

$o    = getopt('', ['lead:', 'dry']);
$lead = isset($o['lead']) ? max(1, (int) $o['lead']) : 60;   // minutes before start
$dry  = isset($o['dry']);

$pdo    = lgpush_db();
$prefix = $GLOBALS['lgpush_prefix'] ?? 'wp_';
$posts  = $prefix . 'posts';
$pm     = $prefix . 'postmeta';

// Published events that have not been reminded yet, with their start value.
$sql = "SELECT p.ID, p.post_title, p.post_name, m.meta_value AS start_raw
        FROM {$posts} p
        JOIN {$pm} m ON m.post_id = p.ID AND m.meta_key = ?
        LEFT JOIN {$pm} r ON r.post_id = p.ID AND r.meta_key = '_lg_push_reminded'
        WHERE p.post_type = 'event' AND p.post_status = 'publish' AND r.meta_id IS NULL";
$st = $pdo->prepare($sql);
$st->execute([LGPUSH_EVENT_START_META]);
$events = $st->fetchAll(PDO::FETCH_ASSOC);

$now       = time();
$windowEnd = $now + $lead * 60;
$out       = [];

foreach ($events as $e) {
    $start = strtotime((string) $e['start_raw']);
    if ($start === false) continue;
    if ($start < $now || $start > $windowEnd) continue;   // only those starting inside the lead window

    $payload = [
        'title' => 'Event starting soon',
        'body'  => (string) $e['post_title'],
        'url'   => '/event/' . $e['post_name'] . '/',
        'icon'  => '/icons/icon-192.png',
        'tag'   => 'event-' . (int) $e['ID'],
    ];

    if ($dry) {
        $out[] = ['id' => (int) $e['ID'], 'title' => $e['post_title'], 'start' => $e['start_raw'], 'would_target' => lgpush_count()];
        continue;
    }

    $res = lgpush_send($payload);
    // Dedupe: mark reminded so the next cron tick skips it.
    $pdo->prepare("INSERT INTO {$pm} (post_id, meta_key, meta_value) VALUES (?, '_lg_push_reminded', '1')")
        ->execute([(int) $e['ID']]);
    $out[] = ['id' => (int) $e['ID'], 'title' => $e['post_title']] + $res;
}

echo json_encode(['lead_min' => $lead, 'dry' => $dry, 'candidates' => count($events), 'reminded' => $out], JSON_PRETTY_PRINT) . "\n";
