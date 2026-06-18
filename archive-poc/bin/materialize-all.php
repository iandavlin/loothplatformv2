<?php
/**
 * archive-poc/bin/materialize-all.php — one-pass blob backfill.
 *
 * Boots WordPress, walks every PUBLISHED managed-CPT post, and writes its
 * standalone-render blob into discovery.article_blobs. The one-pass to populate
 * existing posts; thereafter the save-hook keeps blobs fresh incrementally.
 *
 * Usage (dev, peer auth as the write-side role):
 *   LG_ARCHIVE_POC_DSN='pgsql:host=/var/run/postgresql;dbname=looth' \
 *     sudo -u looth-dev php bin/materialize-all.php
 *
 * Options:
 *   --post=<id>   materialize a single post (debug)
 *   --prune       also delete blobs whose post_id no longer qualifies
 *
 * READ-ONLY on WordPress (SELECT + get_* only). Writes only article_blobs.
 */
require_once __DIR__ . "/../config.php";


if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$opt        = getopt('', ['post:', 'prune']);
$only_post  = isset($opt['post']) ? (int) $opt['post'] : 0;
$do_prune   = array_key_exists('prune', $opt);

// Boot WP (same autodetect as backfill-pg.php).
if (!function_exists('get_post')) {
    if (!isset($_SERVER['HTTP_HOST']))  $_SERVER['HTTP_HOST']  = LG_ARCHIVE_POC_HOST;
    if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    require LG_ARCHIVE_POC_WP_LOAD;
}

require_once __DIR__ . '/materializer.php';

if (!class_exists('LG\\LayoutV2\\Plugin')) {
    fwrite(STDERR, "lg-layout-v2 is not active — cannot materialize.\n");
    exit(1);
}

$db = lg_materialize_pdo();

/* Single-post mode. */
if ($only_post > 0) {
    $res = lg_materialize_upsert($db, $only_post);
    echo json_encode($res, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

global $wpdb;
$wpdb->suppress_errors(true);

$managed = (array) \LG\LayoutV2\Plugin::MANAGED_CPTS;
$types_csv = implode(',', array_map(fn($t) => "'" . esc_sql($t) . "'", $managed));

echo "materialize-all: managed CPTs = " . implode(', ', $managed) . "\n";

$stats   = ['upsert' => 0, 'delete' => 0, 'error' => 0];
$seen    = [];   // post_ids that produced a blob (for --prune)
$batch   = 500;
$offset  = 0;
$total   = 0;

while (true) {
    $posts = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID
        FROM {$wpdb->posts} p
        WHERE p.post_status = 'publish'
          AND p.post_type IN ($types_csv)
        ORDER BY p.ID
        LIMIT %d OFFSET %d
    ", $batch, $offset), ARRAY_A);
    if (!$posts) break;

    foreach ($posts as $row) {
        $pid = (int) $row['ID'];
        try {
            $res = lg_materialize_upsert($db, $pid);
            $action = $res['action'] ?? 'error';
            $stats[$action] = ($stats[$action] ?? 0) + 1;
            if ($action === 'upsert') $seen[$pid] = true;
        } catch (Throwable $e) {
            $stats['error']++;
            error_log("materialize-all post=$pid: " . $e->getMessage());
            fwrite(STDERR, "  ! post $pid: " . $e->getMessage() . "\n");
        }
        $total++;
    }
    $offset += $batch;
    if ($total % 2000 < $batch) echo "  processed: $total\n";
}

/* Optional prune: drop blobs whose post_id is no longer a published managed
   post (deleted outside a hook, un-managed, unpublished). */
if ($do_prune) {
    $existing = $db->query('SELECT post_id FROM article_blobs')->fetchAll(PDO::FETCH_COLUMN);
    $pruned = 0;
    foreach ($existing as $pid) {
        $pid = (int) $pid;
        if (!isset($seen[$pid])) { lg_materialize_delete($db, $pid); $pruned++; }
    }
    echo "  pruned (stale blobs): $pruned\n";
}

echo "\n=== DONE ===\n";
echo "  walked:  $total\n";
echo "  upserts: {$stats['upsert']}\n";
echo "  deletes: {$stats['delete']}  (managed-but-unpublished/un-layout’d)\n";
echo "  errors:  {$stats['error']}\n";
