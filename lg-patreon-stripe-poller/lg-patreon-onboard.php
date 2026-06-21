<?php
/**
 * Plugin Name: LG Patreon + Stripe Poller
 * Description: Patreon OAuth onboarding, Patreon API polling, Stripe Events API polling, WP user provisioning, and role arbitration. Companion to the lg-stripe-billing Slim app.
 * Version: 2.0.0
 * Author: Ian Davlin
 * Text Domain: lg-patreon-stripe-poller
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LGPO_VERSION', '2.4.2' );
define( 'LGPO_PLUGIN_FILE', __FILE__ );
define( 'LGPO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGPO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Alias for the LGMS\* code paths (Stripe poller, arbiter, REST endpoints).
// They originated in a separate `lg-member-sync` plugin and were folded in here.
define( 'LGMS_PLUGIN_DIR', LGPO_PLUGIN_DIR );

// Composer autoload for the LGMS\* namespace (Stripe poller + arbiter + provisioner).
if ( file_exists( LGPO_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once LGPO_PLUGIN_DIR . 'vendor/autoload.php';
}

// Patreon side: existing sync engine and cron (LGPO_*).
require_once LGPO_PLUGIN_DIR . 'includes/campaign-filter.php';
require_once LGPO_PLUGIN_DIR . 'includes/class-lgpo-sync-engine.php';
require_once LGPO_PLUGIN_DIR . 'includes/class-lgpo-sync-cron.php';
add_action( 'init', [ 'LGPO_Sync_Cron', 'init' ] );

/**
 * Bridge: write a Patreon role opinion to lg_role_sources and run the arbiter.
 * Used by every OAuth onboarding spot that previously called $user->set_role().
 *
 * @param int    $wp_user_id WP user being assigned a role.
 * @param string $tier       'looth1'..'looth4'. 'looth1' is reported as null
 *                           ("Patreon has no tier opinion") so the arbiter
 *                           can defer to Stripe (or fall back to looth1).
 */
function lgpo_apply_role_via_arbiter( int $wp_user_id, string $tier ): void {
    if ( $wp_user_id <= 0 ) {
        return;
    }
    $patreon_tier = ( $tier === 'looth1' ) ? null : $tier;
    if ( class_exists( '\\LGMS\\RoleSourceWriter' ) && class_exists( '\\LGMS\\Arbiter' ) ) {
        \LGMS\RoleSourceWriter::report( $wp_user_id, 'patreon', $patreon_tier );
        \LGMS\Arbiter::sync( $wp_user_id );
        return;
    }
    // Fallback if LGMS\* isn't loaded.
    $user = get_user_by( 'id', $wp_user_id );
    if ( $user ) {
        $user->set_role( $tier );
    }
}

/**
 * Log the just-onboarded member into WordPress so they land authenticated
 * instead of anonymous (lifecycle G1). A bare wp_set_auth_cookie is NOT enough:
 * the looth_id JWT — what the fast /whoami path reads — is minted on the
 * `wp_login` action (platform/mu-plugins/profile-auth.php). So we fire wp_login
 * explicitly, which mints the JWT and runs every other login integration just
 * as a password login would. Must run before any output (we're on
 * template_redirect, headers not yet sent).
 */
function lgpo_login_user( $user ): void {
    if ( ! $user instanceof WP_User ) {
        $user = get_user_by( 'id', (int) $user );
    }
    if ( ! $user instanceof WP_User ) {
        return;
    }
    if ( headers_sent() ) {
        error_log( 'lgpo_login_user: headers already sent, cannot set auth cookie for #' . $user->ID );
        return;
    }
    wp_set_current_user( $user->ID, $user->user_login );
    wp_set_auth_cookie( $user->ID, true );
    do_action( 'wp_login', $user->user_login, $user );
}

// Stripe side + arbiter: hook the LGMS lifecycle if the namespace is loaded.
if ( class_exists( '\\LGMS\\Plugin' ) ) {
    register_activation_hook( __FILE__, [ '\\LGMS\\Plugin', 'activate' ] );
    register_deactivation_hook( __FILE__, [ '\\LGMS\\Plugin', 'deactivate' ] );
    add_action( 'plugins_loaded', [ '\\LGMS\\Plugin', 'boot' ] );
}

/**
 * ============================================================
 * ADMIN SETTINGS
 * ============================================================
 */

add_action( 'admin_menu', 'lgpo_admin_menu' );
function lgpo_admin_menu() {
    add_options_page(
        'Patreon OAuth',
        'Patreon OAuth',
        'manage_options',
        'lg-patreon-onboard',
        'lgpo_settings_page'
    );
}

