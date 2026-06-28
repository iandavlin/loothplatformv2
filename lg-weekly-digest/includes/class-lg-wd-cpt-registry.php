<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_CPT_Registry
 * Manages registered WordPress CPTs as email sections.
 * All sections are user-configured — no hardcoded built-ins.
 * Stored in wp_options as 'lg_wd_cpt_registry'.
 *
 * Each section entry:
 *   slug         - WordPress post type slug (or _header_{name} for group headers)
 *   label        - Display name in registry and email
 *   is_header    - If true, this is a group header divider (no post type, no items)
 *   max_items    - How many posts to auto-populate
 *   enabled      - Include in auto-populate and compose dropdown
 *   template     - Section template file: card, list, date-forward, sponsor, full-text
 *   tag_filter   - Optional taxonomy term slug to filter posts by (e.g. 'weeklyyes')
 *   tag_taxonomy - Taxonomy for tag_filter (e.g. 'topic-tag', 'post_tag')
 *   sort_mode    - 'newest' (by publish date) or 'upcoming' (future-first by event date meta)
 */
class LG_WD_CPT_Registry {

    const OPTION_KEY = 'lg_wd_cpt_registry';

    const TEMPLATES = [
        'card'         => 'Card (thumbnail + excerpt)',
        'list'         => 'List (compact rows)',
        'forum'        => 'Forum (16:9 image + reply count)',
        'date-forward' => 'Date-forward (events)',
        'sponsor'      => 'Sponsor / Partner',
        'full-text'    => 'Full Text (WYSIWYG)',
        'html-block'   => 'HTML Block (custom message)',
    ];

    // ── Public API ───────────────────────────────────────────────────────────

    public static function get_all(): array {
        return get_option( self::OPTION_KEY, [] );
    }

    public static function get_enabled(): array {
        return array_values( array_filter( self::get_all(), fn( $s ) => ! empty( $s['enabled'] ) ) );
    }

    public static function get_by_slug( string $slug ): ?array {
        foreach ( self::get_all() as $section ) {
            if ( $section['slug'] === $slug ) return $section;
        }
        return null;
    }

    public static function add( array $entry ): bool {
        $entry = self::sanitize_entry( $entry );
        if ( empty( $entry['slug'] ) || empty( $entry['label'] ) ) return false;
        if ( self::get_by_slug( $entry['slug'] ) ) return false;

        $custom   = get_option( self::OPTION_KEY, [] );
        $custom[] = $entry;
        update_option( self::OPTION_KEY, $custom, false );
        return true;
    }

    public static function remove( string $slug ): bool {
        $custom   = get_option( self::OPTION_KEY, [] );
        $filtered = array_filter( $custom, fn( $s ) => $s['slug'] !== $slug );

        if ( count( $filtered ) === count( $custom ) ) return false;

        update_option( self::OPTION_KEY, array_values( $filtered ), false );
        return true;
    }

    public static function update( string $slug, array $fields ): bool {
        $all     = get_option( self::OPTION_KEY, [] );
        $updated = false;

        foreach ( $all as &$entry ) {
            if ( $entry['slug'] !== $slug ) continue;
            // Merge supplied fields into existing entry, then re-sanitize
            $merged = array_merge( $entry, $fields, [ 'slug' => $slug ] );
            $entry  = self::sanitize_entry( $merged );
            $updated = true;
            break;
        }
        unset( $entry );

        if ( ! $updated ) return false;
        update_option( self::OPTION_KEY, $all, false );
        return true;
    }

    public static function reorder( array $slugs ): void {
        $all    = get_option( self::OPTION_KEY, [] );
        $keyed  = [];
        foreach ( $all as $entry ) {
            $keyed[ $entry['slug'] ] = $entry;
        }

        $sorted = [];
        foreach ( $slugs as $slug ) {
            if ( isset( $keyed[ $slug ] ) ) {
                $sorted[] = $keyed[ $slug ];
                unset( $keyed[ $slug ] );
            }
        }
        // Append any entries not in the slug list (safety net)
        foreach ( $keyed as $entry ) {
            $sorted[] = $entry;
        }

        update_option( self::OPTION_KEY, $sorted, false );
    }

    /**
     * Get all registered WP post types for the dropdown (excluding internal CPTs).
     */
    public static function get_available_post_types(): array {
        $types  = get_post_types( [ 'public' => true ], 'objects' );
        $result = [];
        foreach ( $types as $cpt ) {
            if ( in_array( $cpt->name, [ 'attachment', 'weekly_email' ], true ) ) continue;
            $result[ $cpt->name ] = $cpt->label;
        }
        // Also include non-public CPTs that have show_ui (like email_append)
        $ui_types = get_post_types( [ 'public' => false, 'show_ui' => true ], 'objects' );
        foreach ( $ui_types as $cpt ) {
            if ( in_array( $cpt->name, [ 'weekly_email', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_face', 'wp_font_family' ], true ) ) continue;
            if ( ! isset( $result[ $cpt->name ] ) ) {
                $result[ $cpt->name ] = $cpt->label . ' (private)';
            }
        }
        return $result;
    }

    // Backward-compat alias
    public static function get_all_with_overrides(): array {
        return self::get_all();
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private static function sanitize_entry( array $s ): array {
        $template = sanitize_key( $s['template'] ?? 'card' );
        if ( ! array_key_exists( $template, self::TEMPLATES ) ) {
            $template = 'card';
        }

        $sort_mode = sanitize_key( $s['sort_mode'] ?? 'newest' );
        if ( ! in_array( $sort_mode, [ 'newest', 'upcoming' ], true ) ) {
            $sort_mode = 'newest';
        }

        return [
            'slug'           => sanitize_key( $s['slug'] ?? '' ),
            'label'          => sanitize_text_field( $s['label'] ?? '' ),
            'is_header'      => ! empty( $s['is_header'] ),
            'max_items'      => absint( $s['max_items'] ?? 5 ),
            'enabled'        => ! empty( $s['enabled'] ),
            'template'       => $template,
            'tag_filter'     => sanitize_text_field( $s['tag_filter'] ?? '' ),
            'tag_taxonomy'   => sanitize_key( $s['tag_taxonomy'] ?? 'post_tag' ),
            'sort_mode'      => $sort_mode,
            'excerpt_length' => max( 0, min( 100, absint( $s['excerpt_length'] ?? 20 ) ) ),
        ];
    }
}
