<?php
/**
 * rebuild-ian-mail.php — Ian says the test email he received "didn't have any
 * notifs activity". The atlas says it carried borrowed rows behind a banner.
 * His inbox outranks the doc, so rebuild and MEASURE instead of arguing.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 *
 * /tmp/lg-wdr/ is gone, so the original artifact cannot be inspected. What CAN be
 * done is re-run the same build and diff three things that were never separated
 * before:
 *
 *   A. IAN'S TRUTHFUL RECAP     — what the real send path would produce for him
 *   B. THE BORROWED BUILD       — what build-inbox-test.php constructs
 *   C. THE SAME BODY AFTER Parser::parse() — what actually goes in the envelope
 *
 * B vs C is the one that matters and the one nobody checked: the section is a
 * DEFERRED FluentCRM smart code, and this lane has already been bitten once by
 * assuming Parser::parse() resolves a deferred code when the real second pass is
 * parseCrmValue(). If the token survives parse, the rows exist in the build and
 * never reach the envelope — which is exactly what "no notifs activity" looks
 * like from an inbox.
 *
 * SENDS NOTHING.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$IAN  = 1;
if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }

require_once __DIR__ . '/_load-under-test.php';
lg_wd_load_under_test( $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php', 'LG_WD_Recap' );
lg_wd_load_under_test( $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php', 'LG_WD_Recap_Source' );

echo "\n── A. WHAT THE REAL PATH HOLDS FOR EACH MEMBER ────────────────────\n";
$ids    = [ $IAN, 690, 197, 1891, 1138 ];
$recaps = LG_WD_Recap_Source::fetch( $ids );
if ( $recaps === false ) { fwrite( STDERR, "CANNOT RUN: source unavailable (fetch returned FALSE)\n" ); exit( 2 ); }
foreach ( $ids as $id ) {
	$r = $recaps[ $id ] ?? $recaps[ (string) $id ] ?? null;
	printf( "  wp:%-5d notifications=%-3d dms=%-3d %s\n",
		$id,
		is_array( $r ) ? count( $r['notifications'] ?? [] ) : -1,
		is_array( $r ) ? count( $r['dms'] ?? [] ) : -1,
		is_array( $r ) ? ( $r['display_name'] ?? '' ) : '(no row)' );
}
$mine = $recaps[ $IAN ] ?? $recaps[ (string) $IAN ] ?? null;
if ( ! is_array( $mine ) ) { fwrite( STDERR, "no endpoint row for wp:$IAN\n" ); exit( 2 ); }
$ian_real = count( $mine['notifications'] ?? [] ) + count( $mine['dms'] ?? [] );
printf( "  -> IAN'S TRUTHFUL RECAP IS %s (%d rows)\n", $ian_real ? 'NON-EMPTY' : 'EMPTY', $ian_real );

// ── Borrow exactly as build-inbox-test.php does ─────────────────────────────
$merged = [ 'display_name' => $mine['display_name'] ?? '', 'notifications' => [], 'dms' => [] ];
foreach ( [ 690, 197, 1891 ] as $d ) {
	foreach ( ( $recaps[ $d ]['notifications'] ?? $recaps[ (string) $d ]['notifications'] ?? [] ) as $n ) {
		$merged['notifications'][] = $n;
	}
}
foreach ( ( $recaps[1138]['dms'] ?? $recaps['1138']['dms'] ?? [] ) as $d ) { $merged['dms'][] = $d; }
printf( "  borrowed payload: %d notifications, %d dms\n",
	count( $merged['notifications'] ), count( $merged['dms'] ) );

/**
 * CLEAR THE MEMO BEFORE THE FILTER, OR THIS SCRIPT MEASURES ITSELF.
 *
 * payload_for() short-circuits on LG_WD_Recap_Source::$cache (line 324) and only
 * calls fetch() — the one place `lg_wd_recap_fetch` is applied — on a miss. Step A
 * above already fetched wp:1, so without this reset the borrow filter NEVER FIRES
 * and the section comes back empty for a reason that belongs to this harness and
 * not to the product. The first run of this script did exactly that and produced a
 * confident, wrong verdict.
 */
