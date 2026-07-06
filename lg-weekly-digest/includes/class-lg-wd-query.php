<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Query
 *
 * Two modes:
 *  1. Auto-populate: fetch post IDs for a date range per section (compose page).
 *  2. Issue-based: build full payload from curated post IDs (email rendering).
 */
class LG_WD_Query {

    /** Post-meta cache key for the resolved 16:9 YouTube thumb (see video_thumb_fix). */
    const YT_THUMB_META = '_lg_wd_yt_thumb';

    // ── Mode 1: Auto-populate ────────────────────────────────────────────────

    /**
     * Fetch post IDs for a single registry section within a date range.
     */
    public static function fetch_ids_for_section( array $section, string $date_from, string $date_to ): array {
        $posts = self::fetch_posts_for_section( $section, $date_from, $date_to );
        return wp_list_pluck( $posts, 'ID' );
    }

    private static function fetch_posts_for_section( array $section, string $date_from, string $date_to ): array {
        $slug      = $section['slug'] ?? '';
        $max       = (int) ( $section['max_items'] ?? 5 );
        $sort_mode = $section['sort_mode'] ?? 'newest';
        $tag       = $section['tag_filter'] ?? '';
        $taxonomy  = $section['tag_taxonomy'] ?? 'post_tag';

        if ( empty( $slug ) ) return [];

        // Resolve post type: slugs starting with '_all' query across all public types
        $post_type = str_starts_with( $slug, '_all' ) ? self::get_public_post_types() : $slug;

        // upcoming sort: future-first by event date meta (for event CPTs)
        if ( $sort_mode === 'upcoming' ) {
            return self::fetch_upcoming( $post_type, $max, $date_from, $date_to, $tag, $taxonomy );
        }

        return self::fetch_cpt_posts( $post_type, $max, $date_from, $date_to, $tag, $taxonomy );
    }

    // ── Mode 2: Issue-based ──────────────────────────────────────────────────

