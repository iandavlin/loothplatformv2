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
 *      contacts and `wp_user_id_for()` falls back to matching on email. If that
 *      fallback were broken the filter would treat real members as account-less and
 *      the symptom would be "quiet week", not an error.
 *
 *      ⚠ THE RULE THIS ENCODES CHANGED ON 2026-07-30, and the old assertions caught
 *      the change loudly, which is the point of having them. A subscriber with no WP
 *      account used to be DROPPED ("no account, no bell rows, nothing waiting"). It
 *      is now KEPT: Ian ruled that the email announces the week's public content to
 *      everyone on the list and that non-members are on it because the announcement
 *      is for them. Their email is not a to-do list, so an empty to-do list is not a
 *      reason to withhold it. A MEMBER with nothing waiting is still dropped — Rule 5
 *      is untouched for the people it was actually about.
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
require_once __DIR__ . '/_load-under-test.php';
lg_wd_load_under_test($LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php', 'LG_WD_Recap');
lg_wd_load_under_test($LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php', 'LG_WD_Recap_Source');

/**
 * ── THE FLAG MUST BE ON OR THIS FILE TESTS NOTHING (added 2026-07-31) ────────
 *
 * See the same note in verify-empty-means-no-send.php. `recipients_with_something_
 * waiting()` returns its input verbatim while the flag is off, so every assertion here
 * was measuring the pass-through: "no member with an empty to-do list survives" got
 * 1,596. RED since the master switch landed, for a reason that had nothing to do with
 * the filter's logic — and therefore hiding whether that logic still works at all.
 */
add_filter( 'lg_wd_recap_enabled', '__return_true' );
if ( ! LG_WD_Recap_Source::recap_enabled() ) {
	fwrite( STDERR, "CANNOT RUN: could not enable the recap in-process\n" ); exit( 2 );
}

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

// How many of them map to a WP user at all? This is the mapping under test — and
// since 07-30 the two groups have DIFFERENT outcomes, so both sets are kept, not
// just the count.
$mapped = 0;
$unmapped_ids = [];
foreach ( \FluentCrm\App\Models\Subscriber::whereIn( 'id', $ids )->get() as $s ) {
	$uid = (int) ( $s->user_id ?? 0 );
	if ( $uid < 1 && ! empty( $s->email ) ) {
		$u = get_user_by( 'email', $s->email );
		$uid = $u ? (int) $u->ID : 0;
	}
	if ( $uid > 0 ) { $mapped++; } else { $unmapped_ids[] = (int) $s->id; }
}
// Caller order, so it can be compared against filter output directly.
$unmapped = array_values( array_filter( $ids, fn( $i ) => in_array( $i, $unmapped_ids, true ) ) );
printf( "  of those, %d map to a WP user (%d do not — account-less contacts, now KEPT)\n\n",
	$mapped, count( $unmapped ) );

$HAS = [ 'display_name' => 'T', 'dms' => [], 'notifications' => [], 'stale' => [ 'connection_request' => 1 ] ];
$install = function ( callable $give ) {
	remove_all_filters( 'lg_wd_recap_fetch' );
	add_filter( 'lg_wd_recap_fetch', function ( $pre, $want ) use ( $give ) {
		$out = [];
		foreach ( $want as $id ) { $out[ $id ] = $give( $id ); }
		return $out;
	}, 10, 3 );
};

echo "--- 1. everyone has something: the filter must return EVERY subscriber ---\n";
$install( fn( $id ) => $HAS );
$all = LG_WD_Recap_Source::recipients_with_something_waiting( $ids );
$chk( 'kept == every subscriber (mapped + account-less)', count( $all ), count( $ids ) );
$chk( 'no duplicates survived chunking', count( $all ), count( array_unique( $all ) ) );
$chk( 'every kept id was in the input', array_diff( $all, $ids ), [] );

echo "--- 2. order is the caller's, across chunk boundaries ---\n";
// The filter batches in 200s, so with a real list this genuinely crosses boundaries.
$expected_order = array_values( array_filter( $ids, fn( $i ) => in_array( $i, $all, true ) ) );
$chk( 'output order matches input order', $all, $expected_order );
printf( "      (%d subscribers, %d chunks of 200 — boundaries genuinely crossed)\n",
	count( $ids ), (int) ceil( count( $ids ) / 200 ) );

echo "--- 3. no MEMBER has anything: every member dropped, every non-member kept ---\n";
echo "      (this is the assertion the 07-30 ruling changed — it used to be []).\n";
$install( fn( $id ) => [] );
$none = LG_WD_Recap_Source::recipients_with_something_waiting( $ids );
$chk( 'exactly the account-less contacts survive', $none, $unmapped );
$chk( 'no member with an empty to-do list survives',
	count( array_intersect( $none, array_diff( $ids, $unmapped ) ) ), 0 );

echo "--- 4. one member in the middle of the list has something ---\n";
// Pick a MAPPED subscriber from deep in the list so it is not the first chunk.
// It must be mapped, or the "one member has something" case would silently become
// "one non-member is kept anyway" and prove nothing about the member path.
$mapped_ids = array_values( array_diff( $all, $unmapped ) );
$probe_sub  = $mapped_ids[ intdiv( count( $mapped_ids ), 2 ) ];
$probe_wp  = 0;
$ps = \FluentCrm\App\Models\Subscriber::find( $probe_sub );
$probe_wp = (int) ( $ps->user_id ?? 0 );
if ( $probe_wp < 1 && ! empty( $ps->email ) ) {
	$u = get_user_by( 'email', $ps->email ); $probe_wp = $u ? (int) $u->ID : 0;
}
$install( fn( $id ) => $id === $probe_wp ? $HAS : [] );
$want_probe = array_values( array_filter( $ids,
	fn( $i ) => $i === $probe_sub || in_array( $i, $unmapped, true ) ) );
$chk( "subscriber $probe_sub (wp:$probe_wp) + the account-less kept",
	LG_WD_Recap_Source::recipients_with_something_waiting( $ids ), $want_probe );

echo "--- 5. an unreachable source keeps EVERYONE, at scale ---\n";
echo "      (failing closed here would mail nobody and look like a quiet week)\n";
remove_all_filters( 'lg_wd_recap_fetch' );
add_filter( 'lg_wd_recap_fetch', fn( $pre, $want ) => false, 10, 3 );
$open = LG_WD_Recap_Source::recipients_with_something_waiting( $ids );
$chk( 'every subscriber kept', count( $open ), count( $ids ) );
$chk( 'the outage is visible', LG_WD_Recap_Source::source_answered(), false );

echo "\n--- 6. THE NON-MEMBER STORE: the sender's real two-list audience ---\n";
echo "      Ian's backlog asks for 'the digest sender reading that store alongside\n";
echo "      members'. Resolved here the way LG_WD_Sender_FluentCRM now resolves it.\n";
$nm_list = (string) ( ( get_option( 'lg_wd_settings' ) ?: [] )['fcrm_nonmember_list_id'] ?? 7 );
$nm_ids  = \FluentCrm\App\Models\Subscriber::whereHas( 'lists', function ( $q ) use ( $nm_list ) {
		$q->where( 'object_id', $nm_list );
	} )->where( 'status', 'subscribed' )->pluck( 'id' )->toArray();
$nm_ids  = array_values( array_map( 'intval', $nm_ids ) );
printf( "      list %s (non-members), subscribed: %d on DEV2\n", $nm_list, count( $nm_ids ) );

if ( ! $nm_ids ) {
	// Not a pass. A box with an empty non-member list cannot prove this property,
	// and saying so is the whole point of the CANNOT-RUN convention.
	printf( "  %-52s %s\n", 'CANNOT PROVE: non-member list is empty on this box', 'DEAD' );
	$fail++;
} else {
	$union = array_values( array_unique( array_merge( $ids, $nm_ids ) ) );
	printf( "      union of both lists: %d (overlap %d)\n",
		count( $union ), count( $ids ) + count( $nm_ids ) - count( $union ) );

	// The case that matters: NOBODY has a to-do item. Every non-member must still
	// be mailed — this is exactly the 195 people the old filter deleted on live.
	$install( fn( $id ) => [] );
	$kept = LG_WD_Recap_Source::recipients_with_something_waiting( $union );

	// A list-7 contact who ALSO has a WP account is a member by Ian's ruling-6 test
	// and is legitimately subject to Rule 5, so the property is asserted over the
	// account-less ones — the people the signup page actually creates.
	$nm_accountless = [];
	foreach ( \FluentCrm\App\Models\Subscriber::whereIn( 'id', $nm_ids )->get() as $s ) {
		$uid = (int) ( $s->user_id ?? 0 );
		if ( $uid < 1 && ! empty( $s->email ) ) {
			$u = get_user_by( 'email', $s->email ); $uid = $u ? (int) $u->ID : 0;
		}
		if ( $uid < 1 ) { $nm_accountless[] = (int) $s->id; }
	}
	printf( "      of those, %d have no WP account (%d do — members on the wrong list)\n",
		count( $nm_accountless ), count( $nm_ids ) - count( $nm_accountless ) );
	$chk( 'every account-less non-member survives an empty week',
		count( array_diff( $nm_accountless, $kept ) ), 0 );
	$chk( 'and none of them was duplicated', count( $kept ), count( array_unique( $kept ) ) );
}

echo "\n--- what this does NOT prove ---\n";
echo "  No campaign was created and nothing was sent. The sender's own early return\n";
echo "  (nobody has anything -> no campaign at all) is still unexercised, and these\n";
echo "  are DEV2 numbers — the live population is in RECAP-SUPPRESSION-PROPOSAL §1.\n";

remove_all_filters( 'lg_wd_recap_fetch' );
echo $fail ? "\n$fail FAILED\n" : "\nRECIPIENT FILTER HOLDS AT SCALE\n";
exit( $fail ? 1 : 0 );
