<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Email_Builder
 * Renders the full HTML email from the content payload.
 * Section display is handled by pluggable template files in templates/sections/.
 */
class LG_WD_Email_Builder {

    // Brand palette (available to section templates via LG_WD_Email_Builder::GOLD etc.)
    const GOLD       = '#ECB351';
    const SAND       = '#F1DE83';
    const MINT_LIGHT = '#D4E0B8';
    const MINT_DARK  = '#87986A';
    const CORAL      = '#FE6A4F';
    const DARK       = '#2B2318';
    const MID        = '#5C4E3A';
    const LIGHT      = '#FAF6EE';

    // ── Public API ────────────────────────────────────────────────────────────

    public static function build( array $payload ): string {
        $settings = LG_WD_Settings::get_all();
        $week_str = date_i18n( 'F j, Y' );

        ob_start();
        include LG_WD_PLUGIN_DIR . 'templates/email.php';
        return ob_get_clean();
    }

    // ── Section renderer (called from email.php template) ─────────────────────

    /**
     * Render a group header divider (gold line + large label, no items).
     */
    public static function render_group_header( string $label ): string {
        $label = self::strip_emoji( $label );
        return '<div style="margin-bottom:8px;margin-top:36px;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr>'
            . '<td style="padding:0;white-space:nowrap;">'
            . '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;font-weight:700;color:#2B2318;text-transform:uppercase;letter-spacing:2px;">' . esc_html( $label ) . '</span>'
            . '</td>'
            . '<td width="100%" style="padding-left:14px;">'
            . '<div style="height:2px;background:#ECB351;"></div>'
            . '</td>'
            . '</tr>'
            . '</table>'
            . '</div>';
    }

    /**
     * Render a content section.
     * If under_header is true, the label is shown as a smaller subheading (mint).
     * If under_header is false, the label gets the full gold-line header treatment.
     */
    public static function render_section( array $data ): string {
        $section      = $data['section'];
        $items        = $data['items'];
        $is_arch      = $data['is_archive'];
        $under_header = ! empty( $data['under_header'] );
        $hide_header  = ! empty( $data['hide_header'] );

        if ( empty( $items ) ) return '';

        $settings = LG_WD_Settings::get_all();

        $archive_notice = $is_arch
            ? '<p style="font-size:11px;color:#aaa;margin:0 0 10px;font-style:italic;">From the archive</p>'
            : '';

        $template = $section['template'] ?? 'card';
        $template_file = LG_WD_PLUGIN_DIR . 'templates/sections/' . $template . '.php';

        // Safety: fall back to card if template file doesn't exist
        if ( ! file_exists( $template_file ) ) {
            $template_file = LG_WD_PLUGIN_DIR . 'templates/sections/card.php';
        }

        $rows = '';
        $hide_type_label = $under_header; // templates use this to suppress per-item CPT badge
        foreach ( $items as $item ) {
            ob_start();
            include $template_file;
            $rows .= ob_get_clean();
        }

        $label = esc_html( self::strip_emoji( $section['label'] ) );
        $html  = '<div style="margin-bottom:28px;">' . $archive_notice;

        if ( ! $hide_header ) {
            if ( $under_header ) {
                // Subheading style (mint, smaller) — appears under a group header
                $html .= '<p style="font-family:Georgia,\'Times New Roman\',serif;font-size:15px;font-weight:600;color:#87986A;text-transform:uppercase;letter-spacing:1px;margin:0 0 14px;">' . $label . '</p>';
            } else {
                // Full section header (gold line)
                $html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:16px;">'
                    . '<tr>'
                    . '<td style="padding:0;white-space:nowrap;">'
                    . '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;font-weight:700;color:#2B2318;text-transform:uppercase;letter-spacing:2px;">' . $label . '</span>'
                    . '</td>'
                    . '<td width="100%" style="padding-left:14px;">'
                    . '<div style="height:2px;background:#ECB351;"></div>'
                    . '</td>'
                    . '</tr>'
                    . '</table>';
            }
        }

        $html .= $rows . '</div>';
        return $html;
    }

