<?php
/**
 * render-built-page.php — render the BUILT signup page to a static file for Ian.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 *   then: bash lg-weekly-digest/dev/signup/publish-signup.sh
 *
 * ── WHY A STATIC RENDER AND NOT JUST A URL ──────────────────────────────────
 * The page cannot be reached at /weekly-email-sign-up/ yet: lg-weekly-digest is
 * symlinked into the docroot from the SERVING CHECKOUT, which is on main and pulls
 * only, so nothing this lane has written is being served. Ian gets the real markup
 * now rather than waiting on a merge.
 *
 * ── THE FORM IS MADE INERT, AND THAT IS NOT A FORMALITY ─────────────────────
 * `wp_ajax_nopriv_lg_weekly_signup` IS LIVE ON DEV2 — measured, not assumed
 * (mu-plugins/lg-event-reminders.php carries it and mu-plugins load unconditionally).
 * The built page's JS posts to admin-ajax with credentials, so publishing it as-is
 * behind the dev gate would put a WORKING signup form on a preview page: one click
 * from Ian and FluentCRM gains a real contact and sends a real double-opt-in email.
 * So the script tag is dropped and the button is disabled, and publish-signup.sh
 * re-asserts inertness on the published bytes.
 *
 * WHAT IAN GAINS BY THE FORM BEING DEAD: all four answers are drawn at once, as
 * panels, instead of one at a time behind four different email addresses he does not
 * have. The copy in them is READ FROM THE ENDPOINT'S OWN SOURCE, so this cannot
 * drift into showing him wording that is not what a person would actually receive.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file\n" ); exit( 1 ); }

$L = '/home/ubuntu/worktrees/weekly-digest-recap';
require_once $L . '/lg-weekly-digest/includes/class-lg-wd-signup-page.php';

/**
 * ── THIS SCRIPT WROTE NOTHING AND SAID IT HAD, ON ITS FIRST RUN ─────────────
 * It ran as `looth-dev` (wp-cli must, to bootstrap WP) and wrote into the lane's
 * worktree, which is ubuntu:ubuntu 755. Every file_put_contents() returned false,
 * and because the success lines printed strlen($doc) rather than the WRITE RESULT,
 * it reported "built-page.html: 16 KB, form inert, 4 state panels" over an empty
 * directory. Caught only by listing the directory afterwards.
 *
 * Two fixes, both of which had to be made: write somewhere the running user can
 * (a tmp dir, then copied into the worktree as ubuntu by the caller), and make
 * every write ASSERT. A renderer that cannot write must fail, not narrate.
 */
$OUT = getenv( 'LG_SIGNUP_OUT' ) ?: '/tmp/lgws-render';
if ( ! is_dir( $OUT ) && ! @mkdir( $OUT, 0777, true ) && ! is_dir( $OUT ) ) {
	fwrite( STDERR, "CANNOT CREATE $OUT\n" ); exit( 2 );
}
$put = function ( string $file, string $bytes ) use ( $OUT ): int {
	$n = @file_put_contents( "$OUT/$file", $bytes );
	if ( $n === false || $n !== strlen( $bytes ) ) {
		fwrite( STDERR, sprintf( "WRITE FAILED: %s/%s (wrote %s of %d bytes) — user=%s\n",
			$OUT, $file, var_export( $n, true ), strlen( $bytes ),
			function_exists( 'posix_getpwuid' ) ? posix_getpwuid( posix_geteuid() )['name'] : '?' ) );
		exit( 2 );
	}
	@chmod( "$OUT/$file", 0644 );
	return $n;
};

// ── 1. The sample email, exactly as the built page renders it ────────────────
$ref = new ReflectionMethod( 'LG_WD_Signup_Page', 'build_email_preview' );
$ref->setAccessible( true );
$mail = (string) $ref->invoke( null );
if ( $mail === '' ) { fwrite( STDERR, "no sent issue to render\n" ); exit( 1 ); }
$put( 'signup-sample-email.html', $mail );
printf( "sample email: %d KB, %d resizer-routed images, %d raw uploads left\n",
	(int) round( strlen( $mail ) / 1024 ),
	preg_match_all( '#/img\.php\?s=#', $mail ),
	preg_match_all( '#src="[^"]*/wp-content/uploads/#', $mail ) );

// ── 2. The page itself ───────────────────────────────────────────────────────
$html = LG_WD_Signup_Page::render();

// Point the iframe at the sibling file: home_url('/?lg_wd_email_preview=1') is served
// by a plugin that is not deployed, so live it would be an empty frame.
$html = preg_replace( '#(<iframe src=")[^"]*(")#', '$1signup-sample-email.html$2', $html, 1 );

