/* practice-sheet.js — Looth PWA (mobile only)
 *
 * Tapping a business/practice link (the .lg-bizpill on a profile, a
 * .practice-row in a public profile, a .pr-name link in the directory render,
 * or any /p/<slug> anchor anywhere) opens the practice in an elegant app-style
 * bottom sheet instead of navigating to the desktop /p/ page.
 *
 * Sibling of /profile-sheet.js — same scaffold, scopeCss mini-parser, Leaflet
 * map re-init, gallery carousel re-init, dark-mode overrides, and capture-phase
 * link interceptor. Differences vs the profile sheet:
 *   - fetches /p/<slug>?view=member (the clean members render; for the owner,
 *     ?view=member turns off the builder + View-as chrome — p.php honors
 *     public|member|me exactly like u.php).
 *   - extracts the same canonical .lg-profile node (p.php wraps its blocks in
 *     .lg-profile, parallel to u.php), scopes the page's two <style> blocks to
 *     THIS sheet's id, strips owner/builder chrome, and re-inits the shared
 *     .lg-loc__map / .lg-dropoffs__map / .lg-carousel widgets (the practice page
 *     renders through the SAME _render_blocks.php as profiles, so the map +
 *     carousel markup is byte-identical).
 *   - the members-gate (.lg-gate) is a first-class state: a logged-out / non-
 *     member viewer gets the gate inside .lg-profile, and the sheet shows it as-is.
 *   - top-bar identity comes from .lg-idrow__name + .lg-idrow__pic (practice
 *     header card).
 *
 * Loaded site-wide via /pwa.js. Self-gates to <=640px so desktop keeps the
 * native full-page practice untouched. Touches nothing on the canonical tree.
 */
