<?php
/**
 * Admin-only Stripe controls — minimal template (iframed by the standalone
 * /manage-subscription/ surface).
 *
 * Renders [lg_manage_subscription] inside a stripped doc with wp_head/wp_footer
 * — so the shortcode's enqueued JS, REST, and nonces all load — but with no
 * theme chrome and no membership-page shell. This is the path that keeps Stripe
 * "dormant but TESTABLE" at cut: admins iframe this URL to drive plan-switch /
 * payment-method / cancel-timing controls; members never see it.
 *
 * Server-side admin gate fires in the lg-membership-chrome template_include
 * hook BEFORE this template loads. By the time we reach here, manage_options
 * is verified.
 *
 * The 200 status header overrides whatever WP set for the (intentionally
 * missing) /__lg-stripe-panel/ URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

status_header( 200 );
nocache_headers();

// Clickjacking guard: this admin panel is iframed ONLY by the same-origin
// /manage-subscription/ surface (CSP) — refuse embedding from any other origin.
header( 'X-Frame-Options: SAMEORIGIN' );
header( "Content-Security-Policy: frame-ancestors 'self'" );

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stripe controls — admin</title>
<style>
    /* Minimal reset for the iframe context. The shortcode's own styles
     * still apply — these just keep the body padding sane and prevent
     * accidental margin collapse with the parent surface. */
    body { margin: 0; padding: 1rem 1.25rem; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .lg-stripe-panel-admin__banner {
        margin: 0 0 1rem;
        padding: .5rem .75rem;
        background: #fef3c7;
        border-left: 3px solid #f59e0b;
        font-size: .8rem;
        color: #92400e;
    }
</style>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'lg-stripe-panel-admin' ); ?>>

<p class="lg-stripe-panel-admin__banner">
    Admin-only Stripe controls — rendered from <code>[lg_manage_subscription]</code> for testing
    while Stripe is dormant for members.
</p>

<?php
echo do_shortcode( '[lg_manage_subscription]' );
?>

<?php wp_footer(); ?>
</body>
</html>
