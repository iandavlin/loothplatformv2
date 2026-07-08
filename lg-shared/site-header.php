<?php
/**
 * /srv/lg-shared/site-header.php
 *
 * Shared site-header partial.  Include from any strangler surface:
 *
 *   require_once '/srv/lg-shared/site-header.php';
 *   lg_shared_render_site_header([
 *       'authenticated' => true,
 *       'tier'          => 'pro',       // 'public' | 'lite' | 'pro'
 *       'display_name'  => 'evan-gluck',
 *       'avatar_url'    => 'https://…/bpfull.jpg',   // optional
 *       'capabilities'  => [
 *           'manage_options'   => false,
 *           'edit_archive_poc' => false,
 *       ],
 *       'msg_unread'    => 0,   // optional; null → lazy-load via REST
 *       'notif_unread'  => 0,   // optional; null → lazy-load via REST
 *       // 'logo_url'   => 'https://…/logo.png',     // optional override
 *       // 'search_id'  => 'chrome-q',               // optional; id of the <input>
 *       // 'search_placeholder' => 'Search…',        // optional
 *       // 'profile_url'        => '/u/<slug>',       // optional; viewer's public profile page (/u/<slug>, which for the owner is the inline editor). Default /profile/edit only when slug-less.
 *       // 'logout_url'         => wp_logout_url(),  // optional; WP callers pass nonce'd URL
 *   ]);
 *
 * The caller is responsible for outputting the corresponding CSS:
 *   <link rel="stylesheet" href="/lg-shared/site-header.css">
 * (nginx maps /lg-shared/ → /srv/lg-shared/)
 *
 * The partial is intentionally dumb — it renders what it's handed.
 * Source-of-truth per consumer:
 *   archive-poc  → reads /whoami (lg_archive_poc_whoami())
 *   bb-mirror    → reads /whoami (lg_bb_mirror_whoami())
 *   lg-layout-v2 → reads $current_user in-process
 *
 * Companion:
 *   lg_shared_render_site_footer([
 *       'logo_url' => '…',   // optional
 *   ]);
 *
 * Guard: require_once safe (function_exists check on each function).
 */

declare(strict_types=1);

