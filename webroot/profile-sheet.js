/* profile-sheet.js — Looth PWA (mobile only)
 *
 * Tapping a profile link (a member in the directory, a Hub author, or "View
 * profile" in the You sheet) opens the person's profile in an elegant app-style
 * bottom sheet instead of navigating to the desktop /u/ page.
 *
 * Strategy: fetch /u/<slug>?view=member (the clean VISITOR render — for the
 * owner, ?view=member turns off the inline editor + the View-as chrome), extract
 * the canonical .lg-profile node + the page's block CSS (scoped to the sheet),
 * strip any leftover owner chrome, and re-init the Leaflet maps + gallery
 * carousel (the page's own hydration scripts don't come across with the node).
 *
 * Loaded site-wide via /pwa.js. Self-gates to <=640px so desktop keeps the
 * native full-page profile untouched. Bumps nothing on the canonical tree.
 */
(function () {
  'use strict';
  if (window.__lgProfSheet) return;
  window.__lgProfSheet = true;
  var MOBILE = window.matchMedia('(max-width:640px)');
  if (!MOBILE.matches) return;                 // desktop: do nothing at all

  // Don't run inside an embedded profile iframe (defensive; we don't use one yet).
  try { if (window.top !== window.self) return; } catch (e) {}

  var SHEET_ID = 'looth-prof-sheet';
  var STYLE_ID = 'looth-prof-style';
  var PAGE_CSS_ID = 'looth-prof-pagecss';
  var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  var LEAFLET_JS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';

  /* ---- frame CSS (the app shell around the canonical profile) ---- */
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
      '#' + SHEET_ID + ' .lps-bavi{flex:0 0 auto;width:30px;height:30px;border-radius:50%;overflow:hidden;background:var(--lg-sage-tint,#eef2e3);display:none}',
      '#' + SHEET_ID + ' .lps-bavi img{width:100%;height:100%;object-fit:cover;display:block}',
      '#' + SHEET_ID + ' .lps-btitle{flex:1 1 auto;min-width:0;font:700 16px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;opacity:0;transition:opacity .2s}',
      '#' + SHEET_ID + ' .lps-btitle.show{opacity:1}',
      // scroll body
      '#' + SHEET_ID + ' .lps-body{flex:1 1 auto;overflow:auto;-webkit-overflow-scrolling:touch;padding:0 0 28px}',
      '#' + SHEET_ID + ' .lps-load{padding:46px 0;text-align:center;color:var(--lg-mute,#6b6f6b);font:600 14px/1 var(--lg-font-sans,system-ui)}',
      '#' + SHEET_ID + ' .lps-spin{width:26px;height:26px;margin:0 auto 12px;border:3px solid var(--lg-sage-tint,#eef2e3);border-top-color:var(--lg-sage,#87986a);border-radius:50%;animation:lps-spin .8s linear infinite}',
      '@keyframes lps-spin{to{transform:rotate(360deg)}}',
      // the injected canonical profile lives under .lps-body; trim its page gutters
      '#' + SHEET_ID + ' .lg-profile{max-width:none;margin:0;padding:0 14px}',
      '#' + SHEET_ID + ' .lg-loc__map,#' + SHEET_ID + ' .lg-dropoffs__map{height:190px;border-radius:14px;overflow:hidden}',
      // owner chrome that might slip through — belt & suspenders
      '#' + SHEET_ID + ' .lg-viewas,#' + SHEET_ID + ' .lg-caddy,#' + SHEET_ID + ' .lg-caddy__backdrop,' +
        '#' + SHEET_ID + ' .lg-freeform-add,#' + SHEET_ID + ' .lg-report,#' + SHEET_ID + ' [data-freeform-new],' +
        '#' + SHEET_ID + ' .lg-block__addbtn{display:none!important}',
      // dark mode: app-settings sets data-lguser-theme on <html>; honor it
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-card,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-bar{background:#1b1e21}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-bar{border-color:#2c312d}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-btitle{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-x{background:#262b30;color:#e5e7e1}',
      // dark: u.php block surfaces are hardcoded #fff/cream while the text tokens go
      // light → unreadable. Darken the injected block cards + soft fills to match the app.
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-block{background:#1e2124!important;border-color:#2c312d!important}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-chip,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-link,' +
        'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-loc__f,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-dropoff,' +
        'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-bizpill{background:#262b30!important;border-color:#2c312d!important;color:#e5e7e1!important}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-gate,html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lg-gmode{background:#1e2124!important;border-color:#2c312d!important}',
      // "Edit profile" pill (owner-only; revealed by JS when the open profile is mine)
      '#' + SHEET_ID + ' .lps-edit{flex:0 0 auto;margin-left:auto;border:0;border-radius:999px;padding:8px 14px;' +
        'background:var(--lg-sage,#87986a);color:#fff;font:600 13px/1 var(--lg-font-sans,system-ui);cursor:pointer}',
      '#' + SHEET_ID + ' .lps-edit[hidden]{display:none}',
      'html[data-lguser-theme="dark"] #' + SHEET_ID + ' .lps-edit{background:var(--lg-sage-d,#6b7c52)}',
      // the embedded-editor sheet (its own near-fullscreen layer)
      '#looth-prof-edit{position:fixed;inset:0;z-index:2147483540;display:none}',
      '#looth-prof-edit.is-open{display:block}',
      '#looth-prof-edit .lpe-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}',
      '#looth-prof-edit .lpe-card{position:absolute;left:0;right:0;bottom:0;top:0;display:flex;flex-direction:column;background:var(--lg-cream,#fbfbf8)}',
      '#looth-prof-edit .lpe-bar{flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--lg-cream,#fbfbf8);border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '#looth-prof-edit .lpe-ttl{flex:1 1 auto;font:700 16px/1.2 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '#looth-prof-edit .lpe-done{flex:0 0 auto;border:0;border-radius:999px;padding:9px 18px;background:var(--lg-sage,#87986a);color:#fff;font:600 14px/1 var(--lg-font-sans,system-ui);cursor:pointer}',
      '#looth-prof-edit .lpe-frame{flex:1 1 auto;width:100%;border:0;display:block;background:var(--lg-cream,#fbfbf8)}',
      'html[data-lguser-theme="dark"] #looth-prof-edit .lpe-card,html[data-lguser-theme="dark"] #looth-prof-edit .lpe-bar{background:#1b1e21}',
      'html[data-lguser-theme="dark"] #looth-prof-edit .lpe-ttl{color:#f2f4ee}'
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

  /* ---- re-init maps + carousels inside an injected container ---- */
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
          '<button type="button" class="lps-edit" hidden>Edit profile</button>' +
        '</div>' +
        '<div class="lps-body"></div>' +
      '</div>';
    document.body.appendChild(sheet);
    sheet.querySelector('.lps-back').addEventListener('click', closeSheet);
    sheet.querySelector('.lps-x').addEventListener('click', closeSheet);
    sheet.querySelector('.lps-edit').addEventListener('click', function () {
      var slug = sheet.getAttribute('data-lps-slug');
      if (slug) openProfileEdit(slug);
    });
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

  // Drag-to-dismiss from ANYWHERE on the card (Buck 2026-06-12): the header bar
  // anytime; the rest of the card when the body is scrolled to the top. One
  // card-level handler instead of bar+body attaches so no spot is uncovered.
  // On-device lessons from sponsor-sheet v4: the guard is scrollTop <= 1 (real
  // phones rest at fractional offsets after momentum scrolling — a <= 0 guard
  // silently kills the gesture), and an axis-lock so horizontal swipes
  // (galleries, carousels, maps) don't half-drag the card.
  function wireDrag(sheet) {
    var card = sheet.querySelector('.lps-card');
    var body = sheet.querySelector('.lps-body');
    var sy = null, sx = null, dy = 0, pulling = false, fromBody = false;
    function atTop() { return !fromBody || !body || body.scrollTop <= 1; }
    card.addEventListener('touchstart', function (e) {
      fromBody = !!(e.target.closest && e.target.closest('.lps-body'));
      if (!atTop()) { pulling = false; return; }
      sy = e.touches[0].clientY; sx = e.touches[0].clientX; dy = 0; pulling = true;
      card.style.transition = 'none';
    }, { passive: true });
    card.addEventListener('touchmove', function (e) {
      if (!pulling || sy === null) return;
      dy = e.touches[0].clientY - sy;
      var dx = e.touches[0].clientX - sx;
      if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) {
        card.style.transform = ''; card.style.transition = ''; pulling = false; return;
      }
      if (dy > 0 && atTop()) { card.style.transform = 'translateY(' + dy + 'px)'; if (e.cancelable) e.preventDefault(); }
      else { card.style.transform = ''; card.style.transition = ''; pulling = false; }
    }, { passive: false });
    card.addEventListener('touchend', function () {
      if (!pulling) return; pulling = false; card.style.transition = ''; sy = null;
      if (dy > 110) closeSheet(); else card.style.transform = '';
    });
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
    var card = sheet.querySelector('.lps-card'); if (card) { card.style.transform = ''; card.style.transition = ''; }
    try { lastFocus && lastFocus.focus && lastFocus.focus(); } catch (e) {}
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var ed = document.getElementById('looth-prof-edit');
    if (ed && ed.classList.contains('is-open')) { closeProfileEdit(); return; }
    var s = document.getElementById(SHEET_ID); if (s && s.classList.contains('is-open')) closeSheet();
  });

  var inflight = null;
  function openProfileSheet(slug) {
    if (!slug) return;
    slug = String(slug).replace(/^\/?u\//, '').split(/[?#/]/)[0];
    if (!slug) return;
    openSheet();
    var sheet = document.getElementById(SHEET_ID);
    var body = sheet.querySelector('.lps-body');
    body.scrollTop = 0;
    body.innerHTML = '<div class="lps-load"><div class="lps-spin"></div>Loading profile…</div>';
    sheet.querySelector('.lps-btitle').classList.remove('show');
    sheet.querySelector('.lps-bavi').style.display = 'none';
    sheet.setAttribute('data-lps-slug', slug);
    var editBtn0 = sheet.querySelector('.lps-edit'); if (editBtn0) editBtn0.hidden = true;   // hide until ownership confirmed
    var token = {}; inflight = token;
    fetch('/u/' + encodeURIComponent(slug) + '?view=member', { credentials: 'include' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        if (inflight !== token) return;               // superseded by a newer open
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var prof = doc.querySelector('.lg-profile');
        if (!prof) throw new Error('no-profile');
        // strip owner/editor chrome + dead report link, drop ids to avoid collisions
        prof.querySelectorAll('.lg-viewas,.lg-caddy,.lg-caddy__backdrop,.lg-freeform-add,.lg-report,[data-freeform-new],.lg-block__addbtn,.lg-lm-backdrop,script').forEach(function (n) { n.remove(); });
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
        // top-bar identity from the injected header
        var pic = prof.querySelector('.lg-idrow__pic img, .lg-idrow__pic');
        var nm = prof.querySelector('.lg-idrow__name');
        var bt = sheet.querySelector('.lps-btitle'), bavi = sheet.querySelector('.lps-bavi img');
        if (nm && bt) bt.textContent = (nm.textContent || '').trim();
        if (bavi) { var src = pic && (pic.tagName === 'IMG' ? pic.src : (pic.querySelector && pic.querySelector('img') && pic.querySelector('img').src)); if (src) bavi.src = src; }
        initMaps(prof);
        initCarousels(prof);
        // Reveal "Edit profile" only when this is the viewer's OWN profile.
        meSlug().then(function (mine) {
          if (inflight !== token) return;
          if (mine && String(mine).toLowerCase() === slug.toLowerCase()) {
            var eb = sheet.querySelector('.lps-edit'); if (eb) eb.hidden = false;
          }
        });
      })
      .catch(function () {
        if (inflight !== token) return;
        body.innerHTML = '<div class="lps-load">Couldn’t load this profile.<br><a href="/u/' + encodeURIComponent(slug) + '" style="color:var(--lg-sage-d,#6b7c52)">Open the full page</a></div>';
      });
  }
  /* ---- embedded REAL owner editor (iframe of /u/<slug>?view=me) ----
   * The editor JS lives inline in u.php, so it runs inside this same-origin
   * iframe with full inline-edit + visibility controls. pwa.js self-guards inside
   * frames (window.top!==window.self) so NO app chrome nests; the only server-
   * rendered chrome (.lg-chrome header/footer) we hide via injected CSS on load.
   * No ?embed param — verified a no-op on the profile-app. */
  var editEl = null;
  function buildEditSheet() {
    if (editEl) return editEl;
    ensureStyle();
    editEl = document.createElement('div');
    editEl.id = 'looth-prof-edit'; editEl.setAttribute('role', 'dialog'); editEl.setAttribute('aria-modal', 'true');
    editEl.innerHTML =
      '<div class="lpe-back"></div>' +
      '<div class="lpe-card">' +
        '<div class="lpe-bar"><span class="lpe-ttl">Edit profile</span><button type="button" class="lpe-done">Done</button></div>' +
        '<iframe class="lpe-frame" title="Edit your profile"></iframe>' +
      '</div>';
    document.body.appendChild(editEl);
    editEl.querySelector('.lpe-done').addEventListener('click', closeProfileEdit);
    editEl.querySelector('.lpe-back').addEventListener('click', closeProfileEdit);
    return editEl;
  }
  var EDIT_FRAME_CSS =
    '#site-header,.lg-chrome,.lg-chrome-foot{display:none!important}' +
    'body{padding-top:0!important}' +
    '.lg-shell--owner{display:block!important;max-width:none!important;column-gap:0!important}' +
    // KEEP the Sections caddy as the canonical FIXED off-canvas drawer. The old
    // static override pulled the (hidden, 100vh-tall) drawer into the page flow —
    // that was the giant empty gap under the control panel (Buck 2026-06-10).
    '.lg-shell--owner .lg-caddy{position:fixed!important;width:300px!important;max-width:86vw!important}' +
    // privacy controls moved to the pull-up sheet — slim the control panel
    '.lg-viewas__vis{display:none!important}' +
    '.lg-privbtn{background:var(--lg-sage,#87986a);color:#fff;border:0;border-radius:999px;padding:6px 14px;font:800 12px/1 var(--lg-font-sans,system-ui);cursor:pointer}' +
    // ── privacy pull-up sheet (lives inside the editor frame) ──
    '#lg-privsheet{position:fixed;inset:0;z-index:1500;display:none}' +
    '#lg-privsheet.is-open{display:block}' +
    '#lg-privsheet .pv-back{position:absolute;inset:0;background:rgba(20,22,18,.4)}' +
    '#lg-privsheet .pv-card{position:absolute;left:0;right:0;bottom:0;max-height:82vh;overflow:auto;-webkit-overflow-scrolling:touch;' +
      'background:#fff;border-radius:18px 18px 0 0;box-shadow:0 -10px 36px rgba(0,0,0,.25);padding:0 16px calc(22px + env(safe-area-inset-bottom,0px))}' +
    '#lg-privsheet .pv-grab{position:sticky;top:0;height:22px;display:flex;align-items:center;justify-content:center;background:#fff;cursor:grab}' +
    '#lg-privsheet .pv-grab::before{content:"";width:40px;height:5px;border-radius:3px;background:#d8d2c4}' +
    '#lg-privsheet .pv-t{font:700 18px/1.2 var(--lg-font-serif,Georgia,serif);margin:2px 0 3px;color:var(--lg-charcoal,#1a1d1a)}' +
    '#lg-privsheet .pv-sub{font:12.5px/1.45 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b);margin:0 0 12px}' +
    '#lg-privsheet .pv-row{padding:13px 0 11px;border-top:1px solid var(--lg-line,#e3ddd0)}' +
    '#lg-privsheet .pv-row--global{border-top:0;background:var(--lg-sage-tint,#eef2e3);border-radius:14px;padding:13px 14px 11px;margin:0 0 6px}' +
    '#lg-privsheet .pv-lab{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font:700 13.5px/1.2 var(--lg-font-sans,system-ui);color:var(--lg-ink,#323532)}' +
    '#lg-privsheet .pv-cur{flex:0 0 auto;font:700 11.5px/1 var(--lg-font-sans,system-ui);color:var(--lg-sage-d,#6b7c52)}' +
    '#lg-privsheet input[type=range]{display:block;width:100%;margin:10px 0 4px;accent-color:var(--lg-sage,#87986a)}' +
    '#lg-privsheet .pv-ticks{display:flex;justify-content:space-between;font:600 10.5px/1 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b)}' +
    '#lg-privsheet .pv-note{font:11px/1.4 var(--lg-font-sans,system-ui);color:var(--lg-mute,#6b6f6b);margin:7px 0 0}' +
    '#lg-privsheet .pv-row.pv-busy{opacity:.45;pointer-events:none}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-card,html[data-lguser-theme="dark"] #lg-privsheet .pv-grab{background:#1b1e21}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-grab::before{background:#3a403a}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-t{color:#f2f4ee}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-lab{color:#e5e7e1}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-sub,html[data-lguser-theme="dark"] #lg-privsheet .pv-ticks,html[data-lguser-theme="dark"] #lg-privsheet .pv-note{color:#9aa097}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-row{border-top-color:#2c312d}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-row--global{background:#243024}' +
    'html[data-lguser-theme="dark"] #lg-privsheet .pv-cur{color:#9cb37d}' +
    // section Move up/down buttons (Buck 2026-06-11) — injected by wireEditSectionMove
    '.lg-block__mv{display:inline-flex;align-items:center;justify-content:center;border:0;background:none;cursor:pointer;color:var(--lg-mute,#6b6f6b);padding:0 2px;min-width:30px;height:30px;vertical-align:middle}' +
    '.lg-block__mv svg{width:16px;height:16px}' +
    '.lg-block__mv[disabled]{opacity:.28;cursor:default}';
  function styleEditFrame(frame) {
    try {
      var d = frame.contentDocument; if (!d) return;
      if (!d.getElementById('lpe-frame-style')) {
        var st = d.createElement('style'); st.id = 'lpe-frame-style'; st.textContent = EDIT_FRAME_CSS;
        (d.head || d.documentElement).appendChild(st);
      }
      wireEditPrivacy(frame);
      wireEditSectionMove(frame);
    } catch (e) {}
  }

  /* ---- Move up/down per section (Buck 2026-06-11): one-tap reorder inside the
     Edit-profile frame. pwa.js self-guards out of iframes, so the app-mobile-fixes
     overlay never runs in here — inject the same .lg-block__mv buttons frame-side.
     Persists via the same PUT /me/layout the editor's drag uses. No-ops once the
     canonical u.php version (buck/profile-section-move) merges — its inline script
     runs inside this frame too and both sides guard on button presence. ---- */
  function wireEditSectionMove(frame) {
    var d = frame.contentDocument; if (!d) return;
    var win = frame.contentWindow;

    function bodyBlocks(profile) {
      return Array.prototype.slice.call(profile.querySelectorAll('.lg-block:not(.lg-block--header)'));
    }
    function order(profile) {
      return bodyBlocks(profile).map(function (s) { return s.getAttribute('data-block'); }).filter(Boolean);
    }
    function putLayout(arr) {
      win.fetch('/profile-api/v0/me/layout', {
        method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order: arr })
      }).catch(function () {});
    }
    function wire(profile) {
      function mk(dir, label, path) {
        var btn = d.createElement('button');
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
          var u = b.querySelector('.lg-block__mv--up'), dd = b.querySelector('.lg-block__mv--down');
          if (u) u.disabled = (i === 0);
          if (dd) dd.disabled = (i === list.length - 1);
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

    // u.php's inline owner script injects the grip chrome after DOM-ready —
    // poll briefly, wire once. Re-runs safely on every frame (re)load.
    var tries = 0;
    var iv = win.setInterval(function () {
      if (++tries > 60) { win.clearInterval(iv); return; }
      var profile = d.querySelector('.lg-profile');
      if (!profile || !profile.querySelector('.lg-block__grip')) return;
      win.clearInterval(iv);
      if (profile.querySelector('.lg-block__mv')) return;   // canonical already provides them
      wire(profile);
    }, 200);
  }

  /* ---- Privacy pull-up (Buck 2026-06-10): one global slider + a slider per
     profile section, replacing the chip rows in the control panel. Writes go
     through the SAME canonical endpoints the editor's own pmp menus use (the
     EP table mirrors u.php's inline pmp script); the server stays the source
     of truth for ceilings + the Pro/Lite gate. Saves reload the frame (same as
     canonical) and the sheet re-opens itself via sessionStorage. ---- */
  function wireEditPrivacy(frame) {
    var d = frame.contentDocument; if (!d) return;
    if (d.getElementById('lg-privsheet')) return;
    var va = d.querySelector('.lg-viewas'); if (!va) return;          // owner editor only
    var win = frame.contentWindow;
    var BASE = '/profile-api/v0';
    var EP = {
      'header':          { url: BASE + '/me/header',   m: 'PATCH', k: 'visibility' },
      'craft':           { url: BASE + '/me/craft',    m: 'PATCH', k: 'visibility' },
      'skills':          { url: BASE + '/me/catalog/skills',      m: 'PUT', k: 'visibility' },
      'services':        { url: BASE + '/me/catalog/services',    m: 'PUT', k: 'visibility' },
      'instruments':     { url: BASE + '/me/catalog/instruments', m: 'PUT', k: 'visibility' },
      'music':           { url: BASE + '/me/catalog/music',       m: 'PUT', k: 'visibility' },
      'connect':         { url: BASE + '/me/connect',  m: 'PATCH', k: 'visibility' },
      'about':           { url: BASE + '/me/about',    m: 'PATCH', k: 'visibility' },
      'gallery':         { url: BASE + '/me/gallery',  m: 'PUT',   k: 'visibility' },
      'socials':         { url: BASE + '/me/socials',  m: 'PUT',   k: 'visibility' },
      'dropoffs':        { url: BASE + '/me/dropoffs', m: 'PUT',   k: 'visibility' },
      'location-approx': { url: BASE + '/me/location', m: 'PUT',   k: 'location_visibility' },
      'location-exact':  { url: BASE + '/me/location', m: 'PUT',   k: 'location_exact_visibility' }
    };
    var TIERS = { 'location-exact': ['members', 'private', 'on_request'], _default: ['public', 'members', 'private'] };
    var LABEL = { 'public': 'Public', 'members': 'Members', 'private': 'Only me', 'on_request': 'On request', 'member': 'Members' };
    var NAME = { 'about': 'About', 'craft': 'Craft', 'skills': 'Skills', 'services': 'Services', 'instruments': 'Instruments',
      'music': 'Music', 'connect': 'Connect', 'gallery': 'Gallery', 'socials': 'Links', 'dropoffs': 'Drop-off spots',
      'resume': 'Resume', 'location-approx': 'Location (area)', 'location-exact': 'Exact address' };
    function escT(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function nameFor(key, chipEl) {
      if (NAME[key]) return NAME[key];
      if (key.indexOf('freeform:') === 0 && chipEl) {
        var blk = chipEl.closest('.lg-block');
        var h = blk && blk.querySelector('h2, h3, .lg-bh, .lg-block__h');
        var t = h && (h.textContent || '').trim();
        if (t) return t;
      }
      return key.replace(/^freeform:/, '').replace(/[-_]/g, ' ');
    }
    // collect blocks off the editor's own pmp chips (key + current vis)
    var chips = {}, order = [];
    var chipEls = d.querySelectorAll('.lg-pmp');
    for (var i = 0; i < chipEls.length; i++) {
      var k = chipEls[i].getAttribute('data-pmp-block');
      if (!k || chips[k]) continue;
      chips[k] = { vis: chipEls[i].getAttribute('data-pmp-vis') || 'members', el: chipEls[i] };
      if (k !== 'header') order.push(k);
    }
    function rowHTML(key, cfg, globalRow) {
      var tiers = TIERS[key] || TIERS._default;
      var idx = Math.max(0, tiers.indexOf(cfg.vis));
      var lab = globalRow ? 'Whole profile' : nameFor(key, cfg.el);
      return '<div class="pv-row' + (globalRow ? ' pv-row--global' : '') + '" data-pv="' + escT(key) + '">' +
        '<div class="pv-lab"><span>' + escT(lab) + '</span><span class="pv-cur">' + LABEL[tiers[idx]] + '</span></div>' +
        '<input type="range" min="0" max="' + (tiers.length - 1) + '" step="1" value="' + idx + '" data-tiers="' + tiers.join(',') + '" aria-label="' + escT(lab) + ' visibility">' +
        '<div class="pv-ticks">' + tiers.map(function (t) { return '<span>' + LABEL[t] + '</span>'; }).join('') + '</div>' +
        (globalRow ? '<div class="pv-note">Sections below can never be more open than this.</div>' : '') +
        '</div>';
    }
    var html = '<div class="pv-back" data-pv-close></div><div class="pv-card">' +
      '<div class="pv-grab" aria-hidden="true"></div>' +
      '<div class="pv-t">Privacy</div>' +
      '<div class="pv-sub">Slide each section between Public, Members and Only&nbsp;me. Changes save instantly.</div>';
    if (chips.header) html += rowHTML('header', chips.header, true);
    // discussion posts (2-stop; the canonical seg buttons do the saving)
    var seg = d.querySelector('.lg-disc-seg');
    if (seg) {
      var dcur = seg.getAttribute('data-disc-current') === 'public' ? 'public' : 'member';
      html += '<div class="pv-row" data-pv="__disc">' +
        '<div class="pv-lab"><span>Discussion posts</span><span class="pv-cur">' + LABEL[dcur] + '</span></div>' +
        '<input type="range" min="0" max="1" step="1" value="' + (dcur === 'public' ? 0 : 1) + '" data-tiers="public,member" aria-label="Discussion posts visibility">' +
        '<div class="pv-ticks"><span>Public</span><span>Members</span></div>' +
        '<div class="pv-note">Members-only hides your name on discussion posts from logged-out visitors.</div></div>';
    }
    for (var o = 0; o < order.length; o++) html += rowHTML(order[o], chips[order[o]], false);
    html += '</div>';
    var sh = d.createElement('div'); sh.id = 'lg-privsheet';
    sh.setAttribute('role', 'dialog'); sh.setAttribute('aria-modal', 'true'); sh.setAttribute('aria-label', 'Privacy');
    sh.innerHTML = html;
    (d.body || d.documentElement).appendChild(sh);
    function openSheet() { sh.classList.add('is-open'); }
    function closeSheet() { sh.classList.remove('is-open'); }
    sh.addEventListener('click', function (e) { if (e.target.closest('[data-pv-close]')) closeSheet(); });
    sh.querySelector('.pv-grab').addEventListener('click', closeSheet);
    // Pull-down-to-dismiss (HOUSE RULE — every pull-up sheet): drag the grab
    // anytime, or pull down from the body when it's scrolled to the very top.
    (function () {
      var card = sh.querySelector('.pv-card');
      function dragTo(dy) { card.style.transition = 'none'; card.style.transform = 'translateY(' + Math.max(0, dy) + 'px)'; }
      function dragReset() { card.style.transition = ''; card.style.transform = ''; }
      function dragEnd(dy) { dragReset(); if (dy > 110) closeSheet(); }
      function attach(el, atTopGuard) {
        if (!el) return;
        var sy = 0, dy = 0, on = false;
        el.addEventListener('touchstart', function (e) {
          if (atTopGuard && !atTopGuard()) { on = false; return; }
          sy = e.touches[0].clientY; dy = 0; on = true;
        }, { passive: true });
        el.addEventListener('touchmove', function (e) {
          if (!on) return;
          dy = e.touches[0].clientY - sy;
          if (dy <= 0) { if (atTopGuard) { on = false; dragReset(); } return; }   // scrolling up — let it scroll
          if (atTopGuard && !atTopGuard()) { on = false; dragReset(); return; }
          dragTo(dy);
          if (e.cancelable) e.preventDefault();
        }, { passive: false });
        el.addEventListener('touchend', function () { if (!on) return; on = false; dragEnd(Math.max(0, dy)); });
      }
      attach(sh.querySelector('.pv-grab'), null);
      attach(card, function () { return card.scrollTop <= 0; });
    })();
    sh.addEventListener('change', function (e) {
      var r = e.target; if (!r || r.type !== 'range') return;
      var row = r.closest('.pv-row'); if (!row) return;
      var key = row.getAttribute('data-pv');
      var tiers = (r.getAttribute('data-tiers') || '').split(',');
      var tier = tiers[parseInt(r.value, 10) || 0];
      var cur = row.querySelector('.pv-cur');
      if (key === '__disc') {
        // drive the canonical seg button — its handler PUTs + updates in place
        var btn = d.querySelector('.lg-disc-seg button[data-disc="' + tier + '"]');
        if (btn) { btn.click(); if (cur) cur.textContent = LABEL[tier]; }
        return;
      }
      var ep = EP[key]; if (!ep) return;
      row.classList.add('pv-busy');
      var body = {}; body[ep.k] = tier;
      win.fetch(ep.url, { method: ep.m, credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (res) { return res.ok; })
        .then(function (ok) {
          if (!ok) throw 0;
          if (cur) cur.textContent = LABEL[tier];
          // reload (canonical behavior — the server re-derives ceilings + the
          // gate) and re-open the sheet so the user keeps their place.
          try { win.sessionStorage.setItem('lgPrivReopen', '1'); } catch (e2) {}
          win.location.reload();
        })
        .catch(function () { row.classList.remove('pv-busy'); win.alert('Could not change visibility right now.'); });
    });
    // sage Privacy pill next to the amber Sections pill
    var pbtn = d.createElement('button');
    pbtn.type = 'button'; pbtn.className = 'lg-privbtn'; pbtn.textContent = 'Privacy';
    var caddyBtn = d.getElementById('lg-caddy-toggle');
    if (caddyBtn && caddyBtn.parentNode) caddyBtn.parentNode.insertBefore(pbtn, caddyBtn.nextSibling);
    else va.appendChild(pbtn);
    pbtn.addEventListener('click', openSheet);
    try {
      if (win.sessionStorage.getItem('lgPrivReopen') === '1') { win.sessionStorage.removeItem('lgPrivReopen'); openSheet(); }
    } catch (e3) {}
  }
  function openProfileEdit(slug) {
    if (!slug) return;
    slug = String(slug).replace(/^\/?u\//, '').split(/[?#/]/)[0];
    if (!slug) return;
    var el = buildEditSheet();
    var frame = el.querySelector('.lpe-frame');
    frame.onload = function () { styleEditFrame(frame); };
    frame.src = '/u/' + encodeURIComponent(slug) + '?view=me';
    document.body.style.overflow = 'hidden';
    el.classList.add('is-open');
  }
  function closeProfileEdit() {
    if (!editEl) return;
    editEl.classList.remove('is-open');
    var frame = editEl.querySelector('.lpe-frame'); if (frame) frame.src = 'about:blank';
    var sheet = document.getElementById(SHEET_ID);
    if (sheet && sheet.classList.contains('is-open')) { document.body.style.overflow = 'hidden'; }
    else { document.body.style.overflow = ''; }
    var openSlug = sheet && sheet.getAttribute('data-lps-slug');
    if (sheet && sheet.classList.contains('is-open') && openSlug) { openProfileSheet(openSlug); }   // re-fetch to show saved edits
  }
  window.openProfileEdit = openProfileEdit;

  window.openProfileSheet = openProfileSheet;       // let other layers call it

  /* ---- wiring: intercept profile links site-wide (capture phase) ---- */
  var meSlugP = null;
  function meSlug() {
    if (meSlugP) return meSlugP;
    meSlugP = fetch('/profile-api/v0/me', { credentials: 'include' })
      .then(function (r) { return r.json(); }).then(function (j) { return j && j.slug; })
      .catch(function () { return null; });
    return meSlugP;
  }

  document.addEventListener('click', function (e) {
    if (!MOBILE.matches) return;
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    // "View profile" in the You sheet → my own profile. Match ONLY the link's
    // class, NOT the bare /profile/edit href — the You *tab* shares that href and
    // must keep opening the bottom-nav You sheet, not the profile sheet.
    if (a.classList.contains('lt-sheet__view')) {
      e.preventDefault(); e.stopPropagation();
      meSlug().then(function (s) { if (s) openProfileSheet(s); else location.href = '/profile/edit'; });
      return;
    }
    // any /u/<slug> link anywhere (directory cards, hub authors, mentions)
    var m = href.match(/^\/u\/([^/?#]+)/);
    if (m) {
      // don't hijack the owner's editor affordances on the profile page itself
      e.preventDefault(); e.stopPropagation();
      openProfileSheet(m[1]);
    }
  }, true);

})();
