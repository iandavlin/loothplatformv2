<?php
/**
 * Plugin Name: LG Apps
 * Description: Interactive tools for luthiers — shop planner, and more. Each app lives in its own folder under apps/ and is auto-discovered. Includes admin dashboard with ad management.
 * Version: 1.1.0
 * Author: Looth Group
 * Text Domain: lg-apps
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LGAPPS_VERSION', '1.1.0' );
define( 'LGAPPS_URL', plugin_dir_url( __FILE__ ) );
define( 'LGAPPS_PATH', plugin_dir_path( __FILE__ ) );

/* ───────────────────────────────────────────
   1. Load shared classes
   ─────────────────────────────────────────── */

require_once LGAPPS_PATH . 'includes/class-lgapps-registry.php';
require_once LGAPPS_PATH . 'includes/class-lgapps-widget.php';
require_once LGAPPS_PATH . 'includes/class-lgapps-admin.php';
require_once LGAPPS_PATH . 'includes/class-lgapps-ads.php';

/* ───────────────────────────────────────────
   2. Initialize admin + ads
   ─────────────────────────────────────────── */

if ( is_admin() ) {
    LGApps_Admin::init();
}
LGApps_Ads::init();

/* ───────────────────────────────────────────
   3. Register shared base styles
   ─────────────────────────────────────────── */

add_action( 'wp_enqueue_scripts', function() {
    wp_register_style(
        'lgapps-base',
        LGAPPS_URL . 'assets/css/lgapps-base.css',
        [],
        LGAPPS_VERSION
    );
});

/* ───────────────────────────────────────────
   4. Auto-discover apps
   ─────────────────────────────────────────── */

add_action( 'plugins_loaded', function() {
    $apps_dir = LGAPPS_PATH . 'apps/';
    if ( ! is_dir( $apps_dir ) ) return;

    foreach ( scandir( $apps_dir ) as $folder ) {
        if ( $folder === '.' || $folder === '..' ) continue;
        $app_file = $apps_dir . $folder . '/app.php';
        if ( file_exists( $app_file ) ) {
            require_once $app_file;
        }
    }
});

/* ───────────────────────────────────────────
   5. Register widgets for all active apps
   ─────────────────────────────────────────── */

add_action( 'widgets_init', function() {
    foreach ( LGApps_Registry::get_all() as $slug => $app ) {
        // Skip disabled apps
        if ( ! LGApps_Admin::is_app_active( $slug ) ) continue;

        $class_name = 'LGApps_Widget_' . str_replace( '-', '_', ucwords( $slug, '-' ) );
        if ( ! class_exists( $class_name ) ) {
            LGApps_Widget::register_for_app( $slug, $app );
        }
    }
});

/* ───────────────────────────────────────────
   6. Render all queued app modals in footer
   ─────────────────────────────────────────── */

add_action( 'wp_footer', function() {
    $queued = LGApps_Registry::get_queued();
    if ( empty( $queued ) ) return;

    foreach ( $queued as $slug ) {
        // Skip disabled apps
        if ( ! LGApps_Admin::is_app_active( $slug ) ) continue;

        $app = LGApps_Registry::get( $slug );
        if ( $app && is_callable( $app['render_modal'] ) ) {
            call_user_func( $app['render_modal'] );
        }
    }

    // Pass gating config + auth state to JS
    $settings = LGApps_Admin::get_settings();
    $gating = [
        'logged_in'       => is_user_logged_in(),
        'gated_features'  => $settings['gated_features'],
        'login_url'       => wp_login_url( get_permalink() ),
        'register_url'    => wp_registration_url(),
    ];
    echo '<script>window.lgapps_gating = ' . wp_json_encode( $gating ) . ';</script>' . "\n";
}, 50 );

/* ───────────────────────────────────────────
   7. Generic shortcode: [lg_app app="shop-planner"]
      Plus each app gets its own: [shop_planner]
   ─────────────────────────────────────────── */

add_shortcode( 'lg_app', function( $atts ) {
    $atts = shortcode_atts( [ 'app' => '', 'text' => '' ], $atts, 'lg_app' );
    $slug = sanitize_key( $atts['app'] );
    $app  = LGApps_Registry::get( $slug );
    if ( ! $app ) return '<!-- lg_app: unknown app "' . esc_html( $slug ) . '" -->';

    // Skip disabled apps
    if ( ! LGApps_Admin::is_app_active( $slug ) ) return '';

    LGApps_Registry::enqueue( $slug );

    $btn_text = ! empty( $atts['text'] ) ? $atts['text'] : $app['title'];
    return '<button class="lgapps-open-btn" onclick="window.lgapps_open(\'' . esc_attr( $slug ) . '\')">'
         . esc_html( $btn_text ) . '</button>';
});

/* ───────────────────────────────────────────
   8. Visibility shortcodes:
      [lgapps_logged_out]...[/lgapps_logged_out]
      [lgapps_logged_in]...[/lgapps_logged_in]
   ─────────────────────────────────────────── */

add_shortcode( 'lgapps_logged_out', function( $atts, $content = '' ) {
    if ( is_user_logged_in() ) return '';
    return do_shortcode( $content );
});

add_shortcode( 'lgapps_logged_in', function( $atts, $content = '' ) {
    if ( ! is_user_logged_in() ) return '';
    return do_shortcode( $content );
});
