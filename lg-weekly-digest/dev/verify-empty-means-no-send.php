<?php
/**
 * verify-empty-means-no-send.php — the test of record for Ian's 2026-07-28 ruling:
 * a member with nothing waiting on them gets NO EMAIL AT ALL.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * This asserts the RECIPIENT FILTER, which is the mechanism that implements the
 * ruling: LG_WD_Recap_Source::recipients_with_something_waiting() runs before
 * $campaign->subscribe(), so a member with nothing never gets a CampaignEmail row
 * and is never mailed — as opposed to being mailed a digest with the section
 * missing, which is what shipped before the ruling.
 *
 * The older byte-identical empty-body proof still lives in verify-per-recipient.php
 * and still passes. It has been REPOINTED rather than deleted: it is now the
 * belt-and-braces behind this filter, not a description of what a member receives.
 *
 * WHY FAIL-OPEN IS ASSERTED HERE TOO, and it is the assertion that matters most:
 * if the recap source cannot be reached, everyone must come back as "has something"
 * and the send goes out whole. The opposite — failing closed — means one unreachable
 * endpoint silently mails NOBODY, and that failure is indistinguishable from a quiet
 * week. There is no error, no bounce, no complaint; the digest just stops. A gate
 * whose absent-signal branch is restrictive turns an outage into a total outage.
 *
 * No mail is sent, no campaign is created, no DB write happens. The fetch seam is
 * short-circuited by filter, so no loopback call is made either.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
require_once __DIR__ . '/_load-under-test.php';
lg_wd_load_under_test($LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php', 'LG_WD_Recap');
lg_wd_load_under_test($LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php', 'LG_WD_Recap_Source');

if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
	fwrite( STDERR, "FluentCRM not loaded — this test needs real Subscriber rows.\n" );
	exit( 1 );
}

/**
 * ── THE FLAG MUST BE ON OR THIS FILE TESTS NOTHING (added 2026-07-31) ────────
 *
 * `recipients_with_something_waiting()` opens with
 * `if ( ! self::recap_enabled() ) { return $subscriber_ids; }` — flag OFF means the
 * function RETURNS ITS INPUT VERBATIM and its body never runs.
 *
 * This test predates the master switch (0733ee4). When the flag landed, every
 * assertion below silently became a test of the pass-through: "all dropped" got
 * [1,2,3,4], "zero/negative ids are dropped" got [0,-5] back. It has been RED ever
 * since, which LOOKED like the test doing its job while the filter it exists to guard
 * had in fact been untested for the whole life of the flag.
 *
 * That is the box's standing trap — a flag-ON gate silently exercising the OFF path —
 * and the fix is to arm it here rather than to depend on the box's wp-config, which
 * is deliberately OFF and must stay that way.
 */
add_filter( 'lg_wd_recap_enabled', '__return_true' );
if ( ! LG_WD_Recap_Source::recap_enabled() ) {
	fwrite( STDERR, "CANNOT RUN: could not enable the recap in-process\n" );
	exit( 2 );
}

$fail = 0;
$chk  = function ( string $what, $got, $want ) use ( &$fail ) {
	$ok = $got === $want;
	printf( "  %-52s got=%-14s want=%-14s %s\n", $what,
		is_array( $got ) ? '[' . implode( ',', $got ) . ']' : var_export( $got, true ),
		is_array( $want ) ? '[' . implode( ',', $want ) . ']' : var_export( $want, true ),
		$ok ? 'OK' : 'FAIL' );
	if ( ! $ok ) { $fail++; }
};

/** Take real subscribers that DO map to a WP user, so the filter is exercised for real. */
$subs = \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )->where( 'user_id', '>', 0 )
	->orderBy( 'id' )->limit( 4 )->get();
if ( count( $subs ) < 3 ) {
	fwrite( STDERR, "need at least 3 WP-linked subscribers on this box\n" );
	exit( 1 );
}

