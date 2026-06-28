<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Compose
 * Dedicated admin page for composing, previewing, and sending a weekly email issue.
 */
class LG_WD_Compose {

    const PAGE_SLUG = 'lg-wd-compose';
    const CAP       = 'manage_options';

    public static function init(): void {
        add_action( 'wp_ajax_lg_wd_compose_save',        [ __CLASS__, 'ajax_save' ] );
        add_action( 'wp_ajax_lg_wd_compose_populate',    [ __CLASS__, 'ajax_populate' ] );
        add_action( 'wp_ajax_lg_wd_compose_search',      [ __CLASS__, 'ajax_search' ] );
        add_action( 'wp_ajax_lg_wd_compose_preview',     [ __CLASS__, 'ajax_preview' ] );
        add_action( 'wp_ajax_lg_wd_compose_test_send',   [ __CLASS__, 'ajax_test_send' ] );
        add_action( 'wp_ajax_lg_wd_compose_send',        [ __CLASS__, 'ajax_send' ] );
        add_action( 'wp_ajax_lg_wd_compose_new_issue',   [ __CLASS__, 'ajax_new_issue' ] );
        add_action( 'wp_ajax_lg_wd_compose_delete_draft', [ __CLASS__, 'ajax_delete_draft' ] );
        add_action( 'wp_ajax_lg_wd_compose_reset_draft',  [ __CLASS__, 'ajax_reset_draft' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets(): void {
        wp_enqueue_media();
        wp_enqueue_editor();

        wp_enqueue_style(
            'lg-wd-admin',
            LG_WD_PLUGIN_URL . 'assets/admin.css',
            [],
            LG_WD_VERSION
        );

        wp_enqueue_script(
            'lg-wd-compose',
            LG_WD_PLUGIN_URL . 'assets/compose.js',
            [ 'jquery', 'jquery-ui-sortable', 'editor' ],
            LG_WD_VERSION,
            true
        );

        wp_localize_script( 'lg-wd-compose', 'lgWDCompose', [
            'nonce'   => wp_create_nonce( 'lg_wd_compose' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    // ── Page render ──────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( 'Unauthorized' );

        self::enqueue_assets();

        // Determine which issue to load
        $issue_id = absint( $_GET['issue_id'] ?? 0 );

        if ( ! $issue_id ) {
            $issue_id = LG_WD_Issue::get_latest_draft();
        }

        $issue_data = $issue_id ? LG_WD_Issue::get_data( $issue_id ) : null;
        $issue_title = $issue_id ? get_the_title( $issue_id ) : '';
        $is_sent    = $issue_data && $issue_data['status'] === 'sent';

        // Available sections from registry for "Add Section" dropdown
        $registry = LG_WD_CPT_Registry::get_all_with_overrides();

        // All existing issues for the issue picker
        $all_issues = LG_WD_Issue::get_all_issues();
        $compose_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        ?>
        <div class="wrap lg-wd-wrap">

          <div class="lg-wd-page-header">
            <div>
              <h1 class="lg-wd-title">Compose Weekly Email</h1>
              <p class="lg-wd-subtitle">
                <?php if ( $issue_id ) : ?>
                  Issue #<?php echo $issue_id; ?>
                  <?php if ( $is_sent ) : ?>
                    <span class="lg-wd-badge lg-wd-badge-sent">Sent</span>
                  <?php else : ?>
                    <span class="lg-wd-badge lg-wd-badge-draft">Draft</span>
                  <?php endif; ?>
                <?php else : ?>
                  No issue loaded
                <?php endif; ?>
              </p>
            </div>
            <div class="lg-wd-header-actions" style="display:flex;gap:8px;align-items:center;">
              <?php if ( ! empty( $all_issues ) ) : ?>
              <select id="lg-wd-issue-picker" class="lg-wd-select" style="min-width:280px;">
                <option value="">— Load Existing Issue —</option>
                <?php foreach ( $all_issues as $issue ) :
                    $badge  = $issue['status'] === 'sent' ? '✓ Sent' : '● Draft';
                    $selected = ( $issue['id'] === $issue_id ) ? ' selected' : '';
                ?>
                  <option value="<?php echo esc_attr( $compose_url . '&issue_id=' . $issue['id'] ); ?>"<?php echo $selected; ?>>
                    <?php echo esc_html( "[{$badge}] {$issue['title']} — {$issue['date']}" ); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
              <button class="button lg-wd-btn-secondary" id="lg-wd-new-issue-btn">+ New Issue</button>
            </div>
          </div>

          <!-- Response area -->
          <div id="lg-wd-response" style="display:none;" class="lg-wd-response"></div>

          <?php if ( ! $issue_id ) : ?>
            <div class="lg-wd-card">
              <div class="lg-wd-card-body" style="text-align:center;padding:40px;">
                <?php if ( ! empty( $all_issues ) ) : ?>
                  <p style="font-size:16px;color:#5C4E3A;">Select an existing issue above, or create a new one.</p>
                <?php else : ?>
                  <p style="font-size:16px;color:#5C4E3A;">No issues found. Create a new one to get started.</p>
                <?php endif; ?>
                <button class="button button-primary lg-wd-btn-primary" id="lg-wd-new-issue-btn-empty">+ New Issue</button>
              </div>
            </div>
          <?php else : ?>

          <input type="hidden" id="lg-wd-issue-id" value="<?php echo $issue_id; ?>">

          <!-- Issue title + date range -->
          <div class="lg-wd-card">
            <div class="lg-wd-card-body">
              <div class="lg-wd-grid lg-wd-grid-3">
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">Issue Title</label>
                  <input type="text" id="lg-wd-issue-title"
                         value="<?php echo esc_attr( $issue_title ); ?>"
                         class="lg-wd-input" placeholder="Weekly Digest — March 11, 2026">
                </div>
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">Date From</label>
                  <input type="date" id="lg-wd-date-from"
                         value="<?php echo esc_attr( $issue_data['date_from'] ?? '' ); ?>"
                         class="lg-wd-input">
                </div>
                <div class="lg-wd-form-group">
                  <label class="lg-wd-label">Date To</label>
                  <input type="date" id="lg-wd-date-to"
                         value="<?php echo esc_attr( $issue_data['date_to'] ?? '' ); ?>"
                         class="lg-wd-input">
                </div>
              </div>
              <div style="margin-top:12px;display:flex;gap:8px;">
                <button class="button lg-wd-btn-secondary" id="lg-wd-populate-btn">
                  Auto-Populate from Date Range
                </button>
                <button class="button lg-wd-btn-secondary" id="lg-wd-save-draft-btn">
                  Save Draft
                </button>
                <button class="button" id="lg-wd-clear-draft-btn" style="color:#a00;">
                  Clear Draft
                </button>
              </div>
            </div>
          </div>

          <!-- Sections container (sortable) -->
          <div id="lg-wd-sections-container">
            <?php
            $sections = $issue_data['sections'] ?? [];
            foreach ( $sections as $idx => $section ) :
                self::render_section_card( $section, $idx );
            endforeach;
            ?>
          </div>

          <!-- Add section / Add archive post -->
          <div class="lg-wd-card" style="margin-top:16px;">
            <div class="lg-wd-card-body" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
              <div class="lg-wd-form-group" style="margin-bottom:0;">
                <label class="lg-wd-label">Add Section</label>
                <select id="lg-wd-add-section-select" class="lg-wd-select">
                  <option value="">— Choose —</option>
                  <?php foreach ( $registry as $entry ) : ?>
                    <option value="<?php echo esc_attr( $entry['slug'] ); ?>"
                            data-label="<?php echo esc_attr( $entry['label'] ); ?>"
                            data-template="<?php echo esc_attr( $entry['template'] ?? 'card' ); ?>"
                            data-is-header="<?php echo ! empty( $entry['is_header'] ) ? '1' : '0'; ?>">
                      <?php if ( ! empty( $entry['is_header'] ) ) : ?>
                        📌 <?php echo esc_html( $entry['label'] ); ?> (header)
                      <?php else : ?>
                        <?php echo esc_html( $entry['label'] ); ?> (<?php echo esc_html( $entry['slug'] ); ?>)
                      <?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                  <option value="__custom__">+ Custom Section…</option>
                </select>
              </div>
              <button class="button" id="lg-wd-add-section-btn">Add Section</button>

              <div class="lg-wd-form-group" style="margin-bottom:0;margin-left:16px;border-left:1px solid #ddd;padding-left:16px;">
                <label class="lg-wd-label">Quick Header</label>
                <div style="display:flex;gap:6px;">
                  <input type="text" id="lg-wd-quick-header-input" class="lg-wd-input"
                         placeholder="e.g. New To The Website" style="width:200px;">
                  <button class="button" id="lg-wd-quick-header-btn">+ Header</button>
                </div>
              </div>

              <div class="lg-wd-form-group" style="margin-bottom:0;margin-left:16px;border-left:1px solid #ddd;padding-left:16px;">
                <label class="lg-wd-label">Custom HTML</label>
                <button class="button" id="lg-wd-add-html-block-btn">+ HTML Block</button>
              </div>

              <div style="margin-left:auto;display:flex;gap:8px;align-items:flex-end;">
                <div class="lg-wd-form-group" style="margin-bottom:0;">
                  <label class="lg-wd-label">Search Posts</label>
                  <input type="text" id="lg-wd-archive-search" class="lg-wd-input"
                         placeholder="Type to search all CPTs…" style="width:250px;">
                </div>
                <button class="button" id="lg-wd-archive-search-btn">Search</button>
              </div>
            </div>

            <!-- Search results -->
            <div id="lg-wd-search-results" style="display:none;padding:0 16px 16px;">
              <table class="widefat striped" id="lg-wd-search-results-table">
                <thead>
                  <tr><th>Title</th><th>Type</th><th>Date</th><th>Action</th></tr>
                </thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <!-- Action bar -->
          <div class="lg-wd-card" style="margin-top:16px;">
            <div class="lg-wd-card-body" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <button class="button lg-wd-btn-secondary" id="lg-wd-preview-btn">Preview Email</button>

              <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
                <input type="email" id="lg-wd-test-email" placeholder="test@email.com"
                       value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                       class="lg-wd-input-sm">
                <button class="button lg-wd-btn-secondary" id="lg-wd-test-btn">Send Test</button>
                <button class="button lg-wd-btn-danger" id="lg-wd-send-btn">Send Now</button>
                <?php if ( $is_sent ) : ?>
                  <button class="button lg-wd-btn-secondary" id="lg-wd-reset-draft-btn">Reset to Draft</button>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php endif; ?>

        </div><!-- /.wrap -->

        <!-- Preview modal -->
        <div id="lg-wd-preview-modal" style="display:none;">
          <div class="lg-wd-modal-overlay"></div>
          <div class="lg-wd-modal-inner">
            <div class="lg-wd-modal-header">
              <strong>Email Preview</strong>
              <button class="lg-wd-modal-close">&times;</button>
            </div>
            <div class="lg-wd-modal-body">
              <iframe id="lg-wd-preview-frame" src="" style="width:100%;height:100%;border:none;"></iframe>
            </div>
          </div>
        </div>

        <!-- Custom section modal -->
        <div id="lg-wd-custom-section-modal" style="display:none;">
          <div class="lg-wd-modal-overlay"></div>
          <div class="lg-wd-modal-inner" style="max-width:400px;">
            <div class="lg-wd-modal-header">
              <strong>Add Custom Section</strong>
              <button class="lg-wd-modal-close">&times;</button>
            </div>
            <div class="lg-wd-modal-body" style="padding:20px;">
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Section Label</label>
                <input type="text" id="lg-wd-custom-label" class="lg-wd-input" placeholder="e.g. From the Archive">
              </div>
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Key (slug)</label>
                <input type="text" id="lg-wd-custom-key" class="lg-wd-input" placeholder="e.g. from_archive">
              </div>
              <button class="button button-primary" id="lg-wd-custom-section-add">Add</button>
            </div>
          </div>
        </div>

        <!-- External card modal -->
        <div id="lg-wd-external-card-modal" style="display:none;">
          <div class="lg-wd-modal-overlay"></div>
          <div class="lg-wd-modal-inner" style="max-width:500px;">
            <div class="lg-wd-modal-header">
              <strong>Add External Card</strong>
              <button class="lg-wd-modal-close">&times;</button>
            </div>
            <div class="lg-wd-modal-body" style="padding:20px;">
              <input type="hidden" id="lg-wd-ext-target-section" value="">
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Title *</label>
                <input type="text" id="lg-wd-ext-title" class="lg-wd-input" placeholder="Post title">
              </div>
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Post URL *</label>
                <input type="url" id="lg-wd-ext-url" class="lg-wd-input" placeholder="https://example.com/article">
              </div>
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Featured Image URL</label>
                <input type="url" id="lg-wd-ext-thumb" class="lg-wd-input" placeholder="https://example.com/image.jpg">
              </div>
              <div class="lg-wd-form-group">
                <label class="lg-wd-label">Excerpt</label>
                <textarea id="lg-wd-ext-excerpt" class="lg-wd-input" rows="2" placeholder="Short description…"></textarea>
              </div>
              <div class="lg-wd-form-row" style="display:flex;gap:12px;">
                <div class="lg-wd-form-group" style="flex:1;">
                  <label class="lg-wd-label">Author / Source Name</label>
                  <input type="text" id="lg-wd-ext-author-name" class="lg-wd-input" placeholder="Guitar World">
                </div>
                <div class="lg-wd-form-group" style="flex:1;">
                  <label class="lg-wd-label">Author / Source URL</label>
                  <input type="url" id="lg-wd-ext-author-url" class="lg-wd-input" placeholder="https://example.com">
                </div>
              </div>
              <button class="button button-primary" id="lg-wd-ext-add-btn">Add Card</button>
            </div>
          </div>
        </div>
        <?php
    }

    // ── Section card renderer (used in initial render + AJAX) ────────────────

    public static function render_section_card( array $section, int $idx ): void {
        $key           = esc_attr( $section['key'] ?? '' );
        $label         = esc_html( $section['label'] ?? '' );
        $is_header     = ! empty( $section['is_header'] );
        $template      = esc_attr( $section['template'] ?? 'card' );
        $slug          = esc_attr( $section['slug'] ?? '' );
        $post_ids      = $section['post_ids'] ?? [];
        $manual_items  = $section['manual_items'] ?? [];
        $total_items   = count( $post_ids ) + count( $manual_items );

        $html_content  = $section['html_content'] ?? '';
        $html_header   = $section['html_header'] ?? '';
        $is_html_block = $template === 'html-block';

        // Group header — visual divider, no posts
        if ( $is_header ) : ?>
        <div class="lg-wd-compose-section lg-wd-compose-header" data-section-key="<?php echo $key; ?>" data-section-template="header" data-section-slug="<?php echo $slug; ?>" data-is-header="1">
          <div class="lg-wd-compose-section-header" style="background:#2B2318;color:#ECB351;border-left:4px solid #ECB351;">
            <span class="lg-wd-drag-handle" title="Drag to reorder" style="color:#ECB351;">⠿</span>
            <strong style="color:#ECB351;">📌 <?php echo $label; ?></strong>
            <span class="lg-wd-section-type-badge" style="background:#ECB351;color:#2B2318;">GROUP HEADER</span>
            <button type="button" class="button button-small lg-wd-remove-section-btn" title="Remove section" style="color:#ECB351;">✕</button>
          </div>
        </div>
        <?php elseif ( $is_html_block ) :
            $editor_id = 'lg_wd_html_' . $key;
        ?>
        <div class="lg-wd-compose-section lg-wd-compose-html-block" data-section-key="<?php echo $key; ?>" data-section-template="html-block" data-section-slug="" data-is-header="0" data-editor-id="<?php echo esc_attr( $editor_id ); ?>">
          <div class="lg-wd-compose-section-header">
            <span class="lg-wd-drag-handle" title="Drag to reorder">⠿</span>
            <strong><?php echo $label; ?></strong>
            <span class="lg-wd-section-type-badge" style="background:#87986A;color:#fff;">HTML</span>
            <button type="button" class="button button-small lg-wd-remove-section-btn" title="Remove section">✕</button>
          </div>
          <div class="lg-wd-compose-section-body" style="padding:12px;">
            <div class="lg-wd-html-header-row" style="margin-bottom:10px;">
              <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#87986A;">Subsection Header <span style="font-weight:400;color:#999;">(optional)</span></label>
              <input type="text" class="lg-wd-html-header-input" value="<?php echo esc_attr( $html_header ); ?>" placeholder="e.g. A Note From The Editor" style="width:100%;margin-top:4px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;" />
            </div>
            <?php
            wp_editor( $html_content, $editor_id, [
                'textarea_name' => $editor_id,
                'textarea_rows' => 12,
                'media_buttons' => true,
                'teeny'         => false,
                'quicktags'     => true,
                'tinymce'       => [
                    'paste_data_images' => true,
                    'toolbar1'      => 'formatselect,bold,italic,underline,strikethrough,separator,bullist,numlist,separator,blockquote,hr,separator,alignleft,aligncenter,alignright,separator,link,unlink,separator,wp_more,wp_adv',
                    'toolbar2'      => 'fontsizeselect,forecolor,backcolor,separator,pastetext,removeformat,separator,charmap,separator,outdent,indent,separator,undo,redo,separator,wp_help',
                    'block_formats' => 'Paragraph=p;Heading 1=h1;Heading 2=h2;Heading 3=h3;Heading 4=h4;Preformatted=pre',
                    'content_style' => 'body { font-family: Georgia, "Times New Roman", serif; font-size: 15px; color: #5C4E3A; line-height: 1.65; }',
                ],
            ] );
            ?>
          </div>
        </div>

        <?php else : ?>
        <div class="lg-wd-compose-section" data-section-key="<?php echo $key; ?>" data-section-template="<?php echo $template; ?>" data-section-slug="<?php echo $slug; ?>" data-is-header="0">
          <div class="lg-wd-compose-section-header">
            <span class="lg-wd-drag-handle" title="Drag to reorder">⠿</span>
            <strong><?php echo $label; ?></strong>
            <span class="lg-wd-section-type-badge"><?php echo esc_html( $template ); ?></span>
            <span class="lg-wd-section-count"><?php echo $total_items; ?> items</span>
            <button type="button" class="button button-small lg-wd-add-external-btn" title="Add external card">+ External</button>
            <button type="button" class="button button-small lg-wd-remove-section-btn" title="Remove section">✕</button>
          </div>
          <div class="lg-wd-compose-section-body">
            <?php if ( empty( $post_ids ) && empty( $manual_items ) ) : ?>
              <p class="lg-wd-empty-section">No posts in this section.</p>
            <?php else : ?>
              <ul class="lg-wd-post-list" data-section-key="<?php echo $key; ?>">
                <?php foreach ( $post_ids as $pid ) :
                    $post = get_post( $pid );
                    if ( ! $post ) continue;
                    $title     = esc_html( get_the_title( $post ) );
                    $date      = get_the_date( 'M j', $post );
                    $cpt_label = esc_html( self::cpt_label( $post->post_type ) );
                    ?>
                    <li class="lg-wd-post-item" data-post-id="<?php echo $pid; ?>">
                      <label class="lg-wd-post-check">
                        <input type="checkbox" checked data-post-id="<?php echo $pid; ?>">
                        <span class="lg-wd-post-title"><?php echo $title; ?></span>
                      </label>
                      <span class="lg-wd-post-meta">
                        <span class="lg-wd-post-type"><?php echo $cpt_label; ?></span>
                        <span class="lg-wd-post-date"><?php echo esc_html( $date ); ?></span>
                      </span>
                      <button type="button" class="lg-wd-post-remove" title="Remove" data-post-id="<?php echo $pid; ?>">✕</button>
                    </li>
                <?php endforeach; ?>
                <?php foreach ( $manual_items as $mi_idx => $mi ) : ?>
                    <li class="lg-wd-post-item lg-wd-manual-item"
                        data-manual-title="<?php echo esc_attr( $mi['title'] ); ?>"
                        data-manual-url="<?php echo esc_attr( $mi['url'] ); ?>"
                        data-manual-thumb="<?php echo esc_attr( $mi['thumb_url'] ); ?>"
                        data-manual-excerpt="<?php echo esc_attr( $mi['excerpt'] ); ?>"
                        data-manual-author-name="<?php echo esc_attr( $mi['author_name'] ); ?>"
                        data-manual-author-url="<?php echo esc_attr( $mi['author_url'] ); ?>">
                      <label class="lg-wd-post-check">
                        <input type="checkbox" checked>
                        <span class="lg-wd-post-title"><?php echo esc_html( $mi['title'] ); ?></span>
                      </label>
                      <span class="lg-wd-post-meta">
                        <span class="lg-wd-post-type lg-wd-external-badge">EXTERNAL</span>
                        <span class="lg-wd-post-date"><?php echo esc_html( $mi['author_name'] ); ?></span>
                      </span>
                      <button type="button" class="lg-wd-post-remove lg-wd-manual-remove" title="Remove">✕</button>
                    </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
        <?php endif;
    }

    // ── AJAX: Save draft ────────────────────────────────────────────────────

    public static function ajax_save(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $issue_id = absint( $_POST['issue_id'] ?? 0 );
        if ( ! $issue_id ) wp_send_json_error( 'No issue ID.' );

        // Update title
        $title = sanitize_text_field( $_POST['title'] ?? '' );
        if ( $title ) {
            wp_update_post( [ 'ID' => $issue_id, 'post_title' => $title ] );
        }

        // Build sections from POST data
        $raw_sections = json_decode( stripslashes( $_POST['sections'] ?? '[]' ), true );
        if ( ! is_array( $raw_sections ) ) $raw_sections = [];

        $sections = [];
        foreach ( $raw_sections as $s ) {
            // Sanitize manual (external) items
            $manual_items = [];
            foreach ( $s['manual_items'] ?? [] as $mi ) {
                $manual_items[] = [
                    'title'       => sanitize_text_field( $mi['title'] ?? '' ),
                    'url'         => esc_url_raw( $mi['url'] ?? '' ),
                    'thumb_url'   => esc_url_raw( $mi['thumb_url'] ?? '' ),
                    'excerpt'     => sanitize_text_field( $mi['excerpt'] ?? '' ),
                    'author_name' => sanitize_text_field( $mi['author_name'] ?? '' ),
                    'author_url'  => esc_url_raw( $mi['author_url'] ?? '' ),
                ];
            }

            $entry = [
                'key'          => sanitize_key( $s['key'] ?? '' ),
                'label'        => sanitize_text_field( $s['label'] ?? '' ),
                'is_header'    => ! empty( $s['is_header'] ),
                'slug'         => sanitize_text_field( $s['slug'] ?? '' ),
                'template'     => sanitize_key( $s['template'] ?? 'card' ),
                'post_ids'     => array_map( 'absint', $s['post_ids'] ?? [] ),
                'manual_items' => $manual_items,
            ];

            // HTML block sections store their content directly
            if ( ( $s['template'] ?? '' ) === 'html-block' ) {
                if ( isset( $s['html_content'] ) ) {
                    $entry['html_content'] = wp_kses_post( $s['html_content'] );
                }
                if ( ! empty( $s['html_header'] ) ) {
                    $entry['html_header'] = sanitize_text_field( $s['html_header'] );
                }
            }

            $sections[] = $entry;
        }

        $data = LG_WD_Issue::get_data( $issue_id );
        $data['date_from'] = sanitize_text_field( $_POST['date_from'] ?? $data['date_from'] );
        $data['date_to']   = sanitize_text_field( $_POST['date_to'] ?? $data['date_to'] );
        $data['sections']  = $sections;

        LG_WD_Issue::save_data( $issue_id, $data );

        wp_send_json_success( [ 'message' => 'Draft saved.' ] );
    }

    // ── AJAX: Auto-populate ─────────────────────────────────────────────────

    public static function ajax_populate(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );

        if ( ! $date_from || ! $date_to ) {
            wp_send_json_error( 'Please set both dates.' );
        }

        $sections = LG_WD_Issue::auto_populate( $date_from, $date_to );

        // Render HTML for each section card
        ob_start();
        foreach ( $sections as $idx => $section ) {
            self::render_section_card( $section, $idx );
        }
        $html = ob_get_clean();

        wp_send_json_success( [
            'html'     => $html,
            'sections' => $sections,
            'message'  => count( $sections ) . ' sections populated.',
        ] );
    }

    // ── AJAX: Search posts ──────────────────────────────────────────────────

    public static function ajax_search(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $term = sanitize_text_field( $_POST['term'] ?? '' );
        if ( strlen( $term ) < 2 ) {
            wp_send_json_error( 'Search term too short.' );
        }

        $results = LG_WD_Query::search_posts( $term );
        wp_send_json_success( [ 'results' => $results ] );
    }

    // ── AJAX: Preview ───────────────────────────────────────────────────────

    public static function ajax_preview(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $issue_id = absint( $_POST['issue_id'] ?? 0 );
        if ( ! $issue_id ) wp_send_json_error( 'No issue ID.' );

        // Save current state first
        self::save_from_post( $issue_id );

        $result = LG_WD_Sender::send_issue( $issue_id, true );
        if ( $result['success'] ) {
            wp_send_json_success( [ 'html' => $result['html'], 'subject' => $result['subject'] ] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    // ── AJAX: Test send ─────────────────────────────────────────────────────

    public static function ajax_test_send(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $issue_id = absint( $_POST['issue_id'] ?? 0 );
        $to       = sanitize_email( $_POST['to'] ?? '' );

        if ( ! $issue_id ) wp_send_json_error( 'No issue ID.' );
        if ( ! is_email( $to ) ) wp_send_json_error( 'Invalid email.' );

        self::save_from_post( $issue_id );

        $result = LG_WD_Sender::send_issue( $issue_id, false, $to );
        if ( $result['success'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    // ── AJAX: Send now ──────────────────────────────────────────────────────

    public static function ajax_send(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $issue_id = absint( $_POST['issue_id'] ?? 0 );
        if ( ! $issue_id ) wp_send_json_error( 'No issue ID.' );

        self::save_from_post( $issue_id );

        $result = LG_WD_Sender::send_issue( $issue_id );
        if ( $result['success'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    // ── AJAX: New issue ─────────────────────────────────────────────────────

    public static function ajax_new_issue(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $issue_id = LG_WD_Issue::create();
        if ( ! $issue_id ) {
            wp_send_json_error( 'Failed to create issue.' );
        }

        wp_send_json_success( [
            'issue_id' => $issue_id,
            'redirect' => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&issue_id=' . $issue_id ),
        ] );
    }

    /**
     * Delete the current draft issue (move to trash).
     */
    public static function ajax_delete_draft(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $issue_id = (int) ( $_POST['issue_id'] ?? 0 );
        if ( ! $issue_id ) {
            wp_send_json_error( 'No issue ID.' );
        }

        $post = get_post( $issue_id );
        if ( ! $post || $post->post_type !== 'weekly_email' ) {
            wp_send_json_error( 'Invalid issue.' );
        }

        // Only allow deleting drafts, not sent issues
        if ( $post->post_status === 'publish' ) {
            wp_send_json_error( 'Cannot delete a sent issue.' );
        }

        wp_trash_post( $issue_id );

        wp_send_json_success( [
            'redirect' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
        ] );
    }

    /**
     * Reset a sent issue back to draft status.
     */
    public static function ajax_reset_draft(): void {
        check_ajax_referer( 'lg_wd_compose', 'nonce' );
        if ( ! current_user_can( self::CAP ) ) wp_send_json_error( 'Unauthorized' );

        $issue_id = (int) ( $_POST['issue_id'] ?? 0 );
        if ( ! $issue_id ) {
            wp_send_json_error( 'No issue ID.' );
        }

        $post = get_post( $issue_id );
        if ( ! $post || $post->post_type !== 'weekly_email' ) {
            wp_send_json_error( 'Invalid issue.' );
        }

        // Reset WP post status to draft
        wp_update_post( [ 'ID' => $issue_id, 'post_status' => 'draft' ] );

        // Reset issue meta status
        $data = LG_WD_Issue::get_data( $issue_id );
        $data['status']      = 'draft';
        $data['sent_at']     = null;
        $data['campaign_id'] = null;
        LG_WD_Issue::save_data( $issue_id, $data );

        wp_send_json_success( [
            'redirect' => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&issue_id=' . $issue_id ),
        ] );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Save the current form state from $_POST into the issue.
     */
    private static function save_from_post( int $issue_id ): void {
        if ( isset( $_POST['sections'] ) ) {
            $raw_sections = json_decode( stripslashes( $_POST['sections'] ), true );
            if ( is_array( $raw_sections ) ) {
                $data = LG_WD_Issue::get_data( $issue_id );
                $data['sections'] = [];
                foreach ( $raw_sections as $s ) {
                    // Sanitize manual (external) items
                    $manual_items = [];
                    foreach ( $s['manual_items'] ?? [] as $mi ) {
                        $manual_items[] = [
                            'title'       => sanitize_text_field( $mi['title'] ?? '' ),
                            'url'         => esc_url_raw( $mi['url'] ?? '' ),
                            'thumb_url'   => esc_url_raw( $mi['thumb_url'] ?? '' ),
                            'excerpt'     => sanitize_text_field( $mi['excerpt'] ?? '' ),
                            'author_name' => sanitize_text_field( $mi['author_name'] ?? '' ),
                            'author_url'  => esc_url_raw( $mi['author_url'] ?? '' ),
                        ];
                    }

                    $entry = [
                        'key'          => sanitize_key( $s['key'] ?? '' ),
                        'label'        => sanitize_text_field( $s['label'] ?? '' ),
                        'is_header'    => ! empty( $s['is_header'] ),
                        'slug'         => sanitize_text_field( $s['slug'] ?? '' ),
                        'template'     => sanitize_key( $s['template'] ?? 'card' ),
                        'post_ids'     => array_map( 'absint', $s['post_ids'] ?? [] ),
                        'manual_items' => $manual_items,
                    ];

                    if ( ( $s['template'] ?? '' ) === 'html-block' ) {
                        if ( isset( $s['html_content'] ) ) {
                            $entry['html_content'] = wp_kses_post( $s['html_content'] );
                        }
                        if ( ! empty( $s['html_header'] ) ) {
                            $entry['html_header'] = sanitize_text_field( $s['html_header'] );
                        }
                    }

                    $data['sections'][] = $entry;
                }
                if ( ! empty( $_POST['date_from'] ) ) {
                    $data['date_from'] = sanitize_text_field( $_POST['date_from'] );
                }
                if ( ! empty( $_POST['date_to'] ) ) {
                    $data['date_to'] = sanitize_text_field( $_POST['date_to'] );
                }
                LG_WD_Issue::save_data( $issue_id, $data );
            }
        }
    }

    private static function cpt_label( string $slug ): string {
        $obj = get_post_type_object( $slug );
        return $obj ? ( $obj->labels->singular_name ?? ucfirst( $slug ) ) : ucfirst( $slug );
    }
}
