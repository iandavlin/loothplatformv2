/* Looth PWA service worker — intentionally minimal.
   Network-first for navigations so live dev HTML is never masked;
   cache-first only for our own /icons/ assets; offline.html fallback.
   Plus Web Push: a `push` handler renders the notification and a
   `notificationclick` handler focuses/opens the target URL. */
const CACHE = 'looth-pwa-v3';
const SHELL = ['/offline.html', '/icons/icon-192.png'];

/* ── The 3.10 flag, and how it gets in here ────────────────────────────────────
   This file is a STATIC docroot asset — it cannot read PHP. /pwa.js is PHP-served
   (pwa-loader.php) and is what registers this worker, so the flag rides the
   REGISTRATION URL: pwa.js registers '/sw.js?f=resilient' when
   platform/config/pwa-sw.php has resilient_fetch on, and plain '/sw.js' when it does
   not. With no query every branch below takes the ORIGINAL path verbatim, so OFF is
   byte-identical behaviour and not merely equivalent behaviour.
   Full reasoning + measurements: docs/PWA-SW-AUDIT.md, platform/config/pwa-sw.php. */
const SWQ = (function () {
  try { return new URL(self.location.href).searchParams; }
  catch (e) { return { get: function () { return null; } }; }
})();
const RESILIENT = String(SWQ.get('f') || '').indexOf('resilient') !== -1;

/* platform/config/pwa-sw.php is AUTHORITATIVE for both of these — pwa-loader.php puts
   them in the registration URL, so editing the config is enough and there is no second
   copy to drift. The literals below are a fallback for a client that somehow holds a
   registration without the query, and are unreachable while the flag is off. */
const NAV_TIMEOUT_MS = parseInt(SWQ.get('t'), 10) > 0 ? parseInt(SWQ.get('t'), 10) : 8000;
const BYPASS_PREFIXES = (SWQ.get('b') || '/footer-mockups/,/claim,/gatetest,/vscode/')
  .split(',').map(function (s) { return s.trim(); }).filter(Boolean);

/* dev2 ONLY, as an explicit allowlist. NEVER a negation of 'loothgroup.com':
   live's /etc/looth/env says LG_ENV=dev2, so the environment name cannot be trusted,
   and a "claim this device" page shown to a paying member would be a real defect. */
const IS_DEV2 = self.location.hostname === 'dev2.loothgroup.com';

function isBypassed(url) {
  if (!RESILIENT || !IS_DEV2) return false;
  if (url.origin !== self.location.origin) return false;
  for (let i = 0; i < BYPASS_PREFIXES.length; i++) {
    if (url.pathname.indexOf(BYPASS_PREFIXES[i]) === 0) return true;
  }
  return false;
}

