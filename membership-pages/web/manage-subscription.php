<?php
/**
 * /manage-subscription/ — standalone front controller (no WP boot).
 *
 * Read-only Patreon membership surface. At cut Stripe is dormant (coord §3h,
 * B-now/A-later), so this surface SHOWS the user's Patreon membership and
 * links out to Patreon for any mutation. No in-app form, no nonces, no
 * Stripe — those are all out of scope per the launch-critical relay.
 *
 * Reuses the membership-guide PoC shape: `config.php` + `lib/whoami.php` for
 * the chrome ctx, plus a new `lib/subscription-data.php` for the Patreon read.
 *
 * Anon visitors get a sign-in prompt. Authenticated visitors with no
 * lg_patreon_members row get a "no Patreon membership on file" state with a
 * link to Patreon. Authenticated visitors with a row see the full breakdown:
 * status pill (Active / Payment declined / Former / Not a member), tier label
 * from Patreon, last charge result + date, next charge date, monthly amount,
 * and a "Manage on Patreon" CTA that goes to lgpo_patreon_link.
 *
 * WP-templated /manage-subscription/ remains the rollback — pull this nginx
 * location, the slug falls back to the WP page render via the template_include
 * mu-plugin or the BB theme.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require __DIR__ . '/../lib/subscription-data.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';

$h           = 'lg_membership_h';
$ctx         = lg_membership_header_ctx('');                              // §0a: no top-nav slot for membership
$is_anon     = !(($ctx['authenticated'] ?? false) === true);
$wp_user_id  = (int)($ctx['wp_user_id'] ?? 0);                            // whoami may expose this; falls through to 0
$body_class  = $is_anon ? 'lgms-mg-anon' : 'lgms-mg-member';
$patreon_link = lg_membership_load_patreon_link();

// /whoami doesn't always include wp_user_id; if absent, try the cookie path:
// the wordpress_logged_in_<hash> cookie's first pipe-segment is the user_login.
// Use it to lookup wp_user_id from the WP DB (single small query, then cached
// for the request).
if (!$is_anon && $wp_user_id === 0) {
    $login = '';
    foreach ($_COOKIE as $name => $val) {
        if (strpos($name, 'wordpress_logged_in_') === 0) {
            $parts = explode('|', urldecode((string) $val), 4);
            if (!empty($parts[0])) { $login = (string) $parts[0]; break; }
        }
    }
    if ($login !== '') {
        try {
            $stmt = lg_membership_db()->prepare(
                "SELECT ID FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE user_login = ? LIMIT 1"
            );
            $stmt->execute([$login]);
            $wp_user_id = (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $wp_user_id = 0;
        }
    }
}

$membership   = $wp_user_id > 0 ? lg_membership_load_patreon_membership($wp_user_id) : null;
$status_label = lg_membership_format_status_label($membership['patron_status'] ?? null);
$status_kind  = lg_membership_format_status_kind($membership['patron_status'] ?? null);
$last_charge  = lg_membership_format_date($membership['last_charge_date'] ?? null);
$next_charge  = lg_membership_format_date($membership['next_charge_date'] ?? null);
$amount       = lg_membership_format_amount($membership['will_pay_amount_cents'] ?? null);

$asset_v = (string)(@filemtime(__DIR__ . '/manage-subscription.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Account — The Looth Group</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/manage-subscription.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page lg-manage-sub <?= $h($body_class) ?>">

<?php lg_shared_render_site_header($ctx); ?>

<main id="lg-main" class="lg-manage-sub__main">
    <header class="lg-manage-sub__head">
        <h1 class="lg-manage-sub__title">Manage Account</h1>
    </header>

    <?php if ($is_anon): ?>

        <section class="lg-manage-sub__card lg-manage-sub__card--anon">
            <p>Sign in to see your membership details.</p>
            <p><a class="lg-manage-sub__cta" href="/wp-login.php?redirect_to=<?= rawurlencode('/manage-subscription/') ?>">Sign in</a></p>
            <p class="lg-manage-sub__hint"><small>Not linked yet? <a href="/join/">Join with Patreon &rarr;</a></small></p>
        </section>

    <?php elseif ($membership === null): ?>

        <section class="lg-manage-sub__card lg-manage-sub__card--none">
            <p class="lg-manage-sub__status-pill lg-manage-sub__status-pill--none"><?= $h($status_label) ?></p>
            <p>We don't have a Patreon membership on file for this account.</p>
            <p>
                <a class="lg-manage-sub__cta" href="<?= $h($patreon_link) ?>" target="_blank" rel="noopener">
                    Become a member on Patreon &rarr;
                </a>
            </p>
            <p class="lg-manage-sub__hint"><small>Already a patron but not linked yet? <a href="/join/">Connect your Patreon &rarr;</a></small></p>
        </section>

    <?php else: ?>

        <section class="lg-manage-sub__card lg-manage-sub__card--<?= $h($status_kind) ?>">
            <p class="lg-manage-sub__status-pill lg-manage-sub__status-pill--<?= $h($status_kind) ?>"><?= $h($status_label) ?></p>

            <?php if (!empty($membership['tier_label'])): ?>
                <h2 class="lg-manage-sub__tier"><?= $h((string) $membership['tier_label']) ?></h2>
            <?php endif; ?>

            <dl class="lg-manage-sub__details">
                <?php if (!empty($membership['email'])): ?>
                    <dt>Patreon email</dt>
                    <dd><?= $h((string) $membership['email']) ?></dd>
                <?php endif; ?>
                <?php if ($amount !== ''): ?>
                    <dt>Monthly</dt>
                    <dd><?= $h($amount) ?></dd>
                <?php endif; ?>

                <?php if ($last_charge !== ''): ?>
                    <dt>Last charge</dt>
                    <dd>
                        <?= $h($last_charge) ?>
                        <?php if (!empty($membership['last_charge_status'])): ?>
                            <span class="lg-manage-sub__sublabel">&middot; <?= $h((string) $membership['last_charge_status']) ?></span>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>

                <?php if ($next_charge !== '' && ($membership['patron_status'] ?? '') === 'active_patron'): ?>
                    <dt>Next charge</dt>
                    <dd><?= $h($next_charge) ?></dd>
                <?php endif; ?>
            </dl>

            <p class="lg-manage-sub__note">
                <small>
                    Membership billing is handled by Patreon &mdash; change tier, update
                    your card, or cancel from your Patreon account.
                </small>
            </p>

            <p>
                <a class="lg-manage-sub__cta" href="<?= $h($patreon_link) ?>" target="_blank" rel="noopener">
                    Manage on Patreon &rarr;
                </a>
            </p>
            <p class="lg-manage-sub__changepw">
                <a href="/patreon-password/?change=1">Change your password &rarr;</a>
            </p>
        </section>

    <?php endif; ?>

    <p class="lg-manage-sub__poc-note">
        <small>Read-only at launch. Stripe-side controls (plan change, immediate cancel, refund request) ship with the Stripe-A-later phase.</small>
    </p>

    <?php
    // Admin-only Stripe panel — "dormant but TESTABLE" per Ian (2026-06-01).
    // Members never see this block. Admins get the legacy [lg_manage_subscription]
    // shortcode rendered inline via an iframe pointed at /__lg-stripe-panel/, an
    // admin-only URL served by the lg-membership-chrome mu-plugin. The iframe
    // boots WP for the admin tab (acceptable — admins aren't the launch-experience
    // audience that §0b protects), so the shortcode's JS / forms / nonces all
    // work without porting. Server-side gate at /__lg-stripe-panel/ re-verifies
    // manage_options, so the iframe URL is harmless if it leaks.
    $is_admin = ($ctx['capabilities']['manage_options'] ?? false) === true;
    if ($is_admin):
    ?>
        <aside class="lg-manage-sub__admin">
            <header class="lg-manage-sub__admin-head">
                <h2 class="lg-manage-sub__admin-title">Stripe controls (admin)</h2>
                <p class="lg-manage-sub__admin-sub">
                    Visible only to <code>manage_options</code>. Stripe is dormant for members at cut;
                    this panel keeps it testable in the standalone surface.
                </p>
            </header>
            <iframe
                class="lg-manage-sub__admin-iframe"
                src="/__lg-stripe-panel/"
                title="Stripe management controls"
                loading="lazy"
                referrerpolicy="same-origin"
            ></iframe>
        </aside>
    <?php endif; ?>
</main>

<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>

</body>
</html>
