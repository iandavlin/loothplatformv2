<?php
/**
 * verify-kept-but-empty.php — does every member the recipient filter KEEPS
 * actually get a section? RED means someone is mailed a digest with nothing in the
 * place the digest exists to put something.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 *
 * ── THE RULE UNDER TEST ──────────────────────────────────────────────────────
 *
 * "EMPTY MEANS SEND NOTHING" (Ian, 2026-07-28): a MEMBER with nothing waiting gets
 * no email at all, not the digest minus the section. LG_WD_Recap_Source
 * ::recipients_with_something_waiting() is what enforces it.
 *
 * ── WHY THIS CAN FAIL, AND WHY NO EXISTING TEST SEES IT ──────────────────────
 *
 * The filter and the renderer answer the same question through DIFFERENT rules:
 *
 *   the filter   asks "is the payload non-empty?" — a SHAPE test on whatever the
 *                endpoint returned (fetch(), the `empty(notifications) &&
 *                empty(dms) && empty(stale)` normalisation)
 *   the renderer asks "does any of it survive the SOURCE BOUNDARY?" —
 *                LG_WD_Recap::INCLUDED_TYPES for live rows, and the `$labels` map in
 *                rows_from_stale() for counted ones
 *
 * /internal/recap is a general read API and does NOT apply the digest's boundary: it
 * returns every type the bell stores, including the ones the digest DELIBERATELY
 * refuses (LG_WD_Recap::DECIDED_EXCLUDED — reaction.on_post, connection_accept).
 *
 * So a member whose entire week is `reaction.on_post` has a non-empty payload and an
 * empty section. The filter keeps them; the renderer draws nothing; they are mailed
 * a digest whose personal section is missing — the exact outcome the rule forbids.
 *
 * It is invisible to the existing suite because every other recap test asserts what
 * should be PRESENT. This one asserts agreement between two components that were
 * each correct alone.
 *
 * READ-ONLY. Renders in memory, sends nothing, writes nothing.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

// The flag is OFF on this box and stays OFF — turn it on for this process only, or
// recipients_with_something_waiting() short-circuits and returns its input.
add_filter( 'lg_wd_recap_enabled', '__return_true' );

/** The real recipient set: the two lists the sender resolves (members + non-members). */
$settings = LG_WD_Settings::get_all();
$list_ids = array_unique( [
	(int) ( $settings['fcrm_list_id'] ?? 3 ),
	(int) ( $settings['fcrm_nonmember_list_id'] ?? 7 ),
] );

global $wpdb;
$in  = implode( ',', array_map( 'intval', $list_ids ) );
$ids = $wpdb->get_col(
	"SELECT DISTINCT s.id FROM {$wpdb->prefix}fc_subscribers s
	 JOIN {$wpdb->prefix}fc_subscriber_pivot p
	   ON p.subscriber_id = s.id AND p.object_type = 'FluentCrm\\\\App\\\\Models\\\\Lists'
	 WHERE s.status = 'subscribed' AND p.object_id IN ($in)"
);
$ids = array_map( 'intval', $ids );

printf( "lists %s → %d subscribed contacts\n", implode( '+', $list_ids ), count( $ids ) );

$kept = LG_WD_Recap_Source::recipients_with_something_waiting( $ids );
printf( "recipient filter KEEPS: %d   (suppressed %d)\n\n", count( $kept ), count( $ids ) - count( $kept ) );

if ( ! LG_WD_Recap_Source::source_answered() ) {
	echo "⚠️  the recap source did not answer — the filter failed OPEN and these\n"
	   . "    numbers describe an outage, not a quiet week. Not a valid run.\n";
	exit( 1 );
}

$empty = [];
$drawn = 0;
$no_wp = 0;
foreach ( $kept as $sid ) {
	$sub = \FluentCrm\App\Models\Subscriber::find( $sid );
	if ( ! $sub ) { continue; }

	$section = LG_WD_Recap_Source::render_for_subscriber( $sub );
	if ( $section !== '' ) { $drawn++; continue; }

	// A contact with no WP account is KEPT ON PURPOSE (Ian, 2026-07-30): the email
	// announces public content and non-members are on the list because it is for
	// them. Their empty section is correct, not a defect — count them separately.
	$uid = (int) ( $sub->user_id ?? 0 );
	if ( $uid < 1 ) {
		$u = get_user_by( 'email', (string) $sub->email );
		$uid = $u ? (int) $u->ID : 0;
	}
	if ( $uid < 1 ) { $no_wp++; continue; }

	$raw = LG_WD_Recap_Source::payload_for( $uid );
	$empty[] = [
		'sub'    => $sid,
		'wp'     => $uid,
		'name'   => (string) ( $sub->full_name ?: $sub->email ),
		'types'  => array_values( array_unique( array_column( $raw['notifications'] ?? [], 'type' ) ) ),
		'stale'  => array_keys( array_filter( (array) ( $raw['stale'] ?? [] ) ) ),
	];
}

printf( "kept AND drew a section          : %d\n", $drawn );
printf( "kept, no WP account (correct)     : %d\n", $no_wp );
printf( "kept, IS a member, drew NOTHING   : %d\n\n", count( $empty ) );

if ( ! $empty ) {
	echo "GREEN — every member the filter keeps gets a section.\n";
	exit( 0 );
}

echo "RED — these members are mailed a digest with an empty personal section:\n\n";
foreach ( $empty as $e ) {
	printf( "  sub %-5d wp %-5d %-36s live=%-22s stale=%s\n",
		$e['sub'], $e['wp'], substr( $e['name'], 0, 36 ),
		$e['types'] ? implode( ',', $e['types'] ) : '—',
		$e['stale'] ? implode( ',', $e['stale'] ) : '—' );
}

echo "\nEvery type listed above is in LG_WD_Recap::DECIDED_EXCLUDED — the digest\n"
   . "refuses them on Ian's to-do test. The filter does not know that.\n";
exit( 1 );
