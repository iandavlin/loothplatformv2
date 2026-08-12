<?php
/**
 * probe-transport.php — measure loss on the realtime bb-mirror sync transport.
 *
 * Fires bb_mirror_sync_dispatch('reply', $id) — the EXACT mu-plugin call, so the
 * exact wp_remote_post(blocking=false, timeout=1, https loopback) transport — for
 * a set of DISTINCT reply ids, then re-reads sync_at. Every id is independently
 * observable, so transport loss falls straight out.
 *
 * SAMPLE IS RESTRICTED TO REPLIES THAT EXIST IN WP AS post_status=publish.
 * A mirror row whose WP post is gone is a GHOST, and bb_mirror_upsert_reply()'s
 * not-a-reply branch DELETES it — which reads as "lost" while actually being the
 * ghost sweep doing its job. (Measured the hard way: a first run sampled
 * ORDER BY sync_at ASC, hit 13 ghosts, and deleted them.) Intersecting with WP
 * first makes the probe non-destructive as well as correct.
 *
 * Measured with it on dev2, 2026-08-09 (docs/BB-MIRROR-STALL-3.9.md §5):
 * 120/120 dispatches landed on an idle box, and 60/60 landed with the looth-dev
 * FPM pool saturated — but under saturation each call burned its FULL 1-second
 * timeout (the firing loop went 13s -> 61s). The transport is not inherently
 * lossy; the margin before a silent drop is exactly one second, and under load
 * it is already being spent.
 *
 * Run (dev2 only — it fires real sync dispatches):
 *   sudo -u looth-dev env PROBE_MODE=burst PROBE_N=60 \
 *        wp --path=/var/www/dev eval-file <this>
 * For the load case, saturate the pool first (12 concurrent hits on a WP page
 * served by the looth-dev pool) and run the burst mode against it.
 */

require_once '/srv/bb-mirror/config.php';

// argv positions shift when wp-cli global flags (--path=) are passed, so read the
// knobs from the environment instead. sudo strips env: pass via `sudo -u X env ...`.
$mode = getenv('PROBE_MODE') ?: 'burst';
$n    = (int) (getenv('PROBE_N') ?: 50);
if (!in_array($mode, ['burst', 'paced'], true)) { $mode = 'burst'; }

if (!function_exists('bb_mirror_sync_dispatch')) {
    fwrite(STDERR, "bb_mirror_sync_dispatch() not loaded — is the mu-plugin active?\n");
    exit(2);
}

global $wpdb;
$db = bb_mirror_db(true);

// Live WP replies only — never a ghost (see header).
$wp_ids = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type='reply' AND post_status='publish'"
);
if (!$wp_ids) { fwrite(STDERR, "no published replies in WP\n"); exit(2); }
$wp_set = array_flip(array_map('intval', $wp_ids));

$rows = $db->query(
    "SELECT id, EXTRACT(EPOCH FROM sync_at)::bigint AS sync_epoch FROM reply ORDER BY id DESC"
)->fetchAll();

$before = [];
foreach ($rows as $r) {
    $id = (int) $r['id'];
    if (!isset($wp_set[$id])) { continue; }          // ghost — never touch it
    $before[$id] = (int) $r['sync_epoch'];
    if (count($before) >= $n) { break; }
}
if (!$before) { fwrite(STDERR, "no live reply rows to probe\n"); exit(2); }

$t0 = time();
printf("mode=%s  n=%d (live WP replies only)  fired_at=%s UTC\n",
    $mode, count($before), gmdate('H:i:s', $t0));

foreach (array_keys($before) as $id) {
    bb_mirror_sync_dispatch('reply', $id, 'upsert');
    if ($mode === 'paced') { usleep(2_000_000); }   // 2s apart — no queueing pressure
}

printf("all %d dispatched in %ds; waiting 30s for the receivers to land...\n",
    count($before), time() - $t0);
sleep(30);

$ids = implode(',', array_keys($before));
$after = [];
foreach ($db->query(
    "SELECT id, EXTRACT(EPOCH FROM sync_at)::bigint AS sync_epoch FROM reply WHERE id IN ($ids)"
)->fetchAll() as $r) {
    $after[(int) $r['id']] = (int) $r['sync_epoch'];
}

$landed = $lost = [];
foreach ($before as $id => $was) {
    if (($after[$id] ?? 0) > $was) { $landed[] = $id; } else { $lost[] = $id; }
}

$total = count($before);
printf("\nLANDED: %d/%d (%.1f%%)\n", count($landed), $total, 100 * count($landed) / $total);
printf("LOST:   %d/%d (%.1f%%)\n", count($lost), $total, 100 * count($lost) / $total);
if ($lost) { echo "lost ids: " . implode(', ', array_slice($lost, 0, 40)) . "\n"; }