    // ── UTM helper ────────────────────────────────────────────────────────────

    public static function add_utm( string $url ): string {
        if ( ! LG_WD_Settings::get( 'utm_enabled' ) ) {
            return $url;
        }

        $week_date = date_i18n( 'Y-m-d' );
        $campaign  = str_replace( '{{week_date}}', $week_date, LG_WD_Settings::get( 'utm_campaign', '' ) );

        return add_query_arg( [
            'utm_source'   => LG_WD_Settings::get( 'utm_source', 'weekly-digest' ),
            'utm_medium'   => LG_WD_Settings::get( 'utm_medium', 'email' ),
            'utm_campaign' => $campaign,
        ], $url );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Strip emoji and other symbol characters from a string.
     * Keeps letters, numbers, punctuation, and whitespace.
     */
    private static function strip_emoji( string $text ): string {
        // Remove emoji & miscellaneous symbols (broad Unicode ranges)
        $clean = preg_replace( '/[\x{1F000}-\x{1FFFF}]/u', '', $text );   // Emoticons, Dingbats, etc.
        $clean = preg_replace( '/[\x{2600}-\x{27BF}]/u', '', $clean );    // Misc Symbols & Dingbats
        $clean = preg_replace( '/[\x{FE00}-\x{FE0F}]/u', '', $clean );    // Variation Selectors
        $clean = preg_replace( '/[\x{200D}]/u', '', $clean );              // Zero-width joiner
        return trim( $clean );
    }

    /**
     * Build an author HTML snippet with contextual link.
     * - bbPress topics → BuddyBoss member forums tab (/members/{slug}/forums/)
     * - Other CPTs → author archive (/author/{nicename}/)
     * - All other authors → author archive (/author/{nicename}/)
     * Returns "By <a>Author Name</a>" or "By <strong>Author Name</strong>".
     */
    public static function author_html( int $post_id ): string {
        $author_id = (int) get_post_field( 'post_author', $post_id );
        if ( ! $author_id ) return '';

        $name = esc_html( get_the_author_meta( 'display_name', $author_id ) );
        if ( ! $name ) return '';

        $nicename = get_the_author_meta( 'user_nicename', $author_id );
        $url      = '';

        // bbPress topic → BuddyBoss member forums tab (/members/{slug}/forums/)
        if ( get_post_type( $post_id ) === 'topic' && function_exists( 'bp_core_get_user_domain' ) ) {
            $member_url = bp_core_get_user_domain( $author_id );
            if ( $member_url ) {
                $url = trailingslashit( $member_url ) . 'forums/';
            }
        }

        // Other CPTs → Search & Filter archive by author
        if ( ! $url && $nicename ) {
            $url = home_url( '/archive/?_post_author=' . $nicename );
        }

        // No valid URL → unlinked bold name
        if ( ! $url ) {
            return 'By <strong style="color:#87986A;">' . $name . '</strong>';
        }

        $url = esc_url( self::add_utm( $url ) );
        return 'By <a href="' . $url . '" style="color:#87986A;font-weight:600;text-decoration:none;">' . $name . '</a>';
    }

    // ── Subject line builder ───────────────────────────────────────────────────

    public static function build_subject( array $payload ): string {
        $template   = LG_WD_Settings::get( 'subject_template', 'The Looth Group — Week of {{week_date}}' );
        $item_count = array_sum( array_map( fn( $p ) => count( $p['items'] ), $payload ) );
        $week_date  = date_i18n( 'F j, Y' );

        return str_replace(
            [ '{{week_date}}', '{{site_name}}', '{{item_count}}' ],
            [ $week_date, get_bloginfo( 'name' ), $item_count ],
            $template
        );
    }
}
