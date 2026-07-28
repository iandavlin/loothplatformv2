<?php
/**
 * verify-recipient-filter-at-scale.php — run the recipient filter against the REAL
 * resolved list-3 subscriber set, at full size, without creating a campaign or
 * sending anything.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * WHY THIS EXISTS. `verify-empty-means-no-send.php` proves the filter's LOGIC against
 * four subscribers and a stubbed source. It cannot see the two things that only
 * appear at scale, and both are places this would fail quietly rather than loudly:
 *
 *   1. THE SUBSCRIBER → WP-ID MAPPING. `wp_fc_subscribers.user_id` is NULL for some
 *      contacts and `wp_user_id_for()` falls back to matching on email. A subscriber
 *      that resolves to no WP user is DROPPED — correctly, since they have no bell
 *      rows — but if that fallback were broken the filter would drop real members and
 *      the symptom would be "quiet week", not an error.
 *   2. THE CHUNKING. The filter batches in 200s and rebuilds the caller's order at the
 *      end. A bug there loses or duplicates recipients silently.
 *
 * NOTHING IS SENT AND NO CAMPAIGN IS CREATED. The subscriber set is resolved the same
 * way LG_WD_Sender_FluentCRM does, but `subscribe()` is never called. The recap source
 * is driven through the `lg_wd_recap_fetch` seam so no loopback call is made either —
 * this box's bell is not live data anyway.
 *
 * THE NUMBERS IT PRINTS ARE DEV2's, NOT LIVE'S. dev2 and live hold different data and
 * different subscriber lists; the LIVE composition figures live in
 * docs/atlas/RECAP-SUPPRESSION-PROPOSAL.md §1. What is being proven here is the
 * PLUMBING at real cardinality, not the population.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php';

if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
	fwrite( STDERR, "FluentCRM not loaded\n" ); exit( 1 );
}

$fail = 0;
/**
 * Compare, but SUMMARISE arrays rather than dumping them. The first version of this
 * printed var_export() of a 1,568-element list twice per assertion — 46KB of output
 * for a run whose entire signal is eight OK/FAIL lines. On a box with seven resident
 * lanes and ~500MB headroom, a test that buries its own result is a defect in the
 * test.
 */
$brief = function ( $v ): string {
	if ( ! is_array( $v ) ) { return var_export( $v, true ); }
	$n = count( $v );
	if ( $n === 0 ) { return '[]'; }
	if ( $n <= 4 ) { return '[' . implode( ',', $v ) . ']'; }
	return sprintf( '[%d items: %s…%s]', $n, $v[0], $v[ $n - 1 ] );
};
$chk  = function ( string $what, $got, $want ) use ( &$fail, $brief ) {
	$ok = $got === $want;
	printf( "  %-52s got=%-22s want=%-22s %s\n", $what,
		$brief( $got ), $brief( $want ), $ok ? 'OK' : 'FAIL' );
	if ( ! $ok ) { $fail++; }
};

// ── Resolve the real recipient set, the way the sender does ──────────────────
$list_id = (string) ( ( get_option( 'lg_wd_settings' ) ?: [] )['fcrm_list_id'] ?? 3 );
$ids = \FluentCrm\App\Models\Subscriber::whereHas( 'lists', function ( $q ) use ( $list_id ) {
		$q->where( 'object_id', $list_id );
	} )->where( 'status', 'subscribed' )->pluck( 'id' )->toArray();
$ids = array_values( array_map( 'intval', $ids ) );

printf( "list %s, subscribed: %d subscribers (DEV2 — not live)\n\n", $list_id, count( $ids ) );
if ( count( $ids ) < 2 ) { fwrite( STDERR, "too few subscribers on this box to test scale\n" ); exit( 1 ); }

