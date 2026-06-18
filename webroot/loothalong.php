<?php
/**
 * Loothalong — gated join redirect.
 *
 * The Loothalong is a member-only live Zoom jam. We deliberately NEVER ship the
 * Zoom room URL to the browser: the CTA banner (loothalong.js) is injected on
 * the public /events/ page, so a logged-out visitor could otherwise read the
 * room link straight out of the inspector. Instead the banner points here, and
 * the room URL is resolved + redirected entirely server-side:
 *
 *   - logged-in member  -> 302 to the live Zoom room
 *   - everyone else      -> 302 to log in, then bounced back here to join
 *
 * Source of truth for the room link is the "Loothalong Zoom Call" WP nav menu
 * item (shown in the logged-in menus); mirrored here for the redirect. If the
 * room ever changes, update it in both places.
 */
declare(strict_types=1);

require __DIR__ . '/wp-load.php';

// Real room link — kept server-side only (PHP executes, so this is never
// emitted to an unauthorized browser).
$ZOOM_URL = 'https://us02web.zoom.us/j/87325405572?pwd=ZnA3NEtwTlNXN0RKQThCNVJ2YzZoQT09';

if ( is_user_logged_in() ) {
    wp_redirect( $ZOOM_URL, 302 );
} else {
    wp_redirect( wp_login_url( home_url( '/loothalong.php' ) ), 302 );
}
exit;
