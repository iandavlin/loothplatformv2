<?php
/**
 * bb-mirror/api/v0/auth.php — viewer-state endpoint for the reply form.
 *
 * GET /bb-mirror-api/v0/auth
 *
 * Runs on the WP FPM pool (looth-dev) so $current_user + wp_create_nonce()
 * are available. The bb-mirror FPM pool can't mint nonces — no WP context.
 *
 * Cookie-authed (browser sends `wordpress_logged_in_*` automatically since
 * same origin). Returns:
 *   { authenticated: bool,
 *     wp_user_id?:   int,
 *     display_name?: string,
 *     nonce?:        string   (X-WP-Nonce for /wp-json/buddyboss/v1/* writes) }
 *
 * NOT loopback-restricted — designed to be hit from the browser. nginx
 * exposes it under the bb-mirror-api prefix routed to the WP pool.
 *
 * No /whoami dependency. When /whoami lands (profile-app), the bb-mirror
 * client should ALSO call /whoami for the tier + capabilities surface. This
 * endpoint stays the source for the BB-REST nonce specifically.
 *
 * Future hook point: include `groups` array (current user's group memberships)
 * once profile-app's user_group table lands. Drives the "Join SoCal to post"
 * gating without an extra round-trip.
 */

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$uid = get_current_user_id();
if (!$uid) {
    echo json_encode(['authenticated' => false]);
    exit;
}

$u = wp_get_current_user();
// can_edit_others → moderators/admins (edit_others_topics maps to keep_gate /
// bbp_moderator). Drives the "edit any post" UI; BB REST re-checks server-side.
$can_edit_others = current_user_can('edit_others_topics')
    || current_user_can('moderate')
    || current_user_can('administrator');
echo json_encode([
    'authenticated'   => true,
    'wp_user_id'      => (int)$uid,
    'display_name'    => (string)$u->display_name,
    'nonce'           => wp_create_nonce('wp_rest'),
    'can_edit_others' => (bool)$can_edit_others,
    // Nonced one-tap logout for the mobile "You" sheet — the hub pages render on
    // the PG-only mirror pool (no WP), so they can't mint this; we can here. (Ian 6/17)
    // html_entity_decode: wp_logout_url() esc_url-encodes & as &amp; for HTML
    // attributes, but this is consumed as a JS href string — keep it raw so the
    // _wpnonce param isn't mangled into amp;_wpnonce (one-tap stays one-tap).
    'logout_url'      => html_entity_decode(wp_logout_url(), ENT_QUOTES),
    // groups: TBD — wires in when profile-app exposes user_group memberships.
    // Until then the form lets authenticated viewers post anywhere; BB REST
    // enforces group-membership server-side as a backstop.
]);