// ── 3. Kill the form ─────────────────────────────────────────────────────────
$html = preg_replace( '#<script>.*?</script>#s', '', $html );
$html = str_replace( '<button class="btn" type="submit">Send me the weekly</button>',
	'<button class="btn" type="button" disabled>Send me the weekly</button>', $html );

// ── 4. The four answers, read from the endpoint so they cannot drift ─────────
$mu  = (string) file_get_contents( $L . '/platform/mu-plugins/lg-event-reminders.php' );
$say = [];
foreach ( [
	'already_member'     => "A MEMBER fills it in",
	'member_needs_prefs' => "SOMEONE WITH AN ACCOUNT, not on the list — 233 on live",
	'already_signed_up'  => "ALREADY SIGNED UP",
	'pending'            => "A NEW NON-MEMBER — the only case that writes anything",
] as $state => $label ) {
	/**
	 * The handler writes these as concatenated literals:
	 *     'message' => "You're already on the list — the weekly email comes with your "
	 *                . "membership, so there's nothing to do here.",
	 * My first pass joined them with a regex expecting `" . \n "` and the real source
	 * is `" \n . "` — the operator is on the CONTINUATION line, not the first one. It
	 * matched nothing, so two of the four panels showed Ian half a sentence ending in
	 * a stray quote. Pulling the string LITERALS out and joining them has no operator
	 * order to get wrong.
	 */
	if ( preg_match( "#'state'\s*=>\s*'" . $state . "',\s*'message'\s*=>\s*(.+?),\n\s*\]\);#s", $mu, $m ) ) {
		preg_match_all( '#"((?:[^"\\\\]|\\\\.)*)"#', $m[1], $parts );
		$lit = $parts[1] ? stripcslashes( implode( '', $parts[1] ) ) : trim( $m[1], "\"' \n\t" );
		$say[] = [ $label, trim( $lit ) ];
	} else {
		$say[] = [ $label, '(COULD NOT READ THIS STATE FROM THE HANDLER)' ];
	}
}
$panels = '';
foreach ( $say as [ $label, $copy ] ) {
	$panels .= '<div class="st"><span class="stl">' . htmlspecialchars( $label ) . '</span>'
		. htmlspecialchars( $copy ) . '</div>';
}

$banner = <<<HTML
<div class="pvb">
  <b>This is the BUILT page, not a mock</b> — the real markup from
  <code>[lg_weekly_signup]</code>, rendered outside the theme. Two honest differences
  from what will serve: it sits on a bare page rather than inside the site chrome, and
  <b>the form is dead on purpose</b> (the live endpoint is deployed, so a working form
  here would sign people up for real). The four answers it gives are drawn below
  instead, read from the endpoint's own source.
</div>
HTML;

$states = <<<HTML
<div class="pvs">
  <h2>What the form actually says, in all four cases</h2>
  $panels
  <p class="pvn">The member list is never written on any of these paths. Only the last
  one writes at all, and only to the non-member list.</p>
</div>
HTML;

$css = <<<CSS
<style>
  body{margin:0;background:#FAF6EE}
  .pvb{background:#2B2318;color:#FAF6EE;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
       padding:14px 20px;border-bottom:3px solid #ECB351}
  .pvb b{color:#ECB351}
  .pvb code{background:rgba(236,179,81,.16);padding:1px 5px;border-radius:4px}
  .pvs{max-width:820px;margin:0 auto;padding:40px 20px 60px;
       font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#2B2318}
  .pvs h2{font-size:20px;margin:0 0 16px}
  .st{background:#fff;border:1px solid #e3dbcd;border-left:4px solid #87986A;border-radius:9px;
      padding:13px 16px;margin:0 0 10px;font-size:14.5px}
  .stl{display:block;font:700 10.5px/1 inherit;letter-spacing:.09em;text-transform:uppercase;
       color:#5d6b45;margin:0 0 6px}
  .pvn{font-size:13.5px;color:#6b6357;margin:14px 0 0}
</style>
CSS;

$doc = "<!doctype html>\n<meta charset=\"utf-8\">\n"
	. "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
	. "<title>The Looth Group Weekly — signup page (BUILT)</title>\n"
	. $css . $banner . $html . $states;

$put( 'built-page.html', $doc );

// The guard publish-signup.sh also enforces, asserted here so a bad render never
// reaches the publish step at all.
if ( preg_match( '#<script#i', $doc ) || ! str_contains( $doc, 'type="button" disabled' ) ) {
	fwrite( STDERR, "REFUSING: the rendered page is not inert\n" ); exit( 1 );
}
printf( "built-page.html: %d KB, form inert, %d state panels\n",
	(int) round( strlen( $doc ) / 1024 ), count( $say ) );
echo "BUILT PAGE RENDERED\n";
