<?php
/**
 * lg-comments-frame — comments-only view for the standalone comments modal.
 *
 * When ?lg_comments=1 on a singular post, output ONLY the comments thread + WP's
 * native comment form (no theme chrome), styled to sit inside the iframe modal the
 * standalone page opens (archive-poc/standalone/render.php → .lg-cmodal). Posting
 * uses WP's wp-comments-post.php; the redirect keeps the flag so the iframe reloads
 * the thread (not the intercepted standalone permalink).
 *
 * Brand-aligned styling lives here (not on the theme) so the modal looks the same
 * regardless of which theme comment CSS happens to load. It posts its content
 * height to the parent so the modal can hug the thread instead of floating in a
 * tall empty box.
 *
 * Deployed to /var/www/dev/wp-content/mu-plugins/. Repo copy in archive-poc/deploy/.
 */
if (!defined('ABSPATH')) exit;

add_action('template_redirect', function () {
    if (empty($_GET['lg_comments']) || !is_singular()) return;
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comments</title>
<?php wp_head(); ?>
<style>
  /* ---- frame reset + brand palette (sage #87986a / coral #c66845 / ink #1a1d1a) ---- */
  html,body{margin:0;background:#fff;color:#1a1d1a;
            font-family:'Jost',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;}
  #wpadminbar{display:none!important;}
  body.lg-cframe{padding:18px 20px 28px;max-width:680px;margin:0 auto;}
  .lg-cframe a{color:#6b7c52;}

  /* The modal chrome already shows a "Comments" title, so suppress the theme's
     own comments/reply heading + the empty reply-title to kill the dead top gap. */
  .lg-cframe .comments-title,
  .lg-cframe .comment-reply-title{display:none;}
  .lg-cframe #respond #reply-title:empty,
  .lg-cframe #reply-title small:empty{display:none;}

  /* ---- composer ---- */
  .lg-cframe .comment-respond,
  .lg-cframe #respond{margin:0 0 26px;}
  .lg-cframe .logged-in-as{display:flex;align-items:center;gap:10px;margin:0 0 12px;
    font-size:13px;color:#8a857c;}
  .lg-cframe .logged-in-as .comment-author{display:flex;align-items:center;gap:10px;
    text-decoration:none;color:#1a1d1a;}
  .lg-cframe .logged-in-as .avatar{width:34px;height:34px;border-radius:50%;}
  .lg-cframe .logged-in-as .name{font-weight:600;}
  .lg-cframe textarea#comment,
  .lg-cframe .comment-form-comment textarea{
    width:100%;box-sizing:border-box;font:inherit;font-size:15px;line-height:1.5;
    padding:12px 14px;border:1px solid #d8d2c4;border-radius:12px;background:#fbfbf8;
    color:#1a1d1a;resize:vertical;min-height:92px;}
  .lg-cframe textarea#comment:focus,
  .lg-cframe .comment-form-comment textarea:focus{
    outline:none;border-color:#87986a;background:#fff;box-shadow:0 0 0 3px rgba(135,152,106,.18);}
  .lg-cframe .form-submit{display:flex;justify-content:flex-end;align-items:center;gap:14px;margin:12px 0 0;}
  .lg-cframe .form-submit input[type=submit],
  .lg-cframe .form-submit .submit,
  .lg-cframe #submit{
    -webkit-appearance:none;appearance:none;border:0;cursor:pointer;font:inherit;
    font-weight:600;font-size:14px;padding:9px 22px;border-radius:999px;
    background:#87986a;color:#fff;transition:background .15s;}
  .lg-cframe .form-submit input[type=submit]:hover,
  .lg-cframe .form-submit .submit:hover,
  .lg-cframe #submit:hover{background:#6b7c52;}
  .lg-cframe .form-submit a{color:#9a948a;font-size:12px;}

  /* ---- thread ---- */
  .lg-cframe .comment-list{list-style:none;margin:0;padding:0;}
  .lg-cframe .comment-list .children{list-style:none;margin:6px 0 0;padding-left:20px;
    border-left:2px solid #eee7da;}
  .lg-cframe li.comment{margin:0;}
  .lg-cframe .comment-body{padding:16px 0;border-top:1px solid #eee7da;}
  .lg-cframe .comment-author.vcard{display:flex;align-items:center;gap:10px;}
  .lg-cframe .comment-author .avatar{width:40px;height:40px;border-radius:50%;}
  .lg-cframe .comment-author .fn,
  .lg-cframe .comment-author cite,
  .lg-cframe .comment-author b{font-style:normal;font-weight:700;font-size:14px;color:#1a1d1a;}
  .lg-cframe .comment-meta,
  .lg-cframe .comment-metadata{font-size:12px;color:#9a948a;}
  .lg-cframe .comment-metadata a{color:#9a948a;text-decoration:none;}
  .lg-cframe .comment-content,
  .lg-cframe .comment-text{margin:8px 0 6px;font-size:15px;line-height:1.55;}
  .lg-cframe .reply{font-size:13px;}
  .lg-cframe .comment-reply-link,
  .lg-cframe .comment-edit-link{display:inline-block;color:#6b7c52;text-decoration:none;
    font-weight:600;margin-right:14px;}
  .lg-cframe .comment-reply-link:hover,
  .lg-cframe .comment-edit-link:hover{text-decoration:underline;}
  .lg-cframe .no-comments{color:#9a948a;font-size:14px;margin:4px 0 22px;}

  /* WP swaps emoji chars for <img class="emoji"> at runtime, but core's
     print_emoji_styles (img.emoji{height:1em}) is dequeued site-wide for perf —
     so an emoji in a comment renders at full image size (600px+), blowing up the
     thread. Pin it back to text size. Mirrors the v2 bundle's post-footer rule. */
  .lg-cframe img.emoji,
  .lg-cframe img.wp-smiley{
    display:inline-block!important;width:1em!important;height:1em!important;
    margin:0 .07em!important;padding:0!important;border:none!important;
    box-shadow:none!important;background:none!important;vertical-align:-0.1em!important;}
</style>
</head>
<body class="lg-cframe">
<?php
    while (have_posts()) { the_post(); comments_template(); }
    wp_footer();
?>
<script>
/* Report content height to the standalone modal so it can size the iframe to the
   thread (no tall empty box). Posts on load + whenever the body resizes. */
(function(){
  function post(){
    var h = Math.max(
      document.body.scrollHeight,
      document.documentElement.scrollHeight
    );
    parent.postMessage({lgCommentsHeight:h}, location.origin);
  }
  window.addEventListener('load', post);
  if (window.ResizeObserver) new ResizeObserver(post).observe(document.body);
  else window.addEventListener('resize', post);
})();
</script>
</body>
</html><?php
    exit;
}, 1);

/* Stay in the comments-frame after posting (so the iframe reloads the thread, not
   the standalone permalink which has no inline comments). */
add_filter('comment_post_redirect', function ($location) {
    $ref = wp_get_referer();
    if ($ref && strpos($ref, 'lg_comments=1') !== false) {
        $location = add_query_arg('lg_comments', '1', $location);
    }
    return $location;
}, 10, 1);
