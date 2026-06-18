<?php
/**
 * templates/comments-v2.php
 *
 * Plugin-resident comments template — replaces the BuddyBoss theme's
 * comments.php for v2-managed posts. Emits clean markup with no report /
 * block / harassment chrome; rendering happens entirely inside the
 * post-footer block's <section class="lg-post-footer__comments">.
 *
 * Called by blocks/post-footer/render.php only when:
 *   - $show_comments is on
 *   - is_user_logged_in() (guests never see comments)
 *   - $post_id > 0
 */

if (post_password_required()) return;

/* Note: BP_Moderation + WP ULike comment-chrome filters are stripped
   site-wide at init by Plugin::strip_comment_chrome — no per-template
   work needed here. */

/* Custom callback for wp_list_comments. Emits avatar + name + date + body
   + reply link only — no edit/report/block actions. */
if (!function_exists('lg_layout_v2_render_comment')) {
    function lg_layout_v2_render_comment($comment, $args, $depth) {
        $tag = !empty($args['style']) && $args['style'] === 'div' ? 'div' : 'li';
        $author_url = get_comment_author_url($comment);
        $author_name = get_comment_author($comment);
        ?>
        <<?= $tag ?> id="comment-<?php comment_ID(); ?>" <?php comment_class('lg-comment', $comment); ?>>
          <article class="lg-comment__body">
            <div class="lg-comment__avatar"><?= get_avatar($comment, 48); ?></div>
            <div class="lg-comment__main">
              <div class="lg-comment__meta">
                <span class="lg-comment__author">
                  <?php if ($author_url): ?>
                    <a href="<?= esc_url($author_url); ?>" rel="external nofollow"><?= esc_html($author_name); ?></a>
                  <?php else: ?>
                    <?= esc_html($author_name); ?>
                  <?php endif; ?>
                </span>
                <span class="lg-comment__sep">·</span>
                <time class="lg-comment__time" datetime="<?= esc_attr(get_comment_time('c')); ?>">
                  <?= esc_html(get_comment_date('', $comment)); ?>
                </time>
                <?php if ($comment->comment_approved === '0'): ?>
                  <span class="lg-comment__pending"><?php esc_html_e('Awaiting moderation', 'lg-layout-v2'); ?></span>
                <?php endif; ?>
              </div>
              <div class="lg-comment__content">
                <?php comment_text(); ?>
              </div>
              <?php
              comment_reply_link(array_merge($args, [
                  'depth'      => $depth,
                  'max_depth'  => $args['max_depth'],
                  'reply_text' => __('Reply', 'lg-layout-v2'),
                  'before'     => '<div class="lg-comment__reply">',
                  'after'      => '</div>',
              ]));
              ?>
            </div>
          </article>
        <?php
        /* Note: NO closing </li>/</div> — WP's walker closes it. */
    }
}
?>

<div id="comments" class="lg-comments">
  <h3 class="lg-comments__title"><?php esc_html_e('Responses', 'lg-layout-v2'); ?></h3>

  <?php
  comment_form([
      'class_form'          => 'lg-comment-form',
      'class_submit'        => 'lg-comment-form__submit',
      'title_reply'         => '',
      'title_reply_before'  => '<div class="lg-comment-form__reply-title">',
      'title_reply_after'   => '</div>',
      'comment_field'       => '<p class="lg-comment-form__field"><textarea id="comment" name="comment" cols="45" rows="4" aria-required="true" placeholder="' . esc_attr__('Write a response…', 'lg-layout-v2') . '"></textarea></p>',
      'label_submit'        => __('Publish', 'lg-layout-v2'),
      'logged_in_as'        => '',
      'comment_notes_before' => '',
      'comment_notes_after'  => '',
  ]);
  ?>

  <?php if (have_comments()): ?>
    <ol class="lg-comments__list">
      <?php
      wp_list_comments([
          'callback'    => 'lg_layout_v2_render_comment',
          'style'       => 'ol',
          'short_ping'  => true,
          'avatar_size' => 48,
      ]);
      ?>
    </ol>

    <?php the_comments_navigation([
        'prev_text' => __('Older', 'lg-layout-v2'),
        'next_text' => __('Newer', 'lg-layout-v2'),
    ]); ?>

    <?php if (!comments_open()): ?>
      <p class="lg-comments__closed"><?php esc_html_e('Responses are closed.', 'lg-layout-v2'); ?></p>
    <?php endif; ?>
  <?php endif; ?>
</div>
