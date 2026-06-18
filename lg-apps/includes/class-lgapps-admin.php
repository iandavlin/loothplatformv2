<?php
/**
 * LGApps_Admin
 *
 * Admin dashboard for managing LG Apps:
 *   - App overview with active/inactive toggles
 *   - Per-app ad configuration (HTML, placement, visibility)
 *   - Global settings (kill switch, custom CSS)
 *
 * All settings stored in a single option: lgapps_settings
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LGApps_Admin {

    const OPTION_KEY = 'lgapps_settings';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
    }

    /* ─── Settings helpers ─── */

    public static function get_settings() {
        return wp_parse_args( get_option( self::OPTION_KEY, [] ), [
            'disabled_apps'    => [],     // array of slugs
            'ads'              => [],     // slug => { enabled, html, placement, visibility }
            'global_ads_off'   => false,
            'custom_css'       => '',
            'gated_features'   => [],     // array of feature keys requiring login
        ] );
    }

    public static function save_settings( $settings ) {
        update_option( self::OPTION_KEY, $settings );
    }

    public static function is_app_active( $slug ) {
        $settings = self::get_settings();
        return ! in_array( $slug, $settings['disabled_apps'], true );
    }

    /**
     * Check if a feature requires login.
     * Available feature keys: json_download, json_upload, pdf_export, autosave
     */
    public static function is_feature_gated( $feature ) {
        $settings = self::get_settings();
        return in_array( $feature, $settings['gated_features'], true );
    }

    public static function get_ad_config( $slug ) {
        $settings = self::get_settings();
        $defaults = [
            'enabled'    => false,
            'ads'        => [],           // array of { html } — the gallery items
            'html'       => '',           // legacy single-ad field (migrated to ads[])
            'placement'  => 'sidebar',    // sidebar | banner | hint
            'visibility' => 'logged_out', // logged_out | all | roles
            'roles'      => [],
            'rotate_sec' => 8,            // rotation interval in seconds (0 = no rotation)
        ];
        $config = wp_parse_args(
            isset( $settings['ads'][ $slug ] ) ? $settings['ads'][ $slug ] : [],
            $defaults
        );

        // Migrate legacy single html field into ads array
        if ( empty( $config['ads'] ) && ! empty( trim( $config['html'] ) ) ) {
            $config['ads'] = [ [ 'html' => $config['html'] ] ];
        }

        return $config;
    }

    /* ─── Menu ─── */

    public static function add_menu() {
        add_menu_page(
            'LG Apps',
            'LG Apps',
            'manage_options',
            'lgapps',
            [ __CLASS__, 'render_page' ],
            'dashicons-screenoptions',
            80
        );
    }

    /* ─── Admin CSS ─── */

    public static function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'lgapps' ) === false ) return;

        wp_enqueue_style(
            'lgapps-admin',
            LGAPPS_URL . 'assets/css/lgapps-admin.css',
            [],
            LGAPPS_VERSION
        );
    }

    /* ─── Save handler ─── */

    public static function handle_save() {
        if ( ! isset( $_POST['lgapps_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['lgapps_nonce'], 'lgapps_save_settings' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $settings = self::get_settings();
        $tab = isset( $_POST['lgapps_tab'] ) ? sanitize_key( $_POST['lgapps_tab'] ) : 'apps';

        if ( $tab === 'apps' ) {
            // Active/inactive toggles
            $all_slugs = array_keys( LGApps_Registry::get_all() );
            $active    = isset( $_POST['lgapps_active'] ) && is_array( $_POST['lgapps_active'] )
                         ? array_map( 'sanitize_key', $_POST['lgapps_active'] )
                         : [];
            $settings['disabled_apps'] = array_values( array_diff( $all_slugs, $active ) );
        }

        if ( $tab === 'ads' ) {
            $app_slug = isset( $_POST['lgapps_ad_app'] ) ? sanitize_key( $_POST['lgapps_ad_app'] ) : '';
            if ( $app_slug && LGApps_Registry::get( $app_slug ) ) {
                // Collect gallery ads
                $raw_ads = isset( $_POST['lgapps_ad_items'] ) && is_array( $_POST['lgapps_ad_items'] )
                           ? $_POST['lgapps_ad_items'] : [];
                $gallery = [];
                foreach ( $raw_ads as $item ) {
                    $html = isset( $item['html'] ) ? wp_kses_post( wp_unslash( $item['html'] ) ) : '';
                    if ( ! empty( trim( $html ) ) ) {
                        $gallery[] = [ 'html' => $html ];
                    }
                }

                $ad = [
                    'enabled'    => ! empty( $_POST['lgapps_ad_enabled'] ),
                    'ads'        => $gallery,
                    'html'       => '', // deprecated, kept for compat
                    'placement'  => sanitize_key( $_POST['lgapps_ad_placement'] ?? 'sidebar' ),
                    'visibility' => sanitize_key( $_POST['lgapps_ad_visibility'] ?? 'logged_out' ),
                    'roles'      => isset( $_POST['lgapps_ad_roles'] ) && is_array( $_POST['lgapps_ad_roles'] )
                                    ? array_map( 'sanitize_key', $_POST['lgapps_ad_roles'] )
                                    : [],
                    'rotate_sec' => max( 0, min( 120, intval( $_POST['lgapps_ad_rotate'] ?? 8 ) ) ),
                ];
                $settings['ads'][ $app_slug ] = $ad;
            }
        }

        if ( $tab === 'global' ) {
            $settings['global_ads_off'] = ! empty( $_POST['lgapps_global_ads_off'] );
            $settings['custom_css']     = sanitize_textarea_field( wp_unslash( $_POST['lgapps_custom_css'] ?? '' ) );

            // Feature gating
            $allowed_gates = [ 'json_download', 'json_upload', 'pdf_export', 'autosave' ];
            $submitted     = isset( $_POST['lgapps_gated'] ) && is_array( $_POST['lgapps_gated'] )
                             ? array_map( 'sanitize_key', $_POST['lgapps_gated'] )
                             : [];
            $settings['gated_features'] = array_values( array_intersect( $submitted, $allowed_gates ) );
        }

        self::save_settings( $settings );

        // Redirect to avoid resubmission
        $redirect = add_query_arg( [
            'page'    => 'lgapps',
            'tab'     => $tab,
            'app'     => isset( $_POST['lgapps_ad_app'] ) ? sanitize_key( $_POST['lgapps_ad_app'] ) : '',
            'updated' => '1',
        ], admin_url( 'admin.php' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    /* ─── Render ─── */

    public static function render_page() {
        $tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'apps';
        $apps     = LGApps_Registry::get_all();
        $settings = self::get_settings();
        $updated  = ! empty( $_GET['updated'] );
        ?>
        <div class="wrap lgapps-admin">
            <h1>LG Apps</h1>

            <?php if ( $updated ) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgapps&tab=apps' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'apps' ? 'nav-tab-active' : ''; ?>">App Manager</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgapps&tab=ads' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'ads' ? 'nav-tab-active' : ''; ?>">Ad Manager</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgapps&tab=global' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'global' ? 'nav-tab-active' : ''; ?>">Global Settings</a>
            </nav>

            <div class="lgapps-tab-content">
                <?php
                switch ( $tab ) {
                    case 'ads':    self::render_tab_ads( $apps, $settings );    break;
                    case 'global': self::render_tab_global( $settings );        break;
                    default:       self::render_tab_apps( $apps, $settings );   break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* ─── Tab: App Manager ─── */

    private static function render_tab_apps( $apps, $settings ) {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'lgapps_save_settings', 'lgapps_nonce' ); ?>
            <input type="hidden" name="lgapps_tab" value="apps">

            <table class="widefat lgapps-app-table">
                <thead>
                    <tr>
                        <th>Active</th>
                        <th>App</th>
                        <th>Description</th>
                        <th>Shortcode</th>
                        <th>Ad</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $apps ) ) : ?>
                        <tr><td colspan="6">No apps registered. Add an app folder to <code>lg-apps/apps/</code>.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $apps as $slug => $app ) :
                            $is_active = ! in_array( $slug, $settings['disabled_apps'], true );
                            $ad_config = self::get_ad_config( $slug );
                            $shortcode_display = ! empty( $app['shortcode'] )
                                ? '<code>[' . esc_html( $app['shortcode'] ) . ']</code>'
                                : '<code>[lg_app app="' . esc_html( $slug ) . '"]</code>';
                        ?>
                            <tr class="<?php echo $is_active ? '' : 'lgapps-inactive-row'; ?>">
                                <td>
                                    <label class="lgapps-toggle">
                                        <input type="checkbox" name="lgapps_active[]"
                                               value="<?php echo esc_attr( $slug ); ?>"
                                               <?php checked( $is_active ); ?>>
                                        <span class="lgapps-toggle-slider"></span>
                                    </label>
                                </td>
                                <td><strong><?php echo esc_html( $app['title'] ); ?></strong></td>
                                <td><?php echo esc_html( $app['description'] ); ?></td>
                                <td><?php echo $shortcode_display; ?></td>
                                <td>
                                    <?php if ( $ad_config['enabled'] ) : ?>
                                        <span class="lgapps-badge lgapps-badge-green">On</span>
                                    <?php else : ?>
                                        <span class="lgapps-badge lgapps-badge-gray">Off</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgapps&tab=ads&app=' . $slug ) ); ?>"
                                       class="button button-small">Configure Ad</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Save Changes</button>
            </p>
        </form>
        <?php
    }

    /* ─── Tab: Ad Manager ─── */

    private static function render_tab_ads( $apps, $settings ) {
        $current_app = isset( $_GET['app'] ) ? sanitize_key( $_GET['app'] ) : '';

        // If no app selected, show picker
        if ( empty( $current_app ) || ! isset( $apps[ $current_app ] ) ) {
            ?>
            <h2>Select an App to Configure Ads</h2>
            <div class="lgapps-app-cards">
                <?php foreach ( $apps as $slug => $app ) :
                    $ad = self::get_ad_config( $slug );
                ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgapps&tab=ads&app=' . $slug ) ); ?>"
                       class="lgapps-app-card">
                        <strong><?php echo esc_html( $app['title'] ); ?></strong>
                        <span class="lgapps-badge <?php echo $ad['enabled'] ? 'lgapps-badge-green' : 'lgapps-badge-gray'; ?>">
                            Ad: <?php echo $ad['enabled'] ? 'On' : 'Off'; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php
            return;
        }

        $app = $apps[ $current_app ];
        $ad  = self::get_ad_config( $current_app );
        ?>
        <h2>Ad Settings: <?php echo esc_html( $app['title'] ); ?></h2>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lgapps&tab=ads' ) ); ?>">&larr; Back to all apps</a></p>

        <?php if ( $settings['global_ads_off'] ) : ?>
            <div class="notice notice-warning"><p><strong>Note:</strong> Global ad kill switch is ON. No ads will display until you turn it off in Global Settings.</p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'lgapps_save_settings', 'lgapps_nonce' ); ?>
            <input type="hidden" name="lgapps_tab" value="ads">
            <input type="hidden" name="lgapps_ad_app" value="<?php echo esc_attr( $current_app ); ?>">

            <table class="form-table">
                <tr>
                    <th>Enable Ad</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lgapps_ad_enabled" value="1" <?php checked( $ad['enabled'] ); ?>>
                            Show an ad in this app
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Placement</th>
                    <td>
                        <select name="lgapps_ad_placement">
                            <option value="sidebar" <?php selected( $ad['placement'], 'sidebar' ); ?>>Sidebar (below editor panel)</option>
                            <option value="banner" <?php selected( $ad['placement'], 'banner' ); ?>>Banner (above canvas)</option>
                            <option value="hint" <?php selected( $ad['placement'], 'hint' ); ?>>Hint bar (bottom strip)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Visibility</th>
                    <td>
                        <select name="lgapps_ad_visibility" id="lgapps-vis-select">
                            <option value="logged_out" <?php selected( $ad['visibility'], 'logged_out' ); ?>>Logged-out users only</option>
                            <option value="all" <?php selected( $ad['visibility'], 'all' ); ?>>Everyone</option>
                            <option value="roles" <?php selected( $ad['visibility'], 'roles' ); ?>>Specific roles only</option>
                        </select>

                        <div id="lgapps-roles-section" style="margin-top:8px;<?php echo $ad['visibility'] !== 'roles' ? 'display:none;' : ''; ?>">
                            <p><strong>Show ad to these roles:</strong></p>
                            <?php
                            $wp_roles = wp_roles()->get_names();
                            foreach ( $wp_roles as $role_key => $role_name ) : ?>
                                <label style="display:block;margin:2px 0;">
                                    <input type="checkbox" name="lgapps_ad_roles[]"
                                           value="<?php echo esc_attr( $role_key ); ?>"
                                           <?php checked( in_array( $role_key, $ad['roles'], true ) ); ?>>
                                    <?php echo esc_html( $role_name ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <script>
                        document.getElementById('lgapps-vis-select').addEventListener('change', function() {
                            document.getElementById('lgapps-roles-section').style.display =
                                this.value === 'roles' ? 'block' : 'none';
                        });
                        </script>
                    </td>
                </tr>
                <tr>
                    <th>Rotation Speed</th>
                    <td>
                        <input type="number" name="lgapps_ad_rotate" value="<?php echo esc_attr( $ad['rotate_sec'] ); ?>" min="0" max="120" step="1" style="width:80px;">
                        <span>seconds between ads (0 = no rotation, show first ad only)</span>
                    </td>
                </tr>
                <tr>
                    <th>Ad Gallery</th>
                    <td>
                        <p class="description" style="margin-bottom:12px;">Add one or more ads. They'll rotate on a timer inside the app. Drag to reorder.</p>

                        <div id="lgapps-ad-gallery">
                            <?php
                            $ad_items = ! empty( $ad['ads'] ) ? $ad['ads'] : [ [ 'html' => '' ] ];
                            foreach ( $ad_items as $i => $item ) : ?>
                                <div class="lgapps-ad-gallery-item" style="border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:12px;background:#fff;position:relative;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                        <strong style="color:#1A1E12;">Ad #<?php echo $i + 1; ?></strong>
                                        <button type="button" class="button button-small lgapps-remove-ad" style="color:#c00;" title="Remove this ad">&times; Remove</button>
                                    </div>
                                    <textarea name="lgapps_ad_items[<?php echo $i; ?>][html]" rows="6" class="large-text code"
                                              placeholder="Paste ad HTML here..."><?php echo esc_textarea( $item['html'] ?? '' ); ?></textarea>
                                    <?php if ( ! empty( trim( $item['html'] ?? '' ) ) ) : ?>
                                        <div class="lgapps-ad-preview-box" style="margin-top:8px;">
                                            <?php echo wp_kses_post( $item['html'] ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" id="lgapps-add-ad" class="button button-secondary">+ Add Another Ad</button>

                        <script>
                        (function() {
                            var gallery = document.getElementById('lgapps-ad-gallery');
                            var addBtn = document.getElementById('lgapps-add-ad');
                            var count = gallery.querySelectorAll('.lgapps-ad-gallery-item').length;

                            addBtn.addEventListener('click', function() {
                                var div = document.createElement('div');
                                div.className = 'lgapps-ad-gallery-item';
                                div.style.cssText = 'border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:12px;background:#fff;position:relative;';
                                div.innerHTML =
                                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">' +
                                        '<strong style="color:#1A1E12;">Ad #' + (count + 1) + '</strong>' +
                                        '<button type="button" class="button button-small lgapps-remove-ad" style="color:#c00;" title="Remove this ad">&times; Remove</button>' +
                                    '</div>' +
                                    '<textarea name="lgapps_ad_items[' + count + '][html]" rows="6" class="large-text code" placeholder="Paste ad HTML here..."></textarea>';
                                gallery.appendChild(div);
                                count++;
                                bindRemoveButtons();
                            });

                            function bindRemoveButtons() {
                                gallery.querySelectorAll('.lgapps-remove-ad').forEach(function(btn) {
                                    btn.onclick = function() {
                                        if (gallery.querySelectorAll('.lgapps-ad-gallery-item').length <= 1) {
                                            alert('You need at least one ad slot.');
                                            return;
                                        }
                                        btn.closest('.lgapps-ad-gallery-item').remove();
                                        // Re-number
                                        gallery.querySelectorAll('.lgapps-ad-gallery-item').forEach(function(item, idx) {
                                            item.querySelector('strong').textContent = 'Ad #' + (idx + 1);
                                            item.querySelector('textarea').name = 'lgapps_ad_items[' + idx + '][html]';
                                        });
                                        count = gallery.querySelectorAll('.lgapps-ad-gallery-item').length;
                                    };
                                });
                            }
                            bindRemoveButtons();
                        })();
                        </script>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Save Ad Settings</button>
            </p>
        </form>
        <?php
    }

    /* ─── Tab: Global Settings ─── */

    private static function render_tab_global( $settings ) {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'lgapps_save_settings', 'lgapps_nonce' ); ?>
            <input type="hidden" name="lgapps_tab" value="global">

            <table class="form-table">
                <tr>
                    <th>Global Ad Kill Switch</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lgapps_global_ads_off" value="1"
                                   <?php checked( $settings['global_ads_off'] ); ?>>
                            Disable ALL ads across ALL apps
                        </label>
                        <p class="description">Useful during launches, events, or maintenance. Individual app ad settings are preserved.</p>
                    </td>
                </tr>
                <tr>
                    <th>Feature Gating</th>
                    <td>
                        <p><strong>Require login for these features:</strong></p>
                        <p class="description" style="margin-bottom:8px;">Checked features will prompt logged-out visitors to sign up. The planner itself stays public and usable — only save/export actions are gated.</p>
                        <?php
                        $gates = $settings['gated_features'];
                        $features = [
                            'json_download' => 'Download JSON (save layout to file)',
                            'json_upload'   => 'Upload JSON (load layout from file)',
                            'pdf_export'    => 'Save PDF (export shop layout as PDF)',
                            'autosave'      => 'Auto-save (persist layout between visits)',
                        ];
                        foreach ( $features as $key => $label ) : ?>
                            <label style="display:block;margin:4px 0;">
                                <input type="checkbox" name="lgapps_gated[]"
                                       value="<?php echo esc_attr( $key ); ?>"
                                       <?php checked( in_array( $key, $gates, true ) ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th>Custom CSS</th>
                    <td>
                        <textarea name="lgapps_custom_css" rows="10" class="large-text code"
                                  placeholder="/* Custom styles for LG Apps modals */"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
                        <p class="description">Injected into the frontend inside a <code>&lt;style&gt;</code> tag when any app modal loads. Use <code>.lgapps-</code> prefixed selectors.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Save Global Settings</button>
            </p>
        </form>
        <?php
    }
}
