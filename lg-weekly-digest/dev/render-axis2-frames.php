<?php
/**
 * render-axis2-frames.php — what an ALREADY-EMAILED suppression actually does to
 * the mail, drawn rather than argued.
 *
 *   wp eval-file lg-weekly-digest/dev/render-axis2-frames.php
 *
 * ── WHY THIS FRAME EXISTS ────────────────────────────────────────────────────
 *
 * Axis 2 sounds like it makes an email SHORTER. Under Ian's Rule 5 (empty means
 * send NO EMAIL AT ALL) it can make the email DISAPPEAR — and on today's live data
 * that is not the edge case, it is every single member it could touch:
 *
 *     258 members get a digest. 7 have any forum item.
 *     ALL 7 have ONLY forum items.
 *
 * The forum population and the connection population are currently DISJOINT, so
 * suppressing a member's already-emailed forum rows removes their whole digest. A
 * rule described as "do not repeat yourself" would, as ruled, mail nobody.
 * measure-suppression-axes.sh §6(b) re-derives those three numbers on demand.
 *
 * ── WHAT IS REAL HERE AND WHAT IS NOT. READ BEFORE QUOTING THE PICTURES. ─────
 *
 * REAL   the four single-row frames. Real members, real rows, real titles, from
 *        extract-ruled-frames.sh over live-ro. Each one's ENTIRE digest is one
 *        forum row, so each "after" is genuinely no email.
 *
 * CONSTRUCTED  the three-row frame, and it is labelled that way on the page.
 *        Ian asked to see "three rows and none". A three-row member DOES NOT EXIST
 *        on live and never has: only ELEVEN forum bell rows have ever been written
 *        (mention 5, reply_to_reply 3, reply_to_topic 3), across 10 members, and
 *        the worst any single member has ever held is TWO. So the three rows are
 *        three REAL rows belonging to three DIFFERENT real members, gathered onto
 *        one recipient. Every row is live data; the COMBINATION is mine, and it is
 *        the shape a busy week will have once forum volume grows — not a
 *        measurement of one that happened.
 *
 * I am drawing it anyway because the decision is about what the rule does WHEN it
 * fires, and at 1 row the deletion is easy to mistake for a rounding error.
 *
 * Writes to /tmp; wp-cli runs as looth-dev and cannot write a ubuntu-owned worktree.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file\n" ); exit( 1 ); }

$LANE  = '/home/ubuntu/worktrees/weekly-digest-recap';
$CLASS = $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';
$SRC   = '/tmp/lg-wdr/out/ruled-frames.json';
$OUT   = '/tmp/lg-wdr/out/frames';

if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $CLASS;
if ( ! is_dir( $OUT ) ) { mkdir( $OUT, 0777, true ); }

$src = json_decode( (string) file_get_contents( $SRC ), true );
if ( ! $src ) { fwrite( STDERR, "no extract at $SRC — run extract-ruled-frames.sh 1039 836 1773 117\n" ); exit( 1 ); }

$base = LG_WD_Email_Builder::build( LG_WD_Query::build_payload_from_issue( LG_WD_Issue::get_data( 72147 ) ) );

function inject( string $html, string $section ): string {
	if ( $section === '' ) { return $html; }
	$pos = strpos( $html, 'border-bottom:2px solid #ECB351' );
	$end = $pos !== false ? strpos( $html, '</p>', $pos ) : false;
	if ( $end === false ) { return $html; }
	$end += 4;
	return substr( $html, 0, $end ) . "\n" . $section . substr( $html, $end );
}

/** LIVE post ids do not resolve on dev2 — blank beats a wrong title. */
function titles( array $rows ): array {
	foreach ( $rows as &$n ) {
		$t = (int) ( $n['target_id'] ?? 0 );
		$n['title'] = ( $t && get_post_status( $t ) ) ? (string) get_the_title( $t ) : '';
	}
	return $rows;
}

function put( string $p, string $b ): void {
	if ( file_put_contents( $p, $b ) !== strlen( $b ) ) { fwrite( STDERR, "FAILED $p\n" ); exit( 1 ); }
}

