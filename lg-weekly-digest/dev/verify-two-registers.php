<?php
/**
 * verify-two-registers.php — Ian's 2026-07-28 ruling: fresh items get NAMED,
 * stale-but-unresolved items get COUNTED.
 *
 *   sudo -u looth-dev wp eval-file <this file>
 *
 * "If it's fired once and not been resolved, leave it out of the next email. Or
 * perhaps we throw a number at it like the fresh ones have a name and the stale
 * ones have a collective number like You have 6 connection requests."
 *
 * The design dissolves the seen-vs-resolved question rather than answering it: an
 * item is NAMED once, then COUNTED until resolved. Resolved-state is still needed
 * to know when to STOP counting, but no longer to decide whether to name.
 *
 * WHAT MAKES AN ITEM "ALREADY NAMED" IS THE FIXED WINDOW ITSELF — inside it, new;
 * outside it, it was in a previous email. No per-item stamp and no per-member send
 * record, which matters because the send record was Rule 3b and Ian declined it.
 *
 * Pure in-memory. No DB, no mail, no network.
 */
if (!defined('ABSPATH')) { fwrite(STDERR,"wp eval-file\n"); exit(1); }
$L='/home/ubuntu/worktrees/weekly-digest-recap';
require_once __DIR__ . '/_load-under-test.php';
lg_wd_load_under_test($L . '/lg-weekly-digest/includes/class-lg-wd-recap.php', 'LG_WD_Recap');
$fail=0;
$ck=function($w,$g,$e)use(&$fail){$o=$g===$e;printf("  %-52s %s\n",$w,$o?'OK':"FAIL got=".var_export($g,true));if(!$o)$fail++;};

$fresh=['type'=>'connection_request','actor_count'=>1,'actor_name'=>'Jim Glinsky','actor_slug'=>'jim',
        'target_kind'=>null,'target_id'=>null,'anchor_id'=>null,'target_url'=>null,'title'=>''];

echo "--- stale-only member: counted register renders, section EXISTS ---\n";
$p=['display_name'=>'Doron','notifications'=>[],'dms'=>[],'stale'=>['connection_request'=>1]];
$r=LG_WD_Recap::build_rows($p);
$ck('one row',count($r),1);
$ck('Ian\'s copy, singular',$r[0]['lead'],'You have 1 connection request waiting');
$ck('section is not empty',LG_WD_Recap::render($p)!=='' ,true);

echo "--- plural, and his own example number ---\n";
$p6=['display_name'=>'X','notifications'=>[],'dms'=>[],'stale'=>['connection_request'=>6]];
$ck('six reads as he wrote it',LG_WD_Recap::build_rows($p6)[0]['lead'],'You have 6 connection requests waiting');

echo "--- both registers: named first, counted last ---\n";
$pb=['display_name'=>'X','notifications'=>[$fresh],'dms'=>[],'stale'=>['connection_request'=>2,'forum.mention'=>1]];
$rb=LG_WD_Recap::build_rows($pb);
$ck('3 rows',count($rb),3);
$ck('named row is first',$rb[0]['lead'],'Jim Glinsky wants to connect');
$ck('counted rows are last',$rb[2]['kind'],'stale');
$ck('mention counted line',$rb[1]['lead'],'You have 1 mention waiting');

echo "--- a truly empty member still renders NOTHING ---\n";
$ck('no rows',LG_WD_Recap::build_rows(['display_name'=>'X','notifications'=>[],'dms'=>[],'stale'=>[]]),[]);
$ck('render is empty string',LG_WD_Recap::render(['display_name'=>'X','notifications'=>[],'dms'=>[],'stale'=>[]]),'');
$ck('zero counts are not rows',LG_WD_Recap::build_rows(['display_name'=>'X','notifications'=>[],'dms'=>[],'stale'=>['connection_request'=>0]]),[]);

echo "--- excluded types cannot enter the counted register either ---\n";
$ck('reaction.on_post stale is ignored',LG_WD_Recap::build_rows(['display_name'=>'X','notifications'=>[],'dms'=>[],'stale'=>['reaction.on_post'=>9]]),[]);
$ck('connection_accept stale is ignored',LG_WD_Recap::build_rows(['display_name'=>'X','notifications'=>[],'dms'=>[],'stale'=>['connection_accept'=>9]]),[]);
echo $fail?"\n$fail FAILED\n":"\nTWO REGISTERS OK\n";
