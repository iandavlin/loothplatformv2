<?php
/**
 * /join/ — public membership join entry (standalone, no WP boot).
 *
 * Public/anonymous surface (dev cookie gate only — NO looth_id/login gate).
 *
 * LAUNCH shape = lean + connect-first. The PRIMARY action is Patreon connect,
 * driving the poller's authorize-entry contract (coord §3n):
 *   CTA → GET /patreon-connect?return=/join/  (302 → Patreon OAuth).
 *   Callback returns to /join/?onboarded=<status>, status ∈
 *     { success | already_onboarded | not_a_patron | email_collision | fail }.
 *
 * FUNNEL SEAM (do not remove): /join/ is slated to BECOME the Stripe join/
 * checkout page when Stripe goes live. The render is split into a PRIMARY action
 * slot + a SECONDARY slot precisely so that, at Stripe go-live, the primary slot
 * swaps to "Subscribe (Stripe checkout)" and Patreon-connect demotes into the
 * secondary slot — only the slot *contents* change, not the page shape. Stripe
 * checkout is dormant now and is intentionally NOT built here. See lg_join_primary_*.
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';

$h   = 'lg_membership_h';
$ctx = lg_membership_header_ctx('');                 // public page: anon ctx renders fine

// --- Funnel slots (the seam) -------------------------------------------------
// At Stripe go-live: set $primary_mode = 'stripe', point the primary CTA at the
// checkout entry, and the Patreon block drops to secondary. No structural change.
$primary_mode    = 'patreon';                        // 'patreon' (launch) → 'stripe' (later)
$patreon_connect = '/patreon-connect/?return=/join/'; // poller authorize-entry (coord §3n); trailing slash skips a 301 hop
// Canonical membership link — single source of truth in wp_options
// (lgpo_patreon_link), the SAME value manage-subscription's "Manage on Patreon"
// CTA uses. Was hardcoded to a wrong slug (patreon.com/loothgroup/membership,
// 404). Falls back to the campaign URL if the option is unreadable.
$become_patron = 'https://www.patreon.com/c/theloothgroup/membership';
try {
    $st = lg_membership_db()->prepare("SELECT option_value FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "options WHERE option_name = 'lgpo_patreon_link' LIMIT 1");
    $st->execute();
    $opt = (string) ($st->fetchColumn() ?: '');
    if ($opt !== '') $become_patron = $opt;
} catch (Throwable $e) {}
$manage_url      = '/manage-subscription/';
$signin_url      = '/wp-login.php?redirect_to=' . rawurlencode($manage_url);

// Onboarding result from the poller callback. Unknown/missing → the connect CTA.
$valid     = ['success', 'already_onboarded', 'not_a_patron', 'email_collision', 'fail'];
$onboarded = (string) ($_GET['onboarded'] ?? '');
if (!in_array($onboarded, $valid, true)) $onboarded = '';

$asset_v = (string) (@filemtime(__DIR__ . '/join.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Join — The Looth Group</title>
<meta name="robots" content="noindex, follow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/join.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page lg-join lg-join--<?= $h($onboarded !== '' ? $onboarded : 'start') ?>">

<?php lg_shared_render_site_header($ctx); ?>

<main id="lg-main" class="lg-join__main">
    <header class="lg-join__head">
        <h1 class="lg-join__title">Join The Looth Group</h1>
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

    <?php else: /* start — no/unknown onboarded param: the join funnel */ ?>

        <section class="lg-join__card lg-join__card--start">
            <p class="lg-join__lede">The Looth Group is a member-supported community for luthiers and instrument techs. Membership runs through Patreon.</p>

            <?php /* PRIMARY action slot — JOINING happens on Patreon (Ian 2026-06-12:
                     join and connect are two different things); Stripe "Subscribe"
                     swaps into this slot at go-live. */ ?>
            <div class="lg-join__primary">
                <?php if ($primary_mode === 'patreon'): ?>
                    <a class="lg-join__cta lg-join__cta--primary" href="<?= $h($become_patron) ?>" target="_blank" rel="noopener">Join on Patreon &rarr;</a>
                <?php endif; /* $primary_mode === 'stripe' → Subscribe (Stripe checkout) goes here at go-live */ ?>
            </div>

            <?php /* SECONDARY slot — existing patrons go link their account on the
                     dedicated instruction page. */ ?>
            <p class="lg-join__secondary">
                Already a patron? <a class="lg-join__link" href="/connect-your-patreon/">Connect your Patreon to unlock your account &rarr;</a>
            </p>
        </section>

    <?php endif; ?>
</main>

<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>

</body>
</html>
