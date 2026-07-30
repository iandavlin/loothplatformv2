<?php
/* Load the 07-27 renderer under a renamed class so it cannot collide with the
   deployed LG_WD_Recap, and render the SAME borrowed payload through it. */
$LANE='/home/ubuntu/worktrees/weekly-digest-recap';
$src = file_get_contents('/tmp/wd-recap-0727.php');
if (!$src) { fwrite(STDERR,"CANNOT RUN: could not read the 07-27 file from git\n"); exit(2); }
// \b after "Recap" does not match before "_", so LG_WD_Recap_Source is untouched.
$ren = preg_replace('/\bLG_WD_Recap\b/', 'LG_WD_Recap_0727', $src);
$ren = preg_replace('/^<\?php/', '', $ren, 1);
eval($ren);
if (!class_exists('LG_WD_Recap_0727')) { fwrite(STDERR,"CANNOT RUN: rename/eval failed\n"); exit(2); }
echo "loaded the 07-27 renderer as LG_WD_Recap_0727 (".strlen($src)." bytes of git history)\n";

require_once $LANE.'/lg-weekly-digest/dev/_load-under-test.php';
lg_wd_load_under_test($LANE.'/lg-weekly-digest/includes/class-lg-wd-recap-source.php','LG_WD_Recap_Source');
$recaps=LG_WD_Recap_Source::fetch([690,197,1891]);
$merged=['display_name'=>'Ian','notifications'=>[],'dms'=>[]];
foreach([690,197,1891] as $d) foreach(($recaps[$d]['notifications']??[]) as $n) $merged['notifications'][]=$n;

$old = LG_WD_Recap_0727::render($merged);
echo "\n--- 07-27 RENDERER, same ".count($merged['notifications'])." rows ---\n";
echo "bytes: ".strlen($old)."   <tr>: ".substr_count($old,'<tr')."\n";
echo trim(preg_replace('/\s+/',' ',strip_tags($old)))."\n";
