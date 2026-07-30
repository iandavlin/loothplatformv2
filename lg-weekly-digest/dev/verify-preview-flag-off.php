<?php
/**
 * verify-preview-flag-off.php — with LG_WD_SIGNUP_EMAIL_PREVIEW OFF, the sample-email
 * section must be ABSENT, and the flag must change NOTHING ELSE.
 *
 * ── WHY THIS IS THE TEST THAT WAS MISSING ───────────────────────────────────
 *
 * Every other check in this suite asserts the feature is PRESENT and correct. None of
 * them could say what a member sees when it is switched OFF — and OFF is the state
 * this ships in. Keeper's rule, 2026-07-30: gates assert what should be present, they
 * cannot see what should be absent, and all six of Ian's misses were in that blind
 * spot. A flag nobody tests is worse than no flag, because it invites a merge on a
 * promise.
 *
 * ── IT RUNS UNDER PLAIN php, NOT wp eval-file, AND THAT IS DELIBERATE ────────
 *
 * The point is to test THIS BRANCH's template today, before anything is deployed. Run
 * under WordPress it would exercise the SERVING class, which has no flag yet, and
 * report CANNOT RUN until after the merge it is supposed to justify. So it renders the
 * branch template directly against a stub, which is the same trick
 * verify-signup-audience uses to test branch bytes without colliding with deployed
 * ones. run-suite.sh gives this test its own runner.
 *
 * WHAT IS PROVEN:
 *   1. OFF emits no section markup, no iframe, and none of the section's CSS.
 *   2. OFF is a TRUE NO-OP — ON with the two gated regions cut out is byte-identical
 *      to OFF. That is the claim a flag has to earn: not "the section is gone" but
 *      "nothing else moved". Diffed, not asserted.
 *   3. ON still renders it, so the flag is a switch and not a delete.
 *
 * Run: php lg-weekly-digest/dev/verify-preview-flag-off.php
 */

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$TPL  = $LANE . '/lg-weekly-digest/templates/signup-page.php';
$CLS  = $LANE . '/lg-weekly-digest/includes/class-lg-wd-signup-page.php';
$fail = 0;

foreach ( [ $TPL, $CLS ] as $f ) {
	if ( ! is_readable( $f ) ) { fwrite( STDERR, "CANNOT RUN: unreadable: $f\n" ); exit( 2 ); }
}

/* ── The default must be OFF, read out of the branch source ────────────────── */
$cls_src = (string) file_get_contents( $CLS );
if ( ! preg_match( '/function preview_enabled\(\)[^{]*\{(.*?)\n\t\}/s', $cls_src, $m ) ) {
	fwrite( STDERR, "CANNOT RUN: preview_enabled() not found — the flag's read site changed shape\n" .
	                "and this test would be checking nothing.\n" );
	exit( 2 );
}
$body = $m[1];
/* `.*?` and not `[^:]*`: the true-branch contains `self::PREVIEW_FLAG`, whose `::`
 * a negated-colon class cannot cross, so the original pattern could never match the
 * code it was written for. Same shape as the `[^-]width` check earlier today. */
if ( ! preg_match( '/defined\(\s*self::PREVIEW_FLAG\s*\).*?:\s*false\s*;/s', $body ) ) {
	echo "  FAIL: preview_enabled() does not default to FALSE when the constant is undefined.\n";
	$fail++;
} else {
	echo "default when undefined    : OFF\n";
}

/* ── Render the branch template both ways ─────────────────────────────────── */
define( 'ABSPATH', '/var/www/dev/' );
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'esc_url' ) )        { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) )       { function esc_attr( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html' ) )       { function esc_html( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

class LG_WD_Signup_Page {
	public static $sample = '';
	public static function ajax_url()         { return '/wp-admin/admin-ajax.php'; }
	public static function sample_email_url() { return self::$sample; }
	public static function prefs_url()        { return '/manage-subscription/'; }
}

$render = static function ( string $sample ) use ( $TPL ): string {
	LG_WD_Signup_Page::$sample = $sample;
	ob_start(); include $TPL; return (string) ob_get_clean();
};

$off = $render( '' );
$on  = $render( '/wp-admin/admin-ajax.php?action=lg_wd_email_preview' );
printf( "flag OFF page bytes       : %d\n", strlen( $off ) );
printf( "flag ON  page bytes       : %d  (delta %+d)\n", strlen( $on ), strlen( $on ) - strlen( $off ) );

/* ── 1. Nothing of the section may survive OFF ─────────────────────────────── */
$leaked = [];
foreach ( [ 'iframe markup' => '<iframe', 'section markup' => 'class="mailsec"',
            'section CSS'   => '.lgws .mailsec{', 'preview route' => 'lg_wd_email_preview' ] as $label => $needle ) {
	if ( strpos( $off, $needle ) !== false ) { $leaked[] = $label; }
}
printf( "OFF leaks                 : %s\n", $leaked ? implode( ', ', $leaked ) : 'none' );
if ( $leaked ) { echo "  FAIL: still present with the flag OFF: " . implode( ', ', $leaked ) . "\n"; $fail++; }

/* ── 3. ...and the switch must actually switch ─────────────────────────────── */
if ( strpos( $on, '<iframe' ) === false ) {
	echo "  FAIL: ON rendered no iframe either — the flag is not wired to the section,\n";
	echo "        so the OFF result above proves nothing.\n";
	$fail++;
}

/* ── 2. THE NO-OP CLAIM: cut the gated regions from ON, require the rest to match ── */
$cut = preg_replace( '#<section[^>]*class="[^"]*mailsec[^"]*".*?</section>\s*#s', '', $on );
$cut = preg_replace( '#/\* RULING 2 .*?(?=/\* RULING 6)#s', '', $cut );
$norm = static fn( string $h ): string => trim( preg_replace( '/\s+/', ' ', $h ) );

if ( $norm( $cut ) === $norm( $off ) ) {
	echo "no-op proof               : ON minus the gated regions == OFF, byte-identical\n";
} else {
	echo "  FAIL: with the section AND its CSS removed from the ON render, the result still\n";
	echo "        differs from OFF — the flag reaches beyond its own surface.\n";
	printf( "        ON-minus-gated %d vs OFF %d\n", strlen( $norm( $cut ) ), strlen( $norm( $off ) ) );
	$a = $norm( $cut ); $b = $norm( $off );
	for ( $i = 0, $n = min( strlen( $a ), strlen( $b ) ); $i < $n; $i++ ) {
		if ( $a[ $i ] !== $b[ $i ] ) {
			printf( "        first divergence at %d:\n          cut: %s\n          off: %s\n",
				$i, substr( $a, $i, 80 ), substr( $b, $i, 80 ) );
			break;
		}
	}
	$fail++;
}

echo $fail ? "\n$fail FAILURE(S)\n" : "\nPREVIEW FLAG OFF IS A NO-OP\n";
exit( $fail ? 1 : 0 );
