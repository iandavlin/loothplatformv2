/* Looth PWA bootstrap — service-worker registration + mobile-only install banner.
   Loaded site-wide via a single <script src="/pwa.js" defer> injection. */
(function () {
  'use strict';
  if (window.__loothPwa) return;
  window.__loothPwa = true;

  // Embedded in an iframe (e.g. the §4c comments modal) — the app-shell
  // (bottom nav, shop bubble, install banner, theming) belongs to the top-level
  // page only; loading it inside the iframe leaks chrome into the modal.
  if (window.top !== window.self) return;

  // Register the service worker on every viewport (needed for installability).
  //
  // window.LG_SW is a ready-made query string emitted by pwa-loader.php ONLY when
  // platform/config/pwa-sw.php has resilient_fetch on (backlog 3.10). sw.js is static
  // and cannot read PHP, so the flag rides the registration URL; when the global is
  // absent this registers '/sw.js' exactly as it always has. Deliberately opaque here
  // — this file never learns the parameter names, so there is one source of truth.
  //
  // A different script URL is a different worker at the same scope, which is how the
  // flag reaches clients that already have '/sw.js' installed: it supersedes.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      var swUrl = '/sw.js' + (window.LG_SW ? '?' + window.LG_SW : '');
      navigator.serviceWorker.register(swUrl, { scope: '/' }).catch(function () {});
    });
  }

  // Warm the Google Fonts connections early — several layers inject webfonts
  // (Cabin on the Hub, Barlow Condensed/Inter on the shop surfaces).
  (function () {
    var hosts = ['https://fonts.googleapis.com', 'https://fonts.gstatic.com'];
    for (var i = 0; i < hosts.length; i++) {
      var l = document.createElement('link');
      l.rel = 'preconnect'; l.href = hosts[i]; l.crossOrigin = '';
      (document.head || document.documentElement).appendChild(l);
    }
  })();

  // ── Layer loader (Buck 2026-06-11 site-wide speed pass) ─────────────────────
  // Layers used to be injected on EVERY page and rely on their internal gates to
  // no-op — which still cost the download + parse everywhere (hub-polish alone is
  // ~250KB; a non-hub desktop page pulled ~620KB of layer JS it could never run).
  // The loader now applies each layer's OWN gate (mirrored from the layer source —
  // the internal gates STAY, a layer must remain safe however it's loaded) BEFORE
  // injecting, and `idle()` layers (tap-to-open sheets, push opt-in) wait for
  // requestIdleCallback so the first paint never competes with them.
  // "mobileish" = phone width OR coarse-pointer tablet/phone in ANY orientation,
  // so a landscape phone still gets the layers its portrait flip needs. Layers
  // that hard-gate at ≤640 internally behave exactly as before — they just also
  // skip the download on devices that could never pass that gate.
  var PATH = location.pathname || '/';
  var onHub = /^\/hub(\/|$)/.test(PATH);           // covers /hub/ + /hub/<cat>/<topic>/
  var onEvents = PATH.indexOf('/events') === 0;
  var onDir = PATH.indexOf('/directory') === 0;
  var mqPhone = window.matchMedia('(max-width:640px)').matches;
  var coarse = window.matchMedia('(pointer:coarse)').matches;
  var mobileish = mqPhone || (coarse && window.matchMedia('(max-width:1366px)').matches);

  function inject(id, src, sync) {
    if (document.getElementById(id)) return;
    var s = document.createElement('script');
    s.id = id; s.src = src;
    if (sync) s.async = false;   // ordered, earliest execution (dynamic-script defer is a no-op = async)
    else s.defer = true;
    (document.head || document.documentElement).appendChild(s);
  }
  var idleQ = [];
  function idle(id, src) { idleQ.push([id, src]); }

  // ?v= derives from window.LG_V — the filemtime map pwa-loader.php prepends when it
  // serves this file — so a `git pull` in the checkout busts the 1y-immutable asset
  // cache by itself. The literal number in each call is the FALLBACK (kept roughly
  // current at edit time) so any surface that serves pwa.js statically — buck's
  // preview slot, a loader outage — degrades to the old hand-bumped behavior, never
  // to a forever-stale ?v.
  var LGV = window.LG_V || {};
  function v(src, fb) { return src + '?v=' + (LGV[src.replace(/^\//, '')] || fb); }

  // The user settings engine FIRST (color theme / webfont / text size from
  // localStorage, applied site-wide). Earliest so a picked theme paints with
  // minimal flash; defaults apply no override, so most users see no change.
  inject('looth-app-settings-js', v('/app-settings.js', 32), true);

  if (onHub) {
    // Hub feed visual polish (app-card feed, desktop mosaic, action row …).
    inject('looth-hub-polish-js', v('/hub-polish.js', 227), true);
    // Tap-a-notification → reply modal (notif-quickreply lane, Ian 7/30 layout A).
    // GATED HERE, not just inside the file: with LG_NOTIF_QUICKREPLY_ENABLED off,
    // _chrome.php emits no data-lg-notifreply attribute and this file is never even
    // REQUESTED — flag OFF ships zero bytes rather than dead code. Loaded under onHub
    // beside hub-polish because it drives that file's composer through
    // window.lgOpenComposer; off /hub there is no composer and the notification rows
    // keep their existing navigation.
    if (document.body && document.body.getAttribute('data-lg-notifreply') === '1') {
      inject('looth-notif-reply-js', v('/notif-reply.js', 1), true);
    }
    // Hub infinite scroll (auto-append older feed items at the bottom).
    inject('looth-hub-infinite-js', v('/hub-infinite.js', 4));
    // Spotlight sponsor cards in the feed (Ian+Buck greenlight 2026-06-11).
    inject('looth-sponsor-cards-js', v('/sponsor-cards.js', 5));
    // Cover-image placeholder heights (scroll-jump mitigation, Buck 6/11).
    inject('looth-hub-nojump-js', v('/hub-nojump.js', 2), true);
    // Mobile Hub behaviors (≤640): killCompactOnMobile + long-press reactions.
    if (mobileish) inject('looth-mobile-hub-js', v('/mobile-hub.js', 4));
    // "Play today's Guitardle" strip under the sort bar; opens the game in a
    // pull-up sheet (mobile) / centered modal (desktop). Buck 6/12.
    // DECOMMISSIONED for launch (Ian 6/12) — Guitardle is a fast-follow;
    // re-enable this line to bring the Hub teaser back.
    // idle('looth-gdle-teaser-js', v('/guitardle-teaser.js', 6));
  }

  // Guitardle app-icon side art on the archive front page (stopgap until
  // canonical buck/guitardle-polish 903addb merges; layer bails if merged).
  // DECOMMISSIONED for launch (Ian 6/12) — fast-follow with the game block.
  // if (PATH.indexOf('/archive-poc') === 0 || PATH.indexOf('/front-page') === 0) {
  //   idle('looth-gdle-art-js', v('/gdle-side-art.js', 1));
  // }

  // Marketplace shop bubble REMOVED (Ian 2026-06-14): loothtool runs on its own
  // box now, so the "Loothtool" nav item is a plain link-out to loothtool.com (no
  // pop-up modal, no /shop/ mirror). The whole apparatus (shop-bubble.js, the feed
  // cron, the mirror scripts, the /shop/ page) is stashed for revival in the repo at
  // fast-follow/loothtool-shop/ — revive by repointing the feed at loothtool.com's
  // public Dokan API. DO NOT re-add inject('looth-shop-js', ...) without that work.

  // Bottom tab bar (Hub/Events/Members/Shop/You) on phones — but the SAME layer
  // also owns the DESKTOP header settings gear (lg-set-gear -> LGSettings panel),
  // so it must load on ALL viewports; it self-gates internally (tab bar <=640,
  // gear >=641). Gating it mobile-only removed the desktop gear (Ian 6/11).
  inject('looth-tabbar-js', v('/bottom-nav.js', 37));   // v37: 3.8 "← Hub" sticky pill (flag-gated, mobile post pages)

  if (mobileish) {
    inject('looth-mobile-fixes-js', v('/app-mobile-fixes.js', 36));
    // Tap-to-open sheets + push opt-in: needed soon, not needed for first paint.
    idle('looth-prac-sheet-js', v('/practice-sheet.js', 2));     // /p/<slug> business sheet
    idle('looth-prof-sheet-js', v('/profile-sheet.js', 8));      // /u/ profile sheet
    idle('looth-msgr-js', v('/messenger-sheet.js', 9));          // DM pull-up (v9: long-press react fix)
    idle('looth-spon-sheet-js', v('/sponsor-sheet.js', 11));      // sponsors sheet
    idle('looth-push-js', v('/push.js', 2));                     // self-gates mobile-coarse
  }

  if (onEvents) {
    inject('looth-loothalong-js', v('/loothalong.js', 5));       // pinned Loothalong CTA (v5: opens in new tab)
    inject('looth-events-live-js', v('/events-live.js', 1));     // LIVE-NOW surfacing
    if (mobileish) inject('looth-events-mobile-js', v('/events-mobile.js', 9)); // v9: sheet retired — taps go to the post page; landing search bar only
  }

  if (onDir) {
    // Coarse pointers can rotate across the 640 split — give them both layers
    // (each self-gates at init); fine pointers load only the matching one.
    if (coarse || mqPhone) inject('looth-dir-mobile-js', v('/directory-mobile.js', 12));
    if (coarse || !mqPhone) inject('looth-dir-desktop-js', v('/directory-desktop.js', 13));
  }

  (function () {
    function flush() { for (var i = 0; i < idleQ.length; i++) inject(idleQ[i][0], idleQ[i][1]); }
    if (!idleQ.length) return;
    if ('requestIdleCallback' in window) requestIdleCallback(flush, { timeout: 1500 });
    else setTimeout(flush, 600);
  })();

  // Retire the dead Kick-era "Stream" nav item from the shared header, site-wide.
  // The streaming integration is gone; "Archive" stays for reference. Client-side
  // removal because the canonical header (site-header.php) is coordinator-owned.
  (function () {
    function dropStreamNav() {
      var links = document.querySelectorAll('.lg-chrome__menu a[href^="/stream"]');
      for (var i = 0; i < links.length; i++) {
        var li = links[i].closest('li');
        (li || links[i]).remove();
      }
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', dropStreamNav);
    } else {
      dropStreamNav();
    }
  })();

  var DISMISS_KEY = 'looth_pwa_install_dismissed';
  var ua = navigator.userAgent || '';
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                     window.navigator.standalone === true;
  // Mobile-only gate: narrow viewport AND a coarse (touch) pointer.
  var isMobile = window.matchMedia('(max-width: 640px)').matches &&
                 window.matchMedia('(pointer: coarse)').matches;
  var isIOS = /iphone|ipad|ipod/i.test(ua) && !window.MSStream;
  var isiOSSafari = isIOS && /safari/i.test(ua) && !/crios|fxios|edgios/i.test(ua);

  function dismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function setDismissed() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
  }

  // Bail entirely unless we're on mobile, not already installed, not dismissed.
  if (isStandalone || !isMobile || dismissed()) return;

  // Engagement gate (HK-029 / GH #54): the banner used to appear on FIRST PAINT of
  // every page and overlay whatever was at the bottom (front CTAs, hub action rows,
  // even the directory Filters modal). Now it waits for real engagement: the 2nd
  // page of the session, or the first meaningful scroll (>400px) — whichever comes
  // first. Dismiss/install state still wins above.
  var pv = 1;
  try {
    pv = (parseInt(sessionStorage.getItem('looth_pwa_pv') || '0', 10) || 0) + 1;
    sessionStorage.setItem('looth_pwa_pv', String(pv));
  } catch (e) {}
  function whenEngaged(cb) {
    if (pv >= 2) { cb(); return; }
    var fired = false;
    function go() {
      if (fired) return;
      fired = true;
      window.removeEventListener('scroll', onScroll);
      cb();
    }
    function onScroll() { if ((window.scrollY || 0) > 400) go(); }
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  var deferredPrompt = null;

  // ---- LG_PWA_BANNER_INK_FIX (dark-anon-sweep lane, backlog 21, gates 36+45) ----
  //
  // The Install / "Show me how" buttons are white text on var(--lg-sage), and
  // they fail in BOTH themes: 3.12:1 in light, and 2.29:1 in dark because that
  // token repoints LIGHTER still (#87986a -> #9cb37d, see app-settings.js's
  // THEMES) while the text stays hardcoded #fff. The dark reading was the worst
  // text ratio in the whole anon sweep.
  //
  // ORIGINALLY NAMED LG_DARK_PWA_BANNER_FIX AND THAT NAME WAS WRONG. It fixed
  // only the dark half, because dark is where the sweep happened to be looking.
  // Gate 45 exists precisely to catch defects that fail in both themes, and
  // this is one of its three founding instances — so shipping a half-fix under
  // a name saying "dark" would have mislabelled it permanently for the next
  // reader, which is the exact thing I declined to do to the avatar palette.
  // Renamed and widened to fix both sides.
  //
  // Every state measured, not assumed — resting AND pressed, both themes:
  //     light rest    #15171a on #87986a  5.75:1
  //     dark  rest    #15171a on #9cb37d  7.83:1
  //     light pressed #ffffff on #6b7c52  4.54:1  (unchanged; already passing)
  //     dark  pressed #15171a on #b0c693  9.70:1
  // The pressed state needs its own handling because --lg-sage-d goes DARKER in
  // light and LIGHTER in dark, so a single ink cannot serve both.
  //
  // Local module-scoped var, not a window global — pwa.js's own injection order
  // for dynamically-created scripts is not guaranteed, so a shared flag would
  // race. Single copy, so gate 49's paired-flag agreement check is a no-op here.
  var LG_PWA_BANNER_INK_FIX = true;   // ON 2026-08-15: keeper mandate, both themes

  function injectStyles() {
    if (document.getElementById('looth-pwa-style')) return;
    var css =
      '#looth-pwa-banner{position:fixed;left:12px;right:12px;bottom:12px;z-index:3000;' +
      'background:var(--lg-cream,#fbfbf8);color:var(--lg-ink,#323532);border:1px solid var(--lg-line,#e3ddd0);' +
      'border-radius:16px;box-shadow:0 6px 24px rgba(26,29,26,.18);padding:14px 14px 14px 16px;' +
      'display:flex;align-items:center;gap:12px;' +
      'font:15px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;animation:looth-pwa-up .28s ease}' +
      '@keyframes looth-pwa-up{from{transform:translateY(120%);opacity:0}to{transform:translateY(0);opacity:1}}' +
      '#looth-pwa-banner img{width:40px;height:40px;border-radius:10px;flex:0 0 auto}' +
      '#looth-pwa-banner .lpw-tx{flex:1 1 auto;min-width:0}' +
      '#looth-pwa-banner .lpw-tl{font-weight:600;color:var(--lg-charcoal,#1a1d1a)}' +
      '#looth-pwa-banner .lpw-sb,#looth-pwa-banner .lpw-ios{font-size:13px;color:var(--lg-mute,#6b6f6b);margin-top:1px}' +
      '#looth-pwa-banner .lpw-ios b{color:var(--lg-ink,#323532)}' +
      '#looth-pwa-banner .lpw-act{display:flex;align-items:center;gap:4px;flex:0 0 auto}' +
      '#looth-pwa-banner button{font:inherit;cursor:pointer;border-radius:10px;border:0}' +
      '#looth-pwa-banner .lpw-install,#looth-pwa-banner .lpw-how{background:var(--lg-sage,#87986a);color:#fff;font-weight:600;padding:9px 14px;white-space:nowrap}' +
      '#looth-pwa-banner .lpw-install:active,#looth-pwa-banner .lpw-how:active{background:var(--lg-sage-d,#6b7c52)}' +
      (LG_PWA_BANNER_INK_FIX
        // Dark ink on the RESTING button in BOTH themes: white failed on the
        // sage fill either way (light 3.12:1, dark 2.29:1) because --lg-sage is
        // a mid-tone in light and repoints LIGHTER still in dark. #15171a gives
        // 5.75:1 light / 7.83:1 dark. The PRESSED state is left alone in light
        // — its fill darkens to --lg-sage-d #6b7c52 where the original white is
        // already 4.54:1 and passing — but restored to dark ink under dark,
        // where that same token repoints to a LIGHT #b0c693 (9.70:1). Every
        // state clears AA in both themes; measured, not assumed.
        ? '#looth-pwa-banner .lpw-install,#looth-pwa-banner .lpw-how{color:#15171a}' +
          '#looth-pwa-banner .lpw-install:active,#looth-pwa-banner .lpw-how:active{color:#fff}' +
          'html[data-lguser-theme="dark"] #looth-pwa-banner .lpw-install:active,' +
          'html[data-lguser-theme="dark"] #looth-pwa-banner .lpw-how:active{color:#15171a}'
        : '') +
      '#looth-pwa-banner .lpw-x{background:transparent;color:var(--lg-mute,#6b6f6b);padding:8px 8px;font-size:20px;line-height:1}' +
      /* iOS step-by-step "Add to Home Screen" sheet (Buck 2026-06-08: make it super easy) */
      '#looth-ios-sheet{position:fixed;inset:0;z-index:3900;display:none}' +
      '#looth-ios-sheet.is-open{display:block}' +
      '#looth-ios-sheet .lis-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}' +
      '#looth-ios-sheet .lis-card{position:absolute;left:10px;right:10px;bottom:10px;background:var(--lg-cream,#fbfbf8);' +
        'border-radius:18px;padding:18px 16px 16px;box-shadow:0 -8px 30px rgba(26,29,26,.32);' +
        'font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--lg-ink,#323532);' +
        'animation:looth-pwa-up .26s ease}' +
      '#looth-ios-sheet .lis-h{display:flex;align-items:center;gap:11px;margin-bottom:6px}' +
      '#looth-ios-sheet .lis-h img{width:38px;height:38px;border-radius:9px;flex:0 0 auto}' +
      '#looth-ios-sheet .lis-h .t{font:700 17px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}' +
      '#looth-ios-sheet .lis-h .s{font-size:13px;color:var(--lg-mute,#6b6f6b);margin-top:1px}' +
      '#looth-ios-sheet .lis-x{position:absolute;top:10px;right:12px;background:transparent;border:0;color:var(--lg-mute,#6b6f6b);font-size:24px;line-height:1;cursor:pointer;padding:4px 8px}' +
      '#looth-ios-sheet .lis-step{display:flex;align-items:center;gap:12px;padding:11px 2px;border-top:1px solid var(--lg-line,#e3ddd0)}' +
      '#looth-ios-sheet .lis-step:first-of-type{margin-top:8px}' +
      '#looth-ios-sheet .lis-n{flex:0 0 auto;width:24px;height:24px;border-radius:50%;background:var(--lg-sage,#87986a);color:#fff;font:700 13px/24px system-ui;text-align:center}' +
      '#looth-ios-sheet .lis-ic{flex:0 0 auto;width:30px;height:30px;display:flex;align-items:center;justify-content:center;color:var(--lg-sage-d,#6b7c52);background:var(--lg-sage-tint,#eef2e3);border-radius:8px}' +
      '#looth-ios-sheet .lis-ic svg{width:20px;height:20px}' +
      '#looth-ios-sheet .lis-tx{flex:1 1 auto;min-width:0}' +
      '#looth-ios-sheet .lis-tx b{color:var(--lg-charcoal,#1a1d1a)}' +
      '#looth-ios-sheet .lis-done{display:block;width:100%;margin-top:15px;background:var(--lg-sage,#87986a);color:#fff;' +
        'font:600 15px/1 system-ui;border:0;border-radius:12px;padding:14px;cursor:pointer}' +
      '#looth-ios-sheet .lis-done:active{background:var(--lg-sage-d,#6b7c52)}';
    var s = document.createElement('style');
    s.id = 'looth-pwa-style';
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function removeBanner() {
    var b = document.getElementById('looth-pwa-banner');
    if (b && b.parentNode) b.parentNode.removeChild(b);
  }

  function showBanner(mode) {
    if (document.getElementById('looth-pwa-banner') || dismissed()) return;
    injectStyles();
    var el = document.createElement('div');
    el.id = 'looth-pwa-banner';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Install the Looth app');
    var icon = '<img src="/icons/icon-192.png" alt="">';
    if (mode === 'ios') {
      el.innerHTML = icon +
        '<div class="lpw-tx"><div class="lpw-tl">Install Looth</div>' +
        '<div class="lpw-sb">Add the app to your Home Screen</div></div>' +
        '<div class="lpw-act"><button class="lpw-how" type="button">Show me how</button>' +
        '<button class="lpw-x" type="button" aria-label="Dismiss">&times;</button></div>';
    } else {
      el.innerHTML = icon +
        '<div class="lpw-tx"><div class="lpw-tl">Install Looth</div>' +
        '<div class="lpw-sb">Add it to your home screen</div></div>' +
        '<div class="lpw-act"><button class="lpw-install" type="button">Install</button>' +
        '<button class="lpw-x" type="button" aria-label="Dismiss">&times;</button></div>';
    }
    (document.body || document.documentElement).appendChild(el);

    var x = el.querySelector('.lpw-x');
    if (x) x.addEventListener('click', function () { setDismissed(); removeBanner(); });

    var inst = el.querySelector('.lpw-install');
    if (inst) inst.addEventListener('click', function () {
      if (!deferredPrompt) { removeBanner(); return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function (res) {
        if (res && res.outcome === 'accepted') setDismissed();
        deferredPrompt = null;
        removeBanner();
      });
    });

    var how = el.querySelector('.lpw-how');
    if (how) how.addEventListener('click', showIosSheet);
  }

  // iOS-only step-by-step "Add to Home Screen" sheet — Safari has no install prompt,
  // so spell it out with the real icons (Buck 2026-06-08: make it super easy).
  function showIosSheet() {
    injectStyles();
    if (document.getElementById('looth-ios-sheet')) {
      document.getElementById('looth-ios-sheet').classList.add('is-open'); return;
    }
    var shareIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3"/><path d="m8 7 4-4 4 4"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>';
    var addIco   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="4.5"/><path d="M12 9v6M9 12h6"/></svg>';
    var doneIco  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    var s = document.createElement('div');
    s.id = 'looth-ios-sheet';
    s.setAttribute('role', 'dialog');
    s.setAttribute('aria-label', 'Add Looth to your Home Screen');
    s.innerHTML =
      '<div class="lis-back" data-lis-close></div>' +
      '<div class="lis-card">' +
        '<button class="lis-x" type="button" aria-label="Close" data-lis-close>&times;</button>' +
        '<div class="lis-h"><img src="/icons/icon-192.png" alt="">' +
          '<div><div class="t">Add Looth to your Home Screen</div>' +
          '<div class="s">Takes 5 seconds — here’s how:</div></div></div>' +
        '<div class="lis-step"><span class="lis-n">1</span><span class="lis-ic">' + shareIco + '</span>' +
          '<span class="lis-tx">Tap the <b>Share</b> button in Safari’s toolbar (the box with an up-arrow, at the bottom).</span></div>' +
        '<div class="lis-step"><span class="lis-n">2</span><span class="lis-ic">' + addIco + '</span>' +
          '<span class="lis-tx">Scroll down and tap <b>Add to Home Screen</b>.</span></div>' +
        '<div class="lis-step"><span class="lis-n">3</span><span class="lis-ic">' + doneIco + '</span>' +
          '<span class="lis-tx">Tap <b>Add</b> — and Looth lands on your Home Screen like any app.</span></div>' +
        '<button class="lis-done" type="button" data-lis-close>Got it</button>' +
      '</div>';
    (document.body || document.documentElement).appendChild(s);
    requestAnimationFrame(function () { s.classList.add('is-open'); });
    s.addEventListener('click', function (e) {
      if (e.target.closest('[data-lis-close]')) s.classList.remove('is-open');
    });
  }

  // Chromium: intercept the native mini-infobar and show our own banner.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    whenEngaged(function () { showBanner('install'); });
  });

  window.addEventListener('appinstalled', function () {
    setDismissed();
    deferredPrompt = null;
    removeBanner();
  });

  // iOS Safari has no beforeinstallprompt — surface manual instructions.
  if (isiOSSafari) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { whenEngaged(function () { showBanner('ios'); }); });
    } else {
      whenEngaged(function () { showBanner('ios'); });
    }
  }
})();
