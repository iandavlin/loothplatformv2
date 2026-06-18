/* directory-desktop.js — Google-Maps-style desktop directory.
 *
 * Desktop counterpart to directory-mobile.js. Buck-owned client layer, loaded
 * via /pwa.js, path-gated to /directory and only on >=641 (desktop). Mobile and
 * the canonical markup (directory-members.php / directory.css) are UNTOUCHED —
 * this layers over the same shared DOM the way the mobile layer does, so the two
 * surfaces never fight.
 *
 * Look Buck signed off in map-styles/desktop.html (2026-06-09):
 *   Rows list · Medium 400px column · Top-left single search bar · Map-fixed
 *   (only the list scrolls) · no zoom +/- buttons (scroll-wheel zooms).
 *
 * What it does at runtime (idempotent, never throws past its try/catch):
 *   • Two-pane: left results column (header + scrolling .dir-app) beside a
 *     full-height map (#dir-map fills .dir-mapwrap).
 *   • Single Google-style search bar floating top-left over the map: the REAL
 *     #dir-loc location input (with its autocomplete) + a Filters button that
 *     opens a popover holding the rest of #dir-filterbar (radius, instruments,
 *     skills, music, credentials, sort) — server filtering is unchanged, just
 *     relocated behind one click.
 *   • Hover-sync: hover a member card -> its map pin highlights; hover a pin ->
 *     its card highlights and scrolls into view.
 *
 * The card markup is already the canonical .dir-card markup, so no card
 * restructure — only layout chrome.
 */
