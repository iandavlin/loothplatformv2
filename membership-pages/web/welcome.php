<?php
/**
 * /welcome/ — post-checkout success landing (port of [lg_subscription_success]).
 *
 * Transient destination: Slim's ReturnHandler 302's the browser here after a
 * successful checkout. Body is purely informational (provisioning already
 * happened server-side). Verbatim port of the shortcode output — only the chrome
 * changes (shared header/footer instead of the BB theme). Admin-only pre-launch.
 *
 * Query params (same contract as the shortcode):
 *   ?kind=subscription|regional_subscription|membership_annual|gift
 *   &tier=looth2|looth3  &qty=N  &expires_at=YYYY-MM-DD
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';
require __DIR__ . '/_admin-gate.php';

$h   = 'lg_membership_h';
$ctx = lg_membership_header_ctx('');
lg_membership_prelaunch_gate_or_exit($ctx);

// ---- verbatim shortcode logic (WP calls swapped for standalone equivalents) ----
$heading = "You're in!";

$kind      = isset($_GET['kind'])       ? preg_replace('/[^a-z_]/', '', (string) $_GET['kind']) : 'subscription';
$tier      = isset($_GET['tier'])       ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $_GET['tier']) : '';
$qty       = isset($_GET['qty'])        ? max(1, (int) $_GET['qty']) : 1;
$expiresAt = isset($_GET['expires_at']) ? preg_replace('/[^0-9\-]/', '', (string) $_GET['expires_at']) : '';

$tierLabel = match ($tier) {
    'looth2' => 'Looth LITE',
    'looth3' => 'Looth PRO',
    default  => 'Looth membership',
};

$headlineHtml = '';
$bodyHtml     = '';
switch ($kind) {
    case 'gift':
        $headlineHtml = sprintf(
            'Thanks for your gift purchase &mdash; <strong>%d %s</strong> code%s on the way.',
            $qty, $h($tierLabel), $qty === 1 ? '' : 's'
        );
        $bodyHtml = '<p>We just emailed your gift code' . ($qty === 1 ? '' : 's') . ' to the address you used at checkout. Each code can be redeemed at <a href="/lggift/">our redemption page</a>; share them however you like. Codes don\'t expire until they\'re redeemed.</p>';
        break;

    case 'membership_annual':
        $expiresLine = $expiresAt !== ''
            ? sprintf(' Your access runs through <strong>%s</strong>.', $h($expiresAt))
            : '';
        $headlineHtml = sprintf('Your <strong>%s</strong> annual membership is active.', $h($tierLabel));
        $bodyHtml = '<p>Thanks for joining.' . $expiresLine . ' This was a one-time purchase &mdash; you won\'t be charged again automatically. We\'ll send a reminder before your access ends.</p>';
        break;

    case 'regional_subscription':
        $headlineHtml = sprintf('Welcome &mdash; your <strong>%s</strong> regional subscription is active.', $h($tierLabel));
        $bodyHtml = '<p>Your billing region was verified and your first invoice has been charged at the regional rate. The same rate applies on every renewal.</p>';
        break;

    case 'subscription':
    default:
        $headlineHtml = sprintf('Welcome &mdash; your <strong>%s</strong> subscription is active.', $h($tierLabel));
        $bodyHtml = '<p>Thanks for joining. Your first invoice has been paid; you\'ll be billed again automatically when the next period starts.</p>';
        break;
}

$manageHint = '';
if ($kind !== 'gift') {
    $manageHint = '<p class="lg-success__manage">You can change plan, update your card, or cancel any time at <a href="/manage-subscription/">Manage Subscription</a>.</p>';
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Welcome — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="lg-membership-page lg-welcome-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
        <div class="lg-success">
            <h3 class="lg-success__heading"><?= $h($heading) ?></h3>
            <p class="lg-success__headline"><?= $headlineHtml /* already escaped */ ?></p>
            <div class="lg-success__body"><?= $bodyHtml /* intentional HTML */ ?></div>
            <?= $manageHint ?>
            <div class="lg-success__actions">
                <a class="lg-success__cta is-primary" href="/">Head to the community</a>
            </div>
        </div>

        <style>
            .lg-success { max-width: 640px; margin: 0 auto; padding: 1.5em 0; }
            .lg-success__heading { margin-top: 0; }
            .lg-success__headline { font-size: 1.15em; }
            .lg-success__body { padding: 0.8em 1em; background: rgba(0,0,0,0.04); border-radius: 6px; }
            .lg-success__manage { font-size: 0.95em; opacity: 0.85; }
            .lg-success__actions { display: flex; flex-wrap: wrap; gap: 0.6em; margin-top: 1.4em; }
            .lg-success__cta { display: inline-block; padding: 0.6em 1.1em; border-radius: 4px; text-decoration: none; border: 1px solid currentColor; }
            .lg-success__cta.is-primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; border-color: transparent; font-weight: 600; }
            .lg-success__cta.is-primary:hover { filter: brightness(0.95); }
        </style>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
