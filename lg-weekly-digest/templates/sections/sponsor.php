<?php
/**
 * Section template: Sponsor / Partner (2-column fluid hybrid)
 * Desktop: Thumbnail (left) | Partner label + title + excerpt (right)
 * Mobile:  Stacks naturally — image full-width, then details below.
 * Gold border preserved around the whole card.
 *
 * Variables: $item (array), $settings (array)
 */
defined( 'ABSPATH' ) || exit;

$title   = esc_html( $item['title'] );
$url     = esc_url( LG_WD_Email_Builder::add_utm( $item['url'] ) );
$excerpt = esc_html( $item['excerpt'] ?? '' );

// Featured image
$img_url = $item['thumb_url'] ?? '';
if ( ! $img_url ) {
    $post = get_post( $item['id'] );
    if ( $post && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $m ) ) {
        $img_url = $m[1];
    }
}

// Sponsor page URL from ACF user field on the post author
$author_id   = (int) get_post_field( 'post_author', $item['id'] );
$sponsor_url = '';
if ( $author_id ) {
    if ( function_exists( 'get_field' ) ) {
        $sponsor_url = get_field( 'tlg_sponsor_page_url', 'user_' . $author_id );
    }
    if ( ! $sponsor_url ) {
        $sponsor_url = get_user_meta( $author_id, 'tlg_sponsor_page_url', true );
    }
}

// Sponsor brand name from author's display name
$sponsor_name = $author_id ? esc_html( get_the_author_meta( 'display_name', $author_id ) ) : '';

// Sponsor label
if ( $sponsor_name && $sponsor_url ) {
    $sponsor_link = esc_url( LG_WD_Email_Builder::add_utm( $sponsor_url ) );
    $partner_label = '<a href="' . $sponsor_link . '" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:#2B2318;text-decoration:none;border-bottom:2px solid #ECB351;padding-bottom:1px;">' . $sponsor_name . '</a>';
} elseif ( $sponsor_name ) {
    $partner_label = '<span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:#2B2318;border-bottom:2px solid #ECB351;padding-bottom:1px;">' . $sponsor_name . '</span>';
} else {
    $partner_label = '<span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:#2B2318;">Partner</span>';
}

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
            <p style="margin:0 0 6px;"><?php echo $partner_label; ?></p>
            <p style="font-family:Georgia,'Times New Roman',serif;font-size:17px;font-weight:700;color:#2B2318;margin:0 0 8px;line-height:1.35;"><?php echo $title; ?></p>
            <?php if ( $excerpt ) : ?>
            <p class="card-excerpt" style="font-size:17px;color:#5C4E3A;margin:0 0 12px;line-height:1.55;"><?php echo $excerpt; ?></p>
            <?php endif; ?>
            <a href="<?php echo $url; ?>" style="font-size:15px;font-weight:600;color:#ECB351;text-decoration:none;">Learn more &#8594;</a>
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
