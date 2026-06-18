<?php
/**
 * Plugin Name: BB Forum — author delete-own
 * Description: Lets a logged-in member delete their OWN bbPress topics/replies
 *              via BB REST (the forum-mirror "Delete" button). Deleting OTHERS'
 *              posts still requires delete_others_topics / delete_others_replies
 *              (moderators / admins) — untouched.
 *
 *              Stock bbPress withholds delete_topics/delete_replies from the
 *              bbp_participant role, so even own-post deletes 403. Rather than
 *              widen role caps globally, we grant a synthetic primitive
 *              (`bb_forum_delete_own`) ONLY when the current user is the post
 *              author, and hand that primitive to every logged-in user. Net
 *              effect: author-scoped delete, role-independent, fully reversible
 *              (delete this file to revert).
 *
 *              Companion to bb-mirror-sync.php: the trash/delete hook fired by
 *              the delete propagates to Postgres, dropping the row from the
 *              mirror's read queries (status filter).
 */

if (!defined('ABSPATH')) exit;

// 1. For an author acting on their OWN topic/reply, require only the synthetic
//    primitive instead of delete_topics/delete_replies.
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if (($cap === 'delete_topic' || $cap === 'delete_reply') && !empty($args[0]) && $user_id) {
        $post = get_post((int) $args[0]);
        if ($post && (int) $post->post_author === (int) $user_id) {
            return array('bb_forum_delete_own');
        }
    }
    return $caps;
}, 20, 4);

// 2. Every logged-in user holds the synthetic primitive. It is only ever
//    *required* by (1) above when author === current user, so this never
//    grants delete over someone else's post.
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user) {
    if (!empty($user->ID)) {
        $allcaps['bb_forum_delete_own'] = true;
    }
    return $allcaps;
}, 20, 4);
