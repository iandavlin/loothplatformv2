/* Looth Hub — infinite scroll (client-side, injected site-wide via /pwa.js,
   path-gated to /hub). Makes the activity feed feel endless: as the bottom of
   the feed nears the viewport, the next page is fetched and its cards are
   appended in place — no full-page reload, no "Load older activity" click.

   HOW: reuses the server's EXISTING offset pagination. The page already renders
   a `.feed-more__btn` anchor (href `/hub/?sort=...&offset=N`) as a sibling after
   the `.feed` grid. We watch a 1px sentinel placed after `.feed-more`; when it
   enters an 800px pre-load margin we fetch that href, parse the returned HTML,
   move its `.feed > .feed-card` nodes into the live `.feed`, then swap the
   `.feed-more` contents for the fetched page's next link (advancing the offset).
   When a fetched page has no `.feed-more`, we're at the end: tear down and stop.

   END / OFFLINE (Buck 2026-06-08): the mobile app shows the bottom nav, not the
   shared site FOOTER — so we hide `.lg-chrome-foot` on the Hub at ≤640 (it used
   to peek out only when the feed stopped loading). In its place we show a clean
   end-of-feed line ("You're all caught up") when there are no more pages, and an
   OFFLINE line with a "Try again" button when a fetch fails (instead of silently
   retry-looping). Self-contained, no deps, no emoji. No-op off /hub. */