    /**
     * Build the full content payload from an issue's curated data.
     */
    public static function build_payload_from_issue( array $issue_data ): array {
        $sections     = $issue_data['sections'] ?? [];
        $payload      = [];
        $skip_empty   = LG_WD_Settings::get( 'skip_empty', true );
        $under_header = false; // Tracks whether we're under a group header

        foreach ( $sections as $section ) {
            $is_header = ! empty( $section['is_header'] ) || str_starts_with( $section['slug'] ?? '', '_header' );

            // Group header entry — render as divider, subsequent sections get subheadings
            if ( $is_header ) {
                $under_header = true;
                $payload[ $section['key'] ] = [
                    'section' => [
                        'key'       => $section['key'],
                        'label'     => $section['label'],
                        'template'  => 'header',
                    ],
                    'items'        => [],
                    'is_archive'   => false,
                    'is_header'    => true,
                ];
                continue;
            }

            // HTML block sections — single synthetic item with raw HTML content
            $html_content = $section['html_content'] ?? '';
            if ( ( $section['template'] ?? '' ) === 'html-block' && $html_content ) {
                $html_header = $section['html_header'] ?? '';
                $payload[ $section['key'] ] = [
                    'section' => [
                        'key'      => $section['key'],
                        'label'    => $html_header ?: $section['label'],
                        'template' => 'html-block',
                    ],
                    'items'        => [ [ 'id' => 0, 'html_content' => $html_content ] ],
                    'is_archive'   => false,
                    'under_header' => $under_header,
                    'hide_header'  => empty( $html_header ),
                ];
                continue;
            }

            $post_ids     = $section['post_ids'] ?? [];
            $manual_items = $section['manual_items'] ?? [];

            if ( empty( $post_ids ) && empty( $manual_items ) && $skip_empty ) continue;

            // Excerpt length: check issue data first, fall back to registry setting
            $excerpt_length = $section['excerpt_length'] ?? null;
            if ( $excerpt_length === null ) {
                $reg = LG_WD_CPT_Registry::get_by_slug( $section['slug'] ?? '' );
                $excerpt_length = (int) ( $reg['excerpt_length'] ?? 20 );
            } else {
                $excerpt_length = (int) $excerpt_length;
            }
            $items = self::normalize_posts_by_ids( $post_ids, $excerpt_length );

            // Append manual (external) items
            foreach ( $manual_items as $mi ) {
                $items[] = [
                    'id'          => 0,
                    'title'       => $mi['title'] ?? '',
                    'url'         => $mi['url'] ?? '',
                    'excerpt'     => $mi['excerpt'] ?? '',
                    'thumb_url'   => $mi['thumb_url'] ?? '',
                    'date'        => '',
                    'post_type'   => 'external',
                    'type_label'  => '',
                    'author_name' => $mi['author_name'] ?? '',
                    'author_url'  => $mi['author_url'] ?? '',
                ];
            }

            if ( empty( $items ) && $skip_empty ) continue;

            // Resolve template: prefer explicit, fall back from legacy 'type' field
            $template = $section['template'] ?? self::type_to_template( $section['type'] ?? '' );

            $payload[ $section['key'] ] = [
                'section' => [
                    'key'      => $section['key'],
                    'label'    => $section['label'],
                    'template' => $template,
                ],
                'items'        => $items,
                'is_archive'   => false,
                'under_header' => $under_header,
            ];
        }

        return $payload;
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public static function search_posts( string $search_term, string $post_type = '', int $limit = 20 ): array {
        $types = $post_type ? [ $post_type ] : self::get_all_registered_slugs();

        $posts = get_posts( [
            'post_type'      => $types ?: 'any',
            'post_status'    => [ 'publish', 'closed', 'open', 'archived' ],
            'posts_per_page' => $limit,
            's'              => $search_term,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $results = [];
        foreach ( $posts as $post ) {
            $results[] = [
                'id'         => $post->ID,
                'title'      => get_the_title( $post ),
                'date'       => get_the_date( 'M j, Y', $post ),
                'post_type'  => $post->post_type,
                'type_label' => self::cpt_label( $post->post_type ),
            ];
        }

        return $results;
    }

    // ── Fetchers ─────────────────────────────────────────────────────────────

    /**
     * Generic CPT fetch by date range, with optional tag filter.
     */
    private static function fetch_cpt_posts(
        string|array $post_type,
        int $max,
        string $date_from,
        string $date_to,
        string $tag = '',
        string $taxonomy = 'post_tag'
    ): array {
        $args = [
            'post_type'      => $post_type,
            'post_status'    => [ 'publish', 'closed', 'open' ], // bbPress uses 'open' and 'closed'
            'posts_per_page' => $max,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ( $date_from && $date_to ) {
            $args['date_query'] = [
                [
                    'after'     => $date_from,
                    'before'    => $date_to,
                    'inclusive' => true,
                ],
            ];
        }

        if ( $tag && $taxonomy ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => $tag,
                ],
            ];
        }

        $posts = get_posts( $args );

        // Fallback: if date range returned nothing, pull most recent
        if ( empty( $posts ) && LG_WD_Settings::get( 'fallback_enabled', true ) ) {
            unset( $args['date_query'] );
            $posts = get_posts( $args );
        }

        return $posts;
    }

    /**
     * Upcoming sort: future-first by events_start_date_and_time_ meta.
     * Respects date_from/date_to boundaries when provided.
     * Falls back to most recent past events if none upcoming.
     */
    private static function fetch_upcoming( string|array $post_type, int $max, string $date_from = '', string $date_to = '', string $tag = '', string $taxonomy = 'post_tag' ): array {
        // Use date_from as lower bound, or today if not specified
        $start = $date_from ? date( 'Ymd', strtotime( $date_from ) ) : current_time( 'Ymd' );

        $meta_query = [
            [
                'key'     => 'events_start_date_and_time_',
                'value'   => $start,
                'compare' => '>=',
                'type'    => 'DATE',
            ],
        ];

        // Cap at date_to upper bound when provided
        if ( $date_to ) {
            $meta_query[] = [
                'key'     => 'events_start_date_and_time_',
                'value'   => date( 'Ymd', strtotime( $date_to ) ),
                'compare' => '<=',
                'type'    => 'DATE',
            ];
        }

        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $max,
            'meta_key'       => 'events_start_date_and_time_',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => $meta_query,
        ];

        if ( $tag && $taxonomy ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => $tag,
                ],
            ];
        }

