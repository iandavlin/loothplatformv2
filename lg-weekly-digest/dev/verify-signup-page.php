<?php
/**
 * verify-signup-page.php — the PUBLIC signup page's build, asserted against the
 * markup it actually renders rather than against the mock it was ported from.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 *
 * ── WHY THIS TEST EXISTS AT ALL, GIVEN THE MOCK WAS ALREADY CHECKED ─────────
 * The mock was verified against its SERVED bytes on 2026-07-30 and all six of
 * Ian's rulings were present. That proves nothing about this page: the mock is a
 * standalone document with its own <style> and its own <body>, and the build is a
 * shortcode rendering inside the theme. Everything that can go wrong in the port
 * — CSS escaping its scope, a ruling dropped, the form losing its endpoint — is
 * invisible to a check of the mock.
 *
 * ── THE ONE THAT IS A SAFETY PROPERTY AND NOT A STYLE CHECK ─────────────────
 * §D: the on-page sample email must not contain the literal `##lg_recap.section##`
 * and must not carry another member's recap. The preview is rendered for an
 * ANONYMOUS visitor, and the recap section is per-member data. It is stripped by
 * an explicit `mode => strip`, and this asserts the outcome of that mode rather
 * than trusting the argument was passed.
 *
 * ── PROVE IT RED, NOT ONLY GREEN ────────────────────────────────────────────
 *   LG_SIGNUP_TPL=/path/to/a/broken/copy  sudo -u looth-dev wp ... eval-file <this>
 * points the CSS-scope assertion at a copy carrying one unscoped rule; the guard
 * must FAIL rather than pass or fatal. A guard only ever seen green is not known
 * to be a guard.
 *
 * READ-ONLY. Renders markup and reads two wp_options; writes nothing, sends
 * nothing, and never calls the signup endpoint (whose 'new' branch would create a
 * real contact).
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run with: wp eval-file\n" ); exit( 1 ); }

$L = '/home/ubuntu/worktrees/weekly-digest-recap';
require_once $L . '/lg-weekly-digest/includes/class-lg-wd-signup-page.php';

$fail = 0;
$ck = function ( string $what, $got, $exp ) use ( &$fail ) {
	$ok = $got === $exp;
	printf( "  %-56s %s\n", $what, $ok ? 'OK' : 'FAIL got=' . var_export( $got, true ) );
	if ( ! $ok ) { $fail++; }
};
$has = function ( string $what, string $hay, string $needle ) use ( &$fail ) {
	$ok = str_contains( $hay, $needle );
	printf( "  %-56s %s\n", $what, $ok ? 'OK' : 'FAIL (absent)' );
	if ( ! $ok ) { $fail++; }
};

$html = LG_WD_Signup_Page::render();

echo "--- A. the page renders at all, and inside one scoped wrapper ---\n";
$ck( 'render() returned markup', strlen( $html ) > 2000, true );
$has( 'opens the .lgws wrapper', $html, '<div class="lgws">' );
$ck( 'exactly one .lgws wrapper', substr_count( $html, '<div class="lgws">' ), 1 );
// A shortcode must never emit page furniture: it renders INSIDE the theme.
foreach ( [ '<!doctype', '<html', '<head>', '<body' ] as $tag ) {
	$ck( "no page furniture: $tag", stripos( $html, $tag ) !== false, false );
}

echo "--- B. Ian's six rulings, in the BUILD ---\n";
$has( 'R1 build-and-repair headline', $html, 'For people who build and repair guitars.' );
$has( 'R1 luthiers named in the lede', $html, 'luthiers, repairers' );
$has( 'R1 repairs tile', $html, 'Repairs &amp; restorations' );
$has( 'R1 builds tile (building is not a footnote)', $html, 'Builds in progress' );
$has( 'R2 frame is the email\'s own 624px column', $html, '.lgws .mail{max-width:624px' );
$has( 'R2 frame is the email\'s own body colour', $html, '--mail:#e8e2d8' );
$ck( 'R2 no shadow on the mail frame',
	(bool) preg_match( '/\.lgws \.mail\{[^}]*box-shadow/', $html ), false );
$has( 'R3/4 the three-step window band', $html, 'Why it\'s worth being on the list' );
$has( 'R3/4 step 3 shuts the door', $html, 'Later it goes members-only' );
$has( 'R3/4 the closing proposition', $html, 'it\'s the window' );
$has( 'R3/4 lede says while they\'re still public', $html, 'while they\'re still public' );
$has( 'R6 member block is on the page', $html, 'Already a Looth Group member?' );
$has( 'R6 never silently added, said in the open', $html, 'never add a member to the non-member list' );

echo "--- C. the form is wired to the PROVEN endpoint, not to a new one ---\n";
$has( 'posts the ruling-6 action', $html, "body.append('action', 'lg_weekly_signup')" );
$has( 'posts to admin-ajax', $html, 'admin-ajax.php' );
$has( 'honeypot field is present', $html, "name=\"website\"" );
$has( 'honeypot is submitted (the endpoint reads it)', $html, "body.append('website'" );
$has( 'consent checkbox is required', $html, 'name="gdpr-agreement" required' );
// All four audience states must have page-side headings, or a member gets a body
// of text with no heading. The BODY copy is deliberately the endpoint's.
foreach ( [ 'pending', 'already_signed_up', 'already_member', 'member_needs_prefs' ] as $state ) {
	$has( "state '$state' has a heading", $html, $state . ':' );
}
$ck( 'the page does NOT hardcode the endpoint\'s member copy',
	str_contains( $html, "You're already on the list — the weekly email comes with" ), false );

echo "--- D. SAFETY: the anonymous preview carries no per-member data ---\n";
$sample = LG_WD_Signup_Page::sample_email_url();
if ( $sample === '' ) {
	echo "  (no sent issue on this box — preview section correctly omitted)\n";
	$ck( 'no iframe rendered when there is no issue', str_contains( $html, '<iframe' ), false );
} else {
	$ref = new ReflectionMethod( 'LG_WD_Signup_Page', 'build_email_preview' );
	$ref->setAccessible( true );
	$mail = (string) $ref->invoke( null );

	$ck( 'preview rendered', strlen( $mail ) > 1000, true );

	/**
	 * The smartcode is spelled out here rather than read from
	 * LG_WD_RECAP_SMARTCODE, and that is deliberate, not laziness.
	 *
	 * The plugin ACTIVE on this box is the SERVING CHECKOUT's copy, which is on
	 * main and predates this branch — so the constant is undefined there and this
	 * test fatalled on it. Requiring the worktree's bootstrap instead would
	 * re-register every hook the live plugin already registered. So: the literal,
	 * pinned to the branch's own define() so the two cannot drift apart silently.
	 */
	$SMART = '##lg_recap.section##';
	$boot  = (string) file_get_contents( $L . '/lg-weekly-digest/lg-weekly-digest.php' );
	$ck( "the literal still matches the branch's define()",
		str_contains( $boot, "define( 'LG_WD_RECAP_SMARTCODE', '" . $SMART . "' )" ), true );
	$ck( 'NO literal recap smartcode reaches an anon visitor',
		str_contains( $mail, $SMART ), false );
	$ck( 'no recap section markup at all', str_contains( $mail, 'lg-recap' ), false );
	// The recap greets by profile name; none may appear in an anonymous render.
	$ck( 'no per-member greeting', (bool) preg_match( '/Your week,\s*\w/', $mail ), false );
	$has( 'the iframe is on the page', $html, '<iframe' );
	$has( 'iframe is lazy (it is below the fold)', $html, 'loading="lazy"' );

	echo "--- D2. the preview's images ride the resizer (the gate cannot see in) ---\n";
	preg_match_all( '#src="([^"]*/wp-content/uploads/[^"]+)"#i', $mail, $raw );
	preg_match_all( '#src="([^"]*/img\.php\?s=[^"]+)"#i', $mail, $rz );
	$n_raw = count( $raw[1] );
	$n_rz  = count( $rz[1] );
	printf( "  %-56s %s\n", "resizer-routed images", $n_rz );
	if ( file_exists( ABSPATH . 'img.php' ) ) {
		$ck( 'no raw upload URL left in the preview', $n_raw, 0 );
		$ck( 'at least one image was rewritten', $n_rz > 0, true );
		$ck( 'every rewrite uses an ALLOWED_W bucket',
			(bool) preg_match( '/w=(?!96|240|400|480|600|800|960|1200|1600)\d+/', $mail ), false );
	} else {
		echo "  (no /img.php on this box — rewrite correctly skipped, raw=$n_raw)\n";
	}
}

