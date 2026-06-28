<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Admin
 * Admin menu registration + Settings/Registry/Design/History pages.
 * The compose workflow lives in LG_WD_Compose.
 */
class LG_WD_Admin {

    const PAGE_SLUG = 'lg-weekly-digest';
    const CAP       = 'manage_options';

    public static function init(): void {
        add_action( 'admin_menu',             [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_init',             [ __CLASS__, 'handle_form_post' ] );
        add_action( 'wp_ajax_lg_wd_save',     [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_lg_wd_registry_add',     [ __CLASS__, 'ajax_registry_add' ] );
        add_action( 'wp_ajax_lg_wd_registry_remove',  [ __CLASS__, 'ajax_registry_remove' ] );
        add_action( 'wp_ajax_lg_wd_registry_update',  [ __CLASS__, 'ajax_registry_update' ] );
        add_action( 'wp_ajax_lg_wd_registry_reorder', [ __CLASS__, 'ajax_registry_reorder' ] );
        add_action( 'admin_notices',          [ __CLASS__, 'admin_notices' ] );
    }

    // ── Menu ─────────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_menu_page(
            'Weekly Digest',
            'Weekly Digest',
            self::CAP,
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ],
            'dashicons-email-alt',
            30
        );

        // First submenu replaces the top-level duplicate
        add_submenu_page(
            self::PAGE_SLUG,
            'Settings',
            'Settings',
            self::CAP,
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );

        add_submenu_page(
            self::PAGE_SLUG,
            'Compose',
            'Compose',
            self::CAP,
            LG_WD_Compose::PAGE_SLUG,
            [ 'LG_WD_Compose', 'render_page' ]
        );

        add_submenu_page(
            self::PAGE_SLUG,
            'All Issues',
            'All Issues',
            self::CAP,
            'edit.php?post_type=' . LG_WD_Issue::POST_TYPE
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) return;

        wp_enqueue_media();

        wp_enqueue_style(
            'lg-wd-admin',
            LG_WD_PLUGIN_URL . 'assets/admin.css',
            [],
            LG_WD_VERSION
        );

        wp_enqueue_script(
            'lg-wd-admin',
            LG_WD_PLUGIN_URL . 'assets/admin.js',
            [ 'jquery', 'jquery-ui-sortable' ],
            LG_WD_VERSION,
            true
        );

        wp_localize_script( 'lg-wd-admin', 'lgWD', [
            'nonce'     => wp_create_nonce( 'lg_wd_admin' ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'adminEmail'=> get_option( 'admin_email' ),
        ] );
    }

    // ── Notices ───────────────────────────────────────────────────────────────

    public static function admin_notices(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, self::PAGE_SLUG ) === false ) return;

        if ( ! class_exists( 'FluentCrm\App\Models\Campaign' ) ) {
            echo '<div class="notice notice-warning"><p><strong>LG Weekly Digest:</strong> FluentCRM not detected. Will fall back to wp_mail for sends.</p></div>';
        }
    }

    // ── Main page render ─────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( 'Unauthorized' );

        $settings    = LG_WD_Settings::get_all();
        $next_send   = LG_WD_Cron::next_send_label();
        $history     = array_reverse( get_option( 'lg_wd_send_history', [] ) );
        $active_tab  = sanitize_key( $_GET['tab'] ?? 'settings' );

        $tabs = [
            'settings' => 'Settings',
            'registry' => 'CPT Registry',
            'design'   => 'Email Design',
            'history'  => 'Send History',
        ];
        ?>
        <div class="wrap lg-wd-wrap">

          <?php if ( ! empty( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
          <?php endif; ?>

          <div class="lg-wd-page-header">
            <div>
              <h1 class="lg-wd-title">Weekly Digest Settings</h1>
              <p class="lg-wd-subtitle">
                Sender: <strong><?php echo esc_html( LG_WD_Sender::get_sender()->get_label() ); ?></strong>
              </p>
            </div>
            <div class="lg-wd-header-actions">
              <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LG_WD_Compose::PAGE_SLUG ) ); ?>"
                 class="button button-primary lg-wd-btn-primary">Go to Compose</a>
              <button type="submit" class="button lg-wd-btn-secondary" id="lg-wd-save-btn">Save Changes</button>
            </div>
          </div>

