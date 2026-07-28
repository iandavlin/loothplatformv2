<?php
/**
 * verify-window-fixed.php — assert the digest's lookback window is FIXED at 7 days.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * Ian ruled the fixed 7-day window on 2026-07-28, over this lane's own
 * recommendation of a per-member window (Rule 3b). This is the regression test that
 * keeps the ruling true, because the failure mode is silent: a widened window does
 * not error, it just mails people things they were already told about.
 *
 * THREE ASSERTIONS, AND THE THIRD IS THE ONLY ONE THAT PROVES BEHAVIOUR:
 *
 *   1. the constant is 7                    — the decision, as declared
 *   2. fetch() takes exactly ONE parameter  — nobody re-added a per-call override,
 *                                             which is how a second window starts
 *   3. the value that actually LEAVES for the endpoint is 7 — exercised through the
 *      real fetch() path, not read back off the constant
 *
 * #3 is written so it CANNOT PASS VACUOUSLY. The captured value starts as null, so
 * if the filter never fires — wrong hook name, fetch() short-circuiting earlier, the
 * call path rearranged — the comparison fails rather than silently agreeing. An
 * assertion that passes when nothing ran is worse than no assertion, and this repo
 * has already shipped one of those (a gate asserting on a dead slug's echo).
 *
 * Pure in-memory. No DB, no mail, no network — the filter short-circuits the
 * loopback POST before it happens.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php';

$fail = 0;
$chk  = function ( string $what, $got, $want ) use ( &$fail ) {
	$ok = $got === $want;
	printf( "  %-46s got=%-8s want=%-8s %s\n",
		$what,
		var_export( $got, true ),
		var_export( $want, true ),
		$ok ? 'OK' : 'FAIL' );
	if ( ! $ok ) { $fail++; }
};

echo "--- 1. the decision, as declared ---\n";
$chk( 'LG_WD_Recap_Source::WINDOW_DAYS', LG_WD_Recap_Source::WINDOW_DAYS, 7 );

echo "--- 2. the window is not a per-call override ---\n";
$r = new ReflectionMethod( 'LG_WD_Recap_Source', 'fetch' );
$chk( 'fetch() parameter count', $r->getNumberOfParameters(), 1 );
$chk( 'fetch() first parameter', $r->getParameters()[0]->getName(), 'wp_user_ids' );

echo "--- 3. the value that actually leaves for the endpoint ---\n";
$seen = null;                       // stays null if the path never runs -> FAIL, not a pass
add_filter( 'lg_wd_recap_fetch', function ( $pre, $want, $days ) use ( &$seen ) {
	$seen = $days;
	return [];                      // short-circuit: no loopback POST, no DB
}, 10, 3 );

LG_WD_Recap_Source::fetch( [ 1 ] );
$chk( 'days handed to the fetch seam (1 member)', $seen, 7 );

$seen = null;
LG_WD_Recap_Source::fetch( [ 8, 9, 197 ] );
$chk( 'days handed to the fetch seam (batch)', $seen, 7 );

echo "\n--- the guard proves itself: an unrun path must FAIL, not pass ---\n";
$seen = null;
LG_WD_Recap_Source::fetch( [] );    // empty batch returns before the filter fires
printf( "  empty batch never reaches the seam, captured=%s -> a bare 'no complaint'\n",
	var_export( $seen, true ) );
printf( "  would have read as success here; compared against 7 it reads as %s.\n",
	$seen === 7 ? 'OK' : 'FAIL' );

echo "\n--- what Ian ruled, restated where the test can see it ---\n";
echo "  FIXED 7-day window, 2026-07-28, over Rule 3b (per-member watermark).\n";
echo "  ACCEPTED CONSEQUENCE: a member who misses one digest never hears about\n";
echo "  that week. Items older than 7 days are gone from the email permanently.\n";
echo "  Do NOT widen this to compensate. docs/atlas/RECAP-SUPPRESSION-PROPOSAL.md §2.\n";

echo $fail ? "\n$fail FAILED\n" : "\nWINDOW IS FIXED AT 7\n";
exit( $fail ? 1 : 0 );
