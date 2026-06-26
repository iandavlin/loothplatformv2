/* Looth PWA service worker — intentionally minimal.
   Network-first for navigations so live dev HTML is never masked;
   cache-first only for our own /icons/ assets; offline.html fallback.
   Plus Web Push: a `push` handler renders the notification and a
   `notificationclick` handler focuses/opens the target URL. */
const CACHE = 'looth-pwa-v3';
const SHELL = ['/offline.html', '/icons/icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

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
