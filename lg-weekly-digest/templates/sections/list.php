<?php
/**
 * Section template: List
 * Compact rows, no image. Works well for forum topics.
 * Variables: $item (array), $settings (array)
 */
defined( 'ABSPATH' ) || exit;

$title  = esc_html( $item['title'] );
$url    = esc_url( LG_WD_Email_Builder::add_utm( $item['url'] ) );
$date   = esc_html( $item['date'] );
$author_html = LG_WD_Email_Builder::author_html( $item['id'] );

$reply_count = 0;
if ( function_exists( 'bbp_get_topic_reply_count' ) ) {
    $reply_count = (int) bbp_get_topic_reply_count( $item['id'] );
}

$meta = [];
if ( $author_html )  $meta[] = $author_html;
if ( $reply_count )  $meta[] = $reply_count . ( $reply_count === 1 ? ' reply' : ' replies' );
$meta[] = $date;
$meta_html = implode( ' &middot; ', $meta );
?>
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-bottom:1px solid #EAE5DC;padding-bottom:10px;margin-bottom:10px;">
  <tr>
    <td>
      <a href="<?php echo $url; ?>" style="font-size:17px;font-weight:500;color:#2B2318;text-decoration:none;display:block;margin-bottom:3px;line-height:1.4;"><?php echo $title; ?></a>
      <p class="card-meta" style="font-size:14px;color:#aaa;margin:0;"><?php echo $meta_html; ?></p>
    </td>
  </tr>
</table>
