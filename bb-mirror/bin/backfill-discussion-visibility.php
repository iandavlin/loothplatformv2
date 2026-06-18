<?php
/**
 * bb-mirror/bin/backfill-discussion-visibility.php — one-time backfill of the
 * person.discussion_visibility column (added 2026-06-08, discussion-author mask,
 * docs/briefing-discussion-visibility.md).
 *
 * Reads every existing forums.person row, resolves each author's preference from
 * profile-app's /profile-api/v0/users batch payload (the same source the live
 * sync now uses — bb_mirror_discussion_vis_batch), and writes it back. Rows the
 * lookup can't resolve (unbridged / archived / profile-api down) keep the column
 * default 'member' — the leak-SAFE direction. Idempotent: re-runnable any time.
 *
 * Usage (dev sits behind the cookie gate — forward the token via env):
 *   sudo -u bb-mirror env LG_LOOTHDEV_GATE_TOKEN=<dev-gate-token> \
 *       php /home/ubuntu/projects/bb-mirror/bin/backfill-discussion-visibility.php
 *
 * On live there is no gate; run without the token.
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/materializers.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$db = bb_mirror_db(readonly: false);

$ids = array_map('intval', $db->query("SELECT id FROM person ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
echo "person rows: " . count($ids) . "\n";
if (!$ids) { echo "nothing to backfill\n"; exit(0); }

$upd = $db->prepare("UPDATE person SET discussion_visibility = ? WHERE id = ?");
$resolved = 0; $public = 0;

// Chunk through the batch resolver (it already chunks /users at 100 internally,
// but keep our own pass bounded so a huge person table stays memory-flat).
foreach (array_chunk($ids, 500) as $chunk) {
    $map = bb_mirror_discussion_vis_batch($chunk);
    foreach ($chunk as $id) {
        // Default unresolved → 'member' (leak-safe). Only write a value we have.
        $vis = $map[$id] ?? 'member';
        $upd->execute([$vis, $id]);
        $resolved += isset($map[$id]) ? 1 : 0;
        $public   += ($vis === 'public') ? 1 : 0;
    }
    echo "  processed " . count($chunk) . " (resolved-so-far=$resolved, public-so-far=$public)\n";
}

echo "done: {$resolved} resolved from profile-app, {$public} public, "
   . (count($ids) - $resolved) . " defaulted to member\n";
