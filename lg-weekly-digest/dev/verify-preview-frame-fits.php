<?php
/**
 * verify-preview-frame-fits.php — the framed email must not be wider than its frame.
 *
 * verify-preview-frames-the-email.php proves the RIGHT DOCUMENT is framed. It says
 * nothing about whether that document is READABLE, and that gap is exactly what
 * reached Ian: "the email is now in a container where it floats left and right to
 * see the whole thing with out having the text cut off."
 *
 * A right document, unreadably framed, passed every check we had.
 *
 * WHAT THIS ASSERTS, and why it needs no browser: the email declares its own width
 * in its own markup — an `.email-container` at `max-width:960px` inside an
 * `.email-wrapper` carrying `padding:24px 16px` (32px horizontal). So the document
 * needs 960 + 32 = 992px to render at its designed size. The signup page declares
 * the frame it gets, `.mail { max-width: … }`. If the first number exceeds the
 * second, the reader pans sideways. Both numbers are parsed from the shipping files,
 * so this cannot drift away from what is served.
 *
 * IT IS RED ON THE CURRENT BUILD BY CONSTRUCTION: 992 > 624.
 *
 * LIMIT, STATED SO A GREEN IS NOT OVER-READ: this compares DECLARED widths. It does
 * not measure rendered scrollWidth, so it cannot catch overflow caused by an
 * individual element (a long unbreakable string, a fixed-width image) inside an
 * otherwise fluid document. It catches the structural case — the frame being
 * narrower than the document — which is the one that shipped.
 *
 * Run: sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }

$LANE  = '/home/ubuntu/worktrees/weekly-digest-recap';
$EMAIL = $LANE . '/lg-weekly-digest/templates/email.php';
$PAGE  = $LANE . '/lg-weekly-digest/templates/signup-page.php';
$fail  = 0;

foreach ( [ $EMAIL, $PAGE ] as $f ) {
	if ( ! is_readable( $f ) ) { fwrite( STDERR, "CANNOT RUN: unreadable: $f\n" ); exit( 2 ); }
}

$email_src = (string) file_get_contents( $EMAIL );
$page_src  = (string) file_get_contents( $PAGE );

// The email's own container width.
if ( ! preg_match( '/class="email-container".*?max-width:\s*(\d+)px/s', $email_src, $m ) ) {
	fwrite( STDERR, "CANNOT RUN: could not find .email-container's max-width in email.php —\n" .
	                "the template changed shape and this test would silently measure nothing.\n" );
	exit( 2 );
}
$container = (int) $m[1];

// The horizontal padding its wrapper adds around that container.
if ( ! preg_match( '/class="email-wrapper"[^>]*padding:\s*\d+px\s+(\d+)px/', $email_src, $m ) ) {
	fwrite( STDERR, "CANNOT RUN: could not find .email-wrapper's padding in email.php\n" );
	exit( 2 );
}
$pad_each = (int) $m[1];
$needs    = $container + ( 2 * $pad_each );

// The frame the signup page gives it.
if ( ! preg_match( '/\.lgws\s+\.mail\s*\{[^}]*max-width:\s*(\d+)px/', $page_src, $m ) ) {
	fwrite( STDERR, "CANNOT RUN: could not find .lgws .mail's max-width in signup-page.php\n" );
	exit( 2 );
}
$frame = (int) $m[1];

printf( "email container   : %dpx\n", $container );
printf( "wrapper padding   : %dpx each side\n", $pad_each );
printf( "document needs    : %dpx\n", $needs );
printf( "frame gives it    : %dpx\n", $frame );

if ( $needs > $frame ) {
	printf( "\n  FAIL: the framed email is %dpx wider than its frame — the reader must pan\n", $needs - $frame );
	echo   "        sideways to finish a headline. Widen .lgws .mail to at least the\n";
	printf( "        document's own %dpx, or shrink the document. Do NOT paper over it with\n", $needs );
	echo   "        overflow-x or a nicer 'scroll inside the message' caption: that caption is\n";
	echo   "        the bug wearing a label.\n";
	$fail++;
}

// The caption is only honest while there IS something to scroll to.
if ( $needs <= $frame && strpos( $page_src, 'Scroll inside the message' ) !== false ) {
	echo "\n  FAIL: the frame now fits, but the page still tells the reader to scroll inside\n";
	echo "        the message. Remove the caption when the reason for it goes away.\n";
	$fail++;
}

echo $fail ? "\n$fail FAILURE(S)\n" : "\nPREVIEW FRAME FITS THE EMAIL\n";
exit( $fail ? 1 : 0 );
