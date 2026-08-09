<?php
/**
 * repro-silent-return.php — the ~16% dispatch drop is NOT a lost POST.
 *
 * Live's nginx log records a `POST /bb-mirror-api/v0/_sync` returning **200** at
 * create+1..3s for ALL 11 dropped replies and all 61 that landed — indistinguishable.
 * The hook fired, the POST arrived, the receiver answered 200, and wrote nothing.
 *
 * This reproduces that branch deterministically. bb_mirror_upsert_reply() has two
 * exits that yield a 200 and no row, both meaning "the reply's own data was not
 * readable at that instant" — an unreadable post, or unreadable _bbp_topic_id /
 * _bbp_forum_id. Neither throws, neither logs. "I cannot read it yet" is handled
 * as "there is nothing to do".
 *
 * Runs against /srv/bb-mirror — MAIN's DEPLOYED copy, the code live actually runs,
 * not this branch's. Non-mutating: it poisons only the per-request meta cache and
 * the control upsert it performs is idempotent.
 *
 * WHY IT ONLY BITES LIVE: live carries a wp-content/object-cache.php drop-in with
 * redis on :6379; dev2 has redis but NO drop-in, so its object cache is per-request
 * and cannot serve a stale read across processes. dev2 structurally cannot exhibit
 * this, which is why 120/120 idle and 60/60 under pool saturation landed there —
 * a green result on dev2 was never evidence about live.
 *
 * NOT PROVEN: which upstream cause makes the read bad. A stale persistent-cache
 * entry fits; so does "not yet visible to the second request". See
 * docs/BB-MIRROR-STALL-3.9.md §5.
 *
 * Run: sudo -u looth-dev wp --path=/var/www/dev eval-file <this>
 */
require_once '/srv/bb-mirror/config.php';
require_once '/srv/bb-mirror/lib/materializers.php';   // MAIN's deployed copy — the code live runs
global $wpdb;
$db = bb_mirror_db(false);

$id = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='reply' AND post_status='publish' ORDER BY ID DESC LIMIT 1");
$st = $db->prepare("SELECT extract(epoch from sync_at)::bigint FROM reply WHERE id = ?");
$st->execute([$id]); $before = (int) $st->fetchColumn();
echo "probe reply: $id   mirror row present: " . ($before ? "yes" : "NO") . "\n";

echo "\n-- control: normal upsert --\n";
bb_mirror_upsert_reply($id, $db);
$st->execute([$id]); $mid = (int) $st->fetchColumn();
echo "sync_at advanced: " . ($mid > $before ? "YES (row written)" : "no") . "\n";

echo "\n-- poisoned: meta cache says the reply has no parentage --\n";
wp_cache_set($id, [], 'post_meta');           // what a stale persistent entry looks like
$threw = false;
try { bb_mirror_upsert_reply($id, $db); }
catch (Throwable $e) { $threw = true; echo "THREW: " . get_class($e) . "\n"; }
$st->execute([$id]); $after = (int) $st->fetchColumn();
echo "threw an error:   " . ($threw ? "yes" : "NO — it returned quietly") . "\n";
echo "sync_at advanced: " . ($after > $mid ? "yes" : "NO — nothing was written") . "\n";
echo "\nVERDICT: " . ((!$threw && $after <= $mid)
    ? "SILENT NO-WRITE reproduced — the caller cannot tell this from success."
    : "not reproduced") . "\n";