add_action( 'admin_init', 'lgpo_register_settings' );
function lgpo_register_settings() {
    register_setting( 'lgpo_settings', 'lgpo_client_id', 'sanitize_text_field' );
    register_setting( 'lgpo_settings', 'lgpo_client_secret', 'sanitize_text_field' );
    register_setting( 'lgpo_settings', 'lgpo_redirect_uri', 'esc_url_raw' );
    register_setting( 'lgpo_settings', 'lgpo_campaign_id', 'sanitize_text_field' );
    register_setting( 'lgpo_settings', 'lgpo_patreon_link', 'esc_url_raw' );
    register_setting( 'lgpo_settings', 'lgpo_contact_email', 'sanitize_email' );
    register_setting( 'lgpo_settings', 'lgpo_creator_access_token', 'sanitize_text_field' );
    // Refresh-token lifecycle for the creator access token (§3n). Captured by
    // the creator-OAuth dance (Settings → "Connect Creator Account" →
    // /patreon-connect?creator=1) and rotated by lgpo_refresh_creator_token()
    // inline on a 401 from the members API. Not editable via UI — Settings
    // shows only their status badge.
    register_setting( 'lgpo_settings', 'lgpo_creator_refresh_token',    'sanitize_text_field' );
    register_setting( 'lgpo_settings', 'lgpo_creator_token_expires_at', 'absint' );
    register_setting( 'lgpo_settings', 'lgpo_creator_token_obtained_at','absint' );
    register_setting( 'lgpo_settings', 'lgpo_auto_sync_enabled', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
    register_setting( 'lgpo_settings', 'lgpo_sync_frequency', [
        'type'              => 'string',
        'sanitize_callback' => function( $val ) {
            return in_array( $val, [ 'hourly', 'twicedaily', 'daily' ], true ) ? $val : 'daily';
        },
        'default'           => 'daily',
    ] );
}

/**
 * Handle tier map saves separately — avoids Settings API nested array headaches.
 */
add_action( 'admin_init', 'lgpo_handle_tier_map_save' );
function lgpo_handle_tier_map_save() {
    if ( ! isset( $_POST['lgpo_tier_map_save'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lgpo_tier_map_nonce', '_lgpo_tier_nonce' ) ) {
        return;
    }

    $tier_ids  = isset( $_POST['lgpo_tm_id'] ) ? (array) $_POST['lgpo_tm_id'] : array();
    $tier_roles = isset( $_POST['lgpo_tm_role'] ) ? (array) $_POST['lgpo_tm_role'] : array();

    $map = array();
    foreach ( $tier_ids as $i => $tid ) {
        $tid  = sanitize_text_field( trim( $tid ) );
        $role = isset( $tier_roles[ $i ] ) ? sanitize_text_field( $tier_roles[ $i ] ) : '';
        if ( ! empty( $tid ) && ! empty( $role ) ) {
            $map[ $tid ] = $role;
        }
    }

    update_option( 'lgpo_tier_map', $map );

    wp_safe_redirect( admin_url( 'options-general.php?page=lg-patreon-onboard&tier_saved=1' ) );
    exit;
}

/**
 * Handle JSON import — accepts raw Patreon API response or a simple {id:role} map.
 */
add_action( 'admin_init', 'lgpo_handle_json_import' );
function lgpo_handle_json_import() {
    if ( ! isset( $_POST['lgpo_json_import'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lgpo_json_import_nonce', '_lgpo_json_nonce' ) ) {
        return;
    }

    $json_raw = isset( $_POST['lgpo_json_paste'] ) ? wp_unslash( $_POST['lgpo_json_paste'] ) : '';

    if ( empty( $json_raw ) && ! empty( $_FILES['lgpo_json_file']['tmp_name'] ) ) {
        $json_raw = file_get_contents( $_FILES['lgpo_json_file']['tmp_name'] );
    }

    if ( empty( $json_raw ) ) {
        wp_safe_redirect( admin_url( 'options-general.php?page=lg-patreon-onboard&json_error=empty' ) );
        exit;
    }

    $data = json_decode( $json_raw, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_safe_redirect( admin_url( 'options-general.php?page=lg-patreon-onboard&json_error=parse' ) );
        exit;
    }

    $tiers = array();

    // Format 1: Raw Patreon API response with "included" array
    if ( isset( $data['included'] ) && is_array( $data['included'] ) ) {
        foreach ( $data['included'] as $resource ) {
            if ( isset( $resource['type'] ) && $resource['type'] === 'tier' ) {
                $tid   = $resource['id'];
                $title = $resource['attributes']['title'] ?? '';
                $cents = $resource['attributes']['amount_cents'] ?? 0;
                $tiers[ $tid ] = $title . ' ($' . number_format( $cents / 100, 2 ) . ')';
            }
        }
    }
    // Format 2: Simple {tier_id: role} map (re-import)
    elseif ( ! isset( $data['data'] ) && ! isset( $data['included'] ) ) {
        foreach ( $data as $tid => $role ) {
            if ( is_string( $role ) ) {
                $tiers[ $tid ] = $role;
            }
        }
    }

    if ( empty( $tiers ) ) {
        wp_safe_redirect( admin_url( 'options-general.php?page=lg-patreon-onboard&json_error=notiers' ) );
        exit;
    }

    // Store labels persistently so they show in the mapping table
    update_option( 'lgpo_tier_labels', $tiers );

    set_transient( 'lgpo_imported_tiers', $tiers, 300 );

    wp_safe_redirect( admin_url( 'options-general.php?page=lg-patreon-onboard&json_imported=1' ) );
    exit;
}

/**
 * ============================================================
 * SETTINGS PAGE RENDER
 * ============================================================
 */

function lgpo_settings_page() {
    $tier_map      = get_option( 'lgpo_tier_map', array() );
    $tier_labels   = get_option( 'lgpo_tier_labels', array() );
    $patreon_link  = get_option( 'lgpo_patreon_link', 'https://www.patreon.com/cw/theloothgroup/membership' );
    $contact_email = get_option( 'lgpo_contact_email', 'ian.davlin@gmail.com' );
    $wp_roles      = wp_roles()->get_names();

    // Merge imported tiers if we just did a JSON import
    $imported_tiers = get_transient( 'lgpo_imported_tiers' );
    if ( $imported_tiers && isset( $_GET['json_imported'] ) ) {
        delete_transient( 'lgpo_imported_tiers' );
        foreach ( $imported_tiers as $tid => $label ) {
            if ( ! isset( $tier_map[ $tid ] ) ) {
                $tier_map[ $tid ] = array_key_exists( $label, $wp_roles ) ? $label : 'subscriber';
            }
        }
    }

    // Notices
    if ( isset( $_GET['tier_saved'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Tier mapping saved.</p></div>';
    }
    if ( isset( $_GET['json_imported'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Tiers imported from JSON. Set the correct roles below and click "Save Tier Mapping".</p></div>';
    }
    if ( isset( $_GET['json_error'] ) ) {
        $errors = array( 'empty' => 'No JSON data provided.', 'parse' => 'Could not parse JSON.', 'notiers' => 'No tiers found in the JSON.' );
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $_GET['json_error'] ] ?? 'Unknown error.' ) . '</p></div>';
    }
    if ( isset( $_GET['resolved'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Pending review resolved.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>LG Patreon OAuth Settings</h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'lgpo_settings' ); ?>
            <table class="form-table">
                <tr><th>Client ID</th><td><input type="text" name="lgpo_client_id" value="<?php echo esc_attr( get_option( 'lgpo_client_id' ) ); ?>" class="regular-text" /></td></tr>
                <tr><th>Client Secret</th><td><input type="password" name="lgpo_client_secret" value="<?php echo esc_attr( get_option( 'lgpo_client_secret' ) ); ?>" class="regular-text" /></td></tr>
                <tr><th>Redirect URI</th><td><input type="url" name="lgpo_redirect_uri" value="<?php echo esc_attr( get_option( 'lgpo_redirect_uri' ) ); ?>" class="regular-text" /><p class="description">Example: <code><?php echo esc_html( home_url( '/patreon-callback/' ) ); ?></code></p></td></tr>
                <tr><th>Campaign ID</th><td><input type="text" name="lgpo_campaign_id" value="<?php echo esc_attr( get_option( 'lgpo_campaign_id' ) ); ?>" class="regular-text" /></td></tr>
                <tr><th>Patreon Membership Link</th><td><input type="url" name="lgpo_patreon_link" value="<?php echo esc_attr( $patreon_link ); ?>" class="regular-text" /></td></tr>
                <tr><th>Contact Email</th><td><input type="email" name="lgpo_contact_email" value="<?php echo esc_attr( $contact_email ); ?>" class="regular-text" /></td></tr>
                <tr><th>Creator Access Token</th><td><input type="password" name="lgpo_creator_access_token" value="<?php echo esc_attr( get_option( 'lgpo_creator_access_token', '' ) ); ?>" class="regular-text" autocomplete="off" /><p class="description">From Patreon Developer Portal. Manual paste — kept as fallback. Prefer the "Connect Creator Account" OAuth dance below (captures refresh_token + auto-renews).</p></td></tr>
                <?php
                $creator_expires_at  = (int) get_option( 'lgpo_creator_token_expires_at', 0 );
                $creator_obtained_at = (int) get_option( 'lgpo_creator_token_obtained_at', 0 );
                $has_refresh_token   = (string) get_option( 'lgpo_creator_refresh_token', '' ) !== '';
                $token_status        = '<span style="color:#dc3232;">not configured (auto-refresh OFF)</span>';
                if ( $has_refresh_token ) {
                    if ( $creator_expires_at > 0 ) {
                        $delta = $creator_expires_at - time();
                        if ( $delta > 86400 ) {
                            $token_status = sprintf( '<span style="color:#46b450;">healthy — expires %s (in %d days)</span>',
                                esc_html( gmdate( 'Y-m-d H:i', $creator_expires_at ) ),
                                (int) ( $delta / 86400 )
                            );
                        } elseif ( $delta > 0 ) {
                            $token_status = sprintf( '<span style="color:#ffb900;">expires soon: %s (in %d hours) — refresh-on-401 will rotate</span>',
                                esc_html( gmdate( 'Y-m-d H:i', $creator_expires_at ) ),
                                (int) ( $delta / 3600 )
                            );
                        } else {
                            $token_status = sprintf( '<span style="color:#dc3232;">EXPIRED %s — refresh-on-401 will rotate on next sync</span>',
                                esc_html( gmdate( 'Y-m-d H:i', $creator_expires_at ) )
                            );
                        }
                    } else {
                        $token_status = '<span style="color:#ffb900;">refresh_token present but no expires_at recorded</span>';
                    }
                }
                $connect_url = esc_url( home_url( '/patreon-connect/?creator=1' ) );
                $creator_flag = isset( $_GET['lgpo_creator'] ) ? sanitize_text_field( wp_unslash( $_GET['lgpo_creator'] ) ) : '';
                ?>
                <tr><th>Creator-OAuth refresh</th><td>
                    <p>Token status: <?php echo $token_status; ?><?php if ( $creator_obtained_at > 0 ): ?> <em>(last refreshed <?php echo esc_html( gmdate( 'Y-m-d H:i', $creator_obtained_at ) ); ?>)</em><?php endif; ?></p>
                    <?php if ( $creator_flag === 'connected' ): ?>
                        <p style="color:#46b450;font-weight:bold;">✓ Creator account connected. Refresh + access tokens captured. Polling will self-heal on token expiry.</p>
                    <?php elseif ( $creator_flag === 'fail' ): ?>
                        <p style="color:#dc3232;font-weight:bold;">Creator OAuth failed — check the LGPO alert email + Patreon developer portal config.</p>
                    <?php endif; ?>
                    <p>
                        <a href="<?php echo $connect_url; ?>" class="button button-primary">Connect Creator Account</a>
                        <span style="color:#666;margin-left:1em;">One-shot OAuth dance with the creator account. Replaces manual token paste + enables auto-refresh.</span>
                    </p>
                </td></tr>
                <tr><th>Auto Sync</th><td><label><input type="checkbox" name="lgpo_auto_sync_enabled" value="1" <?php checked( get_option( 'lgpo_auto_sync_enabled', '' ), '1' ); ?> /> Enable automatic sync (applies changes without review)</label></td></tr>
                <tr><th>Sync Frequency</th><td><select name="lgpo_sync_frequency"><option value="daily" <?php selected( get_option( 'lgpo_sync_frequency', 'daily' ), 'daily' ); ?>>Daily</option><option value="twicedaily" <?php selected( get_option( 'lgpo_sync_frequency', 'daily' ), 'twicedaily' ); ?>>Twice Daily</option><option value="hourly" <?php selected( get_option( 'lgpo_sync_frequency', 'daily' ), 'hourly' ); ?>>Hourly</option></select><p class="description">Only applies when Auto Sync is enabled.</p></td></tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <hr>
        <h2>Import Tiers from Patreon JSON</h2>
        <p>Paste the raw JSON output from the Patreon tiers curl command, or upload a .json file.</p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'lgpo_json_import_nonce', '_lgpo_json_nonce' ); ?>
            <table class="form-table">
                <tr><th>Paste JSON</th><td><textarea name="lgpo_json_paste" rows="6" class="large-text code" placeholder="Paste curl output here..."></textarea></td></tr>
                <tr><th>Or upload file</th><td><input type="file" name="lgpo_json_file" accept=".json,.txt" /></td></tr>
            </table>
            <?php submit_button( 'Import Tiers', 'secondary', 'lgpo_json_import' ); ?>
        </form>

        <hr>
        <h2>Tier → Role Mapping</h2>
        <p>Map each Patreon Tier ID to a WordPress role.</p>
        <form method="post">
            <?php wp_nonce_field( 'lgpo_tier_map_nonce', '_lgpo_tier_nonce' ); ?>
            <table class="widefat" id="lgpo-tier-map-table" style="max-width:900px;">
                <thead><tr><th>Patreon Tier ID</th><th>Tier Name</th><th>WordPress Role</th><th></th></tr></thead>
                <tbody>
                    <?php if ( ! empty( $tier_map ) ) : ?>
                        <?php foreach ( $tier_map as $tid => $role ) : ?>
                            <?php $label = isset( $tier_labels[ $tid ] ) ? $tier_labels[ $tid ] : '—'; ?>
                            <tr>
                                <td><input type="text" name="lgpo_tm_id[]" value="<?php echo esc_attr( $tid ); ?>" class="regular-text" style="width:120px;" /></td>
                                <td><span style="color:#666;"><?php echo esc_html( $label ); ?></span></td>
                                <td><select name="lgpo_tm_role[]"><?php foreach ( $wp_roles as $slug => $rlabel ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $role, $slug ); ?>><?php echo esc_html( $rlabel ); ?></option><?php endforeach; ?></select></td>
                                <td><button type="button" class="button lgpo-remove-row">✕</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td><input type="text" name="lgpo_tm_id[]" placeholder="Tier ID" class="regular-text" style="width:120px;" /></td>
                            <td><span style="color:#999;">Import JSON to see names</span></td>
                            <td><select name="lgpo_tm_role[]"><?php foreach ( $wp_roles as $slug => $rlabel ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $rlabel ); ?></option><?php endforeach; ?></select></td>
                            <td><button type="button" class="button lgpo-remove-row">✕</button></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="lgpo-add-row">+ Add Tier</button></p>
            <?php submit_button( 'Save Tier Mapping', 'primary', 'lgpo_tier_map_save' ); ?>
        </form>

        <h3>Export Current Mapping (JSON)</h3>
        <pre style="background:#f0f0f0;padding:1em;max-width:700px;overflow:auto;"><?php echo esc_html( json_encode( get_option( 'lgpo_tier_map', array() ), JSON_PRETTY_PRINT ) ); ?></pre>

        <script>
        (function(){
            var roles = <?php echo json_encode( $wp_roles ); ?>;
            document.getElementById('lgpo-add-row').addEventListener('click', function(){
                var tbody = document.querySelector('#lgpo-tier-map-table tbody');
                var tr = document.createElement('tr');
                var opts = '';
                for (var slug in roles) {
                    opts += '<option value="' + slug + '">' + roles[slug] + '</option>';
                }
                tr.innerHTML = '<td><input type="text" name="lgpo_tm_id[]" placeholder="Tier ID" class="regular-text" /></td>'
                    + '<td><select name="lgpo_tm_role[]">' + opts + '</select></td>'
                    + '<td><button type="button" class="button lgpo-remove-row">✕</button></td>';
                tbody.appendChild(tr);
            });
            document.addEventListener('click', function(e){
                if (e.target.classList.contains('lgpo-remove-row')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
        </script>

        <hr>
        <h2>Pending Review Queue</h2>
        <?php lgpo_render_pending_queue(); ?>

        <hr>
        <h2>Patreon API Member Sync</h2>
        <?php lgpo_handle_sync_actions(); ?>
        <?php lgpo_render_sync_section(); ?>
    </div>
    <?php
}

/**
 * ============================================================
 * PENDING REVIEW QUEUE
 * ============================================================
 */

function lgpo_add_pending( $data ) {
    $pending = get_option( 'lgpo_pending_reviews', array() );
    $data['timestamp'] = current_time( 'mysql' );
    $pending[] = $data;
    update_option( 'lgpo_pending_reviews', $pending );
}

function lgpo_render_pending_queue() {
    $pending = get_option( 'lgpo_pending_reviews', array() );
    if ( empty( $pending ) ) {
        echo '<p>No pending reviews.</p>';
        return;
    }
    echo '<table class="widefat"><thead><tr><th>Date</th><th>Patreon User</th><th>Email</th><th>Patreon ID</th><th>Tier</th><th>WP User</th><th>Action</th></tr></thead><tbody>';
    foreach ( $pending as $i => $item ) {
        $wp_user = get_user_by( 'id', $item['wp_user_id'] );
        $wp_display = $wp_user ? esc_html( $wp_user->user_login . ' (#' . $wp_user->ID . ')' ) : 'Unknown';
        printf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>
                <form method="post" style="display:inline">%s<input type="hidden" name="lgpo_resolve_index" value="%d" /><input type="hidden" name="lgpo_resolve_action" value="link" /><button type="submit" class="button button-primary button-small">Link</button></form>
                <form method="post" style="display:inline;margin-left:5px;">%s<input type="hidden" name="lgpo_resolve_index" value="%d" /><input type="hidden" name="lgpo_resolve_action" value="dismiss" /><button type="submit" class="button button-small">Dismiss</button></form>
            </td></tr>',
            esc_html( $item['timestamp'] ), esc_html( $item['patreon_name'] ), esc_html( $item['patreon_email'] ),
            esc_html( $item['patreon_user_id'] ), esc_html( $item['tier_id'] ?? 'none' ), $wp_display,
            wp_nonce_field( 'lgpo_resolve', '_lgpo_nonce', true, false ), $i,
            wp_nonce_field( 'lgpo_resolve', '_lgpo_nonce', true, false ), $i
        );
    }
    echo '</tbody></table>';
}

add_action( 'admin_init', 'lgpo_handle_resolve' );
function lgpo_handle_resolve() {
    if ( ! isset( $_POST['lgpo_resolve_action'] ) || ! isset( $_POST['lgpo_resolve_index'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lgpo_resolve', '_lgpo_nonce' ) ) {
        return;
    }

    $index   = absint( $_POST['lgpo_resolve_index'] );
    $action  = sanitize_text_field( $_POST['lgpo_resolve_action'] );
    $pending = get_option( 'lgpo_pending_reviews', array() );

    if ( ! isset( $pending[ $index ] ) ) {
        return;
    }

    $item = $pending[ $index ];

    if ( $action === 'link' ) {
        update_user_meta( $item['wp_user_id'], 'lgpo_patreon_user_id', $item['patreon_user_id'] );
        if ( ! empty( $item['tier_id'] ) ) {
            $tier_map = get_option( 'lgpo_tier_map', array() );
            if ( isset( $tier_map[ $item['tier_id'] ] ) ) {
                $tier = $tier_map[ $item['tier_id'] ];
                lgpo_apply_role_via_arbiter( (int) $item['wp_user_id'], $tier );
            }
        }
    }

    array_splice( $pending, $index, 1 );
    update_option( 'lgpo_pending_reviews', $pending );

    wp_safe_redirect( admin_url( 'options-general.php?page=lg-patreon-onboard&resolved=1' ) );
    exit;
}

/**
 * ============================================================
 * OAUTH FLOW
 * ============================================================
 */

add_shortcode( 'lg_patreon_onboard', 'lgpo_shortcode' );
function lgpo_shortcode( $atts ) {
    if ( is_user_logged_in() ) {
        $patreon_id = get_user_meta( get_current_user_id(), 'lgpo_patreon_user_id', true );
        if ( $patreon_id ) {
            return '<div class="lgpo-notice lgpo-success">Your Patreon account is already connected. You\'re all set.</div>';
        }
    }

    $client_id    = get_option( 'lgpo_client_id' );
    $redirect_uri = get_option( 'lgpo_redirect_uri' );

    if ( empty( $client_id ) || empty( $redirect_uri ) ) {
        return '<div class="lgpo-notice lgpo-error">Patreon onboarding is not configured yet. Please check back soon.</div>';
    }

    $state = wp_generate_password( 32, false );
    set_transient( 'lgpo_state_' . $state, '1', 600 );

    $auth_url = add_query_arg( array(
        'response_type' => 'code',
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'scope'         => 'identity identity[email] identity.memberships',
        'state'         => $state,
    ), 'https://www.patreon.com/oauth2/authorize' );

    ob_start();
    ?>
    <div class="lgpo-onboard-wrap">
        <a href="<?php echo esc_url( $auth_url ); ?>" class="lgpo-button">Connect Your Patreon Account</a>
        <p class="lgpo-subtext">Click above to verify your Looth Group membership and activate your account on loothgroup.com.</p>
    </div>
    <style>
        .lgpo-onboard-wrap { text-align: center; padding: 2em 0; }
        .lgpo-button { display: inline-block; padding: 14px 28px; background: #ECB351; color: #1A1E12; font-weight: bold; font-size: 1.1em; text-decoration: none; border-radius: 6px; transition: background 0.2s; }
        .lgpo-button:hover { background: #F1DE83; color: #1A1E12; }
        .lgpo-subtext { margin-top: 1em; font-size: 0.9em; color: #666; }
        .lgpo-notice { padding: 1em 1.5em; border-radius: 6px; margin: 1em 0; }
        .lgpo-success { background: #d4e0b8; color: #1A1E12; border: 1px solid #87986A; }
        .lgpo-error { background: #fde8e4; color: #1A1E12; border: 1px solid #FE6A4F; }
    </style>
    <?php
    return ob_get_clean();
}

add_action( 'init', 'lgpo_register_rewrite' );
function lgpo_register_rewrite() {
    add_rewrite_rule( '^patreon-callback/?$', 'index.php?lgpo_callback=1', 'top' );

    // §3n authorize-entry URL — stable entry point for the standalone /join/
    // page (and anywhere else that needs to kick off Patreon OAuth). Builds
    // the OAuth state + redirects to Patreon's authorize endpoint. Optional
    // ?return=<path> binds the post-callback redirect target (defaults to
    // /manage-subscription/). See lgpo_handle_connect() below for the
    // contract.
    add_rewrite_rule( '^patreon-connect/?$', 'index.php?lgpo_connect=1', 'top' );
}

add_filter( 'query_vars', 'lgpo_query_vars' );
function lgpo_query_vars( $vars ) {
    $vars[] = 'lgpo_callback';
    $vars[] = 'lgpo_connect';
    return $vars;
}

/**
 * §3n — Authorize-entry handler.
 *
 * Contract:
 *   GET /patreon-connect[?return=/some/path/]
 *   → 302 to https://www.patreon.com/oauth2/authorize?...&state=<state>
 *
 * The callback at /patreon-callback/ will redirect back to <return>?onboarded=<state>
 * on a terminal outcome, with state ∈ {
 *   success           — new account created, password-setup email sent
 *   already_onboarded — patreon_user_id already maps to a WP user
 *   not_a_patron      — OAuth succeeded but no active Looth Group membership
 *   email_collision   — email matches an existing WP user; manual review queued
 *   fail              — anything else (token/identity fetch failure, WP insert error, etc.)
 * }
 *
 * If the state was minted by the legacy [lg_patreon_onboard] shortcode (no
 * return target), the callback keeps its existing wp_die behavior — no
 * regression for that path.
 *
 * The `return` query param must be path-only (leading /, not //) to prevent
 * open-redirect.
 */
add_action( 'template_redirect', 'lgpo_handle_connect' );
function lgpo_handle_connect() {
    if ( ! get_query_var( 'lgpo_connect' ) ) {
        return;
    }

    $client_id    = get_option( 'lgpo_client_id' );
    $redirect_uri = get_option( 'lgpo_redirect_uri' );

    if ( empty( $client_id ) || empty( $redirect_uri ) ) {
        wp_die(
            'Patreon onboarding is not configured yet. Please check back soon.',
            'Onboarding Unavailable',
            array( 'response' => 503 )
        );
    }

    // ?creator=1 — admin-only branch that does the one-shot OAuth dance to
    // capture refresh_token + expires_at for the campaign-members sweep.
    // Gated behind manage_options because the callback persists the resulting
    // tokens to wp_options (a leaked / unauthenticated trigger would let an
    // attacker race their own creator account into the active token slot).
    $creator_mode = ( isset( $_GET['creator'] ) && $_GET['creator'] === '1' );
    if ( $creator_mode && ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Creator-mode OAuth requires admin access.', 'Forbidden', array( 'response' => 403 ) );
    }

    // Validate the optional return target — path only, no scheme/host, no
    // protocol-relative URLs (//evil.com). Not used in creator mode.
    $return_target = '/manage-subscription/'; // default per §3n
    if ( isset( $_GET['return'] ) ) {
        $candidate = (string) wp_unslash( $_GET['return'] );
        if ( preg_match( '#^/[^/]#', $candidate ) && strpos( $candidate, "\n" ) === false ) {
            $return_target = $candidate;
        }
    }

    $state = wp_generate_password( 32, false );

    if ( $creator_mode ) {
        // Creator scope unlocks the campaign-members API used by the sweep.
        // We don't bind a return_target — the callback routes back to the
        // admin Settings page directly with a status flag.
        $scope = 'campaigns campaigns.members campaigns.members[email] identity';
        set_transient( 'lgpo_state_' . $state, wp_json_encode( [
            'v'            => 1,
            'creator_mode' => true,
            'minted_at'    => time(),
            'source'       => 'creator-onboard',
        ] ), 600 );
    } else {
        $scope = 'identity identity[email] identity.memberships';
        set_transient( 'lgpo_state_' . $state, wp_json_encode( [
            'v'             => 1,
            'return_target' => $return_target,
            'minted_at'     => time(),
            'source'        => 'patreon-connect',
        ] ), 600 );
    }

    $auth_url = add_query_arg( array(
        'response_type' => 'code',
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'scope'         => $scope,
        'state'         => $state,
    ), 'https://www.patreon.com/oauth2/authorize' );

    wp_redirect( $auth_url, 302 );
    exit;
}

/**
 * Parse a state-transient value. /patreon-connect plants JSON; the legacy
 * shortcode plants the string '1'. Returns the decoded payload array on
 * the JSON path, null on the legacy path. Used by the callback to decide
 * whether to redirect or wp_die on terminal states.
 */
function lgpo_parse_state_payload( $raw ): ?array {
    if ( ! is_string( $raw ) || $raw === '1' ) return null;
    $decoded = json_decode( $raw, true );
    if ( ! is_array( $decoded ) || empty( $decoded['v'] ) ) return null;
    return $decoded;
}

/**
 * Terminal-state handler: redirect to <return_target>?onboarded=<status>
 * when the OAuth state was minted by /patreon-connect, OR fall through to
 * the legacy lgpo_confirm_page() wp_die page when it was minted by the
 * shortcode. Both paths resolve their copy through lgpo_onboard_copy($status),
 * so first-time-vs-reconnect wording stays in one place.
 */
function lgpo_terminal( string $status, ?array $payload, string $legacy_html ): void {
    // Creator-OAuth payloads don't carry return_target (they're admin-only and
    // route to Settings on success) — they're handled in lgpo_handle_callback
    // before reaching here, so we fall back to the wp_die page for those if
    // any terminal call reaches lgpo_terminal with a creator-mode payload.
    if ( $payload && ! empty( $payload['return_target'] ) ) {
        $url = add_query_arg( [ 'onboarded' => $status ], (string) $payload['return_target'] );
        wp_redirect( $url, 302 );
        exit;
    }
    // Legacy shortcode path (no return target): render a styled confirmation
    // page whose title + tone match the case — first-time welcome, returning
    // reconnect, or a hard failure (see lgpo_onboard_copy()).
    lgpo_confirm_page( $status, $legacy_html );
}

/**
 * Single source of truth for onboard/reconnect confirmation copy, keyed on the
 * terminal $status the callback emits. `ok` selects the green (success) vs red
 * (needs-action) treatment; `title` + `body` are reused by BOTH the legacy
 * wp_die confirmation page and the return-target banner (?onboarded=<status>).
 *
 * This is where first-time onboard ("Welcome…") and a returning reconnect
 * ("You're logged in now") are distinguished — a repeat Patreon connection must
 * never read as a fresh-onboard welcome (Ian).
 *
 * @return array{ok:bool,title:string,body:string}
 */
function lgpo_onboard_copy( string $status ): array {
    $map = [
        'success' => [
            'ok'    => true,
            'title' => 'Welcome to The Looth Group',
            'body'  => "Your account is set up and you're logged in now. We've emailed a set-password link so you can sign back in anytime.",
        ],
        'already_onboarded' => [
            'ok'    => true,
            'title' => "You're logged in now",
            'body'  => "Your Patreon was already connected — we've just logged you into your existing account and refreshed your membership access. Nothing else to do.",
        ],
        'adopted' => [
            'ok'    => true,
            'title' => "You're logged in now",
            'body'  => 'We connected your Patreon membership to your existing Looth Group account and logged you in. Your access level is up to date.',
        ],
        'not_a_patron' => [
            'ok'    => false,
            'title' => 'Membership not found',
            'body'  => "We couldn't find an active Looth Group membership on your Patreon account.",
        ],
        'email_collision' => [
            'ok'    => false,
            'title' => 'Account needs review',
            'body'  => "There's already an account associated with your email address. Please contact us so we can sort this out.",
        ],
    ];
    return $map[ $status ] ?? [
        'ok'    => false,
        'title' => 'Onboarding Issue',
        'body'  => 'Something went wrong activating your account. Please try again.',
    ];
}

/**
 * Render the terminal confirmation page for the legacy shortcode path (no
 * return target). Title + accent come from lgpo_onboard_copy($status); the body
 * is the richer inline HTML the call site passed (which may carry dynamic links
 * — the Join CTA, a contact mailto — so we keep it over the default copy).
 */
function lgpo_confirm_page( string $status, string $body_html ): void {
    $copy   = lgpo_onboard_copy( $status );
    $accent = $copy['ok'] ? [ 'bg' => '#d4e0b8', 'border' => '#87986A' ]
                          : [ 'bg' => '#fde8e4', 'border' => '#FE6A4F' ];
    wp_die(
        '<div style="max-width:600px;margin:60px auto;font-family:sans-serif;">'
        . '<h2 style="color:#1A1E12;">' . esc_html( $copy['title'] ) . '</h2>'
        . '<div style="padding:1em 1.5em;background:' . $accent['bg'] . ';border:1px solid ' . $accent['border'] . ';border-radius:6px;color:#1A1E12;">' . $body_html . '</div>'
        . '<p style="margin-top:1.5em;"><a href="' . esc_url( home_url() ) . '">← Back to loothgroup.com</a></p></div>',
        $copy['title'],
        [ 'response' => 200 ]
    );
}

/**
 * Return-target path (/patreon-connect → callback → <return_target>?onboarded=<status>):
 * prepend a confirmation banner to the landing page so a member who comes
 * through the standalone /join/ entry still gets the same clear
 * first-time-vs-reconnect confirmation. No-op unless ?onboarded is one of the
 * known statuses. Completes the §3n contract — the redirect was already wired,
 * but nothing consumed the param.
 */
add_filter( 'the_content', 'lgpo_onboarded_banner', 5 );
function lgpo_onboarded_banner( $content ) {
    if ( ! is_main_query() || ! in_the_loop() || ! is_page() || empty( $_GET['onboarded'] ) ) {
        return $content;
    }
    $status = sanitize_key( wp_unslash( $_GET['onboarded'] ) );
    $copy   = lgpo_onboard_copy( $status );
    $accent = $copy['ok'] ? [ 'bg' => '#d4e0b8', 'border' => '#87986A' ]
                          : [ 'bg' => '#fde8e4', 'border' => '#FE6A4F' ];
    $banner = '<div class="lgpo-onboarded-banner" style="max-width:680px;margin:0 auto 28px;padding:1em 1.5em;background:'
        . $accent['bg'] . ';border:1px solid ' . $accent['border'] . ';border-radius:8px;color:#1A1E12;">'
        . '<strong style="display:block;font-size:1.1em;margin-bottom:4px;">' . esc_html( $copy['title'] ) . '</strong>'
        . '<span>' . esc_html( $copy['body'] ) . '</span></div>';
    return $banner . $content;
}

/**
 * Failure alert — used by the polling engine and onboarding callback for
 * conditions the coordinator/admin must know about (expired creator token,
 * API outage, repeated 401, config drift). Emails the contact address
 * (lgpo_contact_email, falling back to admin_email), logs to error_log,
 * and is best-effort — any mail failure is swallowed.
 *
 * §3n item 5 — "Poll failures alert the coordinator (devmsg/email), not just error_log."
 */
function lgpo_alert_failure( string $context, string $detail ): void {
    error_log( "LGPO ALERT [{$context}]: {$detail}" );
    try {
        $to = (string) get_option( 'lgpo_contact_email', '' );
        if ( $to === '' ) { $to = (string) get_option( 'admin_email', '' ); }
        if ( $to === '' ) return;
        $site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
        \LGMS\Mail::send(
            $to,
            "[{$site}] LGPO alert: {$context}",
            "Context: {$context}\n\nDetail:\n{$detail}\n\nReview: "
            . admin_url( 'options-general.php?page=lg-patreon-onboard' ) . "\n"
        );
    } catch ( \Throwable $_ ) {
        // best-effort
    }
}

/**
 * Persist a Patreon OAuth-token response (creator scope) to the lgpo_creator_*
 * options. Takes the full body returned by Patreon's /api/oauth2/token
 * endpoint (auth_code or refresh_token grants) and stores:
 *   - lgpo_creator_access_token       — used for member-sweep API calls
 *   - lgpo_creator_refresh_token      — used by lgpo_refresh_creator_token()
 *   - lgpo_creator_token_expires_at   — unix ts; ~31 days from issue for Patreon
 *   - lgpo_creator_token_obtained_at  — audit / staleness display
 *
 * Returns true if at least access_token + refresh_token are present in the
 * payload (refresh isn't always re-issued by Patreon on rotation; we keep the
 * old refresh_token if the new response omits it).
 */
function lgpo_persist_creator_tokens( array $token_body ): bool {
    $access = isset( $token_body['access_token'] ) ? (string) $token_body['access_token'] : '';
    if ( $access === '' ) return false;

    update_option( 'lgpo_creator_access_token', $access );

    if ( ! empty( $token_body['refresh_token'] ) ) {
        update_option( 'lgpo_creator_refresh_token', (string) $token_body['refresh_token'] );
    }

    if ( ! empty( $token_body['expires_in'] ) ) {
        update_option( 'lgpo_creator_token_expires_at', time() + (int) $token_body['expires_in'] );
    }

    update_option( 'lgpo_creator_token_obtained_at', time() );
    return true;
}

/**
 * Refresh the creator access token using the stored refresh_token. Posts
 * `grant_type=refresh_token` to Patreon's OAuth endpoint, persists the new
 * pair, and returns the fresh access token on success.
 *
 * @return array{ok:true, access_token:string} | array{ok:false, error:string}
 */
function lgpo_refresh_creator_token(): array {
    $refresh = (string) get_option( 'lgpo_creator_refresh_token', '' );
    $client_id     = (string) get_option( 'lgpo_client_id', '' );
    $client_secret = (string) get_option( 'lgpo_client_secret', '' );

    if ( $refresh === '' ) {
        return [ 'ok' => false, 'error' => 'no refresh_token on file (run creator-OAuth dance via Settings)' ];
    }
    if ( $client_id === '' || $client_secret === '' ) {
        return [ 'ok' => false, 'error' => 'client_id/client_secret missing' ];
    }

    $resp = wp_remote_post( 'https://www.patreon.com/api/oauth2/token', [
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent'   => 'LoothGroup-TokenRefresh/1.0',
        ],
        'body' => [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ],
    ] );

    if ( is_wp_error( $resp ) ) {
        return [ 'ok' => false, 'error' => 'wp_remote_post: ' . $resp->get_error_message() ];
    }
    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = (string) wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );

    if ( $code !== 200 || ! is_array( $json ) || empty( $json['access_token'] ) ) {
        return [
            'ok'    => false,
            'error' => "Patreon token endpoint returned HTTP {$code} — " . substr( $body, 0, 300 ),
        ];
    }

    if ( ! lgpo_persist_creator_tokens( $json ) ) {
        return [ 'ok' => false, 'error' => 'persist failed (token_body shape unexpected)' ];
    }

    return [ 'ok' => true, 'access_token' => (string) $json['access_token'] ];
}

add_action( 'template_redirect', 'lgpo_handle_callback' );
function lgpo_handle_callback() {
    if ( ! get_query_var( 'lgpo_callback' ) ) {
        return;
    }

    $state = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : '';
    $state_raw = $state !== '' ? get_transient( 'lgpo_state_' . $state ) : false;
    if ( empty( $state ) || $state_raw === false ) {
        wp_die( 'Invalid or expired request. Please go back and try again.', 'Onboarding Error', array( 'response' => 403 ) );
    }
    delete_transient( 'lgpo_state_' . $state );

    // Decode whether this OAuth flow originated from /patreon-connect (JSON
    // payload with return target) vs. the legacy shortcode (raw '1' string).
    $state_payload = lgpo_parse_state_payload( $state_raw );

    $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';
    if ( empty( $code ) ) {
        wp_die( 'Authorization was denied or failed. Please try again.', 'Onboarding Error', array( 'response' => 400 ) );
    }

    $client_id     = get_option( 'lgpo_client_id' );
    $client_secret = get_option( 'lgpo_client_secret' );
    $redirect_uri  = get_option( 'lgpo_redirect_uri' );

    $token_response = wp_remote_post( 'https://www.patreon.com/api/oauth2/token', array(
        'body' => array(
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => $redirect_uri,
        ),
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent'   => 'LoothGroup-Onboard/1.0',
        ),
    ) );

    if ( is_wp_error( $token_response ) ) {
        lgpo_terminal( 'fail', $state_payload, 'Could not connect to Patreon. Please try again later.' );
    }

    $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
    if ( empty( $token_body['access_token'] ) ) {
        lgpo_terminal( 'fail', $state_payload, 'Failed to get access token from Patreon. Please try again.' );
    }

    $access_token = $token_body['access_token'];

    // Creator-mode branch: persist the token pair to wp_options and bounce
    // back to the admin Settings page. Skips the identity / membership fetch
    // because creator OAuth is solely about capturing the API credentials.
    if ( is_array( $state_payload ) && ! empty( $state_payload['creator_mode'] ) ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            // The /patreon-connect entry was admin-gated, but the callback
            // can be hit directly (a third party with the state could replay
            // it) — re-check on this end too.
            wp_die( 'Creator-mode callback requires admin access.', 'Forbidden', array( 'response' => 403 ) );
        }
        if ( ! lgpo_persist_creator_tokens( (array) $token_body ) ) {
            lgpo_alert_failure(
                'creator-oauth.persist',
                "Creator OAuth callback succeeded but the token payload was unexpected:\n"
                . substr( wp_json_encode( $token_body ), 0, 500 )
            );
            wp_redirect( add_query_arg( [ 'lgpo_creator' => 'fail' ], admin_url( 'options-general.php?page=lg-patreon-onboard' ) ), 302 );
            exit;
        }
        wp_redirect( add_query_arg( [ 'lgpo_creator' => 'connected' ], admin_url( 'options-general.php?page=lg-patreon-onboard' ) ), 302 );
        exit;
    }

    // include=memberships.campaign is REQUIRED for the per-campaign guard below
    // (lgpo_membership_matches_campaign): the identity membership list spans every
    // creator the patron backs, and the guard rejects any membership whose
    // campaign id != lgpo_campaign_id. Without pulling the campaign relationship,
    // each member's relationships.campaign.data.id is empty → the guard rejects
    // EVERY self-connector as not_a_patron. The include alone populates that
    // linkage id; do NOT add fields[campaign] — Patreon 400s on an empty
    // fieldset ("Invalid value for parameter 'fields[campaign]'"), which would
    // fail the whole identity fetch → ?onboarded=fail.
    $identity_url = 'https://www.patreon.com/api/oauth2/v2/identity'
        . '?include=memberships,memberships.currently_entitled_tiers,memberships.campaign'
        . '&fields%5Buser%5D=email,full_name,image_url'
        . '&fields%5Bmember%5D=patron_status,currently_entitled_amount_cents,email,full_name'
        . '&fields%5Btier%5D=title';

    $identity_response = wp_remote_get( $identity_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'User-Agent'    => 'LoothGroup-Onboard/1.0',
        ),
    ) );

    if ( is_wp_error( $identity_response ) ) {
        lgpo_terminal( 'fail', $state_payload, 'Could not fetch your Patreon profile. Please try again later.' );
    }

    $identity_body = json_decode( wp_remote_retrieve_body( $identity_response ), true );

    if ( empty( $identity_body['data']['id'] ) ) {
        lgpo_terminal( 'fail', $state_payload, 'Could not read your Patreon profile data. Please try again.' );
    }

    $patreon_user_id = $identity_body['data']['id'];
    $patreon_email   = $identity_body['data']['attributes']['email'] ?? '';
    $patreon_name    = $identity_body['data']['attributes']['full_name'] ?? '';

    $campaign_id = get_option( 'lgpo_campaign_id' );
    $membership  = null;
    $tier_id     = null;
    $tier_label  = null;

    if ( ! empty( $identity_body['data']['relationships']['memberships']['data'] ) ) {
        $member_ids = wp_list_pluck( $identity_body['data']['relationships']['memberships']['data'], 'id' );
        $included   = $identity_body['included'] ?? array();

        foreach ( $included as $resource ) {
            if ( $resource['type'] !== 'member' || ! in_array( $resource['id'], $member_ids, true ) ) {
                continue;
            }

            $member_campaign_id = $resource['relationships']['campaign']['data']['id'] ?? '';
            // Only provision off a membership in OUR campaign. The identity
            // endpoint lists memberships across every creator the user backs,
            // so without this a patron of any foreign creator would be granted
            // a paid Looth tier. Fails open only when lgpo_campaign_id is unset.
            if ( ! lgpo_membership_matches_campaign( (string) $member_campaign_id, (string) $campaign_id ) ) {
                continue;
            }

            $status = $resource['attributes']['patron_status'] ?? '';
            if ( $status !== 'active_patron' ) {
                continue;
            }

            $membership = $resource;
            $entitled_tiers = $resource['relationships']['currently_entitled_tiers']['data'] ?? array();
            if ( ! empty( $entitled_tiers ) ) {
                $tier_id = $entitled_tiers[0]['id'];
                // Resolve the tier title from the included tier resource (fields[tier]=title)
                // so the lg_patreon_members snapshot carries the same tier_label the sweep writes.
                foreach ( $included as $inc ) {
                    if ( ( $inc['type'] ?? '' ) === 'tier' && ( $inc['id'] ?? '' ) === $tier_id ) {
                        $tier_label = $inc['attributes']['title'] ?? null;
                        break;
                    }
                }
            }
            break;
        }
    }

    if ( ! $membership ) {
        $patreon_link = get_option( 'lgpo_patreon_link', 'https://www.patreon.com/cw/theloothgroup/membership' );
        lgpo_terminal( 'not_a_patron', $state_payload,
            "We couldn't find an active Looth Group membership on your Patreon account. "
            . '<a href="' . esc_url( $patreon_link ) . '">Join here</a> and then come back to activate your account.'
        );
    }

    // Snapshot of the matched active membership, shaped for
    // LGPO_Sync_Engine::record_patreon_member() — written at every terminal
    // (new account + both adopt branches) so a self-connected member is fully
    // provisioned (lg_patreon_members row) immediately, not next sweep.
    $member_snapshot = array(
        'patreon_user_id'                 => $patreon_user_id,
        'email'                           => $patreon_email,
        'full_name'                       => $patreon_name,
        'patron_status'                   => $membership['attributes']['patron_status'] ?? 'active_patron',
        'currently_entitled_amount_cents' => $membership['attributes']['currently_entitled_amount_cents'] ?? null,
        'tier_labels'                     => $tier_label ? array( $tier_label ) : array(),
        // The OAuth identity response carries no charge history — leave null;
        // the next creator-token sweep enriches these on its pass.
        'last_charge_status'              => null,
        'last_charge_date'                => null,
        'next_charge_date'                => null,
        'will_pay_amount_cents'           => null,
    );

    $tier_map = get_option( 'lgpo_tier_map', array() );
    $wp_role  = null;
    if ( $tier_id && isset( $tier_map[ $tier_id ] ) ) {
        $wp_role = $tier_map[ $tier_id ];
    }
    if ( ! $wp_role ) {
        $wp_role = ! empty( $tier_map ) ? reset( $tier_map ) : 'subscriber';
    }

    // Already onboarded? Matched by Patreon user-id — reuse, never mint.
    $existing_by_patreon = lgpo_get_user_by_patreon_id( $patreon_user_id );
    if ( $existing_by_patreon ) {
        lgpo_adopt_existing_user( $existing_by_patreon, $patreon_user_id, $patreon_email, $tier_id, $wp_role, $member_snapshot );
        lgpo_terminal( 'already_onboarded', $state_payload,
            'Your account is already connected and you\'re now logged in! Your membership has been verified and your access level updated.'
        );
    }

    // Email collision? An existing WP account already owns this Patreon email.
    // REUSE it instead of minting a second account (the mikelle.davlin
    // double-account: wp 1848 + minted wp 1905 split her identity and orphaned
    // the original from the profile bridge). The only cases we DON'T auto-adopt:
    //   - the existing account is already linked to a DIFFERENT Patreon id
    //     (genuine conflict — two Patreon accounts, one email), or
    //   - it's a privileged (admin) account — never hand an admin session out
    //     over OAuth; route both to human review.
    $existing_by_email = get_user_by( 'email', $patreon_email );
    if ( $existing_by_email ) {
        $contact_email = get_option( 'lgpo_contact_email', 'ian.davlin@gmail.com' );
        $existing_patreon_id = get_user_meta( $existing_by_email->ID, 'lgpo_patreon_user_id', true );

        if ( $existing_patreon_id && (string) $existing_patreon_id !== (string) $patreon_user_id ) {
            lgpo_add_pending( array(
                'patreon_user_id' => $patreon_user_id, 'patreon_email' => $patreon_email,
                'patreon_name' => $patreon_name, 'tier_id' => $tier_id,
                'wp_user_id' => $existing_by_email->ID, 'reason' => 'different_patreon_id',
            ) );
            lgpo_notify_admin( $patreon_name, $patreon_email, $existing_by_email->user_login );
            lgpo_terminal( 'email_collision', $state_payload,
                'There\'s already an account associated with your email address that is linked to a different Patreon account. '
                . 'Please contact <a href="mailto:' . esc_attr( $contact_email ) . '">' . esc_html( $contact_email ) . '</a> to get this sorted out.'
            );
        }

        if ( user_can( $existing_by_email, 'manage_options' ) ) {
            lgpo_add_pending( array(
                'patreon_user_id' => $patreon_user_id, 'patreon_email' => $patreon_email,
                'patreon_name' => $patreon_name, 'tier_id' => $tier_id,
                'wp_user_id' => $existing_by_email->ID, 'reason' => 'admin_collision',
            ) );
            lgpo_notify_admin( $patreon_name, $patreon_email, $existing_by_email->user_login );
            lgpo_terminal( 'email_collision', $state_payload,
                'There\'s already an account associated with this email address. '
                . 'Please contact <a href="mailto:' . esc_attr( $contact_email ) . '">' . esc_html( $contact_email ) . '</a> to get this sorted out.'
            );
        }

        // Same Patreon id, or not yet linked: adopt the existing account.
        lgpo_adopt_existing_user( $existing_by_email, $patreon_user_id, $patreon_email, $tier_id, $wp_role, $member_snapshot );
        lgpo_terminal( 'adopted', $state_payload,
            'We connected your Patreon membership to your existing Looth Group account and logged you in. Your access level has been updated.'
        );
    }

    // No existing account by Patreon id or email — create a new one.
    $username = lgpo_generate_username( $patreon_name, $patreon_email );
    $password = wp_generate_password( 24, true, true );

    $user_id = wp_insert_user( array(
        'user_login'   => $username,
        'user_email'   => $patreon_email,
        'user_pass'    => $password,
        'display_name' => $patreon_name,
        'role'         => $wp_role,
    ) );

    if ( is_wp_error( $user_id ) ) {
        lgpo_terminal( 'fail', $state_payload, 'Could not create your account: ' . $user_id->get_error_message() );
    }

    update_user_meta( $user_id, 'lgpo_patreon_user_id', $patreon_user_id );
    update_user_meta( $user_id, 'lgpo_patreon_email', $patreon_email );
    update_user_meta( $user_id, 'lgpo_patreon_tier_id', $tier_id );
    update_user_meta( $user_id, 'lgpo_onboarded_at', current_time( 'mysql' ) );
    update_user_meta( $user_id, 'payment_source', 'patreon' );

    // Write the full membership snapshot (lg_patreon_members) the sweep would
    // write, so Membership::statusFor() sees them as a member NOW — not next sweep.
    if ( class_exists( 'LGPO_Sync_Engine' ) ) {
        LGPO_Sync_Engine::record_patreon_member( (int) $user_id, $member_snapshot );
    }

    // Record the Patreon role opinion in lg_role_sources so the arbiter
    // sees it on later cross-source merges (e.g. user later signs up for Stripe).
    lgpo_apply_role_via_arbiter( (int) $user_id, $wp_role );

    // Log them straight in (lifecycle G1) — they connected via Patreon, so land
    // them authenticated instead of bouncing to a password screen.
    lgpo_login_user( (int) $user_id );

    // New account: no emailed reset link. They're already logged in (above) —
    // send them to the inline set-password welcome page (lgpo-set-password.php).
    wp_safe_redirect( home_url( '/patreon-password/' ) );
    exit;
}

/**
 * ============================================================
 * HELPERS
 * ============================================================
 */

function lgpo_get_user_by_patreon_id( $patreon_user_id ) {
    $users = get_users( array( 'meta_key' => 'lgpo_patreon_user_id', 'meta_value' => $patreon_user_id, 'number' => 1 ) );
    return ! empty( $users ) ? $users[0] : null;
}

/**
 * Reuse an existing WP account for this Patreon member instead of minting a
 * second one. Stamps the Patreon linkage meta (idempotent), applies the
 * entitled tier through the arbiter, writes the lg_patreon_members snapshot,
 * and logs them in. This is the dedupe guard: a Patreon email or Patreon
 * user-id that already maps to a WP account must NEVER create a new account.
 *
 * @param array $member_snapshot Shape for LGPO_Sync_Engine::record_patreon_member().
 */
function lgpo_adopt_existing_user( $user, $patreon_user_id, $patreon_email, $tier_id, $wp_role, $member_snapshot = array() ) {
    if ( ! $user instanceof WP_User ) {
        $user = get_user_by( 'id', (int) $user );
    }
    if ( ! $user instanceof WP_User ) {
        return;
    }
    update_user_meta( $user->ID, 'lgpo_patreon_user_id', $patreon_user_id );
    update_user_meta( $user->ID, 'lgpo_patreon_email', $patreon_email );
    update_user_meta( $user->ID, 'lgpo_patreon_tier_id', $tier_id );
    if ( ! get_user_meta( $user->ID, 'lgpo_onboarded_at', true ) ) {
        update_user_meta( $user->ID, 'lgpo_onboarded_at', current_time( 'mysql' ) );
    }
    update_user_meta( $user->ID, 'payment_source', 'patreon' );
    lgpo_apply_role_via_arbiter( (int) $user->ID, $wp_role );
    // Full membership snapshot so the member is provisioned NOW, not next sweep.
    if ( ! empty( $member_snapshot ) && class_exists( 'LGPO_Sync_Engine' ) ) {
        LGPO_Sync_Engine::record_patreon_member( (int) $user->ID, $member_snapshot );
    }
    lgpo_login_user( $user );
}

function lgpo_generate_username( $name, $email ) {
    $base = sanitize_user( strtolower( str_replace( ' ', '.', trim( $name ) ) ), true );
    if ( empty( $base ) ) {
        $base = sanitize_user( strtolower( explode( '@', $email )[0] ), true );
    }
    if ( empty( $base ) ) {
        $base = 'looth-member';
    }
    $username = $base;
    $suffix = 1;
    while ( username_exists( $username ) ) {
        $username = $base . $suffix;
        $suffix++;
    }
    return $username;
}

function lgpo_notify_admin( $patreon_name, $patreon_email, $wp_username ) {
    $admin_email = get_option( 'lgpo_contact_email', get_option( 'admin_email' ) );
    \LGMS\Mail::send( $admin_email, '[Looth Group] Patreon onboard collision needs review',
        "A Patreon member tried to onboard but their email collides with an existing WP account.\n\n"
        . "Patreon Name: {$patreon_name}\nPatreon Email: {$patreon_email}\nExisting WP User: {$wp_username}\n\n"
        . "Review: " . admin_url( 'options-general.php?page=lg-patreon-onboard' ) . "\n"
    );
}

register_activation_hook( __FILE__, 'lgpo_activate' );
function lgpo_activate() {
    lgpo_register_rewrite();
    // Defer flush_rewrite_rules() to 'init' priority 9999 via transient.
    // Activation runs in isolation before 'init' fires, so flushing here
    // serializes a partial rule set missing rules other plugins register on init.
    // See \LGMS\Plugin::boot() for the deferred handler.
    set_transient( 'lgms_pending_rewrite_flush', 1, HOUR_IN_SECONDS );
}

register_deactivation_hook( __FILE__, 'lgpo_deactivate' );
function lgpo_deactivate() {
    set_transient( 'lgms_pending_rewrite_flush', 1, HOUR_IN_SECONDS );
    LGPO_Sync_Cron::deactivate();
}

/**
 * ============================================================
 * PATREON API SYNC — HANDLERS AND UI
 * ============================================================
 */

/**
 * Handle sync-related POST actions (Fetch, Execute, Clear).
 */
function lgpo_handle_sync_actions() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle "Fetch from Patreon" button
    if ( isset( $_POST['lgpo_fetch_patreon'] ) && check_admin_referer( 'lgpo_fetch_patreon', '_lgpo_fetch_nonce' ) ) {
        $stats = LGPO_Sync_Engine::fetch_and_compare();
        if ( isset( $stats['error'] ) ) {
            echo '<div class="notice notice-error"><p>Sync failed: ' . esc_html( $stats['error'] ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Fetched ' . esc_html( $stats['total_fetched'] ) . ' members from Patreon. Review proposed changes below.</p></div>';
        }
    }

    // Handle "Execute Selected Changes" button
    if ( isset( $_POST['lgpo_execute_sync'] ) && check_admin_referer( 'lgpo_execute_sync', '_lgpo_exec_nonce' ) ) {
        $json = isset( $_POST['lgpo_selected_changes'] ) ? wp_unslash( $_POST['lgpo_selected_changes'] ) : '';
        $selected = json_decode( $json, true );
        if ( ! is_array( $selected ) || empty( $selected ) ) {
            echo '<div class="notice notice-error"><p>No changes selected.</p></div>';
        } else {
            $results = LGPO_Sync_Engine::execute_approved( $selected );
            $count = count( $results['applied'] );
            $errors = count( $results['errors'] );
            echo '<div class="notice notice-success"><p>Sync complete: ' . esc_html( $count ) . ' applied, ' . esc_html( $errors ) . ' errors. Admin email sent.</p></div>';
        }
    }

    // Handle "Execute All Changes" button
    if ( isset( $_POST['lgpo_execute_all'] ) && check_admin_referer( 'lgpo_execute_all', '_lgpo_exec_all_nonce' ) ) {
        $proposed = LGPO_Sync_Engine::get_proposed_changes();
        $all_updates = $proposed['updates'] ?? [];
        if ( empty( $all_updates ) ) {
            echo '<div class="notice notice-error"><p>No changes to apply.</p></div>';
        } else {
            $results = LGPO_Sync_Engine::execute_approved( $all_updates );
            $count = count( $results['applied'] );
            $errors = count( $results['errors'] );
            echo '<div class="notice notice-success"><p>Sync complete: ' . esc_html( $count ) . ' applied, ' . esc_html( $errors ) . ' errors. Admin email sent.</p></div>';
        }
    }

    // Handle "Clear" button
    if ( isset( $_POST['lgpo_clear_sync'] ) && check_admin_referer( 'lgpo_clear_sync', '_lgpo_clear_nonce' ) ) {
        LGPO_Sync_Engine::clear_proposed_changes();
        echo '<div class="notice notice-info"><p>Proposed changes cleared.</p></div>';
    }

    // Handle "Revert Batch" button
    if ( isset( $_POST['lgpo_revert_batch'] ) && check_admin_referer( 'lgpo_revert_batch', '_lgpo_revert_nonce' ) ) {
        $batch_id = sanitize_text_field( $_POST['lgpo_revert_batch_id'] ?? '' );
        if ( $batch_id ) {
            $result = LGPO_Sync_Engine::revert_batch( $batch_id );
            echo '<div class="notice notice-success"><p>Reverted ' . esc_html( $result['reverted'] ) . ' changes from batch ' . esc_html( $batch_id ) . '.</p></div>';
            if ( ! empty( $result['errors'] ) ) {
                echo '<div class="notice notice-warning"><p>Revert errors: ' . esc_html( implode( '; ', $result['errors'] ) ) . '</p></div>';
            }
        }
    }
}

/**
 * Render the sync section on the admin page.
 */
function lgpo_render_sync_section() {
    $last_fetch = get_option( 'lgpo_last_fetch_time', '' );
    $last_sync  = get_option( 'lgpo_last_sync_time', '' );
    $token      = get_option( 'lgpo_creator_access_token', '' );
    ?>
    <p>
        <strong>Last Fetch:</strong> <?php echo $last_fetch ? esc_html( date( 'Y-m-d H:i:s', $last_fetch ) ) : 'Never'; ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Last Sync:</strong> <?php echo $last_sync ? esc_html( date( 'Y-m-d H:i:s', $last_sync ) ) : 'Never'; ?>
    </p>

    <?php if ( ! $token ) : ?>
        <p><em>Configure a Creator Access Token above to enable API sync.</em></p>
        <?php return; ?>
    <?php endif; ?>

    <form method="post" style="display:inline;">
        <?php wp_nonce_field( 'lgpo_fetch_patreon', '_lgpo_fetch_nonce' ); ?>
        <input type="submit" name="lgpo_fetch_patreon" class="button button-primary" value="Fetch from Patreon">
    </form>

    <?php
    // Render changelog / revert UI
    lgpo_render_changelog_section();

    // Render review UI if we have proposed changes
    $changes = LGPO_Sync_Engine::get_proposed_changes();
    if ( ! $changes ) {
        return;
    }

    lgpo_render_sync_review( $changes );
}

/**
 * Render the review UI with checkboxes.
 */
function lgpo_render_sync_review( array $changes ) {
    $updates = $changes['updates'] ?? [];
    $skipped = $changes['skipped'] ?? [];
    $stats   = $changes['stats'] ?? [];

    $current_tab = isset( $_GET['synctab'] ) ? sanitize_text_field( $_GET['synctab'] ) : 'updates';
    if ( ! in_array( $current_tab, [ 'updates', 'skipped' ], true ) ) {
        $current_tab = 'updates';
    }

    // Email filter
    $email_filter = isset( $_GET['sync_email'] ) ? sanitize_text_field( $_GET['sync_email'] ) : '';
    ?>
    <div style="margin-top:20px; background:#fff; border:1px solid #ccd0d4; padding:20px;">
        <h3 style="margin-top:0;">Proposed Changes — Review &amp; Approve</h3>

        <div style="margin-bottom:15px;">
            <strong>Fetched:</strong> <?php echo esc_html( $stats['total_fetched'] ?? 0 ); ?>
            &nbsp;|&nbsp; <strong>Matched:</strong> <?php echo esc_html( $stats['matched'] ?? 0 ); ?>
            &nbsp;|&nbsp; <strong>Changes:</strong> <?php echo esc_html( count( $updates ) ); ?>
            &nbsp;|&nbsp; <strong>Unchanged:</strong> <?php echo esc_html( $stats['unchanged'] ?? 0 ); ?>
            &nbsp;|&nbsp; <strong>Skipped (Stripe):</strong> <?php echo esc_html( $stats['skipped_stripe'] ?? 0 ); ?>
            &nbsp;|&nbsp; <strong>Skipped (looth4):</strong> <?php echo esc_html( $stats['skipped_looth4'] ?? 0 ); ?>
            &nbsp;|&nbsp; <strong>No WP account:</strong> <?php echo esc_html( $stats['skipped_no_wp'] ?? 0 ); ?>
        </div>

        <?php
        // Build role transition breakdown
        $transitions = [];
        foreach ( $updates as $u ) {
            $key = $u['current_role'] . ' → ' . $u['new_role'];
            $transitions[ $key ] = ( $transitions[ $key ] ?? 0 ) + 1;
        }
        ksort( $transitions );
        if ( ! empty( $transitions ) ) : ?>
            <div style="margin-bottom:15px; padding:10px; background:#f0f6fc; border-left:4px solid #2271b1;">
                <strong>Role Transitions:</strong>
                <?php foreach ( $transitions as $label => $count ) : ?>
                    &nbsp;&nbsp; <code><?php echo esc_html( $label ); ?></code> (<?php echo esc_html( $count ); ?>)
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( empty( $updates ) && empty( $skipped ) ) : ?>
            <p><em>No changes needed. All matched users are already in sync.</em></p>
            <?php return; ?>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <h2 class="nav-tab-wrapper" style="margin:0 0 15px 0;">
            <a href="<?php echo esc_url( add_query_arg( [ 'synctab' => 'updates', 'sync_email' => false ] ) ); ?>"
               class="nav-tab <?php echo $current_tab === 'updates' ? 'nav-tab-active' : ''; ?>">
                Updates &amp; Downgrades (<?php echo count( $updates ); ?>)
            </a>
            <a href="<?php echo esc_url( add_query_arg( [ 'synctab' => 'skipped', 'sync_email' => false ] ) ); ?>"
               class="nav-tab <?php echo $current_tab === 'skipped' ? 'nav-tab-active' : ''; ?>">
                Skipped (<?php echo count( $skipped ); ?>)
            </a>
        </h2>

        <!-- Email Filter -->
        <div style="margin-bottom:15px; padding:10px; background:#f9f9f9; border:1px solid #ddd;">
            <form method="get" style="margin:0;">
                <input type="hidden" name="page" value="lg-patreon-onboard">
                <input type="hidden" name="synctab" value="<?php echo esc_attr( $current_tab ); ?>">
                <label><strong>Filter by Email:</strong></label>
                <input type="text" name="sync_email" value="<?php echo esc_attr( $email_filter ); ?>" placeholder="Enter email..." style="width:250px; margin:0 5px;">
                <button type="submit" class="button">Filter</button>
                <?php if ( $email_filter ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'synctab' => $current_tab, 'sync_email' => false ] ) ); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ( $current_tab === 'updates' ) : ?>
            <?php lgpo_render_updates_tab( $updates, $email_filter ); ?>
        <?php else : ?>
            <?php lgpo_render_skipped_tab( $skipped, $email_filter ); ?>
        <?php endif; ?>

        <!-- Execute All / Clear buttons -->
        <div style="margin-top:15px;">
            <?php if ( ! empty( $updates ) ) : ?>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field( 'lgpo_execute_all', '_lgpo_exec_all_nonce' ); ?>
                    <input type="submit" name="lgpo_execute_all" class="button button-primary button-large"
                           value="Execute All <?php echo esc_attr( count( $updates ) ); ?> Changes"
                           onclick="return confirm('Execute ALL <?php echo esc_attr( count( $updates ) ); ?> proposed changes? This includes tag-only, upgrades, and downgrades.');">
                </form>
            <?php endif; ?>

            <form method="post" style="display:inline; margin-left:10px;">
                <?php wp_nonce_field( 'lgpo_clear_sync', '_lgpo_clear_nonce' ); ?>
                <input type="submit" name="lgpo_clear_sync" class="button" value="Clear Proposed Changes" onclick="return confirm('Clear all proposed changes?');">
            </form>
        </div>
    </div>
    <?php
}

/**
 * Render the Updates & Downgrades tab with checkboxes.
 */
function lgpo_render_updates_tab( array $updates, string $email_filter ) {
    // Apply email filter
    if ( $email_filter ) {
        $updates = array_filter( $updates, function ( $u ) use ( $email_filter ) {
            return stripos( $u['email'], $email_filter ) !== false;
        } );
        $updates = array_values( $updates );
    }

    if ( empty( $updates ) ) {
        echo '<p><em>No updates to show.</em></p>';
        return;
    }

    // Pagination
    $per_page     = 50;
    $current_page = isset( $_GET['syncpage'] ) ? max( 1, absint( $_GET['syncpage'] ) ) : 1;
    $total        = count( $updates );
    $total_pages  = (int) ceil( $total / $per_page );
    $offset       = ( $current_page - 1 ) * $per_page;
    $page_items   = array_slice( $updates, $offset, $per_page );
    ?>
    <form method="post" id="lgpo-sync-form">
        <?php wp_nonce_field( 'lgpo_execute_sync', '_lgpo_exec_nonce' ); ?>

        <div style="margin-bottom:10px;">
            <button type="button" id="lgpo-select-all" class="button">Select All on Page</button>
            <button type="button" id="lgpo-deselect-all" class="button">Deselect All</button>
            <span style="margin-left:15px;">Showing <?php echo min( $offset + 1, $total ); ?>-<?php echo min( $offset + $per_page, $total ); ?> of <?php echo $total; ?></span>
        </div>

        <?php foreach ( $page_items as $change ) :
            $change_id   = md5( json_encode( $change ) );
            $is_downgrade = ( $change['action'] ?? '' ) === 'downgrade';
            $border_color = $is_downgrade ? '#dc3232' : '#2271b1';
            $tag_label    = ( $change['action'] ?? '' ) === 'tag_only' ? ' (tag only)' : '';
        ?>
            <div style="padding:12px 15px; margin:6px 0; background:#f9f9f9; border-left:4px solid <?php echo $border_color; ?>; font-size:15px; line-height:1.6;">
                <label style="cursor:pointer; display:block;">
                    <input type="checkbox" class="lgpo-change-cb"
                           data-change-id="<?php echo esc_attr( $change_id ); ?>"
                           data-change-json="<?php echo esc_attr( json_encode( $change ) ); ?>">
                    <strong><?php echo esc_html( $change['email'] ); ?></strong>
                    <code><?php echo esc_html( $change['current_role'] ); ?></code> &rarr; <code><?php echo esc_html( $change['new_role'] ); ?></code><?php echo esc_html( $tag_label ); ?>
                    <?php if ( ! empty( $change['reason'] ) ) : ?>
                        <span style="color:#666;"> &mdash; <?php echo esc_html( $change['reason'] ); ?></span>
                    <?php endif; ?>
                </label>
            </div>
        <?php endforeach; ?>

        <?php if ( $total_pages > 1 ) : ?>
            <div style="margin:15px 0;">
                <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                    <?php if ( $p === $current_page ) : ?>
                        <strong><?php echo $p; ?></strong>
                    <?php else : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'syncpage', $p ) ); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <div id="lgpo-selection-summary" style="margin:15px 0; padding:12px; background:#f0f6fc; border-left:4px solid #2271b1; display:none;">
            <strong><span id="lgpo-sel-count">0</span> changes selected</strong>
        </div>

        <input type="hidden" name="lgpo_selected_changes" id="lgpo-selected-json" value="">
        <input type="submit" name="lgpo_execute_sync" id="lgpo-execute-btn" class="button button-primary button-large" value="Execute Selected Changes" disabled>
    </form>

    <script>
    (function() {
        var STORAGE_KEY = 'lgpo_sync_selections';

        function load() {
            try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; } catch(e) { return {}; }
        }
        function save(sel) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(sel)); } catch(e) {}
        }

        function updateUI() {
            var sel = load();
            var count = Object.keys(sel).length;
            var summary = document.getElementById('lgpo-selection-summary');
            var countEl = document.getElementById('lgpo-sel-count');
            var btn = document.getElementById('lgpo-execute-btn');
            var hidden = document.getElementById('lgpo-selected-json');

            countEl.textContent = count;
            summary.style.display = count > 0 ? 'block' : 'none';
            btn.disabled = count === 0;
            hidden.value = JSON.stringify(Object.values(sel));
        }

        // Init: restore checkbox state
        var sel = load();
        document.querySelectorAll('.lgpo-change-cb').forEach(function(cb) {
            if (sel[cb.dataset.changeId]) cb.checked = true;
        });
        updateUI();

        // Checkbox change
        document.addEventListener('change', function(e) {
            if (!e.target.classList.contains('lgpo-change-cb')) return;
            var sel = load();
            if (e.target.checked) {
                sel[e.target.dataset.changeId] = JSON.parse(e.target.dataset.changeJson);
            } else {
                delete sel[e.target.dataset.changeId];
            }
            save(sel);
            updateUI();
        });

        // Select All
        document.getElementById('lgpo-select-all').addEventListener('click', function(e) {
            e.preventDefault();
            var sel = load();
            document.querySelectorAll('.lgpo-change-cb').forEach(function(cb) {
                cb.checked = true;
                sel[cb.dataset.changeId] = JSON.parse(cb.dataset.changeJson);
            });
            save(sel);
            updateUI();
        });

        // Deselect All
        document.getElementById('lgpo-deselect-all').addEventListener('click', function(e) {
            e.preventDefault();
            localStorage.removeItem(STORAGE_KEY);
            document.querySelectorAll('.lgpo-change-cb').forEach(function(cb) { cb.checked = false; });
            updateUI();
        });

        // Submit confirmation
        document.getElementById('lgpo-sync-form').addEventListener('submit', function(e) {
            var count = Object.keys(load()).length;
            if (count === 0) { e.preventDefault(); alert('Select at least one change.'); return; }
            if (!confirm('Execute ' + count + ' selected changes? This cannot be undone.')) { e.preventDefault(); }
            // Clear localStorage after successful submit
            localStorage.removeItem(STORAGE_KEY);
        });
    })();
    </script>
    <?php
}

/**
 * Render the Skipped tab (read-only, no checkboxes).
 */
function lgpo_render_skipped_tab( array $skipped, string $email_filter ) {
    if ( $email_filter ) {
        $skipped = array_filter( $skipped, function ( $s ) use ( $email_filter ) {
            return stripos( $s['email'], $email_filter ) !== false;
        } );
        $skipped = array_values( $skipped );
    }

    if ( empty( $skipped ) ) {
        echo '<p><em>No skipped members.</em></p>';
        return;
    }

    $per_page     = 50;
    $current_page = isset( $_GET['syncpage'] ) ? max( 1, absint( $_GET['syncpage'] ) ) : 1;
    $total        = count( $skipped );
    $total_pages  = (int) ceil( $total / $per_page );
    $offset       = ( $current_page - 1 ) * $per_page;
    $page_items   = array_slice( $skipped, $offset, $per_page );

    foreach ( $page_items as $item ) {
        printf(
            '<div style="padding:8px 15px; margin:4px 0; background:#f9f9f9; border-left:4px solid #ddd; font-size:14px;"><strong>%s</strong> &mdash; %s</div>',
            esc_html( $item['email'] ),
            esc_html( $item['reason'] )
        );
    }

    if ( $total_pages > 1 ) {
        echo '<div style="margin:15px 0;">';
        for ( $p = 1; $p <= $total_pages; $p++ ) {
            if ( $p === $current_page ) {
                echo '<strong>' . $p . '</strong> ';
            } else {
                echo '<a href="' . esc_url( add_query_arg( 'syncpage', $p ) ) . '">' . $p . '</a> ';
            }
        }
        echo '</div>';
    }
}

/**
 * Render the changelog / revert section.
 */
function lgpo_render_changelog_section() {
    $batches = LGPO_Sync_Engine::get_batches();
    if ( empty( $batches ) ) {
        return;
    }
    ?>
    <div style="margin-top:20px; background:#fff; border:1px solid #ccd0d4; padding:20px;">
        <h3 style="margin-top:0;">Sync History (last 3 days)</h3>
        <table class="widefat striped" style="max-width:800px;">
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Date</th>
                    <th>Changes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $batches as $batch ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $batch['batch'] ); ?></code></td>
                        <td><?php echo esc_html( date( 'Y-m-d H:i:s', $batch['timestamp'] ) ); ?></td>
                        <td><?php echo esc_html( $batch['count'] ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'lgpo_revert_batch', '_lgpo_revert_nonce' ); ?>
                                <input type="hidden" name="lgpo_revert_batch_id" value="<?php echo esc_attr( $batch['batch'] ); ?>">
                                <input type="submit" name="lgpo_revert_batch" class="button button-small"
                                       value="Revert"
                                       onclick="return confirm('Revert <?php echo esc_attr( $batch['count'] ); ?> changes from this batch? All users will be restored to their previous roles.');">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
