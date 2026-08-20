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
require __DIR__ . '/../lib/following-data.php';
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

/* #150/#149 — the direction we cannot block. A member who pays here and then
   pledges on patreon.com is charged twice and nothing of ours can stop them,
   so the least we can do is be the one to tell them. Behind
   `lgms_double_pay_block`; with the flag off nothing below runs and this page
   is unchanged. The member's own email is needed for the fallback match, and
   the Patreon snapshot already carries it when the WP row does not. */
$dual_email = '';
if ($wp_user_id > 0) {
    $dual_email = (string) ($membership['email'] ?? '');
    if ($dual_email === '') {
        try {
            $st = lg_membership_db()->prepare(
                "SELECT user_email FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE ID = ? LIMIT 1"
            );
            $st->execute([$wp_user_id]);
            $dual_email = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) { $dual_email = ''; }
    }
}
$is_dual_payer = lg_membership_is_dual_payer($wp_user_id, $dual_email);
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

<?php if ($is_dual_payer): ?>
    <section class="lg-manage-sub__card lg-manage-sub__card--dual" role="status">
        <p class="lg-manage-sub__status-pill lg-manage-sub__status-pill--dual">Charged twice</p>
        <p><?= $h(lg_membership_dual_payer_message()) ?></p>
        <p class="lg-manage-sub__dual-actions">
            <a class="lg-manage-sub__cta" href="<?= $h($patreon_link) ?>" target="_blank" rel="noopener">Manage your pledge on Patreon &rarr;</a>
            <a class="lg-manage-sub__cta lg-manage-sub__cta--secondary" href="/request-refund/">Ask us about a refund &rarr;</a>
        </p>
    </section>
