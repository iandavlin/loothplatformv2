<?php
/**
 * Plugin Name: Looth Dev — Sheets Bridge
 * Description: REST endpoints used by the Showrunner Tracker Google Sheet to create/update `event` posts and look up users.
 * Author: Looth Group
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Public/loopback host for the bridge's self-calls (the blocking _materialize
 * below). Sourced from /etc/looth/env (LG_PUBLIC_HOST) via lg_env() so the BOX,
 * not the code, decides it: dev2.loothgroup.com on dev, loothgroup.com on live,
 * with ZERO code edits to promote. A box brought up without /etc/looth/env falls
 * through to the literal guard (same absent-safe contract as lg-shared/lg-env.php).
 */
function lgsb_public_host(): string {
    if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
    $e = function_exists('lg_env') ? lg_env() : [];
    return (string)($e['host'] ?? '') ?: 'dev2.loothgroup.com';
}

add_action('rest_api_init', function () {

    register_rest_route('loothdev/v1', '/user-search', [
        'methods'  => 'GET',
        'permission_callback' => 'lgsb_perm_edit_posts',
        'callback' => 'lgsb_user_search',
        'args' => [
            'q'        => ['type' => 'string', 'required' => false, 'default' => ''],
            'per_page' => ['type' => 'integer', 'required' => false, 'default' => 25],
            'email'    => ['type' => 'string', 'required' => false, 'default' => ''],
        ],
    ]);

    register_rest_route('loothdev/v1', '/events', [
        'methods'  => 'POST',
        'permission_callback' => 'lgsb_perm_publish_posts',
        'callback' => 'lgsb_create_or_update_event',
    ]);
});

function lgsb_perm_edit_posts() {
    return current_user_can('edit_posts');
}

function lgsb_perm_publish_posts() {
    return current_user_can('publish_posts') && current_user_can('upload_files');
}

function lgsb_user_search(WP_REST_Request $req) {
    $email = trim((string) $req->get_param('email'));
    if ($email !== '') {
        $u = get_user_by('email', $email);
        if (!$u) return new WP_REST_Response([], 200);
        return new WP_REST_Response([lgsb_user_row($u)], 200);
    }

    $q        = trim((string) $req->get_param('q'));
    $per_page = max(1, min(100, (int) $req->get_param('per_page')));

    $args = [
        'number' => $per_page,
        'orderby' => 'display_name',
        'order' => 'ASC',
        'fields' => ['ID', 'user_login', 'user_email', 'display_name'],
    ];
    if ($q !== '') {
        $args['search'] = '*' . esc_attr($q) . '*';
        $args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
    }
    $users = get_users($args);
    $rows = array_map('lgsb_user_row', $users);
    return new WP_REST_Response($rows, 200);
}

function lgsb_user_row($u) {
    // $u may be WP_User or stdClass from get_users with fields arg
    return [
        'id'           => (int) $u->ID,
        'username'     => $u->user_login,
        'email'        => $u->user_email,
        'display_name' => $u->display_name,
    ];
}

/**
 * Body JSON:
 *   wp_post_id      (int, optional — for update)
 *   title           (string, required)
 *   author_id       (int, required)
 *   status          ('publish' | 'draft', default 'draft')
 *   start_date      (Y-m-d, required)        — stored as Ymd to match ACF field
 *   time_of_event   ('h:i a', required)      — e.g. '7:30 pm'
 *   tier            (string, required)       — taxonomy term slug or name in `tier`
 *   blurb           (string, optional)       — post_content
 *   topic           (string, optional)       — post_excerpt
 *   region          (string, optional)       — single term slug/name in `region`
 *   languages       (array<string>, optional)— term slugs/names in `language`
 *   zoom_url        (string, optional)
 *   image           (object, optional) {
 *       filename   (string)                  — e.g. "may-acoustic-guitar-builders-club-4729.jpg"
 *       mime       (string)                  — "image/jpeg" | "image/webp" | "image/png"
 *       data_b64   (string)                  — base64-encoded file bytes
 *   }
 */
