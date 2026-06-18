/* sponsor-sheet.js — Looth PWA (mobile only)
 *
 * Tapping the Sponsors nav (/sponsors/) OR an individual sponsor
 * (/sponsor-page/<slug>/ which 301s to /sponsors/<slug>/) opens it in an
 * app-style chromeless bottom sheet instead of navigating to the full page
 * (which carries the site header). Sibling of practice-sheet.js — same sheet
 * scaffold + scopeCss mini-parser + capture-phase interceptor.
 *
 * v3 (2026-06-12): individual sponsor pages are lg-layout-v2 STANDALONE pages
 * (main.lg-standalone-main) whose design lives in LINKED stylesheets
 * (lg-v2-bundle...css) — v2 of this layer only copied inline <style> blocks,
 * so the page rendered naked in the sheet (Buck's Total Vise screenshot).
 * Now: v2 pages are detected, their linked CSS is fetched (cached) and scoped
 * (scopeCss learned @layer), html/body/:root rules land on .lss-body so the
 * backdrop isn't painted over, the old blind-polish CSS is gated to legacy
 * (:not(.lss-v2)) pages, plus: dark pass for the v2 surfaces, tap-to-play for
 * the stripped YouTube facade, carousel arrows, body-drag dismiss at
 * scrollTop 0 and back-gesture close (Buck's pull-up-sheet rules).
 *
 * v5 (2026-06-12): @layer is flattened when scoping — layered design rules
 * lost to the host page's unlayered globals (green underlined CTA text).
 *
 * v6 (2026-06-12): dark mode renders v2 pages as the designed LIGHT paper
 * (only the sheet chrome goes dark), matching app-settings' deliberate
 * light-island treatment of main.lg-standalone-main on article pages
 * (Ian 2026-06-10). A bespoke dark skin fought those !important tokens and
 * kept producing light-on-light / dark-on-dark text.
 *
 * v4 (2026-06-12): overscroll-to-close actually works in the wild (Buck).
 * Two fixes: (1) close() clears the drag's inline transform/transition —
 * it used to outlive a drag-dismiss, freeze the slide-down animation and
 * leave every later open stuck partway down the screen; (2) the body
 * at-top guard is scrollTop <= 1 (not <= 0) because real phones rest at a
 * fractional scrollTop after momentum scrolling, which silently killed the
 * pull-down gesture on-device.
 */