$cacheProp = new ReflectionProperty( 'LG_WD_Recap_Source', 'cache' );
$cacheProp->setAccessible( true );
$cacheProp->setValue( null, [] );
printf( "  memo cleared before installing the borrow filter (was %d entries)\n", count( $recaps ) );

add_filter( 'lg_wd_recap_fetch', fn( $pre, $want ) => in_array( $IAN, $want, true ) ? [ $IAN => $merged ] : [], 10, 2 );
LG_WD_Recap_Source::register_smartcode();

echo "\n── B. DOES THE SECTION RENDER FROM THE BORROWED PAYLOAD? ──────────\n";
$sub = \FluentCrm\App\Models\Subscriber::where( 'user_id', $IAN )->first();
if ( ! $sub ) { $sub = \FluentCrm\App\Models\Subscriber::where( 'email', 'ian.davlin@gmail.com' )->first(); }
if ( ! $sub ) { fwrite( STDERR, "CANNOT RUN: no FluentCRM subscriber for Ian\n" ); exit( 2 ); }
$section = LG_WD_Recap_Source::render_for_subscriber( $sub );
printf( "  render_for_subscriber() -> %d bytes, %s\n",
	strlen( $section ), strlen( $section ) ? 'HAS CONTENT' : 'EMPTY STRING' );

echo "\n── C. WHAT SURVIVES INTO THE ENVELOPE ─────────────────────────────\n";
$issue_id = 72147;
$payload  = LG_WD_Query::build_payload_from_issue( LG_WD_Issue::get_data( $issue_id ) );
$campaign = LG_WD_Email_Builder::build( $payload );
$pos = strpos( $campaign, 'border-bottom:2px solid #ECB351' );
$end = $pos !== false ? strpos( $campaign, '</p>', $pos ) : false;
if ( $end === false ) { fwrite( STDERR, "CANNOT RUN: intro anchor not found in the built campaign\n" ); exit( 2 ); }
$end += 4;
$campaign = substr( $campaign, 0, $end ) . "\n" . LG_WD_RECAP_SMARTCODE . substr( $campaign, $end );

printf( "  body BEFORE parse : %d bytes, token present: %s\n",
	strlen( $campaign ), strpos( $campaign, LG_WD_RECAP_SMARTCODE ) !== false ? 'yes' : 'NO' );

$html = \FluentCrm\App\Services\Libs\Parser\Parser::parse( $campaign, $sub );

$token_left = strpos( $html, LG_WD_RECAP_SMARTCODE ) !== false;
printf( "  body AFTER parse  : %d bytes, token still present: %s\n",
	strlen( $html ), $token_left ? 'YES' : 'no' );

// Did any recap row actually land in the final document?
$rows_in_html = substr_count( $html, 'border-radius:50%' );
printf( "  avatar circles in final html : %d   (the row proxy build-inbox-test printed)\n", $rows_in_html );
$has_section = ( $section !== '' && strpos( $html, substr( $section, 0, 120 ) ) !== false );
printf( "  section text present in final html : %s\n", $has_section ? 'YES' : 'NO' );

echo "\n── VERDICT ────────────────────────────────────────────────────────\n";
if ( $token_left ) {
	echo "  THE TOKEN SURVIVED Parser::parse(). The section was built and NEVER\n";
	echo "  reached the envelope — the recipient sees the digest with a literal\n";
	echo "  token or nothing where the recap belongs. This matches Ian.\n";
} elseif ( ! $has_section && $section !== '' ) {
	echo "  Token consumed but the SECTION TEXT IS ABSENT from the final html —\n";
	echo "  something between render and parse dropped it. This matches Ian.\n";
} elseif ( $section === '' ) {
	echo "  render_for_subscriber() returned EMPTY even with borrowed rows — the\n";
	echo "  borrow did not take. This matches Ian.\n";
} else {
	echo "  The borrowed section DID land in the parsed body. If Ian still saw no\n";
	echo "  activity, the artifact that was SENT was not this one.\n";
}
printf( "\n  For the record — Ian's own truthful recap right now: %d rows (%s).\n",
	$ian_real, $ian_real ? 'non-empty' : 'EMPTY' );
