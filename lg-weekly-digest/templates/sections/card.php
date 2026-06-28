<?php
/**
 * Section template: Card (2-column fluid hybrid)
 * Desktop: Thumbnail (left) | Title + excerpt + meta (right)
 * Mobile:  Stacks naturally — image full-width, then details below.
 *
 * Variables: $item (array), $settings (array), $hide_type_label (bool)
 */
defined( 'ABSPATH' ) || exit;

$show_thumb   = LG_WD_Settings::get( 'show_thumbnails' );
$show_excerpt = LG_WD_Settings::get( 'show_excerpts' );
$title        = esc_html( $item['title'] );
$url          = esc_url( LG_WD_Email_Builder::add_utm( $item['url'] ) );
$type_label   = esc_html( $item['type_label'] );
$date         = esc_html( $item['date'] );

// Tier — solid pill (Public / Looth Lite / Looth Pro)
$tier_slug  = $item['tier_slug']  ?? '';
$tier_label = $item['tier_label'] ?? '';
$tier_pill_styles = [
    'public'     => 'background:#D4E0B8;color:#3d4a2a;',
    'looth-lite' => 'background:#F5D69A;color:#6e4b0c;',
    'looth-pro'  => 'background:#2B2318;color:#ECB351;',
];
$tier_pill_style = $tier_pill_styles[ $tier_slug ] ?? '';
$tier_html = ( $tier_pill_style && $tier_label )
    ? '<span style="display:inline-block;padding:0 10px;border-radius:999px;font-size:11px;line-height:1.6;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;' . $tier_pill_style . '">' . esc_html( $tier_label ) . '</span>'
    : '';

// Author attribution
if ( ! empty( $item['id'] ) ) {
    $author_html = LG_WD_Email_Builder::author_html( $item['id'] );
} elseif ( ! empty( $item['author_name'] ) ) {
    $a_name = esc_html( $item['author_name'] );
    $a_url  = ! empty( $item['author_url'] ) ? esc_url( LG_WD_Email_Builder::add_utm( $item['author_url'] ) ) : '';
    $author_html = $a_url
        ? 'By <a href="' . $a_url . '" style="color:#87986A;font-weight:600;text-decoration:none;">' . $a_name . '</a>'
        : 'By <strong style="color:#87986A;">' . $a_name . '</strong>';
} else {
    $author_html = '';
}

$excerpt = $show_excerpt && ! empty( $item['excerpt'] )
    ? '<p class="card-excerpt" style="font-size:17px;color:#5C4E3A;margin:6px 0 0;line-height:1.55;">' . esc_html( $item['excerpt'] ) . '</p>'
    : '';

// Meta line: "[pill] · By Author · Mar 12"
$meta_parts = [];
if ( $tier_html )   $meta_parts[] = $tier_html;
if ( $author_html ) $meta_parts[] = $author_html;
if ( $date )        $meta_parts[] = $date;
$meta_html = implode( ' &middot; ', $meta_parts );

// Image
$img_url = ( $show_thumb && ! empty( $item['thumb_url'] ) ) ? $item['thumb_url'] : '';

// Column widths — thumbnail is fixed, detail has a desktop width but max-width:100%
// so it can't overflow on narrow viewports (Gmail Android etc.).
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
            <?php if ( empty( $hide_type_label ) ) : ?>
            <p style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#87986A;margin:0 0 3px;"><?php echo $type_label; ?></p>
            <?php endif; ?>
            <a href="<?php echo $url; ?>" class="card-title" style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:600;color:#2B2318;text-decoration:none;display:block;line-height:1.35;margin-bottom:4px;"><?php echo $title; ?></a>
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