function lgsb_create_or_update_event(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (!is_array($p)) return new WP_Error('bad_payload', 'JSON body required', ['status' => 400]);

    $title     = trim((string)($p['title'] ?? ''));
    $author_id = (int)($p['author_id'] ?? 0);
    $start     = trim((string)($p['start_date'] ?? ''));
    $time      = trim((string)($p['time_of_event'] ?? ''));
    $tier      = trim((string)($p['tier'] ?? ''));
    $status    = ($p['status'] ?? 'draft') === 'publish' ? 'publish' : 'draft';

    $missing = [];
    if ($title === '')     $missing[] = 'title';
    if ($author_id <= 0)   $missing[] = 'author_id';
    if ($start === '')     $missing[] = 'start_date';
    if ($time === '')      $missing[] = 'time_of_event';
    if ($tier === '')      $missing[] = 'tier';
    if ($missing) {
        return new WP_Error('missing_fields', 'Missing required fields: ' . implode(', ', $missing), ['status' => 422]);
    }

    if (!get_user_by('id', $author_id)) {
        return new WP_Error('bad_author', "No WP user with ID $author_id", ['status' => 422]);
    }

    $start_ymd = lgsb_normalize_date_ymd($start);
    if (!$start_ymd) return new WP_Error('bad_date', "Could not parse start_date: $start", ['status' => 422]);
    $time_norm = lgsb_normalize_time($time);
    if (!$time_norm) return new WP_Error('bad_time', "Could not parse time_of_event: $time", ['status' => 422]);

    $tier_term = lgsb_resolve_term($tier, 'tier');
    if (!$tier_term) return new WP_Error('bad_tier', "No `tier` term matches: $tier", ['status' => 422]);

    $post_arr = [
        'post_type'    => 'event',
        'post_title'   => $title,
        'post_status'  => $status,
        'post_author'  => $author_id,
        'post_content' => (string)($p['blurb'] ?? ''),
        'post_excerpt' => (string)($p['topic'] ?? ''),
    ];

    $existing_id = (int)($p['wp_post_id'] ?? 0);
    if ($existing_id > 0 && get_post($existing_id)) {
        $post_arr['ID'] = $existing_id;
        $post_id = wp_update_post($post_arr, true);
    } else {
        $post_id = wp_insert_post($post_arr, true);
    }
    if (is_wp_error($post_id)) return $post_id;

    update_field('field_66579fcdd963d', $start_ymd, $post_id); // start date — Ymd
    update_field('field_66647d708283b', $time_norm, $post_id); // time of event
    update_field('field_69af0fa4a346d', [$tier_term->term_id], $post_id); // event tier taxonomy field

    wp_set_object_terms($post_id, [(int)$tier_term->term_id], 'tier', false);

    if (!empty($p['region'])) {
        $rterm = lgsb_resolve_term((string)$p['region'], 'region');
        if ($rterm) {
            update_field('field_66587f95411e5', [$rterm->term_id], $post_id);
            wp_set_object_terms($post_id, [(int)$rterm->term_id], 'region', false);
        }
    }
    if (!empty($p['languages']) && is_array($p['languages'])) {
        $ids = [];
        foreach ($p['languages'] as $lang) {
            $lt = lgsb_resolve_term((string)$lang, 'language');
            if ($lt) $ids[] = (int)$lt->term_id;
        }
        if ($ids) {
            update_field('field_6658811487962', $ids, $post_id); // language field — checkbox/multi
            wp_set_object_terms($post_id, $ids, 'language', false);
        }
    }
    if (!empty($p['zoom_url'])) {
        update_field('field_66647b9a6fd15', esc_url_raw((string)$p['zoom_url']), $post_id);
    }

    if (!empty($p['image']) && is_array($p['image'])) {
        $att_id = lgsb_sideload_image_b64($p['image'], $post_id);
        if (is_wp_error($att_id)) return $att_id;
        if ($att_id) set_post_thumbnail($post_id, $att_id);
    }

    // Final, BLOCKING re-bake of the standalone blob now that the featured image
    // and all metas are committed. The async auto-materializer fires mid-create and
    // can race (an early incomplete bake landing last); this guarantees the public
    // /event/ render matches the just-published post.
    wp_remote_post('https://127.0.0.1/archive-api/v0/_materialize', [
        'method'    => 'POST',
        'timeout'   => 8,
        'blocking'  => true,
        'sslverify' => false,
        'headers'   => ['Host' => $_SERVER['HTTP_HOST'] ?? lgsb_public_host(), 'Content-Type' => 'application/json'],
        'body'      => wp_json_encode(['post_id' => (int) $post_id, 'action' => 'upsert']),
    ]);

    return new WP_REST_Response([
        'ok'         => true,
        'wp_post_id' => (int) $post_id,
        'edit_url'   => get_edit_post_link($post_id, ''),
        'view_url'   => get_permalink($post_id),
        'status'     => $status,
    ], 200);
}

function lgsb_normalize_date_ymd($s) {
    $ts = strtotime($s);
    if (!$ts) return null;
    return date('Ymd', $ts);
}

function lgsb_normalize_time($s) {
    $ts = strtotime($s);
    if (!$ts) return null;
    return strtolower(date('g:i a', $ts)); // e.g. "7:30 pm"
}

function lgsb_resolve_term($value, $taxonomy) {
    $value = trim($value);
    if ($value === '') return null;
    $term = get_term_by('slug', sanitize_title($value), $taxonomy);
    if ($term) return $term;
    $term = get_term_by('name', $value, $taxonomy);
    if ($term) return $term;
    if (ctype_digit($value)) {
        $term = get_term((int)$value, $taxonomy);
        if ($term && !is_wp_error($term)) return $term;
    }
    return null;
}

function lgsb_sideload_image_b64($img, $parent_post_id) {
    $filename = sanitize_file_name((string)($img['filename'] ?? ''));
    $mime     = (string)($img['mime'] ?? '');
    $b64      = (string)($img['data_b64'] ?? '');
    if ($filename === '' || $b64 === '') return null;

    $bytes = base64_decode($b64, true);
    if ($bytes === false) return new WP_Error('bad_image', 'image.data_b64 is not valid base64', ['status' => 422]);

    $upload = wp_upload_bits($filename, null, $bytes);
    if (!empty($upload['error'])) return new WP_Error('upload_failed', $upload['error'], ['status' => 500]);

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $att = [
        'post_mime_type' => $mime ?: (wp_check_filetype($upload['file'])['type'] ?? 'image/jpeg'),
        'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $att_id = wp_insert_attachment($att, $upload['file'], $parent_post_id);
    if (is_wp_error($att_id)) return $att_id;
    $meta = wp_generate_attachment_metadata($att_id, $upload['file']);
    wp_update_attachment_metadata($att_id, $meta);
    return (int) $att_id;
}
