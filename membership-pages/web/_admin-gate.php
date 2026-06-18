<?php
/**
 * _admin-gate.php — pre-launch admin-only gate for ported Stripe surfaces.
 *
 * Every Stripe membership surface ported from the poller is ADMIN-ONLY
 * (manage_options) while Ian builds it privately pre-launch — EXCEPT
 * /manage-subscription/ (members-visible), which does NOT include this.
 *
 * The shell lane hides these from the nav menu; this gates the URL itself so a
 * non-admin who guesses the path can't reach a half-built Stripe surface.
 * Mirrors the poller's own defense-in-depth (e.g. lg_test_checklist gates on
 * manage_options inside the shortcode regardless of the page).
 *
 * Usage — after config + lib/whoami + shared header/footer are required and
 * $ctx = lg_membership_header_ctx('') is built:
 *
 *   require __DIR__ . '/_admin-gate.php';
 *   lg_membership_admin_gate_or_exit($ctx);   // non-admins get a stub page + exit
 */
declare(strict_types=1);

if (!function_exists('lg_membership_admin_gate_or_exit')) {
function lg_membership_admin_gate_or_exit(array $ctx): void
{
    if (($ctx['capabilities']['manage_options'] ?? false) === true) {
        return; // admin — proceed to the real surface
    }

    $h = 'lg_membership_h';
    if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Not available — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<style>
.lg-gate__main { max-width: 640px; margin: 0 auto; padding: 4rem 1.25rem; text-align: center; }
.lg-gate__main h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
.lg-gate__main p { color: #666; margin: 0; }
</style>
</head>
<body class="lg-membership-page lg-gate">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main" class="lg-gate__main">
    <h1>This page isn't available yet</h1>
    <p>This area is still being set up. Check back soon.</p>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
<?php
    exit;
}
}

/**
 * lg_membership_prelaunch_gate_or_exit — flag-aware gate for the Stripe purchase
 * pages. Admin-only WHILE the `lgms_stripe_pages_live` toggle is off (Ian builds
 * the Stripe op privately pre-launch); once he flips it on, this is a no-op and
 * the page serves its real audience. Mirrors router.php's flag-aware decision so
 * a page file smoke-tested in isolation behaves identically to a routed hit.
 *
 * Use this in the flippable purchase pages INSTEAD of the hard admin gate. Pages
 * that must stay admin-only forever (e.g. test-checklist) keep the hard gate.
 */
if (!function_exists('lg_membership_prelaunch_gate_or_exit')) {
function lg_membership_prelaunch_gate_or_exit(array $ctx): void
{
    if (function_exists('lg_membership_stripe_pages_live')
        && lg_membership_stripe_pages_live()) {
        return; // toggle on → pages are live to their real audience
    }
    lg_membership_admin_gate_or_exit($ctx); // pre-launch → admin-only stub
}
}
