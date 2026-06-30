<?php
/**
 * Plugin Name: LG Poller Mail Killswitch (dev2 ONLY — TEMPORARY)
 * Description: Suppresses BULK email from lg-patreon-stripe-poller (the hourly
 *   sync report + welcome/membership mails) while the plugin stays ACTIVE (so
 *   the /whoami bridge + admin login are untouched). INTENTIONAL notifications
 *   — provision/bridge/role failure alerts and the member "we're aware" note —
 *   are tagged with the header `X-LG-Poller-Intent: notify` and pass through;
 *   those are separate and must reach members + Ian. Revert = delete this file.
 *
 * @lg-folded-in 2026-06-26  (poller-monorepo-reconcile P3; was deployed-but-unrepo'd
 *   at dev2 wp-content/mu-plugins/lg-poller-mail-killswitch.php)
 * @lg-review-after 2027-06-26
 * @lg-dev-only DO NOT DEPLOY TO PROD. Belt-and-braces with lg-dev-mail-containment;
 *   on live the poller MUST be able to send. Decommission when the poller's own
 *   `lgms_poller_mail_enabled` flag (OFF on dev) fully governs dev mail and this
 *   stack-trace suppressor is redundant. See [[project_poller_email_stripe_freeze_flags]],
 *   [[project_dev2_mail_containment_guard]].
 */
if (!defined('ABSPATH')) return;

// Self-disable wherever poller mail is INTENTIONALLY enabled (live, where
// lgms_poller_mail_enabled is ON): the poller's own mail-gate then governs
// delivery and this belt-and-braces suppressor must NOT block it. Active only
// where mail is OFF (dev) -> this ONE file is safe to ship everywhere (single pull).
if ( (bool) get_option( 'lgms_poller_mail_enabled', false ) ) { return; }
add_filter('pre_wp_mail', function ($short, $atts) {
    // Allow-list: anything explicitly marked as an intentional poller
    // notification passes through, even though it originates in the plugin.
    $headers = is_array($atts) ? ($atts['headers'] ?? '') : '';
    $flat = is_array($headers) ? implode("\n", $headers) : (string) $headers;
    if (stripos($flat, 'X-LG-Poller-Intent') !== false) {
        return $short; // proceed with normal delivery
    }
    // Otherwise suppress any wp_mail whose call stack runs through the poller.
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
        if (!empty($f['file']) && strpos($f['file'], '/lg-patreon-stripe-poller/') !== false) {
            error_log('LG poller-mail-killswitch: suppressed — ' . (is_array($atts) ? ($atts['subject'] ?? '') : ''));
            return false;
        }
    }
    return $short;
}, 10, 2);
