<?php
/**
 * Plugin Name: LG Dev Disable looth1 Bounce (dev2 ONLY)
 * Description: DEV ONLY — disables snippet 90 (force_redirect_looth1_user) so
 *   looth1 accounts can log in for testing on dev2. Delete this file to restore
 *   the bounce. NOT for live.
 *
 * @lg-folded-in 2026-06-26  (poller-monorepo-reconcile P3; was deployed-but-unrepo'd
 *   at dev2 wp-content/mu-plugins/lg-dev-disable-looth1-bounce.php)
 * @lg-review-after 2027-06-26
 * @lg-dev-only DO NOT DEPLOY TO PROD. On live, looth1 is gated by design
 *   (see [[project_looth1_origin]] / [[reference_tier_taxonomy]]); this opener is
 *   purely a dev-login convenience. Decommission when looth1 dev testing no longer
 *   needs the bypass.
 */
if (!defined("ABSPATH")) exit;
add_action("wp_loaded", function () {
    remove_action("wp_login", "force_redirect_looth1_user", 1);
}, 1);
