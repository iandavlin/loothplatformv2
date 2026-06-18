<?php
/* code-snippets #39 — "Email for new pending posts" — folded verbatim */
add_action('transition_post_status', 'notify_on_pending', 10, 3);
function notify_on_pending($new_status, $old_status, $post) {
    if ($new_status != 'pending' || $old_status == 'pending') return;
    
    $post_type = get_post_type_object($post->post_type);
    $subject = sprintf('New %s pending review: %s', $post_type->labels->singular_name, $post->post_title);
    
    $message = sprintf(
        'A new %s is waiting for your review: %s',
        strtolower($post_type->labels->singular_name),
        get_the_title($post)
    );
    $message .= "\n\n";
    $message .= sprintf('Edit: %s', admin_url('post.php?post='.$post->ID.'&action=edit'));
    
    // Multiple recipients
    $recipients = array(
        get_option('admin_email'), // default admin
        'gerry@hazeguitars.com', // specific editor
        'manager@yoursite.com' // specific manager
    );
    
    wp_mail($recipients, $subject, $message);
}
