<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Frontend
 * Public-facing shortcode [weekly_digest] that renders sent issues
 * as a streaming feed with pagination. Also makes the weekly_email
 * CPT public so BuddyBoss picks it up for the activity feed.
 */
class LG_WD_Frontend {

    const PER_PAGE = 5;

    public static function init(): void {
        add_shortcode( 'weekly_digest', [ __CLASS__, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
        add_filter( 'the_content', [ __CLASS__, 'single_issue_content' ] );
    }

    // ── Shortcode ────────────────────────────────────────────────────────────

    /**
     * [weekly_digest per_page="5"]
     */
    public static function render_shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'per_page' => self::PER_PAGE,
        ], $atts, 'weekly_digest' );

        $per_page = max( 1, (int) $atts['per_page'] );
        $paged    = max( 1, (int) ( get_query_var( 'paged' ) ?: get_query_var( 'page' ) ?: 1 ) );

        $query = new WP_Query( [
            'post_type'      => LG_WD_Issue::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        if ( ! $query->have_posts() ) {
            return '<p class="lg-wd-fe-empty">No issues have been published yet.</p>';
        }

        // Enqueue styles
        wp_enqueue_style( 'lg-wd-frontend' );

        $settings = LG_WD_Settings::get_all();

        ob_start();
        // Hide the page title via inline style scoped to the parent entry
        echo '<style>.lg-wd-fe-archive-page .entry-title,.lg-wd-fe-archive-page .entry-header{display:none!important;}</style>';
        echo '<div class="lg-wd-fe-archive lg-wd-fe-archive-page">';
        echo '<div class="lg-wd-fe-layout">';
        echo '<div class="lg-wd-fe-main">';

        $index = 0;
        while ( $query->have_posts() ) {
            $query->the_post();
            $issue_id   = get_the_ID();
            $issue_data = LG_WD_Issue::get_data( $issue_id );
            $sent_at    = $issue_data['sent_at'] ?? '';
            $date_label = $sent_at
                ? date_i18n( 'F j, Y', strtotime( $sent_at ) )
                : get_the_date( 'F j, Y' );
            $week_label = 'Week of ' . $date_label;

            if ( $index === 0 && $paged === 1 ) {
                // ── First issue: fully expanded with header/footer ──
                $payload    = LG_WD_Query::build_payload_from_issue( $issue_data );
                $item_count = array_sum( array_map( fn( $p ) => count( $p['items'] ), $payload ) );

                echo '<article class="lg-wd-fe-issue lg-wd-fe-single">';

                // Header
                $header_img = esc_url( $settings['header_image_url'] ?? '' );
                echo '<header class="lg-wd-fe-issue-header">';
                if ( $header_img ) {
                    echo '<a href="' . esc_url( home_url() ) . '"><img src="' . $header_img . '" alt="' . esc_attr( $settings['from_name'] ?? 'The Looth Group' ) . '" class="lg-wd-fe-header-img"></a>';
                } else {
                    echo '<h2 class="lg-wd-fe-issue-title">' . esc_html( $settings['from_name'] ?? 'THE LOOTH GROUP' ) . '</h2>';
                    echo '<p class="lg-wd-fe-issue-date">' . esc_html( $settings['branding_tagline'] ?? 'Guitar Repair & Restoration Community' ) . '</p>';
                }
                echo '</header>';

                // Hero band with subscribe button
                echo '<div class="lg-wd-fe-hero">';
                echo '<span class="lg-wd-fe-hero-left">Loothgroup Weekly</span>';
                echo '<button type="button" class="lg-wd-fe-subscribe-btn lg-wd-fe-subscribe-pulse">Subscribe</button>';
                echo '<span class="lg-wd-fe-hero-right">' . esc_html( $week_label ) . ' &middot; ' . $item_count . ' items</span>';
                echo '</div>';

                // Body
                $intro = trim( $settings['intro_text'] ?? '' );
                self::render_issue_body( $issue_data, $payload, $intro );

                // Signoff
                $signoff = trim( $settings['signoff'] ?? '' );
                if ( $signoff ) {
                    echo '<div class="lg-wd-fe-signoff">';
                    echo '<p>' . nl2br( esc_html( wp_unslash( $signoff ) ) ) . '</p>';
                    echo '</div>';
                }

                // Footer
                self::render_footer( $settings );

                echo '</article>';
            } else {
                // ── Subsequent issues: linked card, opens in new tab ──
                $permalink = esc_url( get_permalink( $issue_id ) );
                echo '<a href="' . $permalink . '" target="_blank" rel="noopener" class="lg-wd-fe-issue lg-wd-fe-issue-link">';
                echo '<header class="lg-wd-fe-issue-header">';
                echo '<h2 class="lg-wd-fe-issue-title">' . esc_html( get_the_title() ) . '</h2>';
                echo '<p class="lg-wd-fe-issue-date">' . esc_html( $date_label ) . '</p>';
                echo '</header>';
                echo '</a>';
            }

            $index++;
        }

        // Subscribe modal (once, outside archive wrapper)
        self::render_subscribe_modal();

        // Pagination
        $total_pages = $query->max_num_pages;
        if ( $total_pages > 1 ) {
            echo '<nav class="lg-wd-fe-pagination">';
            echo paginate_links( [
                'total'     => $total_pages,
                'current'   => $paged,
                'prev_text' => '&larr; Newer',
                'next_text' => 'Older &rarr;',
                'type'      => 'plain',
            ] );
            echo '</nav>';
        }

        echo '</div>'; // .lg-wd-fe-main

        // Sidebar
        self::render_sidebar();

        echo '</div>'; // .lg-wd-fe-layout
        echo '</div>'; // .lg-wd-fe-archive
        wp_reset_postdata();

        return ob_get_clean();
    }

    // ── Single issue content filter ──────────────────────────────────────────

    /**
     * When viewing a single weekly_email post, render the issue content.
     */
    public static function single_issue_content( string $content ): string {
        if ( ! is_singular( LG_WD_Issue::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        wp_enqueue_style( 'lg-wd-frontend' );

        $issue_data = LG_WD_Issue::get_data( get_the_ID() );
        $settings   = LG_WD_Settings::get_all();
        $payload    = LG_WD_Query::build_payload_from_issue( $issue_data );
        $item_count = array_sum( array_map( fn( $p ) => count( $p['items'] ), $payload ) );

        $sent_at    = $issue_data['sent_at'] ?? '';
        $week_label = $sent_at
            ? 'Week of ' . date_i18n( 'F j, Y', strtotime( $sent_at ) )
            : 'Week of ' . get_the_date( 'F j, Y' );

        ob_start();
        echo '<div class="lg-wd-fe-archive">';
        echo '<div class="lg-wd-fe-layout">';
        echo '<div class="lg-wd-fe-main">';
        echo '<article class="lg-wd-fe-issue lg-wd-fe-single">';

        // ── Header (dark banner + logo) ──
        $header_img = esc_url( $settings['header_image_url'] ?? '' );
        echo '<header class="lg-wd-fe-issue-header">';
        if ( $header_img ) {
            echo '<a href="' . esc_url( home_url() ) . '"><img src="' . $header_img . '" alt="' . esc_attr( $settings['from_name'] ?? 'The Looth Group' ) . '" class="lg-wd-fe-header-img"></a>';
        } else {
            echo '<h2 class="lg-wd-fe-issue-title">' . esc_html( $settings['from_name'] ?? 'THE LOOTH GROUP' ) . '</h2>';
            echo '<p class="lg-wd-fe-issue-date">' . esc_html( $settings['branding_tagline'] ?? 'Guitar Repair & Restoration Community' ) . '</p>';
        }
        echo '</header>';

        // ── Hero band (gold) with subscribe ──
        echo '<div class="lg-wd-fe-hero">';
        echo '<span class="lg-wd-fe-hero-left">Loothgroup Weekly</span>';
        echo '<button type="button" class="lg-wd-fe-subscribe-btn lg-wd-fe-subscribe-pulse">Subscribe</button>';
        echo '<span class="lg-wd-fe-hero-right">' . esc_html( $week_label ) . ' &middot; ' . $item_count . ' items</span>';
        echo '</div>';

        // ── Body sections (with optional intro) ──
        $intro = trim( $settings['intro_text'] ?? '' );
        self::render_issue_body( $issue_data, $payload, $intro );

        // ── Signoff ──
        $signoff = trim( $settings['signoff'] ?? '' );
        if ( $signoff ) {
            echo '<div class="lg-wd-fe-signoff">';
            echo '<p>' . nl2br( esc_html( wp_unslash( $signoff ) ) ) . '</p>';
            echo '</div>';
        }

        // ── Footer ──
        self::render_footer( $settings );

        echo '</article>';
        echo '</div>'; // .lg-wd-fe-main

        // Sidebar removed — theme already provides one on single views
        // Sidebar removed — theme already provides one on single views

        echo '</div>'; // .lg-wd-fe-layout
        echo '</div>'; // .lg-wd-fe-archive

        // Subscribe modal (rendered outside article so overflow:hidden doesn't clip it)
        self::render_subscribe_modal();

        return ob_get_clean();
    }

    // ── Issue body renderer ──────────────────────────────────────────────────

    /**
     * Render the sections of an issue using web-friendly markup.
     */
    private static function render_issue_body( array $issue_data, ?array $payload = null, string $intro = '' ): void {
        $payload = $payload ?? LG_WD_Query::build_payload_from_issue( $issue_data );

        if ( empty( $payload ) ) {
            echo '<p class="lg-wd-fe-empty">This issue has no content.</p>';
            return;
        }

        echo '<div class="lg-wd-fe-body">';

        if ( $intro ) {
            echo '<p class="lg-wd-fe-intro">' . nl2br( esc_html( wp_unslash( $intro ) ) ) . '</p>';
        }

        foreach ( $payload as $data ) {
            // Group header
            if ( ! empty( $data['is_header'] ) ) {
                $label = esc_html( $data['section']['label'] );
                echo '<div class="lg-wd-fe-group-header">';
                echo '<span class="lg-wd-fe-group-label">' . $label . '</span>';
                echo '<span class="lg-wd-fe-group-line"></span>';
                echo '</div>';
                continue;
            }

            $section      = $data['section'];
            $items        = $data['items'] ?? [];
            $under_header = ! empty( $data['under_header'] );
            $hide_header  = ! empty( $data['hide_header'] );
            $template     = $section['template'] ?? 'card';

            if ( empty( $items ) ) continue;

            echo '<div class="lg-wd-fe-section">';

            // Section header
            if ( ! $hide_header ) {
                $label = esc_html( $section['label'] );
                if ( $under_header ) {
                    echo '<h4 class="lg-wd-fe-subheading">' . $label . '</h4>';
                } else {
                    echo '<div class="lg-wd-fe-section-header">';
                    echo '<span class="lg-wd-fe-section-label">' . $label . '</span>';
                    echo '<span class="lg-wd-fe-section-line"></span>';
                    echo '</div>';
                }
            }

            // Render items
            foreach ( $items as $item ) {
                self::render_item( $item, $template );
            }

            echo '</div>';

            // Section divider
            echo '<hr class="lg-wd-fe-divider">';
        }

        echo '</div>';
    }

    // ── Item renderers ───────────────────────────────────────────────────────

    private static function tier_pill( string $slug, string $label ): string {
        $styles = [
            'public'     => 'background:#D4E0B8;color:#3d4a2a;',
            'looth-lite' => 'background:#F5D69A;color:#6e4b0c;',
            'looth-pro'  => 'background:#2B2318;color:#ECB351;',
        ];
        $style = $styles[ $slug ] ?? '';
        if ( ! $style || ! $label ) return '';
        return '<span style="display:inline-block;padding:0 10px;border-radius:999px;font-size:11px;line-height:1.6;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;' . $style . '">' . esc_html( $label ) . '</span>';
    }

    private static function render_item( array $item, string $template ): void {
        switch ( $template ) {
            case 'html-block':
                self::render_html_block( $item );
                break;
            case 'date-forward':
                self::render_event( $item );
                break;
            case 'sponsor':
                self::render_sponsor( $item );
                break;
            case 'list':
                self::render_list_item( $item );
                break;
            case 'full-text':
                self::render_full_text( $item );
                break;
            default: // card, forum
                self::render_card( $item );
                break;
        }
    }

    /**
     * Card layout — thumbnail + title + excerpt + meta.
     */
    private static function render_card( array $item ): void {
        $title   = esc_html( $item['title'] );
        $url     = esc_url( $item['url'] );
        $excerpt = esc_html( $item['excerpt'] ?? '' );
        $date    = esc_html( $item['date'] ?? '' );
        $img_url = $item['thumb_url'] ?? '';

        // Fallback: extract first <img> from post content (forum topics etc.)
        if ( ! $img_url && ! empty( $item['id'] ) ) {
            $post = get_post( $item['id'] );
            if ( $post && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $m ) ) {
                $img_url = $m[1];
            }
        }
        $img_url = esc_url( $img_url );

        $author    = self::web_author( $item );
        $tier_html = self::tier_pill( $item['tier_slug'] ?? '', $item['tier_label'] ?? '' );

        $meta = array_filter( [ $tier_html, $author, $date ] );

        echo '<div class="lg-wd-fe-card">';
        if ( $img_url ) {
            echo '<a href="' . $url . '" class="lg-wd-fe-card-thumb">';
            echo '<img src="' . $img_url . '" alt="' . $title . '" loading="lazy">';
            echo '</a>';
        }
        echo '<div class="lg-wd-fe-card-body">';
        echo '<a href="' . $url . '" class="lg-wd-fe-card-title">' . $title . '</a>';
        if ( $excerpt ) {
            echo '<p class="lg-wd-fe-card-excerpt">' . $excerpt . '</p>';
        }
        if ( $meta ) {
            echo '<p class="lg-wd-fe-card-meta">' . implode( ' &middot; ', $meta ) . '</p>';
        }
        echo '</div></div>';
    }

    /**
     * Event layout — date-forward with event meta.
     */
    private static function render_event( array $item ): void {
        $title   = esc_html( $item['title'] );
        $url     = esc_url( $item['url'] );
        $img_url = esc_url( $item['thumb_url'] ?? '' );

        // Parse event date
        $date_raw = get_post_meta( $item['id'], 'events_start_date_and_time_', true );
        $time_raw = get_post_meta( $item['id'], 'time_of_event', true );
        $zoom_url = get_post_meta( $item['id'], 'zoom_url_for_looth_group_virtual_event', true );

        $display_date = '';
        $dt_start     = null;
        if ( $date_raw ) {
            $ts = DateTime::createFromFormat( 'Ymd', $date_raw, new DateTimeZone( LG_WD_TIMEZONE ) );
            if ( $ts ) {
                $display_date = $ts->format( 'l, F j, Y' );
                $dt_start     = $ts;
            }
        }
        if ( ! $display_date ) $display_date = $item['date'] ?? '';

        // Time
        $time_display = '';
        if ( $time_raw && $date_raw ) {
            $tz_eastern = new DateTimeZone( LG_WD_TIMEZONE );
            $tz_utc     = new DateTimeZone( 'UTC' );
            $formats    = [ 'Ymd H:i:s', 'Ymd H:i', 'Ymd g:ia', 'Ymd g:i a' ];
            foreach ( $formats as $fmt ) {
                $dt = DateTime::createFromFormat( $fmt, $date_raw . ' ' . $time_raw, $tz_eastern );
                if ( $dt ) {
                    $dt_start = clone $dt;
                    $eastern = strtolower( $dt->format( 'g' ) ) . ':' . $dt->format( 'i' );
                    $eastern = str_replace( ':00', '', $eastern );
                    $eastern .= strtolower( $dt->format( 'A' ) ) . ' ' . $dt->format( 'T' );
                    $dt->setTimezone( $tz_utc );
                    $utc = strtolower( $dt->format( 'g' ) ) . ':' . $dt->format( 'i' );
                    $utc = str_replace( ':00', '', $utc );
                    $utc .= strtolower( $dt->format( 'A' ) ) . ' UTC';
                    $time_display = $eastern . ' (' . $utc . ')';
                    break;
                }
            }
        }

        // Google Calendar link
        $gcal_url = '';
        if ( $dt_start ) {
            $dt_utc_start = clone $dt_start;
            $dt_utc_start->setTimezone( new DateTimeZone( 'UTC' ) );
            $dt_utc_end = clone $dt_utc_start;
            $dt_utc_end->modify( '+1 hour' );
            $gcal_url = 'https://calendar.google.com/calendar/render?' . http_build_query( [
                'action'   => 'TEMPLATE',
                'text'     => $item['title'],
                'dates'    => $dt_utc_start->format( 'Ymd\THis\Z' ) . '/' . $dt_utc_end->format( 'Ymd\THis\Z' ),
                'details'  => $item['url'],
                'location' => $zoom_url ?: '',
            ] );
        }

        $tier_html = self::tier_pill( $item['tier_slug'] ?? '', $item['tier_label'] ?? '' );
        $location  = $zoom_url ? 'Virtual Event' : 'In Person';
        $author    = self::web_author( $item );

        $gcal_link = $gcal_url
            ? ' &middot; <a href="' . esc_url( $gcal_url ) . '" target="_blank" rel="noopener" class="lg-wd-fe-calendar-link" style="display:inline-block;padding:0 10px;border-radius:999px;font-size:11px;line-height:1.6;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#ECB351;border:1px solid #ECB351;text-decoration:none;"><span style="font-size:9px;">&#128197;</span> Add</a>'
            : '';

        echo '<div class="lg-wd-fe-card lg-wd-fe-event">';
        if ( $img_url ) {
            echo '<a href="' . $url . '" class="lg-wd-fe-card-thumb">';
            echo '<img src="' . $img_url . '" alt="' . $title . '" loading="lazy">';
            echo '</a>';
        }
        echo '<div class="lg-wd-fe-card-body">';
        echo '<a href="' . $url . '" class="lg-wd-fe-card-title">' . $title . '</a>';
        echo '<p class="lg-wd-fe-event-date">' . esc_html( $display_date );
        if ( $time_display ) echo ' &middot; ' . $time_display;
        echo $gcal_link;
        echo '</p>';

        $event_meta = [];
        if ( $tier_html ) $event_meta[] = $tier_html;
        $event_meta[] = '<span class="lg-wd-fe-location">' . esc_html( $location ) . '</span>';
        if ( $author ) $event_meta[] = $author;
        echo '<p class="lg-wd-fe-card-meta">' . implode( ' &middot; ', $event_meta ) . '</p>';

        echo '</div></div>';
    }

    /**
     * Sponsor layout — partner branding.
     */
    private static function render_sponsor( array $item ): void {
        $title   = esc_html( $item['title'] );
        $url     = esc_url( $item['url'] );
        $excerpt = esc_html( $item['excerpt'] ?? '' );
        $img_url = esc_url( $item['thumb_url'] ?? '' );

        // Sponsor name from author
        $author_id    = (int) get_post_field( 'post_author', $item['id'] );
        $sponsor_name = $author_id ? esc_html( get_the_author_meta( 'display_name', $author_id ) ) : '';

        echo '<div class="lg-wd-fe-card lg-wd-fe-sponsor">';
        if ( $img_url ) {
            echo '<a href="' . $url . '" class="lg-wd-fe-card-thumb">';
            echo '<img src="' . $img_url . '" alt="' . $title . '" loading="lazy">';
            echo '</a>';
        }
        echo '<div class="lg-wd-fe-card-body">';
        if ( $sponsor_name ) {
            echo '<p class="lg-wd-fe-sponsor-label">' . $sponsor_name . '</p>';
        }
        echo '<a href="' . $url . '" class="lg-wd-fe-card-title">' . $title . '</a>';
        if ( $excerpt ) {
            echo '<p class="lg-wd-fe-card-excerpt">' . $excerpt . '</p>';
        }
        echo '<a href="' . $url . '" class="lg-wd-fe-sponsor-cta">Learn more &rarr;</a>';
        echo '</div></div>';
    }

    /**
     * List layout — compact rows.
     */
    private static function render_list_item( array $item ): void {
        $title  = esc_html( $item['title'] );
        $url    = esc_url( $item['url'] );
        $date   = esc_html( $item['date'] ?? '' );
        $author = self::web_author( $item );

        $reply_count = 0;
        if ( ! empty( $item['id'] ) && function_exists( 'bbp_get_topic_reply_count' ) ) {
            $reply_count = (int) bbp_get_topic_reply_count( $item['id'] );
        }

        $meta = [];
        if ( $author ) $meta[] = $author;
        if ( $reply_count ) $meta[] = $reply_count . ( $reply_count === 1 ? ' reply' : ' replies' );
        if ( $date ) $meta[] = $date;

        echo '<div class="lg-wd-fe-list-item">';
        echo '<a href="' . $url . '" class="lg-wd-fe-list-title">' . $title . '</a>';
        if ( $meta ) {
            echo '<p class="lg-wd-fe-card-meta">' . implode( ' &middot; ', $meta ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Full-text layout — WYSIWYG content.
     */
    private static function render_full_text( array $item ): void {
        $post = get_post( $item['id'] ?? 0 );
        if ( ! $post ) return;

        echo '<div class="lg-wd-fe-full-text">';
        echo wp_kses_post( apply_filters( 'the_content', $post->post_content ) );

        $author = self::web_author( $item );
        if ( $author ) {
            echo '<p class="lg-wd-fe-card-meta">' . $author . '</p>';
        }
        echo '</div>';
    }

    /**
     * HTML block — raw WYSIWYG content.
     */
    private static function render_html_block( array $item ): void {
        $html = $item['html_content'] ?? '';
        if ( ! $html ) return;

        echo '<div class="lg-wd-fe-html-block">';
        echo wp_kses_post( $html );
        echo '</div>';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build web-friendly author link (no inline styles).
     */
    private static function web_author( array $item ): string {
        if ( ! empty( $item['id'] ) ) {
            $author_id = (int) get_post_field( 'post_author', $item['id'] );
            if ( ! $author_id ) return '';
            $name = esc_html( get_the_author_meta( 'display_name', $author_id ) );
            if ( ! $name ) return '';

            $url = '';
            if ( get_post_type( $item['id'] ) === 'topic' && function_exists( 'bp_core_get_user_domain' ) ) {
                $member_url = bp_core_get_user_domain( $author_id );
                if ( $member_url ) $url = trailingslashit( $member_url ) . 'forums/';
            }
            if ( ! $url ) {
                $nicename = get_the_author_meta( 'user_nicename', $author_id );
                if ( $nicename ) $url = home_url( '/archive/?_post_author=' . $nicename );
            }

            return $url
                ? 'By <a href="' . esc_url( $url ) . '" class="lg-wd-fe-author-link">' . $name . '</a>'
                : 'By <strong class="lg-wd-fe-author">' . $name . '</strong>';
        }

        if ( ! empty( $item['author_name'] ) ) {
            $name = esc_html( $item['author_name'] );
            $url  = ! empty( $item['author_url'] ) ? esc_url( $item['author_url'] ) : '';
            return $url
                ? 'By <a href="' . $url . '" class="lg-wd-fe-author-link">' . $name . '</a>'
                : 'By <strong class="lg-wd-fe-author">' . $name . '</strong>';
        }

        return '';
    }

    /**
     * Subscribe modal — rendered outside any overflow:hidden containers.
     */
    private static function render_subscribe_modal(): void {
        echo '<div id="lg-wd-subscribe-modal" class="lg-wd-fe-modal-overlay">';
        echo '<div class="lg-wd-fe-modal">';
        echo '<button type="button" class="lg-wd-fe-modal-close">&times;</button>';
        echo '<h3 class="lg-wd-fe-modal-title">Subscribe to the Weekly Digest</h3>';
        echo '<p class="lg-wd-fe-modal-desc">Get the latest from The Looth Group delivered to your inbox every week.</p>';
        echo do_shortcode( '[fluentform id="5"]' );
        echo '</div>';
        echo '</div>';
        // Inline script for modal open/close (no jQuery dependency)
        echo '<script>
(function(){
  var modal=document.getElementById("lg-wd-subscribe-modal");
  if(!modal)return;
  document.querySelectorAll(".lg-wd-fe-subscribe-btn").forEach(function(btn){
    btn.addEventListener("click",function(){modal.style.display="flex";});
  });
  modal.addEventListener("click",function(e){if(e.target===modal)modal.style.display="none";});
  modal.querySelector(".lg-wd-fe-modal-close").addEventListener("click",function(){modal.style.display="none";});
})();
</script>';
    }

    /**
     * Shared footer renderer used by both shortcode and single view.
     */
    private static function render_footer( array $settings ): void {
        $footer_links = json_decode( $settings['footer_links'] ?? '[]', true );
        if ( ! is_array( $footer_links ) || empty( $footer_links ) ) {
            $footer_links = [
                [ 'label' => 'Website', 'url' => home_url() ],
                [ 'label' => 'Forum',   'url' => home_url( '/forum' ) ],
                [ 'label' => 'Events',  'url' => home_url( '/events' ) ],
                [ 'label' => 'Videos',  'url' => home_url( '/videos' ) ],
            ];
        }
        echo '<footer class="lg-wd-fe-footer">';
        echo '<p class="lg-wd-fe-footer-brand">THE LOOTH GROUP</p>';
        echo '<p class="lg-wd-fe-footer-links">';
        $link_html = [];
        foreach ( $footer_links as $fl ) {
            $link_html[] = '<a href="' . esc_url( $fl['url'] ) . '">' . esc_html( $fl['label'] ) . '</a>';
        }
        echo implode( ' <span class="lg-wd-fe-footer-sep">&middot;</span> ', $link_html );
        echo '</p>';
        echo '<p class="lg-wd-fe-footer-tagline">' . esc_html( $settings['from_name'] ?? 'The Looth Group' ) . ' &middot; loothgroup.com</p>';
        echo '</footer>';
    }

    // ── Sidebar ──────────────────────────────────────────────────────────────

    /**
     * Render the WordPress widget sidebar.
     * Tries the theme's primary sidebar, falls back to any registered sidebar.
     */
    private static function render_sidebar(): void {
        // Common sidebar IDs across themes (BuddyBoss uses 'sidebar')
        $sidebar_ids = [ 'sidebar', 'sidebar-1', 'primary-widget-area', 'primary' ];

        $active_id = null;
        foreach ( $sidebar_ids as $id ) {
            if ( is_active_sidebar( $id ) ) {
                $active_id = $id;
                break;
            }
        }

        if ( ! $active_id ) return;

        echo '<aside class="lg-wd-fe-sidebar widget-area">';
        dynamic_sidebar( $active_id );
        echo '</aside>';
    }

    // ── Assets ───────────────────────────────────────────────────────────────

    public static function maybe_enqueue(): void {
        // Always enqueue — widget styling applies sitewide
        wp_enqueue_style(
            'lg-wd-frontend',
            LG_WD_PLUGIN_URL . 'assets/frontend.css',
            [],
            LG_WD_VERSION
        );
    }
}
