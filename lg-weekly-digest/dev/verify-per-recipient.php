<?php
/**
 * verify-per-recipient.php — proves the seam: ONE campaign body, five real
 * subscribers, five different renders.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * This is the check the lane brief asks for — "prove the per-recipient path with at
 * least three members side by side, from a single campaign render" — because a
 * recap that is correct for one member proves nothing about substitution.
 *
 * WHAT IS REAL HERE, AND WHAT IS NOT. The subscribers, their WP accounts, the
 * notification rows, the DM counts, the thread titles and the deep links are all
 * real. The recap material is the VERBATIM JSON response of internal-recap.php,
 * captured by driving the real profile-app FPM pool over cgi-fcgi (see
 * /tmp/lg-wdr/hit-endpoint.sh in the lane report), and injected through the
 * `lg_wd_recap_fetch` filter. The one link NOT exercised is curl → nginx → FPM:
 * the `/profile-api/v0/internal/recap` location is written in
 * platform/nginx/strangler-profile-app.conf but is not live on dev2, because
 * activating it means touching the shared serve and that needs a keeper window.
 * That leg is a copy of the /notify block which is already in production use.
 *
 * SENDS NOTHING. Parses a template string in memory. No campaign, no wp_mail.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$RESP = '/tmp/lg-wdr/endpoint-response.json';

if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php';

$resp = json_decode( (string) file_get_contents( $RESP ), true );
if ( empty( $resp['ok'] ) ) { fwrite( STDERR, "bad endpoint response at $RESP\n" ); exit( 1 ); }

// Feed the endpoint's OWN response in, instead of re-fetching over a route that
// is not live yet. Everything downstream — title hydration, row building, copy,
// links, the empty rule — runs for real.
add_filter( 'lg_wd_recap_fetch', function ( $pre, $want, $days ) use ( $resp ) {
	$out = [];
	foreach ( $want as $id ) {
		if ( isset( $resp['recaps'][ (string) $id ] ) ) { $out[ $id ] = $resp['recaps'][ (string) $id ]; }
	}
	return $out;
}, 10, 3 );

LG_WD_Recap_Source::register_smartcode();

// ONE body. This is the shape templates/email.php now emits.
$body = '<p>INTRO</p>' . LG_WD_RECAP_SMARTCODE . '<p>CURATED CONTENT</p>';

$cases = [
	690  => 'has activity  (2 forum rows: replies + a reaction)',
	197  => 'has activity  (3 rows: 2 reactions + a connection)',
	1138 => 'ONLY DMs      (6 unread from one sender)',
	1891 => 'one connection request, nothing else',
	1170 => 'NOTHING       (section must be absent)',
];

$renders = [];
$fail    = 0;

foreach ( $cases as $wp_id => $label ) {
	$sub = \FluentCrm\App\Models\Subscriber::where( 'user_id', $wp_id )->first();
	if ( ! $sub ) { printf( "wp:%-5d  NO SUBSCRIBER — skipped\n", $wp_id ); $fail++; continue; }

	// The real parse path: exactly what CampaignEmail::getEmailBody() applies to
	// every recipient's row at send (fluent_crm/parse_campaign_email_text).
	$out = \FluentCrm\App\Services\Libs\Parser\Parser::parse( $body, $sub );
	$renders[ $wp_id ] = $out;

	$has_section = strpos( $out, 'Your week' ) !== false;
	$has_token   = strpos( $out, LG_WD_RECAP_SMARTCODE ) !== false;
	$rows        = substr_count( $out, 'border-radius:50%' );   // one dot per row

	printf(
		"wp:%-5d sub:%-5d %-42s  section=%-3s rows=%d token_left=%s\n",
		$wp_id, $sub->id, $label,
		$has_section ? 'YES' : 'no', $rows, $has_token ? 'YES(BUG)' : 'no'
	);

	if ( $has_token ) { echo "   FAIL: the smart code did not substitute\n"; $fail++; }
	if ( $wp_id === 1170 ) {
		if ( $has_section ) { echo "   FAIL: empty member got a section\n"; $fail++; }
		if ( $out !== '<p>INTRO</p><p>CURATED CONTENT</p>' ) {
			echo "   FAIL: empty member's body is not byte-identical to the no-recap body\n"; $fail++;
		}
	} elseif ( ! $has_section ) {
		echo "   FAIL: member with activity got no section\n"; $fail++;
	}
}

// The whole point: one body in, DIFFERENT bodies out.
$distinct = count( array_unique( $renders ) );
printf( "\ndistinct renders from ONE campaign body: %d of %d\n", $distinct, count( $renders ) );
if ( $distinct !== count( $renders ) ) { echo "FAIL: two members received identical bodies\n"; $fail++; }

// ── The privacy ruling, asserted rather than assumed ─────────────────────────
// "Counts and senders with deep links — never content." Two checks, both against
// the SECTION ITSELF (not the whole body, which legitimately contains the curated
// content around it — the first version of this check matched the trailing
// <p>CURATED CONTENT</p> and reported four false failures).
//
// VERBATIM_CONTENT is the actual stored text of the replies these members were
// notified about, read out of forums.reply. If any of it ever reaches a render,
// the ruling has been broken.
$VERBATIM_CONTENT = [
	'this is a testthis is at test',
	'NOTIFLANE re-verify C: hey @patreon_88817783 what do you think?',
	'Hard to argue with the best in the business',
];

$leaks = 0;
foreach ( $cases as $wp_id => $label ) {
	$sub = \FluentCrm\App\Models\Subscriber::where( 'user_id', $wp_id )->first();
	if ( ! $sub ) { continue; }
	$section = LG_WD_Recap_Source::render_for_subscriber( $sub );
	if ( $section === '' ) { continue; }

	// Prose markup is what pasted content would drag in; the renderer emits only
	// table/div/span/a.
	if ( preg_match( '/<(p|br|blockquote|img)[ \/>]/i', $section ) ) {
		printf( "   FAIL: wp:%d — prose markup inside the section\n", $wp_id );
		$leaks++;
	}
	foreach ( $VERBATIM_CONTENT as $text ) {
		if ( stripos( $section, $text ) !== false ) {
			printf( "   FAIL: wp:%d — stored content leaked into the section: %s\n", $wp_id, $text );
			$leaks++;
		}
	}
}
echo $leaks
	? "content-leak check: $leaks FAILED\n"
	: "content-leak check: clean (no prose markup, no stored reply text, in any section)\n";

$fail += $leaks;
echo $fail ? "\n$fail CHECK(S) FAILED\n" : "\nALL CHECKS PASSED\n";
