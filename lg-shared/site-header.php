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
        foreach ($nav_items as $slug => [$href, $label]):
            $is_active = ($active_nav === $slug);
            ?>
        <li><a href="<?= $h($href) ?>"<?= $is_active ? ' class="is-active" aria-current="page"' : '' ?>><?= $h($label) ?></a></li>
        <?php endforeach; ?>
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
              <a role="menuitem" href="/manage-subscription/">Manage Subscription</a>
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