          <!-- Status Bar -->
          <div class="lg-wd-status-bar">
            <div class="lg-wd-status-item">
              <span class="lg-wd-dot <?php echo $settings['enabled'] ? 'green' : 'off'; ?>"></span>
              Digest is <strong><?php echo $settings['enabled'] ? 'Active' : 'Paused'; ?></strong>
            </div>
            <div class="lg-wd-status-divider"></div>
            <div class="lg-wd-status-item">
              <span class="lg-wd-dot gold"></span>
              Next send: <strong><?php echo esc_html( $next_send ); ?></strong>
            </div>
            <div class="lg-wd-status-divider"></div>
            <div class="lg-wd-status-item">
              <?php
              $last = ! empty( $history ) ? $history[0] : null;
              echo $last
                  ? 'Last sent: <strong>' . esc_html( date( 'M j, Y', strtotime( $last['sent_at'] ) ) ) . '</strong>'
                  : '<span style="color:#aaa;">No sends yet</span>';
              ?>
            </div>
          </div>

          <!-- Response area -->
          <div id="lg-wd-response" style="display:none;" class="lg-wd-response"></div>

          <!-- Tabs -->
          <nav class="nav-tab-wrapper lg-wd-tabs">
            <?php foreach ( $tabs as $slug => $label ) : ?>
              <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=<?php echo $slug; ?>"
                 class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html( $label ); ?>
              </a>
            <?php endforeach; ?>
          </nav>

          <form id="lg-wd-form" method="post" action="">
            <?php wp_nonce_field( 'lg_wd_admin', 'lg_wd_nonce' ); ?>
            <input type="hidden" name="lg_wd_form_save" value="1">

            <?php
            switch ( $active_tab ) {
                case 'registry': self::render_tab_registry(); break;
                case 'design':   self::render_tab_design( $settings ); break;
                case 'history':  self::render_tab_history( $history ); break;
                default:         self::render_tab_settings( $settings ); break;
            }
            ?>
          </form>

