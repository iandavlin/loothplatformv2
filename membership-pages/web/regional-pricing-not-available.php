<?php
/**
 * /regional-pricing-not-available/ — port of [lg_regional_fail].
 *
 * Transient destination: a checkout-verification redirect lands here when a
 * visitor's billing region didn't qualify for regional pricing. Verbatim port
 * of the shortcode output — only the chrome changes. Admin-only pre-launch.
 *
 * Query params (same contract): ?reason, ?region_tag, ?billing_country,
 * ?issuer_country, ?standard_price_id.
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

// ---- verbatim shortcode logic (WP calls → standalone equivalents) ----
$heading = "We couldn't apply regional pricing";

$reason          = isset($_GET['reason']) ? (string) $_GET['reason'] : '';
$regionTag       = isset($_GET['region_tag']) ? preg_replace('/[^a-z_]/', '', (string) $_GET['region_tag']) : '';
$billingCountry  = isset($_GET['billing_country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $_GET['billing_country'])) : '';
$issuerCountry   = isset($_GET['issuer_country'])  ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $_GET['issuer_country']))  : '';
$standardPriceId = isset($_GET['standard_price_id']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $_GET['standard_price_id']) : '';

$billingCountry = strlen($billingCountry) === 2 ? $billingCountry : '';
$issuerCountry  = strlen($issuerCountry)  === 2 ? $issuerCountry  : '';

$joinUrl = '/lgjoin/?country=US';

// support email: lgms_refund_email, falling back to admin_email (wp_options).
$supportEmail = '';
try {
    $db   = lg_membership_db();
    $stmt = $db->prepare("SELECT option_value FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "options WHERE option_name = ? LIMIT 1");
    foreach (['lgms_refund_email', 'admin_email'] as $opt) {
        $stmt->execute([$opt]);
        $val = (string) ($stmt->fetchColumn() ?: '');
        if ($val !== '') { $supportEmail = $val; break; }
    }
} catch (Throwable $e) {
    error_log('regional-pricing-not-available: ' . $e->getMessage());
}
$supportEmail = filter_var($supportEmail, FILTER_VALIDATE_EMAIL) ? $supportEmail : '';

$regionLabel = $regionTag === 'regional_a' ? 'regional discount' : ($regionTag === 'regional_b' ? 'regional discount' : 'regional pricing');
$explanation = '';
if ($billingCountry !== '' && $issuerCountry !== '') {
    $explanation = sprintf(
        'You entered <strong>%s</strong> as your billing address, and the card you used is issued by a bank in <strong>%s</strong>. To qualify for our %s, both your billing address <em>and</em> your card issuer need to be in the same eligible region.',
        $h($billingCountry), $h($issuerCountry), $h($regionLabel)
    );
} elseif ($billingCountry !== '') {
    $explanation = sprintf(
        'You entered <strong>%s</strong> as your billing address, which isn\'t in the eligible list for our %s.',
        $h($billingCountry), $h($regionLabel)
    );
} else {
    $explanation = sprintf('We couldn\'t verify your eligibility for our %s.', $h($regionLabel));
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Regional pricing not available — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="lg-membership-page lg-regional-fail-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
        <div class="lg-regional-fail">
            <h3 class="lg-regional-fail__heading"><?= $h($heading) ?></h3>

            <p class="lg-regional-fail__intro">
                Your card wasn't charged, and the payment method has been removed from our system &mdash; nothing further is needed from you.
            </p>

            <p class="lg-regional-fail__detail"><?= $explanation /* already escaped above */ ?></p>

            <?php if ($reason !== 'region_mismatch'): ?>
                <p class="lg-regional-fail__notice" style="opacity:.7;font-size:0.9em;">
                    Note: this page is meant to be reached from a checkout-verification redirect. If you arrived here directly, the links below will get you back on track.
                </p>
            <?php endif; ?>

            <div class="lg-regional-fail__actions">
                <a class="lg-regional-fail__cta is-primary" href="<?= $h($joinUrl) ?>">
                    Subscribe at standard pricing
                </a>
                <?php if ($supportEmail !== ''): ?>
                    <a class="lg-regional-fail__cta" href="mailto:<?= $h($supportEmail) ?>?subject=<?= rawurlencode('Question about regional pricing eligibility') ?>">
                        Contact support
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($standardPriceId !== ''): ?>
                <!-- standard_price_id from referrer: <?= $h($standardPriceId) ?> -->
            <?php endif; ?>
        </div>

        <style>
            .lg-regional-fail { max-width: 640px; margin: 0 auto; padding: 1.5em 0; }
            .lg-regional-fail__heading { margin-top: 0; }
            .lg-regional-fail__intro { font-size: 1.05em; }
            .lg-regional-fail__detail { padding: 0.8em 1em; background: rgba(0,0,0,0.04); border-radius: 6px; }
            .lg-regional-fail__actions { display: flex; flex-wrap: wrap; gap: 0.6em; margin-top: 1.4em; }
            .lg-regional-fail__cta { display: inline-block; padding: 0.6em 1.1em; border-radius: 4px; text-decoration: none; border: 1px solid currentColor; }
            .lg-regional-fail__cta.is-primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; border-color: transparent; font-weight: 600; }
            .lg-regional-fail__cta.is-primary:hover { filter: brightness(0.95); }
        </style>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
