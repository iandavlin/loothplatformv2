<?php
/**
 * Welcome email — slim version.
 * Single email at signup (UserProvisioner's plain-text "set your password"
 * email has been folded in below).
 *
 * Variables in scope (set by WelcomeMailer::renderBody):
 *   string $name, $tierLabel
 *   string $passwordResetUrl   — wp-login.php?action=rp&...  (may be '')
 *   string $guideUrl           — /membership-guide/
 *   string $manageUrl          — /manage-subscription/
 *   string $homeUrl            — site root
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Welcome to <?php echo esc_html( $tierLabel ); ?></title>
</head>
<body style="margin:0; padding:0; background:#f5f3ef; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif; color:#1f1d1a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f3ef; padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background:#ffffff; border-radius:10px; box-shadow:0 2px 14px rgba(0,0,0,0.06); padding:32px 28px;">
          <tr>
            <td>
              <h1 style="margin:0 0 14px; font-size:22px; line-height:1.3; font-weight:700;">
                Welcome to <?php echo esc_html( $tierLabel ); ?><?php echo $name !== '' ? ', ' . esc_html( $name ) : ''; ?>.
              </h1>
              <p style="margin:0 0 22px; font-size:15px; line-height:1.55; color:#3a3633;">
                Your membership is active. Two quick things to take care of:
              </p>

              <?php if ( $passwordResetUrl !== '' ) : ?>
              <p style="margin:0 0 8px;">
                <a href="<?php echo esc_url( $passwordResetUrl ); ?>" style="display:inline-block; background:#ECB351; color:#1f1d1a; text-decoration:none; font-weight:600; padding:12px 22px; border-radius:6px; font-size:15px;">
                  Set your password
                </a>
              </p>
              <p style="margin:0 0 28px; font-size:12px; color:#8a8580;">
                This link expires in 24 hours. After that you can reset your password from the login page.
              </p>
              <?php endif; ?>

              <p style="margin:0 0 28px;">
                <a href="<?php echo esc_url( $guideUrl ); ?>" style="display:inline-block; background:#87986A; color:#ffffff; text-decoration:none; font-weight:600; padding:12px 22px; border-radius:6px; font-size:15px;">
                  Explore your membership guide
                </a>
              </p>

              <p style="margin:0 0 6px; font-size:13px; color:#6a6560;">
                Need to update your card or change plan? <a href="<?php echo esc_url( $manageUrl ); ?>" style="color:#87986A;">Manage your subscription</a>.
              </p>
              <p style="margin:24px 0 0; font-size:14px; color:#3a3633;">
                Welcome aboard,<br>
                The Looth Group
              </p>
            </td>
          </tr>
        </table>
        <p style="margin:18px 0 0; font-size:11px; color:#9a948e; text-align:center;">
          <a href="<?php echo esc_url( $homeUrl ); ?>" style="color:#9a948e; text-decoration:none;"><?php echo esc_html( wp_parse_url( $homeUrl, PHP_URL_HOST ) ); ?></a>
        </p>
      </td>
    </tr>
  </table>
</body>
</html>
