# 165 — the go-live header: anon Join points at OUR join page — plan

> **BUILT 2026-08-20** — approved by keeper against the #165 scope, built as
> written. Flag `header-join-stripe` defaulted OFF, gate **79** (94 assertions
> with the browser leg, 41 without; red-first 14/14). Not merged and not
> flipped — keeper merges, and the flip is two switches, not one (§2c). The one
> deviation from the plan is in §5: §D's reachability assertion became a DELTA
> ("the OTHER href is exactly as reachable as this one") plus a route-agnostic
> contract, because the first run proved the absolute form was measuring main.
> §2c's warning held exactly as written — /lgjoin/ still serves anon the stub.

## 1. What I'm solving

Ian, 8/20, verbatim on the issue:

> "can you Wire the header on Dev2 to have the stripe menuing that a logged out
> user would see?"

Today the anon header's **Join** goes straight to patreon.com — his own 6/12
ruling, and correct in a Patreon-only world. Now that the Stripe rail exists, a
logged-out visitor should land on **our** two-tier join page, `/lgjoin/`, which
offers both worlds. Behind a new flag, default OFF.

## 2. What I found — measured, not read

### 2a. Today's state, proven cookieless (this is the gate's red-first leg)

Anon (dev-gate cookie only, **no WP session**), via the loopback pin so
Cloudflare can't challenge the request:

```
HTTP  surface                  header Join href
200   /                        https://www.patreon.com/c/theloothgroup/membership
200   /hub/                    https://www.patreon.com/c/theloothgroup/membership
200   /events/                 https://www.patreon.com/c/theloothgroup/membership
200   /sponsors/               https://www.patreon.com/c/theloothgroup/membership
200   /connect-your-patreon/   https://www.patreon.com/c/theloothgroup/membership
200   /shop-layout-planner/    https://www.patreon.com/c/theloothgroup/membership
200   /directory/members/      https://www.patreon.com/c/theloothgroup/membership
200   /lgjoin/                 https://www.patreon.com/c/theloothgroup/membership
```

Seven independent apps (bb-mirror, archive-poc, events, profile-app,
membership-pages, lg-layout-v2, a docroot script) all render the same partial,
so this is **one** anchor to change — `lg-shared/site-header.php:631`.

### 2b. The charter's width warning is half right, and the half that is wrong matters

The charter says the ≤640px phone-condense path is the only anon door at small
widths and to cover it. Measured against the CSS: what condense hides is
**Sign in** (`.lg-chrome__signin { display:none }` at ≤640, with
`.lg-chrome__menu-signin` in the drawer taking over). **Join is not width-gated
at all** — `.lg-chrome__join` has no `display:none` in any media query in
`site-header.css`, and neither does `.lg-chrome__connect`. So the header itself
needs exactly one edit for every width.

**But there is a second anon Join, and it is the phone one.**
`webroot/bottom-nav.js:782` builds the PWA anon account sheet:

```js
var joinHref = hdrHref('.lg-chrome__join', 'https://www.patreon.com/c/theloothgroup/membership');
...
joinRow.href = joinHref; joinRow.target = '_blank'; joinRow.rel = 'noopener';
```

`hdrHref` reads the header, so the **href follows my change for free**. The
`target = '_blank'` does **not** — it is unconditional. Flip the flag and the
phone sheet opens `/lgjoin/` in a new tab; in the installed PWA
(`manifest.json` is `display: standalone`) that punts the member out of the app
into a browser to buy a membership. That is the mobile door Ian will actually
tap, so it is in scope.

Same unconditional `target="_blank" rel="noopener"` sits on the header anchor
itself. Both must become conditional on the destination being external.

### 2c. ⚠️ The thing that decides whether this ask lands at all

**`/lgjoin/` refuses anonymous visitors today.** Measured — anon GET
`/lgjoin/` returns **HTTP 200** carrying `<title>Not available</title>` and
`<h1>This page isn't available yet</h1>`. That is
`lg_membership_admin_gate_or_exit()`'s stub, reached because
`membership-pages/web/router.php` lists lgjoin as
`['lgjoin.php', 'testgroup', 'public']` and the column selector
`lgms_stripe_pages_live` is **off** on dev2, so the *prelaunch* column
(`testgroup`) applies. Anon is not an admin, not on the test-group list, holds
no invite → stub.

So flipping my flag alone gives Ian a logged-out Join button that lands on
"This page isn't available yet". The wiring would be correct and the ask would
have failed. **Two switches, flipped in the same window:**

