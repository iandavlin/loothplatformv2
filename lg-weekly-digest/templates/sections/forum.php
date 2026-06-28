<?php
/**
 * Section template: Forum (2-column fluid hybrid)
 * Desktop: Thumbnail (left) | Title + excerpt + meta (right)
 * Mobile:  Stacks naturally — image full-width, then details below.
 * Falls back to text-only row when no image is available.
 *
 * Variables: $item (array), $settings (array)
 */
defined( 'ABSPATH' ) || exit;

$title  = esc_html( $item['title'] );
$url    = esc_url( LG_WD_Email_Builder::add_utm( $item['url'] ) );
$date   = esc_html( $item['date'] );

// Image: featured first, then first <img> in post content
$img_url = $item['thumb_url'] ?? '';
if ( ! $img_url ) {
    $post = get_post( $item['id'] );
    if ( $post && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $m ) ) {
        $img_url = $m[1];
    }
}

// Excerpt
$show_excerpt = LG_WD_Settings::get( 'show_excerpts' );
$excerpt      = $show_excerpt && ! empty( $item['excerpt'] )
    ? '<p class="card-excerpt" style="font-size:17px;color:#5C4E3A;margin:6px 0 0;line-height:1.55;">' . esc_html( $item['excerpt'] ) . '</p>'
    : '';

// bbPress meta
$author_html = LG_WD_Email_Builder::author_html( $item['id'] );
$reply_count = 0;
if ( function_exists( 'bbp_get_topic_reply_count' ) ) {
    $reply_count = (int) bbp_get_topic_reply_count( $item['id'] );
}
$meta = [];
if ( $author_html ) $meta[] = $author_html;
if ( $reply_count ) $meta[] = $reply_count . ( $reply_count === 1 ? ' reply' : ' replies' );
$meta[] = $date;
$meta_html = implode( ' &middot; ', $meta );

// Column widths — thumbnail is fixed, detail has desktop width + max-width:100% for mobile
$thumb_width  = 240;
$gutter       = 16;
$detail_width = 624;
?>
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-bottom:1px solid #EAE5DC;padding-bottom:16px;margin-bottom:16px;">
  <tr>
    <td>

      <!--[if mso]>
      <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
      <?php if ( $img_url ) : ?>
      <td width="<?php echo $thumb_width; ?>" valign="top">
      <?php endif; ?>
      <![endif]-->

      <?php if ( $img_url ) : ?>
      <table class="event-col-thumb" width="<?php echo $thumb_width; ?>" cellpadding="0" cellspacing="0" border="0"
             align="left" style="width:<?php echo $thumb_width; ?>px;max-width:<?php echo $thumb_width; ?>px;">
        <tr>
          <td style="padding:0 <?php echo $gutter; ?>px 12px 0;line-height:0;">
            <a href="<?php echo $url; ?>" style="display:block;line-height:0;">
              <img src="<?php echo esc_url( $img_url ); ?>"
                   width="<?php echo $thumb_width; ?>" class="event-img"
                   style="width:100%;height:auto;border-radius:6px;display:block;"
                   alt="<?php echo $title; ?>">
            </a>
          </td>
        </tr>
      </table>
      <?php endif; ?>

      <!--[if mso]>
      <?php if ( $img_url ) : ?>
      </td>
      <?php endif; ?>
      <td width="<?php echo $img_url ? $detail_width : '100%'; ?>" valign="top">
      <![endif]-->

      <table class="event-col-details" width="<?php echo $img_url ? $detail_width : '100%'; ?>" cellpadding="0" cellspacing="0" border="0"
             align="left" style="width:<?php echo $img_url ? $detail_width . 'px' : '100%'; ?>;max-width:100%;">
        <tr>
          <td valign="top" style="padding:0;">
            <a href="<?php echo $url; ?>" class="card-title" style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:600;color:#2B2318;text-decoration:none;display:block;line-height:1.35;"><?php echo $title; ?></a>
            <?php echo $excerpt; ?>
            <p class="card-meta" style="font-size:15px;color:#aaa;margin:6px 0 0;"><?php echo $meta_html; ?></p>
          </td>
        </tr>
      </table>

      <!--[if mso]>
      </td>
      </tr></table>
      <![endif]-->

    </td>
  </tr>
</table>