(function () {
  'use strict';
  if (window.__loothHubInfinite) return;
  window.__loothHubInfinite = true;

  function onHubPath() { return /^\/hub(\/|$)/.test(location.pathname || '/'); }
  if (!onHubPath()) return;

  var SENTINEL_ID = 'looth-hub-sentinel';
  var STYLE_ID = 'looth-hub-infinite-style';
  var END_ID = 'looth-hub-end';
  var loading = false, done = false, errored = false, io = null;

  // Pre-load buffer: start fetching the next page when the bottom of the feed
  // comes within this many px of the viewport, so new cards are already in
  // place before the user scrolls to them (seamless). Desktop gets a much
  // longer runway (Buck 2026-06-11: "~5 cards below the screen") — masonry
  // columns burn vertical px 3-4x faster than the single mobile column, and
  // the wider margin also gives the image/teaser prefetchers (hub-nojump,
  // hub-polish previews) time to settle new cards while still off-screen.
  var PRELOAD = window.matchMedia('(min-width:641px)').matches ? 3200 : 1500;

  function liveFeed() { return document.querySelector('.feed'); }
  function liveMore() { return document.querySelector('.feed-more'); }
  function nextUrl() {
    var btn = document.querySelector('.feed-more__btn');
    return btn ? btn.getAttribute('href') : null;
  }

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '.feed-more[data-lg-loading="1"] .feed-more__btn{opacity:.55;pointer-events:none}' +
      '#' + SENTINEL_ID + '{height:1px;width:100%}' +
      // The mobile app has the bottom nav — the desktop site footer is redundant
      // and only ever peeks out at the end of the feed. Hide it on the Hub ≤640.
      '@media (max-width:640px){.lg-chrome-foot{display:none!important}}' +
      // end-of-feed / offline indicator (replaces the footer at the bottom)
      '#' + END_ID + '{padding:24px 16px calc(34px + env(safe-area-inset-bottom,0px));text-align:center;' +
      'color:var(--lg-mute,#6b6f6b);font:500 14px/1.5 var(--lg-font-sans,system-ui,-apple-system,"Segoe UI",sans-serif)}' +
      '#' + END_ID + ' .lg-end-retry{margin-top:9px;display:inline-block;border:1px solid var(--lg-line,#e3ddd0);' +
      'border-radius:999px;padding:8px 18px;background:var(--lg-cream,#fbfbf8);color:var(--lg-ink,#323532);' +
      'font:600 13px/1 var(--lg-font-sans,system-ui,sans-serif);cursor:pointer}' +
      'html[data-lguser-theme="dark"] #' + END_ID + '{color:#9aa097}' +
      'html[data-lguser-theme="dark"] #' + END_ID + ' .lg-end-retry{background:#222629;border-color:#333833;color:#e5e7e1}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function clearEnd() {
    var h = document.getElementById(END_ID);
    if (h && h.parentNode) h.parentNode.removeChild(h);
  }
  function showEnd(kind) {
    var feed = liveFeed(); if (!feed) return;
    var host = document.getElementById(END_ID);
    if (!host) {
      host = document.createElement('div'); host.id = END_ID;
      (feed.parentNode || document.body).insertBefore(host, feed.nextSibling);
    }
    if (kind === 'offline') {
      host.innerHTML = (navigator.onLine === false
        ? 'You appear to be offline — couldn’t load more.'
        : 'Couldn’t load more right now.') +
        '<br><button type="button" class="lg-end-retry">Try again</button>';
      var btn = host.querySelector('.lg-end-retry');
      if (btn) btn.addEventListener('click', function () { retry(); });
    } else {
      host.innerHTML = 'You’re all caught up.';   // genuine end of the feed
    }
  }
  function retry() {
    clearEnd();
    errored = false; done = false; loading = false;
    if (!document.getElementById(SENTINEL_ID)) ensureSentinel();
    if (!io) start();
    loadMore();
  }

  function ensureSentinel() {
    var s = document.getElementById(SENTINEL_ID);
    if (s) return s;
    var more = liveMore();
    if (!more || !more.parentNode) return null;
    s = document.createElement('div');
    s.id = SENTINEL_ID;
    more.parentNode.insertBefore(s, more.nextSibling);
    return s;
  }

  function setBusy(b) {
    var more = liveMore();
    if (more) more.setAttribute('data-lg-loading', b ? '1' : '');
  }

  function teardown() {
    if (io) { io.disconnect(); io = null; }
    var s = document.getElementById(SENTINEL_ID);
    if (s && s.parentNode) s.parentNode.removeChild(s);
  }

  function maybeContinue() {
    // If the sentinel is still within the pre-load margin after a load (short
    // page / tall viewport), keep going — IntersectionObserver only fires on
    // CHANGES, so a still-visible sentinel would otherwise stall.
    var s = document.getElementById(SENTINEL_ID);
    if (!s) return;
    var r = s.getBoundingClientRect();
    if (r.top < (window.innerHeight || 0) + PRELOAD) loadMore();
  }

  function loadMore() {
    if (loading || done || errored) return;
    var url = nextUrl();
    if (!url) { done = true; teardown(); showEnd('end'); return; }
    loading = true;
    setBusy(true);
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
      .then(function (html) {
        clearEnd();   // a successful load clears any prior end/offline message
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var srcFeed = doc.querySelector('.feed');
        var feed = liveFeed();
        if (!srcFeed || !feed) { done = true; return; }
        var cards = srcFeed.querySelectorAll('.feed-card');
        if (!cards.length) { done = true; return; }
        var frag = document.createDocumentFragment();
        for (var i = 0; i < cards.length; i++) frag.appendChild(document.importNode(cards[i], true));
        feed.appendChild(frag);
        var more = liveMore();
        var srcMore = doc.querySelector('.feed-more');
        if (more) {
          if (srcMore) {
            more.innerHTML = srcMore.innerHTML; // fresh button → advanced offset
          } else if (more.parentNode) {
            more.parentNode.removeChild(more); // server says: no more pages
            done = true;
          }
        } else {
          done = true;
        }
      })
      .catch(function () {
        // Network/offline (or a non-OK status) — STOP auto-retrying and surface a
        // clear message with a manual retry, instead of silently looping.
        errored = true;
        showEnd('offline');
      })
      .then(function () {
        loading = false;
        setBusy(false);
        if (errored) { teardown(); return; }       // manual retry only from here
        if (done) { teardown(); showEnd('end'); return; }
        ensureSentinel();
        maybeContinue();
      });
  }

  function start() {
    if (!liveFeed() || !liveMore()) return; // listing pages with a pager only
    injectStyles();
    if (!ensureSentinel()) return;
    io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) { loadMore(); break; }
      }
    }, { rootMargin: '0px 0px ' + PRELOAD + 'px 0px' });
    // Arm AFTER the first paint settles (load + idle): an 18-card first page
    // is shorter than the preload runway, so observing immediately fetched
    // page 2 DURING initial render and competed with LCP (craft gate /
    // lighthouse, Ian 6/12). Same seamless scroll — the fetch just yields to
    // first paint. Manual button + online-retry paths below are unaffected.
    var armIO = function () { var el = document.getElementById(SENTINEL_ID); if (el && io) io.observe(el); };
    var armIdle = function () { (window.requestIdleCallback || function (f) { setTimeout(f, 800); })(armIO); };
    if (document.readyState === 'complete') armIdle();
    else window.addEventListener('load', armIdle, { once: true });

    // Delegated: a manual button click also appends via AJAX (survives the
    // innerHTML swaps) instead of doing a full-page navigation.
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest && e.target.closest('.feed-more__btn');
      if (!btn) return;
      e.preventDefault();
      loadMore();
    });
    // Coming back online after an offline stall → auto-retry the next page.
    window.addEventListener('online', function () { if (errored) retry(); });
  }

  // Inject the footer-hide style ASAP (before the feed/pager exist) so the
  // desktop footer never flashes at the bottom of the Hub on mobile.
  injectStyles();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
