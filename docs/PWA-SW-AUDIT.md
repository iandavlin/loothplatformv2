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

## Decision to arm

**ARMED. Ian ruled it in a clean decision box, 2026-08-11**, after the bug was explained
to him plainly — a jumpy offline screen shown to people whose internet is fine, which hit
him twice on 8/9 — and he chose **"Active right away"**.

As relayed by keeper, and quotable: *Ian ruled the fix ships ACTIVE for members immediately
on live deploy; today's live version is the broken one, arriving asleep helps nobody,
instant undo stays available.*

`platform/config/pwa-sw.php` → `'resilient_fetch' => true`.

### The sequence, recorded on purpose

There were **two** decision-box clicks and only the second is a ruling. Written down
because the first one is still in the history and must never be mistaken for authority:

1. **2026-08-11, first click — RETRACTED.** It read as "ship ON", but Ian had misread the
   box as being about the keeper watchdog rather than this offline fix. Keeper caught it,
   the arming was reverted the same turn, and it is **not** a ruling.
2. **2026-08-11, re-asked cleanly — THIS IS THE RULING.** The defect was described in
   member terms first, then the choice put. That is the click quoted above.

If you are reading a commit or a click that says "ship ON" and cannot tell which of the two
it belongs to: the ruling is the one accompanied by the member-terms explanation, and the
retraction is documented in this branch's history rather than erased from it.

### Why the usual default-OFF rule is not the safe choice here

A member-facing flag defaults OFF to keep a *new* behaviour away from members until it has
been looked at. This case inverts that, which is exactly what was put to Ian:

- **The version live is running right now IS the broken one.** Re-measured 2026-08-12 over
  `ssh live-ro`: `const CACHE = 'looth-pwa-v3'` with no `fetchWithDeadline` anywhere in it.
- So **OFF does not protect a member from a new surface — it preserves the wall.** The new
  behaviour is "stop stranding the user".
- **Arming cannot expose the dev2-only behaviour.** The claim prompt and the dev-path bypass
  are gated on `self.location.hostname === 'dev2.loothgroup.com'` — an explicit allowlist,
  never a negation of `loothgroup.com`, because live's `/etc/looth/env` says `LG_ENV=dev2`
  and the environment name cannot be trusted. On live this flag turns on the deadline and
  the per-asset install, and nothing else.

### The armed path was verified before it was armed

Measured in a real browser against this branch (`/sw.js` + `/pwa.js` swapped in through
`endpoint-swap-proxy.py --no-route-strip`):

```
window.LG_SW      "f=resilient&t=8000&b=%2Ffooter-mockups%2F,..."
controller        /sw.js?f=resilient&t=8000&b=...      registered WITH the flag
state             activated
caches.keys()     ["looth-pwa-v4"]   <- v3 purged by activate(), so the bump works
shell cached      /offline.html AND /icons/icon-192.png
the page          title "Test — Looth Group", 3822 chars, shell: false
```

So the flip is not a leap: the behaviour behind it was known to work before the flag moved.

### The undo, stated precisely

`'resilient_fetch' => false` + a pull. `pwa-loader.php` then stops emitting
`window.LG_SW`, `pwa.js` registers plain `/sw.js`, that registration supersedes the
flagged one, and `sw.js` reads no query so `RESILIENT` is false and every branch takes its
original path. Gate 23 asserts that OFF path in both directions, so the undo is measured
rather than hoped.

Two honest caveats on the word "instant": it is a **deploy**, not a runtime toggle — it
needs `git pull` on the serving checkout — and each client picks it up on its next visit,
not immediately. The cache name stays `v4`; that is only a name, and nothing is lost by
leaving it.

### What flipping it costs, so the decision is informed

A different script URL is a different worker, so turning the flag on (or back off)
re-runs `install` once per client. The cache name is unchanged, so nobody loses cached
shell assets; expect one extra install per client per flip and nothing worse.

## 2026-08-11, the THIRD bite — and it was a different origin

Ian clicked `https://dev.loothgroup.com/hub/touring-tech/test-3/`, got a blank spin, then
the offline shell. The URL was verified 200 server-side. Both facts are true and they are
about **two different hosts**.

