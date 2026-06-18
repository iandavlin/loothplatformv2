<?php
/* code-snippets #95 — "Admin - Field For Anonymous Forum Posts. " — folded verbatim */
if (!is_admin()) { return; } // was admin-scope snippet

add_action('add_meta_boxes', function () {
    add_meta_box(
        'ff_anon_audit',
        'Anonymous Post Audit',
        function ($post) {

            $uid = (int) get_post_meta($post->ID, '_ff_submitted_by_user_id', true);

            if (!$uid) {
                echo '<p>No audit data.</p>';
                return;
            }

            $u = get_userdata($uid);
            if (!$u) {
                echo '<p>Unknown user (#' . esc_html($uid) . ')</p>';
                return;
            }

            // Best-effort "nice name" resolution
            $nice = '';

            // BuddyBoss/BuddyPress display name (usually nicest)
            if (function_exists('bp_core_get_user_displayname')) {
                $nice = (string) bp_core_get_user_displayname($uid);
            }

            // Fallback: First + Last
            if ($nice === '' || stripos($nice, 'patreon_') === 0) {
                $first = (string) get_user_meta($uid, 'first_name', true);
                $last  = (string) get_user_meta($uid, 'last_name', true);
                $full  = trim($first . ' ' . $last);
                if ($full !== '') $nice = $full;
            }

            // Fallback: WP display_name
            if ($nice === '' || stripos($nice, 'patreon_') === 0) {
                $nice = (string) $u->display_name;
            }

            // Final fallback: login
            if ($nice === '' || stripos($nice, 'patreon_') === 0) {
                $nice = (string) $u->user_login;
            }

            $login = (string) $u->user_login;

            echo '<p><strong>Submitted by:</strong> ' . esc_html($nice) .
                 ' <span style="opacity:.7">(@' . esc_html($login) . ', #' . esc_html($uid) . ')</span></p>';
        },
        'topic',
        'side',
        'high'
    );
});