$ids = [];
$wp  = [];
foreach ( $subs as $s ) { $ids[] = (int) $s->id; $wp[ (int) $s->id ] = (int) $s->user_id; }
printf( "subscribers under test: %s (wp: %s)\n\n", implode( ',', $ids ), implode( ',', $wp ) );

$HAS = [ 'display_name' => 'Test', 'dms' => [], 'notifications' => [ [
	'type' => 'connection_request', 'target_kind' => null, 'target_id' => null,
	'anchor_id' => null, 'target_url' => null, 'actor_count' => 1,
	'actor_name' => 'Doug Proper', 'actor_slug' => 'the-guitar-specialist',
	'created_at' => '2026-07-28 00:00:00',
] ] ];

/** Drive the fetch seam so no loopback call happens. $give maps wp id → payload. */
$install = function ( callable $give ) {
	remove_all_filters( 'lg_wd_recap_fetch' );
	add_filter( 'lg_wd_recap_fetch', function ( $pre, $want ) use ( $give ) {
		$out = [];
		foreach ( $want as $id ) { $out[ $id ] = $give( $id ); }
		return $out;
	}, 10, 3 );
};

echo "--- 1. everybody has something -> everybody is mailed ---\n";
$install( fn( $id ) => $HAS );
$chk( 'all kept', LG_WD_Recap_Source::recipients_with_something_waiting( $ids ), $ids );

echo "--- 2. nobody has anything -> NOBODY is mailed (the ruling) ---\n";
$install( fn( $id ) => [] );
$chk( 'all dropped', LG_WD_Recap_Source::recipients_with_something_waiting( $ids ), [] );

echo "--- 3. mixed: only the member with something waiting is mailed ---\n";
$only = $ids[1];
$install( fn( $id ) => $id === $wp[ $only ] ? $HAS : [] );
$chk( 'exactly the one kept', LG_WD_Recap_Source::recipients_with_something_waiting( $ids ), [ $only ] );

echo "--- 4. order is the caller's, not the chunk's ---\n";
$install( fn( $id ) => $HAS );
$rev = array_reverse( $ids );
$chk( 'reversed input returns reversed', LG_WD_Recap_Source::recipients_with_something_waiting( $rev ), $rev );

echo "--- 5. FAIL OPEN when the source is unreachable ---\n";
echo "     (the whole list must be kept; failing closed would mail nobody and look\n";
echo "      exactly like a quiet week, with no error anywhere)\n";
// FALSE means "the source is unavailable" — deliberately distinct from an array of
// nothing, which means "it answered and these members have nothing". Returning []
// here is what the FIRST version of this test did, and it passed for the wrong
// reason: [] is exactly what a healthy quiet week looks like, so it was testing
// case 2 again under a different name.
remove_all_filters( 'lg_wd_recap_fetch' );
add_filter( 'lg_wd_recap_fetch', fn( $pre, $want ) => false, 10, 3 );
$chk( 'unreachable source keeps everyone', LG_WD_Recap_Source::recipients_with_something_waiting( $ids ), $ids );
$chk( 'and the outage is visible, not guessed', LG_WD_Recap_Source::source_answered(), false );

echo "--- 6. degenerate inputs ---\n";
$install( fn( $id ) => $HAS );
$chk( 'empty list in, empty list out', LG_WD_Recap_Source::recipients_with_something_waiting( [] ), [] );
$chk( 'zero/negative ids are dropped', LG_WD_Recap_Source::recipients_with_something_waiting( [ 0, -5 ] ), [] );

echo "\n--- what this test is NOT ---\n";
echo "  It does not prove a campaign was skipped end to end. The sender's own\n";
echo "  early return (nobody has anything -> no campaign at all) is unexercised\n";
echo "  here; proving THAT needs a real send, which is Ian's to run.\n";

remove_all_filters( 'lg_wd_recap_fetch' );
echo $fail ? "\n$fail FAILED\n" : "\nEMPTY MEANS NO SEND\n";
exit( $fail ? 1 : 0 );