if (!function_exists('lg_shared_h')) {
    function lg_shared_h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('lg_shared_render_site_header')) {
/**
 * Render the shared site header.
 *
 * @param array{
 *   authenticated: bool,
 *   tier: string,
 *   display_name?: string,
 *   avatar_url?: string|null,
 *   capabilities?: array<string,bool>,
 *   msg_unread?: int|null,
 *   notif_unread?: int|null,
 *   logo_url?: string,
 *   search_id?: string,
 *   search_placeholder?: string,
 *   profile_url?: string,  // viewer's public profile page (/u/<slug>; owner edits inline there). Default /profile/edit when slug-less.
 *   logout_url?: string,   // optional; WP callers pass wp_logout_url() for nonce'd URL
 *   before_nav?: string,   // raw HTML injected between logo and <nav> (e.g. archive-poc back-link)
 * } $ctx
 */
function lg_shared_render_site_header(array $ctx): void
{
    // ---------- unpack with sane defaults ----------
    $authenticated = (bool)($ctx['authenticated'] ?? false);
    $tier          = (string)($ctx['tier'] ?? 'public');
    $display_name  = (string)($ctx['display_name'] ?? '');
    $avatar_url    = isset($ctx['avatar_url']) ? (string)$ctx['avatar_url'] : null;
    $caps          = (array)($ctx['capabilities'] ?? []);
    $msg_unread    = $ctx['msg_unread']   ?? null;   // null = lazy-load
    $notif_unread  = $ctx['notif_unread'] ?? null;   // null = lazy-load
    $profile_url   = (string)($ctx['profile_url'] ?? '/profile/edit');  // viewer's public profile (/u/<slug>); /profile/edit is only the slug-less fallback
    $logout_url    = (string)($ctx['logout_url']  ?? '/wp-login.php?action=logout');
    $search_id     = (string)($ctx['search_id'] ?? 'lg-chrome-q');
    $search_ph     = (string)($ctx['search_placeholder'] ?? 'Search…');
    $active_nav    = (string)($ctx['active_nav'] ?? '');  // slug: 'stream'|'hub'|'events'|'members'|'sponsors'
    // Raw HTML injected between logo and nav — consumer responsibility to escape
    $before_nav    = $ctx['before_nav'] ?? null;

    // Logo: consumer may pass its own logo URL (env-specific); fall back to
    // a host-relative path so each environment serves its own copy and the
    // default never 404s due to pointing at the wrong host.
    $logo_url = (string)($ctx['logo_url'] ?? '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png');

    // ---------- derived display values ----------
    $manage_opts = ($caps['manage_options'] ?? false) === true;

    // Tier pill label: Admin overrides paid-tier labels for manage_options users.
    $tier_label = match($tier) {
        'lite' => 'Lite',
        'pro'  => 'Pro',
        default => null,
    };
    if ($manage_opts) $tier_label = 'Admin';

    // Avatar: initials fallback when no URL or URL is empty.
    $avatar_initial = $display_name !== '' ? strtoupper(mb_substr($display_name, 0, 1)) : '?';

    // Badge display helpers (null = hidden and lazy-loaded by JS; 0 = shown but empty)
    $msg_hidden   = $msg_unread   === null;
    $notif_hidden = $notif_unread === null;
    $msg_count    = (int)($msg_unread ?? 0);
    $notif_count  = (int)($notif_unread ?? 0);

    $h = 'lg_shared_h';

    ?>
<style>
/* Critical chrome overrides — inline so they beat any <head> stylesheet (BB theme,
   archive.css, etc.) that loads after site-header.css at equal or higher specificity. */
.lg-chrome ul.lg-chrome__menu,
.lg-chrome ul.lg-chrome__account-menu,
.lg-chrome ul.lg-chrome__account-menu li { list-style: none !important; }

.lg-chrome ul.lg-chrome__account-menu {
  display: block;
  position: absolute !important;
  top: calc(100% + 6px) !important;
  right: 0 !important;
  min-width: 210px !important;
  background: #fff !important;
  border: 1px solid #e3ddd0 !important;
  border-radius: 10px !important;
  box-shadow: 0 8px 24px rgba(0,0,0,0.10), 0 2px 6px rgba(0,0,0,0.06) !important;
  padding: 6px !important;
  margin: 0 !important;
  z-index: 200 !important;
}
.lg-chrome ul.lg-chrome__account-menu[hidden] { display: none !important; }
.lg-chrome ul.lg-chrome__account-menu [role="menuitem"] {
  display: block !important;
  padding: 9px 12px !important;
  border-radius: 6px !important;
  text-decoration: none !important;
  font-size: 13px !important;
  color: #323532 !important;
  white-space: nowrap !important;
  background: transparent !important;
}
.lg-chrome ul.lg-chrome__account-menu [role="menuitem"]:hover,
.lg-chrome ul.lg-chrome__account-menu [role="menuitem"]:focus {
  background: #eef2e3 !important;
  color: #6b7c52 !important;
}
.lg-chrome ul.lg-chrome__account-menu .lg-chrome__account-menu-divider {
  height: 1px !important;
  background: #e3ddd0 !important;
  margin: 4px 0 !important;
  padding: 0 !important;
}
.lg-chrome ul.lg-chrome__account-menu .lg-chrome__account-menu-signout { color: #c66845 !important; }
.lg-chrome ul.lg-chrome__account-menu .lg-chrome__account-menu-signout:hover,
.lg-chrome ul.lg-chrome__account-menu .lg-chrome__account-menu-signout:focus {
  background: #fdf0ec !important; color: #c66845 !important;
}

/* Hub content-type submenu — a desktop dropdown (hover / keyboard focus / click)
   that becomes an inline indented list inside the mobile drawer. Scoped under
   .lg-chrome + !important so a PAGE theme (BB / archive.css) that styles ul/li/a
   inside the chrome can't bleed in — same defensive posture as the account menu. */
.lg-chrome .lg-chrome__has-sub { position: relative; display: inline-flex; align-items: center; gap: 2px; }
.lg-chrome ul.lg-chrome__submenu,
.lg-chrome ul.lg-chrome__submenu li { list-style: none !important; }
.lg-chrome__sub-toggle {
  appearance: none; -webkit-appearance: none; background: transparent; border: 0;
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 30px; padding: 0; margin: 0;
  color: var(--lg-ink); cursor: pointer; border-radius: 6px;
}
.lg-chrome__sub-toggle:hover { background: var(--lg-sage-tint); color: var(--lg-sage-d); }
.lg-chrome__sub-toggle svg { transition: transform .15s ease; }
.lg-chrome .lg-chrome__has-sub[data-open] .lg-chrome__sub-toggle svg { transform: rotate(180deg); }

.lg-chrome ul.lg-chrome__submenu {
  display: none;
  position: absolute; top: 100%; left: 0;
  min-width: 200px; margin: 0 !important; padding: 6px !important;
  background: #fff !important; border: 1px solid var(--lg-line) !important;
  border-radius: 10px !important;
  box-shadow: 0 8px 24px rgba(0,0,0,0.10), 0 2px 6px rgba(0,0,0,0.06) !important;
  z-index: 200;
}
/* Invisible bridge over the visual gap so a mouse travelling from the caret to
   the dropdown doesn't cross a dead zone and drop the :hover. */
.lg-chrome ul.lg-chrome__submenu::before {
  content: ""; position: absolute; left: 0; right: 0; top: -8px; height: 8px;
}
.lg-chrome ul.lg-chrome__submenu li { margin: 0 !important; }
.lg-chrome ul.lg-chrome__submenu a {
  display: block !important;
  padding: 9px 12px !important; border-radius: 6px !important;
  font: 600 13px/1 var(--lg-font-sans) !important;
  color: var(--lg-ink) !important; text-decoration: none !important;
  white-space: nowrap !important; background: transparent !important;
}
.lg-chrome ul.lg-chrome__submenu a:hover,
.lg-chrome ul.lg-chrome__submenu a:focus {
  background: var(--lg-sage-tint) !important; color: var(--lg-sage-d) !important;
}
/* Click / touch toggle — works at every width. */
.lg-chrome .lg-chrome__has-sub[data-open] > ul.lg-chrome__submenu { display: block; }
/* Desktop only: hover + keyboard focus also reveal the dropdown. */
@media (min-width: 821px) {
  .lg-chrome .lg-chrome__has-sub:hover > ul.lg-chrome__submenu,
  .lg-chrome .lg-chrome__has-sub:focus-within > ul.lg-chrome__submenu { display: block; }
}
/* Mobile (≤820): the desktop dropdown is replaced by a tap-opened MODAL picker
   (.lg-hubmenu below + the script). Both the caret and the inline dropdown are
   suppressed here so "The Hub" link itself is the only affordance — first tap
   opens the picker, a second tap falls through to /hub/. Deliberate split:
   desktop keeps the hover/focus dropdown, mobile gets the modal. */
@media (max-width: 820px) {
  .lg-chrome .lg-chrome__has-sub { display: flex; align-items: center; width: 100%; }
  .lg-chrome .lg-chrome__has-sub > a { flex: 1 1 auto; }
  /* No caret on mobile — the link is the trigger. */
  .lg-chrome__sub-toggle { display: none !important; }
  /* The desktop dropdown list never flows inline on mobile (the modal owns it). */
  .lg-chrome .lg-chrome__has-sub[data-open] > ul.lg-chrome__submenu { display: none !important; }
  /* Armed: while the picker is open the Hub link is highlighted; the header rule
     below lifts it above the modal so a 2nd tap reaches /hub/. */
  .lg-chrome .lg-chrome__has-sub.is-armed > a {
    background: var(--lg-sage-tint) !important; color: var(--lg-sage-d) !important;
    border-radius: 8px;
  }
  /* Focus the drawer on the Hub button while the picker is up (no sheet overlap). */
  .lg-chrome--hubmenu-open .lg-chrome__menu > li:not(.lg-chrome__has-sub) { display: none; }
}
/* Lift the whole header (and the armed Hub link with it) above the modal backdrop
   while the picker is open, so tapping the Hub link again lands on the link
   (→ /hub/) rather than the dimmer. The header already owns a stacking context
   (backdrop-filter), so this is only a level change; the class is added on mobile
   only. */
.lg-chrome.lg-chrome--hubmenu-open { z-index: 360; }

/* ---- Mobile Hub content-type picker (modal). Mobile-only; the desktop dropdown
   above is untouched. Bottom sheet in the house modal style; rendered for anon +
   authed because the Hub is public. Items mirror $hub_types (→ HUB_TYPE_LABELS). ---- */
.lg-hubmenu { position: fixed; inset: 0; z-index: 300; display: flex; align-items: flex-end; justify-content: center; }
.lg-hubmenu[hidden] { display: none; }
/* Motion = the house .lt-sheet tray idiom (Ian 7/08: "slide away like the
   other trays"): backdrop fades (.22s ease), sheet slides from/to the bottom
   edge (.26s cubic-bezier(.32,.72,0,1)) — the SAME numbers as bottom-nav's
   trays, one idiom everywhere. .is-open drives both; [hidden] is only the
   resting state, applied by JS after the slide-out ends. The sliding sheet
   also absorbs a grab-tap's synthesized click exactly like the other trays. */
.lg-hubmenu__backdrop {
  position: absolute; inset: 0; background: rgba(26,29,26,0.45); backdrop-filter: blur(3px);
  opacity: 0; transition: opacity .22s ease;
}
.lg-hubmenu.is-open .lg-hubmenu__backdrop { opacity: 1; }
.lg-hubmenu__sheet {
  position: relative; z-index: 1;
  width: 100%; max-width: 560px; max-height: 72vh;
  display: flex; flex-direction: column;
  background: #fff; border-radius: 16px 16px 0 0;
  box-shadow: 0 -6px 28px rgba(0,0,0,0.18);
  padding-bottom: env(safe-area-inset-bottom, 0px);
  transform: translateY(100%);
  transition: transform .26s cubic-bezier(.32,.72,0,1);
}
.lg-hubmenu.is-open .lg-hubmenu__sheet { transform: translateY(0); }
.lg-hubmenu__head {
  display: flex; align-items: center; gap: 8px;
  padding: 14px 18px; border-bottom: 1px solid var(--lg-line, #e3ddd0); flex: 0 0 auto;
}
.lg-hubmenu__title { flex: 1; margin: 0; font: 700 16px/1.2 var(--lg-font-sans, system-ui); color: var(--lg-ink, #323532); }
.lg-hubmenu__close {
  appearance: none; background: transparent; border: 0; cursor: pointer; padding: 0;
  width: 32px; height: 32px; border-radius: 50%; flex: 0 0 auto;
  display: inline-flex; align-items: center; justify-content: center;
  color: var(--lg-mute, #6b6f6b);
}
.lg-hubmenu__close:hover { background: var(--lg-sage-tint, #eef2e3); color: var(--lg-ink, #323532); }
.lg-hubmenu__list { list-style: none; margin: 0; padding: 8px; overflow-y: auto; -webkit-overflow-scrolling: touch; }
.lg-hubmenu__list li { margin: 0; }
.lg-hubmenu__item {
  display: block; padding: 14px; border-radius: 10px;
  font: 600 15px/1.2 var(--lg-font-sans, system-ui);
  color: var(--lg-ink, #323532); text-decoration: none;
}
.lg-hubmenu__item:hover, .lg-hubmenu__item:focus { background: var(--lg-sage-tint, #eef2e3); color: var(--lg-sage-d, #6b7c52); outline: none; }
.lg-hubmenu__item--all { font-weight: 800; }
/* Grab bar: house sheet idiom (visible 40x5 bar; content-box padding = a big
   forgiving tap/drag target). Carries .lt-sheet__grab too so bottom-nav.js's
   claim-model drag (enableSheetDrag) treats it as the handle — tap closes,
   downward drag dismisses. This body-level rule wins the duplicate-property
   cascade against bottom-nav's injected head styles. */
.lg-hubmenu__grab {
  width: 40px; height: 5px; border-radius: 999px; background: var(--lg-line, #e3ddd0);
  margin: 0 auto -8px; padding: 13px 30px; box-sizing: content-box; background-clip: content-box;
  cursor: pointer; touch-action: none; flex: 0 0 auto;
}
/* Tray mode — open({tray:true}), Ian's pick 7/08 (sibling-sheet): flush
   bottom at the Nav tray's own level, replacing the tray like a sub-sheet —
   the bar dims under the backdrop exactly as it does under the tray it just
   swapped in for (.lt-sheet z 2147481400). Drawer mode (≤820 header door)
   keeps the base 300 (header lifts itself to 360 above it). */
.lg-hubmenu--tray { z-index: 2147481400; }
/* The content type you are ALREADY viewing (marked on open from location). */
.lg-hubmenu__item.is-current {
  background: var(--lg-sage-tint, #eef2e3); color: var(--lg-sage-d, #6b7c52);
  box-shadow: inset 3px 0 0 var(--lg-sage-d, #6b7c52);
}
/* Hard guard: the picker never appears on desktop (desktop uses the dropdown). */
@media (min-width: 821px) { .lg-hubmenu { display: none !important; } }
</style>
<a href="#lg-main" class="skip-link">Skip to content</a>

<header class="lg-chrome" id="site-header">
  <div class="lg-chrome__inner">

    <button class="lg-chrome__hamburger" type="button"
            aria-label="Menu" aria-expanded="false" data-lg-mobile-toggle>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M3 6h18M3 12h18M3 18h18"/>
      </svg>
    </button>

    <a class="lg-chrome__logo" href="/front-page/" rel="home" aria-label="Looth Group home">
      <img src="<?= $h($logo_url) ?>" alt="Looth Group" width="36" height="36">
      <span class="lg-chrome__wordmark">Looth Group</span>
    </a>

    <?php if ($before_nav !== null): ?>
      <?= $before_nav ?>
    <?php endif; ?>

    <nav class="lg-chrome__nav" aria-label="Primary">
      <ul class="lg-chrome__menu">
        <?php
        // Always render the full nav on every surface; mark the current section
        // with aria-current + .is-active (consumers pass $active_nav). Loothtool
        // is external — it has no slug and is never marked active.
        $nav_items = [
            // 'stream' removed 6/12 — /stream/ is retired (301 → /hub/); the
            // nav was advertising a dead route next to the Hub it bounces to.
            'hub'      => ['/hub/',               'The Hub'],
            'events'   => ['/events/',            'Events'],
            'members'  => ['/directory/members/', 'The Map'],
            'sponsors' => ['/sponsors/',          'Sponsors'],
        ];

        // "The Hub" carries a content-type submenu. The Hub feed (bb-mirror)
        // already filters on a ?type=<kind> CSV param, so each item deep-links
        // to one content kind. SOURCE OF TRUTH for this list/labels/order is
        // bb-mirror/web/forums/_hub-filters.php → HUB_TYPE_LABELS (the SAME
        // facets the Hub's own filter rail renders). 'event' and 'misc' are
        // deliberately omitted: the Hub feed itself excludes them
        // (kind NOT IN ('event','misc')) and Events have their own top-level
        // nav item (/events/). Gating stays the Hub's job — below-tier items
        // render as locked teasers server-side (absence model), so a deep-link
        // exposes no payload. Keep in sync if a new feed-facing CPT launches.
        $hub_types = [
            'discussions'  => 'Discussions',
            'video'        => 'Videos',
            'article'      => 'Articles',
            'loothprint'   => 'Loothprints',
            // Labels mirror HUB_TYPE_LABELS so the submenu and the Hub's own
            // filter rail read identically — EXCEPT sponsor-post, which the rail
            // calls "Sponsors". Here that would collide with the top-level
            // "Sponsors" nav item (the /sponsors/ directory), so we use the CPT's
            // own registered label "Sponsor Posts" to disambiguate the two.
            'sponsor-post' => 'Sponsor Posts',
            'useful_links' => 'Useful Links',
            'shorty'       => 'Shorts',
            'benefit'      => 'Benefits',
            'loothcuts'    => 'Loothcuts',
            'document'     => 'Documents',
        ];

        foreach ($nav_items as $slug => [$href, $label]):
            $is_active = ($active_nav === $slug);
            if ($slug === 'hub'):
                // "The Hub" carries a content-type submenu, split by viewport:
                //   • Desktop (≥821): the caret opens a dropdown on hover / focus /
                //     click (CSS + the sub-toggle JS); the link itself navigates.
                //   • Mobile (≤820): the caret is hidden and the link is a dual-tap
                //     trigger — first tap opens the .lg-hubmenu MODAL (rendered after
                //     </header>), a second tap falls through to /hub/. JS keys off
                //     data-lg-hub-link. Items in both surfaces mirror $hub_types.
                ?>
        <li class="lg-chrome__has-sub" data-lg-hassub>
          <a href="<?= $h($href) ?>" data-lg-hub-link aria-expanded="false"<?= $is_active ? ' class="is-active" aria-current="page"' : '' ?>><?= $h($label) ?></a>
          <button class="lg-chrome__sub-toggle" type="button"
                  aria-expanded="false" aria-controls="lg-hub-submenu"
                  aria-label="Browse the Hub by content type" data-lg-sub-toggle>
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none"
                 stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>
          <ul class="lg-chrome__submenu" id="lg-hub-submenu">
            <li><a href="/hub/">Everything</a></li>
            <?php foreach ($hub_types as $tkey => $tlabel): ?>
            <li><a href="<?= $h('/hub/?type=' . rawurlencode($tkey)) ?>"><?= $h($tlabel) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </li>
        <?php else: ?>
        <li><a href="<?= $h($href) ?>"<?= $is_active ? ' class="is-active" aria-current="page"' : '' ?>><?= $h($label) ?></a></li>
        <?php endif;
        endforeach; ?>
        <li><a href="https://loothtool.com/">Loothtool</a></li>
        <?php if (!$authenticated): /* Phone-condense (≤640) hides the header
              Sign-in button, which left anon phones with NO sign-in path —
              the drawer carries it there. CSS keeps this hidden >640 where
              the real button exists. */ ?>
        <li class="lg-chrome__menu-signin"><a href="/wp-login.php">Sign in</a></li>
        <?php endif; ?>
      </ul>
    </nav>

    <div class="lg-chrome__aside">

      <?php if ($authenticated): ?>

        <?php if ($manage_opts): ?>
          <a class="lg-chrome__edit" href="/wp-admin/" target="_blank" aria-label="WP Admin">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
                 stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 20h9"/>
              <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            Edit
          </a>
        <?php endif; ?>

        <button class="lg-chrome__icon-btn lg-chrome__icon-btn--badged"
                type="button"
                aria-label="Messages"
                data-lg-msg-link>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>
            <path d="M22 6l-10 7L2 6"/>
          </svg>
          <?php if (!$msg_hidden && $msg_count > 0): ?>
            <span class="lg-chrome__badge" data-lg-msg-count><?= $msg_count ?></span>
          <?php else: ?>
            <span class="lg-chrome__badge" data-lg-msg-count hidden>0</span>
          <?php endif; ?>
        </button>

        <button class="lg-chrome__icon-btn lg-chrome__icon-btn--badged"
                type="button"
                aria-label="Notifications"
                data-lg-notif-link>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <?php if (!$notif_hidden && $notif_count > 0): ?>
            <span class="lg-chrome__badge" data-lg-notif-count><?= $notif_count ?></span>
          <?php else: ?>
            <span class="lg-chrome__badge" data-lg-notif-count hidden>0</span>
          <?php endif; ?>
        </button>

        <button class="lg-chrome__icon-btn lg-chrome__icon-btn--badged"
                aria-label="Connections"
                data-lg-conn-link>
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
          <span class="lg-chrome__badge" data-lg-conn-count hidden>0</span>
        </button>

        <!-- Account dropdown trigger -->
        <div class="lg-chrome__account-wrap" data-lg-account-wrap style="position:relative">
          <button class="lg-chrome__account" type="button"
                  aria-haspopup="true" aria-expanded="false"
                  aria-controls="lg-account-menu"
                  data-lg-account-btn>
            <span class="lg-chrome__avatar" aria-hidden="true">
              <?php if ($avatar_url !== null && $avatar_url !== ''): ?>
                <?php /* profile-media avatars take ?w= resize buckets (craft
                         gate 6/12): the chrome slot is 30px — 96 covers 3x.
                         Non-profile-media URLs (gravatar, BB) pass through. */
                      $lg_av = str_starts_with((string)$avatar_url, '/profile-media/')
                          ? $avatar_url . (str_contains((string)$avatar_url, '?') ? '&' : '?') . 'w=96'
                          : $avatar_url; ?>
                <img src="<?= $h($lg_av) ?>"
                     alt="<?= $h($display_name) ?>"
                     width="30" height="30">
              <?php else: ?>
                <?= $h($avatar_initial) ?>
              <?php endif; ?>
            </span>
            <span class="lg-chrome__account-name"><?= $h($display_name) ?></span>
            <?php if ($tier_label !== null): ?>
              <span class="lg-chrome__tier lg-chrome__tier--<?= $h(strtolower($tier_label)) ?>">
                <?= $h($tier_label) ?>
              </span>
            <?php endif; ?>
            <svg class="lg-chrome__account-caret" viewBox="0 0 24 24" width="12" height="12"
                 fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>

          <ul class="lg-chrome__account-menu" id="lg-account-menu"
              role="menu" aria-label="Account menu" hidden>
            <li role="none">
              <a role="menuitem" href="<?= $h($profile_url) ?>">My Profile</a>
            </li>
            <?php /* Patreon-member-facing — always visible to logged-in members */ ?>
            <li role="none">
              <a role="menuitem" href="/manage-subscription/">Manage Account</a>
            </li>
            <?php /* "Connect Your Patreon" removed from the logged-in account menu (Ian 6/16):
                     connected members don't need it; the anon connect door lives elsewhere. */ ?>
            <?php /* "Membership Guide" hidden until the guide is actually built out (Ian 6/16). */ ?>
            <?php if ($manage_opts): /* Stripe money pages — dormant pre-launch; admin-only QA until cut */ ?>
            <li role="none" class="lg-chrome__account-menu-divider"></li>
            <li role="none">
              <a role="menuitem" href="/lgjoin/">Join</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/lggift-buy/">Gift Memberships</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/lggift/">Redeem a Gift</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/my-gifts/">My Gifts</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/affiliate-earnings/">Earnings</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/request-refund/">Request a Refund</a>
            </li>
            <li role="none">
              <a role="menuitem" href="/test-checklist/">Test Checklist</a>
            </li>
            <?php endif; ?>
            <li role="none" class="lg-chrome__account-menu-divider"></li>
            <li role="none">
              <a role="menuitem" class="lg-chrome__account-menu-signout"
                 href="<?= $h($logout_url) ?>">Sign out</a>
            </li>
          </ul>
        </div><!-- .lg-chrome__account-wrap -->

      <?php else: ?>

        <a class="lg-chrome__signin" href="/wp-login.php">Sign in</a>
        <?php /* Join goes STRAIGHT to Patreon (Ian 2026-06-12) — joining and
                 connecting are two different things; /connect-your-patreon/ is
                 the on-site instruction page for patrons linking an account
                 (visible at ALL widths — it's the only anon door to it).
                 Canonical URL also lives in wp_options lgpo_patreon_link. */ ?>
        <a class="lg-chrome__connect" href="/connect-your-patreon/">Connect Patreon</a>
        <a class="lg-chrome__join" href="https://www.patreon.com/c/theloothgroup/membership" target="_blank" rel="noopener">Join</a>

      <?php endif; ?>

    </div><!-- .lg-chrome__aside -->
  </div><!-- .lg-chrome__inner -->
</header>

<?php /* Mobile Hub content-type picker (modal). Opened by the FIRST tap on "The
         Hub" in the drawer (≤820); a SECOND tap on the link falls through to
         /hub/. Desktop uses the dropdown above and never opens this. Rendered for
         everyone — the Hub is public. Items mirror $hub_types (→ HUB_TYPE_LABELS)
         so the picker and the dropdown read identically. */ ?>
<div class="lg-hubmenu" id="lg-hubmenu" hidden aria-hidden="true"
     role="dialog" aria-modal="true" aria-label="Browse the Hub by content type">
  <div class="lg-hubmenu__backdrop" data-lg-hubmenu-close></div>
  <div class="lg-hubmenu__sheet" role="document">
    <?php /* .lt-sheet__grab alias = bottom-nav.js enableSheetDrag's handle +
             tap-to-close selector; styled by .lg-hubmenu__grab above. */ ?>
    <div class="lg-hubmenu__grab lt-sheet__grab" aria-hidden="true"></div>
    <div class="lg-hubmenu__head">
      <h2 class="lg-hubmenu__title">Browse the Hub</h2>
      <button class="lg-hubmenu__close" type="button" aria-label="Close" data-lg-hubmenu-close>
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <ul class="lg-hubmenu__list">
      <li><a class="lg-hubmenu__item lg-hubmenu__item--all" href="/hub/">Everything</a></li>
      <?php foreach ($hub_types as $tkey => $tlabel): ?>
      <li><a class="lg-hubmenu__item" href="<?= $h('/hub/?type=' . rawurlencode($tkey)) ?>"><?= $h($tlabel) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<script>
(function () {
  /* Mobile nav toggle */
  var btn = document.querySelector('[data-lg-mobile-toggle]');
  var hdr = document.getElementById('site-header');
  if (btn && hdr) {
    btn.addEventListener('click', function () {
      var open = hdr.hasAttribute('data-mobile-open');
      if (open) {
        hdr.removeAttribute('data-mobile-open');
        btn.setAttribute('aria-expanded', 'false');
      } else {
        hdr.setAttribute('data-mobile-open', '');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  }

  /* Account dropdown */
  var accountBtn  = document.querySelector('[data-lg-account-btn]');
  var accountMenu = document.getElementById('lg-account-menu');
  var accountWrap = document.querySelector('[data-lg-account-wrap]');

  function closeAccountMenu() {
    if (!accountMenu || !accountBtn) return;
    accountMenu.hidden = true;
    accountBtn.setAttribute('aria-expanded', 'false');
    accountWrap && accountWrap.removeAttribute('data-open');
  }

  function openAccountMenu() {
    if (!accountMenu || !accountBtn) return;
    accountMenu.hidden = false;
    accountBtn.setAttribute('aria-expanded', 'true');
    accountWrap && accountWrap.setAttribute('data-open', '');
    // Focus first item for keyboard nav
    var first = accountMenu.querySelector('[role="menuitem"]');
    if (first) first.focus();
  }

  if (accountBtn && accountMenu) {
    accountBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (accountMenu.hidden) {
        openAccountMenu();
      } else {
        closeAccountMenu();
      }
    });

    // Close on outside click
    document.addEventListener('click', function (e) {
      if (accountWrap && !accountWrap.contains(e.target)) {
        closeAccountMenu();
      }
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !accountMenu.hidden) {
        closeAccountMenu();
        accountBtn.focus();
      }
    });

    // Arrow key navigation within menu
    accountMenu.addEventListener('keydown', function (e) {
      var items = Array.prototype.slice.call(
        accountMenu.querySelectorAll('[role="menuitem"]')
      );
      var idx = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        var next = items[(idx + 1) % items.length];
        if (next) next.focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        var prev = items[(idx - 1 + items.length) % items.length];
        if (prev) prev.focus();
      }
    });
  }

  /* Hub content-type submenu: the caret toggles the dropdown for touch users and
     inside the mobile drawer. Desktop mouse/keyboard also open it via CSS
     (:hover / :focus-within), so this is purely additive — null-safe if the Hub
     item isn't rendered. */
  var subToggles = document.querySelectorAll('[data-lg-sub-toggle]');
  for (var si = 0; si < subToggles.length; si++) {
    (function (tgl) {
      var li = tgl.closest ? tgl.closest('[data-lg-hassub]') : null;
      if (!li) return;
      function closeSub() { li.removeAttribute('data-open'); tgl.setAttribute('aria-expanded', 'false'); }
      tgl.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (li.hasAttribute('data-open')) {
          closeSub();
        } else {
          li.setAttribute('data-open', '');
          tgl.setAttribute('aria-expanded', 'true');
        }
      });
      // Close when clicking outside the Hub item.
      document.addEventListener('click', function (e) {
        if (!li.contains(e.target)) closeSub();
      });
      // Close on Escape, return focus to the caret.
      li.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && li.hasAttribute('data-open')) { closeSub(); tgl.focus(); }
      });
    })(subToggles[si]);
  }

  /* Mobile Hub picker (dual-tap modal). On phones/tablets (≤820) the "The Hub"
     link opens a content-type MODAL instead of navigating; a SECOND tap on the
     (now lifted) Hub link — without choosing a type — falls through to /hub/.
     Desktop keeps the dropdown, so this is gated off entirely ≥821. Null-safe.
     hdr / btn are the header + hamburger declared at the top of this IIFE.
     The picker is ALSO summoned by the Nav tray's Hub tile (bottom-nav.js)
     through window.lgHubMenu — open({tray:true}) = sibling-sheet flush-bottom
     at tray level (.lg-hubmenu--tray, Ian's mock-gate pick 7/08). ONE modal,
     ONE $hub_types list, two doors (parity gate: never a second menu). */
  var hubMq    = window.matchMedia('(max-width: 820px)');
  var hubLi    = document.querySelector('[data-lg-hassub]');
  var hubLink  = hubLi ? hubLi.querySelector('[data-lg-hub-link]') : null;
  var hubModal = document.getElementById('lg-hubmenu');
  if (hdr && hubLi && hubLink && hubModal) {
    // "Open" = the .is-open class, NOT [hidden]: during the ~300ms slide-out
    // the element is still un-hidden but already closing — a tap then should
    // re-OPEN (first-tap branch), never fall through to navigation.
    var hubMenuOpen = function () { return hubModal.classList.contains('is-open'); };
    var hubCloseTimer = null;

    // Mark the item matching the CURRENT view (/hub/?type=<key>; bare /hub/ =
    // "Everything") so the menu shows where you already are. Re-checked on
    // every open — cheap, and stays right if history state ever changes.
    var markHubCurrent = function () {
      var type = null;
      try { type = new URLSearchParams(location.search).get('type'); } catch (err) {}
      var onHub = /^\/hub\/?$/.test(location.pathname);
      var items = hubModal.querySelectorAll('.lg-hubmenu__item');
      for (var i = 0; i < items.length; i++) {
        var m = (items[i].getAttribute('href') || '').match(/[?&]type=([^&]+)/);
        var cur = onHub && (m ? (type === decodeURIComponent(m[1]))
                              : !type);          // typeless item = "Everything"
        items[i].classList.toggle('is-current', cur);
        if (cur) items[i].setAttribute('aria-current', 'true');
        else items[i].removeAttribute('aria-current');
      }
    };

    var openHubMenu = function (opts) {
      if (hubCloseTimer) { clearTimeout(hubCloseTimer); hubCloseTimer = null; }
      hubModal.classList.toggle('lg-hubmenu--tray', !!(opts && opts.tray));
      markHubCurrent();
      hubModal.hidden = false;
      hubModal.setAttribute('aria-hidden', 'false');
      void hubModal.offsetHeight;                  // reflow so the slide-in runs (house idiom)
      hubModal.classList.add('is-open');
      hdr.classList.add('lg-chrome--hubmenu-open');
      hubLi.classList.add('is-armed');
      hubLink.setAttribute('aria-expanded', 'true');
      document.body.classList.add('lg-sm-open');   // reuse the social-modal scroll lock
      var first = hubModal.querySelector('.lg-hubmenu__item.is-current') ||
                  hubModal.querySelector('.lg-hubmenu__item');
      if (first) first.focus();
      try { document.dispatchEvent(new CustomEvent('lg:hubmenu-open', { detail: opts || {} })); } catch (err) {}
    };

    var closeHubMenu = function () {
      hubModal.classList.remove('is-open');        // slide-out starts (sheet + backdrop transition)
      hubModal.setAttribute('aria-hidden', 'true');
      hdr.classList.remove('lg-chrome--hubmenu-open');
      hubLi.classList.remove('is-armed');
      hubLink.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('lg-sm-open');
      if (hubCloseTimer) clearTimeout(hubCloseTimer);
      hubCloseTimer = setTimeout(function () {     // rest ([hidden]) once the .26s slide ends
        hubModal.hidden = true;
        hubModal.classList.remove('lg-hubmenu--tray');
        hubCloseTimer = null;
      }, 300);
      try { document.dispatchEvent(new CustomEvent('lg:hubmenu-close')); } catch (err) {}
    };

    // Public door (bottom-nav.js Nav-tray Hub tile, or any future summoner).
    window.lgHubMenu = { open: openHubMenu, close: closeHubMenu, isOpen: hubMenuOpen };

    hubLink.addEventListener('click', function (e) {
      if (!hubMq.matches) return;                    // desktop: normal link + caret dropdown
      if (hubMenuOpen()) { closeHubMenu(); return; } // 2nd tap → fall through to /hub/
      e.preventDefault();                            // 1st tap → reveal the picker
      openHubMenu();
    });

    // Grab-bar taps: the tap/drag semantics live in bottom-nav.js's
    // enableSheetDrag (tap on the grab closes, drag dismisses/snaps back).
    // This sheet hides INSTANTLY on close (no slide-out covering the finger
    // like the .lt-sheet trays), so the tap's synthesized click would fall
    // through onto whatever the page shows underneath — swallow it here.
    var hubGrab = hubModal.querySelector('.lg-hubmenu__grab');
    if (hubGrab) hubGrab.addEventListener('touchend', function (e) { if (e.cancelable) e.preventDefault(); });

    // Dismiss WITHOUT navigating: backdrop, close button, Escape.
    var hubClosers = hubModal.querySelectorAll('[data-lg-hubmenu-close]');
    for (var hc = 0; hc < hubClosers.length; hc++) {
      hubClosers[hc].addEventListener('click', function (e) {
        e.preventDefault(); closeHubMenu(); hubLink.focus();
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && hubMenuOpen()) { closeHubMenu(); hubLink.focus(); }
    });
    // Toggling the hamburger or crossing back to desktop tears the picker down.
    if (btn) btn.addEventListener('click', function () { if (hubMenuOpen()) closeHubMenu(); });
    if (hubMq.addEventListener) {
      hubMq.addEventListener('change', function (ev) { if (!ev.matches && hubMenuOpen()) closeHubMenu(); });
    }
  }
})();
</script>

<?php if ($authenticated): ?>
<!-- ── P9 social modals ────────────────────────────────────── -->

<!-- Notifications modal -->
<div class="lg-social-modal" id="lg-notif-modal"
     hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Notifications">
  <div class="lg-social-modal__backdrop"></div>
  <div class="lg-social-modal__panel">
    <div class="lg-social-modal__head">
      <h2 class="lg-social-modal__title">Notifications</h2>
      <button class="lg-social-modal__action" data-lg-notif-readall hidden>Mark all read</button>
      <button class="lg-social-modal__close" aria-label="Close" data-lg-modal-close>
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="lg-social-modal__body" id="lg-notif-list"></div>
  </div>
</div>

<!-- Unified social modal: Messages + Connections tabs -->
<div class="lg-social-modal" id="lg-social-modal"
     hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Messages and connections">
  <div class="lg-social-modal__backdrop"></div>
  <div class="lg-social-modal__panel">
    <div class="lg-social-modal__head">
      <button class="lg-social-modal__back-btn" data-lg-thread-back aria-label="Back to threads" hidden>
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </button>
      <div class="lg-social-tabs" role="tablist" aria-label="Social">
        <button class="lg-social-tab" data-lg-tab="messages" role="tab" aria-selected="true">Messages</button>
        <button class="lg-social-tab" data-lg-tab="connections" role="tab" aria-selected="false">Connections</button>
      </div>
      <button class="lg-social-modal__close" aria-label="Close" data-lg-modal-close>
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <!-- Messages pane -->
    <div class="lg-social-pane" data-lg-pane="messages" role="tabpanel">
      <!-- Thread list view -->
      <div class="lg-social-modal__body" id="lg-msg-list"></div>
      <!-- Thread detail view. Layout via the .lg-msg-detail class, NOT an
           inline style — inline display:flex beat the UA [hidden] rule, so
           the pane never hid again after opening a thread (Buck 6/11; the
           css keeps a defensive [hidden] counter-rule either way). -->
      <div id="lg-msg-detail" class="lg-msg-detail" hidden>
        <div class="lg-msg__messages" id="lg-msg-messages"></div>
        <div class="lg-msg__compose" id="lg-msg-compose">
          <!-- staged image preview (above the input); shown only when a photo is picked -->
          <div class="lg-msg__attach-preview" id="lg-msg-attach-preview" hidden>
            <div class="lg-msg__attach-thumb">
              <img id="lg-msg-attach-img" alt="Attachment preview">
              <button type="button" class="lg-msg__attach-remove" data-lg-attach-remove
                      aria-label="Remove photo">&times;</button>
            </div>
          </div>
          <div class="lg-msg__compose-row">
            <input type="file" id="lg-msg-attach-input" class="lg-msg__attach-input"
                   accept="image/jpeg,image/png,image/webp" hidden>
            <button type="button" class="lg-msg__attach-btn" data-lg-attach aria-label="Attach photo" title="Attach photo">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                   stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21.4 11.05 12.25 20.2a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.49-8.49"/>
              </svg>
            </button>
            <textarea id="lg-msg-reply-input" class="lg-msg__reply-input"
                      placeholder="Reply... (Enter to send, Shift+Enter for newline)"
                      rows="2"></textarea>
            <button class="lg-msg__send-btn" data-lg-send-reply aria-label="Send">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Connections pane -->
    <div class="lg-social-pane" data-lg-pane="connections" role="tabpanel" hidden>
      <div class="lg-social-modal__body">
        <div id="lg-conn-pending-section" hidden>
          <h3 class="lg-conn__section-h">Pending requests</h3>
          <div id="lg-conn-pending"></div>
        </div>
        <h3 class="lg-conn__section-h">Your connections</h3>
        <div class="lg-conn__search-wrap">
          <input type="search" id="lg-conn-search" class="lg-conn__search"
                 placeholder="Search connections…" aria-label="Search your connections" autocomplete="off">
        </div>
        <div id="lg-conn-accepted"></div>
      </div>
    </div>
  </div>
</div>

<script src="/lg-shared/social-modals.js?v=<?= @filemtime(__DIR__ . '/social-modals.js') ?: '1' ?>" defer></script>
<?php endif; ?>
<?php
} // end function
} // end if !function_exists