echo "--- E. craft: the page chrome ships no images and no editor ---\n";
// Everything outside the iframe must be text/CSS. The craft gate's IMG rules bite
// on <img>; having none is how this page is exempt from them by construction.
$chrome = preg_replace( '#<iframe.*?</iframe>#s', '', $html );
$ck( 'zero <img> tags in the page chrome', substr_count( (string) $chrome, '<img' ), 0 );
$ck( 'no editor marker (quill) pulled in', stripos( $html, 'quill' ) !== false, false );
// Scoped to the chrome on purpose: the sample email inside the iframe really does
// fetch YouTube thumbnails, because that is what the issue that went out contains.
// Asserting "no external host" over the whole page would either be false or would
// quietly claim something about the iframe that this test does not check.
$ck( 'no external host in the page chrome', (bool) preg_match( '#(src|href)="https?://(?!' . preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '#' ) . ')#i', (string) $chrome ), false );
printf( "  %-56s %s\n", 'page chrome weight', round( strlen( $html ) / 1024, 1 ) . 'KB' );

echo "--- F. the CSS cannot escape .lgws and restyle the site ---\n";
// The mock's `section{padding:52px 0}` would have restyled every <section> on any
// page carrying this shortcode. Parse the real rules rather than eyeballing them.
$tpl_path = getenv( 'LG_SIGNUP_TPL' ) ?: ( $L . '/lg-weekly-digest/templates/signup-page.php' );
printf( "  template under test: %s\n", $tpl_path );
$css = '';
if ( preg_match( '#<style>(.*?)</style>#s', (string) file_get_contents( $tpl_path ), $m ) ) {
	$css = $m[1];
}
$ck( 'a <style> block was found to check', $css !== '', true );
$css_nc  = (string) preg_replace( '#/\*.*?\*/#s', '', $css );   // comments carry prose, not selectors
$unscoped = [];
foreach ( explode( '}', $css_nc ) as $chunk ) {
	$pos = strpos( $chunk, '{' );
	if ( $pos === false ) { continue; }
	$sel = trim( substr( $chunk, 0, $pos ) );
	if ( $sel === '' || $sel[0] === '@' ) { continue; }          // @media wrapper
	foreach ( explode( ',', $sel ) as $one ) {
		$one = trim( $one );
		if ( $one === '' ) { continue; }
		if ( ! str_starts_with( $one, '.lgws' ) ) { $unscoped[] = $one; }
	}
}
if ( $unscoped ) {
	printf( "  %-56s FAIL: %s\n", 'every selector is scoped to .lgws', implode( ' | ', array_slice( $unscoped, 0, 5 ) ) );
	$fail++;
} else {
	printf( "  %-56s OK\n", 'every selector is scoped to .lgws' );
}

echo $fail ? "\n$fail FAILED\n" : "\nSIGNUP PAGE OK\n";