$meta    = [];
$harvest = [];   // real rows, kept to assemble the constructed frame

foreach ( $src as $m ) {
	$wp   = (int) $m['wp_user_id'];
	$rows = titles( $m['named'] );

	// Only forum rows can be suppressed by axis 2 — connections have no email path
	// at all, because they live in profile_app and nothing emails about them.
	$forum = array_values( array_filter( $rows, fn( $r ) => str_starts_with( (string) $r['type'], 'forum.' ) ) );
	$kept  = array_values( array_filter( $rows, fn( $r ) => ! str_starts_with( (string) $r['type'], 'forum.' ) ) );
	foreach ( $forum as $f ) { $harvest[] = $f; }

	$payload = [ 'display_name' => $m['display_name'], 'notifications' => $rows, 'dms' => [], 'stale' => $m['stale'] ];
	$before  = LG_WD_Recap::render( $payload );

	// AFTER axis-2 suppression: forum rows the per-event mailer already covered are gone.
	$payload['notifications'] = $kept;
	$after = LG_WD_Recap::render( $payload );

	put( "$OUT/axis2-$wp-before.html", inject( $base, $before ) );
	if ( $after !== '' ) { put( "$OUT/axis2-$wp-after.html", inject( $base, $after ) ); }

	$meta[] = [
		'wp'        => $wp,
		'name'      => $m['display_name'],
		'kind'      => 'REAL',
		'types'     => implode( ',', array_column( $forum, 'type' ) ),
		'rows_before' => substr_count( $before, 'border-radius:50%' ),
		'rows_after'  => substr_count( $after, 'border-radius:50%' ),
		// THE HEADLINE. An empty section under Rule 5 is not a shorter email, it is none.
		'no_email_after' => ( $after === '' ),
	];
	printf(
		"REAL         wp:%-5d %-34s %d row%s -> %s\n",
		$wp, substr( $m['display_name'], 0, 32 ),
		substr_count( $before, 'border-radius:50%' ),
		substr_count( $before, 'border-radius:50%' ) === 1 ? ' ' : 's',
		$after === '' ? 'NO EMAIL AT ALL' : substr_count( $after, 'border-radius:50%' ) . ' rows'
	);
}

// ── The CONSTRUCTED three-row frame ────────────────────────────────────────────
// Three REAL rows from three DIFFERENT real members on one recipient. Live has
// never held a three-forum-row member (worst ever: 2), so this combination is mine
// and the page says so beside it.
if ( count( $harvest ) >= 3 ) {
	$three = array_slice( $harvest, 0, 3 );
	$p3    = [ 'display_name' => 'A member with a busy week', 'notifications' => $three, 'dms' => [], 'stale' => [] ];
	$b3    = LG_WD_Recap::render( $p3 );
	put( "$OUT/axis2-constructed-before.html", inject( $base, $b3 ) );

	$meta[] = [
		'wp'             => 0,
		'name'           => 'A member with a busy week',
		'kind'           => 'CONSTRUCTED — 3 real rows from 3 different members',
		'types'          => implode( ',', array_column( $three, 'type' ) ),
		'rows_before'    => substr_count( $b3, 'border-radius:50%' ),
		'rows_after'     => 0,
		'no_email_after' => true,
	];
	printf(
		"CONSTRUCTED  %-40s %d rows -> NO EMAIL AT ALL\n",
		'(3 real rows, 3 different members)', substr_count( $b3, 'border-radius:50%' )
	);
} else {
	fwrite( STDERR, "only " . count( $harvest ) . " real forum rows harvested; skipping the constructed frame\n" );
}

put( "$OUT/axis2.json", (string) wp_json_encode( $meta, JSON_PRETTY_PRINT ) );

$silenced = count( array_filter( $meta, fn( $x ) => $x['no_email_after'] ) );
echo "\n" . count( $meta ) . " frames written to $OUT\n";
echo "$silenced of " . count( $meta ) . " would receive NO EMAIL AT ALL after suppression.\n";
