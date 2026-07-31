<?php
/**
 * verify-preview-frames-the-email.php
 *
 * The signup page's centrepiece says "this is the most recent issue that actually
 * went out". On 2026-07-30 it framed the DISCOVER FEED instead, and returned 200
 * the whole way.
 *
 * WHY IT HAPPENED: preview_url() was built from home_url('/'), and `/` is served by
 * archive-poc's strangler, which answers before WordPress's `template_redirect` —
 * the hook maybe_serve_preview() lives on. So the handler never ran and the iframe
 * got the front page.
 *
 * WHY NOTHING CAUGHT IT: every layer was healthy. 200 status, real HTML, an iframe
 * present in the markup, and the craft gate cannot see inside a frame at all. The
 * only thing wrong was WHICH DOCUMENT came back. That is the same class as the
 * `?page_id=N` trap this lane hit while measuring, and as `grep -c` counting lines:
 * a confident answer to a question nobody asked. Second occurrence -> it gets a test.
 *
 * This test fetches the preview URL over LOOPBACK (the dev gate authorizes 127.0.0.1
 * outright, so no cookie is needed) and asserts the returned document is the EMAIL.
 *
 * IT ALSO RUNS THE NEGATIVE CONTROL. It fetches the OLD url shape and requires that
 * one to look like the front page. If both documents look the same, this test cannot
 * tell them apart and is worthless — so that case reports CANNOT RUN (exit 2) rather
 * than a green it has not earned.
 *
 * Run: sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run via wp eval-file\n" ); exit( 1 ); }

$fail = 0;

/** Fetch a site path over loopback, Host-header'd, so the dev gate authorizes us. */
$get = static function ( string $url ): array {
	$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$loop = preg_replace( '#^https?://[^/]+#', 'https://127.0.0.1', $url );
	$r    = wp_remote_get( $loop, [
		'headers'   => [ 'Host' => $host ],
		'sslverify' => false,
		'timeout'   => 30,
		'redirection' => 0,
	] );
	if ( is_wp_error( $r ) ) {
		fwrite( STDERR, "CANNOT RUN: fetch failed for $url — " . $r->get_error_message() . "\n" );
		exit( 2 );
	}
	return [ (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) ];
};

/** Does this document look like the weekly email? */
$looks_like_email = static function ( string $h ): bool {
	return stripos( $h, 'unsubscribe' ) !== false
		&& preg_match( '/<title>[^<]*Week of /i', $h ) === 1;
};
/** Does it look like the discovery front page the strangler serves? */
$looks_like_front = static function ( string $h ): bool {
	return stripos( $h, 'view-discover' ) !== false;
};

// ── Locate the page that hosts the shortcode, the way a visitor reaches it ──
$hosts = get_posts( [
	'post_type'      => 'page',
	'post_status'    => 'publish',
	's'              => 'lg_weekly_signup',
	'posts_per_page' => 5,
	'fields'         => 'ids',
] );
$host_id = 0;
foreach ( $hosts as $id ) {
	if ( has_shortcode( (string) get_post_field( 'post_content', $id ), 'lg_weekly_signup' ) ) { $host_id = (int) $id; break; }
}
if ( ! $host_id ) {
	fwrite( STDERR, "CANNOT RUN: no published page contains [lg_weekly_signup]. Merge-window step 1 not done on this box?\n" );
	exit( 2 );
}
$permalink = (string) get_permalink( $host_id );
printf( "host page      : %d %s\n", $host_id, $permalink );

/** Does this box hold a sent issue at all? Decides whether an absent section is legitimate. */
$get_latest_sent_issue_id = static function (): int {
	if ( ! class_exists( 'LG_WD_Issue' ) ) { return 0; }
	foreach ( LG_WD_Issue::get_all_issues( 20 ) as $issue ) {
		if ( ( $issue['status'] ?? '' ) === 'sent' ) { return (int) $issue['id']; }
	}
	return 0;
};

