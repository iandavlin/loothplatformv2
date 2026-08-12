<?php
/**
 * repro-silent-return.php — the ~16% dispatch drop is NOT a lost POST.
 *
 * Live's nginx log records a `POST /bb-mirror-api/v0/_sync` returning **200** at
 * create+1..3s for ALL 11 dropped replies and all 61 that landed — indistinguishable.
 * The hook fired, the POST arrived, the receiver answered 200, and wrote nothing.
 *
 * bb_mirror_upsert_reply() has two exits that yield 200-and-no-row, both meaning
 * "the reply's own data was not readable at that instant": an unreadable post, or
 * unreadable _bbp_topic_id / _bbp_forum_id. Neither throws, neither logs, neither
 * retries. This confirms the second one on REAL unparented data (reply 71433,
 * which genuinely has no _bbp_topic_id rows), against /srv/bb-mirror — MAIN's
 * DEPLOYED copy, the code live actually runs.
 *
 * Non-mutating: 71433 cannot be written (that is the point), and the control
 * upserts are idempotent re-materialisations of rows that already exist.
 *
 * TWO MEASUREMENT TRAPS THIS PROBE WAS BUILT WRONG AROUND FIRST, both worth
 * keeping in mind for anything that touches this table:
 *
 *   1. POISONING THE OBJECT CACHE DOES NOTHING TO THE META PATH.
 *      bb_mirror_post_meta_all() runs a direct $wpdb SELECT on wp_postmeta with no
 *      cache layer, so wp_cache_set($id, [], 'post_meta') is not a lever on it.
 *      The first draft "forced" the branch that way and forced nothing.
 *
 *   2. sync_at HAS ONE-SECOND RESOLUTION, so "sync_at did not advance" is NOT
 *      "nothing was written". Two genuine upserts milliseconds apart are
 *      indistinguishable from one upsert and one no-op. The first draft used that
 *      as its detector and reported a no-write that had not happened. It reached
 *      the right verdict by luck. This version checks ROW EXISTENCE, and proves
 *      the granularity trap on itself at the end rather than asserting it.
 *
 * Run: sudo -u looth-dev wp --path=/var/www/dev eval-file <this>
 */

require_once '/srv/bb-mirror/config.php';
require_once '/srv/bb-mirror/lib/materializers.php';   // DEPLOYED copy = what live runs
global $wpdb;
$db = bb_mirror_db(false);

// A REAL orphan: exists in WP as a published reply, has no _bbp_topic_id at all.
$id = 71433;
$type = $wpdb->get_var("SELECT post_type FROM {$wpdb->posts} WHERE ID=$id");
$meta = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id=$id AND meta_key='_bbp_topic_id'");
echo "reply $id: post_type=$type  _bbp_topic_id rows in wp_postmeta=$meta\n";

$cnt = $db->prepare("SELECT count(*) FROM reply WHERE id = ?");
$cnt->execute([$id]); $before = (int)$cnt->fetchColumn();
echo "mirror row before: $before\n\n";

$threw = false;
try { bb_mirror_upsert_reply($id, $db); }
catch (Throwable $e) { $threw = true; echo "THREW: ".get_class($e).": ".$e->getMessage()."\n"; }
$cnt->execute([$id]); $after = (int)$cnt->fetchColumn();

echo "threw:            " . ($threw ? "yes" : "NO — returned quietly") . "\n";
echo "mirror row after: $after\n";
echo "\nVERDICT: " . ((!$threw && $after === 0)
  ? "SILENT NO-WRITE confirmed on REAL data — no throw, no row, caller sees success."
  : "not reproduced") . "\n";

// And the flaw in the first probe, demonstrated rather than asserted.
echo "\n-- why the first probe's method was unsound --\n";
$live = (int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='reply' AND post_status='publish' ORDER BY ID DESC LIMIT 1");
$s = $db->prepare("SELECT extract(epoch from sync_at)::bigint FROM reply WHERE id = ?");
bb_mirror_upsert_reply($live, $db); $s->execute([$live]); $a = (int)$s->fetchColumn();
bb_mirror_upsert_reply($live, $db); $s->execute([$live]); $b = (int)$s->fetchColumn();
echo "two back-to-back REAL upserts of reply $live: sync_at $a -> $b\n";
echo ($b > $a ? "second write detected" : "second write INVISIBLE at 1-second granularity — this is how the first probe manufactured its 'no write'") . "\n";
