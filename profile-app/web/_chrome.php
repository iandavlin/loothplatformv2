<?php
/**
 * profile-app/web/_chrome.php — site header partial.
 *
 * Thin adapter: resolves viewer state via Whoami::resolve() (no HTTP hop
 * since /whoami lives in-process) and passes it to the shared partial at
 * /srv/lg-shared/site-header.php. Mirrors archive-poc's pattern.
 *
 * Include from any profile-app web template right after <body>:
 *   <?php require __DIR__ . '/_chrome.php'; ?>
 *
 * Companion footer call at the end of <body>:
 *   <?php lg_shared_render_site_footer(); ?>
 *
 * CSS link in <head>:
 *   <link rel="stylesheet" href="/lg-shared/site-header.css">
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once '/srv/lg-shared/site-header.php';
require_once '/srv/lg-shared/site-footer.php';

use Looth\ProfileApp\Whoami;

$_whoami = Whoami::resolve();

lg_shared_render_site_header([
    'logo_url'      => LG_PROFILE_APP_LOGO_URL,
    'authenticated' => (bool) ($_whoami['authenticated'] ?? false),
    'tier'          => (string) ($_whoami['tier'] ?? 'public'),
    'display_name'  => (string) ($_whoami['display_name'] ?? ''),
    'avatar_url'    => $_whoami['avatar_url'] ?? null,
    'capabilities'  => (array) ($_whoami['capabilities'] ?? []),
    'msg_unread'    => null,  // lazy-loaded; no rail in profile-app for unread polling yet
    'notif_unread'  => null,
    'profile_url'   => isset($_whoami['slug']) && $_whoami['slug']
        ? '/u/' . rawurlencode((string)$_whoami['slug'])
        : '/profile/edit',
]);

// /u/ uses this profile-app chrome, and the shared header does NOT emit the script tag
// here — so this IS the only loader of social-modals.js on profile pages (the DM modal +
// the notifications/connections panels). It MUST carry the ?v=filemtime cache-bust:
// /lg-shared/*.js is served `immutable, max-age=1yr`, so a BARE url hands the browser a
// year-old copy forever. That is exactly what happened — profile pages ran stale panel
// code and notification rows rendered WITHOUT their links, while every other surface
// (which loads it versioned via site-header.php) had them (Ian, 2026-07-13).
echo '<script src="/lg-shared/social-modals.js?v=' . (@filemtime('/srv/lg-shared/social-modals.js') ?: '1') . '" defer></script>';
