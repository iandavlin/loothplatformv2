<?php
/**
 * Email template for LG Weekly Digest.
 * Variables available: $settings (array), $payload (array), $week_str (string)
 */
defined( 'ABSPATH' ) || exit;

$header_img_url = esc_url( $settings['header_image_url'] ?? '' );
$signoff        = nl2br( esc_html( wp_unslash( $settings['signoff'] ?? '' ) ) );
$site_url       = esc_url( home_url() );
$unsubscribe    = '##crm.unsubscribe_url##'; // FluentCRM smart code
$from_name      = esc_html( $settings['from_name'] ?? 'The Looth Group' );
$week_label     = esc_html( 'Week of ' . $week_str );
$item_count     = array_sum( array_map( fn( $p ) => count( $p['items'] ), $payload ) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?php echo esc_html( LG_WD_Email_Builder::build_subject( $payload ) ); ?></title>
<style>
  /* Progressive enhancement — these refine spacing for clients that support <style> (Apple Mail, iOS Gmail, Outlook.com) */
  /* Tablet */
  @media only screen and (max-width: 768px) {
    .email-container { width: 100% !important; max-width: 100% !important; }
    .email-body { padding: 24px 20px 8px !important; }
    .email-header { padding: 24px 20px !important; }
    .email-hero { padding: 10px 20px !important; }
    .email-signoff { padding: 8px 20px 24px !important; }
    .email-footer { padding: 20px !important; }
    .hero-left, .hero-right { display: block !important; width: 100% !important; text-align: center !important; }
    .hero-right { padding-top: 2px !important; }
    .img-cap { max-height: none !important; width: 100% !important; }
  }
  /* Phone */
  @media only screen and (max-width: 480px) {
    .email-wrapper { padding: 8px 4px !important; }
    .email-body { padding: 20px 16px 8px !important; }
    .email-header { padding: 20px 16px !important; }
    .email-hero { padding: 8px 16px !important; }
    .email-signoff { padding: 8px 16px 20px !important; }
    .email-footer { padding: 16px !important; }
    .card-row { display: block !important; width: 100% !important; }
    .card-title, .event-title { font-size: 17px !important; }
    .card-excerpt { font-size: 19px !important; }
    .card-meta { font-size: 17px !important; }
    .event-date { font-size: 18px !important; }
    .event-meta { font-size: 17px !important; }
    .sponsor-img img { max-width: 100% !important; height: auto !important; }
    /* Hybrid columns — stack on mobile */
    .event-col-thumb,
    .event-col-details { width: 100% !important; max-width: 100% !important; display: block !important; }
    .event-col-thumb td { padding-right: 0 !important; }
    .event-img { width: 100% !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#e8e2d8;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#e8e2d8;">
  <tr>
    <td class="email-wrapper" align="center" style="padding:24px 16px;">

      <!-- Email container -->
      <table class="email-container" width="100%" cellpadding="0" cellspacing="0" border="0"
             style="max-width:960px;width:100%;background-color:#FAF6EE;border-radius:8px;">

        <!-- HEADER -->
        <tr>
          <td class="email-header" align="center" style="background-color:#2B2318;padding:28px 24px;">
            <?php if ( $header_img_url ) : ?>
              <a href="<?php echo esc_url( home_url() ); ?>" style="text-decoration:none;border:0;">
                <img src="<?php echo $header_img_url; ?>" alt="<?php echo $from_name; ?>"
                     style="max-width:300px;width:100%;height:auto;display:block;margin:0 auto;border:0;outline:none;text-decoration:none;">
              </a>
            <?php else : ?>
              <p style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;
                         color:#ECB351;letter-spacing:4px;text-transform:uppercase;margin:0 0 6px;">
                THE LOOTH GROUP
              </p>
              <p style="font-size:11px;color:#87986A;letter-spacing:2px;text-transform:uppercase;margin:0;">
                <?php echo esc_html( $settings['branding_tagline'] ?? 'Guitar Repair & Restoration Community' ); ?>
              </p>
            <?php endif; ?>
          </td>
        </tr>

        <!-- HERO BAND -->
        <tr>
          <td class="email-hero" style="background-color:#ECB351;padding:10px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td class="hero-left">
                  <span style="font-size:13px;font-weight:700;color:#2B2318;
                               text-transform:uppercase;letter-spacing:1.5px;">Loothgroup Weekly</span>
                </td>
                <td class="hero-right" align="right">
                  <span style="font-size:13px;color:#5C4E3A;">
                    <?php echo $week_label; ?> &middot; <?php echo $item_count; ?> items
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td class="email-body" style="padding:28px 24px 8px;">

            <!-- Intro line -->
            <p class="card-excerpt" style="font-size:17px;color:#5C4E3A;line-height:1.6;margin:0 0 24px;
                       padding-bottom:16px;border-bottom:2px solid #ECB351;">
              <?php echo nl2br( esc_html( wp_unslash( $settings['intro_text'] ?? '' ) ) ); ?>
            </p>

            <?php foreach ( $payload as $key => $data ) : ?>
              <?php if ( ! empty( $data['is_header'] ) ) : ?>
                <?php echo LG_WD_Email_Builder::render_group_header( $data['section']['label'] ); ?>
              <?php else : ?>
                <?php echo LG_WD_Email_Builder::render_section( $data ); ?>

                <!-- Section divider -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                  <tr>
                    <td width="30%" height="1" style="font-size:0;line-height:0;">&nbsp;</td>
                    <td width="40%" height="1" style="background-color:#D4E0B8;font-size:0;line-height:0;">&nbsp;</td>
                    <td width="30%" height="1" style="font-size:0;line-height:0;">&nbsp;</td>
                  </tr>
                </table>
              <?php endif; ?>
            <?php endforeach; ?>

          </td>
        </tr>

        <!-- SIGN-OFF -->
        <tr>
          <td class="email-signoff" style="padding:8px 24px 28px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="border-top:1px solid #E2DDD3;padding-top:20px;
                            text-align:center;">
                  <p style="font-size:16px;color:#5C4E3A;font-style:italic;line-height:1.6;margin:0;">
                    <?php echo $signoff; ?>
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td class="email-footer" style="background-color:#2B2318;padding:24px;text-align:center;">
            <p style="font-family:Georgia,'Times New Roman',serif;color:#ECB351;
                       font-size:14px;letter-spacing:3px;margin:0 0 12px;">
              THE LOOTH GROUP
            </p>
            <p style="margin:0 0 12px;">
              <?php
              $footer_links = json_decode( $settings['footer_links'] ?? '[]', true );
              if ( ! is_array( $footer_links ) || empty( $footer_links ) ) {
                  $footer_links = [
                      [ 'label' => 'Website', 'url' => home_url() ],
                      [ 'label' => 'Forum',   'url' => home_url( '/forum' ) ],
                      [ 'label' => 'Events',  'url' => home_url( '/events' ) ],
                      [ 'label' => 'Videos',  'url' => home_url( '/videos' ) ],
                  ];
              }
              foreach ( $footer_links as $fl ) :
                  $fl_url = esc_url( LG_WD_Email_Builder::add_utm( $fl['url'] ) );
              ?>
              <a href="<?php echo $fl_url; ?>" style="color:#87986A;font-size:14px;text-decoration:none;margin:0 8px;"><?php echo esc_html( $fl['label'] ); ?></a>
              <?php endforeach; ?>
            </p>
            <p style="font-size:13px;color:#5C4E3A;margin:0;line-height:1.6;">
              You&rsquo;re receiving this because you subscribed to the Looth Group weekly digest.<br>
              <a href="<?php echo $unsubscribe; ?>" style="color:#87986A;text-decoration:underline;">Unsubscribe</a>
              &nbsp;&middot;&nbsp; <?php echo $from_name; ?> &nbsp;&middot;&nbsp; loothgroup.com
            </p>
          </td>
        </tr>

      </table>
      <!-- /Email container -->

    </td>
  </tr>
</table>

</body>
</html>
