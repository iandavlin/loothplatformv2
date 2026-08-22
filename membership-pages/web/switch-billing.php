<?php
/**
 * /switch-billing/ — standalone front controller (no WP boot).
 *
 * WHY THIS PAGE EXISTS.  Ian, 2026-08-22, verbatim on issue #196:
 *
 *     "Can you check and see if a user that has a patreon would have a menu for
 *      join in the profile chip? If so we need to change that to switch and give
 *      people a page with instructions for Patreon deactivation and reactivation
 *      through stripe."
 *
 * The header's Switch control (lg-shared/site-header.php, $tester_join_href)
 * points here for a member Patreon is already charging. Its whole job is to
 * SEQUENCE the move so the member is never charged twice and never left short —
 * #149's dual-holder failure class stated forwards.
 *
 * ⚠️ THE SEAM IS REAL AND THE PAGE SAYS SO. Ian, 2026-08-19: "We should disallow
 * double payment source for the same user", enforced at checkout by #150. So a
 * member CANNOT hold both rails to bridge the gap: cancelling on Patreon and
 * rejoining here necessarily meets at the lapse date. Pretending otherwise would
 * be the one thing a page about money must not do, so it names the date, says
 * what happens if they are late, and says nothing is deleted.
 *
 * SHAPE: manage-subscription.php's, deliberately (issue build item 2) —
 * config.php + lib/whoami.php for the chrome ctx, lib/subscription-data.php for
 * the Patreon read, its own <main>, shared header/footer, own stylesheet.
 *
 * DATA: lg_membership_patreon_standing() and lg_membership_load_patreon_
 * membership(), BOTH ALREADY IN THIS APP. No new detection and no new query —
 * the first is this app's mirror of LGMS\Membership\PatreonStanding and gate 75
 * keeps the two honest against each other. A third definition of "already
 * paying" is exactly what MEMBERSHIP.md forbids.
 *
 * GATE: the router registers this slug and the nginx location regex must list
 * it. Gate 93 §E asserts the header's href, the router's registry and the nginx
 * regex all name the same slug, because "wired perfectly and lands nowhere" is
 * the named failure class on this surface.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require __DIR__ . '/../lib/subscription-data.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';

$h            = 'lg_membership_h';
$ctx          = lg_membership_header_ctx('');       // §0a: no top-nav slot for membership
$is_anon      = !(($ctx['authenticated'] ?? false) === true);
$wp_user_id   = (int)($ctx['wp_user_id'] ?? 0);
$patreon_link = lg_membership_load_patreon_link();

/**
 * The same standing the MENU keyed on, asked again here.
 *
 * Not belt-and-braces: the menu's answer rode a cached whoami and this page can
 * be reached by a bookmark, by a link a member was sent, or by somebody whose
 * pledge lapsed since the menu was drawn. A page of cancel-your-Patreon
 * instructions shown to somebody with no Patreon is worse than no page, so the
 * page decides for itself and renders a different body when the premise is gone.
 */
$standing   = $wp_user_id > 0 ? lg_membership_patreon_standing($wp_user_id) : ['active' => false];
$is_paying  = ($standing['active'] ?? false) === true;
$membership = $wp_user_id > 0 ? lg_membership_load_patreon_membership($wp_user_id) : null;

$tier_label = (string)($standing['tier_label'] ?? ($membership['tier_label'] ?? ''));
/* Only an ACTIVE pledge has a meaningful next charge — for a former patron the
   column is a fossil of the last one, and printing it as "your membership runs
   until" would be a promise of access they no longer have. */
$lapse_date = $is_paying ? lg_membership_format_date($membership['next_charge_date'] ?? null) : '';

