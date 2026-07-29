<?php
/**
 * bb-mirror/bin/ghost-census.php — READ-ONLY. What does the reverse pass see?
 *
 *   sudo -u looth-dev wp eval-file /srv/bb-mirror/bin/ghost-census.php
 *
 * bin/reconcile.php runs the sweep report-only by default, but its output is
 * buried in the journal every 10 minutes. This prints the same census on demand,
 * touching nothing, so "how far has the mirror drifted from WordPress" is a
 * question with a one-command answer.
 *
 * BOTH DIRECTIONS, because they are different defects with different owners:
 *   mirror -> WP   GHOSTS: rows the mirror still renders whose WP post is gone.
 *                  Member-visible. The orphan census reads 0 for these.
 *   WP -> mirror   NEVER ARRIVED: posts members wrote that the mirror never
 *                  received, so they have never rendered to anyone.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post_meta')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . "\n");
    exit(2);
}

global $wpdb;
$db = bb_mirror_db();   // read-only

$host = function_exists('lg_env') ? (lg_env()['host'] ?? '?') : '?';
echo "box: $host   (dev2 and live hold DIFFERENT data — always say which)\n\n";

foreach (['topic', 'reply'] as $kind) {
    $wp_ids     = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $kind)));
    $mirror_ids = array_map('intval', (array) $db->query("SELECT id FROM $kind")->fetchAll(PDO::FETCH_COLUMN));

    if (!$wp_ids) { echo strtoupper($kind) . ": wp_posts returned zero rows — refusing to read that as 'all deleted'\n"; continue; }

    $wp_have     = array_flip($wp_ids);
    $mirror_have = array_flip($mirror_ids);

    $ghosts       = array_values(array_filter($mirror_ids, fn($id) => !isset($wp_have[$id])));
    $never_landed = array_values(array_filter($wp_ids,     fn($id) => !isset($mirror_have[$id])));

    printf("%-6s  mirror %5d   wp %5d\n", strtoupper($kind), count($mirror_ids), count($wp_ids));
    printf("        GHOSTS (in mirror, gone from WP, STILL RENDERING): %d%s\n",
        count($ghosts), $ghosts ? '  ids: ' . implode(', ', array_slice($ghosts, 0, 25)) . (count($ghosts) > 25 ? ' …' : '') : '');

    // Not every "never landed" id is a defect — drafts are correctly excluded,
    // and a reply whose _bbp_topic_id points at something that is not a topic is
    // WP-side corruption the mirror is RIGHT to reject. Split them, because the
    // raw count reads as an outage and mostly is not one.
    $real = $drafts = $corrupt = [];
    foreach ($never_landed as $id) {
        $st = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $id));
        if ($st !== 'publish') { $drafts[] = $id; continue; }
        if ($kind === 'reply') {
            $parent = (int) get_post_meta($id, '_bbp_topic_id', true);
            $ptype  = $parent ? get_post_type($parent) : false;
            if ($ptype !== 'topic') { $corrupt[] = $id; continue; }
        }
        $real[] = $id;
    }
    printf("        NEVER ARRIVED (in WP, absent from mirror):         %d\n", count($never_landed));
    printf("          - published, parent OK  -> GENUINE LOSS:         %d%s\n",
        count($real), $real ? '  ids: ' . implode(', ', array_slice($real, 0, 25)) : '');
    printf("          - not published (draft/trash/spam) -> correct:   %d\n", count($drafts));
    if ($kind === 'reply') {
        printf("          - parent is not a topic -> WP-side corruption:  %d%s\n",
            count($corrupt), $corrupt ? '  ids: ' . implode(', ', array_slice($corrupt, 0, 25)) : '');
    }
    echo "\n";
}

// Orphaned attachments — the leak that started all of this. Counted here too so
// one command answers the whole family of questions.
$orph = $db->query(
    "SELECT parent_kind, count(*) rows_leaked, count(DISTINCT parent_id) parents
       FROM attachment a
      WHERE (a.parent_kind = 'topic' AND NOT EXISTS (SELECT 1 FROM topic t WHERE t.id = a.parent_id))
         OR (a.parent_kind = 'reply' AND NOT EXISTS (SELECT 1 FROM reply r WHERE r.id = a.parent_id))
      GROUP BY parent_kind")->fetchAll();
echo "ORPHANED ATTACHMENTS (parent row gone from the mirror):\n";
if (!$orph) echo "  none\n";
foreach ($orph as $o) echo "  {$o['parent_kind']}: {$o['rows_leaked']} row(s) across {$o['parents']} lost parent(s)\n";

$trig = $db->query("SELECT count(*) c FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = 'forums' AND NOT t.tgisinternal
                      AND t.tgname LIKE '%attachment_purge%'")->fetch();
echo "  attachment-purge triggers installed: " . (int) $trig['c'] . " of 2"
   . ((int) $trig['c'] === 2 ? "\n" : "   <-- the leak is still open\n");
