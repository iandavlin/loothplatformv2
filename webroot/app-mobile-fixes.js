/* Looth app — mobile layout guards (client-side, injected site-wide via /pwa.js).
   Self-contained: injects one <style> block. No deps, no emoji.

   WHY: the shared chrome FOOTER (site-header.css `.lg-chrome-foot__inner`) uses a
   fixed `grid-template-columns: 320px 1fr` with NO mobile breakpoint, so on a phone
   it is wider than the viewport and forces horizontal scroll on EVERY page (Hub,
   Events, Members, …). Confirmed via CDP phone audit: hiding `.lg-chrome-foot__cols`
   drops document scrollWidth from 663–695 back under the 500 viewport.

   The proper fix lives in `/srv/lg-shared/site-header.css` (www-data / coordinator) —
   handed off. This is the same rules as a client-side guard so the bug is gone NOW.
   When the canonical media query lands, this becomes a harmless no-op duplicate.

   These rules sit in a <style> appended to <head> AFTER site-header.css, so plain
   source-order specificity wins — no !important needed. */
(function () {
  'use strict';
  if (window.__loothMobileFixes) return;
  window.__loothMobileFixes = true;

  // Mobile only: the legacy Archive views (/archive, /archive-poc) are desktop-only
  // reference surfaces — on a phone the Hub is the front door, so a tap should never
  // dead-end there. Send mobile visitors to the Hub. Runs as early as this script
  // executes; location.replace avoids a back-button trap. Desktop is untouched.
  (function () {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      // Guitardle is the one phone-friendly surface down there — its standalone
      // page has a pinned-keyboard mobile layout, and the Hub teaser layer links
      // straight to it. Let it through (Buck 6/12).
      if (/^\/archive-poc\/guitardle(\/|$)/.test(location.pathname || '')) return;
      if (/^\/archive(-poc)?(\/|$)/.test(location.pathname || '')) {
        location.replace('/hub/');
      }
    } catch (e) {}
  })();

  var STYLE_ID = 'looth-mobile-fixes';
  if (document.getElementById(STYLE_ID)) return;

  var css =
    '@media (max-width:640px){' +
      // Footer: stack the brand column above the link columns, collapse links to
      // two columns, and let the legal row wrap — kills the horizontal overflow.
      '.lg-chrome-foot__inner{grid-template-columns:1fr;gap:28px;padding:32px 18px 24px}' +
      '.lg-chrome-foot__brand{max-width:100%}' +
      '.lg-chrome-foot__cols{grid-template-columns:repeat(2,1fr);gap:18px 20px}' +
      '.lg-chrome-foot__legal{flex-direction:column;align-items:flex-start;gap:8px}' +
      '.lg-chrome-foot{margin-top:36px}' +
      // REVERSED (HK-013 / GH #45): .fcr-add used to be display:none on mobile —
      // "press-and-hold the like opens the picker instead" (Buck 2026-06-08). But
      // the sweep proved long-press never covered replies/OPs with zero reactions,
      // leaving mobile members NO way to originate a reaction. Show it again as a
      // clear "☺ React" pill (mirrors hub-polish's desktop treatment): hide the
      // tiny "+" span, label via ::after, 32px tap height.
      '.fcr-add{display:inline-flex!important;align-items:center!important;height:32px!important;' +
        'min-width:0!important;width:auto!important;padding:0 12px!important;border-radius:999px!important;' +
        'gap:5px!important;background:var(--lguser-pill,var(--lg-sage-tint,#eef2e3))!important;' +
        'border:1px solid var(--lguser-line,#e3ddd0)!important;' +
        'color:var(--lguser-accent-d,var(--lg-sage-d,#52613d))!important;' +
        'font-size:16px!important;line-height:1!important}' +
      '.fcr-add>span{display:none!important}' +
      '.fcr-add::after{content:"React";font:700 12px/1 var(--lg-font-sans,system-ui,-apple-system,sans-serif);color:inherit}' +
    '}' +
    // Very small phones: a single link column reads better than cramped pairs.
    '@media (max-width:380px){' +
      '.lg-chrome-foot__cols{grid-template-columns:1fr}' +
    '}' +
    // Members directory: pin the map below the sticky header so it stays visible
    // while the filters + member list scroll underneath. (Canonical home is
    // directory.css, which is now ubuntu-owned; this guard ships it on the live
    // domain today. Map shrinks to a band so the list keeps room below it.)
    '@media (max-width:640px){' +
      '.dir-map{position:sticky;top:61px;height:240px;z-index:30;' +
      'box-shadow:0 6px 12px -6px rgba(26,29,26,.35)}' +
    '}' +
    // Off-canvas search/filter drawer (.ls-*) + chrome aside spilled ~87px past
    // the phone viewport (backdrop 477px wide, panel 440px parked via transform),
    // forcing the page to render zoomed-out/cramped. Contain them to the viewport.
    '@media (max-width:640px){' +
      // Kill any residual horizontal scroll at the document root. overflow-x:clip
      // (not hidden) does NOT create a scroll container, so the sticky map above
      // and position:fixed chrome keep working. Belt-and-suspenders over the
      // specific drawer/aside containment below.
      'html{overflow-x:clip}' +
      '.ls-back{max-width:100vw;overflow-x:hidden}' +
      '.ls-panel{max-width:86vw}' +
      '.lg-chrome__aside{max-width:100%;overflow:hidden}' +
      // Hub top-bar fixes for the v54 absorption regressions (Buck, 2026-06-06):
      //  (1) the sort/filter/new-post bar went position:static and scrolled away on
      //      scroll — pin it just under the 61px sticky header so it stays reachable;
      //  (2) the theme/text/compact toggles got crammed into the top bar — hide them
      //      (theme lives in the profile-bubble Settings). Temporary client guard until
      //      the canonical forums fix lands; coordinator notified.
      '.feed-sort-bar{position:sticky;top:61px;z-index:40;background:var(--lg-cream,#fbfbf8);transition:transform .25s ease}' +
      // The header (which holds the search bar) auto-hides on scroll via .lg-chrome--tuck;
      // tuck the sort bar away WITH it so it does not float alone (Buck, 2026-06-06).
      '.feed-sort-bar.lg-sortbar-tuck{transform:translateY(-120px)}' +
      '.feed-view-toggles{display:none !important}' +
      // The Filters chip hamburger glyph (.corner-hamburger__icon) carries legacy
      // corner-menu styling: position:absolute with corner offsets. Its button
      // (.lg-filters-chip) is position:static, so the icon escaped its parent and
      // floated to the top-left, overlapping the first sort tab (Random/Fresh) — the
      // "hamburger remnant" Buck reported (2026-06-07). Pin it back inside the chip.
      '.feed-sort-bar .lg-filters-chip .corner-hamburger__icon{position:static;inset:auto;margin-right:4px}' +
      // Lock the sort bar to the viewport edges (Buck, 2026-06-07: "new posts is
      // slightly off the screen, I want the edges locked"). With THREE sort tabs
      // (Random/Newest/Trending) the nowrap row (~479px) overflowed ~366px and pushed
      // "+ New post" off the right edge. Collapse the two right-hand ACTIONS to compact
      // icon button (Filters → ☰); the New-post pill keeps a label but a SHORT one
      // ("+ Create" instead of "+ New post", Buck 2026-06-07) and the sort tabs are
      // trimmed slightly so the whole row still fits the locked edges.
      '.feed-page .feed-sort-bar{max-width:100%;box-sizing:border-box;gap:5px;overflow-x:auto;scrollbar-width:none;-ms-overflow-style:none}' +
      '.feed-page .feed-sort-bar::-webkit-scrollbar{display:none}' +
      '.feed-page .feed-sort-bar>a{padding-left:8px;padding-right:8px}' +
      '.feed-page .feed-sort-bar>.lg-filters-chip{margin-left:auto;padding:7px 10px;margin-right:0}' +
      '.feed-page .feed-sort-bar>.lg-filters-chip .lg-filters-chip__tx{display:none}' +
      '.feed-page .feed-sort-bar>.lg-newpost{font-size:0;gap:0;padding:7px 12px}' +
      '.feed-page .feed-sort-bar>.lg-newpost::before{content:"+ Create";font-size:13px;font-weight:700;line-height:1;display:inline-block;white-space:nowrap}' +
      // (Card-header category-dup stopgap RETIRED 2026-06-07: canonical commit ccd6350
      // "fix mobile category dup — default-hide .fc-cat-chip, show only >=641" now owns
      // this, so the app-mobile-fixes hack is no longer needed.)
      // Consolidate the DUPLICATE comment/reply controls on CONTENT cards (Buck
      // 2026-06-07: loothprints/articles/videos showed BOTH a "💬 Comment" button AND a
      // "Reply" action). The canonical content card renders feed_action_bar(0) (→ the
      // .lg-act-replies "Reply", which hub-polish wires to open the comments modal) AND a
      // standalone .feed-card__comments-btn. On MOBILE both show. Keep the Reply (it opens
      // the same thread) and hide the redundant comment button. (Desktop ≥641 keeps the
      // comment button — its action row is hidden there, so it's the only access; this
      // rule is mobile-only.) Proper consolidation + a comment-count on Reply = canonical
      // follow-up, relayed to the coordinator.
      '.feed-page .feed-card--content .feed-card__comments-btn{display:none}' +
      // TEXT SIZE: the canonical MOBILE card title/excerpt are FIXED px (they ignore the
      // user size — Buck: "Small still looks big"). Re-tie the prominent Hub text to the
      // user scale var (--lguser-scale, which app-settings drives off the Settings size)
      // so Small/Default/Large/Larger actually resize the whole card. (Reply/secondary
      // text already keys off --lguser-scale.) (Buck 2026-06-08.)
      '.feed-page .feed-card__title{font-size:calc(18px*var(--lguser-scale,1)) !important}' +
      '.feed-page .feed-card--content .feed-card__title{font-size:calc(20px*var(--lguser-scale,1)) !important}' +
      '.feed-page .feed-card__op-excerpt,.feed-page .feed-card__full-body{font-size:calc(15px*var(--lguser-scale,1)) !important}' +
      // iOS: a long-press on the engagement rows was firing Safari text-selection +
      // image copy/paste callouts instead of the reaction picker (Ian, 2026-06-06).
      // Kill the iOS touch-callout + text selection on BOTH engagement rows and the
      // canonical reaction picker (mobile-hub.js's wireLongPressReactions now owns the
      // long-press → it opens the persisted .fcr palette; the old visual-only
      // .lg-react-bar floating bar was removed 2026-06-07, unified on the real picker).
      '.lg-card-actions,.lg-card-actions *,.fc-actions,.fcr,.fcr-palette,.fcr-palette *{' +
        '-webkit-touch-callout:none;-webkit-user-select:none;user-select:none}' +
      // (Theme-normalize block REMOVED 2026-06-10 bespoke-cutover: it keyed on
      // the retired hub-theme-* classes — dead code — and mobile now honors the
      // user's own Light/Dark pick from the gear.)
      ''+
    '}';

  function inject() {
    if (document.getElementById(STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  if (document.head) inject();
  else document.addEventListener('DOMContentLoaded', inject);

  // Mobile only: the bottom tab bar already provides Hub / Events / Members, so
  // remove those duplicate links from the hamburger drawer — leaving the unique
  // items (Archive, Loothtool). On mobile the bottom bar owns page navigation;
  // the redundant menu page-selection is just clutter. Desktop has no bottom bar,
  // so it keeps the full menu. Client-side; the canonical header is coordinator-owned.
  function trimHamburgerDupes() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var menu = document.querySelector('.lg-chrome__menu');
      if (!menu) return;
      var dupe = /^\/(hub|events|directory\/members)\/?$/;
      var links = menu.querySelectorAll('a');
      for (var i = 0; i < links.length; i++) {
        var href = (links[i].getAttribute('href') || '').replace(/[#?].*$/, '');
        if (dupe.test(href)) { var li = links[i].closest('li'); (li || links[i]).remove(); }
      }
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', trimHamburgerDupes);
  else trimHamburgerDupes();

  // killCompactOnMobile RETIRED here 2026-06-10 (bespoke-cutover, audit C6): it was
  // a byte-identical duplicate of mobile-hub.js §1, which owns it (mobile behaviors
  // layer, ≤640-gated at load). One implementation, one owner.

  // Mobile only: the sort/filter bar should hide together with the search bar.
  // The header (#site-header, which contains the .lg-hub-search bar) auto-hides on
  // scroll-down by toggling .lg-chrome--tuck. Mirror that state onto the sort bar so
  // the two move as one - hide on scroll-down, reveal on scroll-up (Buck, 2026-06-06).
  // Desktop: matchMedia is false, so the observer is never set up.
  function tieSortBarToHeaderTuck() {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      var hdr = document.getElementById('site-header') || document.querySelector('.lg-chrome');
      if (!hdr) return;
      var sync = function () {
        var sb = document.querySelector('.feed-sort-bar');
        if (sb) sb.classList.toggle('lg-sortbar-tuck', hdr.classList.contains('lg-chrome--tuck'));
      };
      sync();
      if ('MutationObserver' in window) {
        new MutationObserver(sync).observe(hdr, { attributes: true, attributeFilter: ['class'] });
      }
    } catch (e) {}
  }
  tieSortBarToHeaderTuck();
  document.addEventListener('DOMContentLoaded', tieSortBarToHeaderTuck);

  // ── Fullscreen video → auto-rotate to landscape (Buck 2026-06-08) ───────────
  // When a YouTube (or any) video goes fullscreen on a phone, lock the screen to
  // landscape; restore (unlock → portrait) when leaving fullscreen. Android Chrome
  // honors screen.orientation.lock inside fullscreen; iOS Safari ignores it but its
  // native video fullscreen already auto-rotates, so it's a harmless no-op there.
  // Mobile only — desktop never locks orientation.
  (function () {
    if (!window.matchMedia('(max-width:640px)').matches && !window.matchMedia('(pointer:coarse)').matches) return;
    // NO orientation lock on fullscreen entry — settled empirically over three
    // rounds (Buck 2026-06-11): any parent-page lock('landscape') during a
    // YT-button fullscreen makes the player warp back out (v30 + v32 both
    // bounced; v31 without the lock was stable). YouTube fullscreen rotates
    // natively when the phone's auto-rotate is on / it's held sideways, and
    // our rotate-the-phone path below still force-fullscreens to landscape.
    function onFsChange() {
      var fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
      if (fsEl) return;
      try {
        // When the standalone orientation manager (below) is active it owns the
        // exit transition (re-locks portrait); a plain unlock here would undo it.
        if (!window.__lgOrientMgr) {
          var so = screen.orientation;
          if (so && so.unlock) so.unlock();    // leaving → back to natural rotation
        }
      } catch (e) {}
      // The fullscreen churn can leave the page slightly pinch-zoomed with the
      // layout feeling "off" (Buck). If the visual viewport didn't land back at
      // 1:1, force it: momentarily clamp max-scale, then restore.
      try {
        if (window.visualViewport && Math.abs(window.visualViewport.scale - 1) > 0.02) {
          var m = document.querySelector('meta[name="viewport"]');
          if (m) {
            var orig = m.getAttribute('content') || 'width=device-width, initial-scale=1';
            m.setAttribute('content', orig + ', maximum-scale=1');
            setTimeout(function () { m.setAttribute('content', orig); }, 250);
          }
        }
      } catch (e) {}
    }
    document.addEventListener('fullscreenchange', onFsChange, false);
    document.addEventListener('webkitfullscreenchange', onFsChange, false);
  })();

  // ── Rotate phone to landscape → put the playing video FULLSCREEN (Buck 2026-06-08).
  // The inverse of the lock above: when the user turns the phone sideways while a
  // feed video is playing, take it fullscreen; turning back to portrait exits.
  // BEST-EFFORT: the Fullscreen API usually needs a user gesture, and an
  // orientationchange may not count as one on every browser (Android Chrome often
  // allows it shortly after rotation; iOS only fullscreens native <video>, not
  // YouTube iframes — but iOS already rotates its own player). Harmless if blocked.
  (function () {
    if (!window.matchMedia('(max-width:640px)').matches && !window.matchMedia('(pointer:coarse)').matches) return;
    function isLandscape() {
      try { if (screen.orientation && /landscape/.test(screen.orientation.type)) return true; } catch (e) {}
      return window.innerWidth > window.innerHeight;
    }
    function playingVid() {
      if (document.fullscreenElement || document.webkitFullscreenElement) return null;
      return document.querySelector('.fc-cover--video iframe.fc-video') || document.querySelector('iframe.fc-video');
    }
    // byRotate: true only while a fullscreen WE started (phone turned sideways)
    // is active. The portrait→exit shortcut below applies ONLY to those — a
    // fullscreen the user started via YouTube's own button must never be exited
    // by orientation noise (locks snapping the orientation made us bounce
    // straight back out — Buck 2026-06-11).
    var byRotate = false, lastExit = 0;
    function reqFs(el) { var fn = el.requestFullscreen || el.webkitRequestFullscreen || el.webkitEnterFullscreen; if (fn) { try { fn.call(el); byRotate = true; } catch (e) {} } }
    function exitFs() { var fn = document.exitFullscreen || document.webkitExitFullscreen; if (fn) { try { fn.call(document); } catch (e) {} } }
    // After ANY fullscreen exit, clear the rotate flag and hold off auto
    // re-entry briefly — otherwise exiting while the phone is physically
    // sideways can immediately fullscreen the video again ("stuck").
    function onAnyFsChange() {
      if (!(document.fullscreenElement || document.webkitFullscreenElement)) {
        lastExit = Date.now();
        byRotate = false;
      }
    }
    document.addEventListener('fullscreenchange', onAnyFsChange, false);
    document.addEventListener('webkitfullscreenchange', onAnyFsChange, false);
    function onRotate() {
      try {
        if (isLandscape()) {
          if (Date.now() - lastExit < 1500) return; // user just exited — respect it
          var v = playingVid(); if (v) reqFs(v);
        }
        else if (byRotate) {
          var fs = document.fullscreenElement || document.webkitFullscreenElement;
          if (fs && fs.classList && fs.classList.contains('fc-video')) exitFs();
        }
      } catch (e) {}
    }
    try { if (screen.orientation && screen.orientation.addEventListener) screen.orientation.addEventListener('change', function () { setTimeout(onRotate, 130); }); } catch (e) {}
    window.addEventListener('orientationchange', function () { setTimeout(onRotate, 130); });
  })();

  // ── Installed-app orientation manager (Buck 2026-06-12): landscape fullscreen ──
  // The PWA manifest used to say "orientation":"portrait" — the OS held the app
  // portrait even during video fullscreen, so LANDSCAPE WAS IMPOSSIBLE in the
  // installed app (and the rotate-phone path above never fires there: a
  // portrait-locked webview never reports an orientation change). Forcing
  // lock('landscape') instead bounced the YT player out (v30/v32, settled 6/11).
  // The fix flips the default: manifest is now "any", and the APP locks itself
  // to portrait at runtime (allowed for installed apps outside fullscreen).
  // While a video/iframe is fullscreen the lock is RELEASED, so the sensor
  // rotates the player natively — exactly the mobile-browser behavior that was
  // stable all along. Exit re-locks portrait. Browser tabs: lock() rejects →
  // permanent no-op (the browser already rotates fullscreen video natively).
  // Phones/tablets only; desktop never touches orientation.
  (function () {
    if (!window.matchMedia('(pointer:coarse)').matches) return;
    var standalone = window.matchMedia('(display-mode: standalone)').matches ||
                     window.navigator.standalone === true;
    if (!standalone) return;
    var so = screen.orientation;
    if (!so || !so.lock) return;
    window.__lgOrientMgr = true;   // the exit handler above defers to us
    function lockPortrait() {
      try { var p = so.lock('portrait-primary'); if (p && p.catch) p.catch(function () {}); } catch (e) {}
    }
    function release() { try { so.unlock(); } catch (e) {} }
    function isMedia(el) {
      if (!el) return false;
      var t = (el.tagName || '').toUpperCase();
      if (t === 'IFRAME' || t === 'VIDEO') return true;
      return !!(el.querySelector && el.querySelector('iframe, video'));
    }
    var fsLandTimer = null;
    function onFs() {
      var fsEl = document.fullscreenElement || document.webkitFullscreenElement;
      if (fsEl && isMedia(fsEl)) {
        release();                               // lift our portrait lock first
        // FORCE landscape (like the YouTube app) — Buck 2026-06-12: sensor-only
        // release left fullscreen portrait on his phone. Conditions have changed
        // since the v30/v32 bounce rounds: the deploy auto-reload that killed
        // fullscreen mid-test is gone and fullscreen is stable now, so a
        // delayed, re-checked lock is safe to try. Works even while the
        // installed app still carries the OLD portrait manifest (fullscreen
        // grants orientation-lock permission). If this ever warps the player
        // back out again, delete THIS timer block only — release-only above is
        // the fallback (sensor rotation).
        if (fsLandTimer) clearTimeout(fsLandTimer);
        fsLandTimer = setTimeout(function () {
          fsLandTimer = null;
          var el = document.fullscreenElement || document.webkitFullscreenElement;
          if (!el || !isMedia(el)) return;       // already exited — don't touch
          try { var p = so.lock('landscape'); if (p && p.catch) p.catch(function () {}); } catch (e) {}
        }, 400);
      } else if (!fsEl) {
        if (fsLandTimer) { clearTimeout(fsLandTimer); fsLandTimer = null; }
        lockPortrait();                          // back in the app → portrait
      }
    }
    document.addEventListener('fullscreenchange', onFs, false);
    document.addEventListener('webkitfullscreenchange', onFs, false);
    lockPortrait();
  })();

  // Mobile reactions: the old visual-only "heart, hold for more options" floating
  // emoji bar (wireMobileReactions / .lg-react-bar) was REMOVED 2026-06-07. It never
  // persisted, and it collided with mobile-hub.js's wireLongPressReactions — two
  // long-press handlers fought on every card, so the picker flashed then vanished
  // before you could tap it (Buck report). We unified on the REAL picker: the
  // canonical, persisted .fcr palette, opened by mobile-hub.js's long-press. Nothing
  // reaction-related lives here anymore.

  // ── Profile editor: one-tap Move up / Move down per section (Buck 2026-06-11,
  // MOBILE). The drag grip is fiddly on a phone. The canonical version of these
  // buttons is awaiting merge (buck/profile-section-move @ a30096e); this overlay
  // injects the SAME .lg-block__mv buttons into the owner editor chrome and
  // becomes a no-op once canonical lands (both sides guard on button presence).
  // Gates: ≤640, /u/ paths, and ONLY true edit mode (the owner chrome's
  // .lg-block__grip must be present — View-as and visitor views never get it).
  (function () {
    try {
      if (!window.matchMedia('(max-width:640px)').matches) return;
      if (!/^\/u(\/|$)/.test(location.pathname || '')) return;
    } catch (e) { return; }

    function bodyBlocks(profile) {
      return Array.prototype.slice.call(profile.querySelectorAll('.lg-block:not(.lg-block--header)'));
    }
    function order(profile) {
      return bodyBlocks(profile).map(function (s) { return s.getAttribute('data-block'); }).filter(Boolean);
    }
    function putLayout(arr) {
      fetch('/profile-api/v0/me/layout', {
        method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order: arr })
      }).catch(function () {});
    }

    function wire(profile) {
      if (profile.querySelector('.lg-block__mv')) return;   // canonical already provides them
      var st = document.createElement('style');
      st.id = 'lg-secmv-css';
      st.textContent =
        '.lg-block__mv{display:inline-flex;align-items:center;justify-content:center;border:0;background:none;cursor:pointer;color:var(--lg-mute,#6b6f6b);padding:0 2px;min-width:30px;height:30px;vertical-align:middle}' +
        '.lg-block__mv svg{width:16px;height:16px}' +
        '.lg-block__mv[disabled]{opacity:.28;cursor:default}';
      (document.head || document.documentElement).appendChild(st);

      function mk(dir, label, path) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lg-block__mv lg-block__mv--' + dir;
        btn.setAttribute('data-mv', dir);
        btn.setAttribute('title', label); btn.setAttribute('aria-label', label);
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
        return btn;
      }
      bodyBlocks(profile).forEach(function (b) {
        var host = b.querySelector('.lg-bh') || b;
        if (host.querySelector('.lg-block__mv--up')) return;
        var up = mk('up', 'Move section up', '<path d="m5 14.5 7-7 7 7"/>');
        var dn = mk('down', 'Move section down', '<path d="m5 9.5 7 7 7-7"/>');
        var anchor = host.querySelector('.lg-block__rm') ||
                     (host.querySelector('.lg-block__grip') && host.querySelector('.lg-block__grip').nextSibling) || host.firstChild;
        host.insertBefore(dn, anchor);
        host.insertBefore(up, dn);
      });
      function refresh() {
        var list = bodyBlocks(profile);
        list.forEach(function (b, i) {
          var u = b.querySelector('.lg-block__mv--up'), d = b.querySelector('.lg-block__mv--down');
          if (u) u.disabled = (i === 0);
          if (d) d.disabled = (i === list.length - 1);
        });
      }
      refresh();
      profile.addEventListener('click', function (e) {
        var mv = e.target.closest && e.target.closest('.lg-block__mv');
        if (!mv || mv.disabled) return;
        var block = mv.closest('.lg-block:not(.lg-block--header)');
        if (!block) return;
        var list = bodyBlocks(profile);
        var i = list.indexOf(block);
        if (i < 0) return;
        if (mv.getAttribute('data-mv') === 'up') {
          if (i === 0) return;
          list[i - 1].parentNode.insertBefore(block, list[i - 1]);
        } else {
          if (i === list.length - 1) return;
          list[i + 1].parentNode.insertBefore(block, list[i + 1].nextSibling);
        }
        putLayout(order(profile));
        refresh();
        try { block.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (err) {}
      });
    }

    // The owner chrome injects after DOM-ready via u.php's own script — poll
    // briefly for the grip (its presence = true edit mode), then wire once.
    var tries = 0;
    var iv = setInterval(function () {
      if (++tries > 60) { clearInterval(iv); return; }
      var profile = document.querySelector('.lg-profile');
      if (!profile || !profile.querySelector('.lg-block__grip')) return;
      clearInterval(iv);
      wire(profile);
    }, 200);
  })();
})();