$asset_v = (string)(@filemtime(__DIR__ . '/switch-billing.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Switching to card billing — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/switch-billing.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page lg-switch <?= $is_anon ? 'lg-switch--anon' : 'lg-switch--member' ?>">

<?php lg_shared_render_site_header($ctx); ?>

<main id="lg-main" class="lg-switch__main">
    <header class="lg-switch__head">
        <h1 class="lg-switch__title">Switching from Patreon to card billing</h1>
    </header>

<?php if ($is_anon): ?>

    <section class="lg-switch__card">
        <p>Sign in to see the steps for your own membership.</p>
        <p><a class="lg-switch__cta" href="/wp-login.php?redirect_to=<?= rawurlencode('/switch-billing/') ?>">Sign in</a></p>
    </section>

<?php elseif (!$is_paying): ?>

    <?php /* THE PREMISE IS GONE. No Patreon pledge means nothing to switch away
             from, and the instructions below would be nonsense. Send them at the
             ordinary join door instead of leaving them on a page about
             cancelling something they do not have. */ ?>
    <section class="lg-switch__card">
        <p>This page is for members who pay through Patreon, and we don&rsquo;t have an
           active Patreon pledge on file for your account.</p>
        <p>If you&rsquo;d like to become a member, you can do that here.</p>
        <p>
            <a class="lg-switch__cta" href="/lgjoin/">Join &rarr;</a>
            <a class="lg-switch__cta lg-switch__cta--secondary" href="/manage-subscription/">See my membership &rarr;</a>
        </p>
    </section>

<?php else: ?>

    <section class="lg-switch__card lg-switch__card--intro">
        <p class="lg-switch__lede">
            You&rsquo;re a member through <b>Patreon</b><?= $tier_label !== '' ? ' &mdash; ' . $h($tier_label) : '' ?>.
            <?php if ($lapse_date !== ''): ?>
                Your next Patreon payment is due on <b><?= $h($lapse_date) ?></b>.
            <?php endif; ?>
        </p>
        <p>
            You can move your membership so you pay us directly by card instead.
            Nothing about your membership changes: same tier, same access, same
            account. Only where the money comes from changes.
        </p>
        <p class="lg-switch__warn">
            <b>Do it in this order</b>, so you&rsquo;re never charged twice and never left short.
        </p>
    </section>

    <ol class="lg-switch__steps">
        <li class="lg-switch__step">
            <h2 class="lg-switch__step-title">Cancel your pledge on Patreon</h2>
            <p>
                Cancelling does <b>not</b> cut you off. Patreon keeps your membership
                running <?= $lapse_date !== ''
                    ? 'until <b>' . $h($lapse_date) . '</b> &mdash; the end of the period you&rsquo;ve already paid for'
                    : 'to the end of the period you&rsquo;ve already paid for' ?>.
                Nothing changes here until then.
            </p>
            <p>
                <a class="lg-switch__cta" href="<?= $h($patreon_link) ?>" target="_blank" rel="noopener">
                    Manage your pledge on Patreon &rarr;
                </a>
            </p>
        </li>
        <li class="lg-switch__step">
            <h2 class="lg-switch__step-title">
                <?= $lapse_date !== ''
                    ? 'Come back on ' . $h($lapse_date) . ' and join with a card'
                    : 'Come back when it lapses and join with a card' ?>
            </h2>
            <p>
                That&rsquo;s the day your Patreon membership lapses. Join on that day and
                your access carries straight on. It&rsquo;s worth putting in your calendar.
            </p>
            <p>
                <a class="lg-switch__cta" href="/lgjoin/">Join with a card &rarr;</a>
            </p>
        </li>
    </ol>

    <section class="lg-switch__card lg-switch__faq">
        <h2 class="lg-switch__faq-q">What if I&rsquo;m a few days late?</h2>
        <p>
            Nothing is deleted. Your account stays exactly as it is &mdash; you&rsquo;ll
            just see the free member view until you join. Come back whenever and you
            pick up where you left off.
        </p>

        <h2 class="lg-switch__faq-q">Why can&rsquo;t I just do both and cancel later?</h2>
        <p>
            Because you&rsquo;d be paying twice for the same month, and we&rsquo;d rather
            you didn&rsquo;t. If you&rsquo;ve already ended up paying both,
            <a href="/request-refund/">tell us</a> and we&rsquo;ll sort out the refund.
        </p>

        <h2 class="lg-switch__faq-q">Changed your mind?</h2>
        <p>
            Do nothing at all. Your Patreon pledge carries on as normal &mdash;
            cancelling it is the only step that changes anything.
        </p>
    </section>

    <p class="lg-switch__foot">
        <a href="/manage-subscription/">Back to my membership &rarr;</a>
    </p>

<?php endif; ?>
</main>

<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>

</body>
</html>
