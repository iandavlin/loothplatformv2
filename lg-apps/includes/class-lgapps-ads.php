<?php
/**
 * LGApps_Ads
 *
 * Renders ad content inside app modals based on admin configuration.
 * Hooks into wp_footer alongside the modal rendering to inject ads
 * into the correct placement slot.
 *
 * Visibility rules:
 *   - logged_out : only non-authenticated visitors
 *   - all        : everyone
 *   - roles      : specific WordPress roles only
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LGApps_Ads {

    public static function init() {
        // Inject ad markup after each modal renders
        add_action( 'wp_footer', [ __CLASS__, 'render_ads' ], 51 );

        // Inject custom CSS if any
        add_action( 'wp_footer', [ __CLASS__, 'render_custom_css' ], 52 );
    }

    /**
     * Should this ad be shown to the current visitor?
     */
    private static function should_show( $ad_config ) {
        // Global kill switch
        $settings = LGApps_Admin::get_settings();
        if ( ! empty( $settings['global_ads_off'] ) ) return false;

        // Ad not enabled
        if ( empty( $ad_config['enabled'] ) ) return false;

        // No HTML content — check gallery
        $has_content = false;
        if ( ! empty( $ad_config['ads'] ) && is_array( $ad_config['ads'] ) ) {
            foreach ( $ad_config['ads'] as $item ) {
                if ( ! empty( trim( $item['html'] ?? '' ) ) ) { $has_content = true; break; }
            }
        }
        // Legacy fallback
        if ( ! $has_content && ! empty( trim( $ad_config['html'] ?? '' ) ) ) {
            $has_content = true;
        }
        if ( ! $has_content ) return false;

        $vis = $ad_config['visibility'];

        if ( $vis === 'logged_out' ) {
            return ! is_user_logged_in();
        }

        if ( $vis === 'all' ) {
            return true;
        }

        if ( $vis === 'roles' ) {
            if ( ! is_user_logged_in() ) return false;
            $user  = wp_get_current_user();
            $roles = ! empty( $ad_config['roles'] ) ? $ad_config['roles'] : [];
            return ! empty( array_intersect( $user->roles, $roles ) );
        }

        return false;
    }

    /**
     * Render ad slots for all queued apps via inline JS injection.
     *
     * Each app's modal has predictable DOM structure. We inject the ad
     * HTML into the right container using a small inline script that
     * runs after the modal markup is in the DOM.
     */
    public static function render_ads() {
        $queued = LGApps_Registry::get_queued();
        if ( empty( $queued ) ) return;

        foreach ( $queued as $slug ) {
            $ad = LGApps_Admin::get_ad_config( $slug );
            if ( ! self::should_show( $ad ) ) continue;

            $gallery = ! empty( $ad['ads'] ) ? $ad['ads'] : [];
            if ( empty( $gallery ) ) continue;

            $modal_id    = 'lgapps-modal-' . esc_attr( $slug );
            $placement   = $ad['placement'];
            $rotate_sec  = intval( $ad['rotate_sec'] );

            // Build array of sanitized HTML strings
            $ad_htmls = [];
            foreach ( $gallery as $item ) {
                $html = wp_kses_post( $item['html'] ?? '' );
                if ( ! empty( trim( $html ) ) ) {
                    $ad_htmls[] = $html;
                }
            }
            if ( empty( $ad_htmls ) ) continue;

            ?>
            <script>
            (function() {
                var modal = document.getElementById('<?php echo esc_js( $modal_id ); ?>');
                if (!modal) return;

                var ads = <?php echo wp_json_encode( $ad_htmls ); ?>;
                var placement = '<?php echo esc_js( $placement ); ?>';
                var rotateSec = <?php echo intval( $rotate_sec ); ?>;
                var current = 0;

                // Create the container
                var container = document.createElement('div');
                container.className = 'lgapps-ad-slot lgapps-ad-' + placement;
                container.setAttribute('data-app', '<?php echo esc_js( $slug ); ?>');
                container.innerHTML = ads[0];
                container.style.transition = 'opacity 0.4s ease';

                // Place it
                if (placement === 'sidebar') {
                    var sidebar = modal.querySelector('.lgapps-sidebar');
                    if (sidebar) {
                        var footer = sidebar.querySelector('.lgapps-sidebar-footer');
                        if (footer) sidebar.insertBefore(container, footer);
                        else sidebar.appendChild(container);
                    }
                } else if (placement === 'banner') {
                    var controls = modal.querySelector('.lgapps-controls');
                    if (controls) controls.parentNode.insertBefore(container, controls.nextSibling);
                } else if (placement === 'hint') {
                    var hint = modal.querySelector('.lgapps-hint');
                    if (hint) hint.parentNode.insertBefore(container, hint);
                }

                // Rotation
                if (ads.length > 1 && rotateSec > 0) {
                    setInterval(function() {
                        container.style.opacity = '0';
                        setTimeout(function() {
                            current = (current + 1) % ads.length;
                            container.innerHTML = ads[current];
                            container.style.opacity = '1';
                        }, 400);
                    }, rotateSec * 1000);
                }
            })();
            </script>
            <?php
        }
    }

    /**
     * Output custom CSS from global settings.
     */
    public static function render_custom_css() {
        $queued = LGApps_Registry::get_queued();
        if ( empty( $queued ) ) return;

        $settings = LGApps_Admin::get_settings();
        $css = trim( $settings['custom_css'] );
        if ( empty( $css ) ) return;

        echo '<style id="lgapps-custom-css">' . wp_strip_all_tags( $css ) . '</style>' . "\n";
    }
}
