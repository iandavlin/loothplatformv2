<?php
/* code-snippets #44 — "Patreon Tier Toggler" — folded verbatim */
/**
 * ───────────────────────── CONFIG ─────────────────────────
 * Supports multiple post types using the same access logic.
 */

// Array of all CPTs using Patreon-tier gating
if ( ! defined( 'LOOTH_CPT_LIST' ) ) {
    define( 'LOOTH_CPT_LIST', [ 'post-type-videos','post-imgcap','loothprint','member-benefit','shorty','loothcuts','useful_links','event','document' ] );
}

// Shared taxonomy slug (must be registered)
if ( ! defined( 'LOOTH_TAX_SLUG' ) ) {
    define( 'LOOTH_TAX_SLUG', 'tier' );
}

// Post meta key used by Patreon plugin to gate content
if ( ! defined( 'LOOTH_META_KEY' ) ) {
    define( 'LOOTH_META_KEY', 'patreon-level' );
}

// Term slug to tier mapping (shared across CPTs)
if ( ! defined( 'LOOTH_TERM_TIER_MAP' ) ) {
    define( 'LOOTH_TERM_TIER_MAP', [
        'free'          => 0,
        'looth-lite'    => 1,
        'looth-pro'     => 7,
        'patron-saint'  => 7,
    ]);
}

/**
 * Sync required Patreon tier based on assigned taxonomy terms.
 */
add_action(
    'set_object_terms',
    static function ( int $object_id, array $terms, array $tt_ids, string $taxonomy ) {

        // ───── Skip if this is not a listed CPT or the wrong taxonomy
        $post_type = get_post_type( $object_id );
        if ( ! in_array( $post_type, LOOTH_CPT_LIST, true ) || $taxonomy !== LOOTH_TAX_SLUG ) {
            return;
        }

        // ───── Normalize terms into clean slugs
        $slugs = array_filter( array_map(
            static function ( $term ) use ( $taxonomy ) {
                if ( is_int( $term ) || ctype_digit( (string) $term ) ) {
                    $term_obj = get_term( (int) $term, $taxonomy );
                    return ( ! is_wp_error( $term_obj ) && $term_obj ) ? $term_obj->slug : '';
                }
                return sanitize_title( $term );
            },
            (array) $terms
        ) );

        // ───── Find the highest tier from mapped terms
        $tier = 0;
        foreach ( $slugs as $slug ) {
            if ( isset( LOOTH_TERM_TIER_MAP[ $slug ] ) ) {
                $tier = max( $tier, (int) LOOTH_TERM_TIER_MAP[ $slug ] );
            }
        }

        // ───── Only update meta if value has changed
        $existing = get_post_meta( $object_id, LOOTH_META_KEY, true );
        if ( (string) $existing !== (string) $tier ) {
            update_post_meta( $object_id, LOOTH_META_KEY, $tier );
        }

    },
    10,
    4
);
