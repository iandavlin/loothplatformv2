<?php
/* code-snippets #92 — "Author Link For Archive - [looth_author_archive_link]" — folded verbatim */
/* 01 */ add_shortcode('looth_author_archive_link', function () {
/* 02 */   $author_id = get_the_author_meta('ID');
/* 03 */   if (!$author_id) return '';
/* 04 */   $user = get_userdata($author_id);
/* 05 */   if (!$user || empty($user->user_nicename)) return '';
/* 06 */   return esc_url(home_url('/archive/?_post_author=' . rawurlencode($user->user_nicename)));
/* 07 */ });
