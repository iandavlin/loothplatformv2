#!/usr/bin/env node
/*
 * sw-handler-harness — run webroot/sw.js's REAL event handlers under a stubbed
 * ServiceWorkerGlobalScope, so the navigation handler can be asked the one question
 * a browser makes awkward: does it settle when the network never answers?
 *
 * WHY A STUB AND NOT A BROWSER. Ian's desktop symptom was a tab spinning on a request
 * that never reached nginx (no access-log entry). That is a fetch which neither
 * resolves NOR rejects — and a `.catch`-guarded retry cannot see it, which is the
 * whole defect. A CDP network stall cannot express it reliably, because a service
 * worker is its own target and the stall lands on the page's. A stub can express it
 * exactly: `fetch = () => new Promise(() => {})`.
 *
 * It loads the real file (no copy of the logic here — a second copy would drift and
 * then agree with itself), so this is a test OF sw.js, not of a paraphrase.
 *
 *   node sw-handler-harness.js <case> [--sw path] [--flags str] [--budget ms]
 *
 * cases:
 *   hang            navigation whose fetch NEVER settles     -> must settle by budget
 *   reject          navigation whose fetch always rejects    -> must settle by budget
 *   slow-then-ok    fetch rejects once, then succeeds        -> must serve the PAGE
 *   ok              fetch succeeds immediately               -> must serve the PAGE
 *   dev-path        navigation to a dev-gated path           -> must NOT be handled
 *   gate-403        navigation the dev gate refuses (403)    -> reports what came back
 *   install-partial one shell asset 403s                     -> must still install
 *
 * Prints one JSON object: { case, settled, ms, served, handled, notes }.
 * Exit 0 always — the caller decides what the numbers mean.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const argv = process.argv.slice(2);
const kase = argv[0] || 'hang';
const arg = (n, d) => { const i = argv.indexOf('--' + n); return i < 0 ? d : argv[i + 1]; };
const SW = arg('sw', path.join(__dirname, '..', '..', '..', 'webroot', 'sw.js'));
const FLAGS = arg('flags', '');            // what pwa.js would put in the register URL
const BUDGET = parseInt(arg('budget', '9000'), 10);

/* ---- the stubbed worker global -------------------------------------------- */
function makeResponse(body, status, url) {
  return {
    __body: body, status: status === undefined ? 200 : status,
    ok: (status === undefined ? 200 : status) < 400,
    url: url || '', headers: { get: () => null },
    clone() { return makeResponse(body, status, url); },
    text() { return Promise.resolve(String(body)); },
  };
}

const cacheStore = new Map();
const notes = [];

function makeScope(fetchImpl) {
  const listeners = {};
  const caches = {
    open: (name) => Promise.resolve({
      addAll: (urls) => Promise.all(urls.map((u) =>
        fetchImpl({ url: absolute(u), mode: 'no-cors', method: 'GET' })
          .then((r) => {
            if (!r || !r.ok) { const e = new TypeError('Request failed'); throw e; }
            cacheStore.set(u, r);
          }))).then(() => undefined),
      put: (req, resp) => { cacheStore.set(typeof req === 'string' ? req : req.url, resp); return Promise.resolve(); },
    }),
    match: (req) => {
      const key = typeof req === 'string' ? req : req.url;
      if (cacheStore.has(key)) return Promise.resolve(cacheStore.get(key));
      // the real Cache API also matches by pathname for our string keys
      try {
        const p = new URL(key, 'https://dev2.loothgroup.com').pathname;
        if (cacheStore.has(p)) return Promise.resolve(cacheStore.get(p));
      } catch (e) { /* not a url */ }
      return Promise.resolve(undefined);
    },
    keys: () => Promise.resolve([...new Set(['looth-pwa-v3'])]),
    delete: () => Promise.resolve(true),
  };

  const scope = {
    // ⚠️ A vm context gets V8 INTRINSICS ONLY. URL, URLSearchParams, Response,
    // AbortController and even setTimeout are host globals and are ABSENT unless
    // injected — a real ServiceWorkerGlobalScope has all of them.
    //
    // This is not housekeeping, it is the harness's own red-first finding. Without
    // setTimeout, `new Promise((res) => setTimeout(res, 350))` throws inside its
    // executor, which REJECTS the promise, which lands in the very .catch that serves
    // offline.html. So the shipped file's `reject` case "passed" by falling down a
    // path a browser never takes, and the flag-ON case silently did nothing because
    // `new URL(...)` threw and the flag read as absent. A harness missing a global
    // does not fail loudly — it quietly tests something else.
    URL, URLSearchParams, Response, Request, Headers, AbortController,
    setTimeout, clearTimeout, setInterval, clearInterval,
    console, TextEncoder, TextDecoder,
    addEventListener: (t, fn) => { (listeners[t] = listeners[t] || []).push(fn); },
    skipWaiting: () => { notes.push('skipWaiting'); return Promise.resolve(); },
    clients: { claim: () => Promise.resolve(), matchAll: () => Promise.resolve([]) },
    registration: { showNotification: () => Promise.resolve() },
    location: new URL('https://dev2.loothgroup.com/sw.js' + (FLAGS ? '?' + FLAGS : '')),
    caches, fetch: fetchImpl,
    __listeners: listeners,
  };
  scope.self = scope;
  return scope;
}