        </div>
        <?php
    }

    // ── Tab: Settings ─────────────────────────────────────────────────────────

    private static function render_tab_settings( array $s ): void { ?>
        <input type="hidden" name="_active_tab" value="settings">
        <div class="lg-wd-grid lg-wd-grid-2">

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Schedule</h3></div>
            <div class="lg-wd-card-body">

              <div class="lg-wd-form-row">
                <label class="lg-wd-label">Digest Enabled</label>
                <label class="lg-wd-toggle">
                  <input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>>
                  <span class="lg-wd-toggle-track"></span>
                </label>
              </div>

              <div class="lg-wd-grid lg-wd-grid-2">
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">Send Day</label>
                  <select name="send_day" class="lg-wd-select">
                    <?php foreach ( [ 'monday','tuesday','wednesday','thursday','friday','saturday','sunday' ] as $d ) : ?>
                      <option value="<?php echo $d; ?>" <?php selected( $s['send_day'], $d ); ?>>
                        <?php echo ucfirst( $d ); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">Send Time <span class="lg-wd-hint">(ET)</span></label>
                  <input type="time" name="send_time" value="<?php echo esc_attr( $s['send_time'] ); ?>"
                         class="lg-wd-input">
                </div>
              </div>

              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Subject Line Template</label>
                <input type="text" name="subject_template"
                       value="<?php echo esc_attr( $s['subject_template'] ); ?>"
                       class="lg-wd-input">
                <p class="lg-wd-hint">Tokens: <code>{{week_date}}</code> <code>{{site_name}}</code> <code>{{item_count}}</code></p>
              </div>

            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Display Options</h3></div>
            <div class="lg-wd-card-body">
              <?php
              $toggles = [
                  'show_excerpts'   => [ 'Show Excerpts',         'Append post excerpt below each title' ],
                  'show_thumbnails' => [ 'Show Featured Images',  'Thumbnail alongside each post item' ],
                  'skip_empty'      => [ 'Skip Empty Sections',   'Hide sections that have 0 content items' ],
              ];
              foreach ( $toggles as $key => [ $label, $hint ] ) : ?>
                <div class="lg-wd-toggle-row">
                  <div>
                    <div class="lg-wd-toggle-label"><?php echo esc_html( $label ); ?></div>
                    <div class="lg-wd-hint"><?php echo esc_html( $hint ); ?></div>
                  </div>
                  <label class="lg-wd-toggle">
                    <input type="checkbox" name="<?php echo $key; ?>" value="1" <?php checked( $s[ $key ] ); ?>>
                    <span class="lg-wd-toggle-track"></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>From Details</h3></div>
            <div class="lg-wd-card-body">
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">From Name</label>
                <input type="text" name="from_name"
                       value="<?php echo esc_attr( $s['from_name'] ); ?>"
                       class="lg-wd-input">
              </div>
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">From Email</label>
                <input type="email" name="from_email"
                       value="<?php echo esc_attr( $s['from_email'] ); ?>"
                       class="lg-wd-input">
              </div>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>FluentCRM Targeting</h3></div>
            <div class="lg-wd-card-body">
              <div class="lg-wd-grid lg-wd-grid-2">
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">List ID</label>
                  <input type="number" name="fcrm_list_id"
                         value="<?php echo absint( $s['fcrm_list_id'] ?? 3 ); ?>"
                         class="lg-wd-input" min="1">
                  <p class="lg-wd-hint">FluentCRM list that receives the digest.</p>
                </div>
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">Tag</label>
                  <input type="text" name="fcrm_tag"
                         value="<?php echo esc_attr( $s['fcrm_tag'] ?? 'all' ); ?>"
                         class="lg-wd-input">
                  <p class="lg-wd-hint">FluentCRM tag filter. Use <code>all</code> for all subscribers.</p>
                </div>
              </div>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Automation</h3></div>
            <div class="lg-wd-card-body">

              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Cron Behavior</label>
                <select name="cron_mode" class="lg-wd-select">
                  <option value="auto_send" <?php selected( $s['cron_mode'] ?? 'auto_send', 'auto_send' ); ?>>Auto Send</option>
                  <option value="draft_and_notify" <?php selected( $s['cron_mode'] ?? 'auto_send', 'draft_and_notify' ); ?>>Draft &amp; Notify</option>
                </select>
                <p class="lg-wd-hint"><strong>Auto Send</strong> fires immediately. <strong>Draft &amp; Notify</strong> creates a draft and emails you a review link.</p>
              </div>

              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Notification Email</label>
                <input type="email" name="review_notify_email"
                       value="<?php echo esc_attr( $s['review_notify_email'] ?? '' ); ?>"
                       class="lg-wd-input"
                       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                <p class="lg-wd-hint">Used in Draft &amp; Notify mode. Defaults to site admin email if empty.</p>
              </div>

              <div class="lg-wd-toggle-row">
                <div>
                  <div class="lg-wd-toggle-label">Content Fallback</div>
                  <div class="lg-wd-hint">If a section has 0 posts in the date range, pull the most recent posts regardless of date.</div>
                </div>
                <label class="lg-wd-toggle">
                  <input type="checkbox" name="fallback_enabled" value="1" <?php checked( $s['fallback_enabled'] ?? true ); ?>>
                  <span class="lg-wd-toggle-track"></span>
                </label>
              </div>

            </div>
          </div>

        </div>
        <p style="margin-top:16px;text-align:right;">
          <button type="submit" class="button button-primary lg-wd-btn-primary">Save Settings</button>
        </p>
    <?php }

    // ── Tab: CPT Registry ────────────────────────────────────────────────────

    private static function render_tab_registry(): void {
        $registry = LG_WD_CPT_Registry::get_all_with_overrides();
        $wp_cpts  = LG_WD_CPT_Registry::get_available_post_types();
        ?>
        <div class="lg-wd-card">
          <div class="lg-wd-card-header">
            <h3>Registered Content Types</h3>
            <span class="lg-wd-hint">These appear as available sections when composing an email.</span>
          </div>
          <div class="lg-wd-card-body">
            <?php if ( empty( $registry ) ) : ?>
              <p style="color:#aaa;font-style:italic;">No content types registered yet. Add one below.</p>
            <?php else : ?>
            <table class="widefat striped" id="lg-wd-registry-table">
              <thead>
                <tr>
                  <th style="width:20px;"></th>
                  <th>Label</th>
                  <th>Type</th>
                  <th>Slug</th>
                  <th>Template</th>
                  <th>Sort</th>
                  <th>Tag Filter</th>
                  <th>Max</th>
                  <th>Enabled</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="lg-wd-registry-sortable">
                <?php foreach ( $registry as $entry ) :
                    $slug = esc_attr( $entry['slug'] );
                    $tmpl = $entry['template'] ?? 'card';
                    $sort = $entry['sort_mode'] ?? 'newest';
                ?>
                    <?php $is_header = ! empty( $entry['is_header'] ); ?>
                    <tr data-slug="<?php echo $slug; ?>"
                        data-label="<?php echo esc_attr( $entry['label'] ); ?>"
                        data-is-header="<?php echo $is_header ? '1' : '0'; ?>"
                        data-template="<?php echo esc_attr( $tmpl ); ?>"
                        data-sort-mode="<?php echo esc_attr( $sort ); ?>"
                        data-tag-filter="<?php echo esc_attr( $entry['tag_filter'] ?? '' ); ?>"
                        data-tag-taxonomy="<?php echo esc_attr( $entry['tag_taxonomy'] ?? 'post_tag' ); ?>"
                        data-max-items="<?php echo (int) $entry['max_items']; ?>"
                        data-excerpt-length="<?php echo (int) ( $entry['excerpt_length'] ?? 20 ); ?>"
                        data-enabled="<?php echo $entry['enabled'] ? '1' : '0'; ?>"
                        <?php if ( $is_header ) echo 'style="background:#FFF8E8;"'; ?>>
                      <td class="lg-wd-drag-handle" style="cursor:grab;text-align:center;color:#aaa;" title="Drag to reorder">☰</td>
                      <td class="lg-wd-reg-label">
                        <?php if ( $is_header ) : ?>
                          <strong style="color:#2B2318;"><?php echo esc_html( $entry['label'] ); ?></strong>
                        <?php else : ?>
                          <?php echo esc_html( $entry['label'] ); ?>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ( $is_header ) : ?>
                          <span style="color:#ECB351;font-weight:600;font-size:11px;">📌 GROUP HEADER</span>
                        <?php elseif ( str_starts_with( $entry['slug'], '_all' ) ) : ?>
                          <span style="color:#87986A;font-size:11px;">All Types</span>
                        <?php else : ?>
                          <span style="color:#aaa;font-size:11px;">CPT</span>
                        <?php endif; ?>
                      </td>
                      <td><code><?php echo esc_html( $entry['slug'] ); ?></code></td>
                      <td class="lg-wd-reg-template"><?php echo $is_header ? '<span style="color:#aaa;">—</span>' : esc_html( LG_WD_CPT_Registry::TEMPLATES[ $tmpl ] ?? $tmpl ); ?></td>
                      <td class="lg-wd-reg-sort"><?php echo $is_header ? '<span style="color:#aaa;">—</span>' : ( $sort === 'upcoming' ? 'Upcoming' : 'Newest' ); ?></td>
                      <td class="lg-wd-reg-tag">
                        <?php if ( $is_header ) : ?>
                          <span style="color:#aaa;">—</span>
                        <?php elseif ( ! empty( $entry['tag_filter'] ) ) : ?>
                          <code><?php echo esc_html( $entry['tag_filter'] ); ?></code>
                          <span style="color:#aaa;font-size:11px;">(<?php echo esc_html( $entry['tag_taxonomy'] ?? 'post_tag' ); ?>)</span>
                        <?php else : ?>
                          <span style="color:#aaa;">—</span>
                        <?php endif; ?>
                      </td>
                      <td class="lg-wd-reg-max"><?php echo $is_header ? '<span style="color:#aaa;">—</span>' : (int) $entry['max_items']; ?></td>
                      <td class="lg-wd-reg-enabled"><?php echo $entry['enabled'] ? 'Yes' : '<span style="color:#aaa;">No</span>'; ?></td>
                      <td style="white-space:nowrap;">
                        <button class="button button-small lg-wd-registry-edit" data-slug="<?php echo $slug; ?>">Edit</button>
                        <button class="button button-small lg-wd-registry-remove" data-slug="<?php echo $slug; ?>" style="color:#d63638;">Remove</button>
                      </td>
                    </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;">
              <h4 style="margin:0 0 12px;">Add New Content Type</h4>
              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-items:end;">
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Post Type</label>
                  <select id="lg-wd-reg-slug" class="lg-wd-select">
                    <option value="">— Select —</option>
                    <option value="_header">📌 Group Header (divider)</option>
                    <option value="_all">⭐ All Post Types (tag-based)</option>
                    <?php foreach ( $wp_cpts as $slug => $label ) : ?>
                      <option value="<?php echo esc_attr( $slug ); ?>">
                        <?php echo esc_html( $label ); ?> (<?php echo esc_html( $slug ); ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Display Label</label>
                  <input type="text" id="lg-wd-reg-label" class="lg-wd-input" placeholder="e.g. Videos">
                </div>
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Template</label>
                  <select id="lg-wd-reg-template" class="lg-wd-select">
                    <?php foreach ( LG_WD_CPT_Registry::TEMPLATES as $slug => $name ) : ?>
                      <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Sort Mode</label>
                  <select id="lg-wd-reg-sort" class="lg-wd-select">
                    <option value="newest">Newest first</option>
                    <option value="upcoming">Upcoming (events)</option>
                  </select>
                </div>
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Tag Filter <span class="lg-wd-hint">(optional)</span></label>
                  <input type="text" id="lg-wd-reg-tag" class="lg-wd-input" placeholder="e.g. weeklyyes">
                </div>
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Tag Taxonomy</label>
                  <input type="text" id="lg-wd-reg-taxonomy" class="lg-wd-input" value="post_tag" placeholder="post_tag or topic-tag">
                </div>
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Max Items</label>
                  <input type="number" id="lg-wd-reg-max" class="lg-wd-input" value="5" min="1" max="20" style="width:60px;">
                </div>
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Excerpt Words</label>
                  <input type="number" id="lg-wd-reg-excerpt-length" class="lg-wd-input" value="20" min="0" max="100" style="width:60px;">
                </div>
                <div style="padding-bottom:2px;">
                  <button class="button button-primary" id="lg-wd-reg-add-btn">+ Add</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Edit Modal -->
        <div id="lg-wd-reg-edit-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100050;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:8px;padding:24px 28px;max-width:480px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2);">
            <h3 style="margin:0 0 16px;">Edit Content Type</h3>
            <input type="hidden" id="lg-wd-edit-slug">
            <input type="hidden" id="lg-wd-edit-is-header" value="0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div class="lg-wd-form-group" style="margin:0;">
                <label class="lg-wd-label">Display Label</label>
                <input type="text" id="lg-wd-edit-label" class="lg-wd-input">
              </div>
              <div class="lg-wd-form-group lg-wd-edit-cpt-only" style="margin:0;">
                <label class="lg-wd-label">Template</label>
                <select id="lg-wd-edit-template" class="lg-wd-select">
                  <?php foreach ( LG_WD_CPT_Registry::TEMPLATES as $tslug => $tname ) : ?>
                    <option value="<?php echo esc_attr( $tslug ); ?>"><?php echo esc_html( $tname ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="lg-wd-form-group lg-wd-edit-cpt-only" style="margin:0;">
                <label class="lg-wd-label">Sort Mode</label>
                <select id="lg-wd-edit-sort" class="lg-wd-select">
                  <option value="newest">Newest first</option>
                  <option value="upcoming">Upcoming (events)</option>
                </select>
              </div>
              <div class="lg-wd-form-group lg-wd-edit-cpt-only" style="margin:0;">
                <label class="lg-wd-label">Max Items</label>
                <input type="number" id="lg-wd-edit-max" class="lg-wd-input" min="1" max="50">
              </div>
              <div class="lg-wd-form-group lg-wd-edit-cpt-only" style="margin:0;">
                <label class="lg-wd-label">Excerpt Words</label>
                <input type="number" id="lg-wd-edit-excerpt-length" class="lg-wd-input" min="0" max="100">
              </div>
              <div class="lg-wd-form-group lg-wd-edit-cpt-only" style="margin:0;">
                <label class="lg-wd-label">Tag Filter</label>
                <input type="text" id="lg-wd-edit-tag" class="lg-wd-input" placeholder="e.g. weeklyyes">
              </div>
              <div class="lg-wd-form-group lg-wd-edit-cpt-only" style="margin:0;">
                <label class="lg-wd-label">Tag Taxonomy</label>
                <input type="text" id="lg-wd-edit-taxonomy" class="lg-wd-input" placeholder="post_tag">
              </div>
              <div class="lg-wd-form-group" style="margin:0;">
                <label class="lg-wd-label">Enabled</label>
                <select id="lg-wd-edit-enabled" class="lg-wd-select">
                  <option value="1">Yes</option>
                  <option value="0">No</option>
                </select>
              </div>
            </div>
            <div style="margin-top:18px;display:flex;gap:8px;justify-content:flex-end;">
              <button class="button" id="lg-wd-edit-cancel">Cancel</button>
              <button class="button button-primary" id="lg-wd-edit-save">Save Changes</button>
            </div>
          </div>
        </div>
    <?php }

    // ── Tab: Email Design ─────────────────────────────────────────────────────

    private static function render_tab_design( array $s ): void { ?>
        <input type="hidden" name="_active_tab" value="design">
        <div class="lg-wd-grid lg-wd-grid-2">
          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Header Image</h3></div>
            <div class="lg-wd-card-body">
              <?php if ( ! empty( $s['header_image_url'] ) ) : ?>
                <div class="lg-wd-current-img">
                  <img src="<?php echo esc_url( $s['header_image_url'] ); ?>"
                       style="max-height:60px;max-width:100%;display:block;">
                  <button type="button" class="button button-small" id="lg-wd-remove-img">Remove</button>
                </div>
              <?php endif; ?>
              <input type="hidden" name="header_image_url" id="lg-wd-header-img-url"
                     value="<?php echo esc_attr( $s['header_image_url'] ); ?>">
              <button type="button" class="button" id="lg-wd-choose-img">
                <?php echo empty( $s['header_image_url'] ) ? 'Choose Header Image' : 'Change Image'; ?>
              </button>
              <p class="lg-wd-hint">Recommended width: 600px. Leave empty to use text logo.</p>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Sign-off Message</h3></div>
            <div class="lg-wd-card-body">
              <textarea name="signoff" rows="4" class="lg-wd-textarea"><?php
                echo esc_textarea( $s['signoff'] );
              ?></textarea>
              <p class="lg-wd-hint">Appears at the bottom of every digest above the unsubscribe link.</p>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Intro Text</h3></div>
            <div class="lg-wd-card-body">
              <textarea name="intro_text" rows="3" class="lg-wd-textarea"><?php
                echo esc_textarea( $s['intro_text'] ?? '' );
              ?></textarea>
              <p class="lg-wd-hint">Appears at the top of every digest below the gold band.</p>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Branding Tagline</h3></div>
            <div class="lg-wd-card-body">
              <input type="text" name="branding_tagline"
                     value="<?php echo esc_attr( $s['branding_tagline'] ?? '' ); ?>"
                     class="lg-wd-input"
                     placeholder="Guitar Repair & Restoration Community">
              <p class="lg-wd-hint">Shown below the logo text in the email header.</p>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>Footer Links</h3></div>
            <div class="lg-wd-card-body">
              <input type="hidden" name="footer_links" id="lg-wd-footer-links-json"
                     value="<?php echo esc_attr( $s['footer_links'] ?? '[]' ); ?>">
              <div id="lg-wd-footer-links-list"></div>
              <button type="button" class="button" id="lg-wd-footer-link-add">+ Add Link</button>
              <p class="lg-wd-hint">Links shown in the email footer. Leave empty to use defaults (Website, Forum, Events, Videos).</p>
            </div>
          </div>

          <div class="lg-wd-card">
            <div class="lg-wd-card-header"><h3>UTM Tracking</h3></div>
            <div class="lg-wd-card-body">
              <div class="lg-wd-toggle-row" style="margin-bottom:14px;">
                <div>
                  <div class="lg-wd-toggle-label">Enable UTM Parameters</div>
                  <div class="lg-wd-hint">Append tracking parameters to all email links.</div>
                </div>
                <label class="lg-wd-toggle">
                  <input type="checkbox" name="utm_enabled" value="1" <?php checked( $s['utm_enabled'] ?? false ); ?>>
                  <span class="lg-wd-toggle-track"></span>
                </label>
              </div>
              <div id="lg-wd-utm-fields" style="<?php echo empty( $s['utm_enabled'] ) ? 'display:none;' : ''; ?>">
                <div class="lg-wd-grid lg-wd-grid-2" style="gap:10px;">
                  <div class="lg-wd-form-group">
                    <label class="lg-wd-label">Source</label>
                    <input type="text" name="utm_source"
                           value="<?php echo esc_attr( $s['utm_source'] ?? 'weekly-digest' ); ?>"
                           class="lg-wd-input" placeholder="weekly-digest">
                  </div>
                  <div class="lg-wd-form-group">
                    <label class="lg-wd-label">Medium</label>
                    <input type="text" name="utm_medium"
                           value="<?php echo esc_attr( $s['utm_medium'] ?? 'email' ); ?>"
                           class="lg-wd-input" placeholder="email">
                  </div>
                </div>
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">Campaign</label>
                  <input type="text" name="utm_campaign"
                         value="<?php echo esc_attr( $s['utm_campaign'] ?? '{{week_date}}' ); ?>"
                         class="lg-wd-input" placeholder="{{week_date}}">
                  <p class="lg-wd-hint">Supports <code>{{week_date}}</code> token.</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <p style="margin-top:16px;text-align:right;">
          <button type="submit" class="button button-primary lg-wd-btn-primary">Save Design Settings</button>
        </p>
    <?php }

    // ── Tab: Send History ─────────────────────────────────────────────────────

    private static function render_tab_history( array $history ): void { ?>
        <div class="lg-wd-card">
          <div class="lg-wd-card-header"><h3>Send History</h3></div>
          <div class="lg-wd-card-body">
            <?php if ( empty( $history ) ) : ?>
              <p style="color:#aaa;">No sends recorded yet.</p>
            <?php else : ?>
              <table class="widefat striped">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Title</th>
                    <th>Campaign ID</th>
                    <th>Result</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ( $history as $row ) : ?>
                    <tr>
                      <td><?php echo esc_html( date( 'M j, Y g:i A', strtotime( $row['sent_at'] ) ) ); ?></td>
                      <td><?php echo esc_html( $row['title'] ); ?></td>
                      <td>
                        <?php if ( ! empty( $row['campaign_id'] ) ) : ?>
                          <a href="<?php echo esc_url( admin_url( 'admin.php?page=fluentcrm-admin#/campaigns/' . $row['campaign_id'] ) ); ?>">
                            #<?php echo (int) $row['campaign_id']; ?>
                          </a>
                        <?php else : ?>—<?php endif; ?>
                      </td>
                      <td><?php echo esc_html( $row['message'] ?? '' ); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
    <?php }

    // ── Save logic (shared by AJAX and form POST) ────────────────────────────

    private static function save_settings_from_raw( array $raw ): void {
        $data = [];

        $text_fields = [
            'send_day'         => 'sanitize_key',
            'send_time'        => 'sanitize_text_field',
            'header_image_url' => 'esc_url_raw',
            'from_name'        => 'sanitize_text_field',
            'from_email'       => 'sanitize_email',
            'subject_template' => 'sanitize_text_field',
            'signoff'          => 'sanitize_textarea_field',
            'fcrm_list_id'     => 'absint',
            'fcrm_tag'         => 'sanitize_text_field',
            'review_notify_email' => 'sanitize_email',
            'intro_text'       => 'sanitize_textarea_field',
            'branding_tagline' => 'sanitize_text_field',
            'utm_source'       => 'sanitize_text_field',
            'utm_medium'       => 'sanitize_text_field',
            'utm_campaign'     => 'sanitize_text_field',
        ];

        foreach ( $text_fields as $field => $sanitizer ) {
            if ( isset( $raw[ $field ] ) ) {
                // wp_unslash first: WordPress adds magic quotes to $_POST
                $data[ $field ] = $sanitizer( wp_unslash( $raw[ $field ] ) );
            }
        }

        if ( isset( $raw['cron_mode'] ) ) {
            $data['cron_mode'] = in_array( $raw['cron_mode'], [ 'auto_send', 'draft_and_notify' ], true )
                ? $raw['cron_mode'] : 'auto_send';
        }

        // Checkboxes: use _active_tab to know which tab's checkboxes to handle
        $tab = sanitize_key( $raw['_active_tab'] ?? '' );
        $tab_checkboxes = [
            'settings' => [ 'enabled', 'show_excerpts', 'show_thumbnails', 'skip_empty', 'fallback_enabled' ],
            'design'   => [ 'utm_enabled' ],
        ];
        foreach ( $tab_checkboxes[ $tab ] ?? [] as $cb ) {
            $data[ $cb ] = ! empty( $raw[ $cb ] );
        }

        if ( isset( $raw['footer_links'] ) ) {
            $footer_links_raw = stripslashes( $raw['footer_links'] );
            $footer_links_arr = json_decode( $footer_links_raw, true );
            $data['footer_links'] = is_array( $footer_links_arr ) ? $footer_links_raw : '[]';
        }

        LG_WD_Settings::save( $data );
        do_action( 'lg_wd_settings_saved' );
    }

    // ── AJAX: Save settings ──────────────────────────────────────────────────

    public static function ajax_save(): void {
        check_ajax_referer( 'lg_wd_admin', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        self::save_settings_from_raw( $_POST );

        wp_send_json_success( [ 'message' => 'Settings saved. Cron rescheduled.' ] );
    }

    // ── Standard form POST save (runs on admin_init, before headers) ────────

    public static function handle_form_post(): void {
        if ( ! isset( $_POST['lg_wd_form_save'] ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;
        if ( ! check_admin_referer( 'lg_wd_admin', 'lg_wd_nonce' ) ) return;

        self::save_settings_from_raw( $_POST );

        $tab = sanitize_key( $_POST['_active_tab'] ?? 'settings' );
        wp_safe_redirect( add_query_arg( [
            'page'  => self::PAGE_SLUG,
            'tab'   => $tab,
            'saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── AJAX: Registry add ──────────────────────────────────────────────────

    public static function ajax_registry_add(): void {
        check_ajax_referer( 'lg_wd_admin', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $raw_slug  = sanitize_key( $_POST['slug'] ?? '' );
        $is_header = false;

        // "_header" = group header divider; generate unique slug from label
        if ( $raw_slug === '_header' ) {
            $is_header  = true;
            $label_slug = sanitize_key( $_POST['label'] ?? 'header' );
            $raw_slug   = '_header_' . ( $label_slug ?: 'section' );
            $base = $raw_slug;
            $i = 2;
            while ( LG_WD_CPT_Registry::get_by_slug( $raw_slug ) ) {
                $raw_slug = $base . '_' . $i++;
            }
        }
        // "_all" = cross-type section; generate unique slug from label
        elseif ( $raw_slug === '_all' ) {
            $label_slug = sanitize_key( $_POST['label'] ?? 'picks' );
            $raw_slug   = '_all_' . ( $label_slug ?: 'section' );
            $base = $raw_slug;
            $i = 2;
            while ( LG_WD_CPT_Registry::get_by_slug( $raw_slug ) ) {
                $raw_slug = $base . '_' . $i++;
            }
        }

        $entry = [
            'slug'           => $raw_slug,
            'label'          => sanitize_text_field( $_POST['label'] ?? '' ),
            'is_header'      => $is_header,
            'max_items'      => absint( $_POST['max_items'] ?? 5 ),
            'enabled'        => true,
            'template'       => sanitize_key( $_POST['template'] ?? 'card' ),
            'tag_filter'     => sanitize_text_field( $_POST['tag_filter'] ?? '' ),
            'tag_taxonomy'   => sanitize_key( $_POST['tag_taxonomy'] ?? 'post_tag' ),
            'sort_mode'      => in_array( $_POST['sort_mode'] ?? '', [ 'newest', 'upcoming' ], true ) ? $_POST['sort_mode'] : 'newest',
            'excerpt_length' => absint( $_POST['excerpt_length'] ?? 20 ),
        ];

        if ( LG_WD_CPT_Registry::add( $entry ) ) {
            wp_send_json_success( [ 'message' => 'Content type registered.' ] );
        } else {
            wp_send_json_error( 'Failed to add. Slug may already exist or be empty.' );
        }
    }

    // ── AJAX: Registry remove ───────────────────────────────────────────────

    public static function ajax_registry_remove(): void {
        check_ajax_referer( 'lg_wd_admin', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $slug = sanitize_key( $_POST['slug'] ?? '' );

        if ( LG_WD_CPT_Registry::remove( $slug ) ) {
            wp_send_json_success( [ 'message' => 'Content type removed.' ] );
        } else {
            wp_send_json_error( 'Cannot remove built-in type or slug not found.' );
        }
    }

    // ── AJAX: Registry update ──────────────────────────────────────────────

    public static function ajax_registry_update(): void {
        check_ajax_referer( 'lg_wd_admin', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $slug = sanitize_key( $_POST['slug'] ?? '' );
        if ( ! $slug ) wp_send_json_error( 'Missing slug.' );

        $fields = [];
        if ( isset( $_POST['label'] ) )        $fields['label']        = sanitize_text_field( $_POST['label'] );
        if ( isset( $_POST['template'] ) )     $fields['template']     = sanitize_key( $_POST['template'] );
        if ( isset( $_POST['sort_mode'] ) )    $fields['sort_mode']    = sanitize_key( $_POST['sort_mode'] );
        if ( isset( $_POST['tag_filter'] ) )   $fields['tag_filter']   = sanitize_text_field( $_POST['tag_filter'] );
        if ( isset( $_POST['tag_taxonomy'] ) ) $fields['tag_taxonomy'] = sanitize_key( $_POST['tag_taxonomy'] );
        if ( isset( $_POST['max_items'] ) )      $fields['max_items']      = absint( $_POST['max_items'] );
        if ( isset( $_POST['excerpt_length'] ) ) $fields['excerpt_length'] = absint( $_POST['excerpt_length'] );
        if ( isset( $_POST['enabled'] ) )      $fields['enabled']        = $_POST['enabled'] === '1';

        if ( LG_WD_CPT_Registry::update( $slug, $fields ) ) {
            wp_send_json_success( [ 'message' => 'Content type updated.' ] );
        } else {
            wp_send_json_error( 'Slug not found.' );
        }
    }

    // ── AJAX: Registry reorder ─────────────────────────────────────────────

    public static function ajax_registry_reorder(): void {
        check_ajax_referer( 'lg_wd_admin', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $order = $_POST['order'] ?? [];
        if ( ! is_array( $order ) ) wp_send_json_error( 'Invalid order data.' );

        $slugs = array_map( 'sanitize_key', $order );
        LG_WD_CPT_Registry::reorder( $slugs );

        wp_send_json_success( [ 'message' => 'Order saved.' ] );
    }
}
