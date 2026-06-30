<?php
/**
 * bb-mirror/bin/backfill-reply-count.php — one-shot heal of stale topic.reply_count.
 *
 * bbPress doesn't bump a topic's post_modified_gmt when a reply is added or
 * removed, so historically neither the per-reply api/v0/_sync.php (which upserts
 * the reply row but never the parent topic) nor bin/reconcile.php's
 * modified-since delta-walk re-materialized the parent topic — its stored
 * reply_count drifted and cards showed "0 replies" while the live facepile
 * showed avatars. This recomputes every topic's reply_count from WP published
 * replies (the authoritative source, same as bb_mirror_upsert_topic).
 *
 * Usage:
 *   sudo -u looth-dev wp eval-file <app-root>/bin/backfill-reply-count.php
 *
 * Idempotent — only topics whose stored count drifted are written. The same
 * recompute now runs inline on every reply mutation (api/v0/_sync.php) and as a
 * rollup in bin/reconcile.php, so this is a one-time catch-up for historical
 * drift; re-running it is harmless.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . "\n");
    exit(2);
}

global $wpdb;
require_once __DIR__ . '/../lib/materializers.php';

$db = bb_mirror_db(readonly: false);
$fixed = bb_mirror_refresh_all_reply_counts($db);
echo "reply_count backfill: $fixed topic(s) corrected\n";
