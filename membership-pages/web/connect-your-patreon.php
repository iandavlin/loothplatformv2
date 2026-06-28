<?php
/**
 * /connect-your-patreon/ — dedicated Patreon-connect funnel (standalone).
 *
 * Split out of join.php's funnel (coord §3n: /join/ is slated to become the
 * Stripe join/checkout page, so the Patreon-connect entry gets its own durable
 * page). join.php's funnel is intentionally left untouched for now — this is a
 * sibling, not a move.
 *
 * PUBLIC + standalone (Ian 2026-06-12): logged-OUT patrons land here to link
 * their Patreon to a Looth account — joining (the Patreon pledge itself) and
 * connecting are two different things; every "Join" button goes straight to
 * Patreon, and THIS page owns the clear how-to + what-to-expect copy.
 * Drives the poller's authorize-entry contract (coord §3n):
 *   CTA → GET /patreon-connect?return=/connect-your-patreon/  (302 → Patreon OAuth).
 *   Callback returns to <return>?onboarded=<status>, status ∈
 *     { success | already_onboarded | not_a_patron | email_collision | fail }.
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';

$h   = 'lg_membership_h';
$ctx = lg_membership_header_ctx('');

$patreon_connect = '/patreon-connect?return=/connect-your-patreon/';   // poller authorize-entry (coord §3n)
$become_patron   = 'https://www.patreon.com/c/theloothgroup/membership';   // canonical join link (= wp_options lgpo_patreon_link)
// Stuck-contact address — the poller's configured contact (lgpo_contact_email).
$contact_email = 'info@loothgroup.com';
try {
    $st = lg_membership_db()->prepare("SELECT option_value FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "options WHERE option_name = 'lgpo_contact_email' LIMIT 1");
    $st->execute();
    $opt = (string) ($st->fetchColumn() ?: '');
    if ($opt !== '') $contact_email = $opt;
} catch (Throwable $e) {}
$manage_url      = '/manage-subscription/';
$signin_url      = '/wp-login.php?redirect_to=' . rawurlencode($manage_url);

$valid     = ['success', 'already_onboarded', 'not_a_patron', 'email_collision', 'fail'];
$onboarded = (string) ($_GET['onboarded'] ?? '');
if (!in_array($onboarded, $valid, true)) $onboarded = '';

// Reuse join.css (the funnel styles already live there; lg-join__* classes).
$asset_v = (string) (@filemtime(__DIR__ . '/join.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connect your Patreon — The Looth Group</title>
<meta name="robots" content="noindex, follow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/join.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page lg-join lg-join--<?= $h($onboarded !== '' ? $onboarded : 'start') ?>">

<?php lg_shared_render_site_header($ctx); ?>

<main id="lg-main" class="lg-join__main">
    <header class="lg-join__head">
        <h1 class="lg-join__title">Connect your Patreon</h1>
    </header>

    <?php if ($onboarded === 'success'): ?>

        <section class="lg-join__card lg-join__card--success">
            <p class="lg-join__pill lg-join__pill--ok">Account linked</p>
            <p>Your Patreon is linked and you're logged in. <strong>Set a password</strong> so you can sign in directly next time &mdash; or skip it and just reconnect with Patreon whenever you like.</p>
            <p><a class="lg-join__cta" href="<?= $h($manage_url) ?>">Go to your membership &rarr;</a></p>
        </section>

    <?php elseif ($onboarded === 'already_onboarded'): ?>

        <section class="lg-join__card lg-join__card--already">
            <p class="lg-join__pill lg-join__pill--ok">Already connected</p>
            <p>You're already connected — just sign in.</p>
            <p><a class="lg-join__cta" href="<?= $h($signin_url) ?>">Sign in &rarr;</a></p>
        </section>

    <?php elseif ($onboarded === 'not_a_patron'): ?>

        <section class="lg-join__card lg-join__card--error">
            <p class="lg-join__pill lg-join__pill--warn">No active pledge</p>
            <p>We don't see an active pledge on that Patreon account. Become a patron, then come back and connect.</p>
            <p><a class="lg-join__cta" href="<?= $h($become_patron) ?>" target="_blank" rel="noopener">Become a patron &rarr;</a></p>
            <p><a class="lg-join__link" href="<?= $h($patreon_connect) ?>">Already pledged? Try connecting again</a></p>
        </section>

    <?php elseif ($onboarded === 'email_collision' || $onboarded === 'fail'): ?>

        <section class="lg-join__card lg-join__card--pending">
            <p class="lg-join__pill lg-join__pill--warn">We're sorting this</p>
            <p>This one needs a human touch to finish linking your account. You'll hear from us shortly — nothing to do on your end.</p>
        </section>

    <?php else: /* start */ ?>

        <section class="lg-join__card lg-join__card--start">
            <p class="lg-join__lede">Already a Looth Group patron? Linking your Patreon creates your account here and unlocks everything your tier includes. It takes about two minutes.</p>

            <ol class="lg-join__steps">
                <li><strong>Make sure your pledge is active.</strong> Your membership itself lives on Patreon — if you haven't joined yet, <a class="lg-join__link" href="<?= $h($become_patron) ?>" target="_blank" rel="noopener">join on Patreon</a> first, then come back here.</li>
                <li><strong>Click the button below</strong> and authorize with the <em>same Patreon account</em> you pledge with. You'll bounce to Patreon and right back.</li>
                <li><strong>Set your password.</strong> We create your Looth account on the spot and log you straight in — then we'll show you a quick page to set a password so you can sign in directly next time (you can skip it and just reconnect with Patreon instead).</li>
                <li><strong>You're in.</strong> Your content opens up the moment you connect — no email, no waiting period.</li>
            </ol>

            <div class="lg-join__primary">
                <a class="lg-join__cta lg-join__cta--primary" href="<?= $h($patreon_connect) ?>">Connect your Patreon &rarr;</a>
            </div>

            <p class="lg-join__secondary">
                Good to know: if you've <em>just</em> pledged or changed your tier on Patreon, give it up to an hour to sync over — connecting still works right away, and your access level catches up on the next sync. Stuck? We'll sort it: <a class="lg-join__link" href="mailto:<?= $h($contact_email) ?>"><?= $h($contact_email) ?></a>.
            </p>
        </section>

    <?php endif; ?>
</main>

<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>

</body>
</html>
