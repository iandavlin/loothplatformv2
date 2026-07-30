<?php
/**
 * render-recipient-frames.php — five REAL members, one campaign body.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * The companion to render-recap-frames.php. Where that one renders four design
 * VARIANTS against curated material (including one labelled composite), this one
 * renders five real subscribers' ACTUAL recaps through the real FluentCRM
 * substitution path — the same Parser::parse() that CampaignEmail::getEmailBody()
 * applies per recipient at send. No composites, no fixtures on the render side:
 * whatever these five members really have this window is what appears.
 *
 * It is the picture of the thing the lane brief asks to be proven: a recap that is
 * correct for one member proves nothing about substitution.
 *
 * Material comes from internal-recap.php's verbatim response (captured against the
 * real profile-app FPM pool), injected via `lg_wd_recap_fetch` because the nginx
 * route is written but not yet live on dev2. SENDS NOTHING.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$OUT  = '/var/www/dev/mockups/wd-recap';
$RESP = '/tmp/lg-wdr/endpoint-response.json';

if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php';

$resp = json_decode( (string) file_get_contents( $RESP ), true );
if ( empty( $resp['ok'] ) ) { fwrite( STDERR, "bad endpoint response at $RESP\n" ); exit( 1 ); }

add_filter( 'lg_wd_recap_fetch', function ( $pre, $want ) use ( $resp ) {
	$out = [];
	foreach ( $want as $id ) {
		if ( isset( $resp['recaps'][ (string) $id ] ) ) { $out[ $id ] = $resp['recaps'][ (string) $id ]; }
	}
	return $out;
}, 10, 2 );

LG_WD_Recap_Source::register_smartcode();

/**
 * The host email, with the token in place — i.e. exactly the body the FluentCRM
 * campaign would carry.
 *
 * NOTE ON HOW THE TOKEN GETS THERE. wp-cli loads the DEPLOYED plugin (dev2 symlinks
 * lg-weekly-digest at the serving checkout), so LG_WD_Email_Builder::build() here is
 * main's one-argument version rendering main's templates/email.php — neither of
 * which knows about the recap yet. Rather than pretend otherwise, this driver
 * injects the token at the SAME anchor the lane's templates/email.php now emits it:
 * immediately after the gold-ruled intro paragraph. Once the lane's plugin is the
 * deployed one, `build($payload, ['mode' => 'token'])` produces this body directly
 * and the injection below becomes dead code.
 */
$issue_id = 72147;
$payload  = LG_WD_Query::build_payload_from_issue( LG_WD_Issue::get_data( $issue_id ) );
$campaign = LG_WD_Email_Builder::build( $payload );

$pos = strpos( $campaign, 'border-bottom:2px solid #ECB351' );
$end = $pos !== false ? strpos( $campaign, '</p>', $pos ) : false;
if ( $end === false ) {
	fwrite( STDERR, "could not find the intro anchor in the built email\n" );
	exit( 1 );
}
$end     += 4;
$campaign = substr( $campaign, 0, $end ) . "\n" . LG_WD_RECAP_SMARTCODE . substr( $campaign, $end );

$people = [ 690 => 'activity', 197 => 'activity', 1138 => 'only-dms', 1891 => 'one-connection', 1170 => 'nothing' ];

$rows = [];
foreach ( $people as $wp_id => $shape ) {
	$sub = \FluentCrm\App\Models\Subscriber::where( 'user_id', $wp_id )->first();
	if ( ! $sub ) { continue; }

	// THE REAL PATH. One body in, this member's body out.
	$html = \FluentCrm\App\Services\Libs\Parser\Parser::parse( $campaign, $sub );

	$file = "recipient-$wp_id.html";
	file_put_contents( "$OUT/$file", $html );

	$user = get_user_by( 'id', $wp_id );
	$rows[] = [
		'wp_id'   => $wp_id,
		'sub_id'  => (int) $sub->id,
		'name'    => $user ? $user->display_name : ( 'wp:' . $wp_id ),
		'shape'   => $shape,
		'file'    => $file,
		'section' => strpos( $html, 'Your week' ) !== false,
		'rows'    => substr_count( $html, 'border-radius:50%' ),
	];

	printf( "wp:%-5d sub:%-5d %-16s %-22s section=%-3s rows=%d\n",
		$wp_id, $sub->id, $shape, $rows[ count( $rows ) - 1 ]['name'],
		$rows[ count( $rows ) - 1 ]['section'] ? 'YES' : 'no',
		$rows[ count( $rows ) - 1 ]['rows'] );
}

file_put_contents( "$OUT/recipients.json", wp_json_encode( [
	'issue'      => [ 'id' => $issue_id, 'title' => get_the_title( $issue_id ) ],
	'days'       => $resp['days'],
	'recipients' => $rows,
], JSON_PRETTY_PRINT ) );

$bodies = array_map( fn( $r ) => file_get_contents( "$OUT/" . $r['file'] ), $rows );
printf( "\ndistinct bodies from ONE campaign: %d of %d\n", count( array_unique( $bodies ) ), count( $bodies ) );
echo "wrote " . count( $rows ) . " recipient frames to $OUT\n";
