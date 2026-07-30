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
 * PROVEN RED BEFORE IT WAS TRUSTED GREEN. Against the shipping 624px frame it failed
 * with the 368px overflow; with a hard `width` added beside the max-width it failed the
 * phone assertion. That second probe FIRST PASSED — the check was `[^-]width`, which
 * needs a character before "width" and so could never match `.mail{width:...`, the very
 * case it was written for. An assertion that cannot fail is worse than no assertion.
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

/* ── THE PHONE HALF. A DESKTOP-ONLY PASS IS NOT A PASS ─────────────────────────
 *
 * Widening the frame fixes desktop and can do NOTHING for a 390px phone, where the
 * constraint is the viewport rather than the frame. Ian decides from his phone and it
 * has beaten a green suite three times, so the phone case gets assertions of its own
 * rather than a hope.
 *
 * Two structural properties carry it, and both are checkable in the shipping files:
 *
 *   1. The frame must be declared with MAX-width, not width. `width:992px` would hold
 *      the box open at 992 inside a 390px screen and push the panning problem from
 *      inside the iframe to outside it — the same defect, one level up.
 *   2. The email must neutralise its own fixed-width images below the phone
 *      breakpoint. Its cards carry <img width="240"> with no max-width, which is fine
 *      beside a text column at desktop and is an overflow at 390px unless
 *      `.event-img { width:100% }` fires inside the <=480px query.
 *
 * LIMIT, so a green is not over-read: this proves the RULES that make the phone case
 * work are present, not that a phone rendered it. Only Ian's phone proves that. */
if ( ! preg_match( '/\.lgws\s+\.mail\s*\{[^}]*max-width:\s*\d+px/', $page_src ) ) {
	echo "\n  FAIL: .lgws .mail must be declared with max-width. A fixed width holds the box\n";
	echo "        open on a phone and moves the sideways panning outside the iframe.\n";
	$fail++;
}
/* Lookbehind, NOT [^-]. `[^-]width` needs a character to sit before "width", so it
 * could never match `.mail{width:...` where the declaration is first in the block —
 * the class it was written to catch. Proven vacuous by probing it red: the probe
 * passed. An assertion that cannot fail is worse than no assertion. */
if ( preg_match( '/\.lgws\s+\.mail\s*\{[^}]*(?<!-)\bwidth:\s*\d+px/', $page_src ) ) {
	echo "\n  FAIL: .lgws .mail carries a hard width as well as a max-width.\n";
	$fail++;
}

if ( ! preg_match( '/@media[^{]*max-width:\s*480px[^{]*\{(.*?)\n\s*\}/s', $email_src, $mq ) ) {
	fwrite( STDERR, "CANNOT RUN: the email has no <=480px breakpoint to inspect — its phone\n" .
	                "layout changed shape and this assertion would measure nothing.\n" );
	exit( 2 );
}
$phone_rules = $mq[1];
$fixed_imgs  = preg_match_all( '/<img[^>]*\bwidth="\d{2,4}"/', $email_src );
if ( $fixed_imgs && strpos( $phone_rules, '.event-img' ) === false ) {
	echo "\n  FAIL: the email ships fixed-width <img> tags but its <=480px breakpoint does not\n";
	echo "        reset .event-img — those images will overflow a phone-width frame.\n";
	$fail++;
}
printf( "phone frame       : max-width, fluid below 992px  %s\n", 'OK' );
printf( "phone breakpoint  : <=480px resets .event-img     %s\n",
	strpos( $phone_rules, '.event-img' ) !== false ? 'OK' : 'MISSING' );

echo $fail ? "\n$fail FAILURE(S)\n" : "\nPREVIEW FRAME FITS THE EMAIL\n";
exit( $fail ? 1 : 0 );