| measured | result |
|---|---|
| `dev.loothgroup.com` resolves to | `50.19.198.38` — **unreachable from this box**; `/hub/…` and `/sw.js` both time out |
| `dev2.loothgroup.com` resolves to | Cloudflare → us |
| our only vhost's `server_name` | `loothgroup.com www.loothgroup.com dev2.loothgroup.com` — the retired name is **not** there; it appears in that file only inside a comment |
| the same path on dev2 | **200**, 184146 bytes, `<title>Test — Looth Group</title>` |

`dev.loothgroup.com` was retired 2026-07-27. **Service workers are per-origin**, so a
worker registered on that origin while it was live still holds `/offline.html` in its own
cache; it intercepts the navigation, its fetch to a dead host rejects, and it serves its
own shell. That reproduces "blank spin, then the offline shell" precisely while dev2 serves
the page.

Two things follow, and both are worth stating rather than glossing:

1. **No change to dev2's `sw.js` can reach that worker.** Different origin, different
   registration. The fix in this branch does not and cannot address it.
2. **On that origin the shell was arguably correct** — the server really was unreachable.
   "Never show the shell when the server is reachable" is the right rule, and it was not
   violated there.

The remedy is Ian's and takes seconds: unregister the worker for `dev.loothgroup.com`
(DevTools → Application → Service Workers → Unregister), or simply use
`dev2.loothgroup.com`. It lives in his browser profile and no lane can reach it.

### Both dev2-side paths were measured, to rule them in or out

| client state | what the user actually sees |
|---|---|
| valid gate cookie | the REAL page — title `Test — Looth Group`, 3540 chars, no shell |
| worker installed, then cookie removed | `403 Forbidden nginx`, **19 chars** — not the shell |

A 403 is a **resolved** fetch, so the worker passes it straight through; the shell needs a
**rejection**. So neither dev2 path produces the symptom. Note the second row is itself an
ugly 19-character wall, and it is exactly what this lane's claim prompt replaces.

## Gate 24 — the browser half

`tools/gates/sw-no-offline-shell-gate.py`. Real browser, real registered worker, real page,
real origin, as a real user. Asserts the shell markers are ABSENT **and** the real content
is PRESENT — "shell absent" alone passes on a blank page, and a blank page was the other
half of what Ian saw.

Red-firsted with `--prove`, which registers a deliberately broken worker that always
answers navigations with a shell lookalike, scoped to a dev-gated fixture directory (a
worker cannot claim a scope above its own path, so it can never touch `/hub/`). Both
assertions went red. **The first attempt at that prove was thrown away**: it hijacked the
real worker's cache, and with the origin UP the shipped handler never consults the cache —
it would have left the gate green and proven nothing.

⚠️ The gate audits **whatever nginx serves**, i.e. the serving checkout (`main`), not a
lane's branch. Correct default for a regression tripwire; not evidence about an unmerged
fix.

## Suite result, 2026-08-12 — 24/24, ALL GREEN

`tools/gates/run-all.sh` from this worktree, after registering gate 24:

```
24 gates run | 0 FAIL | 0 NO VERDICT | 0 GATE-ERROR
############ ALL GATES GREEN ############   (exit 0)
Buck-surface guard: clean (nothing of Buck's is touched).
```

Gate 23 (node, the deadline) 24/24. Gate 24 (browser, the shell) 4/4 — controller
confirmed as `https://dev2.loothgroup.com/sw.js`, the real page on screen, no shell.

Notable because the four documented in-sequence flake shapes (gate 1 `limit_req`, gate 2
dead CDP socket, gate 14 its own nginx, gate 17 a `None` probe) **all stayed green on a
2-core box**, which is the opposite of what the downgrade predicted. One clean run is not
proof they are fixed — they are load-dependent — but it does mean a red suite today should
not be waved away as "probably the usual flakes" without checking.

### Live is still running the unfixed worker

Re-measured over `ssh live-ro` the same day:

```
/sw.js 200   /offline.html 200
const CACHE = 'looth-pwa-v3'     <- no fetchWithDeadline anywhere in it
/offline.html Cache-Control:     (none — same as dev2)
```

So live members are on the no-deadline worker right now, and live's `offline.html` is
equally exposed to the heuristic-caching staleness the v4 bump exists to clear. That is
the whole argument for the rollout shape below.