(function () {
  'use strict';
  if (window.__lgPracSheet) return;
  window.__lgPracSheet = true;
  var MOBILE = window.matchMedia('(max-width:640px)');
  if (!MOBILE.matches) return;                 // desktop: do nothing at all

  // Don't run inside an embedded iframe (defensive; we don't use one yet).
  try { if (window.top !== window.self) return; } catch (e) {}

  var SHEET_ID = 'looth-prac-sheet';
  var STYLE_ID = 'looth-prac-style';
  var PAGE_CSS_ID = 'looth-prac-pagecss';
  var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

  /* ---- frame CSS (the app shell around the canonical practice page) ---- */
  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var css = [
      '#' + SHEET_ID + '{position:fixed;inset:0;z-index:2147483520;display:none}',
      '#' + SHEET_ID + '.is-open{display:block}',
      '#' + SHEET_ID + ' .lps-back{position:absolute;inset:0;background:rgba(26,29,26,.55);opacity:0;transition:opacity .2s}',
      '#' + SHEET_ID + '.is-open .lps-back{opacity:1}',
      '#' + SHEET_ID + ' .lps-card{position:absolute;left:0;right:0;bottom:0;top:34px;display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;overflow:hidden;' +
        'box-shadow:0 -10px 34px rgba(26,29,26,.34);transform:translateY(100%);transition:transform .28s cubic-bezier(.2,.7,.2,1)}',
      '#' + SHEET_ID + '.is-open .lps-card{transform:translateY(0)}',
      // sticky top bar
      '#' + SHEET_ID + ' .lps-bar{flex:0 0 auto;position:relative;display:flex;align-items:center;gap:10px;' +
        'padding:10px 12px;background:var(--lg-cream,#fbfbf8);border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '#' + SHEET_ID + ' .lps-grab{position:absolute;top:5px;left:50%;transform:translateX(-50%);width:38px;height:4px;border-radius:999px;background:var(--lg-line,#e3ddd0)}',
      '#' + SHEET_ID + ' .lps-x{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-charcoal,#1a1d1a);font-size:21px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center}',
      // practice avatar in the bar is a SQUARE (matches .lg-idrow__pic radius), not a circle
      '#' + SHEET_ID + ' .lps-bavi{flex:0 0 auto;width:30px;height:30px;border-radius:8px;overflow:hidden;background:var(--lg-sage,#87986a);display:none}',
      '#' + SHEET_ID + ' .lps-bavi img{width:100%;height:100%;object-fit:cover;display:block}',
      '#' + SHEET_ID + ' .lps-btitle{flex:1 1 auto;min-width:0;font:700 16px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:0;transition:opacity .2s}',
      '#' + SHEET_ID + ' .lps-btitle.show{opacity:1}',
      // scroll body
      '#' + SHEET_ID + ' .lps-body{flex:1 1 auto;overflow:auto;-webkit-overflow-scrolling:touch;padding:0 0 28px}',
      '#' + SHEET_ID + ' .lps-load{padding:46px 0;text-align:center;color:var(--lg-mute,#6b6f6b);font:600 14px/1 var(--lg-font-sans,system-ui)}',
      '#' + SHEET_ID + ' .lps-spin{width:26px;height:26px;margin:0 auto 12px;border:3px solid var(--lg-sage-tint,#eef2e3);border-top-color:var(--lg-sage,#87986a);border-radius:50%;animation:lps-spin .8s linear infinite}',
      '@keyframes lps-spin{to{transform:rotate(360deg)}}',
      // the injected canonical practice page lives under .lps-body; trim its page gutters
      '#' + SHEET_ID + ' .lg-profile{max-width:none;margin:0;padding:0 14px}',
      '#' + SHEET_ID + ' .lg-shell{max-width:none;margin:0;padding:0;gap:0}',
      '#' + SHEET_ID + ' .lg-loc__map,#' + SHEET_ID + ' .lg-dropoffs__map{height:190px;border-radius:14px;overflow:hidden}',
      // owner/builder chrome that might slip through — belt & suspenders
      '#' + SHEET_ID + ' .lg-viewas,#' + SHEET_ID + ' .lg-caddy,#' + SHEET_ID + ' .lg-caddy__backdrop,' +
        '#' + SHEET_ID + ' .lg-viewas__caddy,#' + SHEET_ID + ' .lg-block__grip,#' + SHEET_ID + ' .lg-block__rm,' +
        '#' + SHEET_ID + ' .lg-secic,#' + SHEET_ID + ' .lg-pmp__caret,#' + SHEET_ID + ' .lg-pmp-menu,' +
        '#' + SHEET_ID + ' .lg-link__add,#' + SHEET_ID + ' .lg-link__rm,#' + SHEET_ID + ' .lg-gphoto__add,' +
        '#' + SHEET_ID + ' .lg-gphoto__rm,#' + SHEET_ID + ' [draggable="true"]{display:none!important}',
      // .lg-pmp is the per-block visibility control button; in visitor render it is
      // not present, but if it slips through, neutralise its button affordance.
      '#' + SHEET_ID + ' .lg-pmp{pointer-events:none}',
      // dark mode: app-settings sets data-lguser-theme on <html>; honor it
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-card,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-bar{background:#1b1e21}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-bar{border-color:#2c312d}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-btitle{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-x{background:#262b30;color:#e5e7e1}',
      // dark: p.php block surfaces are hardcoded #fff/cream while the text tokens go
      // light → unreadable. Darken the injected block cards + soft fills to match the app.
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-block{background:#1e2124!important;border-color:#2c312d!important}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-dropoff,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-staff__lnk:hover,' +
        'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-link--edit,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-hours__row{background:#262b30!important;border-color:#2c312d!important;color:#e5e7e1!important}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-gate{background:#1e2124!important;border-color:#2c312d!important}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-staff__name,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-dropoff__name,' +
        'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-hours__val,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-about{color:#e5e7e1!important}'
    ].join('\n');
    var s = document.createElement('style');
    s.id = STYLE_ID; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  /* ---- scope a stylesheet so the page's block CSS only affects the sheet ----
   * Prefixes every selector with the sheet scope; leading html/body/:root become
   * the scope itself; @media/@supports recurse; @keyframes/@font-face copied as-is. */
  function scopeCss(css, scope) {
    css = css.replace(/\/\*[\s\S]*?\*\//g, '');
    var out = '', i = 0, n = css.length;
    function scopeSel(list) {
      return list.split(',').map(function (s) {
        s = s.trim(); if (!s) return s;
        var m = s.match(/^(html|body|:root)\b/i);
        if (m) { var rest = s.slice(m[0].length).trim(); return scope + (rest ? ' ' + rest : ''); }
        return scope + ' ' + s;
      }).join(',');
    }
    while (i < n) {
      var at = css.indexOf('@', i), brace = css.indexOf('{', i);
      if (brace === -1) break;
      if (at !== -1 && at < brace) {
        var atEnd = css.indexOf('{', at);
        var prelude = css.slice(at, atEnd).trim();
        if (/^@(media|supports)/i.test(prelude)) {
          var depth = 1, j = atEnd + 1, start = j;
          while (j < n && depth > 0) { if (css[j] === '{') depth++; else if (css[j] === '}') depth--; j++; }
          out += prelude + '{' + scopeCss(css.slice(start, j - 1), scope) + '}';
          i = j; continue;
        }
        if (/^@(import|charset)/i.test(prelude)) { var semi = css.indexOf(';', at); out += css.slice(at, semi + 1); i = semi + 1; continue; }
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

  /* ---- Leaflet on demand (the page assumes L is already global) ---- */
  var leafletReady = null;
  function ensureLeaflet() {
    if (leafletReady) return leafletReady;
    leafletReady = new Promise(function (resolve) {
      if (window.L) { resolve(window.L); return; }
      if (!document.querySelector('link[data-lps-leaflet]')) {
        var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = LEAFLET_CSS; l.setAttribute('data-lps-leaflet', '1');
        document.head.appendChild(l);
      }
      var sc = document.createElement('script'); sc.src = LEAFLET_JS; sc.async = true;
      sc.onload = function () { resolve(window.L); };
      sc.onerror = function () { resolve(null); };
      document.head.appendChild(sc);
    });
    return leafletReady;
  }

  /* ---- re-init maps + carousels inside an injected container (shared with the
   * profile sheet — the practice page emits the SAME map/carousel markup). ---- */
  function initMaps(root) {
    var locs = root.querySelectorAll('.lg-loc__map[data-lat]');
    var drops = root.querySelectorAll('.lg-dropoffs__map[data-pins]');
    if (!locs.length && !drops.length) return;
    ensureLeaflet().then(function (L) {
      if (!L) return;
      locs.forEach(function (el) {
        var lat = parseFloat(el.getAttribute('data-lat')), lng = parseFloat(el.getAttribute('data-lng'));
        if (isNaN(lat) || isNaN(lng)) return;
        var exact = el.getAttribute('data-kind') === 'exact';
        var zoom = parseInt(el.getAttribute('data-zoom'), 10) || (exact ? 15 : 11);
        try {
          var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], zoom);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
          if (exact) L.marker([lat, lng]).addTo(map);
          else L.circle([lat, lng], { radius: zoom <= 8 ? 35000 : 1500, color: '#87986a', fillColor: '#87986a', fillOpacity: .18, weight: 1 }).addTo(map);
          setTimeout(function () { map.invalidateSize(); }, 90);
        } catch (e) {}
      });
      drops.forEach(function (el) {
        var pins; try { pins = JSON.parse(el.getAttribute('data-pins')); } catch (e) { return; }
        if (!Array.isArray(pins) || !pins.length) return;
        var esc = function (v) { var d = document.createElement('div'); d.textContent = (v == null) ? '' : String(v); return d.innerHTML; };
        try {
          var map = L.map(el, { scrollWheelZoom: false }).setView([pins[0].lat, pins[0].lng], 11);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
          var markers = [];
          pins.forEach(function (p) {
            if (typeof p.lat !== 'number' || typeof p.lng !== 'number') return;
            var html = '<div class="lg-pinpop">' + (p.n ? '<strong>' + esc(p.n) + '</strong>' : '') + (p.a ? '<div>' + esc(p.a) + '</div>' : '') +
              (p.h ? '<div>' + esc(p.h) + '</div>' : '') + (p.no ? '<div>' + esc(p.no).replace(/\n/g, '<br>') + '</div>' : '') + '</div>';
            markers.push(L.marker([p.lat, p.lng]).addTo(map).bindPopup(html));
          });
          if (markers.length > 1) map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2));
          else if (markers.length === 1) map.setView(markers[0].getLatLng(), 14);
          setTimeout(function () { map.invalidateSize(); }, 90);
        } catch (e) {}
      });
    });
  }

  function initCarousels(root) {
    root.querySelectorAll('.lg-carousel').forEach(function (car) {
      var track = car.querySelector('.lg-carousel__track');
      if (!track) return;
      var slides = track.querySelectorAll('.lg-gphoto, .lg-gphoto__add');
      if (!slides.length) return;
      var prev = car.querySelector('.lg-carousel__nav--prev'), next = car.querySelector('.lg-carousel__nav--next');
      var dots = car.querySelectorAll('.lg-carousel__dot'), idx = 0;
      function go(n) {
        idx = Math.max(0, Math.min(slides.length - 1, n));
        track.style.transform = 'translateX(-' + (idx * 100) + '%)';
        if (prev) prev.disabled = (idx === 0);
        if (next) next.disabled = (idx === slides.length - 1);
        dots.forEach(function (d, i) { d.setAttribute('aria-current', i === idx ? 'true' : 'false'); });
      }
      prev && prev.addEventListener('click', function () { go(idx - 1); });
      next && next.addEventListener('click', function () { go(idx + 1); });
      dots.forEach(function (d, i) { d.addEventListener('click', function () { go(i); }); });
      var sx = null;
      track.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
      track.addEventListener('touchend', function (e) { if (sx === null) return; var dx = e.changedTouches[0].clientX - sx; sx = null; if (Math.abs(dx) > 50) go(idx + (dx < 0 ? 1 : -1)); });
      go(0);
    });
  }

  /* ---- the sheet ---- */
  var lastFocus = null;
  function buildSheet() {
    var sheet = document.getElementById(SHEET_ID);
    if (sheet) return sheet;
    ensureStyle();
    sheet = document.createElement('div');
    sheet.id = SHEET_ID; sheet.setAttribute('role', 'dialog'); sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML =
      '<div class="lps-back"></div>' +
      '<div class="lps-card">' +
        '<div class="lps-bar">' +
          '<span class="lps-grab"></span>' +
          '<button type="button" class="lps-x" aria-label="Close">✕</button>' +
          '<span class="lps-bavi"><img alt=""></span>' +
          '<span class="lps-btitle"></span>' +
        '</div>' +
        '<div class="lps-body"></div>' +
      '</div>';
    document.body.appendChild(sheet);
    sheet.querySelector('.lps-back').addEventListener('click', closeSheet);
    sheet.querySelector('.lps-x').addEventListener('click', closeSheet);
    // drag the bar down to dismiss
    wireDrag(sheet);
    // show the name in the bar once the user scrolls past the big header
    sheet.querySelector('.lps-body').addEventListener('scroll', function () {
      var t = sheet.querySelector('.lps-btitle'), a = sheet.querySelector('.lps-bavi');
      var on = this.scrollTop > 96;
      if (t) t.classList.toggle('show', on);
      if (a) a.style.display = on ? 'block' : 'none';
    });
    return sheet;
  }

  // Drag the card down to dismiss: from the header bar (anywhere), OR by overscroll —
  // pulling DOWN once the body is scrolled to the very top (Buck 2026-06-09: every pull-up sheet).
  function wireDrag(sheet) {
    var bar = sheet.querySelector('.lps-bar'), card = sheet.querySelector('.lps-card');
    var body = sheet.querySelector('.lps-body');
    function attach(el, atTop) {
      var sy = null, dy = 0, pulling = false;
      el.addEventListener('touchstart', function (e) {
        if (atTop && !atTop()) { pulling = false; return; }
        sy = e.touches[0].clientY; dy = 0; pulling = true; card.style.transition = 'none';
      }, { passive: true });
      el.addEventListener('touchmove', function (e) {
        if (!pulling || sy === null) return;
        dy = e.touches[0].clientY - sy;
        if (dy > 0 && (!atTop || atTop())) { card.style.transform = 'translateY(' + dy + 'px)'; if (e.cancelable) e.preventDefault(); }
        else { card.style.transform = ''; pulling = false; }
      }, { passive: false });
      el.addEventListener('touchend', function () {
        if (!pulling) return; pulling = false; card.style.transition = ''; sy = null;
        if (dy > 110) closeSheet(); else card.style.transform = '';
      });
    }
    attach(bar, null);
    if (body) attach(body, function () { return body.scrollTop <= 0; });
  }

  function openSheet() {
    var sheet = buildSheet();
    lastFocus = document.activeElement;
    document.body.style.overflow = 'hidden';
    sheet.classList.add('is-open');
  }
  function closeSheet() {
    var sheet = document.getElementById(SHEET_ID);
    if (!sheet) return;
    sheet.classList.remove('is-open');
    document.body.style.overflow = '';
    var card = sheet.querySelector('.lps-card'); if (card) card.style.transform = '';
    try { lastFocus && lastFocus.focus && lastFocus.focus(); } catch (e) {}
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { var s = document.getElementById(SHEET_ID); if (s && s.classList.contains('is-open')) closeSheet(); }
  });

  var inflight = null;
  function openPracticeSheet(slug) {
    if (!slug) return;
    slug = String(slug).replace(/^\/?p\//, '').split(/[?#/]/)[0];
    if (!slug) return;
    openSheet();
    var sheet = document.getElementById(SHEET_ID);
    var body = sheet.querySelector('.lps-body');
    body.scrollTop = 0;
    body.innerHTML = '<div class="lps-load"><div class="lps-spin"></div>Loading business…</div>';
    sheet.querySelector('.lps-btitle').classList.remove('show');
    sheet.querySelector('.lps-bavi').style.display = 'none';
    var token = {}; inflight = token;
    fetch('/p/' + encodeURIComponent(slug) + '?view=member', { credentials: 'include' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        if (inflight !== token) return;               // superseded by a newer open
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var prof = doc.querySelector('.lg-profile');
        if (!prof) throw new Error('no-practice');
        // strip owner/builder chrome + drop ids to avoid collisions with the page
        prof.querySelectorAll('.lg-viewas,.lg-caddy,.lg-caddy__backdrop,.lg-viewas__caddy,.lg-block__grip,.lg-block__rm,.lg-secic,.lg-pmp-menu,.lg-link__add,.lg-link__rm,.lg-gphoto__add,.lg-gphoto__rm,[data-freeform-new],script').forEach(function (n) { n.remove(); });
        prof.querySelectorAll('[draggable="true"]').forEach(function (n) { n.removeAttribute('draggable'); });
        prof.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        // pull + scope the page's block CSS once per open (re-inject fresh)
        var old = document.getElementById(PAGE_CSS_ID); if (old) old.remove();
        var cssText = '';
        doc.querySelectorAll('style').forEach(function (st) { cssText += '\n' + st.textContent; });
        if (cssText.trim()) {
          var ps = document.createElement('style'); ps.id = PAGE_CSS_ID;
          ps.textContent = scopeCss(cssText, '#' + SHEET_ID);
          (document.head || document.documentElement).appendChild(ps);
        }
        body.innerHTML = '';
        body.appendChild(prof);
        // top-bar identity from the injected practice header
        var pic = prof.querySelector('.lg-idrow__pic img, .lg-idrow__pic');
        var nm = prof.querySelector('.lg-idrow__name');
        var bt = sheet.querySelector('.lps-btitle'), bavi = sheet.querySelector('.lps-bavi img');
        // .lg-idrow__name may carry a .lg-ptype / .lg-tierpill chip child — take the
        // first text node so the bar shows just the business name.
        if (nm && bt) {
          var nameText = '';
          for (var c = nm.firstChild; c; c = c.nextSibling) { if (c.nodeType === 3) nameText += c.textContent; }
          bt.textContent = (nameText || nm.textContent || '').trim();
        }
        if (bavi) { var src = pic && (pic.tagName === 'IMG' ? pic.src : (pic.querySelector && pic.querySelector('img') && pic.querySelector('img').src)); if (src) bavi.src = src; }
        initMaps(prof);
        initCarousels(prof);
      })
      .catch(function () {
        if (inflight !== token) return;
        body.innerHTML = '<div class="lps-load">Couldn’t load this business.<br><a href="/p/' + encodeURIComponent(slug) + '" style="color:var(--lg-sage-d,#6b7c52)">Open the full page</a></div>';
      });
  }
  window.openPracticeSheet = openPracticeSheet;       // let other layers call it

  /* ---- wiring: intercept practice links site-wide (capture phase) ---- */
  document.addEventListener('click', function (e) {
    if (!MOBILE.matches) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    // any /p/<slug> link anywhere (bizpill on a profile, .practice-row in a public
    // profile, .pr-name link in the directory render, mentions). Accept slug or
    // numeric id (p.php resolves both).
    var m = href.match(/^\/p\/([^/?#]+)/);
    if (m) {
      e.preventDefault(); e.stopPropagation();
      openPracticeSheet(m[1]);
    }
  }, true);

})();