        $posts = get_posts( $args );

        // Fallback to most recent past events if none in range
        if ( empty( $posts ) && LG_WD_Settings::get( 'fallback_enabled', true ) ) {
            $args['meta_query'] = [
                [
                    'key'     => 'events_start_date_and_time_',
                    'value'   => $start,
                    'compare' => '<',
                    'type'    => 'DATE',
                ],
            ];
            $args['order'] = 'DESC';
            $posts = get_posts( $args );
        }

        return $posts;
    }

    // ── Normalizers ──────────────────────────────────────────────────────────

    private static function normalize_posts_by_ids( array $post_ids, int $excerpt_length = 20 ): array {
        if ( empty( $post_ids ) ) return [];

        // Use explicit post types from registry + 'any' to catch all registered CPTs.
        // 'any' alone can miss non-publicly-queryable types like bbPress topics.
        $types = self::get_all_registered_slugs();
        $types[] = 'any';

        $posts = get_posts( [
            'post_type'      => $types,
            'post__in'       => $post_ids,
            'posts_per_page' => count( $post_ids ),
            'orderby'        => 'post__in',
            'post_status'    => [ 'publish', 'closed', 'open', 'archived' ],
        ] );

        return array_map( fn( $p ) => self::normalize_post( $p, $excerpt_length ), $posts );
    }

    /**
     * Canonical Hub URL for forum content. bbPress topics/replies otherwise emit the legacy
     * BuddyBoss /groups/.../forum/topic/<slug>/ permalink, which only reaches the Hub via a
     * fragile nginx->bb-mirror->301 chain that silently falls to the bare /hub/ feed when a
     * topic is missing/stale in the PG mirror. Build /hub/<forum>/<topic>/ directly at source.
     * Non-forum post types keep get_permalink().
     */
    public static function hub_url( \WP_Post $post ): string {
        if ( $post->post_type === 'topic' && function_exists( 'bbp_get_topic_forum_id' ) ) {
            $forum_id   = (int) bbp_get_topic_forum_id( $post->ID );
            $forum_slug = $forum_id ? get_post_field( 'post_name', $forum_id ) : '';
            if ( $forum_slug && $post->post_name ) {
                return add_query_arg( 'topic', $forum_slug . '/' . $post->post_name, home_url( '/hub/' ) );
            }
        }
        if ( $post->post_type === 'reply' && function_exists( 'bbp_get_reply_topic_id' ) ) {
            $topic_id   = (int) bbp_get_reply_topic_id( $post->ID );
            $forum_id   = ( $topic_id && function_exists( 'bbp_get_topic_forum_id' ) ) ? (int) bbp_get_topic_forum_id( $topic_id ) : 0;
            $forum_slug = $forum_id ? get_post_field( 'post_name', $forum_id ) : '';
            $topic_slug = $topic_id ? get_post_field( 'post_name', $topic_id ) : '';
            if ( $forum_slug && $topic_slug ) {
                return add_query_arg( 'topic', $forum_slug . '/' . $topic_slug, home_url( '/hub/' ) );
            }
        }
        return get_permalink( $post );
    }

    public static function normalize_post( \WP_Post $post, int $excerpt_length = 20 ): array {
        $thumb_url = has_post_thumbnail( $post->ID )
            ? get_the_post_thumbnail_url( $post->ID, 'large' )
            : '';

        // Fallback: extract first <img> src from post content (e.g. bbPress topics)
        if ( ! $thumb_url && ! empty( $post->post_content ) ) {
            if ( preg_match( '/<img[^>]+src=["\']([^"\']+)/i', $post->post_content, $m ) ) {
                $thumb_url = $m[1];
            }
        }

        // Video posts: swap letterboxed 4:3 stills for a true 16:9 source
        $thumb_url = self::video_thumb_fix( $post, $thumb_url );

        $tier = self::get_tier( $post->ID );

        return [
            'id'         => $post->ID,
            'title'      => get_the_title( $post ),
            'url'        => self::hub_url( $post ),
            'excerpt'    => self::clean_excerpt( $post, $excerpt_length ),
            'thumb_url'  => $thumb_url,
            'date'       => get_the_date( 'M j', $post ),
            'post_type'  => $post->post_type,
            'type_label' => self::cpt_label( $post->post_type ),
            'tier_slug'  => $tier['slug'],
            'tier_label' => $tier['label'],
        ];
    }

    // ── Video thumbnail rescue ───────────────────────────────────────────────

    /**
     * Rescue letterboxed video thumbnails ("black bands top/bottom").
     *
     * The video ingest historically saved hero images from YouTube's 4:3
     * stills (sddefault 640x480 / hqdefault 480x360), which carry the 16:9
     * frame letterboxed inside — black bars baked into the file. The email
     * card renders thumbs at natural aspect, so the bars show (and the same
     * thumb_url feeds the web view and the PDF).
     *
     * When the stored thumb is missing or letterbox-suspect AND the post has a
     * resolvable YouTube ID, use a true 16:9 source instead: maxresdefault
     * (1280x720) when it exists, else mqdefault (320x180 — always present, no
     * bars). The probe result is cached in post meta so the HTTP HEAD runs at
     * most once per post; a later hand-set 16:9 featured image always wins
     * because non-suspect thumbs return before the cache is consulted.
     */
    private static function video_thumb_fix( \WP_Post $post, string $thumb_url ): string {
        if ( $thumb_url && ! self::thumb_is_letterbox_suspect( $post, $thumb_url ) ) {
            return $thumb_url;
        }

        $cached = get_post_meta( $post->ID, self::YT_THUMB_META, true );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $yt_id = self::youtube_id_for_post( $post );
        if ( ! $yt_id ) {
            return $thumb_url;
        }

        $base     = 'https://i.ytimg.com/vi/' . rawurlencode( $yt_id ) . '/';
        $resolved = $base . 'mqdefault.jpg'; // 320x180, exists for every video

        $head = wp_remote_head( $base . 'maxresdefault.jpg', [ 'timeout' => 3 ] );
        if ( is_wp_error( $head ) ) {
            // Network hiccup: use what we have this render, don't cache a guess.
            return $thumb_url ?: $resolved;
        }
        if ( 200 === (int) wp_remote_retrieve_response_code( $head ) ) {
            $resolved = $base . 'maxresdefault.jpg';
        }

        update_post_meta( $post->ID, self::YT_THUMB_META, $resolved );
        return $resolved;
    }

    /**
     * Is this thumb likely a letterboxed 4:3 YouTube-derived still?
     * - Any URL to YouTube's 4:3 stills (default/hqdefault/sddefault) is.
     * - For VIDEO posts only, a featured image with a 4:3-ish aspect is
     *   (the ingest re-encoded sddefault to <slug>-hero.webp at 640x480).
     *   Other CPTs keep their 4:3 photos — those are legitimate.
     */
    private static function thumb_is_letterbox_suspect( \WP_Post $post, string $thumb_url ): bool {
        if ( preg_match( '#(?:i\.ytimg\.com|img\.youtube\.com)/vi/[^/]+/(?:default|hqdefault|sddefault)\.jpg#i', $thumb_url ) ) {
            return true;
        }

        if ( $post->post_type !== 'post-type-videos' ) {
            return false;
        }

        $thumb_id = (int) get_post_thumbnail_id( $post->ID );
        if ( ! $thumb_id ) {
            return false;
        }
        $meta = wp_get_attachment_metadata( $thumb_id );
        $w    = (int) ( $meta['width'] ?? 0 );
        $h    = (int) ( $meta['height'] ?? 0 );

        return $w > 0 && $h > 0 && ( $w / $h ) < 1.55;
    }

    /**
     * Find the post's YouTube video ID. Managed video posts carry the source
     * URL in _lg_layout_v2 meta (_meta.source; meta may be a JSON string from
     * the FE editor or an array from CLI import) — fall back to scanning the
     * whole layout and post_content for any YouTube URL/still.
     */
    private static function youtube_id_for_post( \WP_Post $post ): string {
        $haystacks = [];

        $layout = get_post_meta( $post->ID, '_lg_layout_v2', true );
        if ( is_string( $layout ) && $layout !== '' ) {
            $decoded = json_decode( $layout, true );
            $layout  = is_array( $decoded ) ? $decoded : [];
        }
        if ( is_array( $layout ) && $layout ) {
            $haystacks[] = (string) ( $layout['_meta']['source'] ?? '' );
            $haystacks[] = (string) wp_json_encode( $layout );
        }
        $haystacks[] = (string) $post->post_content;

        foreach ( $haystacks as $haystack ) {
            if ( $haystack === '' ) {
                continue;
            }
            if ( preg_match( '#(?:youtu\.be/|youtube\.com/(?:watch\?[^"\'\s]*v=|embed/|shorts/)|(?:i\.ytimg\.com|img\.youtube\.com)/vi/)([A-Za-z0-9_-]{11})#', $haystack, $m ) ) {
                return $m[1];
            }
        }

        return '';
    }

    /**
     * Return the first 'tier' taxonomy term assigned to a post.
     * Falls back to empty strings if the taxonomy is missing or no term is assigned.
     */
    public static function get_tier( int $post_id ): array {
        if ( ! taxonomy_exists( 'tier' ) ) {
            return [ 'slug' => '', 'label' => '' ];
        }
        $terms = get_the_terms( $post_id, 'tier' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return [ 'slug' => '', 'label' => '' ];
        }
        $term = $terms[0];
        return [ 'slug' => $term->slug, 'label' => $term->name ];
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    /**
     * Map legacy 'type' values to template slugs for backward compat.
     */
    public static function type_to_template( string $type ): string {
        return match ( $type ) {
            'events'    => 'date-forward',
            'forum'     => 'list',
            'sponsor'   => 'sponsor',
            'spotlight' => 'card',
            default     => 'card',
        };
    }

    private static function clean_excerpt( \WP_Post $post, int $word_count = 20 ): string {
        if ( $word_count === 0 ) return '';
        $text = ! empty( $post->post_excerpt )
            ? wp_strip_all_tags( $post->post_excerpt )
            : wp_trim_words( wp_strip_all_tags( $post->post_content ), $word_count, '…' );

        // Strip any URLs (YouTube, etc.) from the excerpt
        $text = preg_replace( '#https?://\S+#i', '', $text );
        // Collapse multiple spaces left behind
        $text = trim( preg_replace( '/\s{2,}/', ' ', $text ) );

        // Ensure excerpt ends with ellipsis
        if ( $text !== '' && ! preg_match( '/[…\.\!\?]$/', $text ) ) {
            $text .= '…';
        }

        return $text;
    }

    private static function cpt_label( string $slug ): string {
        $obj = get_post_type_object( $slug );
        return $obj ? ( $obj->labels->singular_name ?? ucfirst( $slug ) ) : ucfirst( $slug );
    }

    private static function get_public_post_types(): array {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        // Also include bbPress types that aren't technically public
        if ( post_type_exists( 'topic' ) ) $types['topic'] = 'topic';
        if ( post_type_exists( 'reply' ) ) $types['reply'] = 'reply';
        return array_values( $types );
    }

    private static function get_all_registered_slugs(): array {
        $slugs = [];
        foreach ( LG_WD_CPT_Registry::get_all() as $entry ) {
            $slugs[] = $entry['slug'];
        }
        return array_unique( array_filter( $slugs ) );
    }
}