(function () {
  'use strict';
  if (window.__lgSponSheet) return;
  window.__lgSponSheet = true;
  var MOBILE = window.matchMedia('(max-width:640px)');
  if (!MOBILE.matches) return;
  try { if (window.top !== window.self) return; } catch (e) {}

  var SHEET_ID = 'looth-spon-sheet';
  var STYLE_ID = 'looth-spon-style';
  var PAGE_CSS_ID = 'looth-spon-pagecss';

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var S = '#' + SHEET_ID;
    var L = S + ':not(.lss-v2)';           // legacy (theme-wrapper) pages only
    var V = S + '.lss-v2';                 // lg-layout-v2 standalone pages
    var DK = 'html[data-lguser-theme="dark"] ';
    var css = [
      S + '{position:fixed;inset:0;z-index:2147483520;display:none}',
      S + '.is-open{display:block}',
      S + ' .lss-back{position:absolute;inset:0;background:rgba(26,29,26,.55);opacity:0;transition:opacity .2s}',
      S + '.is-open .lss-back{opacity:1}',
      S + ' .lss-card{position:absolute;left:0;right:0;bottom:0;top:34px;display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;overflow:hidden;' +
        'box-shadow:0 -10px 34px rgba(26,29,26,.34);transform:translateY(100%);transition:transform .28s cubic-bezier(.2,.7,.2,1)}',
      S + '.is-open .lss-card{transform:translateY(0)}',
      S + ' .lss-bar{flex:0 0 auto;position:relative;display:flex;align-items:center;gap:10px;' +
        'padding:13px 12px 10px;background:var(--lg-cream,#fbfbf8);border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      S + ' .lss-grab{position:absolute;top:5px;left:50%;transform:translateX(-50%);width:38px;height:4px;border-radius:999px;background:var(--lg-line,#e3ddd0)}',
      S + ' .lss-x{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-charcoal,#1a1d1a);font-size:21px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center}',
      S + ' .lss-btitle{flex:1 1 auto;min-width:0;font:700 17px/1.2 var(--lg-font-serif,Georgia,serif);' +
        'color:var(--lg-charcoal,#1a1d1a);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      S + ' .lss-body{flex:1 1 auto;overflow:auto;-webkit-overflow-scrolling:touch;padding:0 0 30px}',
      S + ' .lss-load{padding:46px 0;text-align:center;color:var(--lg-mute,#6b6f6b);font:600 14px/1 var(--lg-font-sans,system-ui)}',
      S + ' .lss-spin{width:26px;height:26px;margin:0 auto 12px;border:3px solid var(--lg-sage-tint,#eef2e3);' +
        'border-top-color:var(--lg-sage,#87986a);border-radius:50%;animation:lss-spin .8s linear infinite}',
      '@keyframes lss-spin{to{transform:rotate(360deg)}}',
      L + ' .lg-content-page,' + L + ' #main{max-width:none!important;margin:0!important;' +
        'padding:14px 16px 0!important;min-height:0!important;background:transparent!important}',
      S + ' .lss-body img{max-width:100%;height:auto}',
      // Social-link icons are BARE unsized <svg>s on LEGACY sponsor pages — their
      // sizing CSS doesn't survive into the sheet, so they exploded to full width
      // (Vanessa 2026-06-11, StewMac). Clamp icon-in-a-link to chip size and lay the
      // link row out as a tidy strip. v2 pages bring their own icon sizing — gated.
      L + ' .lss-body a > svg:only-child{width:30px;height:30px;display:inline-block;vertical-align:middle}',
      L + ' .lss-body a:has(> svg:only-child){display:inline-flex;align-items:center;justify-content:center;' +
        'width:48px;height:48px;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52);margin:4px 6px 4px 0}',
      L + ' .lss-body svg{max-width:72px;max-height:72px}',
      DK + L + ' .lss-body a:has(> svg:only-child){background:#262b30;color:#9cb37d}',
      // ── LEGACY sponsor-page polish (Buck 2026-06-11) — compensates for pages whose
      // styling never reaches the sheet. v2 pages get the REAL design CSS instead.
      L + ' .lg-brand-hero__banner img,' + L + ' .lg-brand-hero__banner-img{width:100%;height:150px;object-fit:cover;display:block}',
      L + ' .lg-brand-hero__logo{width:112px!important;height:112px;margin:-48px auto 0;border-radius:50%;overflow:hidden;' +
        'border:4px solid var(--lg-cream,#fbfbf8);background:#fff;box-shadow:0 4px 14px rgba(26,29,26,.18)}',
      L + ' .lg-brand-hero__logo img{width:100%;height:100%;object-fit:cover;display:block}',
      L + ' .lg-brand-hero__bar{display:flex;flex-direction:column;align-items:center;text-align:center;padding:0 16px}',
      L + ' .lg-brand-hero__name{font:700 24px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);margin:10px 0 4px;text-align:center}',
      L + ' .lg-brand-hero__tagline,' + L + ' .lg-brand-hero__desc{text-align:center;color:var(--lg-mute,#6b6f6b);font:14px/1.5 var(--lg-font-sans,system-ui,sans-serif)}',
      L + ' .lg-brand-hero__cta{display:flex;flex-wrap:wrap;justify-content:center;gap:9px;margin:14px 0 6px}',
      L + ' .lg-brand-hero__cta-btn{display:inline-flex;align-items:center;gap:7px;background:var(--lg-sage,#87986a);color:#fff!important;' +
        'border-radius:999px;padding:10px 16px;font:700 13.5px/1 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none}',
      L + ' .lg-brand-hero__cta-btn svg{width:17px!important;height:17px!important;max-width:17px;color:#fff;stroke:#fff}',
      L + ' .lg-section-heading,' + L + ' .lg-feat-products__title,' + L + ' .lg-recent-posts__title,' +
        L + ' .lg-brand-gallery__title,' + L + ' .lg-whos-talking__title,' + L + ' .lg-contact-form__title{' +
        'font:700 19px/1.25 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);margin:24px 0 10px}',
      L + ' .lg-whos-talking__links{display:flex;flex-direction:column;gap:9px;margin:6px 0 4px}',
      L + ' .lg-whos-talking__link{display:inline-flex;align-items:center;gap:9px;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52)!important;border-radius:14px;padding:12px 14px;font:600 14px/1.2 var(--lg-font-sans,system-ui,sans-serif);text-decoration:none}',
      L + ' .lg-whos-talking__link svg{width:20px!important;height:20px!important;max-width:20px;flex:0 0 auto}',
      // contact form: stacked labeled fields, brand inputs + submit (legacy pages)
      L + ' .lg-contact-form__row{display:flex;flex-direction:column;gap:12px;margin:0 0 12px}',
      L + ' .lg-contact-form__field{display:flex;flex-direction:column;gap:5px;width:100%}',
      L + ' .lg-contact-form__field > span{font:700 12.5px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b)}',
      L + ' .lg-contact-form__form input[type=text],' + L + ' .lg-contact-form__form input[type=email],' +
        L + ' .lg-contact-form__form textarea{width:100%;box-sizing:border-box;border:1px solid var(--lg-line,#e3ddd0);' +
        'border-radius:12px;background:#fff;padding:11px 14px;font:15px/1.4 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532)}',
      L + ' .lg-contact-form__form textarea{min-height:110px;resize:vertical}',
      L + ' .lg-contact-form__submit,' + L + ' .lg-contact-form__form button[type=submit]{display:block;width:100%;border:0;cursor:pointer;' +
        'background:var(--lg-sage,#87986a);color:#fff;border-radius:12px;padding:13px;font:700 15px/1 var(--lg-font-sans,system-ui,sans-serif);margin:2px 0 6px}',
      L + ' .lg-contact-form__form a,' + L + ' .lg-contact-form a{color:var(--lg-sage-d,#6b7c52)}',
      // dark pass for the legacy polish
      DK + L + ' .lg-brand-hero__logo{border-color:#1b1e21}',
      DK + L + ' .lg-brand-hero__name{color:#f2f4ee}',
      DK + L + ' .lg-section-heading,' + DK + L + ' .lg-feat-products__title,' +
        DK + L + ' .lg-recent-posts__title,' + DK + L + ' .lg-brand-gallery__title,' +
        DK + L + ' .lg-whos-talking__title,' + DK + L + ' .lg-contact-form__title{color:#f2f4ee}',
      DK + L + ' .lg-whos-talking__link{background:#243024;color:#b6c79a!important}',
      DK + L + ' .lg-contact-form__form input[type=text],' + DK + L + ' .lg-contact-form__form input[type=email],' +
        DK + L + ' .lg-contact-form__form textarea{background:#222629;border-color:#333833;color:#e5e7e1}',
      DK + L + ' .lg-brand-hero__cta-btn,' + DK + L + ' .lg-contact-form__submit,' +
        DK + L + ' .lg-contact-form__form button[type=submit]{background:var(--lg-sage-d,#6b7c52)}',
      // ── lg-layout-v2 standalone pages (Total Vise & co): the real design CSS is
      // fetched + scoped in; here we only FIT it to the sheet and skin it for dark.
      V + ' .lg-standalone-main{padding:0!important;margin:0!important;max-width:none!important;min-height:0!important}',
      // full-bleed blocks size off 100vw; the sheet card IS 100vw on phones, so just
      // keep horizontal overflow contained.
      V + ' .lss-body{overflow-x:hidden}',
      // stripped-JS fallback: the YouTube facade swap-in fills its frame
      V + ' .lg-embed__facade iframe{width:100%;height:100%;border:0;display:block}',
      // no Visit-Forum CTA in the mobile sheet (Buck 2026-06-12) — desktop keeps it
      S + ' .lg-brand-hero__cta-btn[href*="/forum/"]{display:none!important}',
      // and no "See who’s talking" box either (Buck 2026-06-12): redundant with
      // the hero pills and it carries its own forum button. Sheet-only.
      S + ' .lg-whos-talking{display:none!important}',
      // dark mode, v2 pages: NO dark content skin. app-settings deliberately
      // insulates main.lg-standalone-main as a light "paper" island in dark
      // (Ian 2026-06-10, !important tokens) — articles AND sponsor pages are
      // designed light, and a half-dark fight with those !important tokens
      // produced invisible card names. The sheet chrome goes dark; the page
      // inside stays the designed light paper, same as article pages in dark.
      // Match .lss-body to the paper so the bottom run-out doesn't band.
      DK + V + ' .lss-body{background:#f4f2ec}',
      // app-settings' global dark input rules paint near-black fields on the
      // white contact card — pin the paper-island inputs to the designed look.
      DK + V + ' .lg-contact-form__form input[type=text],' +
        DK + V + ' .lg-contact-form__form input[type=email],' +
        DK + V + ' .lg-contact-form__form textarea{' +
        'background:#fff!important;border:1px solid #e7e3d8!important;color:#323532!important}',
      // dark scaffold
      DK + S + ' .lss-card,' + DK + S + ' .lss-bar{background:#1b1e21}',
      DK + S + ' .lss-bar{border-color:#2c312d}',
      DK + S + ' .lss-btitle{color:#f2f4ee}',
      DK + S + ' .lss-x{background:#262b30;color:#e5e7e1}'
    ].join('\n');
    var s = document.createElement('style'); s.id = STYLE_ID; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  /* scope a stylesheet so the page's CSS only affects the sheet (verbatim from
   * practice-sheet.js, + @layer): prefix every selector with the scope; html/
   * body/:root land on the sheet's scroll body (NOT the fixed sheet root — a
   * scoped body{background} would paint over the dim backdrop); @media/
   * @supports/@layer recurse; @keyframes/@font-face copied. */
  function scopeCss(css, scope) {
    css = css.replace(/\/\*[\s\S]*?\*\//g, '');
    var out = '', i = 0, n = css.length;
    function scopeSel(list) {
      return list.split(',').map(function (s) {
        s = s.trim(); if (!s) return s;
        var m = s.match(/^(html|body|:root)\b/i);
        if (m) { var rest = s.slice(m[0].length).trim(); return scope + ' .lss-body' + (rest ? ' ' + rest : ''); }
        return scope + ' ' + s;
      }).join(',');
    }
    while (i < n) {
      var at = css.indexOf('@', i), brace = css.indexOf('{', i);
      if (brace === -1) break;
      if (at !== -1 && at < brace) {
        var atEnd = css.indexOf('{', at);
        // @layer is FLATTENED, not preserved: layered rules lose to ANY unlayered
        // rule, and the host page's global CSS (hub link colors etc.) is unlayered
        // — keeping the bundle's layers let the host repaint the sponsor design
        // (green underlined CTA text). Flattened + #id-scoped, the design wins on
        // specificity. Statement form ("@layer a, b;") is dropped; block form
        // recurses with the wrapper removed (source order ≈ layer order in the
        // generated bundle).
        var semi = css.indexOf(';', at);
        if (/^@layer/i.test(css.slice(at, at + 7)) && semi !== -1 && (atEnd === -1 || semi < atEnd)) {
          i = semi + 1; continue;
        }
        var prelude = css.slice(at, atEnd).trim();
        if (/^@(media|supports|layer)/i.test(prelude)) {
          var depth = 1, j = atEnd + 1, start = j;
          while (j < n && depth > 0) { if (css[j] === '{') depth++; else if (css[j] === '}') depth--; j++; }
          var inner = scopeCss(css.slice(start, j - 1), scope);
          out += /^@layer/i.test(prelude) ? inner : (prelude + '{' + inner + '}');
          i = j; continue;
        }
        if (/^@(import|charset)/i.test(prelude)) { var s2 = css.indexOf(';', at); out += css.slice(at, s2 + 1); i = s2 + 1; continue; }
        if (/^@(font-face|keyframes|-webkit-keyframes|page)/i.test(prelude)) {
          var d2 = 1, k = atEnd + 1; while (k < n && d2 > 0) { if (css[k] === '{') d2++; else if (css[k] === '}') d2--; k++; }
          out += css.slice(at, k); i = k; continue;
        }
      }
      var sel = css.slice(i, brace), end = css.indexOf('}', brace);
      if (end === -1) break;
      out += scopeSel(sel) + '{' + css.slice(brace + 1, end) + '}';
      i = end + 1;
    }
    return out;
  }

  // fetched-stylesheet cache (href → Promise<string>) so re-opens are instant
  var cssFetchCache = {};
  function fetchCss(href) {
    if (!cssFetchCache[href]) {
      cssFetchCache[href] = fetch(href, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : ''; })
        .catch(function () { return ''; });
    }
    return cssFetchCache[href];
  }

  var lastFocus = null;
  var sheetHist = false;
  function buildSheet() {
    var sheet = document.getElementById(SHEET_ID);
    if (sheet) return sheet;
    ensureStyle();
    sheet = document.createElement('div');
    sheet.id = SHEET_ID; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML =
      '<div class="lss-back"></div>' +
      '<div class="lss-card">' +
        '<div class="lss-bar">' +
          '<span class="lss-grab"></span>' +
          '<button type="button" class="lss-x" aria-label="Close">✕</button>' +
          '<span class="lss-btitle"></span>' +
        '</div>' +
        '<div class="lss-body"></div>' +
      '</div>';
    document.body.appendChild(sheet);
    // One close path (✕ / backdrop / drag / Esc / back-gesture) so history stays balanced.
    function close(fromPop) {
      if (!sheet.classList.contains('is-open')) return;
      sheet.classList.remove('is-open');
      // Drop any drag-leftover inline transform/transition (v4): an inline
      // translateY from a body-drag dismiss otherwise outlives the close,
      // freezes the slide-down, and leaves the NEXT open stuck partway down.
      var c = sheet.querySelector('.lss-card');
      c.style.transition = ''; c.style.transform = '';
      document.documentElement.style.overflow = '';
      if (sheetHist && fromPop !== true) { sheetHist = false; try { history.back(); } catch (e) {} }
      else { sheetHist = false; }
      if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    sheet.querySelector('.lss-back').addEventListener('click', function () { close(); });
    sheet.querySelector('.lss-x').addEventListener('click', function () { close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sheet.classList.contains('is-open')) close(); });
    window.addEventListener('popstate', function () { close(true); });
    // Pull-down-to-dismiss: from the bar anytime; from the scroll body only at
    // scrollTop 0, axis-locked so carousel swipes don't drag the card.
    var card = sheet.querySelector('.lss-card'), body = sheet.querySelector('.lss-body');
    function attachDrag(el, atTop) {
      var sy = null, sx = null, dy = 0, pulling = false;
      el.addEventListener('touchstart', function (e) {
        if (atTop && !atTop()) { pulling = false; return; }
        sy = e.touches[0].clientY; sx = e.touches[0].clientX; dy = 0; pulling = true;
        card.style.transition = 'none';
      }, { passive: true });
      el.addEventListener('touchmove', function (e) {
        if (!pulling || sy === null) return;
        dy = e.touches[0].clientY - sy;
        var dx = e.touches[0].clientX - sx;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) { card.style.transform = ''; pulling = false; return; }
        if (dy > 0 && (!atTop || atTop())) { card.style.transform = 'translateY(' + dy + 'px)'; if (e.cancelable) e.preventDefault(); }
        else { card.style.transform = ''; pulling = false; }
      }, { passive: false });
      el.addEventListener('touchend', function () {
        if (!pulling) return; pulling = false; card.style.transition = ''; sy = null;
        if (dy > 110) close(); else card.style.transform = '';
      });
    }
    attachDrag(sheet.querySelector('.lss-bar'), null);
    // <= 1, not <= 0: phones often rest at a fractional scrollTop (0.5-1px)
    // after momentum scrolling, which made the overscroll gesture dead on
    // real devices while passing in synthetic tests (v4).
    attachDrag(body, function () { return body.scrollTop <= 1; });
    // Stripped-JS fallbacks inside fetched content (scripts are removed on extract):
    // YouTube facade → swap in the real embed; carousel arrows → scroll the track.
    body.addEventListener('click', function (e) {
      var fac = e.target.closest && e.target.closest('.lg-embed__facade[data-yt-id]');
      if (fac && !fac.querySelector('iframe')) {
        e.preventDefault();
        var id = fac.getAttribute('data-yt-id') || '';
        var st = parseInt(fac.getAttribute('data-yt-start') || '0', 10) || 0;
        if (/^[\w-]{6,20}$/.test(id)) {
          var ifr = document.createElement('iframe');
          ifr.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&playsinline=1&rel=0' + (st ? '&start=' + st : '');
          ifr.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
          ifr.allowFullscreen = true;
          fac.innerHTML = ''; fac.appendChild(ifr);
        }
        return;
      }
      var nav = e.target.closest && e.target.closest('[class*="__nav--prev"],[class*="__nav--next"]');
      if (nav) {
        e.preventDefault();
        var scope2 = nav.closest('section') || nav.parentElement;
        var track = scope2 && (scope2.querySelector('[class*="__track"]') ||
          (scope2.parentElement && scope2.parentElement.querySelector('[class*="__track"]')));
        if (track) {
          var dir = /__nav--prev/.test(nav.className) ? -1 : 1;
          track.scrollBy({ left: dir * Math.round(track.clientWidth * 0.85), behavior: 'smooth' });
        }
      }
    });
    sheet._close = close;
    return sheet;
  }

  var inflight = 0;
  function openSponsorSheet(url, fallbackTitle, _retry) {
    lastFocus = document.activeElement;
    var sheet = buildSheet();
    var body = sheet.querySelector('.lss-body');
    var bt = sheet.querySelector('.lss-btitle');
    bt.textContent = fallbackTitle || 'Sponsors';
    body.innerHTML = '<div class="lss-load"><div class="lss-spin"></div>Loading…</div>';
    var wasOpen = sheet.classList.contains('is-open');
    sheet.classList.add('is-open');
    document.documentElement.style.overflow = 'hidden';
    // Phone back-gesture closes the sheet instead of leaving the page. One
    // pushState per sheet — in-sheet sponsor swaps reuse it.
    if (!wasOpen && !sheetHist) { try { history.pushState({ lgSpon: 1 }, ''); sheetHist = true; } catch (e) {} }
    var token = ++inflight;
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        if (token !== inflight) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var node = doc.querySelector('main.lg-standalone-main') || doc.querySelector('main.lg-content-page') ||
                   doc.querySelector('#lg-main') || doc.querySelector('#main') ||
                   doc.querySelector('.entry-content') || doc.querySelector('article') || doc.body;
        if (!node) throw new Error('no content');
        var isV2 = !!((node.matches && node.matches('main.lg-standalone-main')) ||
                      node.querySelector('main.lg-standalone-main'));
        node.removeAttribute('id');
        node.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        node.querySelectorAll('script,noscript').forEach(function (n) { n.remove(); });
        // body fallback safety: never show the site chrome inside the sheet
        node.querySelectorAll('.lg-chrome,.lg-chrome-foot,.lg-standalone-dock,.lg-cmodal').forEach(function (n) { n.remove(); });
        // v2 standalone pages keep their design in LINKED stylesheets — fetch
        // them (cached) and scope alongside the inline <style> blocks. Legacy
        // pages keep the inline-only behavior they've always had.
        var cssJobs = [];
        if (isV2) {
          doc.querySelectorAll('link[rel="stylesheet"][href]').forEach(function (lk) {
            try {
              var u = new URL(lk.getAttribute('href'), url);
              if (u.origin === location.origin) cssJobs.push(fetchCss(u.href));
            } catch (e2) {}
          });
        }
        var inlineCss = '';
        doc.querySelectorAll('style').forEach(function (st) { inlineCss += '\n' + st.textContent; });
        Promise.all(cssJobs).then(function (linked) {
          if (token !== inflight) return;
          sheet.classList.toggle('lss-v2', isV2);
          var cssText = linked.join('\n') + '\n' + inlineCss;
          var old = document.getElementById(PAGE_CSS_ID); if (old) old.remove();
          if (cssText.trim()) {
            var ps = document.createElement('style'); ps.id = PAGE_CSS_ID;
            ps.textContent = scopeCss(cssText, '#' + SHEET_ID);
            (document.head || document.documentElement).appendChild(ps);
          }
          // keep the layer's own (unlayered) fit/dark rules after the page CSS
          var own = document.getElementById(STYLE_ID);
          if (own && own.parentNode) own.parentNode.appendChild(own);
          var h = node.querySelector('.lg-brand-hero__name') || node.querySelector('h1, .entry-title, h2');
          var t = (h && h.textContent.trim()) || (doc.title || '').replace(/\s*[—|\-]\s*The Looth Group.*$/i, '').trim();
          if (t) bt.textContent = t;
          body.innerHTML = '';
          body.appendChild(node);
          body.scrollTop = 0;
        });
      })
      .catch(function () {
        if (token !== inflight) return;
        // one silent retry — a transient fetch hiccup surfaced this error to a
        // user during the 2026-06-12 sponsor sweep; phones flake more than dev
        if (!_retry) {
          setTimeout(function () {
            if (token !== inflight) return;
            openSponsorSheet(url, fallbackTitle, true);
          }, 600);
          return;
        }
        body.innerHTML = '<div class="lss-load">Couldn’t load that.<br><a href="' + url +
          '" style="color:var(--lg-sage-d,#6b7c52);font-weight:700">Open the full page</a></div>';
      });
  }
  window.openSponsorSheet = openSponsorSheet;   // let other layers reuse it

  /* ---- wiring: intercept sponsor links site-wide (capture phase) ---- */
  document.addEventListener('click', function (e) {
    if (!MOBILE.matches) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') return;
    var path, full;
    try { var u = new URL(href, location.href); path = u.pathname; full = u.href; } catch (err) { return; }
    // Sponsors nav (the list) — exact /sponsors/
    if (/^\/sponsors\/?$/.test(path)) {
      e.preventDefault(); e.stopPropagation();
      openSponsorSheet(full, 'Our Sponsors');
      return;
    }
    // individual sponsor — /sponsor-page/<slug>/ or /sponsors/<slug>/
    var m = path.match(/^\/(?:sponsor-page|sponsors)\/([^/?#]+)\/?$/);
    if (m && m[1]) {
      e.preventDefault(); e.stopPropagation();
      openSponsorSheet(full, '');
      return;
    }
  }, true);

})();
