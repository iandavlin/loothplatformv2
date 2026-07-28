<?php
/**
 * verify-source-boundary.php — assert exactly what the recap reports on.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * LG_WD_Recap::INCLUDED_TYPES is an ALLOW-LIST, and this is the regression test
 * that keeps it one. It matters for the open SS9.1 question (per-event email vs
 * digest for discussion activity): if the digest ever silently absorbed a new
 * notification type while per-event email was also on, a member would get the same
 * reply twice. The specific type at stake — `forum.followed_topic`, which the
 * thread-follow lane proposes — is asserted EXCLUDED below.
 *
 * No de-duplication is built, on purpose: there is no ruling yet to de-duplicate
 * against. When there is, it is one line in INCLUDED_TYPES, and this test is where
 * you will see the change land.
 *
 * Pure in-memory. No DB, no mail, no network.
 */
if (!defined('ABSPATH')) { fwrite(STDERR,"wp eval-file\n"); exit(1); }
require_once '/home/ubuntu/worktrees/weekly-digest-recap/lg-weekly-digest/includes/class-lg-wd-recap.php';

$hub = fn($t) => ['type'=>$t,'target_kind'=>'topic','target_id'=>71865,'anchor_id'=>null,
  'target_url'=>'/hub/?topic=tools-and-jigs%2Fsuggest-an-alternative-to-concave-fret-file',
  'actor_count'=>1,'actor_name'=>'Doug Proper','actor_slug'=>'the-guitar-specialist',
  'title'=>'Suggest an alternative to concave fret file'];

$fail = 0;
echo "--- every INCLUDED type must produce exactly one row ---\n";
foreach (array_keys(LG_WD_Recap::INCLUDED_TYPES) as $t) {
    $rows = LG_WD_Recap::build_rows(['display_name'=>'Test','notifications'=>[$hub($t)],'dms'=>[]]);
    $ok = count($rows) === 1;
    printf("  %-24s rows=%d  %s\n", $t, count($rows), $ok?'OK':'FAIL');
    if(!$ok) $fail++;
}

echo "--- types OUTSIDE the boundary must produce ZERO rows ---\n";
// forum.followed_topic is the one thread-follow proposes (SS9.1). It must not leak in.
//
// connection_accept and reaction.on_post are here because IAN REMOVED THEM on
// 2026-07-28: the digest is a to-do list, and nothing is owed on either. They were
// shipping types until that day, so they are the regression most likely to walk back
// in — a well-meaning "the recap lost reactions" bug report is all it would take.
// The test for any candidate is: DOES THIS WAIT ON THE MEMBER?
foreach (['connection_accept','reaction.on_post',
          'forum.followed_topic','message','moderation.removed','event.reminder','','bogus.type'] as $t) {
    $rows = LG_WD_Recap::build_rows(['display_name'=>'Test','notifications'=>[$hub($t)],'dms'=>[]]);
    $ok = count($rows) === 0;
    printf("  %-24s rows=%d  %s\n", $t===''?"(empty string)":$t, count($rows), $ok?'OK (excluded)':'FAIL — LEAKED IN');
    if(!$ok) $fail++;
}

echo "--- a mixed payload reports only the included half ---\n";
// Was mention + reaction.on_post as the two INCLUDED types. Repointed 2026-07-28:
// reaction.on_post is now excluded, so leaving it here would have made this
// assertion fail for the right reason at the wrong line. connection_request is the
// second included type now — and it is the archetype of the to-do test.
$mixed = LG_WD_Recap::build_rows(['display_name'=>'Test','dms'=>[],'notifications'=>[
  $hub('forum.mention'), $hub('forum.followed_topic'), $hub('connection_request'), $hub('reaction.on_post')]]);
$ok = count($mixed) === 2;
printf("  4 in (2 included, 2 not) -> rows=%d  %s\n", count($mixed), $ok?'OK':'FAIL');
if(!$ok) $fail++;

echo "\n--- the boundary, as the code declares it ---\n";
foreach (LG_WD_Recap::INCLUDED_TYPES as $t=>$b) printf("  INCLUDED  %-24s -> %s\n", $t, $b);
echo "  DMs: read from message_recipients (not a notification type)\n";
echo $fail ? "\n$fail FAILED\n" : "\nBOUNDARY HOLDS\n";
