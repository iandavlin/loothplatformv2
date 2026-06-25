/* Looth bottom tab bar — iOS-style mobile navigation.
   Self-contained: injects its own styles + a fixed bottom bar with 5 tabs
   (Hub, Events, Members, Shop, Profile). Shown only on mobile viewports; on desktop it
   stays out of the way. The Shop tab reuses the existing shop drawer
   (#looth-shop-fab) instead of a separate page, and the floating Shop FAB is
   hidden on mobile so the two don't duplicate.
   Loaded site-wide via /pwa.js (already injected into every <head>). */
(function () {
  'use strict';
  if (window.__loothTabbar) return;
  window.__loothTabbar = true;

  var MOBILE_MQ = '(max-width: 640px)';
  var BAR_ID = 'looth-tabbar';
  var STYLE_ID = 'looth-tabbar-style';

  // Inline stroke icons (24x24, currentColor). No emoji per brand rules.
  var ICONS = {
    // Wagon wheel (Ian 2026-06-17) — Hub had the same house glyph as Home; the
    // wheel (rim + hub + 8 spokes) distinguishes it. Stroke is inherited from the
    // svg (fill:none, stroke:currentColor), so circles + spokes render as outlines.
    hub: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.3"/>' +
         '<path d="M12 3v6.7M12 14.3V21M3 12h6.7M14.3 12H21' +
         'M5.64 5.64 10.37 10.37M18.36 18.36 13.63 13.63' +
         'M18.36 5.64 13.63 10.37M5.64 18.36 10.37 13.63"/>',
    events: '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9h18"/><path d="M8 2.5v4M16 2.5v4"/>',
    members: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5c0-3 2.6-5 5.5-5s5.5 2 5.5 5"/><path d="M16 5.6a3 3 0 0 1 0 5.4"/><path d="M17.5 14.6c2 .5 3.5 2.2 3.5 4.9"/>',
    shop: '<path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    // Sponsors — a star (featured partners). fill:none + stroke inherited from the svg.
    sponsors: '<path d="M12 3l2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.2l5.9-.8z"/>',
    // Home = the marketing front page (was unreachable on mobile before the
    // 3-button bar — the Hub header hides the logo). Lives top-left in the tray.
    home: '<path d="M3 11 12 3l9 8"/><path d="M5 9.5V20h14V9.5"/><path d="M9.5 20v-5h5v5"/>',
    // Nav button: a 2x2 grid (opens the destinations tray).
    grid: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/>',
    // Big center Post button.
    plus: '<path d="M12 5v14M5 12h14"/>',
    messages: '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 9 9 0 0 1-3.2-.6L4 21l1.9-4.4a8 8 0 0 1-1.4-4.6A8.4 8.4 0 0 1 13 3.7a8.4 8.4 0 0 1 8 7.8z"/>',
    alerts: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M10 21h4"/>',
    loothtool: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    // Fallback only — the Profile tab shows the member's avatar when one exists.
    person: '<circle cx="12" cy="8.5" r="3.8"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/>'
  };

  // Destinations shown in the Nav tray (the slide-up "Go to" sheet) — pure
  // PLACES only. Messages + Alerts(notifications) are personal, not destinations,
  // so they live in the You sheet (Messages row + Notifications row), NOT here
  // (Ian/keeper 2026-06-24). `home:true` paints the accent tile. Shop opens its
  // full page (Buck 2026-06-09). Order = grid.
  var DESTS = [
    { key: 'home',     label: 'Home',      href: '/front-page/',         icon: ICONS.home, home: true },
    { key: 'hub',      label: 'Hub',       href: '/hub/',                icon: ICONS.hub },
    { key: 'events',   label: 'Events',    href: '/events/',             icon: ICONS.events },
    { key: 'members',  label: 'Members',   href: '/directory/members/',  icon: ICONS.members },
    { key: 'shop',     label: 'Shop',      href: '/shop/',               icon: ICONS.shop },
    { key: 'sponsors', label: 'Sponsors',  href: '/sponsors/',           icon: ICONS.sponsors },
    { key: 'loothtool',label: 'Loothtool', href: 'https://loothtool.com/', icon: ICONS.loothtool, ext: true }
  ];

  // Pull the signed-in member's avatar from the shared header (.lg-chrome__avatar
  // img). Returns the src, or '' when the header only shows a text initial.
  function avatarSrc() {
    var img = document.querySelector('.lg-chrome__avatar img, .lg-chrome__account img');
    var src = img && (img.currentSrc || img.getAttribute('src'));
    return src || '';
  }

  // Auth signal, read from the server-rendered shared header: the account button
  // (.lg-chrome__account) is emitted only for logged-in users; anon gets the
  // Sign-in / Join cluster instead. It's in the initial HTML (and only display:none
  // on mobile), so this is reliable at build time. (Ian 2026-06-24.)
  function isAuthed() { return !!document.querySelector('.lg-chrome__account'); }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var H = 54; // bar content height (px); safe-area added on top
    var css =
      '#' + BAR_ID + '{position:fixed;left:0;right:0;bottom:0;z-index:2147481200;' +
      'display:none;align-items:stretch;justify-content:space-around;' +
      'height:calc(' + H + 'px + env(safe-area-inset-bottom,0px));' +
      'padding-bottom:env(safe-area-inset-bottom,0px);' +
      'background:rgba(251,251,248,.92);-webkit-backdrop-filter:saturate(1.6) blur(14px);' +
      'backdrop-filter:saturate(1.6) blur(14px);border-top:1px solid var(--lg-line,#e3ddd0);' +
      'box-shadow:0 -1px 12px rgba(26,29,26,.06);' +
      'font:11px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}' +
      // each tab
      '#' + BAR_ID + ' a,#' + BAR_ID + ' button{all:unset;box-sizing:border-box;position:relative;' +
      'flex:1 1 0;display:flex;flex-direction:column;align-items:center;justify-content:center;' +
      'gap:3px;cursor:pointer;color:var(--lg-mute,#6b6f6b);' +
      '-webkit-tap-highlight-color:transparent;padding:7px 2px 5px;min-width:0;' +
      'transition:color .15s ease}' +
      '#' + BAR_ID + ' .lt-ico{width:25px;height:25px;display:block}' +
      '#' + BAR_ID + ' .lt-ico svg{width:25px;height:25px;display:block;fill:none;' +
      'stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}' +
      // Profile tab avatar (Instagram-style): circular, with an active ring.
      '#' + BAR_ID + ' .lt-avi{border-radius:50%;overflow:hidden;background:var(--lg-sage-tint,#eef2e3);' +
      'box-shadow:0 0 0 1.5px transparent;transition:box-shadow .15s ease}' +
      '#' + BAR_ID + ' .lt-avi img{width:100%;height:100%;object-fit:cover;display:block}' +
      '#' + BAR_ID + ' .is-active .lt-avi{box-shadow:0 0 0 2px var(--lg-sage-d,#6b7c52)}' +
      '#' + BAR_ID + ' .lt-lb{font-weight:600;letter-spacing:.01em;max-width:100%;' +
      'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      // active state
      '#' + BAR_ID + ' .is-active{color:var(--lg-sage-d,#6b7c52)}' +
      '#' + BAR_ID + ' a:active,#' + BAR_ID + ' button:active{color:var(--lg-sage,#87986a)}' +
      // ---- Big center Post button (raised, always visible) ----
      '#' + BAR_ID + ' .lt-post{flex:0 0 auto;width:64px;color:#fff !important;justify-content:flex-start}' +
      '#' + BAR_ID + ' .lt-post-ico{margin-top:-20px;width:54px;height:54px;border-radius:50%;' +
        'background:var(--lg-sage-d,#52613d);display:flex;align-items:center;justify-content:center;' +
        'border:3px solid var(--lg-cream,#fbfbf8);box-shadow:0 6px 16px rgba(82,97,61,.45);' +
        'transition:transform .12s ease}' +
      '#' + BAR_ID + ' .lt-post-ico svg{width:27px;height:27px;stroke:#fff;stroke-width:2.4}' +
      '#' + BAR_ID + ' .lt-post:active .lt-post-ico{transform:scale(.93)}' +
      // show only on mobile
      '@media ' + MOBILE_MQ + '{#' + BAR_ID + '{display:flex}' +
      'body.has-looth-tabbar{padding-bottom:calc(' + H + 'px + env(safe-area-inset-bottom,0px)) !important}' +
      // Shop tab replaces the floating FAB on mobile
      '#looth-shop-fab{display:none !important}' +
      // The center Post button now owns posting on mobile — hide the redundant
      // (and previously clipped/scroll-tucked) sort-bar post button to avoid two.
      '.feed-sort-bar>.lg-newpost,.feed-sort-bar>.feed-post-btn,.forum-header__new-post{display:none !important}' +
      // lift the install banner above the bar
      '#looth-pwa-banner{bottom:calc(' + (H + 14) + 'px + env(safe-area-inset-bottom,0px)) !important}' +
      // Consolidate to the bottom "You": hide the shared header's account bubble
      // on the app (mobile). Desktop keeps it. Avatar src is still readable for
      // the profile tab even while the button is display:none.
      '.lg-chrome__account{display:none !important}' +
      // No hamburger on mobile (Ian 2026-06-24): the bottom-bar Nav tray is the
      // sole mobile menu now. Hide the shared header's hamburger + its fold-down
      // nav drawer. CSS-only — the canonical header markup is left untouched; this
      // is the overlay layer (drawer destinations now live in the Nav tray).
      '.lg-chrome__hamburger{display:none !important}' +
      '.lg-chrome__nav{display:none !important}}' +

      // ---- Profile sheet (Instagram-style upward sheet from "You") ----
      '.lt-sheet-bd{position:fixed;inset:0;z-index:2147481300;background:rgba(26,29,26,.45);' +
        'opacity:0;visibility:hidden;transition:opacity .22s ease,visibility .22s ease}' +
      '.lt-sheet-bd.is-open{opacity:1;visibility:visible}' +
      '.lt-sheet{position:fixed;left:0;right:0;bottom:0;z-index:2147481400;max-height:86vh;overflow:auto;' +
        '-webkit-overflow-scrolling:touch;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;' +
        'box-shadow:0 -8px 40px rgba(26,29,26,.22);transform:translateY(100%);' +
        'transition:transform .26s cubic-bezier(.32,.72,0,1);' +
        'padding:0 16px calc(18px + env(safe-area-inset-bottom,0px));' +
        'font:14px/1.4 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif);color:var(--lg-ink,#323532)}' +
      '.lt-sheet.is-open{transform:translateY(0)}' +
      // Grab handle: visible bar stays 40x5, but content-box padding gives it a big
      // (~100x32) forgiving tap/drag target — a plain tap on it closes the sheet
      // (Ian 2026-06-24). cursor:pointer signals it's interactive.
      '.lt-sheet__grab{width:40px;height:5px;border-radius:999px;background:var(--lg-line,#e3ddd0);' +
        'margin:0 auto 2px;padding:13px 30px;box-sizing:content-box;background-clip:content-box;cursor:pointer;' +
        'touch-action:none}' +
      '.lt-sheet__head{display:flex;align-items:center;gap:12px;padding:8px 2px 14px;' +
        'border-bottom:1px solid var(--lg-line,#e3ddd0)}' +
      '.lt-sheet__avi{width:46px;height:46px;border-radius:50%;overflow:hidden;flex:0 0 auto;' +
        'background:var(--lg-sage-tint,#eef2e3);box-shadow:0 0 0 2px var(--lg-sage-d,#6b7c52)}' +
      '.lt-sheet__avi img{width:100%;height:100%;object-fit:cover;display:block}' +
      '.lt-sheet__id{display:flex;flex-direction:column;min-width:0}' +
      '.lt-sheet__name{font-weight:700;font-size:15px;color:var(--lg-charcoal,#1a1d1a);' +
        'font-family:var(--lg-font-serif,Georgia,serif);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.lt-sheet__view{font-size:12.5px;color:var(--lg-sage-d,#6b7c52);text-decoration:none}' +
      '.lt-sheet__nav{display:flex;flex-direction:column;padding:6px 0;border-bottom:1px solid var(--lg-line,#e3ddd0)}' +
      '.lt-sheet__row{display:block;padding:12px 6px;color:var(--lg-ink,#323532);text-decoration:none;' +
        'border-radius:10px;font-weight:600}' +
      '.lt-sheet__row:active{background:var(--lg-sage-tint,#eef2e3)}' +
      // Log out — a pill on the FAR RIGHT of the header (margin-left:auto pushes it
      // opposite the avatar/View-profile), warm sign-out color, with a real tap
      // target + breathing room so it's not misclicked next to View profile.
      '.lt-sheet__logout{margin-left:auto;flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;' +
        'padding:9px 13px;border:1px solid rgba(198,104,69,.45);border-radius:999px;color:#c66845;' +
        'font:700 13px/1 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none;background:rgba(198,104,69,.06)}' +
      '.lt-sheet__logout:active{background:rgba(198,104,69,.18)}' +
      '.lt-sheet__logout svg{flex:0 0 auto}' +
      // Sign in — the anon counterpart, same far-right slot as Log out but the
      // sage "go" color instead of the warm sign-out color (Ian 2026-06-24).
      '.lt-sheet__login{margin-left:auto;flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;' +
        'padding:9px 13px;border:1px solid var(--lg-sage-d,#6b7c52);border-radius:999px;color:#fff;' +
        'font:700 13px/1 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none;background:var(--lg-sage-d,#52613d)}' +
      '.lt-sheet__login:active{background:var(--lg-sage,#87986a)}' +
      '.lt-sheet__login svg{flex:0 0 auto}' +
      '.lt-sheet__sech{font-weight:700;font-size:12px;letter-spacing:.05em;text-transform:uppercase;' +
        'color:var(--lg-mute,#6b6f6b);padding:14px 6px 4px}' +
      // ---- Nav tray: "Go to" destinations grid (slide-up, same sheet infra) ----
      '.lt-navgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;padding:6px 0 4px}' +
      '.lt-navitem{display:flex;flex-direction:column;align-items:center;gap:7px;padding:13px 4px;' +
        'border-radius:14px;text-decoration:none;color:var(--lg-ink,#323532);' +
        'font:600 12px/1.1 var(--lg-font-sans,system-ui,sans-serif);text-align:center}' +
      '.lt-navitem:active{background:var(--lg-sage-tint,#eef2e3)}' +
      '.lt-navitem .lt-nico{width:48px;height:48px;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'display:flex;align-items:center;justify-content:center;color:var(--lg-sage-d,#6b7c52)}' +
      '.lt-navitem .lt-nico svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.8;' +
        'stroke-linecap:round;stroke-linejoin:round}' +
      '.lt-navitem.is-here .lt-nico{box-shadow:0 0 0 2px var(--lg-sage-d,#6b7c52)}' +
      '.lt-navitem.lt-home .lt-nico{background:var(--lg-sage-d,#52613d);color:#fff}' +
      '.lt-navitem .lt-ndot{position:absolute;margin:-4px 0 0 30px;min-width:8px;height:8px;border-radius:50%;background:#e23b3b}' +
      // notification count badge on the You tab (Instagram-style)
      '#' + BAR_ID + ' .lt-badge{position:absolute;top:5px;left:calc(50% + 5px);min-width:16px;height:16px;' +
        'padding:0 4px;box-sizing:border-box;border-radius:9px;background:#e23b3b;color:#fff;' +
        'font:700 10px/16px var(--lg-font-sans,system-ui,sans-serif);text-align:center;' +
        'box-shadow:0 0 0 2px var(--lg-cream,#fbfbf8);pointer-events:none}' +
      // notifications section in the sheet
      '.lt-notifs__h{display:flex!important;align-items:center;justify-content:space-between}' +
      '.lt-notifs__clear{border:0;background:none;color:var(--lg-sage-d,#6b7c52);font:700 12px/1 var(--lg-font-sans,system-ui,sans-serif);' +
        'text-transform:none;letter-spacing:0;cursor:pointer;padding:4px 6px}' +
      '.lt-notifs{padding:2px 0 6px;display:flex;flex-direction:column}' +
      '.lt-notif{display:flex;align-items:center;gap:11px;width:100%;text-align:left;border:0;background:none;' +
        'cursor:pointer;padding:9px 6px;border-radius:10px}' +
      '.lt-notif:active{background:var(--lg-sage-tint,#eef2e3)}' +
      '.lt-notif.is-unread{background:var(--lg-sage-tint,#eef2e3)}' +
      '.lt-notif-avi{flex:0 0 auto;width:34px;height:34px;border-radius:50%;overflow:hidden;background:var(--lg-sage-3,#d4e0b8)}' +
      '.lt-notif-avi img{width:100%;height:100%;object-fit:cover;display:block}' +
      '.lt-notif-tx{min-width:0;display:flex;flex-direction:column;gap:1px;flex:1 1 auto}' +
      '.lt-notif-t{font:500 13.5px/1.3 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532)}' +
      '.lt-notif-time{font:12px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}' +
      '.lt-notif-dot{flex:0 0 auto;width:8px;height:8px;border-radius:50%;background:#e23b3b}' +
      '.lt-notif-empty{padding:10px 6px;color:var(--lg-mute,#6b6f6b);font:13px/1.4 var(--lg-font-sans,system-ui,sans-serif)}' +
      '.lt-saved{padding:2px 0 6px;display:flex;flex-direction:column}' +
      '.lt-saved-row{display:flex;align-items:center;gap:11px;padding:8px 6px;text-decoration:none;border-radius:10px}' +
      '.lt-saved-row:active{background:var(--lg-sage-tint,#eef2e3)}' +
      '.lt-saved-thumb{flex:0 0 auto;width:46px;height:46px;border-radius:8px;background:var(--lg-sage-3,#d4e0b8) center/cover no-repeat}' +
      '.lt-saved-t{min-width:0;font:600 13.5px/1.35 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532);' +
        'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}' +
      '.lt-notif-all{margin:4px 0 2px;border:0;background:none;color:var(--lg-sage-d,#6b7c52);cursor:pointer;' +
        'font:600 13px/1 var(--lg-font-sans,system-ui,sans-serif);text-align:left;padding:8px 6px}' +
      // settings panel (rendered by app-settings.js buildPanel)
      '.lg-set-sec{padding:6px 6px 10px}' +
      '.lg-set-sec__h{font-size:12.5px;font-weight:600;color:var(--lg-mute,#6b6f6b);margin:0 0 8px}' +
      '.lg-set-row{display:flex;flex-wrap:wrap;gap:8px}' +
      '.lg-set-opt{display:inline-flex;align-items:center;gap:7px;background:#fff;' +
        'border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:8px 13px;' +
        'font:600 13px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532);cursor:pointer}' +
      '.lg-set-opt.is-on{border-color:var(--lg-sage-d,#6b7c52);box-shadow:0 0 0 1px var(--lg-sage-d,#6b7c52);' +
        'background:var(--lg-sage-tint,#eef2e3)}' +
      '.lg-set-swatch{width:15px;height:15px;border-radius:50%;border:1px solid rgba(0,0,0,.12);flex:0 0 auto}' +
      // hide the install banner while the sheet is open so it doesn't overlap
      'body.lt-sheet-open #looth-pwa-banner{display:none !important}' +
      // ---- Tab-switch skeleton: a perceived-speed bridge during the full-page nav.
      // Shown instantly on a tab tap; the server-rendered page paints over it on arrival.
      '#looth-skel{position:fixed;left:0;top:0;right:0;bottom:0;z-index:2147481000;' +
        'background:var(--lg-cream,#fbfbf8);display:none;padding:14px 12px;overflow:hidden}' +
      '#looth-skel.is-on{display:block}' +
      '#looth-skel .sk{background:#fff;border:1px solid var(--lg-line,#e3ddd0);border-radius:16px;' +
        'margin:0 0 12px;overflow:hidden;position:relative}' +
      '#looth-skel .sk-cover{height:150px;background:#ece9e1}' +
      '#looth-skel .sk-row{height:13px;border-radius:7px;background:#ece9e1;margin:10px 14px}' +
      '#looth-skel .sk::after{content:"";position:absolute;inset:0;' +
        'background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);' +
        'transform:translateX(-100%);animation:lt-shimmer 1.2s infinite}' +
      '@keyframes lt-shimmer{100%{transform:translateX(100%)}}' +
      // Match the immersive feed look when that view is active (Buck 2026-06-08):
      // edge-to-edge, no border/radius, full-bleed cover, slim divider, tight gap.
      'html[data-lguser-feed="immersive"] #looth-skel{padding:0}' +
      'html[data-lguser-feed="immersive"] #looth-skel .sk{border:0;border-radius:0;' +
        'margin:0 0 3px;border-bottom:1px solid var(--lg-line,#e3ddd0)}' +
      'html[data-lguser-feed="immersive"] #looth-skel .sk-cover{height:188px}' +
      // Dark mode: match the dark feed palette so the pre-load skeleton isn't a
      // bright flash on tab switch (Buck 2026-06-08). Keyed on the same
      // html[data-lguser-theme="dark"] attribute app-settings.js sets.
      'html[data-lguser-theme="dark"] #looth-skel{background:#15171a}' +
      'html[data-lguser-theme="dark"] #looth-skel .sk{background:#1e2124;border-color:#2c312d}' +
      'html[data-lguser-theme="dark"] #looth-skel .sk-cover,html[data-lguser-theme="dark"] #looth-skel .sk-row{background:#2a2f2b}' +
      'html[data-lguser-theme="dark"] #looth-skel .sk::after{background:linear-gradient(90deg,transparent,rgba(255,255,255,.06),transparent)}' +
      'html[data-lguser-theme="dark"][data-lguser-feed="immersive"] #looth-skel .sk{border-bottom-color:#2c312d}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function openShop() {
    // The Shop is now its own full page (Buck 2026-06-09) instead of a side
    // drawer — navigate there. (Old behavior: clicked the #looth-shop-fab drawer.)
    try { window.location.assign('/shop/'); } catch (e) { window.location.href = '/shop/'; }
  }

  function shopOpen() {
    var ov = document.getElementById('looth-shop-ov');
    return !!(ov && ov.classList.contains('is-open'));
  }

  // Instant skeleton overlay shown on a tab tap, bridging the full-page-nav wait
  // so switching tabs feels snappy. Mobile only; auto-clears as a safety if the
  // navigation never happens (e.g. same-page).
  var skelTimer = null;
  function showTabSkeleton() {
    if (!window.matchMedia(MOBILE_MQ).matches) return;
    var el = document.getElementById('looth-skel');
    if (!el) {
      el = document.createElement('div');
      el.id = 'looth-skel';
      el.setAttribute('aria-hidden', 'true');
      var card = '<div class="sk"><div class="sk-cover"></div>' +
        '<div class="sk-row" style="width:68%"></div>' +
        '<div class="sk-row" style="width:92%"></div>' +
        '<div class="sk-row" style="width:46%"></div></div>';
      el.innerHTML = card + card + card + card;
      (document.body || document.documentElement).appendChild(el);
    }
    el.classList.add('is-on');
    clearTimeout(skelTimer);
    skelTimer = setTimeout(function () { el.classList.remove('is-on'); }, 4000);
  }

  // Open the Hub's existing "new topic" modal composer (#ntm-overlay) by firing
  // any [data-ntm-open] trigger on the page — works even though we hide the
  // sort-bar post button on mobile (.click() fires on display:none elements and
  // the delegated handler in forums.js catches it). Off the Hub there's no
  // composer, so route to the Hub where it lives.
  function openComposer() {
    var trigger = document.querySelector('[data-ntm-open]');
    if (trigger) { trigger.click(); return; }
    showTabSkeleton();
    try { window.location.assign('/hub/'); } catch (e) { window.location.href = '/hub/'; }
  }

  // 3-button bar: Nav (tray) · Post (modal) · You (profile sheet). Replaces the
  // old 5 destination tabs — destinations moved into the Nav tray so the bar can
  // give Post a big, always-visible center action and restore a Home door
  // (Ian 2026-06-17). Mobile only; desktop never shows the bar.
  function build() {
    if (document.getElementById(BAR_ID)) return;
    injectStyles();

    var nav = document.createElement('nav');
    nav.id = BAR_ID;
    nav.setAttribute('role', 'navigation');
    nav.setAttribute('aria-label', 'Primary');

    // Nav (opens the destinations tray)
    var navBtn = document.createElement('button');
    navBtn.type = 'button';
    navBtn.setAttribute('aria-label', 'Menu');
    navBtn.setAttribute('aria-haspopup', 'dialog');
    navBtn.innerHTML = '<span class="lt-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS.grid + '</svg></span><span class="lt-lb">Nav</span>';
    navBtn.addEventListener('click', openNav);
    nav.appendChild(navBtn);

    // Post (big center) — pops the existing composer modal
    var postBtn = document.createElement('button');
    postBtn.type = 'button';
    postBtn.className = 'lt-post';
    postBtn.setAttribute('aria-label', 'New post');
    postBtn.innerHTML = '<span class="lt-post-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS.plus + '</svg></span>';
    postBtn.addEventListener('click', openComposer);
    nav.appendChild(postBtn);

    // You (opens the profile sheet — unchanged behavior)
    var youBtn = document.createElement('a');
    youBtn.href = '/profile/edit';
    var src = avatarSrc();
    youBtn.innerHTML = (src
        ? '<span class="lt-ico lt-avi"><img src="' + src + '" alt=""></span>'
        : '<span class="lt-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS.person + '</svg></span>') +
      '<span class="lt-lb">You</span>';
    youBtn.setAttribute('aria-label', 'You');
    if (/^\/(profile|u)(\/|$)/.test(location.pathname || '')) { youBtn.className = 'is-active'; youBtn.setAttribute('aria-current', 'page'); }
    youBtn.addEventListener('click', function (e) { e.preventDefault(); openSheet(); });
    nav.appendChild(youBtn);

    (document.body || document.documentElement).appendChild(nav);
    document.body.classList.add('has-looth-tabbar');

    // Notification count badge on the You tab (Instagram-style).
    var youTab = nav.querySelector('a[href="/profile/edit"]');
    if (youTab && !youTab.querySelector('.lt-badge')) {
      var bdg = document.createElement('span'); bdg.className = 'lt-badge'; bdg.hidden = true;
      youTab.appendChild(bdg);
    }
    refreshNotifBadge();
    setInterval(function () { if (document.visibilityState === 'visible') refreshNotifBadge(); }, 60000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') refreshNotifBadge(); });

    // The shared header may paint its avatar after we build — if the You tab fell
    // back to the person icon, poll briefly and swap in the real avatar.
    if (youTab && !youTab.querySelector('.lt-avi')) {
      var ptries = 0;
      var piv = setInterval(function () {
        var s = avatarSrc();
        if (s) {
          clearInterval(piv);
          var ico = youTab.querySelector('.lt-ico');
          if (ico) { ico.className = 'lt-ico lt-avi'; ico.innerHTML = '<img src="' + s + '" alt="">'; }
        } else if (++ptries > 20) { clearInterval(piv); }
      }, 150);
    }
  }

  // ---- Profile sheet ----------------------------------------------------
  var SHEET_ID = 'looth-sheet', SHEET_BD_ID = 'looth-sheet-bd';

  // Anon variant of the You sheet (Ian 2026-06-24): no profile / notifications /
  // settings — just the account entry. Sign in sits top-right (mirroring the authed
  // Log out slot); Join + Connect Patreon below. Hrefs are mirrored from the shared
  // header's anon cluster so they stay in sync with the canonical URLs.
  // (The destination menu that used to be mirrored into the sheet now lives solely
  // in the Nav tray — no duplicate menus.)

  // ---- Shared sheet dismissal (every .lt-sheet: You / anon / Nav tray / Notifs) --
  // One forgiving close model for ALL sheets. Tap-vs-drag claim model (Ian 2026-06-25:
  // the You sheet was still "sticky" because its body is wall-to-wall rows/chips —
  // the old handler bailed on any press that landed on an <a>/<button>, so a swipe
  // starting on content never began a dismiss; the short Nav tray felt fine only
  // because you grab its top). Now we START TRACKING on every press (links included)
  // but only CLAIM the gesture as a dismiss once the finger moves DOWN past a small
  // slop AND it's eligible — eligible = from the grab/header/section-head, or the
  // inner scroll is at the very top (scrollTop<=0). An upward move, or a downward
  // move while scrolled, is released back to native scrolling. A plain tap is never
  // claimed, so the row/link/chip under it still fires; a tap on the grab closes.
  function enableSheetDrag(sheet, closeFn) {
    var SLOP = 6, THRESH = 48, FLICK = 0.35;
    var startY = 0, cur = 0, tracking = false, claimed = false, onGrab = false, fromHandle = false,
        startScroll = 0, lastY = 0, lastT = 0, vy = 0;
    function ptY(e) { return (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY; }
    function down(e) {
      var t = e.target;
      onGrab = !!(t.closest && t.closest('.lt-sheet__grab'));
      fromHandle = onGrab || !!(t.closest && t.closest('.lt-sheet__head, .lt-sheet__sech'));
      startY = lastY = ptY(e); startScroll = sheet.scrollTop || 0; lastT = e.timeStamp || 0;
      cur = 0; vy = 0; tracking = true; claimed = false;
      // NB: don't kill the transition or transform yet — only once a drag is claimed,
      // so taps and inner scrolls are left completely untouched.
    }
    function move(e) {
      if (!tracking) return;
      var y = ptY(e), now = e.timeStamp || 0, dy = y - startY;
      if (now > lastT) vy = (y - lastY) / (now - lastT);          // px/ms, +ve = downward
      lastY = y; lastT = now;
      if (!claimed) {
        if (Math.abs(dy) < SLOP) return;                          // not enough movement to decide
        // Claim a downward dismiss only when eligible; otherwise release to native
        // scroll (so the You sheet's content still scrolls normally).
        var eligible = fromHandle || (sheet.scrollTop <= 0 && startScroll <= 0);
        if (dy > 0 && eligible) { claimed = true; sheet.style.transition = 'none'; }
        else { tracking = false; return; }
      }
      cur = Math.max(0, dy);
      sheet.style.transform = 'translateY(' + cur + 'px)';
      if (e.cancelable) e.preventDefault();                       // own the gesture (also swallows the trailing tap on touch)
    }
    function up() {
      if (!tracking) return;
      tracking = false;
      if (claimed) {
        claimed = false;
        sheet.style.transition = '';
        if (cur > THRESH || vy > FLICK) {                         // a modest pull OR a quick flick
          sheet.style.transform = 'translateY(100%)';
          closeFn();
          setTimeout(function () { sheet.style.transform = ''; }, 340);
        } else {
          sheet.style.transform = '';                            // snap back open
        }
      } else if (onGrab) {
        closeFn();                                                // a plain tap on the grab handle closes
      }
    }
    sheet.addEventListener('mousedown', down);
    sheet.addEventListener('touchstart', down, { passive: true });
    window.addEventListener('mousemove', move);
    window.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('mouseup', up);
    window.addEventListener('touchend', up);
  }

  function buildAnonSheet() {
    var bd = document.createElement('div');
    bd.id = SHEET_BD_ID; bd.className = 'lt-sheet-bd';
    bd.addEventListener('click', closeSheet);

    var sheet = document.createElement('div');
    sheet.id = SHEET_ID; sheet.className = 'lt-sheet';
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'Account');

    var grab = document.createElement('div'); grab.className = 'lt-sheet__grab';
    sheet.appendChild(grab);

    function hdrHref(sel, fallback) {
      var el = document.querySelector(sel);
      return (el && el.getAttribute('href')) || fallback;
    }
    var signinHref  = hdrHref('.lg-chrome__signin, .lg-chrome__menu-signin a', '/wp-login.php');
    var joinHref    = hdrHref('.lg-chrome__join', 'https://www.patreon.com/c/theloothgroup/membership');
    var connectHref = hdrHref('.lg-chrome__connect', '/connect-your-patreon/');

    var head = document.createElement('div'); head.className = 'lt-sheet__head';
    head.innerHTML =
      '<span class="lt-sheet__avi"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#6b7c52" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.5" r="3.8"/><path d="M5 20c0-3.6 3-6 7-6s7 2.4 7 6"/></svg></span>' +
      '<span class="lt-sheet__id"><span class="lt-sheet__name">Welcome</span>' +
      '<span class="lt-sheet__view">Sign in to join the conversation</span></span>' +
      '<a class="lt-sheet__login" href="' + String(signinHref).replace(/"/g, '&quot;') + '">' +
        '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>' +
        '<span>Sign in</span></a>';
    sheet.appendChild(head);

    var joinRow = document.createElement('a');
    joinRow.className = 'lt-sheet__row';
    joinRow.href = joinHref; joinRow.target = '_blank'; joinRow.rel = 'noopener';
    joinRow.textContent = 'Join';
    sheet.appendChild(joinRow);

    var connRow = document.createElement('a');
    connRow.className = 'lt-sheet__row';
    connRow.href = connectHref;
    connRow.textContent = 'Connect Patreon';
    sheet.appendChild(connRow);

    enableSheetDrag(sheet, closeSheet);
    document.body.appendChild(bd);
    document.body.appendChild(sheet);
    return sheet;
  }

  function renderSettings(host) {
    if (window.LGSettings && typeof window.LGSettings.buildPanel === 'function') {
      window.LGSettings.buildPanel(host);
    } else {
      host.textContent = 'Settings unavailable.';
    }
  }

  function buildSheet() {
    var existing = document.getElementById(SHEET_ID);
    if (existing) return existing;
    // Anon users get the minimal account sheet — no profile / notifications /
    // settings (Ian 2026-06-24). Auth comes from the server-rendered header.
    if (!isAuthed()) return buildAnonSheet();

    var bd = document.createElement('div');
    bd.id = SHEET_BD_ID; bd.className = 'lt-sheet-bd';
    bd.addEventListener('click', closeSheet);

    var sheet = document.createElement('div');
    sheet.id = SHEET_ID; sheet.className = 'lt-sheet';
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'You');

    var grab = document.createElement('div'); grab.className = 'lt-sheet__grab';
    sheet.appendChild(grab);

    // header: avatar + name + view profile
    var nameBtn = document.querySelector('.lg-chrome__account');
    var name = nameBtn ? (nameBtn.textContent || '').replace(/\s+/g, ' ').trim() : 'You';
    var src = avatarSrc();
    var head = document.createElement('div'); head.className = 'lt-sheet__head';
    // Log out lives in the header, far-right (Ian 2026-06-17) — opposite the avatar/
    // "View profile" so the two account actions are well separated (no misclick).
    // Starts on the plain action URL; upgraded below to a NONCED one-tap URL.
    var soLink     = document.querySelector('.lg-chrome__account-menu-signout, .lg-chrome__menu a[href*="action=logout"]');
    var logoutHref = (soLink && soLink.getAttribute('href')) || '/wp-login.php?action=logout';
    head.innerHTML =
      '<span class="lt-sheet__avi">' + (src ? '<img src="' + src + '" alt="">' : '') + '</span>' +
      '<span class="lt-sheet__id"><span class="lt-sheet__name"></span>' +
      '<a class="lt-sheet__view" href="/profile/edit">View profile</a></span>' +
      '<a class="lt-sheet__logout" href="' + logoutHref.replace(/"/g, '&quot;') + '">' +
        '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>' +
        '<span>Log out</span></a>';
    head.querySelector('.lt-sheet__name').textContent = name;
    sheet.appendChild(head);
    // Logout MUST carry a fresh WP nonce, or WordPress serves its "Do you really
    // want to log out?" confirm page and the session quietly survives — the mobile
    // "logout doesn't take" bug (Ian/keeper 2026-06-24). The header ships the plain
    // un-nonced URL, so we mint a nonced one-tap URL from auth.php (WP pool). We
    // pre-fetch it, but a fast tap can beat that fetch — so the click is ALSO
    // intercepted: if the href isn't nonced yet, fetch a fresh URL and only THEN
    // navigate. Once the real logout lands, WP redirects to a fresh (network-first)
    // page that renders the anon header, so the nav + You sheet rebuild as anon.
    var loEl = head.querySelector('.lt-sheet__logout');
    function freshLogoutUrl() {
      return fetch('/bb-mirror-api/v0/auth.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) { return (d && d.logout_url) || null; });
    }
    freshLogoutUrl().then(function (url) { if (url && loEl) loEl.href = url; }).catch(function () {});
    loEl.addEventListener('click', function (e) {
      var href = loEl.getAttribute('href') || '';
      if (/[?&]_wpnonce=/.test(href)) return;   // already nonced → real one-tap logout, let it through
      e.preventDefault();                        // not nonced yet — never hit the confirm page
      freshLogoutUrl()
        .then(function (url) { window.location.href = url || href; })
        .catch(function () { window.location.href = href; });
    });

    // Swipe / tap-the-grab to dismiss (shared model — see enableSheetDrag).
    enableSheetDrag(sheet, closeSheet);

    // Notifications now live in their OWN off-canvas sheet (Ian 2026-06-24) — the
    // You sheet just carries an entry row + unread badge; the list and the Clear
    // button live over there, not on this card.
    var notifRow = document.createElement('button');
    notifRow.type = 'button';
    notifRow.className = 'lt-sheet__row lt-sheet__row--notifs';
    notifRow.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;border:0;background:none;cursor:pointer';
    notifRow.innerHTML = '<svg class="lt-row-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg><span>Notifications</span>' +
      '<span class="lt-notif-rowbadge" hidden style="margin-left:auto;background:#e23b3b;color:#fff;border-radius:999px;font:700 11px/1 var(--lg-font-sans,system-ui,sans-serif);padding:3px 7px"></span>';
    notifRow.addEventListener('click', function () { closeSheet(); setTimeout(openNotifSheet, 120); });
    sheet.appendChild(notifRow);

    // Messages (Vanessa 2026-06-11): opens the Messenger-style chats pull-up
    // (messenger-sheet.js); unread count fed by social-counts on badge refresh.
    var msgRow = document.createElement('button');
    msgRow.type = 'button';
    msgRow.className = 'lt-sheet__row lt-sheet__row--msgs';
    msgRow.style.cssText = 'display:flex;align-items:center;gap:10px;width:100%;text-align:left;border:0;background:none;cursor:pointer';
    msgRow.innerHTML = '<svg class="lt-row-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.3 9 9 0 0 1-3.2-.6L4 21l1.9-4.4a8 8 0 0 1-1.4-4.6A8.4 8.4 0 0 1 13 3.7a8.4 8.4 0 0 1 8 7.8z"/></svg><span>Messages</span>' +
      '<span class="lt-msg-badge" hidden style="margin-left:auto;background:#e23b3b;color:#fff;border-radius:999px;font:700 11px/1 var(--lg-font-sans,system-ui,sans-serif);padding:3px 7px"></span>';
    msgRow.addEventListener('click', function () { closeSheet(); if (window.openMessenger) window.openMessenger(); });
    sheet.appendChild(msgRow);

    // Saved posts (Buck 2026-06-08): NOT a list here (too busy) — just a button
    // that warps to the Hub with the saved filter on (Instagram-style). The full
    // saved view lives in the Hub feed (?saved=1 → hub-polish saved mode).
    var savedRow = document.createElement('a');
    savedRow.className = 'lt-sheet__row lt-sheet__row--saved';
    savedRow.style.cssText = 'display:flex;align-items:center;gap:10px';
    savedRow.href = '/hub/?saved=1';
    savedRow.innerHTML = '<svg class="lt-row-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></svg><span>Saved posts</span>';
    sheet.appendChild(savedRow);

    // (Destination links removed from the You sheet — the Nav tray is the sole
    // mobile menu now; no duplicate menus. Ian 2026-06-24.)

    // settings
    var setH = document.createElement('div'); setH.className = 'lt-sheet__sech'; setH.textContent = 'Settings';
    sheet.appendChild(setH);
    var setBody = document.createElement('div'); setBody.className = 'lt-sheet__set';
    renderSettings(setBody);
    sheet.appendChild(setBody);

    document.body.appendChild(bd);
    document.body.appendChild(sheet);
    return sheet;
  }

  function openSheet() {
    var sheet = buildSheet();
    var bd = document.getElementById(SHEET_BD_ID);
    // refresh the settings panel so the active chips reflect current choices
    var setBody = sheet.querySelector('.lt-sheet__set');
    if (setBody) renderSettings(setBody);
    if (isAuthed()) refreshNotifBadge();   // sync the Notifications-row unread badge
    sheet.style.transform = '';   // clear any leftover drag transform so it opens cleanly
    void sheet.offsetHeight; // reflow so the transform transition runs
    if (bd) bd.classList.add('is-open');
    sheet.classList.add('is-open');
    document.body.classList.add('lt-sheet-open');
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onSheetKey);
  }

  function closeSheet() {
    var sheet = document.getElementById(SHEET_ID), bd = document.getElementById(SHEET_BD_ID);
    if (sheet) sheet.classList.remove('is-open');
    if (bd) bd.classList.remove('is-open');
    document.body.classList.remove('lt-sheet-open');
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onSheetKey);
    refreshNotifBadge();   // they may have read some
  }

  // ---- Nav tray ("Go to" destinations) --------------------------------------
  // The slide-up sheet behind the bar's Nav button. Reuses the .lt-sheet /
  // .lt-sheet-bd infra (same look & transition as the You sheet) with its own
  // element ids so the two open independently. Carries every destination plus a
  // Home door — the single fix for "no menu / no way back to the front page" on
  // the Hub, where the shared header's logo + hamburger are hidden (Ian 6/17).
  var NAV_ID = 'looth-navsheet', NAV_BD_ID = 'looth-navsheet-bd';
  function navIsHere(key, path) {
    return (key === 'home'    && (path === '/front-page/' || path === '/')) ||
           (key === 'hub'     && /^\/(hub|stream|archive)(\/|$)/.test(path)) ||
           (key === 'events'  && /^\/events(\/|$)/.test(path)) ||
           (key === 'members' && /^\/(directory|members)(\/|$)/.test(path)) ||
           (key === 'shop'    && /^\/shop(\/|$)/.test(path)) ||
           (key === 'sponsors'&& /^\/sponsors(\/|$)/.test(path));
  }
  function buildNavTray() {
    var existing = document.getElementById(NAV_ID);
    if (existing) return existing;
    var bd = document.createElement('div');
    bd.id = NAV_BD_ID; bd.className = 'lt-sheet-bd';
    bd.addEventListener('click', closeNav);

    var sheet = document.createElement('div');
    sheet.id = NAV_ID; sheet.className = 'lt-sheet';
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'Go to');

    var grab = document.createElement('div'); grab.className = 'lt-sheet__grab'; sheet.appendChild(grab);
    var h = document.createElement('div'); h.className = 'lt-sheet__sech'; h.textContent = 'Go to'; sheet.appendChild(h);

    var grid = document.createElement('div'); grid.className = 'lt-navgrid';
    var path = location.pathname || '/';
    DESTS.forEach(function (d) {
      var a = document.createElement('a');
      a.className = 'lt-navitem' + (d.home ? ' lt-home' : '');
      a.href = d.href;
      if (d.ext) { a.target = '_blank'; a.rel = 'noopener'; }
      var here = navIsHere(d.key, path);
      if (here) a.classList.add('is-here');
      a.innerHTML = '<span class="lt-nico"><svg viewBox="0 0 24 24" aria-hidden="true">' + d.icon + '</svg></span><span>' + d.label + '</span>';
      a.addEventListener('click', function () {
        closeNav();
        // Tray = destinations only; Messages/Alerts moved to the You sheet.
        if (!d.ext && !here) showTabSkeleton();   // perceived-speed bridge on real nav
      });
      grid.appendChild(a);
    });
    sheet.appendChild(grid);

    enableSheetDrag(sheet, closeNav);
    document.body.appendChild(bd);
    document.body.appendChild(sheet);
    return sheet;
  }
  function openNav() {
    var sheet = buildNavTray();
    var bd = document.getElementById(NAV_BD_ID);
    sheet.style.transform = ''; void sheet.offsetHeight;
    if (bd) bd.classList.add('is-open');
    sheet.classList.add('is-open');
    document.body.classList.add('lt-sheet-open');
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onNavKey);
  }
  function closeNav() {
    var sheet = document.getElementById(NAV_ID), bd = document.getElementById(NAV_BD_ID);
    if (sheet) sheet.classList.remove('is-open');
    if (bd) bd.classList.remove('is-open');
    document.body.classList.remove('lt-sheet-open');
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onNavKey);
  }
  function onNavKey(e) { if (e.key === 'Escape') closeNav(); }

  // ---- Notifications sheet (dedicated off-canvas) ---------------------------
  // Notifications moved out of the You sheet into their own slide-up (Ian
  // 2026-06-24): the Nav-tray "Alerts" item and the You-sheet Notifications row
  // both open it; Clear lives here, not on the You card. Reuses the .lt-sheet infra.
  var NOTIF_ID = 'looth-notifsheet', NOTIF_BD_ID = 'looth-notifsheet-bd';
  function buildNotifSheet() {
    var existing = document.getElementById(NOTIF_ID);
    if (existing) return existing;
    var bd = document.createElement('div');
    bd.id = NOTIF_BD_ID; bd.className = 'lt-sheet-bd';
    bd.addEventListener('click', closeNotifSheet);

    var sheet = document.createElement('div');
    sheet.id = NOTIF_ID; sheet.className = 'lt-sheet';
    sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true'); sheet.setAttribute('aria-label', 'Notifications');

    var grab = document.createElement('div'); grab.className = 'lt-sheet__grab'; sheet.appendChild(grab);

    var nH = document.createElement('div'); nH.className = 'lt-sheet__sech lt-notifs__h';
    nH.innerHTML = '<span>Notifications</span><button type="button" class="lt-notifs__clear" data-notif-clearall>Clear</button>';
    sheet.appendChild(nH);
    nH.querySelector('[data-notif-clearall]').addEventListener('click', function () { markAllNotifsRead(true); });

    var nBox = document.createElement('div'); nBox.className = 'lt-notifs'; nBox.id = 'lt-notifs';
    sheet.appendChild(nBox);

    enableSheetDrag(sheet, closeNotifSheet);
    document.body.appendChild(bd);
    document.body.appendChild(sheet);
    return sheet;
  }
  function openNotifSheet() {
    var sheet = buildNotifSheet();
    var bd = document.getElementById(NOTIF_BD_ID);
    loadSheetNotifs(sheet.querySelector('#lt-notifs'));   // populate / refresh on open
    sheet.style.transform = ''; void sheet.offsetHeight;
    if (bd) bd.classList.add('is-open');
    sheet.classList.add('is-open');
    document.body.classList.add('lt-sheet-open');
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onNotifKey);
  }
  function closeNotifSheet() {
    var sheet = document.getElementById(NOTIF_ID), bd = document.getElementById(NOTIF_BD_ID);
    if (sheet) sheet.classList.remove('is-open');
    if (bd) bd.classList.remove('is-open');
    document.body.classList.remove('lt-sheet-open');
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onNotifKey);
    refreshNotifBadge();   // they may have read some
  }
  function onNotifKey(e) { if (e.key === 'Escape') closeNotifSheet(); }

  // ---- Notifications (badge on the You tab + section in the sheet) -----------
  function ntEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function ntRel(iso) {
    try {
      var then = Date.parse((iso || '').replace(' ', 'T')), now = Date.now();
      if (!then) return '';
      var s = Math.max(0, (now - then) / 1000);
      if (s < 60) return 'just now';
      if (s < 3600) return Math.floor(s / 60) + 'm';
      if (s < 86400) return Math.floor(s / 3600) + 'h';
      if (s < 604800) return Math.floor(s / 86400) + 'd';
      return Math.floor(s / 604800) + 'w';
    } catch (e) { return ''; }
  }
  function ntText(n) {
    var who = (n.actor && n.actor.name) || 'Someone';
    switch (n.type) {
      case 'connection_accept': return ntEsc(who) + ' accepted your connection request';
      case 'connection_request': return ntEsc(who) + ' sent you a connection request';
      case 'message': return 'New message from ' + ntEsc(who);
      default: return ntEsc(who);
    }
  }
  // Open the real notifications surface (the shared social modal if present, else the page).
  function openNotifs() {
    // Defer to the shared header's notifications modal ONLY when its bell is
    // actually visible (it's display:none on the Hub + other mobile chrome). When
    // hidden, expand the FULL list right here in the notif sheet — never navigate
    // off to the desktop notifications page (Buck: stay in-app, no desktop pages).
    var bell = document.querySelector('.lg-chrome__icon-btn[aria-label="Notifications"]');
    if (bell && bell.offsetParent !== null) { closeNotifSheet(); setTimeout(function () { bell.click(); }, 70); return; }
    var box = document.getElementById('lt-notifs');
    if (box) loadSheetNotifs(box, true);
  }
  function notifRow(n) {
    var avi = (n.actor && n.actor.avatar_url) || '';
    return '<button type="button" class="lt-notif' + (n.is_read ? '' : ' is-unread') + '" data-notif>' +
      '<span class="lt-notif-avi">' + (avi ? '<img src="' + ntEsc(avi) + '" alt="">' : '') + '</span>' +
      '<span class="lt-notif-tx"><span class="lt-notif-t">' + ntText(n) + '</span>' +
      '<span class="lt-notif-time">' + ntRel(n.created_at) + '</span></span>' +
      (n.is_read ? '' : '<span class="lt-notif-dot"></span>') + '</button>';
  }
  function loadSheetNotifs(box, showAll) {
    if (!box) return;
    box.innerHTML = '<div class="lt-notif-empty">Loading…</div>';
    fetch('/profile-api/v0/me/notifications/', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        // Cleared = gone for good (Buck 2026-06-10; Vanessa 2026-06-11 re-report):
        // unread-only AND nothing older than the last explicit Clear. The backend
        // upsert REVIVES a repeat notification (is_read=false, created_at=now()),
        // so is_read alone isn't enough — the Clear watermark keeps resurrected
        // old rows out while genuinely new activity (fresh created_at) still shows.
        var cw = 0;
        try { cw = parseInt(localStorage.getItem('lg_notif_cleared_at'), 10) || 0; } catch (eW) {}
        var all = ((d && d.items) || []).filter(function (n) {
          if (n.is_read) return false;
          if (cw) {
            var ts = Date.parse(String(n.created_at || '').replace(' ', 'T'));
            if (ts && ts <= cw) return false;
          }
          return true;
        });
        var items = showAll ? all : all.slice(0, 8);
        if (!items.length) { box.innerHTML = '<div class="lt-notif-empty">No notifications yet.</div>'; return; }
        box.innerHTML = items.map(notifRow).join('') +
          ((!showAll && all.length > 8) ? '<button type="button" class="lt-notif-all" data-notif-all>See all notifications</button>' : '');
        // "See all" expands the full list IN the sheet (no desktop nav); a tap on a
        // notification row goes through openNotifs (header bell, else expand here).
        var allBtn = box.querySelector('[data-notif-all]');
        if (allBtn) allBtn.addEventListener('click', function () { loadSheetNotifs(box, true); });
        [].forEach.call(box.querySelectorAll('[data-notif]'), function (el) { el.addEventListener('click', openNotifs); });
        // Seeing them clears the indicator (Buck 2026-06-08): mark read + zero the
        // badge once the list is shown. Fire when the badge shows ANY unread
        // (social-counts), not just when one of the visible top-8 is unread —
        // otherwise unread items older than the 8 shown never get marked and the
        // badge "comes back" after an app reset. markAllRead is idempotent.
        var lgBdg = document.querySelector('#' + BAR_ID + ' .lt-badge');
        if ((lgBdg && !lgBdg.hidden) || items.some(function (n) { return !n.is_read; }))
          setTimeout(function () { markAllNotifsRead(false); }, 700);
      })
      .catch(function () { box.innerHTML = '<div class="lt-notif-empty">Couldn’t load notifications.</div>'; });
  }
  // Mark all notifications read → clears the You-tab badge. clearList=true also
  // empties the visible list (the "Clear" button); false just dims them (auto on view).
  function markAllNotifsRead(clearList) {
    var bdg = document.querySelector('#' + BAR_ID + ' .lt-badge'); if (bdg) bdg.hidden = true;   // optimistic
    // explicit Clear sets the permanent watermark (see loadSheetNotifs filter)
    if (clearList) { try { localStorage.setItem('lg_notif_cleared_at', String(Date.now())); } catch (eC) {} }
    var box = document.getElementById('lt-notifs');
    if (box) {
      if (clearList) box.innerHTML = '<div class="lt-notif-empty">No notifications yet.</div>';
      else [].forEach.call(box.querySelectorAll('.lt-notif'), function (r) { r.classList.remove('is-unread'); var d = r.querySelector('.lt-notif-dot'); if (d) d.remove(); });
    }
    fetch('/profile-api/v0/me/notifications/', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'read_all' })
    }).then(function () { refreshNotifBadge(); }).catch(function () {});
  }
  function loadSavedPosts(box) {
    if (!box) return;
    var list = [];
    try { list = JSON.parse(localStorage.getItem('lg_saved_posts') || '[]'); } catch (e) {}
    if (!list.length) { box.innerHTML = '<div class="lt-notif-empty">No saved posts yet. Tap the bookmark on a post to save it.</div>'; return; }
    box.innerHTML = list.slice(0, 15).map(function (p) {
      return '<a class="lt-saved-row" href="' + ntEsc(p.url) + '">' +
        '<span class="lt-saved-thumb"' + (p.cover ? (' style="background-image:url(' + ntEsc(p.cover) + ')"') : '') + '></span>' +
        '<span class="lt-saved-t">' + ntEsc(p.title || 'Post') + '</span></a>';
    }).join('');
  }
  function capN(n) { return n > 9 ? '9+' : String(n); }
  function refreshNotifBadge() {
    fetch('/profile-api/v0/me/social-counts/', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var n = (d.notifications_unread || 0);
        // Messages row badge (You sheet) — unread DM count
        var mb = document.querySelector('.lt-msg-badge');
        if (mb) {
          var mu = (d.messages_unread || 0);
          if (mu > 0) { mb.textContent = capN(mu); mb.hidden = false; } else { mb.hidden = true; }
        }
        // You-sheet Notifications-row badge (mirrors the unread count)
        var nrb = document.querySelector('.lt-notif-rowbadge');
        if (nrb) { if (n > 0) { nrb.textContent = capN(n); nrb.hidden = false; } else { nrb.hidden = true; } }
        var bdg = document.querySelector('#' + BAR_ID + ' .lt-badge');
        if (!bdg) return;
        if (n > 0) { bdg.textContent = capN(n); bdg.hidden = false; } else { bdg.hidden = true; }
      })
      .catch(function () {});
  }

  function onSheetKey(e) { if (e.key === 'Escape') closeSheet(); }

  // ---- Desktop settings entry point (Buck 2026-06-09) ------------------------
  // Mobile reaches settings via the bottom-bar "You" sheet; desktop had NO door at
  // all, so the app theme / text-size could only be changed on a phone. Add a gear
  // button into the shared header's account cluster (.lg-chrome__aside) that opens a
  // popover hosting the SAME LGSettings.buildPanel the mobile sheet uses — theme,
  // text size, font, comment bubble, Hub feed — so desktop matches mobile (Buck:
  // "same settings in the profile bubble as mobile"). The popover surface follows
  // the same --lguser-* / --bg-card chain as the feed, so it's dark on an OS-dark
  // default and light on a picked light theme. Desktop-only (the mobile header
  // bubble is hidden; the bottom bar owns settings there). Idempotent.
  var DSET_POP_ID = 'lg-dset-pop';
  var DSET_STYLE_ID = 'lg-dset-style';
  function injectDsetStyles() {
    if (document.getElementById(DSET_STYLE_ID)) return;
    var P = '#' + DSET_POP_ID;
    var css =
      '@media (min-width:641px){' +
      '.lg-set-gear{display:inline-flex;align-items:center;justify-content:center;cursor:pointer}' +
      '.lg-set-gear svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}' +
      P + '{position:fixed;top:62px;right:14px;z-index:2147481500;width:330px;max-width:92vw;max-height:80vh;' +
        'overflow:auto;background:var(--lguser-card,var(--bg-card,#fff));color:var(--lguser-ink,var(--fg,#1a1d1a));' +
        'border:1px solid var(--lguser-line,var(--border,#e3ddd0));border-radius:16px;' +
        'box-shadow:0 18px 50px -12px rgba(0,0,0,.45);padding:4px 12px 14px;' +
        'opacity:0;transform:translateY(-8px) scale(.98);transform-origin:top right;pointer-events:none;' +
        'transition:opacity .16s ease,transform .16s ease}' +
      P + '.is-open{opacity:1;transform:none;pointer-events:auto}' +
      P + ' .lg-dset__hd{font:700 16px/1.2 var(--lg-font-serif,Georgia,serif);padding:14px 6px 4px;color:var(--lguser-ink,var(--fg,#1a1d1a))}' +
      P + ' .lg-dset__sub{font:400 12.5px/1.45 var(--lg-font-sans,system-ui,sans-serif);color:var(--lguser-mute,var(--fg-muted,#6b6f6b));' +
        'padding:0 6px 9px;border-bottom:1px solid var(--lguser-line,var(--border,#e3ddd0));margin-bottom:2px}' +
      // chips read on a dark popover surface too (follow the theme pill/ink)
      P + ' .lg-set-opt{background:var(--lguser-pill,#fff);color:var(--lguser-ink,var(--fg,#1a1d1a));border-color:var(--lguser-line,var(--border,#e3ddd0))}' +
      // selected chip must stay DISTINCT from the unselected --lguser-pill fill
      // (audit 2026-06-09: the popover override flattened is-on to the same pill).
      // id-scoped so it out-specifies the generic .lg-set-opt above.
      P + ' .lg-set-opt.is-on{background:var(--lguser-card,#fff)!important;border-color:var(--lguser-accent,var(--lg-sage-d,#6b7c52))!important;' +
        'box-shadow:0 0 0 1.5px var(--lguser-accent,var(--lg-sage-d,#6b7c52))!important;color:var(--lguser-ink,#1a1d1a)!important}' +
      P + ' .lg-set-sec__h{color:var(--lguser-mute,var(--fg-muted,#6b6f6b))}' +
      // visible keyboard-focus ring on the gear + chips (a11y; elderly-friendly).
      // :focus-visible isn't suppressed by any reset here.
      '.lg-set-gear:focus-visible{outline:2px solid var(--lguser-accent,var(--lg-sage-d,#6b7c52));outline-offset:2px;border-radius:8px}' +
      P + ' .lg-set-opt:focus-visible{outline:2px solid var(--lguser-accent,var(--lg-sage-d,#6b7c52));outline-offset:2px}' +
      '}' +
      '@media (max-width:640px){.lg-set-gear{display:none!important}' + P + '{display:none!important}}';
    var s = document.createElement('style'); s.id = DSET_STYLE_ID; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function buildDesktopSettings() {
    if (document.querySelector('.lg-set-gear')) return true;   // already added
    var aside = document.querySelector('.lg-chrome__aside');
    if (!aside) return false;                                  // header not painted yet
    injectDsetStyles();

    var gear = document.createElement('button');
    gear.type = 'button';
    gear.className = 'lg-chrome__icon-btn lg-set-gear';
    gear.setAttribute('aria-label', 'Display settings');
    gear.setAttribute('aria-haspopup', 'dialog');
    gear.setAttribute('aria-expanded', 'false');
    gear.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.1"/>' +
      '<path d="M19.4 13a7.6 7.6 0 0 0 0-2l1.9-1.5-1.9-3.2-2.2.9a7.5 7.5 0 0 0-1.8-1L15 3.5h-3.8l-.4 2.7a7.5 7.5 0 0 0-1.8 1l-2.2-.9-1.9 3.2L6 11a7.6 7.6 0 0 0 0 2l-1.9 1.5 1.9 3.2 2.2-.9a7.5 7.5 0 0 0 1.8 1l.4 2.7H15l.4-2.7a7.5 7.5 0 0 0 1.8-1l2.2.9 1.9-3.2z"/></svg>';
    var acctWrap = aside.querySelector('.lg-chrome__account-wrap') || aside.lastElementChild;
    if (acctWrap) aside.insertBefore(gear, acctWrap); else aside.appendChild(gear);

    var pop = document.createElement('div');
    pop.id = DSET_POP_ID;
    pop.setAttribute('role', 'dialog');
    pop.setAttribute('aria-label', 'Display settings');
    pop.innerHTML = '<div class="lg-dset__hd">Appearance</div>' +
      '<div class="lg-dset__sub">Theme, text size &amp; more. Applies across Looth on this device.</div>' +
      '<div class="lg-dset__body"></div>';
    document.body.appendChild(pop);
    var body = pop.querySelector('.lg-dset__body');

    function onDoc(e) { if (pop.contains(e.target) || gear.contains(e.target)) return; closePop(); }
    function onKey(e) { if (e.key === 'Escape') closePop(); }
    function openPop() {
      renderSettings(body);                 // refresh so chips reflect current choices
      pop.classList.add('is-open');
      gear.setAttribute('aria-expanded', 'true');
      setTimeout(function () { document.addEventListener('click', onDoc, true); }, 0);
      document.addEventListener('keydown', onKey);
    }
    function closePop() {
      pop.classList.remove('is-open');
      gear.setAttribute('aria-expanded', 'false');
      document.removeEventListener('click', onDoc, true);
      document.removeEventListener('keydown', onKey);
    }
    gear.addEventListener('click', function (e) {
      e.stopPropagation();
      pop.classList.contains('is-open') ? closePop() : openPop();
    });
    return true;
  }

  // The shared header can paint after we run; retry briefly until the aside exists.
  function startDesktopSettings() {
    if (buildDesktopSettings()) return;
    var tries = 0;
    var iv = setInterval(function () {
      if (buildDesktopSettings() || ++tries > 40) clearInterval(iv);
    }, 150);
  }

  function start() {
    if (document.body) { build(); startDesktopSettings(); }
    else document.addEventListener('DOMContentLoaded', function () { build(); startDesktopSettings(); });
  }
  start();
})();
