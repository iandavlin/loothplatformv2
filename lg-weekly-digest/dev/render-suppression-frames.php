<?php
/**
 * render-suppression-frames.php — the visible consequence of the read-suppression rule.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * Ian's requirement: do not duplicate notifications in the email that have already
 * been read — read in one of those per-event emails, or read on the website. This
 * renders BEFORE and AFTER for two REAL LIVE members, so the consequence is looked
 * at rather than described.
 *
 *   wp:197  Doug Proper   14 items in the window, 6 already read -> 14 rows become 8
 *   wp:1    Ian Davlin     5 items in the window, ALL read       -> the section VANISHES
 *
 * Data is a read-only extract from LIVE profile_app over `live-ro`, taken with the
 * recap's own rules already applied (connection edges checked live). It carries
 * counts, actor names and links only — never content — exactly like the email.
 *
 * BEFORE = what a date-window-only recap would send (no read-suppression).
 * AFTER  = what ships (is_read excluded at the source).
 *
 * Writes into the REPO, not the docroot: dev2 is pull-only and nothing should write
 * to /var/www/dev (keeper, 2026-07-27). Publishing is a separate step needing a
 * window and a decision on where preview output lives under the pull-only design.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$OUT  = $LANE . '/lg-weekly-digest/dev/frames';
$SRC  = '/tmp/lg-wdr/out/live-frames.json';

if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';

if ( ! is_dir( $OUT ) ) { mkdir( $OUT, 0755, true ); }

$src = json_decode( (string) file_get_contents( $SRC ), true );
if ( empty( $src['recaps'] ) ) { fwrite( STDERR, "no live extract at $SRC\n" ); exit( 1 ); }

$issue_id = 72147;
$payload  = LG_WD_Query::build_payload_from_issue( LG_WD_Issue::get_data( $issue_id ) );
$base     = LG_WD_Email_Builder::build( $payload );

/** Inject at the anchor templates/email.php uses for the token. */
function inject( string $html, string $section ): string {
	if ( $section === '' ) { return $html; }
	$pos = strpos( $html, 'border-bottom:2px solid #ECB351' );
	$end = $pos !== false ? strpos( $html, '</p>', $pos ) : false;
	if ( $end === false ) { return $html; }
	$end += 4;
	return substr( $html, 0, $end ) . "\n" . $section . substr( $html, $end );
}

/** target_id is a WP post id — but these are LIVE ids and this is dev2, so a title
 *  that does not resolve here is left blank rather than mislabelled. */
function titles( array $p ): array {
	foreach ( $p['notifications'] as &$n ) {
		$t = (int) ( $n['target_id'] ?? 0 );
		$n['title'] = ( $t && get_post_status( $t ) ) ? (string) get_the_title( $t ) : '';
	}
	unset( $n );
	return $p;
}

$cases = [
	197 => 'Doug Proper — 14 items, 6 already read',
	1   => 'Ian Davlin — 5 items, every one already read',
];

$rows = [];
foreach ( $cases as $wp => $label ) {
	$p = $src['recaps'][ (string) $wp ] ?? null;
	if ( ! $p ) { continue; }
	$p = titles( $p );

	// BEFORE — date window only, read state ignored.
	$before_payload = $p;

	// AFTER — the shipping rule: anything already read is not something you missed.
	$after_payload = $p;
	$after_payload['notifications'] = array_values( array_filter(
		$p['notifications'],
		fn( $n ) => empty( $n['is_read'] )
	) );

	$before = LG_WD_Recap::render( $before_payload );
	$after  = LG_WD_Recap::render( $after_payload );

	file_put_contents( "$OUT/suppress-$wp-before.html", inject( $base, $before ) );
	file_put_contents( "$OUT/suppress-$wp-after.html",  inject( $base, $after ) );

	$rows[] = [
		'wp' => $wp, 'label' => $label,
		'items' => count( $p['notifications'] ),
		'kept'  => count( $after_payload['notifications'] ),
		'before_rows' => substr_count( $before, 'border-radius:50%' ),
		'after_rows'  => substr_count( $after,  'border-radius:50%' ),
		'after_absent' => $after === '',
	];

	printf( "wp:%-4d %-46s  before=%2d rows   after=%s\n",
		$wp, $label,
		substr_count( $before, 'border-radius:50%' ),
		$after === '' ? 'NO SECTION AT ALL' : substr_count( $after, 'border-radius:50%' ) . ' rows' );
}

file_put_contents( "$OUT/suppression.json", wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
echo "\nwrote " . ( count( $rows ) * 2 ) . " frames to $OUT (repo, not docroot)\n";
