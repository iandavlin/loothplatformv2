/* Looth PWA — push-notification opt-in client.
   Self-contained IIFE. Mobile-aware (matches /pwa.js gates). Renders a slim,
   on-brand opt-in prompt, requests Notification permission, subscribes via the
   service worker's PushManager, and POSTs the subscription to the backend.

   *** FEATURE-FLAGGED OFF BY DEFAULT ***
   PUSH_ENABLED below is false and the VAPID key is a placeholder. While off this
   file is a clean no-op: it never asks for notification permission and never
   renders a prompt. Do NOT ask users to allow notifications before the backend
   sender exists — a stale permission grant we can't fulfil is worse than none.

   TO TURN ON (once the backend + VAPID key are live — see _audit/HANDOFF-push.md):
     1. Paste the real VAPID PUBLIC key into VAPID_PUBLIC_KEY.
     2. Set PUSH_ENABLED = true.
     3. Confirm POST /push/subscribe is deployed and storing subscriptions.
   Loaded site-wide by adding a /push.js <script> injection to /pwa.js. */
(function () {
  'use strict';
  if (window.__loothPush) return;
  window.__loothPush = true;

  /* ----- feature flag + config ------------------------------------------- */
  var PUSH_ENABLED = true; // backend + VAPID live (2026-06-05)
  // VAPID PUBLIC application server key (P-256, URL-safe base64). Public by design;
  // the private half stays server-side at /etc/lg-vapid/.
  var VAPID_PUBLIC_KEY =
    'BMHEhU5ksnWhRjhUGtoj5hJmJgH3Ex6pshqi_QEd0umFTaLy9anVrMVbMkEqsvkGXcyo7Sza_mX7rVSXoYc2jVc';
  // Docroot handler (WP-MySQL wp_lg_push_subscriptions). Rides WP's PHP-FPM via
  // the vhost's `\.php$` block — no pretty-route rewrite needed.
  var SUBSCRIBE_ENDPOINT = '/push-subscribe.php';

  var DISMISS_KEY = 'looth_push_dismissed';   // user clicked "Not now"
  var ENABLED_KEY = 'looth_push_enabled';     // user already subscribed
  var PROMPT_ID = 'looth-push-prompt';
  var STYLE_ID = 'looth-push-style';

  /* ----- environment gates (mirror /pwa.js) ------------------------------ */
  // NOTE (perf, v2): these are ASSIGNED inside boot(), not at eval time —
  // matchMedia at top-level forces style recalc mid-load and was costing
  // ~1.7s of attributed bootup on a throttled mobile run (perf lane 6/11).
  var hasPush = false;
  var isStandalone = false;
  var isMobile = false;

  function dismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function setDismissed() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
  }
  function alreadyEnabled() {
    try { return localStorage.getItem(ENABLED_KEY) === '1'; } catch (e) { return false; }
  }
  function setEnabled() {
    try { localStorage.setItem(ENABLED_KEY, '1'); } catch (e) {}
  }

  /* ----- VAPID key helper ------------------------------------------------ */
  // Convert a URL-safe base64 VAPID public key into the Uint8Array the
  // PushManager.subscribe applicationServerKey expects.
  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  function hasRealKey() {
    return typeof VAPID_PUBLIC_KEY === 'string' &&
           VAPID_PUBLIC_KEY &&
           VAPID_PUBLIC_KEY.indexOf('REPLACE_') !== 0;
  }

  /* ----- subscribe flow -------------------------------------------------- */
  // Request permission, subscribe via PushManager, POST the subscription JSON.
  function subscribe() {
    if (!hasPush || !hasRealKey()) return;
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') {
        // Denied/default — don't nag again this session.
        setDismissed();
        removePrompt();
        return;
      }
      navigator.serviceWorker.ready.then(function (reg) {
        var key;
        try { key = urlBase64ToUint8Array(VAPID_PUBLIC_KEY); } catch (e) { return; }
        reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: key
        }).then(function (sub) {
          postSubscription(sub);
          setEnabled();
          removePrompt();
        }).catch(function () {
          // Subscribe failed (no backend / network) — fail quietly.
          removePrompt();
        });
      });
    }).catch(function () {});
  }

  function postSubscription(sub) {
    try {
      var json = (sub && sub.toJSON) ? sub.toJSON() : sub;
      var body = {
        endpoint: json && json.endpoint,
        keys: (json && json.keys) || {},
        ua: navigator.userAgent ? String(navigator.userAgent).slice(0, 255) : ''
      };
      fetch(SUBSCRIBE_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body)
      }).catch(function () {});
    } catch (e) {}
  }

  /* ----- silent resync (permission already granted) ----------------------- */
  // Keep the server subscription fresh on every load when the user has already
  // allowed notifications — no prompt, no UI. Handles subscription rotation and
  // first-time registration after an out-of-band grant.
  function resyncSilently() {
    if (!hasPush || !hasRealKey()) return;
    navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (sub) return sub;
        var key;
        try { key = urlBase64ToUint8Array(VAPID_PUBLIC_KEY); } catch (e) { return null; }
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
      });
    }).then(function (sub) {
      if (sub) { postSubscription(sub); setEnabled(); }
    }).catch(function () { /* stay silent */ });
  }

  /* ----- on-brand opt-in prompt ------------------------------------------ */
  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '#' + PROMPT_ID + '{position:fixed;left:12px;right:12px;bottom:12px;z-index:2147482900;' +
      'background:var(--lg-cream,#fbfbf8);color:var(--lg-ink,#323532);border:1px solid var(--lg-line,#e3ddd0);' +
      'border-radius:16px;box-shadow:0 6px 24px rgba(26,29,26,.18);padding:13px 12px 13px 14px;' +
      'display:flex;align-items:center;gap:11px;' +
      'font:15px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;animation:looth-push-up .28s ease}' +
      '@keyframes looth-push-up{from{transform:translateY(120%);opacity:0}to{transform:translateY(0);opacity:1}}' +
      // bell glyph in a soft sage tile
      '#' + PROMPT_ID + ' .lpn-ic{flex:0 0 auto;width:38px;height:38px;border-radius:11px;' +
      'background:var(--lg-sage-tint,#eef2e3);display:flex;align-items:center;justify-content:center}' +
      '#' + PROMPT_ID + ' .lpn-ic svg{width:21px;height:21px;display:block;fill:none;' +
      'stroke:var(--lg-sage-d,#6b7c52);stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}' +
      '#' + PROMPT_ID + ' .lpn-tx{flex:1 1 auto;min-width:0}' +
      '#' + PROMPT_ID + ' .lpn-tl{font-weight:600;color:var(--lg-charcoal,#1a1d1a)}' +
      '#' + PROMPT_ID + ' .lpn-sb{font-size:13px;color:var(--lg-mute,#6b6f6b);margin-top:1px}' +
      '#' + PROMPT_ID + ' .lpn-act{display:flex;align-items:center;gap:4px;flex:0 0 auto}' +
      '#' + PROMPT_ID + ' button{font:inherit;cursor:pointer;border-radius:10px;border:0}' +
      '#' + PROMPT_ID + ' .lpn-yes{background:var(--lg-sage,#87986a);color:#fff;font-weight:600;padding:9px 14px}' +
      '#' + PROMPT_ID + ' .lpn-yes:active{background:var(--lg-sage-d,#6b7c52)}' +
      '#' + PROMPT_ID + ' .lpn-no{background:transparent;color:var(--lg-mute,#6b6f6b);padding:9px 10px}' +
      // lift above the mobile bottom tab bar when it is present
      '@media (max-width: 640px){body.has-looth-tabbar #' + PROMPT_ID + '{' +
      'bottom:calc(68px + env(safe-area-inset-bottom,0px))}}';
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  function removePrompt() {
    var p = document.getElementById(PROMPT_ID);
    if (p && p.parentNode) p.parentNode.removeChild(p);
  }

  function showPrompt() {
    if (document.getElementById(PROMPT_ID)) return;
    injectStyles();
    var el = document.createElement('div');
    el.id = PROMPT_ID;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Get Looth notifications');
    // Bell stroke icon (24x24, currentColor). No emoji per brand rules.
    var bell = '<span class="lpn-ic"><svg viewBox="0 0 24 24" aria-hidden="true">' +
      '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6z"/>' +
      '<path d="M10.5 19a1.6 1.6 0 0 0 3 0"/></svg></span>';
    el.innerHTML = bell +
      '<div class="lpn-tx"><div class="lpn-tl">Stay in the loop</div>' +
      '<div class="lpn-sb">Get notified about new Looths &amp; events</div></div>' +
      '<div class="lpn-act"><button class="lpn-yes" type="button">Enable</button>' +
      '<button class="lpn-no" type="button">Not now</button></div>';
    (document.body || document.documentElement).appendChild(el);

    var yes = el.querySelector('.lpn-yes');
    if (yes) yes.addEventListener('click', function () { subscribe(); });

    var no = el.querySelector('.lpn-no');
    if (no) no.addEventListener('click', function () { setDismissed(); removePrompt(); });
  }

  /* ----- boot ------------------------------------------------------------ */
  // v2 perf: eval is define-only. ALL environment probing (matchMedia,
  // localStorage, Notification.permission) and any work happens at idle,
  // never during page bootup.
  function boot() {
    hasPush = ('serviceWorker' in navigator) &&
              ('PushManager' in window) &&
              ('Notification' in window);

    // Hard no-op while the feature is off or unsupported / not applicable.
    if (!PUSH_ENABLED || !hasRealKey() || !hasPush) return;

    // Already allowed: silently (re)sync the subscription on every load — no
    // prompt, no UI, anywhere the user has granted. Keeps the server table fresh.
    if (Notification.permission === 'granted') { resyncSilently(); return; }
    // Hard no: never nag a denied permission.
    if (Notification.permission === 'denied') return;

    isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                   window.navigator.standalone === true;
    // Mobile-only gate: narrow viewport AND a coarse (touch) pointer.
    isMobile = window.matchMedia('(max-width: 640px)').matches &&
               window.matchMedia('(pointer: coarse)').matches;

    // permission === 'default': invite ONLY inside the installed app on a phone,
    // so we never double-prompt against the mobile-web install banner.
    if (!isStandalone || !isMobile) return;
    if (dismissed() || alreadyEnabled()) return;

    if (document.body) showPrompt();
    else document.addEventListener('DOMContentLoaded', showPrompt);
  }

  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(boot, { timeout: 4000 });
  } else {
    setTimeout(boot, 1200);
  }
})();
