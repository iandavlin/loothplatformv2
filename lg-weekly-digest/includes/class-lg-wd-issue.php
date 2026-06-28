<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Issue
 * Registers the `weekly_email` CPT and provides the issue data model.
 * Each issue stores curated sections + post IDs as post meta.
 */
class LG_WD_Issue {

    const POST_TYPE = 'weekly_email';
    const META_KEY  = '_lg_wd_issue_data';

    // ── Init ─────────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_cpt' ] );
    }

    public static function register_cpt(): void {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'               => 'Weekly Emails',
                'singular_name'      => 'Weekly Email',
                'add_new_item'       => 'New Weekly Email',
                'edit_item'          => 'Edit Weekly Email',
                'all_items'          => 'All Issues',
                'search_items'       => 'Search Issues',
                'not_found'          => 'No issues found.',
            ],
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => false, // We add it to our custom menu
            'capability_type'    => 'post',
            'supports'           => [ 'title' ],
            'has_archive'        => true,
            'rewrite'            => [ 'slug' => 'weekly-digest', 'with_front' => false ],
            'show_in_rest'       => true,
        ] );
    }

    // ── Issue CRUD ──────────────────────────────────────────────────────────

    /**
     * Create a new issue with a default title.
     */
    public static function create( string $title = '' ): int {
        if ( ! $title ) {
            $title = 'Weekly Digest — ' . date_i18n( 'F j, Y' );
        }

        $post_id = wp_insert_post( [
            'post_type'   => self::POST_TYPE,
            'post_title'  => $title,
            'post_status' => 'draft',
        ] );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        // Initialize empty issue data
        self::save_data( $post_id, [
            'date_from'   => date( 'Y-m-d', strtotime( '-7 days' ) ),
            'date_to'     => date( 'Y-m-d' ),
            'sections'    => [],
            'status'      => 'draft',
            'sent_at'     => null,
            'campaign_id' => null,
        ] );

        return $post_id;
    }

    /**
     * Get issue data.
     */
    public static function get_data( int $post_id ): array {
        $data = get_post_meta( $post_id, self::META_KEY, true );
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        return wp_parse_args( $data, [
            'date_from'   => date( 'Y-m-d', strtotime( '-7 days' ) ),
            'date_to'     => date( 'Y-m-d' ),
            'sections'    => [],
            'status'      => 'draft',
            'sent_at'     => null,
            'campaign_id' => null,
        ] );
    }

    /**
     * Save issue data.
     */
    public static function save_data( int $post_id, array $data ): void {
        update_post_meta( $post_id, self::META_KEY, $data );
    }

    // ── Section manipulation ─────────────────────────────────────────────────

    /**
     * Auto-populate sections from the CPT registry for a date range.
     * Returns the sections array (doesn't save — caller decides).
     */
    public static function auto_populate( string $date_from, string $date_to ): array {
        $registry = LG_WD_CPT_Registry::get_all_with_overrides();
        $enabled  = array_filter( $registry, fn( $s ) => ! empty( $s['enabled'] ) );
        $sections = [];

        foreach ( $enabled as $entry ) {
            $is_header = ! empty( $entry['is_header'] );

            // Group headers have no posts
            if ( $is_header ) {
                $sections[] = [
                    'key'       => sanitize_key( $entry['slug'] ),
                    'label'     => $entry['label'],
                    'is_header' => true,
                    'slug'      => $entry['slug'],
                    'template'  => 'header',
                    'post_ids'  => [],
                ];
                continue;
            }

            $post_ids = LG_WD_Query::fetch_ids_for_section( $entry, $date_from, $date_to );

            $sections[] = [
                'key'       => sanitize_key( $entry['slug'] ),
                'label'     => $entry['label'],
                'is_header' => false,
                'slug'      => $entry['slug'],
                'template'  => $entry['template'] ?? 'card',
                'post_ids'  => $post_ids,
            ];
        }

        return $sections;
    }

    /**
     * Add a post to a section within an issue.
     */
    public static function add_post_to_section( int $post_id, string $section_key, int $add_post_id ): void {
        $data = self::get_data( $post_id );

        foreach ( $data['sections'] as &$section ) {
            if ( $section['key'] === $section_key ) {
                if ( ! in_array( $add_post_id, $section['post_ids'], true ) ) {
                    $section['post_ids'][] = $add_post_id;
                }
                break;
            }
        }

        self::save_data( $post_id, $data );
    }

    /**
     * Remove a post from a section within an issue.
     */
    public static function remove_post_from_section( int $post_id, string $section_key, int $remove_post_id ): void {
        $data = self::get_data( $post_id );

        foreach ( $data['sections'] as &$section ) {
            if ( $section['key'] === $section_key ) {
                $section['post_ids'] = array_values(
                    array_filter( $section['post_ids'], fn( $id ) => $id !== $remove_post_id )
                );
                break;
            }
        }

        self::save_data( $post_id, $data );
    }

    /**
     * Add a new section to an issue.
     */
    public static function add_section( int $post_id, array $section ): void {
        $data = self::get_data( $post_id );

        $data['sections'][] = [
            'key'          => sanitize_key( $section['key'] ?? '' ),
            'label'        => sanitize_text_field( $section['label'] ?? '' ),
            'is_header'    => ! empty( $section['is_header'] ),
            'slug'         => sanitize_text_field( $section['slug'] ?? '' ),
            'template'     => sanitize_key( $section['template'] ?? 'card' ),
            'post_ids'     => array_map( 'absint', $section['post_ids'] ?? [] ),
            'manual_items' => $section['manual_items'] ?? [],
        ];

        self::save_data( $post_id, $data );
    }

    /**
     * Remove a section from an issue by key.
     */
    public static function remove_section( int $post_id, string $section_key ): void {
        $data = self::get_data( $post_id );
        $data['sections'] = array_values(
            array_filter( $data['sections'], fn( $s ) => $s['key'] !== $section_key )
        );
        self::save_data( $post_id, $data );
    }

    /**
     * Reorder sections within an issue.
     */
    public static function reorder_sections( int $post_id, array $ordered_keys ): void {
        $data     = self::get_data( $post_id );
        $indexed  = [];

        foreach ( $data['sections'] as $section ) {
            $indexed[ $section['key'] ] = $section;
        }

        $reordered = [];
        foreach ( $ordered_keys as $key ) {
            if ( isset( $indexed[ $key ] ) ) {
                $reordered[] = $indexed[ $key ];
                unset( $indexed[ $key ] );
            }
        }

        // Append any sections not in the ordered list
        foreach ( $indexed as $section ) {
            $reordered[] = $section;
        }

        $data['sections'] = $reordered;
        self::save_data( $post_id, $data );
    }

    /**
     * Mark an issue as sent.
     */
    public static function mark_sent( int $post_id, ?int $campaign_id = null ): void {
        $data = self::get_data( $post_id );
        $data['status']      = 'sent';
        $data['sent_at']     = current_time( 'mysql' );
        $data['campaign_id'] = $campaign_id;
        self::save_data( $post_id, $data );

        wp_update_post( [
            'ID'          => $post_id,
            'post_status' => 'publish',
        ] );
    }

    /**
     * Get all issues (drafts and sent), most recent first.
     */
    public static function get_all_issues( int $limit = 50 ): array {
        $posts = get_posts( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => [ 'draft', 'publish' ],
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $issues = [];
        foreach ( $posts as $post ) {
            $data     = self::get_data( $post->ID );
            $issues[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'status'  => $data['status'] ?? ( $post->post_status === 'publish' ? 'sent' : 'draft' ),
                'date'    => get_the_date( 'M j, Y', $post ),
                'sent_at' => $data['sent_at'] ?? null,
            ];
        }

        return $issues;
    }

    /**
     * Get the latest draft issue, or null if none exists.
     */
    public static function get_latest_draft(): ?int {
        $posts = get_posts( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'draft',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );

        return ! empty( $posts ) ? $posts[0] : null;
    }
}
