<?php
/**
 * Section template: Date-forward (2-column fluid hybrid)
 * Desktop: Thumbnail (left) | Event details (right)
 * Mobile:  Stacks naturally — image full-width, then details below.
 *
 * Variables: $item (array), $settings (array)
 */
defined( 'ABSPATH' ) || exit;

$title    = esc_html( $item['title'] );
$url      = esc_url( LG_WD_Email_Builder::add_utm( $item['url'] ) );
$date_raw = get_post_meta( $item['id'], 'events_start_date_and_time_', true );
$time_raw = get_post_meta( $item['id'], 'time_of_event', true );
$zoom_url = get_post_meta( $item['id'], 'zoom_url_for_looth_group_virtual_event', true );

// Image: featured image first, then first <img> in post content
$img_url = $item['thumb_url'] ?? '';
if ( ! $img_url ) {
    $post = get_post( $item['id'] );
    if ( $post && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $m ) ) {
        $img_url = $m[1];
    }
}
if ( has_post_thumbnail( $item['id'] ) ) {
    $img_url = get_the_post_thumbnail_url( $item['id'], 'large' );
}

// Parse event date
$display_date = '';
$dt_start     = null;

if ( $date_raw ) {
    $tz = new DateTimeZone( LG_WD_TIMEZONE );
    $ts = DateTime::createFromFormat( 'Ymd', $date_raw, $tz );
    if ( $ts ) {
        $display_date = $ts->format( 'l, F j, Y' );
        $dt_start     = $ts;
    }
}

if ( ! $display_date ) {
    $display_date = $item['date'];
}

// Build time string: "3pm EDT (7pm UTC)"
$time_display = '';
if ( $time_raw && $date_raw ) {
    $tz_eastern = new DateTimeZone( LG_WD_TIMEZONE );
    $tz_utc     = new DateTimeZone( 'UTC' );
    $datetime_str = $date_raw . ' ' . $time_raw;

    $formats = [ 'Ymd H:i:s', 'Ymd H:i', 'Ymd g:ia', 'Ymd g:i a', 'Ymd h:iA', 'Ymd h:i A' ];
    foreach ( $formats as $fmt ) {
        $dt = DateTime::createFromFormat( $fmt, $datetime_str, $tz_eastern );
        if ( $dt ) {
            $dt_start = clone $dt;

            $eastern_abbr = $dt->format( 'T' );
            $eastern_str  = strtolower( $dt->format( 'g' ) ) . $dt->format( ':i' );
            $eastern_str  = str_replace( ':00', '', $eastern_str );
            $eastern_str .= strtolower( $dt->format( 'A' ) ) . ' ' . $eastern_abbr;

            $dt->setTimezone( $tz_utc );
            $utc_str  = strtolower( $dt->format( 'g' ) ) . $dt->format( ':i' );
            $utc_str  = str_replace( ':00', '', $utc_str );
            $utc_str .= strtolower( $dt->format( 'A' ) ) . ' UTC';

            $time_display = $eastern_str . ' (' . $utc_str . ')';
            break;
        }
    }
} elseif ( $time_raw ) {
    $time_display = esc_html( $time_raw );
}

// Tier badge — solid pill matching post card style (sourced from payload `tier` taxonomy)
$tier_slug  = $item['tier_slug']  ?? '';
$tier_label = $item['tier_label'] ?? '';
$location   = $zoom_url ? 'Virtual Event' : 'In Person';
$tier_pill_styles = [
    'public'     => 'background:#D4E0B8;color:#3d4a2a;',
    'looth-lite' => 'background:#F5D69A;color:#6e4b0c;',
    'looth-pro'  => 'background:#2B2318;color:#ECB351;',
];
$tier_pill_style = $tier_pill_styles[ $tier_slug ] ?? '';
$tier_html = ( $tier_pill_style && $tier_label )
    ? '<span style="display:inline-block;padding:0 10px;border-radius:999px;font-size:11px;line-height:1.6;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;margin-right:6px;' . $tier_pill_style . '">' . esc_html( $tier_label ) . '</span>'
    : '';

// Author
$author_html = LG_WD_Email_Builder::author_html( $item['id'] );

// Google Calendar link
$gcal_url = '';
if ( $dt_start ) {
    $dt_utc_start = clone $dt_start;
    $dt_utc_start->setTimezone( new DateTimeZone( 'UTC' ) );
    $gcal_start = $dt_utc_start->format( 'Ymd\THis\Z' );

    $dt_utc_end = clone $dt_utc_start;
    $dt_utc_end->modify( '+1 hour' );
    $gcal_end = $dt_utc_end->format( 'Ymd\THis\Z' );

    $gcal_params = [
        'action'   => 'TEMPLATE',
        'text'     => $item['title'],
        'dates'    => $gcal_start . '/' . $gcal_end,
        'details'  => $item['url'],
        'location' => $zoom_url ?: '',
    ];
    $gcal_url = 'https://calendar.google.com/calendar/render?' . http_build_query( $gcal_params );
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
      <td width="<?php echo $thumb_width; ?>" valign="top">
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
      </td>
      <td width="<?php echo $detail_width; ?>" valign="top">
      <![endif]-->

      <table class="event-col-details" width="<?php echo $detail_width; ?>" cellpadding="0" cellspacing="0" border="0"
             align="left" style="width:<?php echo $detail_width; ?>px;max-width:100%;">
        <tr>
          <td valign="top" style="padding:0;">
            <a href="<?php echo $url; ?>" class="event-title"
               style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:600;color:#2B2318;text-decoration:none;display:block;line-height:1.35;margin-bottom:4px;"><?php echo $title; ?></a>
            <p class="event-date" style="font-size:16px;color:#5C4E3A;margin:0 0 4px;">
              <?php echo esc_html( $display_date ); ?>
              <?php if ( $time_display ) : ?>
                &middot; <?php echo $time_display; ?>
              <?php endif; ?>
              <?php if ( $gcal_url ) : ?>
                &middot; <a href="<?php echo esc_url( $gcal_url ); ?>"
                   style="display:inline-block;padding:0 10px;border-radius:999px;font-size:11px;line-height:1.6;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#ECB351;border:1px solid #ECB351;text-decoration:none;"
                   target="_blank"><span style="font-size:9px;">&#128197;</span> Add</a>
              <?php endif; ?>
            </p>
            <p class="event-meta" style="font-size:15px;color:#aaa;margin:0 0 6px;">
              <?php echo $tier_html; ?>
              <span style="color:#87986A;"><?php echo esc_html( $location ); ?></span>
              <?php if ( $author_html ) : ?>
                &middot; <?php echo $author_html; ?>
              <?php endif; ?>
            </p>
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
