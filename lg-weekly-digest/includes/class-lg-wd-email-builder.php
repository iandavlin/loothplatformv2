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

    /**
     * @param array $payload Curated content sections.
     * @param array $recap   How to resolve the per-member recap token. One of:
     *   [ 'mode' => 'token' ]                      keep `##lg_recap.section##` for
     *                                              FluentCRM to substitute per
     *                                              recipient — CAMPAIGN SENDS ONLY.
     *   [ 'mode' => 'render', 'wp_user_id' => N ]  resolve it here, for this member
     *                                              (compose preview, test send).
     *   [ 'mode' => 'strip' ]                      remove it. THE DEFAULT.
     *
     * Stripping is the default on purpose: every delivery path that is not a
     * FluentCRM campaign send would otherwise put the literal text
     * "##lg_recap.section##" in front of a member, and forgetting to pass a mode
     * must fail safe rather than fail visible.
     */
    public static function build( array $payload, array $recap = [] ): string {
        $settings = LG_WD_Settings::get_all();
        $week_str = date_i18n( 'F j, Y' );

        ob_start();
        include LG_WD_PLUGIN_DIR . 'templates/email.php';
        return self::apply_recap( ob_get_clean(), $recap );
    }

    /** Resolve (or remove) the per-member recap token — see build(). */
    private static function apply_recap( string $html, array $recap ): string {
        $mode = $recap['mode'] ?? 'strip';

        if ( $mode === 'token' ) {
            return $html;   // FluentCRM owns it from here.
        }

        $replacement = '';

        if ( $mode === 'render' && class_exists( 'LG_WD_Recap_Source' ) && class_exists( 'LG_WD_Recap' ) ) {
            $uid = (int) ( $recap['wp_user_id'] ?? 0 );
            if ( $uid > 0 ) {
                $payload = LG_WD_Recap_Source::payload_for( $uid );
                if ( $payload ) {
                    $replacement = LG_WD_Recap::render( $payload );
                }
            }
        }

        return str_replace( LG_WD_RECAP_SMARTCODE, $replacement, $html );
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
     *
     * EVERY author links to their profile at /u/{slug}. The archive is a fallback for
     * the slugless, not a destination.
     *
     * ⚠️ IT USED TO BRANCH ON POST TYPE, and that is the bug Ian reported on 2026-08-03:
     * "the username in the weeklydigest are now not going to the profile of the user.
     * They are going to the legacy archive page which sucks." Only `topic` got /u/;
     * every article and CPT fell through to /archive/?_post_author=, so the byline on
     * exactly the content the digest is built to showcase went to a filtered list
     * instead of the person.
     *
     * Verified on live before changing, because a 404 would be worse than the archive:
     * all 150+ distinct non-topic post authors resolve. The unhealed patreon_<id> slugs
     * 301 to the human profile and land 200 (/u/patreon_95515498 ->
     * /u/chip-tait-brooklyn-fretworks), and a fabricated slug 404s — so the probe
     * discriminates rather than blanket-200ing.
     *
     * Preferring the healed _looth_slug mirror over user_nicename also skips that
     * redirect hop and keeps patreon_<id> out of the visible URL.
     *
     * Returns "By <a>Author Name</a>" or "By <strong>Author Name</strong>".
     */
    public static function author_html( int $post_id ): string {
        $author_id = (int) get_post_field( 'post_author', $post_id );
        if ( ! $author_id ) return '';

        $name = esc_html( get_the_author_meta( 'display_name', $author_id ) );
        if ( ! $name ) return '';

        $nicename = get_the_author_meta( 'user_nicename', $author_id );
        $url      = '';

        // Author's public profile (/u/<slug>) — for EVERY post type, not just topics.
        // Prefer the healed _looth_slug mirror; fall back to user_nicename (same
        // convention lg-membership-chrome uses for the account chip).
        $slug = get_user_meta( $author_id, '_looth_slug', true );
        if ( ! $slug ) { $slug = $nicename; }
        if ( $slug ) {
            $url = home_url( '/u/' . rawurlencode( $slug ) );
        }

        // Slugless author only. Not a post-type case: an author with neither a healed
        // slug nor a nicename has no profile to link, and the filtered archive is the
        // one honest destination left.
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
