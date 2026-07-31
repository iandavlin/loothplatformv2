<?php
/**
 * signup-preview.php — serve THIS BRANCH's signup page on the vhost Ian is already
 * signed into, so variant B can be looked at and gated without merging.
 *
 * Reached only through platform/nginx/lane-preview-weekly-digest-recap.conf, which
 * lane-preview.sh installs. Never linked from anything.
 *
 * ── WHY A FRONT CONTROLLER AND NOT A SCRIPT_FILENAME SWAP ───────────────────
 *
 * account-following's preview points SCRIPT_FILENAME at its branch's router.php and
 * is done, because membership-pages is a standalone front controller — the pool is
 * a PHP runtime and takes its target per request.
 *
 * This surface is a WORDPRESS PAGE. Its markup comes from a shortcode in a plugin
 * that WP loads through the docroot symlink into the SERVING checkout, and no
 * fastcgi_param can repoint that. Loading the branch's class alongside it would
 * fatal on a duplicate declaration — the same collision dev/_load-under-test.php
 * exists to refuse.
 *
 * So the split is: the deployed PLUGIN supplies the values (ajax url, prefs url,
 * preview url), and the BRANCH TEMPLATE supplies the markup and CSS — which is
 * exactly what is under review. Variant B is a template change, so the template is
 * the only thing that has to come from the branch.
 *
 * ── TWO THINGS THAT WOULD OTHERWISE BREAK IT ────────────────────────────────
 *
 * 1. wp-load.php is required by ABSOLUTE PATH. This file is reached through an
 *    nginx alias into a worktree, so __DIR__ is the worktree and there is no
 *    wp-load.php next to it — the box's trap 7, and the reason manage-subscription
 *    requires /srv/lg-shared/site-header.php absolutely rather than relatively.
 *
 * 2. A MAIN QUERY IS SET UP before the template runs. The deployed preview_url()
 *    resolves from get_queried_object_id(); with no main query it returns '' and the
 *    sample-email section — the entire thing being reviewed — silently vanishes.
 *    That dependency is real and is why the branch moved the route to admin-ajax;
 *    here it is satisfied rather than worked around, so the preview shows the page
 *    as a visitor gets it.
 */

declare( strict_types=1 );

require '/var/www/dev/wp-load.php';          // absolute: see note 1 above.

if ( ! class_exists( 'LG_WD_Signup_Page' ) ) {
	http_response_code( 500 );
	exit( "lane-preview: lg-weekly-digest is not active on this box, so there is nothing to preview.\n" );
}

/* The page that hosts the shortcode, found the way the tests find it rather than
   by a hardcoded id, so this does not rot if the page is recreated. */
$host = get_posts( [
	'post_type' => 'page', 'post_status' => 'publish', 's' => 'lg_weekly_signup',
	'posts_per_page' => 5, 'fields' => 'ids',
] );
$host_id = 0;
foreach ( $host as $id ) {
	if ( has_shortcode( (string) get_post_field( 'post_content', $id ), 'lg_weekly_signup' ) ) {
		$host_id = (int) $id; break;
	}
}
if ( ! $host_id ) {
	http_response_code( 500 );
	exit( "lane-preview: no published page carries [lg_weekly_signup] on this box.\n" );
}

/* See note 2. Set the main query so the deployed preview_url() can resolve. */
$GLOBALS['wp_query']     = new WP_Query( [ 'page_id' => $host_id ] );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
if ( $GLOBALS['wp_query']->have_posts() ) { $GLOBALS['wp_query']->the_post(); }

/* The three values the template reads. Taken from the DEPLOYED class on purpose —
   only the markup is under review, and pretending otherwise would be previewing a
   thing nobody is about to merge. */
$lgws_ajax   = LG_WD_Signup_Page::ajax_url();
$lgws_sample = LG_WD_Signup_Page::sample_email_url();
$lgws_prefs  = LG_WD_Signup_Page::prefs_url();

$branch_tpl = __DIR__ . '/../../templates/signup-page.php';
if ( ! is_readable( $branch_tpl ) ) {
	http_response_code( 500 );
	exit( "lane-preview: branch template missing at $branch_tpl\n" );
}

header( 'Content-Type: text/html; charset=utf-8' );
header( 'X-Robots-Tag: noindex' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PREVIEW — weekly signup (branch weekly-digest-recap)</title>
<style>
  body{margin:0;background:#FAF6EE}
  .lanebar{position:sticky;top:0;z-index:99;background:#2B2318;color:#ECB351;
           font:600 12.5px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
           padding:8px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .lanebar b{color:#FAF6EE}
  .lanebar span{opacity:.85;font-weight:400}
</style>
</head>
<body>
<div class="lanebar">
  <b>PREVIEW</b>
  <span>branch <b>weekly-digest-recap</b> — variant B, the email box at its own 992px width</span>
  <span>· page markup and CSS from the branch; values from the deployed plugin</span>
</div>
<?php
include $branch_tpl;
?>
</body>
</html>
