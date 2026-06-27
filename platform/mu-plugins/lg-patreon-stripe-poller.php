<?php
/**
 * Plugin Name: LG Patreon + Stripe Poller (must-use loader)
 * Description: Must-use loader for the folder-structured poller plugin. WordPress
 *   only auto-loads PHP files in the mu-plugins/ ROOT (not subdirectories), so
 *   this thin loader require_once's the real main file from the subfolder
 *   lg-patreon-stripe-poller/lg-patreon-onboard.php. The folder (src/, includes/,
 *   assets/, vendor/) is deployed alongside this loader.
 *
 *   PRODUCTION must-use plugin — MUST ship to live. It is deliberately NOT tagged
 *   @lg-dev-only (that marker excludes the 5 dev-only mu-plugins from the deploy).
 *
 *   No activation/deactivation: mu-plugins cannot be toggled and never fire
 *   register_activation_hook / register_deactivation_hook. The plugin self-installs
 *   idempotently via LGMS\Plugin::maybeInstall() (init, version-gated on the
 *   lgpo_schema_version option). The off-switches are the runtime option gates
 *   (lgms_poller_mail_enabled, lgms_stripe_frozen, lgpo_auto_sync_enabled), NOT
 *   plugin (de)activation.
 * Author: Looth Group
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$lgpo_dir  = __DIR__ . '/lg-patreon-stripe-poller';
$lgpo_main = $lgpo_dir . '/lg-patreon-onboard.php';

if ( ! is_readable( $lgpo_main ) ) {
    // Folder missing / misdeployed — surface loudly, never fatal the whole site.
    error_log( 'LG Patreon+Stripe Poller mu-loader: main file not found at ' . $lgpo_main );
    return;
}

// Pin the plugin's path/URL constants to the mu-plugins subfolder BEFORE the main
// file loads. The main file's own define()s are guarded with !defined(), so these
// win. plugin_dir_path/url resolve correctly under mu-plugins too — pinning here
// just removes any ambiguity about where the folder lives.
if ( ! defined( 'LGPO_PLUGIN_FILE' ) ) {
    define( 'LGPO_PLUGIN_FILE', $lgpo_main );
}
if ( ! defined( 'LGPO_PLUGIN_DIR' ) ) {
    define( 'LGPO_PLUGIN_DIR', trailingslashit( $lgpo_dir ) );
}
if ( ! defined( 'LGPO_PLUGIN_URL' ) ) {
    // plugins_url() detects WPMU_PLUGIN_DIR and returns the mu-plugins URL.
    define( 'LGPO_PLUGIN_URL', plugins_url( '/', $lgpo_main ) );
}

require_once $lgpo_main;
