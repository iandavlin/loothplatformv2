<?php
require __DIR__.'/../config.php';
// One-shot: reindex every event-kind post so the new event_* columns get populated.
// Run via: cd /var/www/dev && sudo -u www-data wp eval-file /home/ubuntu/projects/archive-poc/bin/reindex-events.php --skip-themes --skip-plugins

if (!function_exists('get_post')) { require LG_ARCHIVE_POC_WP_LOAD; }
require_once __DIR__ . '/indexer.php';

$db = new PDO('sqlite:' . __DIR__ . '/../index.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode = WAL');

global $wpdb;
$rows = $wpdb->get_results("SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','private') AND post_type IN ('event','ajde_events','international-loothi','tribe_events')", ARRAY_A);
echo "events to reindex: " . count($rows) . "\n";
$ok = 0;
foreach ($rows as $r) {
    try {
        $res = archive_poc_index_post($db, (int)$r['ID']);
        $ok++;
    } catch (Throwable $e) {
        echo "  FAIL " . $r['ID'] . ": " . $e->getMessage() . "\n";
    }
}
echo "reindexed: $ok\n";

// Sample
foreach ($db->query("SELECT id, title, event_start_at, event_end_at, event_region, event_join_url FROM content_item WHERE kind='event' AND event_start_at IS NOT NULL ORDER BY event_start_at LIMIT 5") as $r) {
    printf("  %d  start=%s  region=%s  join=%s  | %s\n",
        $r['id'], $r['event_start_at'] ? gmdate('Y-m-d H:i', $r['event_start_at']) : '-',
        $r['event_region'] ?: '-', $r['event_join_url'] ? 'YES' : '-', $r['title']);
}
