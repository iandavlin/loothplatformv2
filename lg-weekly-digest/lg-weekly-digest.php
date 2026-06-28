<?php
/**
 * Plugin Name: LG Weekly Digest
 * Plugin URI:  https://loothgroup.com
 * Description: Curated weekly digest email with pluggable sender support. Compose issues from any registered CPT, preview inline, and send via FluentCRM or wp_mail.
 * Version:     3.0.0
 * Author:      The Looth Group
 * License:     GPL-2.0+
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────
define( 'LG_WD_VERSION',    '3.0.0' );
define( 'LG_WD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LG_WD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LG_WD_OPTION_KEY', 'lg_wd_settings' );
define( 'LG_WD_TIMEZONE',   'America/New_York' );

// ─────────────────────────────────────────────
// Includes
// ─────────────────────────────────────────────
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-settings.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-cpt-registry.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-issue.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-query.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-email-builder.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-sender.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-admin.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-compose.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-cron.php';
require_once LG_WD_PLUGIN_DIR . 'includes/class-lg-wd-frontend.php';

// ─────────────────────────────────────────────
// Boot
// ─────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    LG_WD_Issue::init();
    LG_WD_Admin::init();
    LG_WD_Compose::init();
    LG_WD_Cron::init();
    LG_WD_Frontend::init();
} );

// ─────────────────────────────────────────────
// Weekly Email Append CPT
// A simple WYSIWYG post type for injecting custom
// content into any issue (announcements, notes, etc.)
// ─────────────────────────────────────────────
add_action( 'init', function () {
    register_post_type( 'email_append', [
        'labels' => [
            'name'          => 'Email Appends',
            'singular_name' => 'Email Append',
            'add_new_item'  => 'Add New Email Append',
            'edit_item'     => 'Edit Email Append',
            'all_items'     => 'All Email Appends',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => 'lg-weekly-digest',
        'supports'     => [ 'title', 'editor' ],
        'has_archive'  => false,
        'rewrite'      => false,
        'capability_type' => 'post',
    ] );
} );

// Activation / Deactivation
register_activation_hook( __FILE__, function () {
    LG_WD_Issue::register_cpt();
    flush_rewrite_rules();
    LG_WD_Cron::activate();
} );
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
    LG_WD_Cron::deactivate();
} );