| switch | where | who |
|---|---|---|
| the new header flag | `platform/config/header-join-stripe.local.php` on dev2 | keeper |
| `lgms_stripe_pages_live` | WP option, dash: Settings → LG Member Sync | Ian / keeper |

I am **not** changing the router's visibility columns — that is #150/#148
territory and `lgms_stripe_pages_live` is exactly the switch built for this
moment. I am instead **gating the coupling** (§5, leg E) so it cannot be
flipped half-way and quietly look broken.

### 2d. Good news worth reporting: the dev2 membership-pages 404 is FIXED

MEMBERSHIP.md and lane 150's board posts say *"every membership-pages surface
404s on dev2 (`fastcgi_param LG_MS_SLUG` delivers a wrong value)"* and that it
reddens gates 34b, 36 and digest-forum-images. **Re-measured today: they all
answer 200** — `/lgjoin/`, `/join/`, `/connect-your-patreon/`,
`/manage-subscription/`, each rendering the real shared header. Somebody fixed
the nginx line. That un-blinds the Stripe surface and should clear three reds;
I'll note it in the dossier and on the board.

### 2e. Mechanism check — the flag can be read from the shared partial

`site-header.php` is included by WP-free apps, so it cannot use `get_option`.
Proved by execution rather than assumption: a probe placed at
`/srv/lg-shared/` reports `__DIR__` = `/home/ubuntu/loothplatformv2-clean/lg-shared`
— PHP resolves the `/srv` symlink — so `__DIR__ . '/../platform/config/…'`
lands on the tracked config. Probe removed; serving checkout verified clean and
still on `main`.

## 3. Files I expect to touch

Guessing wider rather than narrower, per LANE-RULES.

| file | why |
|---|---|
| `lg-shared/site-header.php` | the anon Join anchor + the flag reader |
| `platform/config/header-join-stripe.php` | **NEW** tracked config, `'enabled' => false` |
| `webroot/bottom-nav.js` | PWA anon sheet: `target="_blank"` only for external hrefs |
| `tools/gates/header-join-gate.py` | **NEW** gate 79 |
| `tools/gates/run-all.sh` | gate 79 entry (⚠️ collision file — 155-page-train, emoji-picker-build) |
| `docs/FLAGS.md` | the flag's row (gate 62 enforces this) |
| `docs/CRAFT-STANDARD.md` | gate table row 79 (⚠️ collision file — 107-consent-followup, 155-page-train) |
| `docs/domains/MEMBERSHIP.md` | closing commit, per the domain rule |
| `.gitignore` | expected **no change** — `platform/config/*.local.php` glob already present (line 70); listed so a surprise is visible |
| `lg-shared/site-header.css` | expected **no change** — the pill's classes are untouched, so gate 36 keeps owning its contrast; listed for the same reason |
| `handoffs/plans/165-header-join-PLAN.md` | this file |

## 4. The flag

`platform/config/header-join-stripe.php` → `['enabled' => false]`, copying
`back-pill.php`'s shape exactly:

- tracked file loaded first; `header-join-stripe.local.php` (gitignored)
  `@include`d after and allowed to win **only** on
  `array_key_exists('enabled') && === true`; unreadable or malformed → the
  tracked value stands.
- `LG_HEADER_JOIN_STRIPE` read from **both** `getenv()` and `$_SERVER` — a
  `fastcgi_param` lands in `$_SERVER` only, so a getenv-only reader serves the
  OFF path on the very preview URL built for Ian to click. Preview/red-first
  only, never a deploy mechanism.
- **OFF is byte-identical, and that is asserted, not argued.** OFF emits
  `<a class="lg-chrome__join" href="https://www.patreon.com/c/theloothgroup/membership" target="_blank" rel="noopener">Join</a>`
  byte-for-byte. ON emits `<a class="lg-chrome__join" href="/lgjoin/">Join</a>`
  — no `target`/`rel`, because it is our own page.
- Destination is a documented literal `/lgjoin/`, not a config key. One switch,
  one destination; a second knob is a second thing to misconfigure.

## 5. Gate 79 — `tools/gates/header-join-gate.py` (number keeper-minted)

Reads the flag; never hardcodes a state. Five legs:

- **A. Source**, on comment-stripped tokens so prose can never satisfy an
  assertion: exactly one anon join anchor; its href comes from the reader, not a
  literal on the ON path; the reader consults `getenv()` **and** `$_SERVER`; the
  `.local.php` override is honoured.
