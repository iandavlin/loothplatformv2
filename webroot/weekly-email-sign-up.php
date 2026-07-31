<?php
/**
 * /weekly-email-sign-up/ — STANDALONE front controller.
 *
 * Ian, 2026-07-31: "nothing renders from the theme. We do standalone rendering." and
 * "I need the url to remain the same." Both are requirements, not preferences.
 *
 * Routed by platform/nginx/strangler-weekly-signup.conf, which claims the path BEFORE
 * WordPress sees the request. The URL is unchanged and there is no redirect: nginx
 * answers /weekly-email-sign-up/ directly. Page 68595 stays published and untouched —
 * comment the include out and the slug falls straight back to its WordPress render.
 * That is the rollback, and it is why 68595 must not be edited or unpublished.
 *
 * ── WHY wp-load AT ALL ──────────────────────────────────────────────────────
 * Standalone means off the THEME, not off WordPress. The page needs FluentCRM (the
 * signup endpoint), the digest plugin (the sample-email section) and the shared site
 * chrome. wp-load runs core + plugins but NOT the theme and NOT the query, so nothing
 * here emits wp_head/wp_footer and no theme template is ever loaded. Measured on dev2
 * before this file existed: the WP render was 152,449 bytes with 4 wp-content/themes
 * refs and 37 wp-includes refs; the same page through this controller is ~21KB with
 * ZERO of either.
 *
 * ⚠️ NOT __DIR__ FOR wp-load. This file is SYMLINKED into the docroot, and __DIR__
 * resolves through the symlink to the REPO, where wp-load.php does not and never will
 * live. lg-wp-load.php asks the request instead. My own lane-preview controller
 * hardcoded '/var/www/dev/wp-load.php' and worked perfectly on dev2 — it would have
 * fataled on live, which has a different docroot. Found by reading
 * docs/shop-planner-deploy.md, not by testing, because dev2 cannot show it.
 * (__DIR__ IS correct for lg-wp-load.php itself — that file travels with this one.)
 */

declare(strict_types=1);

$lg_wp_load = require __DIR__ . '/lg-wp-load.php';
if ($lg_wp_load === '' || !is_readable($lg_wp_load)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("weekly-email-sign-up: cannot locate wp-load.php from this docroot.\n");
}
require_once $lg_wp_load;

if (!class_exists('LG_WD_Signup_Page')) {
    // The plugin is what renders this page. Failing loudly beats an empty 200 — a
    // blank page here is a signup form nobody can use and nobody would report.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("weekly-email-sign-up: lg-weekly-digest is not active.\n");
}

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* The three values the template reads. All come from the plugin, so the flag state,
   the audience states and the sample-email route are whatever the plugin says they
   are — this controller decides none of them.

   $lgws_sample is '' whenever LG_WD_SIGNUP_EMAIL_PREVIEW is off (ON in dev2's
   wp-config, OFF on live), and the template's own `if ( $lgws_sample )` guard then
   drops the section and its CSS. The switch keeps working through the move.

   preview_url() no longer needs a main query: it resolves to admin-ajax now (fca65ac).
   That is precisely what lets this page stop being a WP page without the sample-email
   section silently vanishing — while it still resolved from get_permalink(), this
   conversion would have deleted it. */
$lgws_ajax   = LG_WD_Signup_Page::ajax_url();
$lgws_sample = LG_WD_Signup_Page::sample_email_url();
$lgws_prefs  = LG_WD_Signup_Page::prefs_url();

$lg_logged_in = is_user_logged_in();
$lg_ctx = ['authenticated' => $lg_logged_in, 'active_nav' => ''];
if ($lg_logged_in) {
    $u = wp_get_current_user();
    $lg_ctx['display_name'] = (string) ($u->display_name ?: $u->user_login);
    $lg_ctx['avatar_url']   = get_avatar_url($u->ID, ['size' => 96]) ?: null;
    $lg_ctx['logout_url']   = wp_logout_url(home_url('/weekly-email-sign-up/'));
}

$lg_header = '/srv/lg-shared/site-header.php';   // absolute on purpose — see the __DIR__ note
$lg_footer = '/srv/lg-shared/site-footer.php';
if (is_readable($lg_header)) { require_once $lg_header; }
if (is_readable($lg_footer)) { require_once $lg_footer; }

$lg_canonical = home_url('/weekly-email-sign-up/');
$lg_title     = 'Weekly Email Sign Up — The Looth Group';
$lg_desc      = 'One email a week for luthiers, repairers and benders of truss rods — '
              . "this week's public articles and videos, while they're still public.";

$tpl = WP_PLUGIN_DIR . '/lg-weekly-digest/templates/signup-page.php';
if (!is_readable($tpl)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("weekly-email-sign-up: template missing at {$tpl}\n");
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($lg_title) ?></title>
<meta name="description" content="<?= $h($lg_desc) ?>">
<link rel="canonical" href="<?= $h($lg_canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $h($lg_title) ?>">
<meta property="og:description" content="<?= $h($lg_desc) ?>">
<meta property="og:url" content="<?= $h($lg_canonical) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php if (is_readable('/srv/lg-shared/site-header.css')) : ?>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= $h((string) @filemtime('/srv/lg-shared/site-header.css')) ?>">
<?php endif; ?>
</head>
<body class="lgws-standalone">
<?php if (function_exists('lg_shared_render_site_header')) { lg_shared_render_site_header($lg_ctx); } ?>
<main>
<?php include $tpl; ?>
</main>
<?php if (function_exists('lg_shared_render_site_footer')) { lg_shared_render_site_footer($lg_ctx); } ?>
</body>
</html>