// How many of them map to a WP user at all? This is the mapping under test.
$mapped = 0;
foreach ( \FluentCrm\App\Models\Subscriber::whereIn( 'id', $ids )->get() as $s ) {
	$uid = (int) ( $s->user_id ?? 0 );
	if ( $uid < 1 && ! empty( $s->email ) ) {
		$u = get_user_by( 'email', $s->email );
		$uid = $u ? (int) $u->ID : 0;
	}
	if ( $uid > 0 ) { $mapped++; }
}
printf( "  of those, %d map to a WP user (%d do not — email-only contacts)\n\n",
	$mapped, count( $ids ) - $mapped );

$HAS = [ 'display_name' => 'T', 'dms' => [], 'notifications' => [], 'stale' => [ 'connection_request' => 1 ] ];
$install = function ( callable $give ) {
	remove_all_filters( 'lg_wd_recap_fetch' );
	add_filter( 'lg_wd_recap_fetch', function ( $pre, $want ) use ( $give ) {
		$out = [];
		foreach ( $want as $id ) { $out[ $id ] = $give( $id ); }
		return $out;
	}, 10, 3 );
};

echo "--- 1. everyone has something: the filter must return every MAPPED subscriber ---\n";
$install( fn( $id ) => $HAS );
$all = LG_WD_Recap_Source::recipients_with_something_waiting( $ids );
$chk( 'kept == mapped subscribers', count( $all ), $mapped );
$chk( 'no duplicates survived chunking', count( $all ), count( array_unique( $all ) ) );
$chk( 'every kept id was in the input', array_diff( $all, $ids ), [] );

echo "--- 2. order is the caller's, across chunk boundaries ---\n";
// The filter batches in 200s, so with a real list this genuinely crosses boundaries.
$expected_order = array_values( array_filter( $ids, fn( $i ) => in_array( $i, $all, true ) ) );
$chk( 'output order matches input order', $all, $expected_order );
printf( "      (%d subscribers, %d chunks of 200 — boundaries genuinely crossed)\n",
	count( $ids ), (int) ceil( count( $ids ) / 200 ) );

echo "--- 3. nobody has anything: NOBODY is mailed ---\n";
$install( fn( $id ) => [] );
$chk( 'all dropped', LG_WD_Recap_Source::recipients_with_something_waiting( $ids ), [] );

echo "--- 4. one member in the middle of the list has something ---\n";
// Pick a mapped subscriber from deep in the list so it is not the first chunk.
$probe_sub = $all[ intdiv( count( $all ), 2 ) ];
$probe_wp  = 0;
$ps = \FluentCrm\App\Models\Subscriber::find( $probe_sub );
$probe_wp = (int) ( $ps->user_id ?? 0 );
if ( $probe_wp < 1 && ! empty( $ps->email ) ) {
	$u = get_user_by( 'email', $ps->email ); $probe_wp = $u ? (int) $u->ID : 0;
}
$install( fn( $id ) => $id === $probe_wp ? $HAS : [] );
$chk( "exactly subscriber $probe_sub (wp:$probe_wp) kept",
	LG_WD_Recap_Source::recipients_with_something_waiting( $ids ), [ $probe_sub ] );

echo "--- 5. an unreachable source keeps EVERYONE, at scale ---\n";
echo "      (failing closed here would mail nobody and look like a quiet week)\n";
remove_all_filters( 'lg_wd_recap_fetch' );
add_filter( 'lg_wd_recap_fetch', fn( $pre, $want ) => false, 10, 3 );
$open = LG_WD_Recap_Source::recipients_with_something_waiting( $ids );
$chk( 'every mapped subscriber kept', count( $open ), $mapped );
$chk( 'the outage is visible', LG_WD_Recap_Source::source_answered(), false );

echo "\n--- what this does NOT prove ---\n";
echo "  No campaign was created and nothing was sent. The sender's own early return\n";
echo "  (nobody has anything -> no campaign at all) is still unexercised, and these\n";
echo "  are DEV2 numbers — the live population is in RECAP-SUPPRESSION-PROPOSAL §1.\n";

remove_all_filters( 'lg_wd_recap_fetch' );
echo $fail ? "\n$fail FAILED\n" : "\nRECIPIENT FILTER HOLDS AT SCALE\n";
exit( $fail ? 1 : 0 );
