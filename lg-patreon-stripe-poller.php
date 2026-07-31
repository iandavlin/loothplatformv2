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

// ── WHERE THE CODE FOLDER IS, AND WHY THIS IS NOT JUST __DIR__ ──────────────────
// ⚠️ PHP RESOLVES SYMLINKS BEFORE COMPUTING __DIR__. This loader is deployed into
// wp-content/mu-plugins/, and the deploy tooling symlinks mu-plugins into the
// monorepo. The moment THIS FILE is a symlink, __DIR__ stops being the docroot and
// becomes the repo checkout — so the loader starts looking for its code in a
// different tree than the one WordPress deployed.
//
// That happened on live on 2026-07-31 and took the whole site down. The folder was
// not found, this loader `return`ed (see below — it deliberately does not fatal),
// the plugin was NEVER REGISTERED, its REST route did not exist, whoami could not
// read member tiers, and every member and admin computed as `public`. The site
// paywalled itself. Nothing fatalled and nothing 500'd, which is why it took so
// long to find. See docs/runbooks/live-divergences.md §4.5 and CLAUDE.md trap #7.
//
// WPMU_PLUGIN_DIR is WordPress's own constant for the mu-plugins directory, so it
// is correct whether or not this file is a symlink, and it always names the tree
// the plugin was actually deployed into — the only one guaranteed to carry
// box-local files (vendor/, .env) that the repo copy does not have.
//
// The __DIR__ fallback keeps the previous behaviour for any context where
// WPMU_PLUGIN_DIR is not defined or the folder genuinely is beside this file (a CLI
// harness, a test tree). This can therefore only ever find MORE than before, never
// less. Covered by tools/deploy/test-poller-loader.sh, whose scenario B is the
// outage above.
$lgpo_dir = __DIR__ . '/lg-patreon-stripe-poller';
if ( defined( 'WPMU_PLUGIN_DIR' )
     && is_readable( WPMU_PLUGIN_DIR . '/lg-patreon-stripe-poller/lg-patreon-onboard.php' ) ) {
    $lgpo_dir = WPMU_PLUGIN_DIR . '/lg-patreon-stripe-poller';
}
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