(function () {
  'use strict';
  if (window.__loothDirDesktop) return;

  // ---- gate: directory path + desktop viewport ----
  var path = location.pathname || '';
  if (path.indexOf('/directory') !== 0) return;
  function isMobile() { return window.matchMedia('(max-width:640px)').matches; }
  if (isMobile()) return;                       // mobile owns its own layer
  window.__loothDirDesktop = true;

  document.documentElement.classList.add('lgdd');
  injectCss();

  function injectCss() {
    if (document.getElementById('lgdd-css')) return;
    var s = document.createElement('style');
    s.id = 'lgdd-css';
    s.textContent = [
      '@media (min-width:641px){',
      // map-fixed app surface: no page scroll, footer hidden, zoom buttons gone
      '  html.lgdd body{overflow:hidden}',
      '  html.lgdd .lg-chrome-foot{display:none}',
      '  html.lgdd .leaflet-control-zoom{display:none}',

      // two-pane.  --lgdd-col: the results column is ~400px at normal desktop but is
      // allowed to breathe on ultrawide (Buck runs a 3440px monitor — a hard 400px next
      // to a 3000px map reads as a thin rail). The filters popover keys its max-width off
      // the same var so it can never overflow the viewport on a narrow-but-desktop width.
      '  html.lgdd{--lgdd-col:clamp(400px, 24vw, 560px)}',
      '  html.lgdd .dir-mapsplit{display:flex;height:calc(100vh - 61px);overflow:hidden}',
      '  html.lgdd .dir-pane-left{flex:0 0 var(--lgdd-col);max-width:var(--lgdd-col);min-width:0;display:flex;' +
        'flex-direction:column;background:var(--lg-cream);border-right:1px solid var(--lg-line);' +
        'box-shadow:2px 0 12px rgba(26,29,26,.06);z-index:5}',
      '  html.lgdd .dir-header{margin:0;padding:14px 18px 12px;border-bottom:1px solid var(--lg-line)}',
      '  html.lgdd .dir-app{flex:1 1 auto;overflow-y:auto;display:block;max-width:none;margin:0;padding:14px 16px 28px;scrollbar-gutter:stable both-edges}',
      '  html.lgdd #dir-more[hidden]{display:none}',   // canonical edit.css forces .dir-load-more{display:block} over [hidden]; re-hide on the desktop surface
      '  html.lgdd .dir-app>main{display:block}',
      '  html.lgdd .dir-results{display:flex;flex-direction:column;gap:12px;grid-template-columns:none}',
      '  html.lgdd .dir-mapwrap{flex:1 1 auto;position:relative;min-width:0}',
      '  html.lgdd .dir-map{position:absolute!important;inset:0;height:100%!important;width:100%;z-index:0;aria-hidden:false}',

      // single Google-style search bar (over the map, top-left)
      '  html.lgdd .dir-filterbar{position:absolute;top:16px;left:16px;right:auto;z-index:1000;' +
        'width:min(420px,calc(100% - 32px));margin:0;padding:0;border:0;background:none}',
      '  html.lgdd .gmaps-search{display:flex;align-items:center;gap:6px;background:#fff;' +
        'border:1px solid var(--lg-line);border-radius:999px;padding:6px 8px 6px 14px;box-shadow:0 4px 18px rgba(26,29,26,.20)}',
      '  html.lgdd .gmaps-search:focus-within{box-shadow:0 6px 24px rgba(26,29,26,.26);border-color:var(--lg-sage)}',
      '  html.lgdd .gmaps-search__icon{display:flex;color:var(--lg-mute);flex:0 0 auto}',
      '  html.lgdd .gmaps-search .filt.loc{flex:1 1 auto;min-width:0;margin:0;padding:0}',
      '  html.lgdd .gmaps-search .filt.loc .flab{display:none}',
      '  html.lgdd .gmaps-search #dir-loc{border:0;outline:0;background:none;width:100%;padding:7px 2px;' +
        'font:inherit;font-size:14.5px;color:var(--lg-ink)}',
      '  html.lgdd .gmaps-search__clear{border:0;background:none;color:var(--lg-mute);font-size:20px;' +
        'line-height:1;cursor:pointer;padding:0 4px;flex:0 0 auto}',
      '  html.lgdd .gmaps-search__divider{width:1px;height:24px;background:var(--lg-line);flex:0 0 auto}',
      '  html.lgdd .gmaps-search__filt{display:flex;align-items:center;gap:6px;flex:0 0 auto;border:0;' +
        'background:none;color:var(--lg-sage-d);cursor:pointer;border-radius:999px;padding:7px 12px;' +
        'font:600 13px/1 var(--lg-font-sans)}',
      '  html.lgdd .gmaps-search__filt:hover{background:var(--lg-sage-tint)}',
      '  html.lgdd .gmaps-search__filt.on{background:var(--lg-sage-d);color:#fff}',

      // filters popover (the moved facets live here)
      '  html.lgdd .gmaps-filters{position:absolute;top:calc(100% + 8px);left:0;' +
        'width:min(300px, calc(100vw - var(--lgdd-col) - 28px));' +
        'background:#fff;border:1px solid var(--lg-line);border-radius:16px;' +
        'box-shadow:0 12px 30px rgba(26,29,26,.22);padding:14px;display:flex;flex-direction:column;gap:12px}',
      '  html.lgdd .gmaps-filters[hidden]{display:none}',
      '  html.lgdd .gmaps-filters .filt{flex:0 0 auto;min-width:0;position:relative}',
      '  html.lgdd .gmaps-filters .flab{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;' +
        'color:var(--lg-mute);font-weight:700;margin-bottom:6px}',
      '  html.lgdd .gmaps-filters select{width:100%;padding:8px 10px;font:inherit;font-size:13px;' +
        'border:1px solid var(--lg-line);border-radius:8px;background:#fff}',
      '  html.lgdd .gmaps-filters__foot{display:flex;justify-content:flex-end;border-top:1px solid var(--lg-line);padding-top:12px}',
      '  html.lgdd .gmaps-filters__foot .apply{border:1px solid var(--lg-sage-d);background:var(--lg-sage-d);' +
        'color:#fff;font:600 13px/1 var(--lg-font-sans);border-radius:999px;padding:9px 16px;cursor:pointer}',

      // --- Theme-lock the floating search bar + filters popover ---------------------
      // These surfaces float OVER the always-light OSM map, so (like Google Maps) they
      // stay a LIGHT surface in EVERY app theme. The site dark theme remaps the --lg-*
      // tokens to light values and restyles inputs/selects dark — which made the
      // placeholder invisible and the location field a dark box inside the white pill.
      // Lock these specific over-map surfaces to the light brand palette using the literal
      // hex the tokens carry in light mode (a justified token exception). !important is
      // needed to beat the dark theme's higher-specificity / !important input rules.
      '  html.lgdd .gmaps-search #dir-loc{background:transparent!important;color:#1a1d1a!important}',
      '  html.lgdd .gmaps-search #dir-loc::placeholder{color:#6b6f6b!important;opacity:1}',
      '  html.lgdd .gmaps-search__icon{color:#6b6f6b!important}',
      '  html.lgdd .gmaps-search__clear{color:#6b6f6b!important}',
      '  html.lgdd .gmaps-search__filt{color:#6b7c52!important}',
      '  html.lgdd .gmaps-search__filt.on{background:#6b7c52!important;color:#fff!important}',
      '  html.lgdd .gmaps-filters .flab{color:#6b6f6b!important}',
      '  html.lgdd .gmaps-filters select, html.lgdd .gmaps-filters input{background:#fff!important;color:#1a1d1a!important;border-color:#e3ddd0!important}',
      '  html.lgdd .gmaps-filters .ms-control{background:#fff!important;border-color:#e3ddd0!important}',
      '  html.lgdd .gmaps-filters .ms-search{background:transparent!important;color:#1a1d1a!important}',
      '  html.lgdd .gmaps-filters .ms-menu{background:#fff!important;border-color:#e3ddd0!important}',
      '  html.lgdd .gmaps-filters .ms-opt{color:#1a1d1a!important}',
      '  html.lgdd .gmaps-filters .ms-chip{background:#eef2e3!important;color:#6b7c52!important}',
      // sort toggle: use the sage brand accent (canonical --accent is the editor-lane rust)
      '  html.lgdd .gmaps-filters .dir-sort button{background:#fff!important;color:#6b6f6b!important;border-color:#e3ddd0!important}',
      '  html.lgdd .gmaps-filters .dir-sort button.on{background:#6b7c52!important;color:#fff!important}',
      '  html.lgdd .gmaps-filters__foot .apply{background:#6b7c52!important;border-color:#6b7c52!important;color:#fff!important}',

      // unified members+places suggest dropdown (under the search bar, over the map)
      '  html.lgdd #lgdd-suggest{position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:1100;' +
        'background:#fff;border:1px solid var(--lg-line);border-radius:14px;box-shadow:0 12px 30px rgba(26,29,26,.22);' +
        'max-height:62vh;overflow:auto;padding:4px;display:none}',
      '  html.lgdd #lgdd-suggest.open{display:block}',
      '  html.lgdd .lgdd-sg-h{font:700 11px/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;' +
        'color:var(--lg-mute);padding:10px 12px 6px}',
      '  html.lgdd .lgdd-sg{display:flex;align-items:center;gap:11px;width:100%;text-align:left;border:0;background:none;' +
        'cursor:pointer;padding:9px 12px;border-radius:9px}',
      '  html.lgdd .lgdd-sg:hover{background:var(--lg-sage-tint)}',
      '  html.lgdd .lgdd-sg-ic{flex:0 0 auto;width:30px;height:30px;border-radius:50%;background:var(--lg-sage-tint);' +
        'color:var(--lg-sage-d);display:flex;align-items:center;justify-content:center}',
      '  html.lgdd .lgdd-sg-ic svg{width:16px;height:16px}',
      '  html.lgdd .lgdd-sg-tx{min-width:0;display:flex;flex-direction:column;gap:1px}',
      '  html.lgdd .lgdd-sg-t{font:600 14.5px/1.2 var(--lg-font-sans);color:var(--lg-ink);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '  html.lgdd .lgdd-sg-s{font:12.5px/1.2 var(--lg-font-sans);color:var(--lg-mute);' +
        'white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      // light-lock the dropdown too — it floats over the always-light map, stays light in dark theme
      '  html.lgdd #lgdd-suggest{background:#fff!important;border-color:#e3ddd0!important}',
      '  html.lgdd .lgdd-sg-h{color:#6b6f6b!important}',
      '  html.lgdd .lgdd-sg-ic{background:#eef2e3!important;color:#6b7c52!important}',
      '  html.lgdd .lgdd-sg:hover{background:#eef2e3!important}',
      '  html.lgdd .lgdd-sg-t{color:#1a1d1a!important}',
      '  html.lgdd .lgdd-sg-s{color:#6b6f6b!important}',

      // list card: hover-sync highlight
      '  html.lgdd .dir-card{cursor:pointer}',
      '  html.lgdd .dir-card.is-hot{border-color:var(--lg-sage);box-shadow:0 6px 18px rgba(26,29,26,.14)}',
      // safety net: clamp pathological long names to 2 lines (current data wraps cleanly)
      '  html.lgdd .dir-results .dir-card .name{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
      // hot map pin (scale the inner dot only — Leaflet owns the marker transform)
      '  html.lgdd .lgdd-pin-hot{z-index:1000!important}',
      '  html.lgdd .lgdd-pin-hot>div{transform:scale(1.55)!important;box-shadow:0 2px 10px rgba(0,0,0,.5)!important}',
      // rich pin popup: the page popup becomes a clone of the member list card (banner + all)
      '  html.lgdd .leaflet-popup.lgdd-cardpop .leaflet-popup-content-wrapper{padding:0;border-radius:16px;overflow:hidden;box-shadow:0 12px 34px rgba(26,29,26,.30)}',
      '  html.lgdd .leaflet-popup.lgdd-cardpop .leaflet-popup-content{margin:0;width:300px!important}',
      '  html.lgdd .leaflet-popup.lgdd-cardpop .dir-card{border:0;border-radius:16px;cursor:default;background:#fff}',
      '  html.lgdd .leaflet-popup.lgdd-cardpop .dir-card:hover{box-shadow:none;transform:none}',
      '  html.lgdd .leaflet-popup.lgdd-cardpop .leaflet-popup-close-button{z-index:2;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.5);width:26px;height:26px;font-size:20px}',
      // map-driven ordering is distance-from-center, so the Joined sort toggle is inert here — hide it
      '  html.lgdd .gmaps-filters .filt.sortbox{display:none}',

      // --- drop-off locations ---
      // distinct teal drop pin
      '  html.lgdd .lgdd-drop-pin{width:14px;height:14px;border-radius:50%;background:#0d7a6f;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.45)}',
      // branded drop popup (banner + logo + location name); light-locked like the card popup
      '  html.lgdd .leaflet-popup.lgdd-droppop-wrap .leaflet-popup-content-wrapper{padding:0;border-radius:16px;overflow:hidden;box-shadow:0 12px 34px rgba(26,29,26,.30)}',
      '  html.lgdd .leaflet-popup.lgdd-droppop-wrap .leaflet-popup-content{margin:0;width:264px!important}',
      '  html.lgdd .leaflet-popup.lgdd-droppop-wrap .leaflet-popup-close-button{z-index:2;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.55);width:26px;height:26px;font-size:20px}',
      '  html.lgdd .lgdd-droppop{background:#fff}',
      '  html.lgdd .lgdd-droppop__banner{height:76px;background:#eef2e3}',
      '  html.lgdd .lgdd-droppop__banner img{width:100%;height:100%;object-fit:cover;display:block}',
      '  html.lgdd .lgdd-droppop__body{display:flex;gap:11px;align-items:flex-start;padding:12px 14px 14px}',
      '  html.lgdd .lgdd-droppop__logo{width:46px;height:46px;border-radius:50%;object-fit:cover;flex:0 0 46px;border:2px solid #fff;margin-top:-34px;background:#fff;box-shadow:0 1px 5px rgba(0,0,0,.25)}',
      '  html.lgdd .lgdd-droppop--nobanner .lgdd-droppop__logo{margin-top:0}',
      '  html.lgdd .lgdd-droppop__logo--ph{display:flex;align-items:center;justify-content:center;font:700 19px/1 var(--lg-font-serif);color:#fff;background:#6b7c52}',
      '  html.lgdd .lgdd-droppop__tx{padding-top:2px}',
      '  html.lgdd .lgdd-droppop__kicker{font:700 9px/1 var(--lg-font-sans);letter-spacing:.13em;text-transform:uppercase;color:#0d7a6f}',
      '  html.lgdd .lgdd-droppop__name{font:700 15.5px/1.2 var(--lg-font-serif);color:#1a1d1a;margin-top:4px}',
      '  html.lgdd .lgdd-droppop__mem{font:400 12px/1.3 var(--lg-font-sans);color:#6b6f6b;margin-top:3px}',
      // left-list expansion: toggle + sub-cards
      '  html.lgdd .lgdd-drop-toggle{display:flex;align-items:center;justify-content:space-between;width:100%;margin:10px 0 0;padding:9px 12px;border:1px solid #d4e0b8;background:#eef2e3;color:#4d5a39;border-radius:10px;font:700 12.5px/1 var(--lg-font-sans);cursor:pointer}',
      '  html.lgdd .lgdd-drop-toggle:hover{background:#e6eed5}',
      '  html.lgdd .lgdd-drop-toggle__chev{transition:transform .15s;font-size:11px}',
      '  html.lgdd .dir-card.lgdd-drops-open .lgdd-drop-toggle__chev{transform:rotate(180deg)}',
      '  html.lgdd .lgdd-drop-list{margin-top:8px;display:flex;flex-direction:column;gap:6px}',
      '  html.lgdd .lgdd-drop-list[hidden]{display:none}',
      '  html.lgdd .lgdd-drop-sub{display:flex;align-items:center;gap:9px;width:100%;text-align:left;padding:9px 11px;border:1px solid var(--lg-line);background:#fff;border-radius:10px;cursor:pointer}',
      '  html.lgdd .lgdd-drop-sub:hover{border-color:#0d7a6f;background:#f3faf8}',
      '  html.lgdd .lgdd-drop-sub__pin{width:9px;height:9px;border-radius:50%;background:#0d7a6f;flex:0 0 9px}',
      '  html.lgdd .lgdd-drop-sub__name{flex:1 1 auto;min-width:0;font:600 13px/1.3 var(--lg-font-sans);color:#1a1d1a}',
      '  html.lgdd .lgdd-drop-sub__go{color:#0d7a6f;font-size:14px;flex:0 0 auto}',
      '}'
    ].join('\n');
    (document.head || document.documentElement).appendChild(s);
  }

  // SVG icons
  var ICO_SEARCH = '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>';
  var ICO_FILT = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" ' +
    'stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/>' +
    '<line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>';

  // ---------- two-pane layout ----------
  function buildTwoPane() {
    if (document.querySelector('.dir-mapsplit')) return true;
    var header = document.querySelector('.dir-header');
    var mapEl = document.getElementById('dir-map');
    var filterbar = document.getElementById('dir-filterbar');
    var dirApp = document.querySelector('.dir-app');
    if (!mapEl || !filterbar || !dirApp || !header) return false;

    var split = document.createElement('div'); split.className = 'dir-mapsplit';
    var left = document.createElement('div'); left.className = 'dir-pane-left';
    var right = document.createElement('div'); right.className = 'dir-mapwrap';
    header.parentNode.insertBefore(split, header);
    left.appendChild(header);
    left.appendChild(dirApp);
    right.appendChild(mapEl);
    right.appendChild(filterbar);
    split.appendChild(left);
    split.appendChild(right);
    mapEl.setAttribute('aria-hidden', 'false');
    return true;
  }

  // ---------- single search bar + filters popover ----------
  function buildSearchBar() {
    var filterbar = document.getElementById('dir-filterbar');
    if (!filterbar || filterbar.querySelector('.gmaps-search')) return;

    var locFilt = filterbar.querySelector('.filt.loc');   // holds #dir-loc + its autocomplete dropdown (child)
    if (!locFilt) return;

    var search = document.createElement('div');
    search.className = 'gmaps-search';
    var icon = document.createElement('span'); icon.className = 'gmaps-search__icon'; icon.innerHTML = ICO_SEARCH;
    var clear = document.createElement('button');
    clear.type = 'button'; clear.className = 'gmaps-search__clear'; clear.setAttribute('aria-label', 'Clear search');
    clear.innerHTML = '&times;'; clear.hidden = true;
    var divider = document.createElement('span'); divider.className = 'gmaps-search__divider';
    var filtBtn = document.createElement('button');
    filtBtn.type = 'button'; filtBtn.className = 'gmaps-search__filt'; filtBtn.setAttribute('aria-label', 'Filters');
    filtBtn.innerHTML = ICO_FILT + '<span>Filters</span>';

    search.appendChild(icon);
    search.appendChild(locFilt);            // move the real location field (dropdown rides along — it's a child)
    search.appendChild(clear);
    search.appendChild(divider);
    search.appendChild(filtBtn);

    // popover: move every other .filt facet into it (radius, instrument/skill/music/cred ms, sort, view)
    var pop = document.createElement('div'); pop.className = 'gmaps-filters'; pop.hidden = true;
    filterbar.querySelectorAll('.filt').forEach(function (f) {
      if (!f.classList.contains('loc')) pop.appendChild(f);
    });
    var foot = document.createElement('div'); foot.className = 'gmaps-filters__foot';
    foot.innerHTML = '<button type="button" class="apply">Show members</button>';
    pop.appendChild(foot);

    filterbar.appendChild(search);
    filterbar.appendChild(pop);

    // wire: Filters toggle
    function openF() { pop.hidden = false; filtBtn.classList.add('on'); }
    function closeF() { pop.hidden = true; filtBtn.classList.remove('on'); }
    filtBtn.addEventListener('click', function (e) { e.stopPropagation(); pop.hidden ? openF() : closeF(); });
    foot.querySelector('.apply').addEventListener('click', closeF);
    document.addEventListener('click', function (e) {
      if (!pop.hidden && !pop.contains(e.target) && e.target !== filtBtn && !filtBtn.contains(e.target)) closeF();
    });

    // wire: unified members+places autocomplete (parity with directory-mobile.js).
    // The canonical page wires a Nominatim location autocomplete onto #dir-loc whose
    // dropdown is a child of .filt.loc; left in place it would fight our dropdown. So
    // (exactly like the mobile layer) clone+replace #dir-loc to STRIP those listeners,
    // keep id='dir-loc' so the page's buildQs()/applyFilters() still read it, then
    // attach our own instant-members + debounced-places suggester.
    var origLoc = document.getElementById('dir-loc');
    if (origLoc) {
      var loc = origLoc.cloneNode(true);
      loc.value = origLoc.value;
      origLoc.parentNode.replaceChild(loc, origLoc);
      loc.setAttribute('placeholder', 'Search members or a place');
      loc.setAttribute('autocomplete', 'off');
      wireUnifiedSearch(loc, clear, filterbar);
    }
  }

  // ---------- unified autocomplete (members + places) ----------
  // Port of directory-mobile.js's wireUnifiedSearch onto the desktop bar: MEMBERS come
  // from a one-shot fetch of the pin index (names + coords, matched instantly/locally);
  // PLACES come from Nominatim (debounced). Picking a member runs the canonical member
  // zoom (dirCluster.zoomToShowLayer → dirMap.setView + popup) — the desktop map-driven
  // list then re-centers on them; picking a place drives #dir-lat/#dir-lng + applyFilters
  // (which fitBounds the map), identical to the location search it replaces.
  function sEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  var SICO_PERSON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>';
  var SICO_PIN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>';
  function wireUnifiedSearch(loc, clear, filterbar) {
    // dropdown anchors to .dir-filterbar (the over-map positioning context), under the bar.
    var dd = document.createElement('div'); dd.id = 'lgdd-suggest';
    filterbar.appendChild(dd);

    var members = [];
    fetch('/profile-api/v0/directory/members?pins=1', { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.pins) members = d.pins.map(function (p) { return { slug: p.slug, name: p.display_name || '', lat: p.lat, lng: p.lng, text: p.text || '' }; });
      })
      .catch(function () {});

    function syncClear() { clear.hidden = !(loc.value && loc.value.trim()); }
    function close() { dd.classList.remove('open'); }
    function open() { if (dd.children.length) dd.classList.add('open'); }
    function groupH(label) { var g = document.createElement('div'); g.className = 'lgdd-sg-h'; g.textContent = label; return g; }
    function row(ico, title, sub, onpick) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'lgdd-sg';
      b.innerHTML = '<span class="lgdd-sg-ic">' + ico + '</span><span class="lgdd-sg-tx"><span class="lgdd-sg-t">' + sEsc(title) + '</span>' + (sub ? '<span class="lgdd-sg-s">' + sEsc(sub) + '</span>' : '') + '</span>';
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
      // Canonical member zoom: zoomToShowLayer opens the cluster then setView+popup. The
      // desktop moveend handler re-queries the list anchored on the new center, so the
      // picked member lands at the top — no need to touch #dir-lat/#dir-lng here.
      try {
        var rec = (typeof pinMarkerBySlug !== 'undefined' && pinMarkerBySlug) ? pinMarkerBySlug[m.slug] : null;
        if (rec && typeof dirCluster !== 'undefined' && dirCluster && dirCluster.zoomToShowLayer) {
          dirCluster.zoomToShowLayer(rec.marker, function () {
            dirMap.setView([rec.lat, rec.lng], Math.max(dirMap.getZoom(), 13), { animate: true });
            rec.openPin ? rec.openPin() : rec.marker.openPopup();
          });
        } else if (typeof dirMap !== 'undefined' && dirMap && m.lat != null) {
          dirMap.setView([m.lat, m.lng], 13, { animate: true });   // gated/clustered fallback
        }
      } catch (e) {}
    }
    function pickPlace(lat, lng, label) {
      var la = document.getElementById('dir-lat'), ln = document.getElementById('dir-lng');
      if (la) la.value = lat; if (ln) ln.value = lng;
      loc.value = label; syncClear(); close();
      try { if (typeof applyFilters === 'function') applyFilters(); } catch (e) {}   // fitBounds the filtered pins
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
      var bar = document.querySelector('.gmaps-search');
      if (!dd.contains(e.target) && !(bar && bar.contains(e.target))) close();
    });
    clear.addEventListener('click', function () {
      loc.value = '';
      var la = document.getElementById('dir-lat'), ln = document.getElementById('dir-lng');
      if (la) la.value = ''; if (ln) ln.value = '';
      syncClear(); close(); dd.innerHTML = ''; lastPlaceQ = null;
      try { if (typeof applyFilters === 'function') applyFilters(); } catch (e) {}
      loc.focus();
    });
    syncClear();
  }

  // ---------- hover-sync (card <-> pin) ----------
  function pinRec(slug) {
    try { return (typeof pinMarkerBySlug !== 'undefined' && pinMarkerBySlug) ? pinMarkerBySlug[slug] : null; }
    catch (e) { return null; }
  }
  function cardEl(slug) {
    var m = document.querySelector('#dir-results .dir-card__main[data-slug="' + (window.CSS && CSS.escape ? CSS.escape(slug) : slug) + '"]');
    return m ? m.closest('.dir-card') : null;
  }
  // card -> pin: highlight the inner dot of the matching marker
  function setPinHot(slug, hot) {
    var rec = pinRec(slug);
    if (!rec || !rec.marker) return;
    var el = rec.marker._icon;
    if (!el) {
      // pin is collapsed inside a cluster at this zoom (most members at world view) —
      // light up the containing cluster bubble instead so the hover still gives feedback.
      try {
        var vis = (typeof dirCluster !== 'undefined' && dirCluster && dirCluster.getVisibleParent)
          ? dirCluster.getVisibleParent(rec.marker) : null;
        el = vis && vis._icon;
      } catch (e) {}
    }
    if (el) el.classList.toggle('lgdd-pin-hot', hot);
  }
  // pin -> card: highlight + bring the card into view
  function setCardHot(slug, hot) {
    var c = cardEl(slug);
    if (!c) return;
    c.classList.toggle('is-hot', hot);
    if (hot) { try { c.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {} }
  }
  function wireCardHover() {
    var results = document.getElementById('dir-results');
    if (!results || results.__lgddHover) return;
    results.__lgddHover = true;
    function slugFromEvt(e) {
      var card = e.target.closest && e.target.closest('.dir-card');
      if (!card) return null;
      var main = card.querySelector('.dir-card__main');
      return main ? main.getAttribute('data-slug') : null;
    }
    results.addEventListener('mouseover', function (e) { var s = slugFromEvt(e); if (s) setPinHot(s, true); });
    results.addEventListener('mouseout', function (e) { var s = slugFromEvt(e); if (s) setPinHot(s, false); });
  }
  // Open a marker's popup on hover (short intent delay so a quick mouse-pass doesn't
  // flash popups). We never auto-close on mouseout — the popup stays so you can move
  // into it (e.g. to click Connect); Leaflet closes it when another opens or on map click.
  var hoverTimer = null;
  function hoverOpenPopup(marker) {
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(function () { try { marker.openPopup(); } catch (e) {} }, 130);
  }
  function hoverCancel() { clearTimeout(hoverTimer); }

  // attach pin->card handlers to any markers that don't have them yet (re-run when
  // pins rebuild — pinMarkerBySlug is replaced wholesale by the page's plotPins).
  function attachPinHover() {
    var map;
    try { map = (typeof pinMarkerBySlug !== 'undefined') ? pinMarkerBySlug : null; } catch (e) { map = null; }
    if (!map) return;
    Object.keys(map).forEach(function (slug) {
      var rec = map[slug];
      if (rec && rec.marker) {
        rec.marker.__lgddSlug = slug;   // used by the rich-card popup lookup
        if (!rec.marker.__lgddHov) {
          rec.marker.__lgddHov = 1;
          rec.marker.on('mouseover', function () { setCardHot(slug, true); hoverOpenPopup(rec.marker); });
          rec.marker.on('mouseout', function () { setCardHot(slug, false); hoverCancel(); });
        }
      }
    });
  }

  // ---------- map-driven list (Feature: list reflects what's on the map) ----------
  // As the user pans/zooms, the left list shows the members in view, ordered by
  // distance from the MAP CENTER (closest = top). We pass the map center as the geo
  // anchor; the directory API then sorts by distance ASC and returns rich cards. We
  // re-use the page's renderResults() so the cards are identical to the side list.
  function cssEsc(s) { return (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/"/g, '\\"'); }
  function searched() {
    var la = document.getElementById('dir-lat'), loc = document.getElementById('dir-loc');
    return !!((la && la.value) || (loc && loc.value.trim()));
  }
  function viewportRadiusMi() {
    try {
      var c = dirMap.getCenter(), b = dirMap.getBounds();
      return Math.max(1, Math.ceil(c.distanceTo(b.getNorthEast()) / 1609.34));
    } catch (e) { return 50; }
  }
  function mapListQs() {
    var sp = new URLSearchParams();
    try { ['inst', 'skill', 'music', 'cred'].forEach(function (k) { (state[k] || []).forEach(function (v) { sp.append(k + '[]', v); }); }); } catch (e) {}
    // The API caps radius at 500mi — past that (continent/world zoom) a geo query
    // anchored mid-ocean returns NOTHING ("0 members in view" at full zoom-out,
    // Buck 2026-06-10). Outside the cap: send NO geo filter, fetch the full
    // listing, and let refreshListFromMap's in-bounds filter keep what's on screen.
    var rMi = viewportRadiusMi();
    if (rMi <= 500) {
      var c = dirMap.getCenter();
      sp.set('lat', c.lat); sp.set('lng', c.lng);
      sp.set('radius', rMi);
    }
    try { sp.set('sort', curSort); } catch (e) {}
    sp.set('page', '1'); sp.set('page_size', '200');
    return sp.toString();
  }
  // Cache the EXACT rendered card HTML per slug across every list view, so a pin's rich
  // popup works even when that member isn't in the currently-rendered list (no markup
  // duplication — we reuse whatever renderResults produced).
  var cardHTMLBySlug = {};
  function snapshotCards() {
    try {
      document.querySelectorAll('#dir-results .dir-card').forEach(function (card) {
        var main = card.querySelector('.dir-card__main'), s = main && main.getAttribute('data-slug');
        if (s) cardHTMLBySlug[s] = card.outerHTML;
      });
    } catch (e) {}
  }

  // ---------- drop-off locations (Feature) ----------
  // A member can list multiple drop-off points (Buck's VLG member has several). The
  // pins feed carries each member's dropoffs [{lat,lng,name}] (→ page global lastPins);
  // the member's banner+logo come from the list item (cardDataBySlug). We: (a) show the
  // drops as distinct teal pins whose popup is BRANDED with the member's banner + logo +
  // the location name, and (b) let the member's left-list card expand into sub-cards
  // (one per drop-off) that zoom the map to that location on click.
  var cardDataBySlug = {};                 // slug -> list item (banner_url, avatar_url, display_name)
  var myDropLayer = null, openDropSlug = null, myDropShownSlug = null;
  function memberData(slug) { return cardDataBySlug[slug] || null; }
  function dropEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function dropoffsFor(slug) {
    try {
      var arr = (typeof lastPins !== 'undefined' && lastPins) ? lastPins : [];
      for (var i = 0; i < arr.length; i++) if (arr[i] && arr[i].slug === slug && arr[i].dropoffs) return arr[i].dropoffs;
    } catch (e) {}
    return [];
  }
  // Branded popup for a drop-off pin: member banner + logo + the location's name.
  function buildDropPopupHTML(mem, dropName) {
    var banner = mem && mem.banner_url ? '<div class="lgdd-droppop__banner"><img src="' + dropEsc(mem.banner_url) + '" alt=""></div>' : '';
    var logo = mem && mem.avatar_url
      ? '<img class="lgdd-droppop__logo" src="' + dropEsc(mem.avatar_url) + '" alt="">'
      : '<div class="lgdd-droppop__logo lgdd-droppop__logo--ph">' + dropEsc(((mem && mem.display_name) || '?').slice(0, 1).toUpperCase()) + '</div>';
    var mname = dropEsc((mem && mem.display_name) || '');
    return '<div class="lgdd-droppop' + (banner ? '' : ' lgdd-droppop--nobanner') + '">' + banner +
      '<div class="lgdd-droppop__body">' + logo +
        '<div class="lgdd-droppop__tx">' +
          '<div class="lgdd-droppop__kicker">Drop-off location</div>' +
          '<div class="lgdd-droppop__name">' + dropEsc(dropName) + '</div>' +
          (mname ? '<div class="lgdd-droppop__mem">' + mname + '</div>' : '') +
        '</div>' +
      '</div></div>';
  }
  var DROP_ICON = null;
  function dropIcon() {
    if (!DROP_ICON && typeof L !== 'undefined') {
      DROP_ICON = L.divIcon({ className: '', html: '<div class="lgdd-drop-pin"></div>', iconSize: [16, 16], iconAnchor: [8, 8], popupAnchor: [0, -9] });
    }
    return DROP_ICON;
  }
  function clearMyDrops() { try { if (myDropLayer) myDropLayer.clearLayers(); } catch (e) {} myDropShownSlug = null; }
  // Plot one member's drop-off pins (no map move, so the list isn't disturbed).
  // Idempotent: if the same member's drops are already plotted, do nothing — otherwise a
  // post-zoom list refresh would clear+recreate the markers and close an open drop popup.
  function showMyDrops(slug) {
    try {
      if (!myDropLayer) myDropLayer = L.layerGroup().addTo(dirMap);
      if (myDropShownSlug === slug && myDropLayer.getLayers().length) return;
      myDropLayer.clearLayers();
      myDropShownSlug = slug;
      dropoffsFor(slug).forEach(function (dk) {
        if (dk.lat == null || dk.lng == null) return;
        var m = L.marker([dk.lat, dk.lng], { icon: dropIcon() });
        m.__lgddDropSlug = slug; m.__lgddDropName = dk.name || 'Drop-off location';
        m.bindPopup('<div class="lgdd-droppop lgdd-droppop--nobanner"><div class="lgdd-droppop__body"></div></div>');
        m.on('mouseover', function () { hoverOpenPopup(m); });
        m.on('mouseout', hoverCancel);
        myDropLayer.addLayer(m);
      });
    } catch (e) {}
  }
  // Zoom to a single drop-off and open its branded popup.
  function zoomToDrop(slug, dk) {
    try {
      openDropSlug = slug;
      showMyDrops(slug);                          // idempotent — keeps the existing markers
      dirMap.setView([dk.lat, dk.lng], 14, { animate: true });
      var openMatch = function () {
        if (!myDropLayer) return;
        myDropLayer.eachLayer(function (m) {
          try { var ll = m.getLatLng(); if (Math.abs(ll.lat - dk.lat) < 1e-5 && Math.abs(ll.lng - dk.lng) < 1e-5) m.openPopup(); } catch (e) {}
        });
      };
      openMatch();
      setTimeout(openMatch, 650);                 // re-assert after the post-zoom list refresh settles
    } catch (e) {}
  }
  // Inject the expand toggle + sub-cards onto any list card whose member has drop-offs.
  function enhanceDropCards() {
    try {
      document.querySelectorAll('#dir-results .dir-card').forEach(function (card) {
        if (card.__lgddDrops) return;
        var main = card.querySelector('.dir-card__main'), slug = main && main.getAttribute('data-slug');
        if (!slug) return;
        var ds = dropoffsFor(slug);
        if (!ds.length) return;
        card.__lgddDrops = true;
        var toggle = document.createElement('button');
        toggle.type = 'button'; toggle.className = 'lgdd-drop-toggle';
        toggle.innerHTML = '<span>' + ds.length + ' drop-off location' + (ds.length === 1 ? '' : 's') + '</span><span class="lgdd-drop-toggle__chev">▾</span>';
        var box = document.createElement('div');
        box.className = 'lgdd-drop-list'; box.hidden = true;
        ds.forEach(function (dk) {
          var sub = document.createElement('button');
          sub.type = 'button'; sub.className = 'lgdd-drop-sub';
          sub.innerHTML = '<span class="lgdd-drop-sub__pin"></span><span class="lgdd-drop-sub__name">' + dropEsc(dk.name || 'Drop-off location') + '</span><span class="lgdd-drop-sub__go">↗</span>';
          sub.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); zoomToDrop(slug, dk); });
          box.appendChild(sub);
        });
        function setOpen(open) {
          box.hidden = !open;
          card.classList.toggle('lgdd-drops-open', open);
          if (open) { openDropSlug = slug; showMyDrops(slug); }
          else if (openDropSlug === slug) { openDropSlug = null; clearMyDrops(); }
        }
        toggle.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); setOpen(box.hidden); });
        card.appendChild(toggle);
        card.appendChild(box);
        if (openDropSlug === slug) { setOpen(true); }   // survive list re-renders while expanded
      });
    } catch (e) {}
  }
  var listFetchSeq = 0;
  function refreshListFromMap() {
    if (typeof dirMap === 'undefined' || !dirMap || typeof renderResults !== 'function') return;
    var seq = ++listFetchSeq, bounds;
    try { bounds = dirMap.getBounds(); } catch (e) { return; }
    fetch('/profile-api/v0/directory/members?' + mapListQs(), { credentials: 'include' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || seq !== listFetchSeq) return;   // a newer move superseded this fetch
        var raw = d.items || [];
        raw.forEach(function (it) { if (it && it.slug) cardDataBySlug[it.slug] = it; });   // cache banner/logo for drop popups
        var inView = raw.filter(function (it) {
          var loc = it.location;
          if (!loc || loc.lat == null || loc.lng == null) return false;
          try { return bounds.contains([loc.lat, loc.lng]); } catch (e) { return false; }
        });
        // Normally show exactly what's in the viewport; but if nothing is strictly in
        // bounds yet members ARE nearby (e.g. zoomed in tighter than a city-coarsened
        // point), fall back to the nearest-to-center set so the list never reads empty.
        var items = inView.length ? inView : raw.slice(0, 60);
        // When a member's drop-off is focused, keep THEIR card pinned at the top even if the
        // tight zoom dropped their home pin out of view — but release the focus (and clear the
        // teal drop pins) once you've panned away from their drop area entirely.
        if (openDropSlug && cardDataBySlug[openDropSlug] && !items.some(function (it) { return it.slug === openDropSlug; })) {
          var anyDropInView = dropoffsFor(openDropSlug).some(function (dk) {
            try { return bounds.contains([dk.lat, dk.lng]); } catch (e) { return false; }
          });
          if (anyDropInView) items = [cardDataBySlug[openDropSlug]].concat(items);
          else { openDropSlug = null; clearMyDrops(); }
        }
        renderResults(items, false);   // closest-to-center first; identical rich cards to the side list
        snapshotCards();
        enhanceDropCards();
        var meta = document.getElementById('dir-meta');
        if (meta) meta.textContent = items.length + ' member' + (items.length === 1 ? '' : 's') + ' in view';
        var more = document.getElementById('dir-more'); if (more) more.hidden = true;
      }).catch(function () {});
  }
  var moveTimer = null;
  function onMapMove() { clearTimeout(moveTimer); moveTimer = setTimeout(refreshListFromMap, 350); }

  // FULL MAP on load (Ian 6/15): fit-to-all-pins / world extent — no auto-center on
  // the US, no geolocation; the viewer's own pin is just another pin. We let the page's
  // own fitBounds(all pins) stand and no longer override it. (The empty-list-at-world-
  // zoom this used to dodge is handled by mapListQs: past the 500mi cap it drops the geo
  // filter and the in-bounds pass keeps everything on screen = "all in view", Ian (a).)
  // Kept as a no-op so the call site below stays valid; search still centers via the page.
  function centerDefault() { window.__lgddCentered = true; }

  // ---------- rich pin popup (Feature: pin shows the member's real profile card) ----------
  // On popup open, swap the page's minimal popup for a clone of the member's actual list
  // card (banner + name + highlights + lights + links + connect), styled identically.
  function wireMapDriven() {
    if (typeof dirMap === 'undefined' || !dirMap || dirMap.__lgddMapDriven) return;
    dirMap.__lgddMapDriven = true;
    dirMap.on('moveend', onMapMove);
    dirMap.on('popupopen', function (e) {
      try {
        var src = e.popup && e.popup._source, slug = src && src.__lgddSlug;
        if (!slug) {
          // Drop-off pin → brand its popup with the member's banner + logo + location name.
          try {
            if (src && src.__lgddDropSlug) {
              e.popup.setContent(buildDropPopupHTML(memberData(src.__lgddDropSlug), src.__lgddDropName));
              if (e.popup._container) e.popup._container.classList.add('lgdd-droppop-wrap');
              e.popup.update();
            } else if (typeof dirChildLayer !== 'undefined' && dirChildLayer && dirChildLayer.hasLayer && dirChildLayer.hasLayer(src)
                       && typeof expandedSlug !== 'undefined' && expandedSlug) {
              // the page's own count-badge expansion path → brand it the same way
              var ll = src.getLatLng(), nm = 'Drop-off location', ds = dropoffsFor(expandedSlug);
              for (var i = 0; i < ds.length; i++) if (Math.abs(ds[i].lat - ll.lat) < 1e-5 && Math.abs(ds[i].lng - ll.lng) < 1e-5) { nm = ds[i].name || nm; break; }
              e.popup.setContent(buildDropPopupHTML(memberData(expandedSlug), nm));
              if (e.popup._container) e.popup._container.classList.add('lgdd-droppop-wrap');
              e.popup.update();
            }
          } catch (e2) {}
          return;                                           // gated pins → keep default popup
        }
        var main = document.querySelector('#dir-results .dir-card__main[data-slug="' + cssEsc(slug) + '"]');
        var live = main && main.closest('.dir-card');
        var clone;
        if (live) {
          clone = live.cloneNode(true);
        } else if (cardHTMLBySlug[slug]) {                  // not in current list → rebuild from cached HTML
          var holder = document.createElement('div'); holder.innerHTML = cardHTMLBySlug[slug];
          clone = holder.firstElementChild;
        }
        if (!clone) return;                                 // never rendered → keep default popup
        clone.classList.remove('is-active', 'is-hot');
        // Connect/Message inside the popup aren't reached by the page's #dir-results
        // delegation, so wire them here (reusing the page's handlers).
        clone.addEventListener('click', function (ev) {
          var c = ev.target.closest && ev.target.closest('.dir-connect');
          if (c) { ev.preventDefault(); ev.stopPropagation(); try { dirHandleConnect(c); } catch (_) {} return; }
          var m = ev.target.closest && ev.target.closest('.dir-msg');
          if (m) { ev.preventDefault(); ev.stopPropagation(); var u = m.dataset.msgUuid; if (u) document.dispatchEvent(new CustomEvent('lg:open-dm', { detail: { uuid: u } })); return; }
        });
        e.popup.setContent(clone);
        if (e.popup._container) e.popup._container.classList.add('lgdd-cardpop');
        e.popup.update();
      } catch (err) {}
    });
    centerDefault();
    refreshListFromMap();
  }

  // ---------- boot ----------
  function boot() {
    try {
      if (!buildTwoPane()) return false;
      buildSearchBar();
      wireCardHover();
      attachPinHover();
      wireMapDriven();

      // pins load async and rebuild on every filter change; re-attach pin->card
      // hover whenever the results list re-renders, plus a few early polls.
      var results = document.getElementById('dir-results');
      if (results && !results.__lgddObs) {
        results.__lgddObs = true;
        new MutationObserver(function () { wireCardHover(); attachPinHover(); snapshotCards(); enhanceDropCards(); }).observe(results, { childList: true });
      }
      [400, 1000, 2200].forEach(function (t) { setTimeout(function () { attachPinHover(); wireMapDriven(); }, t); });

      // nudge Leaflet to re-measure now that #dir-map fills the right pane
      try { if (typeof dirMap !== 'undefined' && dirMap && dirMap.invalidateSize) dirMap.invalidateSize(true); } catch (e) {}
      [120, 500].forEach(function (t) { setTimeout(function () {
        try { if (typeof dirMap !== 'undefined' && dirMap && dirMap.invalidateSize) dirMap.invalidateSize(true); } catch (e) {}
      }, t); });
      return true;
    } catch (e) { return false; }
  }

  // The page builds its DOM + map on DOMContentLoaded; retry until ready.
  (function ready() {
    var tries = 0;
    (function tick() {
      if (boot()) return;
      if (++tries < 50) setTimeout(tick, 120);
    })();
  })();
})();
