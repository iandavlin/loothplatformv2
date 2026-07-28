<?php
/**
 * render-watermark-frames.php — the visible consequence of Rule 3b (per-member window).
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * Rule 1's frames (render-suppression-frames.php) show what suppression REMOVES.
 * This one shows the opposite failure, and it is the one nobody can see happening:
 * what a FIXED 7-day window silently DROPS when a member's send fails.
 *
 * The mechanic, stated plainly so the frames are not read as more than they are:
 * a constant 7-day lookback assumes last week's digest was delivered. When it was
 * not — six real members on 2026-06-01, one SES `SignatureDoesNotMatch`, never
 * re-sent — that week's items fall out of the next window and are gone for good.
 * There is no error state afterwards and nothing on the site says it happened.
 *
 *   LEFT  (fixed 7d)   what ships today: only the last 7 days
 *   RIGHT (watermark)  Rule 3b: window starts at the last digest this member
 *                      ACTUALLY received (wp_fc_campaign_emails.status='sent')
 *
 * Data is a read-only LIVE extract (extract-watermark-frames.sh), already filtered
 * by the shipped source rules — `is_read` false and the connections.status test —
 * so every row in BOTH panels is genuinely listable and the ONLY variable between
 * them is the window. Counts, actors and links only; never content.
 *
 * HONEST LABEL, and it belongs on the picture as well as here: the member is real
 * and every row is real, but the FAILED SEND is simulated. Grace's June sends did
 * not fail; six other members' did. The frames answer "what does it look like the
 * next time it happens", which is the decision in front of Ian.
 *
 * Writes into the REPO, never the docroot (dev2 is pull-only, keeper 2026-07-27).
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$SRC  = '/tmp/lg-wdr/out/watermark-frames.json';

// wp-cli runs this as `looth-dev`, which cannot write into a lane worktree owned by
// `ubuntu`. Stage in /tmp and copy as ubuntu. The first run of this script did NOT
// do that: file_put_contents returned false on all three writes and the script still
// printed "wrote 2 frames", because it never checked. Every write below is asserted
// now — a render script that reports success it did not achieve is worse than one
// that crashes.
$OUT = '/tmp/lg-wdr/out/frames';

if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';

if ( ! is_dir( $OUT ) ) { mkdir( $OUT, 0755, true ); }

$src = json_decode( (string) file_get_contents( $SRC ), true );
if ( empty( $src['notifications'] ) ) { fwrite( STDERR, "no live extract at $SRC\n" ); exit( 1 ); }

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

/** LIVE post ids do not resolve on dev2 — leave a title blank rather than mislabel it. */
function titles( array $p ): array {
	foreach ( $p['notifications'] as &$n ) {
		$t = (int) ( $n['target_id'] ?? 0 );
		$n['title'] = ( $t && get_post_status( $t ) ) ? (string) get_the_title( $t ) : '';
	}
	unset( $n );
	return $p;
}

$wp   = (int) $src['wp_user_id'];
$full = titles( [
	'display_name'  => (string) $src['display_name'],
	'notifications' => $src['notifications'],
	'dms'           => $src['dms'] ?? [],
] );

// LEFT — the fixed 7-day window. Everything older simply is not asked for.
$fixed = $full;
$fixed['notifications'] = array_values( array_filter(
	$full['notifications'],
	fn( $n ) => ! empty( $n['in_last_7d'] )
) );

// RIGHT — Rule 3b. The window starts at the member's last SUCCESSFUL digest, so a
// failed send reaches back over the band it lost. Here that is the whole 14 days.
$reach = $full;

$left  = LG_WD_Recap::render( $fixed );
$right = LG_WD_Recap::render( $reach );

/** Write or die loudly — silence here is how a false "wrote 2 frames" gets reported. */
function put( string $path, string $body ): void {
	$n = file_put_contents( $path, $body );
	if ( $n === false || $n !== strlen( $body ) ) {
		fwrite( STDERR, "FAILED TO WRITE $path\n" );
		exit( 1 );
	}
}

put( "$OUT/watermark-$wp-fixed.html",     inject( $base, $left ) );
put( "$OUT/watermark-$wp-reachback.html", inject( $base, $right ) );

$row = [
	'wp'            => $wp,
	'display_name'  => $src['display_name'],
	'extracted_at'  => $src['extracted_at'],
	'listable_14d'  => count( $full['notifications'] ),
	'in_last_7d'    => count( $fixed['notifications'] ),
	'lost_band'     => count( $full['notifications'] ) - count( $fixed['notifications'] ),
	'fixed_rows'    => substr_count( $left,  'border-radius:50%' ),
	'reach_rows'    => substr_count( $right, 'border-radius:50%' ),
];
put( "$OUT/watermark.json", (string) wp_json_encode( [ $row ], JSON_PRETTY_PRINT ) );

printf(
	"wp:%-5d %-40s  fixed-7d = %d rows   watermark = %d rows   (%d silently dropped)\n",
	$wp, $src['display_name'], $row['fixed_rows'], $row['reach_rows'],
	$row['reach_rows'] - $row['fixed_rows']
);
echo "wrote 2 frames + watermark.json to $OUT — copy them into the lane as ubuntu:\n";
echo "  cp $OUT/watermark-* $LANE/lg-weekly-digest/dev/frames/\n";
