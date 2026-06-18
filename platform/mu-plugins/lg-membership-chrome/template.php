<?php
/**
 * lg-membership-chrome custom template.
 *
 * Renders a WP page on the shared /srv/lg-shared/ header instead of the
 * BuddyBoss theme. Loaded via the template_include filter in the
 * lg-membership-chrome mu-plugin bootstrap.
 *
 * The page's content (including any shortcodes the poller plugin renders)
 * goes inside the .lg-membership-chrome__main wrapper between the shared
 * header and shared footer.
 *
 * wp_head() / wp_footer() are emitted so plugin-enqueued styles/scripts
 * still load — important for the welcome modal hook, REST endpoints, and
 * Plugin.php::addCustomerBodyClass() (lgms-mg-anon / lgms-mg-member body
 * classes that MembershipGuide.php and related shortcodes depend on).
 *
 * Note on stylesheet bloat: this template does NOT dequeue the BuddyBoss
 * theme's CSS. For the PoC that's intentional — the shared header CSS
 * lives in its own scope, and we leave theme/plugin CSS alone so any
 * shortcode-internal markup that depends on BB classes continues to
 * render. A future cleanup pass can dequeue selectively if the visual
 * audit shows BB styles fighting the shell.
 */

require_once '/srv/lg-shared/site-header.php';

if ( ! function_exists( 'lg_membership_chrome_viewer' ) ) {
    // Defensive — the bootstrap should have loaded already, but template files
    // can theoretically be loaded out of order if something weird happens.
    require_once dirname( __DIR__ ) . '/lg-membership-chrome.php';
}

$viewer = lg_membership_chrome_viewer();

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( wp_get_document_title() ); ?></title>
<link rel="stylesheet" href="/lg-shared/site-header.css">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'lg-membership-chrome' ); ?>>

<?php lg_shared_render_site_header( $viewer ); ?>

<main class="lg-membership-chrome__main">
<?php
if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        the_content();
    }
}
?>
</main>

<?php
if ( function_exists( 'lg_shared_render_site_footer' ) ) {
    lg_shared_render_site_footer( [] );
}
wp_footer();
?>
</body>
</html>