function absolute(u) { return new URL(u, 'https://dev2.loothgroup.com').toString(); }

/* ---- per-case fetch behaviour -------------------------------------------- */
let calls = 0;
function fetchFor(kase) {
  return function (req) {
    calls++;
    const url = typeof req === 'string' ? absolute(req) : req.url;
    const p = new URL(url).pathname;
    // The shell fetches during install follow the gate matrix we measured.
    if (kase === 'install-partial') {
      if (p === '/offline.html') return Promise.resolve(makeResponse('forbidden', 403, url));
      return Promise.resolve(makeResponse('icon', 200, url));
    }
    if (p === '/offline.html' || p.startsWith('/icons/')) {
      return Promise.resolve(makeResponse(p === '/offline.html' ? 'OFFLINE PAGE' : 'icon', 200, url));
    }
    switch (kase) {
      case 'hang':     return new Promise(() => {});                 // never settles
      case 'reject':   return Promise.reject(new TypeError('Failed to fetch'));
      case 'slow-then-ok':
        return calls <= 1 ? Promise.reject(new TypeError('Failed to fetch'))
                          : Promise.resolve(makeResponse('REAL PAGE', 200, url));
      case 'gate-403': return Promise.resolve(makeResponse('403 Forbidden\nnginx', 403, url));
      default:         return Promise.resolve(makeResponse('REAL PAGE', 200, url));
    }
  };
}

/* ---- drive it ------------------------------------------------------------ */
const src = fs.readFileSync(SW, 'utf8');
const scope = makeScope(fetchFor(kase));
vm.createContext(scope);
try {
  vm.runInContext(src, scope, { filename: SW });
} catch (e) {
  console.log(JSON.stringify({ case: kase, error: 'sw.js threw on load: ' + e.message }));
  process.exit(0);
}

const out = { case: kase, flags: FLAGS, sw: path.relative(process.cwd(), SW) };

function fire(type, event) {
  const ls = scope.__listeners[type] || [];
  ls.forEach((fn) => { try { fn(event); } catch (e) { notes.push(type + ' threw: ' + e.message); } });
}

(async () => {
  // install first, so SHELL is cached exactly as it would be in the browser
  let installPromise = Promise.resolve();
  fire('install', { waitUntil: (p) => { installPromise = Promise.resolve(p); } });
  out.install = await installPromise.then(() => 'RESOLVED').catch((e) => 'REJECTED ' + e.message);
  out.shell_cached = [...cacheStore.keys()];

  if (kase === 'install-partial') {
    out.notes = notes;
    console.log(JSON.stringify(out));
    return;
  }

  const navUrl = kase === 'dev-path'
    ? 'https://dev2.loothgroup.com/footer-mockups/post-back-nav/'
    : 'https://dev2.loothgroup.com/hub/';
  const req = { url: navUrl, mode: 'navigate', method: 'GET', destination: 'document' };

  let responded = null;
  const t0 = Date.now();
  fire('fetch', { request: req, respondWith: (p) => { responded = Promise.resolve(p); } });

  out.handled = responded !== null;
  if (!responded) {
    // No respondWith => the browser performs its own fetch, i.e. the SW is OUT OF THE
    // WAY for this request. That is the desired shape for a dev-gated path.
    out.settled = true; out.ms = 0; out.served = '(not intercepted — browser handles it)';
    out.notes = notes;
    console.log(JSON.stringify(out));
    return;
  }

  const timeout = new Promise((res) => setTimeout(() => res('__BUDGET_EXCEEDED__'), BUDGET));
  const winner = await Promise.race([responded.catch((e) => '__REJECTED__ ' + e.message), timeout]);
  out.ms = Date.now() - t0;

  if (winner === '__BUDGET_EXCEEDED__') {
    out.settled = false;
    out.served = null;
    out.verdict = 'STRANDED — respondWith never settled within ' + BUDGET + 'ms';
  } else if (typeof winner === 'string' && winner.startsWith('__REJECTED__')) {
    out.settled = true;
    out.served = winner;
    out.verdict = 'respondWith REJECTED (browser falls back to a network error page)';
  } else {
    out.settled = true;
    // Two response shapes reach here: the stub's duck-typed object (from a faked
    // network hit) and a REAL Response the worker constructed itself (the claim
    // prompt, the inline last-resort page). Read both, or a real Response reports as
    // "(empty)" and a working fix looks broken.
    let text = null;
    if (winner && typeof winner.__body !== 'undefined') text = String(winner.__body);
    else if (winner && typeof winner.text === 'function') {
      try { text = await winner.text(); } catch (e) { text = null; }
    }
    out.served = text === null ? '(empty)' : text.replace(/\s+/g, ' ').trim().slice(0, 90);
    out.status = winner && winner.status;
    out.verdict = 'settled';
  }
  out.fetch_calls = calls;
  out.notes = notes;
  console.log(JSON.stringify(out));
})();
