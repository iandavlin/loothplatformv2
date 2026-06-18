<?php
/* code-snippets #93 — "Forum Posting Form" — folded verbatim */

/**
 * Form 38 → Create bbPress Topic + Images + Tags + Audit Meta + Activity + Redirect
 * Drop into Code Snippets → Run everywhere
 *
 * 2026-05-14: fixed missing _bbp_forum_id meta — bbp_insert_topic now receives
 * the $topic_meta second argument with forum_id, so topics are properly
 * associated with their forum and front-end edit submissions work.
 */
add_action( 'fluentform_submission_inserted', function ( $entryId, $formData, $form ) {

    $TARGET_FORM_ID = 38;
    $ANON_AUTHOR_ID = 1517;

    if ( empty( $form ) || (int) $form->id !== (int) $TARGET_FORM_ID ) return;
    if ( ! function_exists( 'bbp_insert_topic' ) ) return;

    // ------------------------------------------------------------
    // Prevent duplicate topic creation for the same FF entry
    // ------------------------------------------------------------
    $existing = get_posts( [
        'post_type'      => 'topic',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_ff_entry_id',
        'meta_value'     => (int) $entryId,
    ] );
    if ( ! empty( $existing ) ) return;

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------
    $pick_first_int = function ( array $keys ) use ( $formData ) {
        foreach ( $keys as $k ) {
            $v = $formData[ $k ] ?? '';
            if ( is_array( $v ) ) $v = reset( $v );
            $v = (int) $v;
            if ( $v > 0 ) return $v;
        }
        return 0;
    };

    $is_checked = function ( $val ) {
        if ( is_array( $val ) ) return ! empty( $val );
        $val = trim( (string) $val );
        return ( $val !== '' && $val !== '0' && strtolower( $val ) !== 'no' );
    };

    // ------------------------------------------------------------
    // Resolve destination forum
    // ------------------------------------------------------------
    $forum_id = $pick_first_int( [
        'forum_dest_repair',
        'forum_dest_builds',
        'forum_dest_tools',
        'forum_dest_business',
        'forum_dest_market',
        'forum_dest_sponsor',
    ] );

    // Special-case: Suggestion Box / Bug Reporting (single forum)
    if ( $forum_id <= 0 ) {
        $area = $formData['input_radio'] ?? '';
        if ( is_array( $area ) ) $area = reset( $area );
        $area = trim( (string) $area );
        if ( stripos( $area, 'Suggestion' ) !== false || stripos( $area, 'Bug' ) !== false ) {
            $forum_id = 4052;
        }
    }

    if ( $forum_id <= 0 ) return;

    // ------------------------------------------------------------
    // Title / Body
    // ------------------------------------------------------------
    $topic_title = sanitize_text_field( $formData['input_text'] ?? '' );
    $topic_body  = wp_kses_post( $formData['description'] ?? '' );
    if ( ! $topic_title || ! $topic_body ) return;

    // ------------------------------------------------------------
    // Flags (checkboxes)
    // ------------------------------------------------------------
    $add_coe_tag    = $is_checked( $formData['checkbox']   ?? null ); // Council
    $add_weekly_tag = $is_checked( $formData['checkbox_1'] ?? null ); // Weekly
    $post_anon      = $is_checked( $formData['checkbox_2'] ?? null ); // Anonymous

    // ------------------------------------------------------------
    // Author selection
    // ------------------------------------------------------------
    $real_user_id = get_current_user_id();
    if ( ! $real_user_id ) return;
    $author_id = $post_anon ? (int) $ANON_AUTHOR_ID : (int) $real_user_id;

    // ------------------------------------------------------------
    // Images (field name: image-upload) — mobile-safe HTML
    // ------------------------------------------------------------
    $uploads = $formData['image-upload'] ?? [];
    if ( is_string( $uploads ) ) {
        $decoded = json_decode( $uploads, true );
        $uploads = $decoded ?: [ $uploads ];
    }
    if ( ! is_array( $uploads ) ) $uploads = [ $uploads ];

    $image_urls = [];
    foreach ( $uploads as $u ) {
        if ( ! $u ) continue;
        if ( is_array( $u ) && isset( $u['url'] ) ) $u = $u['url'];
        $u = esc_url_raw( $u );
        if ( $u ) $image_urls[] = $u;
    }
    $image_urls = array_slice( array_values( array_unique( $image_urls ) ), 0, 3 );

    if ( ! empty( $image_urls ) ) {
        $topic_body .= "\n\n";
        $topic_body .= "<div class=\"ff-topic-images\" style=\"margin-top:16px;\">\n";
        $topic_body .= "<p style=\"margin:0 0 10px;\"><strong>Images</strong></p>\n";
        foreach ( $image_urls as $url ) {
            $safe = esc_url( $url );
            $topic_body .= "<p style=\"margin:0 0 12px;\">\n";
            $topic_body .= "<a href=\"{$safe}\" target=\"_blank\" rel=\"noopener noreferrer\">\n";
            $topic_body .= "<img src=\"{$safe}\" style=\"display:block;max-width:100%;height:auto;border-radius:6px;\" />\n";
            $topic_body .= "</a>\n";
            $topic_body .= "</p>\n";
        }
        $topic_body .= "</div>\n";
    }

    // ------------------------------------------------------------
    // Create topic — FIX: pass topic_meta so _bbp_forum_id gets set
    // ------------------------------------------------------------
    $topic_id = bbp_insert_topic(
        [
            'post_parent'  => (int) $forum_id,
            'post_title'   => $topic_title,
            'post_content' => $topic_body,
            'post_status'  => 'publish',
            'post_author'  => (int) $author_id,
        ],
        [
            'forum_id' => (int) $forum_id,
        ]
    );

    if ( is_wp_error( $topic_id ) || ! $topic_id ) return;

    // ------------------------------------------------------------
    // Tags (Council + Weekly + User tags)
    // ------------------------------------------------------------
    $tags = [];
    if ( $add_coe_tag )    $tags[] = 'councilyes';
    if ( $add_weekly_tag ) $tags[] = 'weeklyyes';

    $raw_tags = (string) ( $formData['topic_tags'] ?? '' );
    if ( $raw_tags !== '' ) {
        $parts = preg_split( '/\s*,\s*/', $raw_tags, -1, PREG_SPLIT_NO_EMPTY );
        $parts = array_slice( array_unique( array_map( 'sanitize_text_field', $parts ) ), 0, 12 );
        foreach ( $parts as $t ) { if ( $t !== '' ) $tags[] = $t; }
    }

    $tags = array_values( array_unique( $tags ) );
    if ( ! empty( $tags ) ) {
        wp_set_object_terms( (int) $topic_id, $tags, bbp_get_topic_tag_tax_id(), true );
    }

    // ------------------------------------------------------------
    // Audit meta (admin trace)
    // ------------------------------------------------------------
    update_post_meta( (int) $topic_id, '_ff_entry_id', (int) $entryId );
    update_post_meta( (int) $topic_id, '_ff_topic_id', (int) $topic_id );
    update_post_meta( (int) $topic_id, '_ff_submitted_by_user_id', (int) $real_user_id );

    $u = get_userdata( (int) $real_user_id );
    if ( $u ) {
        $nice  = (string) $u->display_name;
        $login = (string) $u->user_login;
        update_post_meta( (int) $topic_id, '_ff_submitted_by_user', $nice . ' (@' . $login . ', #' . (int) $real_user_id . ')' );
    }

    // ------------------------------------------------------------
    // Force BuddyBoss activity item (so it appears in feed)
    // ------------------------------------------------------------
    if ( function_exists( 'bp_is_active' ) && bp_is_active( 'activity' ) && function_exists( 'bbpress' ) ) {
        $bbp = bbpress();
        if ( ! empty( $bbp->extend->buddypress->activity ) && method_exists( $bbp->extend->buddypress->activity, 'topic_create' ) ) {
            if ( ! get_post_meta( (int) $topic_id, '_ff_activity_recorded', true ) ) {
                $activity_user_id = $post_anon ? (int) $ANON_AUTHOR_ID : (int) $real_user_id;
                $bbp->extend->buddypress->activity->topic_create(
                    (int) $topic_id,
                    (int) $forum_id,
                    [],
                    (int) $activity_user_id
                );
                update_post_meta( (int) $topic_id, '_ff_activity_recorded', 1 );
            }
        }
    }

}, 10, 3 );


/**
 * Redirect Form 38 submissions to the newly created bbPress topic
 */
add_filter( 'fluentform/submission_confirmation', function ( $returnData, $form, $confirmation, $insertId, $formData ) {

    if ( empty( $form ) || (int) $form->id !== 38 ) return $returnData;

    $topics = get_posts( [
        'post_type'      => 'topic',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_ff_entry_id',
        'meta_value'     => (int) $insertId,
        'orderby'        => 'ID',
        'order'          => 'DESC',
    ] );

    if ( empty( $topics ) ) return $returnData;

    $topic_id  = (int) $topics[0];
    $topic_url = get_permalink( $topic_id );
    if ( ! $topic_url ) return $returnData;

    $returnData['message']     = 'Redirecting…';
    $returnData['action']      = 'hide_form';
    $returnData['redirectTo']  = 'customUrl';
    $returnData['redirectUrl'] = $topic_url;

    return $returnData;

}, 10, 5 );
