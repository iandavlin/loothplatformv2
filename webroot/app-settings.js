/* Looth app settings — user-pickable color theme, webfont, and text size.
   Site-wide, persisted per-device in localStorage. No backend.

   How it applies (the trick): the brand design tokens on :root (--lg-cream,
   --lg-sage*, --lg-line, --lg-font-sans, ...) are what the whole site already
   references, so a THEME just OVERRIDES those tokens and the app recolors; a
   FONT overrides --lg-font-sans (and loads the webfont); SIZE scales the rem
   base. Themes also set --lguser-* vars that hub-polish.js reads for the Hub
   feed (whose colors are otherwise hardcoded), so the Hub follows the theme too.

   DEFAULTS preserve the current look exactly: theme 'default' / font 'default'
   apply NO override (app stays brand-cream, Hub stays its Sage-Tint look), so
   existing users see zero change until they actively pick something.

   Exposes window.LGSettings { THEMES, FONTS, SIZES, get, set, apply,
   getTheme/getFont/getSize, buildPanel(container) } for the settings UI
   (rendered by bottom-nav.js inside the profile sheet).
   Loaded EARLY site-wide via /pwa.js (before hub-polish.js). */
(function () {
  'use strict';
  if (window.LGSettings) return;

  var KEY = { theme: 'lg-set-theme', font: 'lg-set-font', size: 'lg-set-size', bubble: 'lg-set-bubble', feed: 'lg-set-feed', custom: 'lg-set-custom', playone: 'lg-set-playone' };

  // ---- Color modes — pared to TWO (Ian 2026-06-10, bespoke-cutover):
  // LIGHT = the brand default with NO overrides (vars:null), so every page,
  // header and app renders its native palette — nothing can mismatch.
  // DARK = one sage-tinted dark set, tuned for legibility (no pure black,
  // desaturated off-white ink ~12:1 on cards, mutes lifted to ~6:1).
  // Legacy picks (cream/sage/amber/rust/custom) map to Light in getTheme().
  var THEMES = [
    { id: 'default', name: 'Light', dot: '#fbfbf8', dark: false, vars: null },
    { id: 'dark', name: 'Dark', dot: '#1e2320', dark: true, vars: {
        '--lg-cream': '#15171a', '--lg-sage': '#9cb37d', '--lg-sage-d': '#b0c693',
        '--lg-sage-tint': '#243024', '--lg-sage-3': '#2f3d2c', '--lg-line': '#2c312d',
        '--lg-charcoal': '#f2f4ee', '--lg-ink': '#e5e7e1', '--lg-mute': '#a6ac9f',
        '--lg-amber': '#ecb351', '--lg-rust': '#d57a55', '--lg-card-bg': '#1e2124',
        '--lguser-bg': '#15171a', '--lguser-card': '#1e2124', '--lguser-accent': '#9cb37d',
        '--lguser-accent-d': '#b0c693', '--lguser-pill': '#243024', '--lguser-line': '#2c312d',
        '--lguser-ink': '#e5e7e1', '--lguser-mute': '#a6ac9f', '--lguser-bubble': '#262b30' } }
  ];
  // Union of every theme var key, so apply() can cleanly revert before re-setting.
  var THEME_KEYS = (function () {
    var seen = {}, out = [];
    THEMES.forEach(function (t) { if (t.vars) for (var k in t.vars) if (t.vars.hasOwnProperty(k) && !seen[k]) { seen[k] = 1; out.push(k); } });
    return out;
  })();

  // ---- Webfonts (cross-platform — always load, never rely on a system face) ----
  // 'default' = no override (Hub keeps Trebuchet/Cabin, app keeps brand sans).
  var FONTS = [
    { id: 'default', name: 'Default', stack: null, google: null },
    { id: 'cabin', name: 'Cabin', stack: '"Cabin", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', google: 'Cabin:wght@400;500;600;700' },
    { id: 'nunito', name: 'Nunito', stack: '"Nunito Sans", system-ui, sans-serif', google: 'Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700' },
    { id: 'inter', name: 'Inter', stack: '"Inter", system-ui, sans-serif', google: 'Inter:wght@400;500;600;700' },
    { id: 'source-serif', name: 'Source Serif', stack: '"Source Serif 4", Georgia, serif', google: 'Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700' }
  ];

  // ---- Text size (scales the rem base, the standard a11y approach) ----
  var SIZES = [
    // Vanessa 2026-06-11: the top option needs to be genuinely large on a phone —
    // Larger raised 1.25 -> 1.45, Large rescaled to keep even steps.
    { id: 's', name: 'Small', scale: 0.92 },
    { id: 'm', name: 'Default', scale: 1 },
    { id: 'l', name: 'Large', scale: 1.2 },
    { id: 'xl', name: 'Larger', scale: 1.45 }
  ];

  // ---- Comment bubble color (the Facebook-style comment bubbles) ----
  // 'default' = the standard grey (#eceff3). Others set --lguser-bubble.
  var BUBBLES = [
    { id: 'default', name: 'Grey', color: null, dot: '#eceff3' },
    { id: 'white', name: 'White', color: '#ffffff', dot: '#ffffff' },
    { id: 'sage', name: 'Sage', color: '#eef2e3', dot: '#eef2e3' },
    { id: 'cream', name: 'Cream', color: '#f5f1e6', dot: '#f5f1e6' },
    { id: 'sky', name: 'Sky', color: '#e7f0fb', dot: '#e7f0fb' },
    { id: 'amber', name: 'Amber', color: '#f6ecd6', dot: '#f6ecd6' },
    { id: 'rose', name: 'Rose', color: '#f7e8e6', dot: '#f7e8e6' }
  ];

  // ---- Hub feed layout (Buck 2026-06-08) ----
  // 'immersive' = the DEFAULT (Buck 2026-06-08): an Instagram-style edge-to-edge
  // feed where photos go full-bleed and the card chrome (border/radius/side
  // gutter) drops away. 'cards' = the older bordered cards. Applied as data-lguser-feed on
  // <html>; the actual CSS lives in hub-polish.js (Hub-scoped), this just sets the
  // switch site-wide so it's in place before the Hub paints.
  var FEEDVIEWS = [
    { id: 'cards', name: 'Cards' },
    { id: 'immersive', name: 'Full screen' }
  ];

  // ---- Video playback (Buck 2026-06-09): only ONE YouTube video plays at a time.
  // 'on' (default) = starting any video stops the others (desktop + mobile);
  // 'off' = allow several at once. Read by hub-polish.js enforceSingleVideo(). ----
  var PLAYONE = [
    { id: 'on', name: 'One at a time' },
    { id: 'off', name: 'Allow several' }
  ];

  // (custom color wheel removed in the 2026-06-10 two-mode pare-back)

  function byId(list, id) { for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return list[0]; }
  function rd(k) { try { return localStorage.getItem(KEY[k]); } catch (e) { return null; } }
  function wr(k, v) { try { localStorage.setItem(KEY[k], v); } catch (e) {} }

  function getTheme() {
    // Pare-back mapping (2026-06-10): anything that isn't 'dark' is Light.
    var saved = rd('theme');
    if (saved === 'dark') return 'dark';
    if (saved === 'default') return 'default';
    // No explicit pick -> follow the OS/system theme (Buck 2026-07-16, #64).
    try { if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark'; } catch (e) {}
    return 'default';
  }
  function getFont() { return rd('font') || 'default'; }
  function getSize() { return rd('size') || 'm'; }
  function getBubble() { return rd('bubble') || 'default'; }
  function getFeed() { return 'immersive'; }   // control removed 2026-06-10 (Ian): one canonical feed look; stored picks ignored
  function getPlayone() { return rd('playone') || 'on'; }    // Buck 2026-06-09: single-video default ON

  // Lazy-load a Google webfont once.
  function loadFont(font) {
    if (!font || !font.google) return;
    var id = 'lg-font-' + font.id;
    if (document.getElementById(id)) return;
    var head = document.head || document.documentElement;
    if (!document.getElementById('lg-font-pre')) {
      var p = document.createElement('link');
      p.id = 'lg-font-pre'; p.rel = 'preconnect'; p.href = 'https://fonts.gstatic.com'; p.crossOrigin = 'anonymous';
      head.appendChild(p);
    }
    var l = document.createElement('link');
    l.id = id; l.rel = 'stylesheet';
    l.href = 'https://fonts.googleapis.com/css2?family=' + font.google + '&display=swap';
    head.appendChild(l);
  }

  // Real dark-mode surfaces. Token overrides alone don't darken the Hub (its bg/
  // cards are hardcoded / use forums.css's own --bg, which isn't wired here), so we
  // force the key surfaces dark, gated on the chosen theme. Injected once; inert
  // unless data-lguser-theme="dark" (Buck 2026-06-08).
  var DARK_STYLE_ID = 'lg-dark-style';
  function ensureDarkStyle() {
    if (document.getElementById(DARK_STYLE_ID)) return;
    var D = 'html[data-lguser-theme="dark"]';
    var css = [
      D + '{background:#15171a;color-scheme:dark}',
      D + ' body{background:#15171a!important;color:#e5e7e1!important}',
      D + ' .feed-page,' + D + ' .feed,' + D + ' .bb-layout,' + D + ' .bb-layout__main,' + D + ' main{background:#15171a!important}',
      D + ' .feed-card{background:#1e2124!important;border-color:#2c312d!important;box-shadow:none!important}',
      D + ' .feed-card__title,' + D + ' .fc-title,' + D + ' .fc-title a,' + D + ' .feed-card__title a,' + D + ' .feed-card__op-author,' + D + ' .fc-author__name,' + D + ' .lg-fb-name,' + D + ' .reply-stub__author{color:#f2f4ee!important}',
      D + ' .feed-card__op-excerpt,' + D + ' .fc-excerpt,' + D + ' .feed-card__full-body,' + D + ' .reply-stub,' + D + ' .reply-stub__body,' + D + ' .reply-stub__excerpt,' + D + ' .feed-card__meta-top,' + D + ' .fc-time,' + D + ' .feed-card__time,' + D + ' .lg-fb-time,' + D + ' .fc-breadcrumb,' + D + ' .feed-card__ctx-forum{color:#cdd0ca!important}',
      D + ' .lg-fb-bubble{background:#262b30!important;color:#e5e7e1!important}',
      D + ' .feed-sort-bar,' + D + ' .feed-toolbar{background:#15171a!important;border-color:#2c312d!important}',
      D + ' .lg-sort-pill,' + D + ' .feed-sort-bar a,' + D + ' .feed-sort-bar button{color:#cdd0ca}',
      D + ' .lg-hub-search .ubar,' + D + ' .lg-hub-search input,' + D + ' .lgdm-ubar,' + D + ' .lgev-ubar{background:#1e2124!important;border-color:#2c312d!important;color:#e5e7e1!important}',
      D + ' .lg-hub-search input::placeholder,' + D + ' input::placeholder{color:#7e857c!important}',
      D + ' #looth-tabbar{background:rgba(21,23,26,.92)!important;border-color:#2c312d!important}',
      D + ' .lt-sheet,' + D + ' .lt-sheet__row,' + D + ' .lg-set-opt{color:#e5e7e1}',
      D + ' .lt-sheet,' + D + ' #looth-rep-sheet .lrs-card,' + D + ' #looth-lp-sheet .llp-card,' + D + ' #looth-ev-sheet .lev-card,' + D + ' #lgdm-sheet,' + D + ' .lgdm-fsheet,' + D + ' #lgdm-suggest,' + D + ' .bb-layout__nav{background:#1b1e21!important;color:#e5e7e1!important}',
      D + ' .lt-sheet__name,' + D + ' .lrs-t,' + D + ' .llp-t,' + D + ' .lev-t{color:#f2f4ee!important}',
      D + ' .lg-set-opt{background:#222629!important;border-color:#333833!important;color:#e5e7e1!important}',
      D + ' .lg-set-opt.is-on{background:#2a341f!important;border-color:#9cb37d!important}',
      D + ' input,' + D + ' textarea,' + D + ' select{background:#222629!important;color:#e5e7e1!important;border-color:#333833!important}',
      D + ' .feed-card__tags .tag-chip,' + D + ' .fc-tag,' + D + ' .hl,' + D + ' .fcr-chip{background:#243024!important;color:#b6c79a!important;border-color:transparent!important}',
      // ── dark-mode audit fixes (2026-06-08) ──
      // search bar: the WHITE slab is .lg-hub-search itself (mobile), not .ubar
      D + ' .lg-hub-search{background:#1e2124!important;border-color:#000!important}',   // Buck: black border ring in dark
      // the top header bar (.lg-chrome / #site-header) is white in dark — make it black (Buck 2026-06-08)
      D + ' #site-header,' + D + ' .lg-chrome,' + D + ' #site-header .lg-chrome__inner{background:#000!important;border-color:#15171a!important}',
      // ...but .lg-chrome locally redefines the brand tokens (--lg-ink:#323532 etc),
      // which SHADOW the dark theme — so the inactive nav links/icons stayed dark-on-
      // black and near-invisible. Re-point the header's own ink/mute/line dark so menu
      // links + icon buttons light up. NOTE: do NOT touch --lg-charcoal here — it's the
      // DARK background for the Edit btn / ADMIN badge (+ footer), with white text on top.
      D + ' .lg-chrome{--lg-ink:#e5e7e1!important;--lg-mute:#9aa097!important;--lg-line:#2c312d!important}',
      D + ' .lg-chrome__account{background:#1e2124!important;border-color:#2c312d!important}',
      // Edit btn + ADMIN badge use var(--lg-charcoal) as bg; the picked-Dark theme set
      // that token light → light-bg + white text = invisible. Pin them to a dark chip.
      D + ' .lg-chrome__edit,' + D + ' .lg-chrome__tier--admin{background:#2c312d!important;color:#f2f4ee!important}',
      D + ' .lg-hub-search input::placeholder{color:#9aa097!important}',
      D + ' .lg-hub-search .lg-hub-search__ico,' + D + ' .lg-hub-search svg{color:#9aa097!important;stroke:#9aa097!important}',
      D + ' .lg-hub-search__panel{background:#15171a!important;border-color:#2c312d!important}',
      D + ' .lg-hub-search__item{border-color:#2c312d!important}',
      D + ' .lg-hub-search__t{color:#e5e7e1!important}',
      // sort pills: Newest/Trending + the toggle icon buttons render WHITE → dark
      // dark-mode lane 2026-07-23 (credit Buck #64): the bg/active rules used the
      // DIRECT-CHILD combinator `.feed-sort-bar > a`, but the live `--zones` layout
      // nests anchors one level down (`.feed-sort-bar--zones > div.zL > a`), so
      // Buck's descendant COLOR rule (line ~155) lit the text #cdd0ca while these
      // bg rules missed → light text on a still-WHITE pill (1.41–1.56:1, audit
      // #19–22). Widen `> a` to descendant `a` (buttons on this line already are);
      // the more-specific .lg-newpost/.lg-filters-chip rules below still win.
      D + ' .feed-sort-bar a,' + D + ' .feed-sort-bar button{background:#22262a!important;color:#d0d4cd!important;border:1px solid #333833!important;font-weight:600!important;transition:background .15s,color .15s!important}',
      D + ' .feed-sort-bar a.active,' + D + ' .feed-sort-bar .is-active{background:#9cb37d!important;color:#15171a!important;border-color:#9cb37d!important;font-weight:700!important;box-shadow:0 1px 6px rgba(156,179,125,.35)!important}',
      D + ' .feed-sort-bar .lg-filters-chip{background:#9cb37d!important;color:#15171a!important}',   // dark text on sage
      D + ' .feed-sort-bar .lg-newpost{background:#243024!important;color:#e5e7e1!important}',
      // card borders compute near-WHITE on edges → unify dark. The shorthand
      // border-color rule lost to forums.css per-edge longhands, so set each
      // edge longhand at higher specificity (.feed-page .feed-card).
      D + ' .feed-page .feed-card,' + D + ' .feed .feed-card,' + D + ' .feed-card{border-color:#2c312d!important;border-top-color:#2c312d!important;border-right-color:#2c312d!important;border-bottom-color:#2c312d!important;border-left-color:#2c312d!important}',
      D + ' .feed-page .fc-actions,' + D + ' .feed-card .fc-actions,' + D + ' .fc-actions{border-color:#2c312d!important;border-top-color:#2c312d!important}',
      // replies sheet: inactive "Oldest" tab was dark-on-dark + cream border
      D + ' .replies-sort__btn{color:#cdd0ca!important;border-color:#2c312d!important}',
      D + ' .replies-sort__btn.is-active{color:#15171a!important;background:#b0c693!important}',
      // inline comment-row Like/Reply was ~#65676b (2.5:1) → readable
      D + ' .lg-fb-act{color:#9aa097!important}',
      D + ' .lg-fb-time{color:#80867d!important}',
      // members directory (/directory/members/): the .dir-card is hardcoded white
      // → member NAMES (light token) were invisible in dark. Darken the card.
      D + ' .dir-card{background:#1e2124!important;border-color:#2c312d!important}',
      D + ' .dir-card__name,' + D + ' .dir-card a:not(.dir-link),' + D + ' .dir-name{color:#f2f4ee!important}',
      D + ' .dir-card__loc,' + D + ' .dir-loc,' + D + ' .dir-card__meta{color:#cdd0ca!important}',
      D + ' .dir-members__count,' + D + ' .dir-results__count{color:#cdd0ca!important}',
      // ── desktop-Hub dark-theme audit (2026-06-09) ──
      // inline-reply / edit SEND buttons were inverted (white-on-light-sage ~1.9:1)
      // in picked Dark — match the other dark action chips.
      D + ' .feed-card__inline-compose .fic-send,' + D + ' .reply-stub__editbox .rse-save,' + D + ' .lg-fb-send{background:#2a341f!important;color:#e5e7e1!important;border-color:#3d5233!important}',
      // ── Leaflet maps → night mode (Buck 2026-06-10). Invert-filter the TILES
      // only (markers/pins live in their own pane and keep their real colors);
      // darken the map chrome (zoom buttons, attribution) to match the app.
      D + ' .leaflet-container{background:#15171a!important}',
      D + ' .leaflet-tile{filter:invert(1) hue-rotate(180deg) brightness(.92) contrast(.88) saturate(.65)}',
      D + ' .leaflet-control-zoom a{background:#262b30!important;color:#e5e7e1!important;border-color:#2c312d!important}',
      D + ' .leaflet-control-attribution{background:rgba(21,23,26,.8)!important;color:#80867d!important}',
      D + ' .leaflet-control-attribution a{color:#9cb37d!important}',
      // reply-EDIT Quill toolbar stayed #fff (forums only patches hub-theme-dark, not
      // the app data-lguser-theme="dark"); darken the toolbar AND its icon strokes/fills
      // so the icons don't go invisible on the now-dark bar.
      D + ' .reply-stub__editbox .ql-toolbar.ql-snow,' + D + ' .reply-stub__editbox .ql-container.ql-snow,' + D + ' .ntm-form .ql-toolbar.ql-snow,' + D + ' .ntm-form .ql-container.ql-snow{background:#1e2124!important;border-color:#2c312d!important}',
      D + ' .reply-stub__editbox .ql-toolbar.ql-snow .ql-stroke,' + D + ' .ntm-form .ql-toolbar.ql-snow .ql-stroke{stroke:#cdd0ca!important}',
      D + ' .reply-stub__editbox .ql-toolbar.ql-snow .ql-fill,' + D + ' .ntm-form .ql-toolbar.ql-snow .ql-fill{fill:#cdd0ca!important}',
      // lg-layout-v2 ARTICLE pages (<main class="lg-standalone-main">, the
      // standalone renderer): articles are DESIGNED as light "paper" — dark
      // mode flipped the ink tokens site-wide, leaving light text on light
      // paper (invisible body copy, Ian 2026-06-10). Insulate the article
      // canvas: it stays light with its own light tokens while the chrome
      // around it goes dark. Higher specificity than the boot-crit main{} rule.
      D + ' main.lg-standalone-main{background:#f4f2ec!important;color-scheme:light;' +
        '--lg-ink:#262925!important;--lg-mute:#565a55!important;--lg-charcoal:#1a1d1a!important;' +
        '--lg-line:#e4e7d8!important;--lg-cream:#fbfbf8!important;--lg-card-bg:#ffffff!important;' +
        '--lg-sage-d:#586b3f!important;--lg-sage-tint:#eef2e3!important}',
      // Shared FOOTER (.lg-chrome-foot): fixed light slab — was never themed in
      // dark ("footer needs some dark mode love", Ian 2026-06-10). Re-point its
      // pinned shell tokens + darken the slab so link/text colors follow.
      D + ' .lg-chrome-foot{background:#101214!important;border-color:#2c312d!important;color:#cdd0ca!important;' +
        '--lg-ink:#e5e7e1!important;--lg-mute:#a6ac9f!important;--lg-line:#2c312d!important}',
      D + ' .lg-chrome-foot a{color:#cdd0ca!important}',
      D + ' .lg-chrome-foot a:hover{color:#e5e7e1!important}',
      // ── Messenger / Connections / Notifications drawer (lg-shell social modal,
      // Buck 2026-06-11): the panel is hardcoded #fff in site-header.css while its
      // text rides --lg-ink — dark mode flipped the ink light but left the slab
      // white, washing the names out (only the sage-tint pending rows went dark).
      // Full dark pass, same palette as the rest of this block. All widths.
      D + ' .lg-social-modal__panel{background:#1a1d20!important}',
      D + ' .lg-social-modal__head{border-color:#2c312d!important}',
      D + ' .lg-social-modal__title{color:#e5e7e1!important}',
      D + ' .lg-social-modal__close,' + D + ' .lg-social-modal__back-btn{color:#9aa097!important}',
      D + ' .lg-social-modal__close:hover,' + D + ' .lg-social-modal__back-btn:hover{background:#243024!important;color:#e5e7e1!important}',
      D + ' .lg-social-tab{color:#9aa097!important}',
      D + ' .lg-social-tab:hover{color:#e5e7e1!important}',
      D + ' .lg-social-tab.is-active{color:#b0c693!important;background:#243024!important}',
      D + ' .lg-conn__item,' + D + ' .lg-msg__thread,' + D + ' .lg-notif__item{border-bottom-color:#2c312d!important}',
      D + ' .lg-conn__item--pending{background:#243024!important}',
      D + ' .lg-conn__name,' + D + ' .lg-msg__name,' + D + ' .lg-notif__text,' + D + ' .lg-msg__msg-text{color:#e5e7e1!important}',
      D + ' .lg-conn__section-h,' + D + ' .lg-msg__preview,' + D + ' .lg-msg__meta,' + D + ' .lg-msg__msg-time,' + D + ' .lg-notif__time,' + D + ' .lg-sm__status,' + D + ' .lg-sm__empty{color:#9aa097!important}',
      D + ' .lg-conn__search,' + D + ' .lg-msg__reply-input{background:#22262a!important;border-color:#333833!important;color:#e7ebe1!important}',
      D + ' .lg-msg__thread--unread,' + D + ' .lg-msg__msg,' + D + ' .lg-notif__item--unread{background:#22262a!important}',
      D + ' .lg-msg__msg--mine{background:#2a341f!important}',
      // (OS-dark default-theme header block REMOVED 2026-06-10 pare-back: it
      // blackened ONLY the header when OS was dark with no picked theme — the
      // page stayed light = the "some headers and not others" mismatch. Two
      // explicit modes now; Dark is a deliberate pick, Light has no overrides.)
      ''
    ].join('\n');
    var s = document.createElement('style'); s.id = DARK_STYLE_ID; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  var APPLY_STYLE_ID = 'lg-settings-apply';
  function ensureApplyStyle() {
    if (document.getElementById(APPLY_STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = APPLY_STYLE_ID;
    s.textContent =
      'body{font-family:var(--lg-font-sans)}' +
      'html[data-lguser-dark="1"]{color-scheme:dark}';
    (document.head || document.documentElement).appendChild(s);
  }

  function apply() {
    var themeId = getTheme();
    var f = byId(FONTS, getFont());
    var z = byId(SIZES, getSize());
    var root = document.documentElement;

    // Theme: revert all theme keys, then set the chosen mode's overrides
    // (Light = none; Dark = the one override set).
    THEME_KEYS.forEach(function (k) { root.style.removeProperty(k); });
    var t = byId(THEMES, themeId);
    // Mode applies at ALL widths (Ian 2026-06-10 v2: mobile users pick their
    // own mode; the pick is per-device/localStorage, never synced).
    if (t.vars) for (var k in t.vars) if (t.vars.hasOwnProperty(k)) root.style.setProperty(k, t.vars[k]);
    root.setAttribute('data-lguser-theme', t.id);
    root.setAttribute('data-lguser-dark', t.dark ? '1' : '0');

    // Mid-session mode flip (Ian 2026-06-10: "Light picked but page stays dark"):
    // the nginx boot injects #lg-boot-crit pre-paint from the LAST persisted blob —
    // !important dark backgrounds on body/headers — and nothing removed it when
    // the user switched to Light in-page. Drop it the moment dark is off.
    try { var bc = document.getElementById('lg-boot-crit'); if (bc && !t.dark) bc.remove(); } catch (e) {}

    // Font: override the brand sans token + load the webfont (or revert).
    if (f.stack) {
      loadFont(f);
      root.style.setProperty('--lg-font-sans', f.stack);
      root.style.setProperty('--lguser-font', f.stack);
    } else {
      root.style.removeProperty('--lg-font-sans');
      root.style.removeProperty('--lguser-font');
    }

    // Size: scale the rem base AND both feed scale vars. The Hub's prominent text
    // (card title 18-20px, post excerpt 15px) is fixed-px * var(--lg-read-scale), and the
    // secondary text is * var(--lguser-scale) — neither follows the rem base. So drive
    // BOTH vars off the user's size, or "Small" only shrinks the little text (Buck 2026-06-08).
    root.style.setProperty('--lguser-scale', String(z.scale));
    root.style.setProperty('--lg-read-scale', String(z.scale));
    root.style.fontSize = (z.scale * 100) + '%';

    // Comment bubble: derived from the MODE, no user control anywhere (Ian
    // 2026-06-10 final: picker removed from desktop too). Light -> the grey
    // fallback; dark -> the theme's dark bubble (set by the vars loop above).
    if (!t.dark) root.style.removeProperty('--lguser-bubble');

    // Hub feed layout (cards vs immersive edge-to-edge). hub-polish.js reads this.
    root.setAttribute('data-lguser-feed', getFeed());

    ensureApplyStyle();
    ensureDarkStyle();

    // Phone status-bar color (PWA theme-color): black in dark mode so the OS top
    // bar matches the app, else the brand sage (Buck 2026-06-08). Stash the original
    // once so light/custom restore correctly.
    try {
      var tcMeta = document.querySelector('meta[name="theme-color"]');
      if (tcMeta) {
        if (!tcMeta.getAttribute('data-lg-orig')) tcMeta.setAttribute('data-lg-orig', tcMeta.getAttribute('content') || '#87986a');
        var isDark = root.getAttribute('data-lguser-dark') === '1';
        tcMeta.setAttribute('content', isDark ? '#000000' : (tcMeta.getAttribute('data-lg-orig') || '#87986a'));
      }
    } catch (e) {}

    // Persist a compact RESOLVED snapshot for the pre-paint bootstrap (injected in
    // <head> before this script). It reads this and applies the same tokens/attrs
    // synchronously on the first frame, so themed/dark users never flash the default
    // light look on a page navigation. Idempotent: this apply() re-applies identically.
    persistBoot();
  }

  // Build the same resolved values apply() just set, as a flat blob the early
  // bootstrap can replay with zero knowledge of the theme maps.
  function bootBlob() {
    var themeId = getTheme();
    var f = byId(FONTS, getFont());
    var z = byId(SIZES, getSize());
    var vars = {}, dark = false, k;
    var t = byId(THEMES, themeId);
    if (t.vars) for (k in t.vars) if (t.vars.hasOwnProperty(k)) vars[k] = t.vars[k];
    dark = !!t.dark;
    return {
      theme: themeId, dark: dark, vars: vars,
      font: f.stack || null,
      fontHref: f.google ? ('https://fonts.googleapis.com/css2?family=' + f.google + '&display=swap') : null,
      scale: z.scale, feed: getFeed(), bubble: null,
      bg: vars['--lg-cream'] || null, ink: dark ? '#e5e7e1' : null
    };
  }
  function persistBoot() {
    try { localStorage.setItem('lg-set-boot', JSON.stringify(bootBlob())); } catch (e) {}
  }

  function set(kind, id) {
    if (!KEY[kind]) return;
    wr(kind, id);
    apply();
  }

  // Render the settings UI into `container`. Pure DOM; each control calls
  // set(...) for live apply + persistence. Styling piggybacks on brand tokens.
  function buildPanel(container) {
    container.innerHTML = '';
    if (!/lg-set-panel/.test(container.className)) container.className = (container.className + ' lg-set-panel').trim();

    function section(title, items, current, kind, render, extra) {
      var sec = document.createElement('div');
      sec.className = 'lg-set-sec';
      var h = document.createElement('div');
      h.className = 'lg-set-sec__h';
      h.textContent = title;
      sec.appendChild(h);
      var row = document.createElement('div');
      row.className = 'lg-set-row';
      items.forEach(function (it) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'lg-set-opt' + (it.id === current ? ' is-on' : '');
        b.setAttribute('data-id', it.id);
        render(b, it);
        b.addEventListener('click', function () {
          set(kind, it.id);
          row.querySelectorAll('.lg-set-opt').forEach(function (x) { x.classList.remove('is-on'); });
          b.classList.add('is-on');
        });
        row.appendChild(b);
      });
      if (extra) extra(row);
      sec.appendChild(row);
      container.appendChild(sec);
    }

    section('Color', THEMES, getTheme(), 'theme', function (b, it) {
      b.innerHTML = '<span class="lg-set-swatch" style="background:' + it.dot + '"></span>' +
        '<span class="lg-set-opt__t">' + it.name + '</span>';
    });
    section('Font', FONTS, getFont(), 'font', function (b, it) {
      b.innerHTML = '<span class="lg-set-opt__t"' + (it.stack ? ' style="font-family:' + it.stack + '"' : '') + '>' + it.name + '</span>';
      if (it.google) loadFont(it); // preview in its own face
    });
    section('Text size', SIZES, getSize(), 'size', function (b, it) {
      b.innerHTML = '<span class="lg-set-opt__t">' + it.name + '</span>';
    });
    // (Comment-bubble picker removed EVERYWHERE — Ian 2026-06-10: bubbles +
    // text derive from the mode. BUBBLES kept only for stored-key compat.)
    // (Hub feed Cards/Full-screen control REMOVED from the gear — Ian
    // 2026-06-10. Immersive is the one canonical feed look.)
    // Hub layout (desktop only): Mosaic (masonry packing) vs Stream (one centered
    // column). Moved here from the Hub sidebar panel 2026-06-10 (bespoke-cutover;
    // Ian: the gear is the ONLY page-state control zone). Own localStorage key
    // 'lg-hub-layout' (compat with prior picks); applied PRE-PAINT by the nginx
    // boot script and live here on tap. Legacy cards/compact picks → Mosaic.
    if (window.matchMedia && window.matchMedia('(min-width:641px)').matches) {
      (function () {
        var LAYOUTS = [{ id: 'masonry', name: 'Mosaic' }, { id: 'stream', name: 'Stream' }];
        var cur = 'masonry';
        try { cur = localStorage.getItem('lg-hub-layout') || 'masonry'; } catch (e) {}
        if (cur === 'cards' || cur === 'compact') cur = 'masonry';
        var sec = document.createElement('div'); sec.className = 'lg-set-sec';
        var h = document.createElement('div'); h.className = 'lg-set-sec__h'; h.textContent = 'Hub layout';
        sec.appendChild(h);
        var row = document.createElement('div'); row.className = 'lg-set-row';
        LAYOUTS.forEach(function (it) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'lg-set-opt' + (it.id === cur ? ' is-on' : '');
          b.setAttribute('data-id', it.id);
          b.innerHTML = '<span class="lg-set-opt__t">' + it.name + '</span>';
          b.addEventListener('click', function () {
            try { localStorage.setItem('lg-hub-layout', it.id); } catch (e) {}
            document.documentElement.setAttribute('data-lg-hublayout', it.id);
            row.querySelectorAll('.lg-set-opt').forEach(function (x) { x.classList.remove('is-on'); });
            b.classList.add('is-on');
          });
          row.appendChild(b);
        });
        sec.appendChild(row);
        container.appendChild(sec);
      })();
    }
    // Videos section REMOVED from the panel (Vanessa 2026-06-11: "no situation
    // where a person would want to watch two videos at once") — one-at-a-time is
    // simply the behavior now. PLAYONE + getPlayone stay: hub-polish's
    // enforceSingleVideo still reads the pref, which defaults to 'on'.
  }

  window.LGSettings = {
    THEMES: THEMES, FONTS: FONTS, SIZES: SIZES, BUBBLES: BUBBLES, FEEDVIEWS: FEEDVIEWS, PLAYONE: PLAYONE,
    get: rd, set: set, apply: apply,
    getTheme: getTheme, getFont: getFont, getSize: getSize, getBubble: getBubble, getFeed: getFeed, getPlayone: getPlayone,
    buildPanel: buildPanel
  };

  apply();
  // forums.js's feed text-size pill ALSO writes --lg-read-scale on load, racing us
  // — if it runs after our apply() it resets the user's size (Buck 2026-06-08: "set
  // to small but not small until I toggled it"). Re-assert after the DOM + a couple
  // of ticks so the user's chosen size deterministically wins.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply);
  else apply();

  // Follow live OS theme changes while on "system" (no explicit saved theme). #64
  try {
    var _sysMq = window.matchMedia('(prefers-color-scheme: dark)');
    var _onSys = function () { if (!rd('theme')) apply(); };
    if (_sysMq.addEventListener) _sysMq.addEventListener('change', _onSys);
    else if (_sysMq.addListener) _sysMq.addListener(_onSys);
  } catch (e) {}
  setTimeout(apply, 350);
  setTimeout(apply, 1000);
})();
