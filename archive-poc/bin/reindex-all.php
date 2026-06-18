<?php
require __DIR__.'/../config.php';
if (!function_exists('get_post')) { require LG_ARCHIVE_POC_WP_LOAD; }
require_once __DIR__ . '/indexer.php';
$db = new PDO('sqlite:' . __DIR__ . '/../index.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode = WAL');
global $wpdb;
$kinds = ['post-imgcap','post-regular','post','user-post-imgcap','post-type-videos','loothprint','loothcuts','document','event','ajde_events','tribe_events','international-loothi','topic','member-spotlight','member-directory','member-benefit','sponsor-product','useful_links','coe-questions','shorty','banger','contributing-partner','testimonial','sponsor-post'];
$ph = implode(',', array_fill(0, count($kinds), '%s'));
$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','private') AND post_type IN ($ph)", ...$kinds));
echo "reindexing " . count($ids) . " posts...\n";
$ok = 0; $errs = 0;
foreach ($ids as $i => $pid) {
    try { archive_poc_index_post($db, (int)$pid); $ok++; }
    catch (Throwable $e) { $errs++; if ($errs < 5) echo "  $pid: ".$e->getMessage()."\n"; }
    if ($i > 0 && $i % 500 === 0) echo "  ...$i\n";
}
echo "done: $ok ok, $errs errors\n";
$tiers = $db->query("SELECT tier, COUNT(*) c FROM content_item GROUP BY tier")->fetchAll(PDO::FETCH_ASSOC);
foreach ($tiers as $r) printf("  %-10s %d\n", $r['tier'], $r['c']);
