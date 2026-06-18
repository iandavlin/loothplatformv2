<?php
/**
 * bb-mirror/bin/backfill-profile-visibility.php — refresh BOTH visibility
 * cache columns on forums.person from profile-app (the field owner):
 *
 *   discussion_visibility — the logged-out author mask ('member' default)
 *   profile_visibility    — the MASTER SWITCH (Ian 6/12 refactor): 'private'
 *                           = owner-only everywhere; the hub search mask
 *                           (archive-poc search-suggest) reads it off this
 *                           cache via a forums.person JOIN.
 *
 * Idempotent, re-runnable any time — run it after a member flips their
 * profile dial if you can't wait for the next sync pass, and after dev DB
 * reloads. Unresolved rows (unbridged / archived / profile-api down) keep
 * their current values for 'profile' and default 'member' for 'discussion'
 * (each flag's leak-safe direction).
 *
 * Usage (dev sits behind the cookie gate — forward the token via env):
 *   sudo -u bb-mirror env LG_LOOTHDEV_GATE_TOKEN=<dev-gate-token> \
 *       php /home/ubuntu/projects/bb-mirror/bin/backfill-profile-visibility.php [person_id ...]
 *
 * On live there is no gate; run without the token. Optional args limit the
 * pass to specific person ids (= wp user ids).
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/materializers.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$db = bb_mirror_db(readonly: false);

$only = array_values(array_filter(array_map('intval', array_slice($argv, 1)), static fn($i) => $i > 0));
if ($only) {
    $ids = $only;
} else {
    $ids = array_map('intval', $db->query("SELECT id FROM person ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
}
echo "person rows: " . count($ids) . "\n";
if (!$ids) { echo "nothing to backfill\n"; exit(0); }

$upd = $db->prepare("UPDATE person SET discussion_visibility = ?, profile_visibility = ? WHERE id = ?");
$resolved = 0; $private = 0;

foreach (array_chunk($ids, 500) as $chunk) {
    $map = bb_mirror_person_vis_batch($chunk);
    foreach ($chunk as $id) {
        if (!isset($map[$id])) continue;            // unresolved: keep current values
        $upd->execute([$map[$id]['discussion'], $map[$id]['profile'], $id]);
        $resolved++;
        $private += ($map[$id]['profile'] === 'private') ? 1 : 0;
    }
    echo "  processed " . count($chunk) . " (resolved-so-far=$resolved, private-so-far=$private)\n";
}

echo "done: {$resolved} resolved from profile-app, {$private} private, "
   . (count($ids) - $resolved) . " left untouched\n";
