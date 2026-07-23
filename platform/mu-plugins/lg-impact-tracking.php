<?php
/**
 * Plugin Name: LG Impact Tracking
 * Description: impact.com "Stewmac Affiliate Catcher" (P-A3499220) on wp_head, for
 * WP-theme pages (sponsor-product, sponsor-post, anything still on BuddyBoss chrome).
 * Shared-chrome surfaces get the same tag from lg-shared/site-header.php; the
 * window.__lgImpactTag guard dedupes pages that render both. Replaces the DB-scoped
 * Code Snippets row 98 ("Stewmac Affiliate Catcher"), which must stay DISABLED or
 * impressions double-fire. Kill switch: set option lg_impact_tracking_enabled=0.
 */

add_action("wp_head", function () {
    if (get_option("lg_impact_tracking_enabled", "1") === "0") {
        return;
    }
    $tag = "/srv/lg-shared/impact-tag.php";
    if (is_file($tag)) {
        require_once $tag;
        if (function_exists("lg_impact_tag_render")) {
            lg_impact_tag_render();
        }
    }
}, 20);
