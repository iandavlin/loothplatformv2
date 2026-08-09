# The PWA service worker — why it hangs, and why a dev mock installs it

Backlog 3.10. Ian hit this twice on 2026-08-09 viewing dev mocks: the phone showed
`offline.html` plus raw gate 403s, and desktop Chrome span forever on a request that
never reached nginx. Lane `pwa-sw-3.10`, dev2.

This document is the audit. It is deliberately separate from the fix so the
measurements can be checked without reading a diff.

## The one-line version

`webroot/sw.js`'s navigation handler is `fetch(req)` with **no timeout**, and nginx
deliberately leaves the *install prompt* (`/manifest.json`, `/icons/`) **ungated**
while gating **everything the installed app then needs** — including `/sw.js` and
`/offline.html`. So dev2 will happily invite you to install an app that cannot fetch
its own service worker, and the service worker cannot bound its own failure.

## Measured, not inferred

### 1. The gate matrix — the install prompt is the only ungated part of the app

`curl --resolve dev2.loothgroup.com:443:172.31.78.94`, with and without
`loothdev_auth`, 2026-08-09:

| path | with gate cookie | **without** | why it matters |
|---|---|---|---|
| `/manifest.json` | 200 | **200** | ungated ON PURPOSE — the conf says so: "Chrome fetches the manifest + icons WITHOUT credentials, so they must bypass the cookie gate or the install prompt never fires" |
| `/icons/icon-192.png` | 200 | **200** | same, and it is `SHELL[1]` |
| `/sw.js` | 200 | **403** | the worker script itself |
| `/offline.html` | 200 | **403** | `SHELL[0]` — the fallback |
| `/pwa.js` | 200 | 403 | the bootstrap that registers the SW |
| `/footer-mockups/post-back-nav/` | 200 | 403 | Ian's mocks |
| `/hub/` | 200 | 403 | every navigation |

**Consequence, and it is not subtle:** `install` does
`caches.open(CACHE).then(c => c.addAll(SHELL))`. `addAll` is **all-or-nothing** — one
403 rejects the whole thing, `event.waitUntil` rejects, and **the service worker never
installs**. `skipWaiting()` sits inside that same `.then()` chain, so it never runs
either. A client without the gate cookie therefore gets *no* SW at all and sees nginx's
bare 403s; a client that installed the SW *earlier* (while the cookie was present)
keeps an SW that now 403s every navigation.

That is both halves of what Ian saw, from one asymmetry.

### 2. A dev mock installs the site-wide service worker

nginx's server-level `sub_filter` rewrites `</head>` in **every** `text/html` response
to inject `<script src="/pwa.js" defer>`. That includes the static HTML under
`/footer-mockups/`. `webroot/pwa.js` then does:

```js
navigator.serviceWorker.register('/sw.js', { scope: '/' })
```

— scope `/`, from a mock page. Straight out of the access log, Ian's own traffic
(Cloudflare edge IPs, so this is his browser and not a lane harness):

```
15:00:54  GET /footer-mockups/hub-seo-landing/   200 4616   ref: /vscode/
15:00:54  GET /pwa.js                            200 7406   ref: /footer-mockups/hub-seo-landing/
15:00:55  GET /profile-api/v0/me/social-counts/  200   75   ref: /footer-mockups/hub-seo-landing/
15:00:55  GET /icons/icon-192.png                200 40443  ref: /footer-mockups/hub-seo-landing/
```