self.addEventListener('install', (event) => {
  if (!RESILIENT) {
    event.waitUntil(
      caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
    );
    return;
  }
  // addAll is ALL-OR-NOTHING: one 403 rejects the whole thing, waitUntil rejects, and
  // the worker never installs — taking skipWaiting() (in the same chain) with it.
  // Measured on dev2, where /offline.html is gated and /icons/ is not: a client
  // without the dev cookie got NO service worker at all. Cache each asset on its own
  // and let install succeed with whatever it could get; a missing shell asset should
  // cost us that asset, not the entire worker.
  event.waitUntil(
    caches.open(CACHE).then((c) => Promise.all(SHELL.map((u) =>
      fetch(u, { cache: 'reload' })
        .then((r) => (r && r.ok ? c.put(u, r) : null))
        .catch(() => null)
    ))).then(() => self.skipWaiting(), () => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

/* A bounded network attempt. Resolves with the Response, or rejects, or — the case
   the shipped file cannot survive — gives up at `ms` and rejects instead of hanging.
   AbortController so the abandoned request is actually cancelled rather than left in
   flight competing with the retry. */
function fetchWithDeadline(req, ms) {
  return new Promise((resolve, reject) => {
    let done = false;
    let ctl = null;
    try { ctl = new AbortController(); } catch (e) { ctl = null; }
    const timer = setTimeout(() => {
      if (done) return;
      done = true;
      if (ctl) { try { ctl.abort(); } catch (e) {} }
      reject(new Error('sw-deadline'));
    }, ms);
    // A navigation Request carries mode:'navigate' and redirect:'manual'; re-issuing
    // it as `new Request(req, {signal})` is not permitted, so pass the Request
    // straight through and give fetch the signal separately.
    let p;
    try { p = ctl ? fetch(req, { signal: ctl.signal }) : fetch(req); }
    catch (e) { p = fetch(req); }
    p.then((r) => {
      if (done) return;
      done = true; clearTimeout(timer); resolve(r);
    }, (e) => {
      if (done) return;
      done = true; clearTimeout(timer); reject(e);
    });
  });
}

/* The dev gate refused this navigation. On dev2 that almost always means the client
   has no `loothdev_auth` cookie — and the installed app has its OWN cookie jar, so a
   device claimed in the browser is NOT claimed inside the app. /claim is exempt from
   the gate (nginx: `~^/claim 1`), so there is a door; the shipped worker just handed
   the raw nginx 403 over instead of pointing at it. Fail OPEN to the door. */
function claimPrompt(url) {
  const next = (url && url.pathname ? url.pathname + (url.search || '') : '/');
  const body =
    '<!doctype html><html lang="en"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Looth dev — this device is not claimed</title><style>' +
    'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#fbfbf8;' +
    'color:#323532;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;' +
    'text-align:center;padding:24px}img{width:72px;height:72px;border-radius:16px;' +
    'margin-bottom:16px}h1{font:600 20px/1.2 Georgia,serif;color:#1a1d1a;margin:0 0 6px}' +
    'p{color:#6b6f6b;margin:0 0 4px}code{font:13px/1.5 ui-monospace,SFMono-Regular,' +
    'Menlo,monospace;background:#eceee7;border-radius:6px;padding:2px 6px}' +
    '.lo-btn{margin-top:18px;display:inline-block;border:0;border-radius:999px;' +
    'padding:12px 22px;background:#87986a;color:#fff;font:600 15px/1 system-ui,' +
    '-apple-system,Segoe UI,Roboto,sans-serif;text-decoration:none}</style></head><body><div>' +
    '<img src="/icons/icon-192.png" alt="">' +
    '<h1>This device isn’t claimed</h1>' +
    '<p>The dev site answered <strong>403</strong> for this page.</p>' +
    '<p>An installed app keeps its own cookies, so claiming Looth in the browser ' +
    'does not claim it in here.</p>' +
    '<a class="lo-btn" href="/claim">Open the claim page</a>' +
    '<p style="margin-top:14px">Then come back to <code>' + escapeHtml(next) + '</code>.</p>' +
    '</div></body></html>';
  return new Response(body, {
    status: 200,
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }
  });
}

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  // Dev-gated surfaces: stay entirely out of the way. No respondWith at all, so the
  // browser performs its own fetch and nothing about a dev preview can reach the app
  // cache or be mediated by this worker. Ian's mock viewing is what registered this
  // worker in the first place (nginx injects /pwa.js into every text/html, mocks
  // included) — see docs/PWA-SW-AUDIT.md §2.
  let reqUrl = null;
  try { reqUrl = new URL(req.url); } catch (e) { reqUrl = null; }
  if (reqUrl && isBypassed(reqUrl)) return;

  if (RESILIENT && req.mode === 'navigate') {
    // Network-first WITH A DEADLINE. The shipped file is already network-first; what
    // it lacks is any way to stop waiting, so a fetch that neither resolves nor
    // rejects strands the tab forever with nothing in the access log. The one retry
    // is preserved (a transient radio/DNS blip still reaches the real page) and now
    // happens inside the same budget.
    const half = Math.max(1200, Math.floor(NAV_TIMEOUT_MS / 2));
    event.respondWith(
      fetchWithDeadline(req, half)
        .catch(() => new Promise((res) => setTimeout(res, 350))
          .then(() => fetchWithDeadline(req, NAV_TIMEOUT_MS - half)))
        .then((resp) => {
          if (resp && resp.status === 403 && IS_DEV2) return claimPrompt(reqUrl);
          return resp;
        })
        .catch(() => caches.match(req)
          .then((r) => r || caches.match('/offline.html'))
          .then((r) => r || new Response(
            '<!doctype html><meta charset=utf-8><title>Looth — offline</title>' +
            '<p style="font:16px system-ui;padding:24px">Looth needs a connection to ' +
            'load this page. <button onclick="location.reload()">Retry</button>',
            { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } })))
    );
    return;
  }

  if (req.mode === 'navigate') {
    // Network-first, but absorb a transient blip: one mobile radio gap / DNS
    // hiccup used to dead-end the user on offline.html (no retry) even when the
    // network was fine a moment later. Retry once after a short pause before
    // falling back, so the false "You're offline" page stops firing on a single
    // dropped request. (hub-reconnect lane 2026-06-25.)
    event.respondWith(
      fetch(req).catch(() =>
        new Promise((res) => setTimeout(res, 350))
          .then(() => fetch(req))
          .catch(() => caches.match(req).then((r) => r || caches.match('/offline.html')))
      )
    );
    return;
  }

  const url = new URL(req.url);
  if (url.origin === location.origin && url.pathname.startsWith('/icons/')) {
    event.respondWith(
      caches.match(req).then((r) => r || fetch(req).then((resp) => {
        const copy = resp.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
        return resp;
      }))
    );
  }
});

/* ---- Web Push ----------------------------------------------------------
   Payload contract (sender side, ubuntu's web-push task):
     JSON { title, body, url?, tag?, icon? }
   Defaults are brand-safe so a bare/empty payload still renders sanely. */
self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; }
  catch (e) { data = { body: event.data && event.data.text ? event.data.text() : '' }; }

  const title = (data.title && String(data.title)) || 'Looth';
  const url = (data.url && String(data.url)) || '/hub/';
  const options = {
    body: data.body ? String(data.body) : '',
    icon: (data.icon && String(data.icon)) || '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    tag: data.tag ? String(data.tag) : undefined,
    renotify: !!data.tag,
    data: { url: url }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/hub/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((cl) => {
      // Focus an existing tab/app window if one is already open; else open new.
      for (const c of cl) {
        if ('focus' in c) {
          c.navigate && c.navigate(target);
          return c.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(target) : undefined;
    })
  );
});
