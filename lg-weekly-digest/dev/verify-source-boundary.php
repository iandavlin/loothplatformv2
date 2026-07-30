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

/**
 * ── EVERY PLATFORM TYPE MUST HAVE A DECISION, NOT JUST AN OUTCOME ────────────
 *
 * Everything above probes a HARDCODED list of type strings. That proves the
 * allow-list refuses what I thought to ask about, which is a weaker property than
 * it looks: when the thread-follow lane added `forum.followed_topic` to profile-app,
 * this digest excluded it correctly and SILENTLY — the right answer arrived by
 * accident, and if the right answer had been "include it" nothing here would have
 * said so. The next type will be the same, and it might be a moderation action or
 * an event reminder, where "no" is not obvious.
 *
 * So: read the platform's OWN type list out of profile-app/src/Notifications.php and
 * require that each type appears in INCLUDED_TYPES or in DECIDED_EXCLUDED. Adding a
 * notification type anywhere on the platform now turns this suite red until someone
 * writes down what the digest should do with it.
 *
 * Parsed from SOURCE rather than from the class: profile-app's classes are loaded by
 * its own config.php under the profile-app user and are not available in the WP
 * process this runs in. A source read is also the honest boundary — the digest and
 * profile-app are different applications on different databases.
 */
echo "--- every platform notification type has a recorded decision ---\n";
// Overridable so the "a new type has no decision" branch can be PROVEN rather than
// assumed — point it at a copy carrying an invented type and this must go RED:
//   sudo -u looth-dev env LG_NOTIF_SRC=/tmp/fake.php wp ... eval-file <this>
// (`env`, not a bare VAR=… prefix: sudo drops the environment, which already made
// one red proof on this branch pass vacuously against the real file.)
$np = getenv('LG_NOTIF_SRC') ?: '/home/ubuntu/worktrees/weekly-digest-recap/profile-app/src/Notifications.php';
if (!is_readable($np)) {
    printf("  %-52s %s\n", 'CANNOT READ profile-app/src/Notifications.php', 'DEAD');
    $fail++;
} else {
    $src = (string) file_get_contents($np);
    // Strip comments first: the TYPES array carries a long prose comment naming
    // other type strings, and a raw regex over it would invent types that do not
    // exist. (Same trap verify-signup-audience hit — a source assertion that reads
    // prose is defeated by any honest comment about the thing it inspects.)
    $bare = '';
    foreach (token_get_all($src) as $tk) {
        $bare .= is_array($tk)
            ? (in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $tk[1])
            : $tk;
    }
    /**
     * BOTH constants, and missing the second one is exactly the mistake this check
     * caught on its first run. profile-app splits its vocabulary in two:
     *   TYPES      = 'message', 'connection_request', 'connection_accept'
     *   HUB_TYPES  = the forum/reaction events ('forum.mention', …, 'forum.followed_topic')
     * Reading only TYPES made every forum exclusion look STALE — the reverse check
     * below reported reaction.on_post and forum.followed_topic as decisions about
     * types the platform no longer writes, when in truth my parser could not see
     * them. A one-directional check would have passed and said nothing.
     */
    $types = [];
    foreach ( [ 'TYPES', 'HUB_TYPES' ] as $constName ) {
        if (preg_match('/const\s+' . $constName . '\s*=\s*\[(.*?)\];/s', $bare, $mm)) {
            preg_match_all("/'([a-z][a-z0-9_.]*)'/i", $mm[1], $tm);
            $types = array_merge($types, $tm[1]);
        } else {
            printf("  %-52s %s\n", "CANNOT PARSE Notifications::$constName", 'DEAD');
            $fail++;
        }
    }
    $types = array_values(array_unique($types));
    if (!$types) {
        printf("  %-52s %s\n", 'CANNOT PARSE Notifications::TYPES', 'DEAD');
        $fail++;
    } else {
        printf("  platform declares %d types\n", count($types));
        $undecided = [];
        foreach ($types as $t) {
            $in  = array_key_exists($t, LG_WD_Recap::INCLUDED_TYPES);
            $out = array_key_exists($t, LG_WD_Recap::DECIDED_EXCLUDED);
            printf("    %-24s %s\n", $t,
                $in ? 'INCLUDED' : ($out ? 'excluded, on purpose' : '?? NO DECISION RECORDED'));
            if (!$in && !$out) { $undecided[] = $t; }
        }
        if ($undecided) {
            printf("  %-52s FAIL: %s\n",
                'a new platform type has no decision', implode(', ', $undecided));
            echo "      Add it to LG_WD_Recap::INCLUDED_TYPES (with a bucket) or to\n";
            echo "      DECIDED_EXCLUDED (with the reason it fails the to-do test).\n";
            $fail++;
        } else {
            printf("  %-52s OK\n", 'every platform type is decided either way');
        }
        // The reverse leak: a type this digest claims to have decided but which the
        // platform no longer writes is a stale decision, and stale decisions are how
        // a register stops describing the system.
        $stale = array_diff(array_keys(LG_WD_Recap::DECIDED_EXCLUDED), $types);
        // 'message' is deliberately listed though profile-app treats it as dead code.
        $stale = array_diff($stale, ['message']);
        printf("  %-52s %s\n", 'no stale exclusions',
            $stale ? 'FAIL: ' . implode(', ', $stale) : 'OK');
        if ($stale) { $fail++; }
    }
}

echo "\n--- the boundary, as the code declares it ---\n";
foreach (LG_WD_Recap::INCLUDED_TYPES as $t=>$b) printf("  INCLUDED  %-24s -> %s\n", $t, $b);
echo "  DMs: read from message_recipients (not a notification type)\n";
echo $fail ? "\n$fail FAILED\n" : "\nBOUNDARY HOLDS\n";
