<?php
/**
 * bb-mirror/bin/test-ghost-sweep.php — safety tests for bb_mirror_sweep_ghosts().
 *
 * A GHOST is a mirror row whose WordPress post is gone. Unlike an orphan it is
 * status='publish' under a living topic, so IT STILL RENDERS — members see
 * replies that were deleted. Live carried 13 ghost replies + 2 ghost topics on
 * 2026-07-28, and no existing check could see them (the orphan census reads 0
 * for a ghost by definition). See docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md §7.
 *
 * THIS FUNCTION DELETES CONTENT, so the tests that matter are the ones asserting
 * it does NOT delete: an empty wp_posts result, an over-cap sweep, report-only
 * mode, and a ghost topic whose replies are still live in WordPress.
 *
 *   sudo -u looth-dev php bin/test-ghost-sweep.php
 *
 * No WordPress needed — the WP id set is injected, which is why the function
 * takes a closure. Runs against the scratch database only; see
 * bin/test-attachment-purge.php for the one-time setup of `orphan_proof`.
 * Requires the attachment purge triggers installed there (test 3 asserts the
 * trigger fires when a ghost row is swept).
 */
require __DIR__ . '/../lib/materializers.php';
$db = new PDO('pgsql:host=/var/run/postgresql;dbname=' . (getenv('BBM_TEST_DB') ?: 'orphan_proof') . '', null, null);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("SET search_path = forums, public");
$FAIL = 0;
function check(string $w, $got, $want): void {
    global $FAIL; $ok = $got === $want; if (!$ok) $FAIL++;
    printf("  [%s] %-46s got %s, want %s\n", $ok?"\033[32mPASS\033[0m":"\033[31mFAIL\033[0m",
        $w, var_export($got,true), var_export($want,true));
}
// Replies hang off topic 11. Topic 10 is deliberately CHILDLESS so the retyped
// case can be tested without the FK cascade confusing the result — that mistake
// is what surfaced the cascade hazard in the first place.
function reset_fixture(PDO $db, bool $replies_under_10 = false): void {
    $db->exec("DELETE FROM attachment; DELETE FROM reply; DELETE FROM topic; DELETE FROM forum;");
    $db->exec("INSERT INTO forum (id,slug,title,created_at,modified_at) VALUES (1,'f','F',now(),now())");
    $db->exec("INSERT INTO topic (id,forum_id,slug,title,created_at,modified_at)
               SELECT g,1,'t'||g,'T'||g,now(),now() FROM generate_series(10,14) g");
    $db->exec("INSERT INTO reply (id,topic_id,forum_id,status,created_at,modified_at)
               SELECT g,11,1,'publish',now(),now() FROM generate_series(100,109) g");
    if ($replies_under_10) {
        $db->exec("INSERT INTO reply (id,topic_id,forum_id,status,created_at,modified_at)
                   VALUES (200,10,1,'publish',now(),now()),(201,10,1,'publish',now(),now())");
    }
    $db->exec("INSERT INTO attachment (parent_kind,parent_id,url)
               SELECT 'reply',100,'ghost-img-'||g FROM generate_series(1,6) g");
}
$cnt = fn(PDO $d, string $t) => (int)$d->query("SELECT count(*) FROM $t")->fetchColumn();

echo "\n1. CLEAN\n"; reset_fixture($db);
$r = bb_mirror_sweep_ghosts($db, fn($k)=>$k==='topic'?range(10,14):range(100,109), true);
check('topic clean', $r['topic']['status'],'clean'); check('reply clean', $r['reply']['status'],'clean');
check('nothing deleted', $cnt($db,'reply'), 10);

echo "\n2. REPORT-ONLY — must delete NOTHING\n"; reset_fixture($db);
$gone = fn($k)=>$k==='topic'?range(10,13):range(101,109);   // topic 14 + reply 100 gone from WP
$r = bb_mirror_sweep_ghosts($db, $gone, false);
check('reply report_only', $r['reply']['status'],'report_only');
check('reply ghosts', $r['reply']['ghosts'], 1);
check('topic ghosts', $r['topic']['ghosts'], 1);
check('replies untouched', $cnt($db,'reply'), 10);
check('ghost images still present', $cnt($db,'attachment'), 6);

echo "\n3. SWEPT — row goes, trigger takes its 6 images\n"; reset_fixture($db);
$r = bb_mirror_sweep_ghosts($db, $gone, true);
check('reply swept', $r['reply']['status'],'swept');
check('reply 100 gone', $cnt($db,'reply'), 9);
check('topic 14 gone', $cnt($db,'topic'), 4);
check('6 images purged by trigger', $cnt($db,'attachment'), 0);

echo "\n4. ABORT — empty wp_posts must NOT mean 'delete everything'\n"; reset_fixture($db);
$r = bb_mirror_sweep_ghosts($db, fn($k)=>[], true);
check('topic abort', $r['topic']['status'],'abort_empty_wp');
check('reply abort', $r['reply']['status'],'abort_empty_wp');
check('ALL replies survived', $cnt($db,'reply'), 10);
check('ALL topics survived', $cnt($db,'topic'), 5);

echo "\n5. CAP — refuse and delete nothing\n"; reset_fixture($db);
$r = bb_mirror_sweep_ghosts($db, fn($k)=>$k==='topic'?[10]:[100], true, cap_abs:2, cap_pct:5);
check('topic refused', $r['topic']['status'],'refused_cap');
check('reply refused', $r['reply']['status'],'refused_cap');
check('nothing deleted (reply)', $cnt($db,'reply'), 10);
check('nothing deleted (topic)', $cnt($db,'topic'), 5);

echo "\n6. RETYPED — childless topic 10 is a reply in WP (live 71433's shape)\n"; reset_fixture($db);
$retyped = fn($k)=>$k==='topic'?range(11,14):array_merge(range(100,109),[10]);
$r = bb_mirror_sweep_ghosts($db, $retyped, true);
check('topic 10 is a ghost', $r['topic']['ghosts'], 1);
check('topic 10 removed', $cnt($db,'topic'), 4);
check('replies untouched', $cnt($db,'reply'), 10);

echo "\n7. HOLD — ghost topic whose replies are STILL LIVE in WP. Cascade hazard.\n";
reset_fixture($db, replies_under_10: true);   // replies 200,201 under topic 10
// topic 10 gone from WP, but replies 200/201 still exist there
$hazard = fn($k)=>$k==='topic'?range(11,14):array_merge(range(100,109),[200,201]);
$r = bb_mirror_sweep_ghosts($db, $hazard, true);
check('topic 10 HELD not swept', $r['topic']['held'], [10]);
check('nothing swept for topic', $r['topic']['ghosts'], 0);
check('topic 10 still present', $cnt($db,'topic'), 5);
check('its live replies SURVIVED', $cnt($db,'reply'), 12);

printf("\n%s\n", $FAIL===0 ? "\033[32mALL CHECKS PASSED\033[0m" : "\033[31m$FAIL CHECK(S) FAILED\033[0m");
exit($FAIL===0?0:1);
