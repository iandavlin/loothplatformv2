# SESSION HANDOFF — lane `admin-no-offline-shell` (backlog 28)

**Written 2026-08-15. Assume you are a fresh lane with zero context.**

| | |
|---|---|
| Branch | `admin-no-offline-shell`, **pushed**, tip **`464c9e0`** |
| Base | rebased onto `origin/main` `576a6cc` |
| State | **MERGE-READY.** Full suite: **ALL GATES GREEN**, 49 gates, 0 no-verdict, 0 FAIL |
| Gate | **43** (keeper), registered in `run-all.sh`, CRAFT-STANDARD row in |
| Flag | **none — shipped unflagged, deliberately.** See §4 |
| Deploy coupling | **NONE.** No conf edit, no nginx reload. See §3 |

---

## 1. The defect

Ian, 2026-08-15: a slow admin click showed him the game-like **"You're offline"**
page on a **dashboard** URL. He was not offline, and it is not our surface.
wp-admin should fail the way wp-admin fails — with the browser's own network
error — so the symptom names the real problem instead of blaming the network.

**Why the worker sees admin URLs at all** (the ticket did not say): `pwa.js`
registers with `scope: '/'`, so it controls **every** same-origin navigation.
wp-admin never loads `pwa.js` and does not need to — a registration made on a
member page covers the whole origin. Both navigation branches in `sw.js`, the
RESILIENT one and the legacy one, end at `caches.match('/offline.html')`.

## 2. THE TRAP — the obvious fix is wrong and passes a dev2 test

`sw.js` already has a bypass list, `BYPASS_PREFIXES`. Adding `/wp-admin` to it
is what the ticket's framing invites. **It does not work**, because:

```js
function isBypassed(url) {
  if (!RESILIENT || !IS_DEV2) return false;   // <- inert on live, inert flag-off
```

`IS_DEV2` is a hard hostname check. On `loothgroup.com` that function returns
false for everything, and `RESILIENT` only exists when the pwa-sw flag is on.
So the obvious fix is **inert exactly where the members are**.

The real fix is `isAdminSurface()`, checked **before both navigation branches**
and **unconditional** — no `RESILIENT`, no `IS_DEV2`.

**Proven, not argued:** red-first B applies that wrong fix, and gate 43 passes
it on *dev2-with-flag-ON* while failing the other three combinations. A
dev2-only gate would have shipped it.

**Matched as a whole path or directory prefix**, not a bare `indexOf`:
`/wp-admin` as a loose prefix also swallowed `/wp-adminfoo` and
`/wp-admin-ish/` — measured — which are member-facing URLs that would then
silently lose the shell.

## 3. NO CONF v-BUMP AND NO NGINX RELOAD (the ticket said otherwise)

The item claimed nginx `sub_filter` injects `/pwa.js?v=N` and that a v-bump plus
reload must ride the train. **Measured, all three:**

- **No `?v=` on `/pwa.js` in any running conf** — both occurrences are plain
  `src="/pwa.js"`. It was retired when `/pwa.js` moved to `pwa-loader.php`.
- **`/sw.js` and `/pwa.js` are both served `cache-control: no-cache`** — read
  off the wire, not from a conf.
- **`sw.js` calls `skipWaiting()` in both install paths and `clients.claim()`
  on activate.**

Together: a changed `sw.js` is revalidated on the next service-worker update
check and takes over **immediately**, without waiting for tabs to close. Nothing
here needs a version bump, and keeper should not carry a reload for it.

## 4. Why it is unflagged

House rule says member-facing changes ship behind an OFF-default flag. This was
raised with keeper and shipped unflagged on the reasoning that it makes the
worker do **less**, on **admin URLs only**, and an OFF default would mean Ian
keeps seeing the wrong surface until someone remembers to flip it. If that call
is ever revisited, the change is one function and one guard — trivially
flag-wrappable.

## 5. Traps hit in this lane

- **The branch was cut before my own earlier merge landed**, so it was missing
  gates 40/41/42 and I was numbering 43 against a **stale roster**. The number
  was genuinely free, but the roster I checked was old. Rebase before trusting a
  gate-number check.
- **The branch tracked `origin/main`.** A plain `git push` would have put the
  commit **straight onto main**, bypassing the merge train. Caught by printing
  the upstream *before* pushing.
- **A post-rebase push was REJECTED** non-fast-forward, and the failure sat in a
  background task's output I had not read — the `&& echo "pushed"` never ran, so
  nothing announced it. Caught only by checking `unpushed` afterwards. Never
  trust a `&&` chain in a backgrounded command to tell you it worked.
- **A gate can catch your own wrong expectation.** Phase 4 originally asserted a
  cross-origin `/wp-admin/` would be left alone. It is *handled*, correctly —
  the admin rule is origin-scoped and declines to claim a path on someone else's
  domain. Assertion flipped, reasoning written down.

## 6. Open

Nothing blocking. The only thing not verifiable from here: the fix cannot be
exercised on the dev2 **serve** until merge, because the serving checkout runs
`main`'s `sw.js`. After merge, a real check is: open a wp-admin URL with the
origin failing and confirm the browser's own error appears, not our shell.
