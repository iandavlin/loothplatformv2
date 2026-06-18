<?php
/* code-snippets #96 — "Anonymous Forum Posts - Reveal Author to Admin in BB Edit of Post" — folded verbatim */

/* Show anonymous audit info on BuddyBoss/bbPress FRONT-END topic edit screen (admin/mod only) */
add_action('bbp_theme_before_topic_form', function () {

    // Only when editing a topic (front-end edit form)
    if (!function_exists('bbp_is_topic_edit') || !bbp_is_topic_edit()) return;

    // Only for moderators/admins
    if (!current_user_can('moderate')) return;

    $topic_id = function_exists('bbp_get_topic_id') ? (int) bbp_get_topic_id() : 0;
    if ($topic_id <= 0) return;

    $uid = (int) get_post_meta($topic_id, '_ff_submitted_by_user_id', true);
    if ($uid <= 0) return;

    $u = get_userdata($uid);
    if (!$u) return;

    // Best-effort nice name
    $nice = '';
    if (function_exists('bp_core_get_user_displayname')) {
        $nice = (string) bp_core_get_user_displayname($uid);
    }
    if ($nice === '' || stripos($nice, 'patreon_') === 0) {
        $first = (string) get_user_meta($uid, 'first_name', true);
        $last  = (string) get_user_meta($uid, 'last_name', true);
        $full  = trim($first . ' ' . $last);
        if ($full !== '') $nice = $full;
    }
    if ($nice === '' || stripos($nice, 'patreon_') === 0) $nice = (string) $u->display_name;
    if ($nice === '' || stripos($nice, 'patreon_') === 0) $nice = (string) $u->user_login;

    $login = (string) $u->user_login;

    echo '<div class="ff-anon-audit" style="padding:10px 12px;margin:0 0 12px;border:1px solid #ddd;border-radius:6px;background:#fff;">';
    echo '<strong>Anonymous audit:</strong> ';
    echo esc_html($nice) . ' ';
    echo '<span style="opacity:.7">(@' . esc_html($login) . ', #' . esc_html($uid) . ')</span>';
    echo '</div>';

}, 20);
