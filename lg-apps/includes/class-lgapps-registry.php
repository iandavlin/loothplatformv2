<?php
/**
 * LGApps_Registry
 *
 * Central registry for all LG Apps. Each app registers itself here with:
 *   - slug        (string)  Unique identifier, e.g. 'shop-planner'
 *   - title       (string)  Human-readable name
 *   - description (string)  One-liner for widget admin
 *   - scripts     (array)   Handles to wp_register_script'd scripts
 *   - styles      (array)   Handles to wp_register_style'd styles
 *   - render_modal(callable) Function that echoes the modal HTML
 *   - shortcode   (string)  Optional custom shortcode name, e.g. 'shop_planner'
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LGApps_Registry {

    /** @var array slug => app config */
    private static $apps = [];

    /** @var array slugs that have been enqueued this page load */
    private static $queued = [];

    /**
     * Register an app.
     */
    public static function register( $slug, $config ) {
        $slug = sanitize_key( $slug );
        $config = wp_parse_args( $config, [
            'slug'         => $slug,
            'title'        => ucwords( str_replace( '-', ' ', $slug ) ),
            'description'  => '',
            'scripts'      => [],
            'styles'       => [],
            'render_modal' => null,
            'shortcode'    => '',
        ] );

        self::$apps[ $slug ] = $config;

        // Register a convenience shortcode if provided, e.g. [shop_planner]
        if ( ! empty( $config['shortcode'] ) ) {
            add_shortcode( $config['shortcode'], function( $atts ) use ( $slug, $config ) {
                // Respect active/inactive toggle
                if ( class_exists( 'LGApps_Admin' ) && ! LGApps_Admin::is_app_active( $slug ) ) return '';

                $atts = shortcode_atts( [ 'text' => $config['title'] ], $atts, $config['shortcode'] );
                self::enqueue( $slug );
                return '<button class="lgapps-open-btn" onclick="window.lgapps_open(\'' . esc_attr( $slug ) . '\')">'
                     . esc_html( $atts['text'] ) . '</button>';
            });
        }
    }

    /**
     * Enqueue an app's assets and mark it for modal rendering.
     */
    public static function enqueue( $slug ) {
        $app = self::get( $slug );
        if ( ! $app ) return;

        // Base styles (shared modal framework)
        wp_enqueue_style( 'lgapps-base' );

        // App-specific assets
        foreach ( $app['styles'] as $handle ) {
            wp_enqueue_style( $handle );
        }
        foreach ( $app['scripts'] as $handle ) {
            wp_enqueue_script( $handle );
        }

        // Mark for footer rendering (only once)
        if ( ! in_array( $slug, self::$queued, true ) ) {
            self::$queued[] = $slug;
        }
    }

    public static function get( $slug ) {
        return isset( self::$apps[ $slug ] ) ? self::$apps[ $slug ] : null;
    }

    public static function get_all() {
        return self::$apps;
    }

    public static function get_queued() {
        return self::$queued;
    }
}
