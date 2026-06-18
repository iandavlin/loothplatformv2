<?php
/* code-snippets #90 — "Log Out Looth 1 Users Immediately " — folded verbatim */
/**
 * Redirect and logout looth1 users immediately on login
 */
add_action('wp_login', 'force_redirect_looth1_user', 1, 2);
function force_redirect_looth1_user($user_login, $user) {
    // Check if user has looth1 role
    if (in_array('looth1', $user->roles)) {
        // Log the user out
        wp_logout();
        
        // Redirect to custom URL
        wp_redirect('https://www.loothgroup.com/sorry');
        exit;
    }
}