`/pwa.js` fetched **with the mock as referer**, then `icon-192.png` — that last one is
the SW's `install` caching `SHELL`. Viewing a mockup registers a service worker that
then mediates the whole origin. `me/social-counts` firing from a static mock is the
same injection carrying the entire app layer in (`bottom-nav.js`'s badge refresh),
which is the already-documented "docroot injects the boot script into MOCKS" trap
wearing a service worker this time.

`offline.html` was fetched **236 times on 8/9** — 229 from loopback (gate/harness
browsers, each fresh context installing the SW) and **7 from Cloudflare**, i.e. seven
real SW installs from Ian's side in one day.

### 3. The hang: `fetch()` with no timeout cannot fail

```js
if (req.mode === 'navigate') {
  event.respondWith(
    fetch(req).catch(() =>
      new Promise((res) => setTimeout(res, 350))
        .then(() => fetch(req))
        .catch(() => caches.match(req).then((r) => r || caches.match('/offline.html')))
    )
  );
```

The retry is guarded by `.catch`, so it only helps when the fetch **rejects**. A fetch
that neither resolves nor rejects leaves the `respondWith` promise pending **forever**,
and the tab spins with nothing in the access log — which is exactly the desktop
symptom, and exactly why the log has no entry to find. There is no `AbortController`,
no `Promise.race`, no deadline anywhere in the file.

Note the handler is already *network-first* for navigations; that part of the diagnosis
was right in the code comment and is not what is broken. **What is missing is a
deadline.**

### 4. `offline.html` recovers by probing the wrong thing

`offline.html` is *not* the dead end it looks like — it already has a retry button, an
`online` listener, and a 5-second poll. But the poll is:

```js
fetch('/manifest.json', { cache: 'no-store' }).then(...) => location.reload()
```

`/manifest.json` is **the one path deliberately exempt from the gate**. So on dev2
without the cookie the probe always succeeds, `location.reload()` fires, nginx answers
403, and it goes round again every 5 seconds. The success criterion ("a tiny asset is
reachable") is not the question being asked ("is the page I wanted reachable") — the
same verify-the-thing-not-the-thing-next-to-it shape that has cost this codebase time
before, baked into a recovery path.

On live this is harmless (nothing is gated). On dev2 it is a reload loop.

## Live exposure — this is not a dev-tools annoyance

Live serves the **byte-identical** file. Measured over `ssh live-ro`
(`curl -sk -H 'Host: loothgroup.com' https://127.0.0.1…` — note `-k`, the loopback
cert is self-signed and omitting it returns a misleading `000`):

```
/sw.js 200   /offline.html 200   /manifest.json 200   /icons/icon-192.png 200   /hub/ 200
const CACHE = 'looth-pwa-v3';                     <- same version
const SHELL = ['/offline.html', '/icons/icon-192.png'];
fetch(req).catch(... setTimeout(res, 350) ...)    <- same no-timeout handler
```

So the split is:

- **§3 the missing deadline — LIVE MEMBERS.** Same code, no gate needed to trigger it.
  Any hung navigation strands a member with no way out but killing the app.
- **§4 the probe — LIVE-SAFE, dev2 reload loop.** Ungated live, so the probe answers
  the right question there by accident.
- **§1 the gate asymmetry and §2 the mock-installs-the-SW path — dev2.** But §2 also
  means every lane's mock viewing pollutes Ian's app cache, which is how he met §3.
- **`addAll` being all-or-nothing is LIVE-RELEVANT too**: it is only benign today
  because both shell URLs happen to answer 200 on live. One typo'd or moved shell path
  and the service worker silently stops installing for everybody.

## What this audit does NOT claim

I have not proven *what* made the desktop fetch hang — a stalled request that never
reaches the server leaves no server-side evidence by definition, and Ian's browser is
not instrumented. What is proven is that **the handler has no deadline**, so whatever
the upstream cause, the user is stranded rather than bounded. The fix is written to
make the strand impossible regardless of cause, not to explain the cause.

## Suite result, 2026-08-09 — 23/23 ran, and neither red is this branch

`tools/gates/run-all.sh` from this worktree: **23/23 gates ran, 0 NO VERDICT**, exit 1.

| gate | verdict | whose |
|---|---|---|
| **23/23 sw-fetch-bounded** | **GREEN 24/24**, in-sequence and standalone | this branch |
| 1 visibility matrix | 4 fails in-sequence; **GREEN standalone: `MATRIX GREEN`, pass=67 fail=0** | neither — flake |
| 17 subscription-preserved | 1 fail in-sequence (`nofix: probe did not restore the box to its entry state`); **GREEN standalone: 10/10** | neither — flake |
| the other 20 | green | — |

Both reds are the load-dependent in-sequence flake family `run-all.sh` documents, and both
were re-run standalone before being called flakes rather than after being assumed to be.

Worth noting for whoever owns gate 1: **the failing assertions were DIFFERENT from the
last observation.** On 2026-08-07 it failed the S1 page/api set with `code=404`; this run
it failed `S0 default layout: about NOT auto-placed`, `S0 … gallery`, and two S2 probes.
Same family, different symptoms — so the flake is not a fixed set of assertions and
"gate 1 fails on S1" is not a reliable fingerprint for it. The reliable tell is that it
is green standalone.

Nothing here is attributable to this diff, which touches `sw.js`, `pwa.js`,
`pwa-loader.php`, `offline.html`, this lane's gate/harness and docs — nothing in the
visibility model or forum subscriptions.

## Arming the flag — no decision recorded yet

⚠️ **This heading is deliberately NOT the one the gate looks for.** Gate 23 searches for a
heading reading exactly `Decision to arm`; had this placeholder used that wording, the
tripwire would have been satisfied by the very text saying no decision exists. (That
false-green was caught for real on the 4.1 lane, which is why it is avoided here.)

To arm `resilient_fetch`, add a section headed **`## Decision to arm`** naming who decided
and when, then flip the value. Arming it without that section turns gate 23 red, and the
correct response is to record the decision — **not** to delete the check.

### What flipping it costs, so the decision is informed

A different script URL is a different worker, so turning the flag on (or back off)
re-runs `install` once per client. The cache name is unchanged, so nobody loses cached
shell assets; expect one extra install per client per flip and nothing worse.