- **B. Render, all three states** — execute the partial under `php` with a fixed
  anon ctx for **absent / OFF / ON**. Absent and OFF → patreon href **with**
  `target="_blank"`; ON → `/lgjoin/` **without** it. An **authed** ctx yields no
  anon join anchor in any state, and the stripe-tester menu's existing
  `/lgjoin/` item is unchanged.
- **C. OFF is byte-identical to main** — render the partial from the branch and
  from `git show origin/main:lg-shared/site-header.php` with the same ctx and
  `cmp` them. This turns "OFF is a no-op" from a claim into a permanent gated
  fact — the exact assertion whose absence is the recurring failure class here.
- **D. Reachability, in a real browser** — anon on `/hub/` at 1440 / 821 / 820 /
  641 / 640 / 390 (the two bracketing pairs gate 12 established): a Join control
  is visible and **hit-testable** (`elementFromPoint` resolves to the link, not
  to furniture painted over it) and its href equals the flag-derived
  destination. At ≤640 the **PWA anon sheet's** Join row too, asserting it does
  **not** carry `target="_blank"` when the href is internal. Repeated in dark,
  since the shared chrome profile persists a theme.
- **E. The coupling** — **when the deployed flag is ON**, anon `GET /lgjoin/`
  must not be the pre-launch stub. Under OFF this leg **reports** and does not
  assert, so it cannot redden every lane today; under ON it is a hard assertion,
  which is what stops the two switches being flipped half-way.

Every asserting leg in A/B/C is pure source + `php` — no DB, no network — so
the gate cannot flake under load. D and E are the only legs that touch a
browser or the network, and each says so in its own output.

**Red-first**: mutations applied to a **snapshot copy**, never
`git checkout --`, each reddening its own named assertion — href hardcoded on
the ON path; `$_SERVER` dropped from the reader; `.local.php` override removed;
`target` left unconditional; the OFF href changed by one character; the
bottom-nav external test inverted — plus **two no-op mutations** (rename a
local, reflow whitespace) confirmed to redden nothing, so the gate is neither
always-red nor always-green.

## 6. What I am deliberately NOT touching

- **`/connect-your-patreon/`** — untouched, at every width. The dual-rail
  founding law: the join page itself offers both worlds.
- **`membership-pages/web/router.php`** — the visibility columns belong to the
  Stripe lanes; `lgms_stripe_pages_live` is the intended switch.
- **Three other anon Patreon join CTAs**, found while sweeping, all out of
  charter and all reported rather than changed:
  `profile-app/web/directory-members.php:154` (*"Join on Patreon →"* card on
  the members directory), `archive-poc/web/defaults.php:88` (*"Join Looth
  Group"* footer nav) and `archive-poc/web/_chrome-footer.php:40`
  (*"Membership"*). Each is explicitly Patreon-labelled, so none is wrong
  today — but they are where this same question lands next, and Ian should get
  to rule on them rather than have me guess.
- **`lg-shell/lg-shared/site-header.php`** — a 557-line stale twin of the real
  1044-line partial, serving nothing. Left alone; noted so nobody edits it
  believing it ships.

## 7. What has to happen after BUILD DONE (keeper + Ian)

1. keeper merges the branch.
2. keeper places `platform/config/header-join-stripe.local.php` with
   `['enabled' => true]` on dev2. **Not** an FPM `env[]` — dev2's pool files
   symlink into the serving checkout, so an env flip dirties a tracked file
   there and a later `pull --ff-only` can refuse.
3. **In the same window**, `lgms_stripe_pages_live` goes on, or Ian's logged-out
   Join lands on "This page isn't available yet". Gate 79 leg E holds this shut.
4. Ian browses dev2 logged out — desktop and phone — and clicks Join.

## 8. Risks

- **`run-all.sh` and `CRAFT-STANDARD.md` are live collision files.** I'll append
  after gate 76 (where 75 and 76 sit), keep both sides on any conflict, and
  check no suite is mid-run before editing — bash reads a script by byte offset,
  so a length change during a run can corrupt it.
- **Gate 79 sits below the RED-EXIT-SKIP-MARKER** (run-all.sh:1227) like 63, 73,
  75 and 76, so on a red main it will be reported as skipped rather than run.
  I'll verify it green standalone and say so in exactly those words rather than
  claiming a green suite.
- **Ian browsing dev2 outranks the fleet.** `uptime` before leg D, and I kill my
  own browser work on a fleet-quiet order.