<?php endif; ?>
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

    <?php if (!$is_anon): ?>
    <section class="lg-manage-sub__card lg-manage-sub__prefs" id="lg-email-prefs" aria-label="Email preferences">
        <h2 class="lg-manage-sub__prefs-title">Email preferences</h2>
        <div class="lg-pref-row">
            <span class="lg-pref-row__txt"><b>Weekly Digest</b><small>The members&rsquo; weekly round-up &mdash; news, events, highlights.</small></span>
            <label class="lg-switch"><input type="checkbox" id="lg-pref-weekly" disabled><span class="lg-switch__track"></span></label>
        </div>
        <div class="lg-pref-row">
            <span class="lg-pref-row__txt"><b>Event Reminders</b><small>A heads-up email before events you might want to catch.</small></span>
            <label class="lg-switch"><input type="checkbox" id="lg-pref-events" disabled><span class="lg-switch__track"></span></label>
        </div>
        <p class="lg-pref-note"><small>Changes save automatically.</small></p>
    </section>
    <style>
    .lg-manage-sub__prefs{margin-top:18px}
    .lg-manage-sub__prefs-title{font-size:1.05rem;margin:0 0 6px}
    .lg-pref-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0;border-top:1px solid var(--lg-line,#e3e1d8)}
    .lg-pref-row__txt b{display:block}
    .lg-pref-row__txt small{color:var(--lg-mute,#6b6f63)}
    .lg-switch{position:relative;display:inline-block;width:46px;height:26px;flex:0 0 auto}
    .lg-switch input{position:absolute;opacity:0;width:0;height:0}
    .lg-switch__track{position:absolute;inset:0;background:#c9cdbf;border-radius:999px;transition:.2s;cursor:pointer}
    .lg-switch__track:before{content:"";position:absolute;width:20px;height:20px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
    .lg-switch input:checked+.lg-switch__track{background:var(--lg-sage-d,#6b7c52)}
    .lg-switch input:checked+.lg-switch__track:before{transform:translateX(20px)}
    .lg-switch input:disabled+.lg-switch__track{opacity:.55;cursor:not-allowed}
    .lg-pref-note{margin:10px 0 0;color:var(--lg-mute,#6b6f63)}
    </style>
    <script>
    (function(){
      function post(action, on){
        var d=new URLSearchParams(); d.set('action',action); if(on!==null) d.set('on',on);
        return fetch('/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},body:d.toString()})
          .then(function(r){return r.json();});
      }
      function wire(id, stateAction, toggleAction){
        var inp=document.getElementById(id); if(!inp) return;
        post(stateAction,null).then(function(j){ if(j&&j.ok){inp.checked=!!j.on;inp.disabled=false;} }).catch(function(){});
        inp.addEventListener('change',function(){
          var want=inp.checked; inp.disabled=true;
          post(toggleAction, want?'1':'0').then(function(j){
            if(j&&j.ok){inp.checked=!!j.on;} else {inp.checked=!want;alert('Could not save — try again.');}
            inp.disabled=false;
          }).catch(function(){inp.checked=!want;inp.disabled=false;alert('Network error — try again.');});
        });
      }
      wire('lg-pref-weekly','lg_weekly_member_state','lg_weekly_member_toggle');
      wire('lg-pref-events','lg_event_reminder_state','lg_event_reminder_signup');
    })();
    </script>
    <?php endif; ?>

    <?php
    /* ── Discussions you're following ────────────────────────────────────────
     * Ian, 2026-07-30: "in the manage my account page a section for posts I'm
     * following." Discussions only — follow does not exist for articles or
     * events, and extending it was declined in the same conversation.
     *
     * The job is NAVIGATION, not settings: see what you follow, get back to it,
     * and leave. That distinction decides the two things this markup does that
     * the earlier thread-follow mock (footer-mockups/threadfollow-notif-panel/
     * mock-account.html) was rated BAD for missing:
     *
     *   BOUNDED. Five rows, then "Show all". Its verdict — "honest, but
     *   unbounded; someone following thirty threads gets a thirty-row settings
     *   page" — is not hypothetical: the FIRST REAL MEMBER (Ian, wp user 1) is
     *   already at twelve, eleven of them legacy BuddyBoss auto-subscribes he
     *   never knowingly opted into, dating back to August 2023.
     *
     *   ONE OFF SWITCH. "Stop all" clears both bits on every followed discussion
     *   — "the exact thing people look for when they are annoyed enough to open
     *   this page." It confirms first, and it says out loud that the Weekly
     *   Digest is untouched, because that is the thing a member is afraid of
     *   losing when they press a red button.
     *
     * The 🔔/✉ marks REPORT, they do not toggle. Per-discussion tuning already
     * works on the discussion itself; putting live switches here is what turned
     * the earlier mock into a settings grid. They are rendered from the stores
     * and then corrected by the client against follow.php's own GET, because
     * that endpoint is the only thing that knows whether discussion email is
     * enabled at the ACCOUNT level (§8.1.3(a) — a subscription row alone does
     * not mean mail will arrive).
     *
     * Every write goes out through follow.php's POST. There is no second write
     * path to topic_follow, and no bulk endpoint exists, so "Stop all" is N
     * sequential calls to the same contract.
     */
    if (!$is_anon):
        $fol       = lg_following_list($wp_user_id);
        $fol_max   = 50;                                  // hard DOM bound, see below
        $fol_shown = array_slice($fol['items'], 0, $fol_max);
        $fol_svg   = static function (string $which): string {
            $open = '<svg class="lg-manage-sub__fol-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                  . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
            $paths = [
                'bell'  => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
                'email' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
                'x'     => '<path d="M18 6 6 18M6 6l12 12"/>',
            ];
            return $open . ($paths[$which] ?? '') . '</svg>';
        };
    ?>
    <section class="lg-manage-sub__card lg-manage-sub__fol" id="lg-following"
             aria-label="Discussions you are following"
             data-total="<?= (int) $fol['total'] ?>" data-page-size="<?= (int) LG_FOLLOWING_PAGE_SIZE ?>"
             <?php /* every followed id, not just the rendered ones — "Stop all" must
                      mean all, and above $fol_max the DOM deliberately holds fewer. */ ?>
             data-all-ids="<?= $h(implode(',', array_column($fol['items'], 'id'))) ?>">

        <h2 class="lg-manage-sub__fol-title">Discussions you&rsquo;re following</h2>

        <?php if ($fol['total'] === 0): ?>

            <p class="lg-manage-sub__fol-empty">
                You&rsquo;re not following any discussions yet. Open a discussion in the Hub and tap the
                bell to be told when someone replies.
            </p>
            <p><a class="lg-manage-sub__fol-link" href="/hub/">Browse the Hub &rarr;</a></p>

        <?php else: ?>

            <p class="lg-manage-sub__fol-count">
                <b><?= (int) $fol['total'] ?> discussion<?= $fol['total'] === 1 ? '' : 's' ?></b><?php
                    if ($fol['email_count'] > 0):
                ?> &middot; <span id="lg-fol-emailcount"><?= (int) $fol['email_count'] ?> of them email you</span><?php endif; ?>
            </p>

            <?php if (!$fol['hydrated']): ?>
                <?php /* The discussion index itself was unreadable — which is LIVE'S
                         STATE until forums.topic_follow is migrated there. Do NOT
                         fall through to the row loop: every row would render
                         "no longer available", declaring live discussions dead.
                         Say what happened, and still offer the one useful action. */ ?>
                <p class="lg-manage-sub__fol-warn">
                    We can&rsquo;t reach the discussion index right now, so we can&rsquo;t show you
                    which ones these are. Nothing has changed &mdash; try again shortly.
                </p>
            <?php else: ?>

            <?php if ($fol['degraded'] !== ''): ?>
                <p class="lg-manage-sub__fol-warn">
                    <?= $fol['degraded'] === 'notify'
                        ? 'We couldn&rsquo;t read your notification settings just now, so this list may be incomplete.'
                        : 'We couldn&rsquo;t read your email settings just now, so this list may be incomplete.' ?>
                </p>
            <?php endif; ?>

            <ul class="lg-manage-sub__fol-list">
            <?php foreach ($fol_shown as $i => $it): ?>
                <li class="lg-manage-sub__fol-row<?= $i >= LG_FOLLOWING_PAGE_SIZE ? ' is-overflow' : '' ?>"
                    data-topic="<?= (int) $it['id'] ?>">
                    <div class="lg-manage-sub__fol-main">
                        <?php if ($it['gone']): ?>
                            <span class="lg-manage-sub__fol-name is-gone">This discussion is no longer available</span>
                            <span class="lg-manage-sub__fol-meta">It may have been removed &mdash; unfollow it to stop hearing about it.</span>
                        <?php else: ?>
                            <?php if ($it['url'] !== ''): ?>
                                <a class="lg-manage-sub__fol-name" href="<?= $h($it['url']) ?>"><?= $h($it['title']) ?></a>
                            <?php else: ?>
                                <?php /* No link, and the row says so rather than looking dead. The
                                         hub gates on forum visibility, so a private group's
                                         discussions are not addressable there — following one
                                         still emails you, so the row (and its unfollow) must
                                         stay. */ ?>
                                <span class="lg-manage-sub__fol-name"><?= $h($it['title']) ?></span>
                            <?php endif; ?>
                            <span class="lg-manage-sub__fol-meta">
                                <?php
                                $bits = array_filter([
                                    $it['forum'] . (!empty($it['private']) ? ' (private group)' : ''),
                                    $it['replies'] > 0 ? $it['replies'] . ' repl' . ($it['replies'] === 1 ? 'y' : 'ies') : '',
                                    lg_following_when($it['active_at']),
                                ]);
                                echo $h(implode(' · ', $bits));
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php
                    /* The two bits. Under LG_FOLLOWING_ROW_TOGGLES they are real
                     * <button>s a member can press; with the flag off they are the
                     * <span> report they have always been, byte for byte.
                     *
                     * The labels differ by KIND, not just by state: a report says
                     * what IS ("Notifications on"), a control says what pressing it
                     * WILL DO ("Turn notifications off") — otherwise a screen reader
                     * announces the current state as though it were the action. */
                    $mark = static function (string $channel, bool $on, string $glyph) use ($h, $it, $fol_svg): void {
                        $noun  = $channel === 'notify' ? 'Notifications' : 'Emails';
                        $state = $noun . ($on ? ' on' : ' off');
                        $cls   = 'lg-manage-sub__fol-mark' . ($on ? ' is-on' : '');
                        if (!LG_FOLLOWING_ROW_TOGGLES) {
                            // The line breaks are not cosmetic here: OFF must be a
                            // BYTE-identical no-op, and this reproduces the exact
                            // bytes the pre-flag template emitted. Verified by md5
                            // of the rendered section against the commit before the
                            // toggles existed — whitespace included, because "no-op"
                            // that needs a whitespace-insensitive diff is a weaker
                            // claim than the one the flag rule asks for.
                            printf("<span class=\"%s\"\n"
                                 . "                              data-mark=\"%s\" title=\"%s\"\n"
                                 . "                              aria-label=\"%s\">%s</span>",
                                $cls, $h($channel), $h($state), $h($state), $fol_svg($glyph));
                            return;
                        }
                        $action = 'Turn ' . strtolower($noun) . ' ' . ($on ? 'off' : 'on')
                                . ($it['gone'] ? '' : ' for ' . $it['title']);
                        printf('<button type="button" class="%s is-toggle" data-mark="%s" data-toggle="%s"'
                             . ' aria-pressed="%s" title="%s" aria-label="%s">%s</button>',
                            $cls, $h($channel), $h($channel), $on ? 'true' : 'false',
                            $h($state), $h($action), $fol_svg($glyph));
                    };
                    ?>
                    <span class="lg-manage-sub__fol-state">
                        <?php $mark('notify', (bool) $it['notify'], 'bell'); ?>
                        <?php $mark('email',  (bool) $it['email'],  'email'); ?>
                    </span>

                    <button type="button" class="lg-manage-sub__fol-x" data-unfollow="<?= (int) $it['id'] ?>"
                            title="Unfollow" aria-label="Unfollow<?= $it['gone'] ? '' : ' ' . $h($it['title']) ?>"><?= $fol_svg('x') ?></button>
                </li>
            <?php endforeach; ?>
            </ul>

            <?php if ($fol['total'] > LG_FOLLOWING_PAGE_SIZE): ?>
                <button type="button" class="lg-manage-sub__fol-more" id="lg-fol-more"
                        aria-expanded="false" aria-controls="lg-following">
                    Show all <?= (int) min($fol['total'], $fol_max) ?> &rarr;
                </button>
            <?php endif; ?>

            <?php if ($fol['total'] > $fol_max): ?>
                <p class="lg-manage-sub__fol-meta">
                    Showing the <?= (int) $fol_max ?> most recently active of <?= (int) $fol['total'] ?>.
                    <b>Stop all</b> below covers every one of them.
                </p>
            <?php endif; ?>

            <?php endif; /* hydrated */ ?>

            <?php if (LG_FOLLOWING_CADENCE): ?>
                <?php /* ── Email frequency — ONE ACCOUNT-LEVEL SETTING ─────────────
                 * Ian: "we need the email frequency on there too."
                 *
                 * Placed OUTSIDE the row list on purpose. It is not per-discussion
                 * — per-thread cadence was offered and declined, because following
                 * six threads on Daily is six daily emails, which defeats the
                 * feature. Putting it in a row would say the opposite of what it
                 * does, so it sits with the account-level email line instead, and
                 * says "every discussion you follow" in as many words.
                 *
                 * Renders only under LG_FOLLOWING_CADENCE, which is OFF and stays
                 * off until follow-digest's batcher genuinely sends Daily and
                 * Weekly. Until then choosing "Daily" would deliver instant mail.
                 *
                 * aria-checked/radio rather than a <select>: three mutually
                 * exclusive values that all fit, and the current one should be
                 * readable without opening anything.
                 *
                 * ── SHIPPED HIDDEN, REVEALED BY THE SERVER, AND THAT IS THE DESIGN ──
                 * `hidden` is not a nicety here, it is the gate. This page has NO WP
                 * BOOT (:3), so it cannot ask lg_fd_cadence_ui_enabled() — the single
                 * source of truth for whether ANY surface shows this control
                 * (lg-follow-digest.php:377) — at render time. A page-local flag is
                 * exactly the "each surface checks its own condition and they
                 * eventually disagree" failure that function exists to prevent.
                 *
                 * So the flag below is only a CHEAP PRE-GATE (off ⇒ not a byte of
                 * this, and nobody pays for a fetch). The authoritative answer comes
                 * over the wire from lg_fd_cadence_state, which is per-member: it
                 * refuses anyone the sender would not actually serve. manage-
                 * following.js reveals this on ok:true and REMOVES it otherwise.
                 *
                 * FAIL-CLOSED ON PURPOSE: if the JS never runs, the fetch fails, or
                 * the member is not served, the control stays hidden. The failure mode
                 * of a hidden control is that a member does not see a setting; the
                 * failure mode of a visible one is that they set it and get nothing.
                 *
                 * The options below are a SKELETON, not the truth — the JS rebuilds
                 * them from the endpoint's `options` so the list cannot drift from
                 * lg_fd_cadences(). */ ?>
                <div class="lg-manage-sub__fol-freq" id="lg-fol-freq" role="radiogroup"
                     aria-label="How often to send discussion emails"
                     data-state="pending" hidden>
                    <span class="lg-manage-sub__fol-freq-lbl">
                        <b>Email frequency</b>
                        <small>Applies to every discussion you follow.</small>
                    </span>
                    <span class="lg-manage-sub__fol-freq-seg">
                        <?php foreach (LG_FOLLOWING_CADENCES as $value => $label): ?>
                            <button type="button" class="lg-manage-sub__fol-freq-opt"
                                    role="radio" aria-checked="false" tabindex="-1"
                                    data-cadence="<?= $h($value) ?>"><?= $h($label) ?></button>
                        <?php endforeach; ?>
                    </span>
                    <span class="lg-manage-sub__fol-freq-note" id="lg-fol-freq-note"
                          role="status" aria-live="polite"></span>
                </div>
            <?php endif; ?>

            <?php /* Stop all survives an unreadable index on purpose: it needs only
                     the ids, and a member who cannot see the list is exactly the one
                     who wants it to stop. */ ?>
            <div class="lg-manage-sub__fol-foot">
                <span id="lg-fol-master"></span>
                <button type="button" class="lg-manage-sub__fol-stopall" id="lg-fol-stopall"
                        data-count="<?= (int) $fol['total'] ?>">Stop all <?= (int) $fol['total'] ?></button>
            </div>

        <?php endif; ?>
    </section>
    <script src="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/manage-following.js?v=<?= $h((string)(@filemtime(__DIR__ . '/manage-following.js') ?: '1')) ?>" defer></script>
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