// ── NEGATIVE CONTROL FIRST: the old URL shape must look WRONG ───────────────
[ $c_old, $b_old ] = $get( add_query_arg( 'lg_wd_email_preview', '1', home_url( '/' ) ) );
$old_is_front = $looks_like_front( $b_old ) && ! $looks_like_email( $b_old );
printf( "control  home_url('/')?lg_wd_email_preview=1 -> %d, %s\n",
	$c_old, $old_is_front ? 'front page (as expected — the strangler owns /)' : 'NOT the front page' );
if ( ! $old_is_front ) {
	fwrite( STDERR,
		"CANNOT RUN: the control document does not look like the front page, so this test\n" .
		"cannot distinguish the two documents and a GREEN below would be meaningless.\n" );
	exit( 2 );
}

// ── THE ASSERTION: the real preview URL must serve the EMAIL ────────────────
//
// THE URL MOVED TO admin-ajax (2026-07-30). The permalink form worked but required
// this to stay a WP-rendered PAGE, and Ian ruled the platform renders STANDALONE —
// at which point get_permalink() returns nothing and the section is dropped
// entirely. admin-ajax is routed by WordPress unconditionally, so the preview no
// longer depends on any page-routing decision. Proven anon-reachable on dev2: an
// unregistered action returns WP's 400/"0", i.e. the request reaches the ajax
// dispatcher rather than being redirected by the private-network gate.
$preview = add_query_arg( 'action', 'lg_wd_email_preview', admin_url( 'admin-ajax.php' ) );
[ $c_new, $b_new ] = $get( $preview );
printf( "actual   admin-ajax?action=lg_wd_email_preview -> %d, %d bytes\n", $c_new, strlen( $b_new ) );

if ( $c_new !== 200 )              { echo "  FAIL: expected 200\n"; $fail++; }
if ( $looks_like_front( $b_new ) ) { echo "  FAIL: served the DISCOVER FEED — the strangler answered, not WordPress\n"; $fail++; }
if ( ! $looks_like_email( $b_new ) ) {
	preg_match( '/<title>([^<]*)<\/title>/i', $b_new, $t );
	printf( "  FAIL: does not look like the weekly email (title: %s)\n", $t[1] ?? '(none)' );
	$fail++;
}

// ── And the page itself must actually point its iframe at that URL ──────────
//
// THE MISSING-SECTION CASE IS A FAILURE, NOT A NOTE. preview_url() returns '' —
// dropping the whole section — whenever it cannot produce a URL. So this area's own
// failure mode is the section VANISHING, which
// an earlier version of this test waved through with a "note" and a green. That
// would have turned a wrong-document bug into a missing-section bug and called it
// fixed. A box with no sent issue is the one legitimate reason for an absent
// section, so that is the only case allowed to pass, and it is checked rather than
// assumed.
$rendered  = do_shortcode( '[lg_weekly_signup]' );
$want      = esc_url( $preview );
$has_issue = (bool) $get_latest_sent_issue_id();

if ( strpos( $rendered, 'lg_wd_email_preview' ) === false ) {
	if ( $has_issue ) {
		echo "  FAIL: this box HAS a sent issue, but the page rendered no preview section at all.\n";
		echo "        The section is being dropped rather than pointed somewhere wrong — check\n";
		echo "        preview_url()'s early returns before assuming the URL fix is fine.\n";
		$fail++;
	} else {
		echo "  note: no sent issue on this box, so an absent section is correct\n";
	}
} elseif ( strpos( $rendered, $want ) === false ) {
	echo "  FAIL: the page's iframe does not point at the permalink-based preview URL.\n";
	echo "        EXPECTED RED until the fix is DEPLOYED — this assertion runs against the\n";
	echo "        SERVING plugin, and the fix to preview_url() lives on the branch. It turns\n";
	echo "        green on the pull. If it is still red after a pull, the fix did not land.\n";
	$fail++;
}

echo $fail ? "\n$fail FAILURE(S)\n" : "\nPREVIEW FRAMES THE EMAIL\n";
exit( $fail ? 1 : 0 );
