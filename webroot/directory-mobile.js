/* directory-mobile.js — Map-first mobile directory.
 *
 * Port of the map-styles sandbox (map-styles/index.html) onto the canonical
 * /directory/members page. Buck-owned client layer, loaded via /pwa.js,
 * path-gated to /directory and only on ≤640 (phone/app viewport). Desktop and
 * the canonical markup are untouched.
 *
 * What it does (mirrors the sandbox Buck signed off on — Peek-sheet + Bar-on-map):
 *   • Map becomes the hero: #dir-map fills the band between the sticky site
 *     header and the bottom tab bar.
 *   • A floating, search-styled bar over the map ("Search city, instrument,
 *     genre…" + live member count) opens a slide-up Filters sheet that holds
 *     the REAL #dir-filterbar (location autocomplete, radius, instruments,
 *     skills, music, credentials, sort) — nothing about the server filtering
 *     changes, it's just relocated behind one tap.
 *   • Results live in a draggable bottom sheet (grab bar + "Members N matching"
 *     header + sort), snapping peek / half / full. Peek shows one full member
 *     card (banner + Connect); drag up for more.
 *   • Tapping a member card zooms the map (canonical zoomToMember) and drops the
 *     sheet to peek so the pin is visible.
 *
 * The card markup is already the sandbox markup (.dir-card / banner / row1 /
 * avi-sm / name / loc-row / hl-chips / dir-card__foot / dir-connect) so no card
 * restructure is needed — only layout chrome.
 */
