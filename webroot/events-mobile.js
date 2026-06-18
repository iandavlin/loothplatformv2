/* events-mobile.js — Event details popup (mobile).
 *
 * Buck 2026-06-08: "do a popup with all the details like the loothprints one so
 * they don't go to desktop pages by accident." On the events landing, tapping an
 * event card (a.lg-evland__card) used to navigate to the desktop /event/<slug>/
 * page. On mobile we intercept that and open an inline bottom sheet with the
 * event's cover, date, title, when, location/tier, description, and its primary
 * action (Join / Upgrade / Zoom) — no navigation to the desktop page.
 *
 * Buck-owned client layer, loaded via /pwa.js, path-gated to /events + ≤640.
 * Desktop is untouched (tapping a card still opens the full event page there).
 */
(function () {
  'use strict';
  if (window.__loothEventsMobile) return;
  var path = location.pathname || '';
  if (path.indexOf('/events') !== 0) return;                 // events landing only
  if (!window.matchMedia('(max-width:640px)').matches) return;
  window.__loothEventsMobile = true;

  injectStyles();

  // Intercept event-card taps (capture, so the anchor never navigates).
  document.addEventListener('click', function (e) {
    var card = e.target.closest && e.target.closest('a.lg-evland__card');
    if (!card) return;
    e.preventDefault();
    e.stopPropagation();
    openEventSheet(card);
  }, true);

  // On the events landing: replace the shared top bar with a search bar that
  // filters the upcoming-events list (Buck 2026-06-08).
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildEventsSearch);
  else buildEventsSearch();

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function txt(el) { return el ? (el.textContent || '').trim() : ''; }

  // One close path (✕ / backdrop / drag / back-gesture) so history stays balanced.
  var levHist = false;
  function levClose(fromPop) {
    var sheet = document.getElementById('looth-ev-sheet');
    if (!sheet || !sheet.classList.contains('is-open')) return;
    sheet.classList.remove('is-open');
    if (levHist && !fromPop) { levHist = false; try { history.back(); } catch (e) {} }
    else { levHist = false; }
  }
  window.addEventListener('popstate', function () { levClose(true); });

  function injectStyles() {
    if (document.getElementById('looth-ev-style')) return;
    var css = [
      '#looth-ev-sheet{position:fixed;inset:0;z-index:2147483500;display:none}',
      '#looth-ev-sheet.is-open{display:block}',
      '#looth-ev-sheet .lev-back{position:absolute;inset:0;background:rgba(26,29,26,.55)}',
      // Design-system bottom sheet (2026-06-10): edge-to-edge, 18px top radius —
      // matches the content / replies / profile sheets so every pop-up reads as one app.
      '#looth-ev-sheet .lev-card{position:absolute;left:0;right:0;bottom:0;max-height:92vh;overflow:auto;-webkit-overflow-scrolling:touch;background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;padding:0 0 16px;box-shadow:0 -8px 30px rgba(26,29,26,.32);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--lg-ink,#323532);animation:looth-pwa-up .26s ease;will-change:transform}',
      '#looth-ev-sheet .lev-grab{position:absolute;top:8px;left:50%;transform:translateX(-50%);width:40px;height:5px;border-radius:3px;background:rgba(255,255,255,.85);box-shadow:0 1px 4px rgba(0,0,0,.25);z-index:2;pointer-events:none}',
      '#looth-ev-sheet .lev-cover{position:relative;width:100%;height:170px;background:var(--lg-sage-tint,#eef2e3) center/cover no-repeat;border-radius:18px 18px 0 0}',
      '#looth-ev-sheet .lev-pill{position:absolute;left:12px;bottom:12px;background:#fff;border-radius:12px;padding:6px 10px;text-align:center;box-shadow:0 2px 8px rgba(26,29,26,.25);line-height:1}',
      '#looth-ev-sheet .lev-mon{display:block;font:700 10px/1 var(--lg-font-sans,system-ui);letter-spacing:.08em;color:var(--lg-rust,#c66845)}',
      '#looth-ev-sheet .lev-day{display:block;font:700 18px/1.1 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '#looth-ev-sheet .lev-x{position:absolute;top:8px;right:10px;width:32px;height:32px;border:0;border-radius:50%;background:rgba(26,29,26,.5);color:#fff;font-size:20px;line-height:30px;cursor:pointer}',
      '#looth-ev-sheet .lev-body{padding:14px 16px 4px}',
      '#looth-ev-sheet .lev-kind{font:700 10px/1 var(--lg-font-sans,system-ui);letter-spacing:.08em;text-transform:uppercase;color:var(--lg-sage-d,#6b7c52)}',
      '#looth-ev-sheet .lev-t{font:700 19px/1.25 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);margin:5px 0 6px}',
      '#looth-ev-sheet .lev-when{font-weight:600;color:var(--lg-ink,#323532);margin-bottom:4px}',
      '#looth-ev-sheet .lev-meta{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 4px;font-size:13px;color:var(--lg-mute,#6b6f6b)}',
      '#looth-ev-sheet .lev-meta .lg-evland__tier{border-radius:999px;padding:2px 9px;font-weight:700;background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52)}',
      '#looth-ev-sheet .lev-desc{font-size:14.5px;color:var(--lg-ink,#323532);margin-top:10px}',
      '#looth-ev-sheet .lev-desc p{margin:0 0 9px}',
      '#looth-ev-sheet .lev-acts{padding:14px 16px 2px;display:flex;flex-direction:column;gap:9px}',
      '#looth-ev-sheet .lev-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;text-decoration:none;border:0;cursor:pointer;border-radius:12px;padding:13px;font:600 15px/1 var(--lg-font-sans,system-ui)}',
      '#looth-ev-sheet .lev-join{background:var(--lg-sage,#87986a);color:#fff}',
      '#looth-ev-sheet .lev-join:active{background:var(--lg-sage-d,#6b7c52)}',
      '#looth-ev-sheet .lev-cal{background:var(--lg-sage-tint,#eef2e3);color:var(--lg-sage-d,#6b7c52)}',
      '#looth-ev-sheet .lev-acts#lev-acts-cal{padding-top:0}',
      '#looth-ev-sheet .lev-note{padding:10px 16px;color:var(--lg-mute,#6b6f6b);font-size:13px}',
      /* events landing: replace the shared top bar with an upcoming-events search bar */
      'html.lgev .lg-chrome{display:none!important}',
      /* drop the tall shared footer on mobile — the bottom tab bar covers nav (Buck 2026-06-08) */
      'html.lgev .lg-chrome-foot{display:none!important}',
      '#lgev-search{position:sticky;top:0;z-index:1200;background:var(--lg-cream,#fbfbf8);' +
        'padding:calc(env(safe-area-inset-top,0px) + 10px) 12px 10px}',
      '#lgev-search .lgev-ubar{display:flex;align-items:center;gap:9px;background:#fff;' +
        'border:1px solid var(--lg-line,#e3ddd0);border-radius:999px;padding:10px 14px;box-shadow:0 4px 16px rgba(26,29,26,.14)}',
      '#lgev-search .lgev-ic{width:18px;height:18px;flex:0 0 auto;color:var(--lg-mute,#6b6f6b)}',
      '#lgev-search .lgev-input{flex:1 1 auto;min-width:0;border:0;outline:0;background:transparent;' +
        'font:15px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532)}',
      '#lgev-search .lgev-clear{flex:0 0 auto;width:26px;height:26px;border:0;background:none;' +
        'color:var(--lg-mute,#6b6f6b);font-size:20px;line-height:1;cursor:pointer}',
      '.lgev-empty{padding:24px 16px;text-align:center;color:var(--lg-mute,#6b6f6b);font:14px/1.5 var(--lg-font-sans,system-ui,sans-serif)}',
      /* DARK pass (2026-06-10 — this sheet had none; app-settings only darkens the
         card shell + title, the content stayed light-on-light). */
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-card{background:#1b1e21;color:#e5e7e1}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-pill{background:#262b30;box-shadow:0 2px 8px rgba(0,0,0,.45)}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-day{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-kind{color:#9cb37d}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-t{color:#f2f4ee}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-when{color:#e5e7e1}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-desc{color:#cdd0ca}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-meta{color:#9aa097}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-meta .lg-evland__tier{background:#243024;color:#b6c79a}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-cal{background:#243024;color:#b0c693}',
      'html[data-lguser-theme="dark"] #looth-ev-sheet .lev-note{color:#9aa097}',
      'html[data-lguser-theme="dark"] #lgev-search{background:#15171a}',
      'html[data-lguser-theme="dark"] .lgev-empty{color:#9aa097}'
    ].join('\n');
    var s = document.createElement('style'); s.id = 'looth-ev-style'; s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // Replace the shared top bar with a sticky "Search upcoming events" bar that
  // filters the landing's event cards by title/date as you type.
  function buildEventsSearch() {
    try {
      if (document.getElementById('lgev-search')) return;
      var land = document.querySelector('.lg-evland');
      if (!land) return;                                         // only on the events landing
      document.documentElement.classList.add('lgev');           // CSS hides .lg-chrome
      var bar = document.createElement('div');
      bar.id = 'lgev-search';
      bar.innerHTML =
        '<div class="lgev-ubar">' +
          '<svg class="lgev-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
          '<input type="text" class="lgev-input" placeholder="Search upcoming events" autocomplete="off" aria-label="Search upcoming events">' +
          '<button type="button" class="lgev-clear" aria-label="Clear" hidden>&times;</button>' +
        '</div>';
      land.parentNode.insertBefore(bar, land);
      var input = bar.querySelector('.lgev-input'), clr = bar.querySelector('.lgev-clear');
      function apply() {
        var q = input.value.trim().toLowerCase();
        clr.hidden = !input.value;
        var anyAll = false;
        [].forEach.call(document.querySelectorAll('.lg-evland__card'), function (c) {
          var hay = ((txt(c.querySelector('.lg-evland__title')) + ' ' + txt(c.querySelector('.lg-evland__when')) + ' ' + txt(c.querySelector('.lg-evland__region'))) || '').toLowerCase();
          var show = !q || hay.indexOf(q) > -1;
          c.style.display = show ? '' : 'none';
          if (show) anyAll = true;
        });
        // collapse sections (+ their headers) that have no visible cards
        [].forEach.call(document.querySelectorAll('.lg-evland__section'), function (sec) {
          var any = false;
          [].forEach.call(sec.querySelectorAll('.lg-evland__card'), function (c) { if (c.style.display !== 'none') any = true; });
          sec.style.display = any ? '' : 'none';
        });
        var empty = document.getElementById('lgev-empty');
        if (q && !anyAll) {
          if (!empty) { empty = document.createElement('div'); empty.id = 'lgev-empty'; empty.className = 'lgev-empty'; land.appendChild(empty); }
          empty.textContent = 'No upcoming events match “' + input.value.trim() + '”.';
          empty.style.display = '';
        } else if (empty) { empty.style.display = 'none'; }
      }
      input.addEventListener('input', apply);
      clr.addEventListener('click', function () { input.value = ''; apply(); input.focus(); });
    } catch (e) { /* never break the landing */ }
  }

  // ---- Add to calendar (ICS — works on iOS Calendar + Android/Google Cal) ----
  var MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };
  var TZMAP = { et: 'America/New_York', est: 'America/New_York', edt: 'America/New_York',
    ct: 'America/Chicago', cst: 'America/Chicago', cdt: 'America/Chicago',
    mt: 'America/Denver', mst: 'America/Denver', mdt: 'America/Denver',
    pt: 'America/Los_Angeles', pst: 'America/Los_Angeles', pdt: 'America/Los_Angeles' };
  // Parse "Monday, June 8, 2026 · 3:00 PM ET" → {y,mo,d,h,mi,tz}.
  function parseWhen(s) {
    if (!s) return null;
    var m = s.match(/([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4}).*?(\d{1,2}):(\d{2})\s*(AM|PM)?\s*([A-Za-z]{2,3})?/i);
    if (!m) return null;
    var mo = MONTHS[(m[1] || '').slice(0, 3).toLowerCase()];
    if (mo == null) return null;
    var h = parseInt(m[4], 10), mi = parseInt(m[5], 10), ap = (m[6] || '').toUpperCase();
    if (ap === 'PM' && h < 12) h += 12; if (ap === 'AM' && h === 12) h = 0;
    return { y: +m[3], mo: mo, d: +m[2], h: h, mi: mi, tz: TZMAP[(m[7] || 'et').toLowerCase()] || 'America/New_York' };
  }
  // Wall-clock time in an IANA tz → the correct UTC instant (two-pass via Intl).
  function wallToUTC(y, mo, d, h, mi, tz) {
    var asUTC = Date.UTC(y, mo, d, h, mi, 0);
    try {
      var dtf = new Intl.DateTimeFormat('en-US', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
      var p = {}; dtf.formatToParts(new Date(asUTC)).forEach(function (x) { p[x.type] = x.value; });
      var hh = (p.hour === '24') ? 0 : +p.hour;
      var shown = Date.UTC(+p.year, +p.month - 1, +p.day, hh, +p.minute, +p.second);
      return asUTC - (shown - asUTC);
    } catch (e) { return asUTC; }
  }
  function icsTime(ms) {
    var d = new Date(ms);
    function z(n) { return (n < 10 ? '0' : '') + n; }
    return d.getUTCFullYear() + z(d.getUTCMonth() + 1) + z(d.getUTCDate()) + 'T' + z(d.getUTCHours()) + z(d.getUTCMinutes()) + z(d.getUTCSeconds()) + 'Z';
  }
  function icsEsc(s) { return String(s || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n'); }
  var IS_IOS = /iphone|ipad|ipod/i.test(navigator.userAgent || '') ||
    ((navigator.platform === 'MacIntel') && navigator.maxTouchPoints > 1);
  // Add to calendar — OPEN the add-event flow, never force a file download (Buck
  // 2026-06-08: "it's asking me to download an .ics"). iOS: open the .ics inline
  // (text/calendar, NO download attr) so Apple Calendar shows its "Add" sheet.
  // Everything else: Google Calendar's pre-filled add-event page (no file).
  function addToCalendar(title, whenStr, location, url) {
    var w = parseWhen(whenStr);
    if (!w) { if (url) window.open(url, '_blank'); return; }   // can't parse → just open the event
    var start = wallToUTC(w.y, w.mo, w.d, w.h, w.mi, w.tz);
    var end = start + 60 * 60 * 1000;                          // default 1h
    var s = icsTime(start), e = icsTime(end);
    if (IS_IOS) {
      var uid = 'looth-' + start + '-' + Math.abs((title || '').length * 7 + w.d) + '@loothgroup.com';
      var ics = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Looth Group//Events//EN', 'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT', 'UID:' + uid, 'DTSTAMP:' + icsTime(Date.now()),
        'DTSTART:' + s, 'DTEND:' + e,
        'SUMMARY:' + icsEsc(title),
        location ? 'LOCATION:' + icsEsc(location) : '',
        url ? 'URL:' + icsEsc(url) : '',
        url ? 'DESCRIPTION:' + icsEsc('More: ' + url) : '',
        'END:VEVENT', 'END:VCALENDAR'
      ].filter(Boolean).join('\r\n');
      // Navigate to the .ics (no download attr) → iOS hands it to Calendar's Add sheet.
      try { window.location.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' })); }
      catch (e) { window.location.href = 'data:text/calendar;charset=utf-8,' + encodeURIComponent(ics); }
      return;
    }
    // Android / desktop / others → Google Calendar pre-filled add-event (no file).
    var g = 'https://calendar.google.com/calendar/render?action=TEMPLATE' +
      '&text=' + encodeURIComponent(title || 'Event') +
      '&dates=' + s + '/' + e +
      (location ? '&location=' + encodeURIComponent(location) : '') +
      (url ? '&details=' + encodeURIComponent('More: ' + url) : '');
    window.open(g, '_blank', 'noopener');
  }

  function openEventSheet(card) {
    var href = card.getAttribute('href') || '';
    var title = txt(card.querySelector('.lg-evland__title')) || 'Event';
    var when = txt(card.querySelector('.lg-evland__when'));
    var mon = txt(card.querySelector('.lg-evland__mon'));
    var day = txt(card.querySelector('.lg-evland__day'));
    var metaEl = card.querySelector('.lg-evland__meta');
    var metaHtml = '';
    if (metaEl) {
      // Strip any links (e.g. a "Details" link) — nothing in the popup should
      // bounce out to the desktop page, which is the whole point of the popup.
      var mClone = metaEl.cloneNode(true);
      [].forEach.call(mClone.querySelectorAll('a'), function (a) {
        var sp = document.createElement('span'); sp.className = a.className; sp.textContent = a.textContent; a.parentNode.replaceChild(sp, a);
      });
      // Drop a bare "Details" chip if present (desktop affordance).
      [].forEach.call(mClone.children, function (c) { if (/^details\b/i.test((c.textContent || '').trim())) c.remove(); });
      metaHtml = mClone.innerHTML;
    }
    var cover = '';
    var thumb = card.querySelector('.lg-evland__thumb');
    if (thumb) { var m = /url\(["']?(.*?)["']?\)/.exec(getComputedStyle(thumb).backgroundImage || ''); if (m) cover = m[1]; }

    var sheet = document.getElementById('looth-ev-sheet');
    if (!sheet) {
      sheet = document.createElement('div'); sheet.id = 'looth-ev-sheet'; sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-label', 'Event details');
      (document.body || document.documentElement).appendChild(sheet);
      sheet.addEventListener('click', function (e) { if (e.target.closest('[data-lev-close]')) levClose(); });
    }
    sheet.innerHTML =
      '<div class="lev-back" data-lev-close></div>' +
      '<div class="lev-card">' +
        '<div class="lev-grab" aria-hidden="true"></div>' +
        '<div class="lev-cover" style="' + (cover ? "background-image:url('" + esc(cover) + "')" : '') + '">' +
          (mon || day ? '<span class="lev-pill"><span class="lev-mon">' + esc(mon) + '</span><span class="lev-day">' + esc(day) + '</span></span>' : '') +
        '</div>' +
        '<button class="lev-x" type="button" aria-label="Close" data-lev-close>&times;</button>' +
        '<div class="lev-body">' +
          '<div class="lev-kind">Event</div>' +
          '<div class="lev-t">' + esc(title) + '</div>' +
          (when ? '<div class="lev-when">' + esc(when) + '</div>' : '') +
          (metaHtml ? '<div class="lev-meta">' + metaHtml + '</div>' : '') +
          '<div class="lev-desc" id="lev-desc"></div>' +
        '</div>' +
        '<div class="lev-acts" id="lev-acts"><div class="lev-note">Loading details…</div></div>' +
        '<div class="lev-acts" id="lev-acts-cal"></div>' +
      '</div>';
    sheet.classList.add('is-open');
    // Phone back-gesture closes the sheet instead of leaving /events (design-system).
    if (!levHist) { try { history.pushState({ lgEv: 1 }, ''); levHist = true; } catch (e) {} }

    // Drag down to dismiss — from anywhere on the card when it's scrolled to the very
    // top (overscroll-to-dismiss, Buck 2026-06-09). The card is the scroll container.
    (function () {
      var card = sheet.querySelector('.lev-card'); if (!card) return;
      var sy = null, dy = 0, pulling = false;
      card.addEventListener('touchstart', function (e) {
        if (e.target.closest && e.target.closest('a, button')) return;
        if (card.scrollTop <= 0) { sy = e.touches[0].clientY; dy = 0; pulling = true; card.style.transition = 'none'; } else pulling = false;
      }, { passive: true });
      card.addEventListener('touchmove', function (e) {
        if (!pulling || sy === null) return;
        dy = e.touches[0].clientY - sy;
        if (dy > 0 && card.scrollTop <= 0) { card.style.transform = 'translateY(' + dy + 'px)'; if (e.cancelable) e.preventDefault(); }
        else { card.style.transform = ''; pulling = false; }
      }, { passive: false });
      card.addEventListener('touchend', function () {
        if (!pulling) return; pulling = false; card.style.transition = ''; sy = null;
        if (dy > 110) { card.style.transform = ''; levClose(); } else card.style.transform = '';
      });
    })();

    // Add-to-calendar button — rendered immediately from the card's date (works on
    // iOS Calendar + Android/Google Cal via a downloaded .ics). Buck 2026-06-08.
    var regionText = (txt(card.querySelector('.lg-evland__region')) || '').replace(/^[^\w]+/, '').trim();
    var calBox = sheet.querySelector('#lev-acts-cal');
    if (calBox && when) {
      var calIco = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9h18"/><path d="M8 2.5v4M16 2.5v4"/></svg>';
      calBox.innerHTML = '<button class="lev-btn lev-cal" type="button" data-lev-cal>' + calIco + 'Add to calendar</button>';
      calBox.querySelector('[data-lev-cal]').addEventListener('click', function () {
        addToCalendar(title, when, regionText, href);
      });
    }

    if (!href) { var a0 = sheet.querySelector('#lev-acts'); if (a0) a0.innerHTML = ''; return; }
    fetch(href, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        // Description: the event header blurb, else the post/body content.
        var descEl = doc.querySelector('.lg-event-header__detail, .lg-event__description, .bb-card-content, .entry-content, .wp-block-post-content');
        var descBox = sheet.querySelector('#lev-desc');
        if (descBox && descEl) {
          var ps = descEl.querySelectorAll('p');
          if (ps.length) {
            var out = '';
            for (var i = 0; i < ps.length && i < 6; i++) { var p = (ps[i].textContent || '').trim(); if (p) out += '<p>' + esc(p) + '</p>'; }
            descBox.innerHTML = out;
          } else {
            descBox.textContent = (descEl.textContent || '').trim().slice(0, 600);
          }
        }
        // Gate note (Lite events show an upgrade message).
        var gateMsg = txt(doc.querySelector('.lg-event-header__gate-msg, .lg-event-header__upgrade'));
        // Primary action: the most prominent join / register / zoom / upgrade link.
        var cta = null, RX = /zoom|register|rsvp|\bjoin\b|ticket|sign.?up|lgjoin|upgrade|meet\.google|teams/i;
        var anchors = [].slice.call(doc.querySelectorAll('a[href]'));
        for (var k = 0; k < anchors.length; k++) {
          var hh = anchors[k].getAttribute('href') || '', tt = (anchors[k].textContent || '').trim();
          if (!hh || hh.charAt(0) === '#') continue;
          if (RX.test(hh + ' ' + tt)) { cta = { href: new URL(hh, href).href, label: tt || 'Join' }; break; }
        }
        var acts = sheet.querySelector('#lev-acts'); if (!acts) return;
        var joinIco = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>';
        var out = '';
        if (cta) out += '<a class="lev-btn lev-join" href="' + esc(cta.href) + '" target="_blank" rel="noopener">' + joinIco + esc(cta.label.slice(0, 40)) + '</a>';
        if (gateMsg) out += '<div class="lev-note">' + esc(gateMsg) + '</div>';
        acts.innerHTML = out || '<div class="lev-note">See you there.</div>';
      })
      .catch(function () {
        var acts = sheet.querySelector('#lev-acts');
        if (acts) acts.innerHTML = '<div class="lev-note">Couldn’t load full details right now.</div>';
      });
  }
})();