(function () {
  'use strict';
  if (window.__loothDirMobile) return;

  // ---- gate: directory path + phone viewport ----
  var path = location.pathname || '';
  if (path.indexOf('/directory') !== 0) return;
  function isMobile() { return window.matchMedia('(max-width:640px)').matches; }
  if (!isMobile()) return;
  window.__loothDirMobile = true;

  // Buck 2026-06-08: on the members page the shared top bar is HIDDEN so the map
  // runs edge-to-edge to the very top with only the floating search bar over it.
  // So the map band starts at 0 (HEADER=0) and the search bar clears the notch
  // via safe-area-inset-top in CSS.
  var HEADER = 0;    // top bar hidden on this page → map starts at the top
  var NAV = 54;      // looth-tabbar content height (+ safe-area added in calc)

  // Mark the root immediately so the full-stage map CSS wins BEFORE Leaflet
  // measures #dir-map on init (timing-independent — see injectCss specificity).
  document.documentElement.classList.add('lgdm');
  injectCss();
  // NOTE: boot() is invoked at the BOTTOM of this IIFE — after every top-level
  // `var` (SNAP, sheetEl…) has been assigned. Calling it here would run boot
  // before `var SNAP = {…}` executes (this script loads post-DOMContentLoaded),
  // making SNAP undefined and throwing inside buildSheet.

  function boot() {
    try {
      var map = document.getElementById('dir-map');
      var results = document.getElementById('dir-results');
      var filterbar = document.getElementById('dir-filterbar');
      var dirApp = document.querySelector('.dir-app');
      if (!map || !results || !dirApp) { document.documentElement.classList.remove('lgdm'); return; }

      buildSearchBar(filterbar);
      buildSheet(dirApp);
      mirrorCount();
      centerOnDefault();
      wireMapDriven();        // search members as you pan (center = top listing)

      // Let Leaflet re-measure now that #dir-map fills the band.
      bump();
      setTimeout(bump, 80);
      setTimeout(bump, 400);
      window.addEventListener('orientationchange', function () { setTimeout(bump, 200); });
    } catch (e) { /* never break the page */ }
  }

  function bump() {
    try { window.dispatchEvent(new Event('resize')); } catch (e) {}
    // The page's Leaflet map is a top-level `let dirMap` in the directory page
    // script — reachable here by bare name (shared global lexical env). The
    // resize event alone sometimes doesn't make Leaflet repaint tiles into the
    // newly-enlarged full-stage container, so invalidate explicitly.
    try { if (typeof dirMap !== 'undefined' && dirMap && dirMap.invalidateSize) dirMap.invalidateSize(true); } catch (e) {}
  }

  // Default map view: center on Dan Erlewine's shop (Athens, OH) on first load —
  // Buck 2026-06-08. Waits for the page's pin load (its world-fit fitBounds) then
  // overrides it ONCE; skips if the user has already searched a location.
  function centerOnDefault() {
    var DAN = { lat: 39.3, lng: -82.1, z: 8 }, tries = 0;
    function searched() { var loc = document.getElementById('dir-loc'), lat = document.getElementById('dir-lat'); return (loc && loc.value.trim()) || (lat && lat.value); }
    function place() {
      if (searched()) return;
      try { if (typeof dirMap !== 'undefined' && dirMap) { dirMap.stop(); dirMap.setView([DAN.lat, DAN.lng], DAN.z, { animate: false }); } } catch (e) {}
    }
    (function tick() {
      if (window.__lgDirCentered) return;
      if (searched()) { window.__lgDirCentered = true; return; }
      var hasPins = false;
      try { hasPins = (typeof pinMarkerBySlug !== 'undefined' && pinMarkerBySlug && Object.keys(pinMarkerBySlug).length > 0); } catch (e) {}
      if (typeof dirMap !== 'undefined' && dirMap && dirMap.setView && hasPins) {
        window.__lgDirCentered = true;
        place();                       // cancel the in-flight world fitBounds + center on Dan
        setTimeout(place, 500);        // re-assert past the fitBounds animation
        setTimeout(place, 1100);       // …and any late second fit
        return;
      }
      if (++tries < 40) setTimeout(tick, 250);
    })();
  }

  // ---------- floating search bar (Google-Maps style) + Filters sheet ----------
  // Buck 2026-06-08: "one bar that's more intuitive, match Google Maps." The bar
  // is now a SINGLE live search input (the real #dir-loc location field with its
  // Places autocomplete) — type a city/area and the map + member list follow it.
  // The old multi-field form is tucked behind a small filter icon for power users.
  function buildSearchBar(filterbar) {
    if (document.getElementById('lgdm-search')) return;

    var bar = document.createElement('div');
    bar.id = 'lgdm-search';
    bar.innerHTML =
      '<div class="lgdm-ubar">' +
        '<svg class="lgdm-ubar__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
        '<span class="lgdm-ubar__slot" id="lgdm-loc-slot"></span>' +
        '<button type="button" class="lgdm-ubar__clear" id="lgdm-clear" aria-label="Clear search" hidden>&times;</button>' +
        '<button type="button" class="lgdm-ubar__filt" id="lgdm-open-filters" aria-label="Filters"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg></button>' +
      '</div>';
    document.body.appendChild(bar);

    // Filters sheet (advanced filters behind the filter icon).
    var fb = document.createElement('div');
    fb.id = 'lgdm-filters';
    fb.innerHTML =
      '<div class="lgdm-fbd" id="lgdm-fbd"></div>' +
      '<div class="lgdm-fsheet" role="dialog" aria-label="Filter members">' +
        '<div class="lgdm-fsheet__hd"><span>Filters</span>' +
          '<button type="button" class="lgdm-fclose" aria-label="Close">&times;</button></div>' +
        '<div class="lgdm-fsheet__body" id="lgdm-fbody"></div>' +
        '<div class="lgdm-fsheet__foot"><button type="button" class="lgdm-fapply">Show members</button></div>' +
      '</div>';
    document.body.appendChild(fb);

    // Move the REAL location input into the bar (keeps its Places autocomplete +
    // all wired filtering). The rest of the filter bar goes into the sheet; its
    // now-headless Location row is hidden there (CSS).
    var origLoc = document.getElementById('dir-loc');
    if (origLoc) {
      // Clone+replace to STRIP the canonical Nominatim autocomplete listeners — its
      // dropdown attaches to #dir-loc's parent, which ends up stranded in the hidden
      // filters sheet after we relocate the input, so its suggestions never showed
      // ("map not searching the way I want"). The clone keeps id='dir-loc' so the
      // page's filterQs() still reads it; we attach our own unified members+places
      // autocomplete instead.
      var loc = origLoc.cloneNode(true);
      loc.value = origLoc.value;
      origLoc.parentNode.replaceChild(loc, origLoc);
      loc.classList.add('lgdm-loc-input');
      loc.setAttribute('placeholder', 'Search members or a place');
      loc.setAttribute('autocomplete', 'off');
      document.getElementById('lgdm-loc-slot').appendChild(loc);
      wireUnifiedSearch(loc);
    }
    if (filterbar) document.getElementById('lgdm-fbody').appendChild(filterbar);

    function openF() { fb.classList.add('open'); document.documentElement.classList.add('lgdm-noscroll'); }
    function closeF() { fb.classList.remove('open'); document.documentElement.classList.remove('lgdm-noscroll'); }
    document.getElementById('lgdm-open-filters').addEventListener('click', openF);
    fb.querySelector('.lgdm-fclose').addEventListener('click', closeF);
    fb.querySelector('.lgdm-fapply').addEventListener('click', closeF);
    document.getElementById('lgdm-fbd').addEventListener('click', closeF);
  }

  // ---------- unified autocomplete (members + places), Google-Maps feel ----------
  // Buck 2026-06-08: "intuitive autofill dropdown — location OR members, feel
  // natural." Members come from a one-shot fetch of the full pin index (names +
  // coords); places come from Nominatim (debounced). Members are instant/local.
  function sEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  var SICO_PERSON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>';
  var SICO_PIN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>';
  function wireUnifiedSearch(loc) {
    var clr = document.getElementById('lgdm-clear');
    // dropdown lives on <body> (not inside the fixed bar) so it can stack ABOVE
    // the results sheet (z 1400); a child of the bar's stacking context couldn't.
    var dd = document.createElement('div'); dd.id = 'lgdm-suggest'; dd.style.display = 'none';
    document.body.appendChild(dd);

    var members = [];
    fetch('/profile-api/v0/directory/members?pins=1', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.pins) members = d.pins.map(function (p) { return { slug: p.slug, name: p.display_name || '', lat: p.lat, lng: p.lng, text: p.text || '' }; });
      })
      .catch(function () {});

    function syncClear() { clr.hidden = !loc.value; }
    function close() { dd.style.display = 'none'; }
    function open() { if (dd.children.length) dd.style.display = 'block'; }
    function groupH(label) { var g = document.createElement('div'); g.className = 'lgdm-sg-h'; g.textContent = label; return g; }
    function row(ico, title, sub, onpick) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'lgdm-sg';
      b.innerHTML = '<span class="lgdm-sg-ic">' + ico + '</span><span class="lgdm-sg-tx"><span class="lgdm-sg-t">' + sEsc(title) + '</span>' + (sub ? '<span class="lgdm-sg-s">' + sEsc(sub) + '</span>' : '') + '</span>';
      b.addEventListener('click', onpick);
      return b;
    }
    function memberMatches(q) {
      var ql = q.toLowerCase(), starts = [], contains = [];
      for (var i = 0; i < members.length; i++) {
        var n = (members[i].name || '').toLowerCase();
        if (!n) continue;
        if (n.indexOf(ql) === 0) starts.push(members[i]);
        else if (n.indexOf(ql) > -1) contains.push(members[i]);
        if (starts.length >= 5) break;
      }
      return starts.concat(contains).slice(0, 5);
    }
    function pickMember(m) {
      loc.value = m.name; syncClear(); close();
      try { setFrac(SNAP.half); } catch (e) {}
      // Re-center the member LIST on the picked member (Buck 2026-06-08: "their
      // card should be at the top of the pullup, followed by people closest to
      // it"). The directory API orders by distance ASC whenever lat/lng are set,
      // so we feed the member's own coords in as the geo center and let the
      // canonical applyFilters() re-query: the picked member (distance ~0) lands
      // first, then the members nearest to them. We widen the radius to the
      // largest the server honors (500 mi) so it's a "nearest" ordering, not a
      // tight local cut. Clearing the search (the × button) drops lat/lng and
      // restores the full unsorted list. Skip when the member has no coords.
      try {
        if (m.lat != null && m.lng != null) {
          var la = document.getElementById('dir-lat'),
              ln = document.getElementById('dir-lng'),
              rad = document.getElementById('dir-radius');
          if (la && ln) {
            la.value = m.lat; ln.value = m.lng;
            if (rad) {
              if (!rad.querySelector('option[value="500"]')) {
                var o = document.createElement('option');
                o.value = '500'; o.textContent = 'within 500 mi';
                rad.appendChild(o);
              }
              rad.value = '500';
            }
            if (typeof applyFilters !== 'undefined') applyFilters();
          }
        }
      } catch (e) {}
      try {
        var rec = (typeof pinMarkerBySlug !== 'undefined' && pinMarkerBySlug) ? pinMarkerBySlug[m.slug] : null;
        if (rec && typeof dirCluster !== 'undefined' && dirCluster && dirCluster.zoomToShowLayer) {
          dirCluster.zoomToShowLayer(rec.marker, function () { dirMap.setView([rec.lat, rec.lng], Math.max(dirMap.getZoom(), 12), { animate: true }); rec.marker.openPopup(); });
        } else if (typeof dirMap !== 'undefined' && dirMap && m.lat != null) {
          dirMap.setView([m.lat, m.lng], 12, { animate: true });
        }
      } catch (e) {}
    }
    function pickPlace(lat, lng, label) {
      var la = document.getElementById('dir-lat'), ln = document.getElementById('dir-lng');
      if (la) la.value = lat; if (ln) ln.value = lng;
      loc.value = label; syncClear(); close();
      try { if (typeof applyFilters !== 'undefined') applyFilters(); } catch (e) {}
    }
    function buildMembers(q) {
      dd.innerHTML = '';
      var mem = memberMatches(q);
      if (mem.length) {
        dd.appendChild(groupH('Members'));
        mem.forEach(function (m) { dd.appendChild(row(SICO_PERSON, m.name, m.text, function () { pickMember(m); })); });
      }
      if (dd.children.length) open(); else close();
    }
    var placeTimer = null, lastPlaceQ = null;
    function summarize(rw) { var a = rw.address || {}; var city = a.city || a.town || a.village || a.hamlet || a.suburb; var parts = [city, a.state, a.country].filter(Boolean); return parts.length ? parts.join(', ') : (rw.display_name || '').slice(0, 70); }
    function fetchPlaces(q) {
      clearTimeout(placeTimer);
      if (q.length < 3) return;
      placeTimer = setTimeout(function () {
        if (q === lastPlaceQ) return; lastPlaceQ = q;
        fetch('https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=4&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (rows) {
            if (loc.value.trim() !== q) return;                    // stale
            var items = (Array.isArray(rows) ? rows : []).slice(0, 4);
            if (!items.length) return;
            dd.appendChild(groupH('Places'));
            items.forEach(function (rw) { dd.appendChild(row(SICO_PIN, summarize(rw), '', function () { pickPlace(rw.lat, rw.lon, summarize(rw)); })); });
            open();
          })
          .catch(function () {});
      }, 550);                                                      // Nominatim ≤1 req/sec
    }

    var inputTimer = null;
    loc.addEventListener('input', function () {
      syncClear();
      var q = loc.value.trim();
      clearTimeout(inputTimer);
      if (q.length < 2) { close(); dd.innerHTML = ''; lastPlaceQ = null; return; }
      inputTimer = setTimeout(function () { buildMembers(q); fetchPlaces(q); }, 110);
    });
    loc.addEventListener('focus', function () { if (loc.value.trim().length >= 2 && dd.children.length) open(); });
    loc.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    document.addEventListener('click', function (e) {
      if (!dd.contains(e.target) && !document.getElementById('lgdm-search').contains(e.target)) close();
    });
    clr.addEventListener('click', function () {
      loc.value = '';
      var la = document.getElementById('dir-lat'), ln = document.getElementById('dir-lng');
      if (la) la.value = ''; if (ln) ln.value = '';
      syncClear(); close(); dd.innerHTML = ''; lastPlaceQ = null;
      try { if (typeof applyFilters !== 'undefined') applyFilters(); } catch (e) {}
      loc.focus();
    });
    syncClear();
  }

  // ---------- results sheet (draggable, snapping) ----------
  // Fractions of the band measured from its TOP. full = near the top (members
  // fill the screen), half = default, peek = a card or two, hidden = dragged all
  // the way down so only the "Members N" grab header shows as a pull tab (Buck
  // 2026-06-08: drag down to hide the members, default at half).
  var SNAP = { full: 0.06, half: 0.42, peek: 0.62 };
  var HANDLE = 64;                                   // px of sheet left visible when hidden
  var sheetEl, dragging = false, startY = 0, startFrac = 0;

  function bandTop() { return HEADER; }
  function bandH() {
    var navH = NAV + safeBottom();
    return Math.max(220, window.innerHeight - HEADER - navH);
  }
  function hiddenFrac() { return Math.min(0.985, 1 - HANDLE / bandH()); }
  function safeBottom() {
    // env(safe-area-inset-bottom) isn't readable in JS; 0 is fine for layout math
    // (the CSS calc carries the real inset). Headless/desktop = 0.
    return 0;
  }
  function setFrac(f) {
    f = Math.min(0.99, Math.max(0.04, f));
    sheetEl.style.top = (bandTop() + f * bandH()) + 'px';
    sheetEl.dataset.frac = String(f);
  }
  function snapNearest() {
    var cur = parseFloat(sheetEl.dataset.frac || SNAP.half);
    var order = [SNAP.full, SNAP.half, SNAP.peek, hiddenFrac()];
    var best = order[0], bd = 9;
    order.forEach(function (s) { var d = Math.abs(s - cur); if (d < bd) { bd = d; best = s; } });
    sheetEl.classList.remove('dragging');
    setFrac(best);
  }

  function buildSheet(dirApp) {
    if (document.getElementById('lgdm-sheet')) return;
    sheetEl = document.createElement('div');
    sheetEl.id = 'lgdm-sheet';
    sheetEl.innerHTML =
      '<div class="lgdm-grab" id="lgdm-grab"><span class="lgdm-grab__bar"></span></div>' +
      '<div class="lgdm-shd"><span class="lgdm-shd__ttl">Members <span class="lgdm-shd__meta" id="lgdm-meta"></span></span></div>';
    document.body.appendChild(sheetEl);
    // Move the real results app inside the sheet (scroll area).
    sheetEl.appendChild(dirApp);
    setFrac(SNAP.half);                               // default: half

    var grab = document.getElementById('lgdm-grab');
    function down(e) {
      dragging = true; sheetEl.classList.add('dragging');
      startY = (e.touches ? e.touches[0].clientY : e.clientY);
      startFrac = parseFloat(sheetEl.dataset.frac || SNAP.half);
      e.preventDefault();
    }
    function move(e) {
      if (!dragging) return;
      var y = (e.touches ? e.touches[0].clientY : e.clientY);
      setFrac(startFrac + (y - startY) / bandH());
    }
    function up() { if (!dragging) return; dragging = false; snapNearest(); }
    grab.addEventListener('mousedown', down);
    grab.addEventListener('touchstart', down, { passive: false });
    var shd = sheetEl.querySelector('.lgdm-shd');
    if (shd) {
      shd.addEventListener('mousedown', down);
      shd.addEventListener('touchstart', down, { passive: false });
    }
    window.addEventListener('mousemove', move);
    window.addEventListener('touchmove', move, { passive: false });
    window.addEventListener('mouseup', up);
    window.addEventListener('touchend', up);
    window.addEventListener('resize', function () { setFrac(parseFloat(sheetEl.dataset.frac || SNAP.half)); });

    // Overscroll-to-dismiss (Buck 2026-06-11): pulling DOWN on the member list
    // while it's scrolled to the very top drags the whole sheet — same snapping
    // machinery as the grab bar, so a continued pull lands it on hidden (the
    // pull-tab state). Normal list scrolling is untouched: the drag only arms
    // when the gesture STARTS at scrollTop 0 and moves downward.
    var bodyY = null, bodyArmed = false, bodyDrag = false;
    dirApp.addEventListener('touchstart', function (e) {
      bodyArmed = !dragging && dirApp.scrollTop <= 0;
      bodyDrag = false;
      bodyY = e.touches[0].clientY;
    }, { passive: true });
    dirApp.addEventListener('touchmove', function (e) {
      if (bodyDrag) { if (e.cancelable) e.preventDefault(); return; } // window move() drives the sheet
      if (!bodyArmed || dragging || bodyY === null) return;
      var dy = e.touches[0].clientY - bodyY;
      if (dy > 6 && dirApp.scrollTop <= 0) {
        bodyDrag = true; dragging = true; sheetEl.classList.add('dragging');
        startY = e.touches[0].clientY;
        startFrac = parseFloat(sheetEl.dataset.frac || SNAP.half);
        if (e.cancelable) e.preventDefault();
      } else if (dy < 0) { bodyArmed = false; }
    }, { passive: false });
    dirApp.addEventListener('touchend', function () { bodyDrag = false; }, { passive: true });

    // Tapping a member card → if the sheet is covering most of the screen, drop
    // it to half so the map/pin shows (zoom is the canonical zoomToMember
    // handler; we only adjust the sheet).
    var results = document.getElementById('dir-results');
    if (results) results.addEventListener('click', function (e) {
      if (e.target.closest('.dir-connect, .dir-msg, .dir-link')) return;
      if (e.target.closest('.dir-card__main')) {
        var f = parseFloat(sheetEl.dataset.frac || SNAP.half);
        if (f < SNAP.half - 0.02) setFrac(SNAP.half);
      }
    });
  }

  // ---------- live member count mirror (#dir-meta → sheet + search bar) ----------
  function mirrorCount() {
    var src = document.getElementById('dir-meta');
    var sheetMeta = document.getElementById('lgdm-meta');
    var ph = document.querySelector('#lgdm-search .lgdm-ubar__ph');
    if (!src) return;
    function sync() {
      var t = (src.textContent || '').trim();
      if (sheetMeta) sheetMeta.textContent = t;
      // Keep the search placeholder generic but append the count when known.
      if (ph && /\d/.test(t)) ph.setAttribute('data-count', t);
    }
    sync();
    try { new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true }); } catch (e) {}
  }

  // ---------- map-driven list (search members as you pan) ----------
  // Port of the desktop layer (directory-desktop.js): as the user pans/zooms, the
  // results sheet re-queries members ordered by distance from the map center, so
  // the member nearest the center is the TOP listing. The directory API sorts by
  // distance ASC whenever lat/lng are set — we feed the map's (visible) center as
  // that anchor and let the page's renderResults() draw the cards.
  //
  // Mobile twist: the results sheet overlays the LOWER part of the map, so the true
  // container center (dirMap.getCenter) sits BEHIND the sheet. We anchor instead on
  // the center of the UNOBSCURED map — the midpoint of the band above the sheet's
  // top edge — so "what you see centered" is the top result. Bounds filtering still
  // uses the real full-container getBounds(), so nothing on-screen is dropped.
  var moveTimer = null, listFetchSeq = 0;

  function visibleCenterLatLng() {
    try {
      var sz = dirMap.getSize();                       // container pixel space
      var vh, sh = document.getElementById('lgdm-sheet');
      if (sh) { var t = parseFloat(sh.style.top); if (!isNaN(t)) vh = t - HEADER; }
      if (vh == null || vh < 40) vh = sz.y / 2;        // sheet missing/degenerate → true center
      return dirMap.containerPointToLatLng([sz.x / 2, Math.max(40, vh) / 2]);
    } catch (e) { return dirMap.getCenter(); }
  }
  function viewportRadiusMi() {
    try {
      var c = visibleCenterLatLng(), b = dirMap.getBounds();
      return Math.max(1, Math.min(500, Math.ceil(c.distanceTo(b.getNorthEast()) / 1609.34)));
    } catch (e) { return 50; }
  }
  function mapListQs() {
    var sp = new URLSearchParams();
    try { ['inst', 'skill', 'music', 'cred'].forEach(function (k) { (state[k] || []).forEach(function (v) { sp.append(k + '[]', v); }); }); } catch (e) {}
    var c = visibleCenterLatLng();
    sp.set('lat', c.lat); sp.set('lng', c.lng);
    sp.set('radius', viewportRadiusMi());
    try { sp.set('sort', curSort); } catch (e) {}
    sp.set('page', '1'); sp.set('page_size', '200');
    return sp.toString();
  }
  function mapKey() {
    try { var c = dirMap.getCenter(); return c.lat.toFixed(5) + ',' + c.lng.toFixed(5) + '@' + dirMap.getZoom(); }
    catch (e) { return ''; }
  }
  function refreshListFromMap() {
    if (typeof dirMap === 'undefined' || !dirMap || typeof renderResults !== 'function') return;
    var seq = ++listFetchSeq, bounds;
    try { bounds = dirMap.getBounds(); } catch (e) { return; }
    try { dirMap.__lgdmLastKey = mapKey(); } catch (e) {}   // set BEFORE await → next no-move moveend is skipped
    fetch('/profile-api/v0/directory/members?' + mapListQs(), { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || seq !== listFetchSeq) return;             // a newer pan superseded this fetch
        var raw = d.items || [];
        var inView = raw.filter(function (it) {
          var loc = it.location;
          if (!loc || loc.lat == null || loc.lng == null) return false;
          try { return bounds.contains([loc.lat, loc.lng]); } catch (e) { return false; }
        });
        // Normally show exactly what's in view; if nothing is strictly in bounds but
        // members ARE nearby, fall back to nearest-to-center so the list never reads empty.
        var items = inView.length ? inView : raw.slice(0, 60);
        renderResults(items, false);                        // closest-to-center first
        var meta = document.getElementById('dir-meta');
        if (meta) meta.textContent = items.length + ' member' + (items.length === 1 ? '' : 's') + ' in view';
        var more = document.getElementById('dir-more'); if (more) more.hidden = true;
      }).catch(function () {});
  }
  function onMapMove() {
    clearTimeout(moveTimer);
    moveTimer = setTimeout(function () {
      try { if (dirMap.__lgdmLastKey && dirMap.__lgdmLastKey === mapKey()) return; } catch (e) {}  // no real move (e.g. invalidateSize) → skip
      refreshListFromMap();
    }, 350);
  }
  // Attach the moveend listener once the page's Leaflet map exists (it inits on
  // DOMContentLoaded; this layer may run before or after). Poll like centerOnDefault.
  function wireMapDriven() {
    var tries = 0;
    (function tick() {
      if (typeof dirMap !== 'undefined' && dirMap && dirMap.on) {
        if (dirMap.__lgdmMapDriven) return;
        dirMap.__lgdmMapDriven = true;
        dirMap.on('moveend', onMapMove);
        refreshListFromMap();                               // anchor the list on the initial view
        return;
      }
      if (++tries < 40) setTimeout(tick, 250);
    })();
  }

  // ---------- styles ----------
  function injectCss() {
    if (document.getElementById('lgdm-css')) return;
    var nav = 'calc(' + NAV + 'px + env(safe-area-inset-bottom,0px))';
    var css = [
      /* full-stage map behind everything (scope html.lgdm so it beats mobile-directory.css's sticky 240px) */
      'html.lgdm #dir-map{position:fixed!important;top:' + HEADER + 'px!important;left:0!important;right:0!important;' +
        'bottom:' + nav + '!important;height:auto!important;width:auto!important;z-index:1!important;border-radius:0!important;box-shadow:none!important}',
      /* Ocean fill: at a zoomed-out world view the tall container shows area
         above/below the single world copy. Tint it sea-blue (like the OSM ocean
         + the sandbox #aadaf0) so the empty band reads as water, not broken gray. */
      'html.lgdm #dir-map,html.lgdm #dir-map .leaflet-container{background:#aad3df!important}',
      'html.lgdm .dir-header{display:none!important}',
      /* Remove the shared top bar on this page so the map runs to the very top
         (Buck 2026-06-08). Bottom tab bar still carries navigation. */
      'html.lgdm .lg-chrome{display:none!important}',
      'html.lgdm .leaflet-control-zoom{display:none!important}',  /* pinch-to-zoom; Buck removed the +/- in the sandbox */
      'html.lgdm.lgdm-noscroll,html.lgdm.lgdm-noscroll body{overflow:hidden}',
      'html.lgdm body{background:var(--lg-cream,#fbfbf8)}',

      /* floating search bar — pinned to the top, clearing the status bar/notch */
      '#lgdm-search{position:fixed;left:10px;right:10px;top:calc(env(safe-area-inset-top,0px) + 10px);z-index:1200;pointer-events:none}',
      '#lgdm-search>*{pointer-events:auto}',
      '.lgdm-ubar{width:100%;display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--lg-line,#e3ddd0);' +
        'border-radius:999px;padding:6px 8px 6px 14px;box-shadow:0 4px 16px rgba(26,29,26,.18)}',
      '.lgdm-ubar__ico{width:18px;height:18px;flex:0 0 auto;color:var(--lg-mute,#6b6f6b)}',
      /* the relocated #dir-loc location field — borderless, fills the bar */
      '.lgdm-ubar .lgdm-loc-input{flex:1 1 auto;min-width:0;border:0!important;outline:0!important;background:transparent!important;' +
        'box-shadow:none!important;padding:7px 0!important;margin:0!important;height:auto!important;' +
        'font:15px/1.2 var(--lg-font-sans,system-ui,sans-serif)!important;color:var(--lg-ink,#323532)!important;width:100%!important}',
      '.lgdm-ubar__slot{flex:1 1 auto;min-width:0;display:flex}',
      '.lgdm-ubar__clear{flex:0 0 auto;width:26px;height:26px;border:0;background:none;color:var(--lg-mute,#6b6f6b);' +
        'font-size:20px;line-height:1;cursor:pointer;border-radius:50%}',
      '.lgdm-ubar__filt{flex:0 0 auto;width:34px;height:34px;border:0;border-radius:999px;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52);display:flex;align-items:center;justify-content:center;cursor:pointer}',
      '.lgdm-ubar__filt svg{width:16px;height:16px}',
      /* location row is now in the bar → hide its (headless) row in the filter sheet */
      '#lgdm-fbody .filt.loc{display:none!important}',
      /* unified autocomplete dropdown — on <body>, fixed under the bar, ABOVE the sheet */
      '#lgdm-suggest{position:fixed;left:10px;right:10px;top:calc(env(safe-area-inset-top,0px) + 62px);z-index:1500;' +
        'background:#fff;border:1px solid var(--lg-line,#e3ddd0);border-radius:14px;box-shadow:0 12px 30px rgba(26,29,26,.22);' +
        'max-height:58vh;overflow:auto;-webkit-overflow-scrolling:touch;padding:4px}',
      '.lgdm-sg-h{font:700 11px/1 var(--lg-font-sans,system-ui,sans-serif);letter-spacing:.06em;text-transform:uppercase;' +
        'color:var(--lg-mute,#6b6f6b);padding:10px 12px 6px}',
      '.lgdm-sg{display:flex;align-items:center;gap:11px;width:100%;text-align:left;border:0;background:none;cursor:pointer;' +
        'padding:9px 12px;border-radius:9px}',
      '.lgdm-sg:active{background:var(--lg-sage-tint,#eef2e3)}',
      '.lgdm-sg-ic{flex:0 0 auto;width:30px;height:30px;border-radius:50%;background:var(--lg-sage-tint,#eef2e3);' +
        'color:var(--lg-sage-d,#6b7c52);display:flex;align-items:center;justify-content:center}',
      '.lgdm-sg-ic svg{width:16px;height:16px}',
      '.lgdm-sg-tx{min-width:0;display:flex;flex-direction:column;gap:1px}',
      '.lgdm-sg-t{font:600 14.5px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-ink,#323532);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.lgdm-sg-s{font:12.5px/1.2 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',

      /* filters sheet */
      '#lgdm-filters{position:fixed;inset:0;z-index:2147482000;display:none}',
      '#lgdm-filters.open{display:block}',
      '.lgdm-fbd{position:absolute;inset:0;background:rgba(26,29,26,.45)}',
      '.lgdm-fsheet{position:absolute;left:0;right:0;bottom:0;max-height:88vh;display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:18px 18px 0 0;box-shadow:0 -10px 30px rgba(20,30,15,.3)}',
      '.lgdm-fsheet__hd{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:14px 16px 10px;' +
        'font:700 16px/1 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a);border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '.lgdm-fclose{border:0;background:none;font-size:26px;line-height:1;color:var(--lg-mute,#6b6f6b);cursor:pointer;padding:0 4px}',
      '.lgdm-fsheet__body{flex:1 1 auto;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:12px 14px}',
      '.lgdm-fsheet__foot{flex:0 0 auto;padding:12px 14px calc(14px + env(safe-area-inset-bottom,0px));border-top:1px solid var(--lg-line,#e3ddd0)}',
      '.lgdm-fapply{width:100%;border:0;background:var(--lg-sage-d,#6b7c52);color:#fff;font:700 15px/1 var(--lg-font-sans,system-ui,sans-serif);' +
        'border-radius:999px;padding:13px;cursor:pointer}',
      /* the relocated filterbar stacks full-width inside the sheet */
      '#lgdm-fbody .dir-filterbar{display:flex;flex-direction:column;gap:14px;padding:0;margin:0;border:0;background:none;box-shadow:none}',
      '#lgdm-fbody .dir-filterbar .filt{flex:1 1 100%;width:100%}',

      /* results sheet */
      '#lgdm-sheet{position:fixed;left:0;right:0;top:60%;bottom:' + nav + ';z-index:1400;display:flex;flex-direction:column;' +
        'background:var(--lg-cream,#fbfbf8);border-radius:20px 20px 0 0;box-shadow:0 -10px 30px rgba(20,30,15,.25);' +
        'transition:top .26s cubic-bezier(.4,0,.2,1)}',
      '#lgdm-sheet.dragging{transition:none}',
      '.lgdm-grab{flex:0 0 auto;display:flex;justify-content:center;padding:9px 0 5px;cursor:grab;touch-action:none}',
      '.lgdm-grab__bar{width:42px;height:5px;border-radius:3px;background:#cfcabb}',
      '.lgdm-shd{flex:0 0 auto;padding:2px 16px 10px;border-bottom:1px solid var(--lg-line,#e3ddd0)}',
      '.lgdm-shd__ttl{font:700 17px/1.1 var(--lg-font-serif,Georgia,serif);color:var(--lg-charcoal,#1a1d1a)}',
      '.lgdm-shd__meta{font:13px/1 var(--lg-font-sans,system-ui,sans-serif);color:var(--lg-mute,#6b6f6b);font-weight:400;margin-left:2px}',
      /* the relocated results app becomes the scroll area */
      '#lgdm-sheet .dir-app{flex:1 1 auto;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;max-width:none;margin:0;padding:0}',
      '#lgdm-sheet .dir-app main{padding:12px 14px calc(20px + env(safe-area-inset-bottom,0px))}',
      '#lgdm-sheet .dir-results{display:flex;flex-direction:column;gap:11px}',
      // two-stage tap: 1st tap arms the card (map warps to their pin) + shows a hint
      '.dir-card.lgdm-armed{outline:2px solid var(--lg-sage,#87986a);outline-offset:-2px;position:relative}',
      '.dir-card.lgdm-armed::after{content:"Tap again to open profile";position:absolute;right:10px;top:8px;' +
        'background:var(--lg-sage,#87986a);color:#fff;border-radius:999px;padding:4px 10px;' +
        'font:700 10.5px/1 var(--lg-font-sans,system-ui,sans-serif);pointer-events:none;z-index:2}',
      'html[data-lguser-theme="dark"] .dir-card.lgdm-armed{outline-color:#9cb37d}'
    ].join('\n');
    var s = document.createElement('style');
    s.id = 'lgdm-css';
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  // ---------- two-stage member tap (Buck 2026-06-10) ----------
  // 1st tap on a member card WARPS the map to their pin (sheet drops to peek so
  // you see it); tapping the SAME member again opens their profile. Members with
  // no pin (location hidden) open the profile on the first tap as before. This
  // listener is registered by directory-mobile, which pwa.js loads BEFORE
  // profile-sheet — so on the first tap we can stopImmediatePropagation to keep
  // the profile-sheet /u/ interceptor from firing.
  var lgWarpSlug = null, lgWarpAt = 0;
  document.addEventListener('click', function (e) {
    try {
      if (!document.documentElement.classList.contains('lgdm')) return;
      var card = e.target.closest && e.target.closest('.dir-card');
      if (!card) return;
      var a = e.target.closest('a[href]') || card.querySelector('a[href*="/u/"]');
      var href = a ? (a.getAttribute('href') || '') : '';
      var mm = href.match(/\/u\/([^/?#]+)/);
      if (!mm) return;
      var slug = mm[1];
      // NB: canonical declares these with let/const (lexical globals, NOT window
      // props) — reference them bare behind typeof guards, like the rest of this file.
      var rec = (typeof pinMarkerBySlug !== 'undefined' && pinMarkerBySlug) ? pinMarkerBySlug[slug] : null;
      if (!rec) return;                                   // no pin → profile right away
      if (lgWarpSlug === slug && Date.now() - lgWarpAt < 90000) {
        lgWarpSlug = null;                                // 2nd tap → profile-sheet takes it
        card.classList.remove('lgdm-armed');
        return;
      }
      e.preventDefault(); e.stopImmediatePropagation();   // 1st tap: warp only
      lgWarpSlug = slug; lgWarpAt = Date.now();
      var prev = document.querySelectorAll('.dir-card.lgdm-armed');
      for (var i = 0; i < prev.length; i++) prev[i].classList.remove('lgdm-armed');
      card.classList.add('lgdm-armed');
      // the map-driven listing re-renders after the warp — re-apply the armed
      // highlight to whichever CURRENT card is this member
      function rearm() {
        if (lgWarpSlug !== slug) return;
        var live = document.querySelectorAll('.dir-card');
        for (var j = 0; j < live.length; j++) {
          var la = live[j].querySelector('a[href*="/u/"]');
          var lm = la && (la.getAttribute('href') || '').match(/\/u\/([^/?#]+)/);
          live[j].classList.toggle('lgdm-armed', !!(lm && lm[1] === slug));
        }
      }
      setTimeout(rearm, 900); setTimeout(rearm, 2200);
      try { setFrac(SNAP.peek); } catch (e2) {}           // show the map
      try {
        var dm = (typeof dirMap !== 'undefined') ? dirMap : null;
        var dc = (typeof dirCluster !== 'undefined') ? dirCluster : null;
        if (dm && dc && dc.zoomToShowLayer && rec.marker) {
          dc.zoomToShowLayer(rec.marker, function () {
            dm.setView([rec.lat, rec.lng], Math.max(dm.getZoom(), 12), { animate: true });
            rec.marker.openPopup();
          });
        } else if (dm && rec.lat != null) {
          dm.setView([rec.lat, rec.lng], 12, { animate: true });
          if (rec.marker && rec.marker.openPopup) rec.marker.openPopup();
        }
      } catch (e3) {}
    } catch (e4) {}
  }, true);

  // Kick off — at the END, so all top-level `var`s above (SNAP, sheetEl…) are
  // assigned before boot()/buildSheet() reference them.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
