# Web-craft standard (Ian 6/12: "figure out how this gets fixed permanently")

The disease this kills: basics (image sizing, eager scripts, cache headers)
were re-discovered and re-fixed surface by surface — ~13 rounds on images
alone — because each session's lesson died with its context and "verified"
meant "the screenshot looks right." Screenshots can't see weight.

## The law

1. **Discovered twice → becomes a gate.** Any defect class found a second
   time MUST be encoded as a mechanical check in `tools/gates/` before it is
   fixed the second time. Fixes without gates are rent; gates are ownership.
2. **Gates run, not get remembered.** `tools/gates/run-all.sh` is the one
   entry point. Run it before any push that touches a user-facing surface,
   and as the cut acceptance gate (LIVE-DEPLOY-PLAN Phase D). A red gate
   blocks the push the same way a red visibility matrix does.
3. **Done = green gates**, not a screenshot.

## The craft checklist (what the craft gate enforces)

- **Images**: every same-origin content image goes through the resizer
  (`/img.php?s=…&w=…`) — never a raw `/wp-content/uploads/` original — with
  `srcset` (≥2 widths, browser picks by slot × DPR) and `width`/`height`
  attrs (layout reservation). No image ships >1.7× its rendered pixels.
- **Scripts**: no eager heavyweights a viewer can't use — editors (quill),
  composers, admin tooling load on intent (click/focus), never for anon.
- **Weight**: a page's image transfer stays under budget (gate: 1.5 MB);
  total transfer under 2.5 MB.
- **Caching**: versioned static assets (`?v=`) ship long-lived
  `Cache-Control` (nginx d0457fc pattern).
- **Page furniture**: HTML that must not cache (front page) says so; pages
  carry exactly one h1; lazy-load below-the-fold media.

## Documented exceptions (decided, not missed)

The checklist above is the default. An image that departs from it gets an entry
HERE, with the measurement that forced it — so the next reader can tell a
considered trade from an oversight, and doesn't "fix" it back into a defect.

### YouTube thumbnails on discussion cards — `mqdefault`, no `srcset`

`bb-mirror/web/forums/_feed.php` renders a YouTube facade on a discussion card
whose body leads with a YouTube link. When the discussion has no attached photo
of its own, the thumb is `https://i.ytimg.com/vi/<id>/mqdefault.jpg` — 320×180,
cross-origin, **one width, no `srcset`**. That is deliberate:

- **Sharper costs a broken card.** The higher-resolution rungs do not always
  exist. Measured over all 13 YouTube discussions on dev2 (2026-07-30), `hq720`
  and `maxresdefault` return **404 on one of them**. A 404 in a `srcset`
  candidate is a *broken image*, not a graceful fallback — the browser does not
  retry another candidate. One reliably-soft card beats an occasionally-empty one.
- **The 4:3 rungs bake in letterboxing.** `hqdefault` (480×360) and `sddefault`
  (640×480) are 4:3 canvases with black bars burned into the pixels, so they put
  bars *inside* the cover box. `mqdefault` is the only always-present rung that is
  true 16:9.
- **Weight.** Those rungs run 58–206 KB each against a 1.5 MB PAGE-IMG-BUDGET,
  and one forum page can carry 7+ facades. They *do* count: `i.ytimg.com` sends
  `timing-allow-origin: *`, so Resource Timing reports a real `transferSize` and
  the gate's budget sees the bytes. `mqdefault` is 5–19 KB.

**Known cost: it is soft on a high-DPR phone.** ~1× on desktop, ~0.3× on a 3×
phone. That is known, not missed. Do not add `srcset` to "fix" it without first
re-measuring rung availability — the reason this is one width is availability,
not laziness.

The escape hatch already works: a discussion that carries its own attached photo
uses *that* as the thumb, and that path is fully compliant — resizer, `srcset`,
`width`/`height`.

### Not an exception: `img.php?s=bb_medias/…` without dims

`/hub/<forum>/` ships a topic cover through the resizer *without* `width`/`height`.
It is a per-attachment data gap, not a missing code path: on
`/hub/share-your-repair-content/`, **4 of the 5** `bb_medias` covers carry both
attrs and one (`bb_medias/2026/06/1000024061.jpg`) carries neither, so
`lg_cover_dims()` is resolving for most rows and coming back empty for that one.
All five `<img>` tags are **byte-identical between `main` and the
`discussion-card-video` branch** (verified 2026-07-30) — pre-existing, not
introduced by the facade. That page is not in the craft gate's
`PAGES`, so the gate has never seen it. Left alone on purpose (Ian, 2026-07-30):
adding the page would turn a *shared* gate red for a defect the lane did not
introduce and block every other lane. Tracked on the backlog instead; fix the
dims and add the page in the same change.

## The class that keeps getting through: presence is not reachability

Twice now Ian has reported a control he could not use while every check we had
was green, because the check asked whether the element **existed** and he was
asking whether he could **use** it:

1. **2026-07-30, the 🔔/✉ toggles.** 24 `[data-follow]` nodes in the DOM;
   `.fc-actions` wrapped to a second line and the card's `overflow: hidden` ate
   them on 17 of 18 cards. Gated by `follow-visible-gate.py`.
2. **2026-07-31, Sign in on the anon front page.** `wp-login` ×2 in the served
   HTML; `.lg-chrome__signin` was `display:none` from 820px down while its
   stand-in only appeared from 640px down, so **641–820px had no way in at all**
   — and it was live. Gated by `anon-signin-reachable-gate.py`.

Neither was covered, moved or missing from the markup. Both were rendered and
then hidden by CSS resolved across *two* stylesheets, which no static check of
either file can see. So:

- **Assert the goal, not the element.** "A signed-out visitor can reach Sign in"
  survives a redesign that moves the control; "`.lg-chrome__signin` is visible"
  gets edited away by the change that breaks the user.
- **Measure paint and hit-test, in a real engine.** Styled + sized + in-viewport
  + `elementFromPoint` at its own centre returns it. `querySelectorAll().length`
  is not evidence and must never be reported as if it were.
- **Bracket every breakpoint the behaviour crosses.** 1440 and 390 were BOTH
  green on the day the whole band between them was dead. Sampling "desktop and
  phone" is how a responsive lockout hides.

## Existing gates

| # | Gate | What it guards | Needs |
|---|---|---|---|
| 1 | `profile-app/bin/visibility-matrix.php` | the entire visibility model (67 asserts) | — |
| 2 | `tools/gates/craft-gate.py` | the checklist above, over real pages as anon+member | **a browser on CDP :9222** |
| 3 | `tools/gates/infra-sec-gate.sh` | cookie auth / source disclosure / cdp exposure | loopback |
| 4 | `tools/gates/hub-content-paragraph-gate.sh` | `content_html` keeps its paragraph breaks | — |
| 5 | `tools/gates/looth-auth-issue-gate.sh` | non-REST mint bounce (recurs on every DB reload) | loopback |
| 6 | `tools/gates/event-date-tz-gate.sh` | a UTC "today" must not judge a site-local stored date | — |
| 7 | `tools/gates/events-tap-navigates-gate.sh` | an events tap navigates; the retired mobile modal stays retired | — |
| 8 | `tools/gates/composer-topic-meta-test.js` | forum picker cloning + tags on the composer | node |
| 9 | `tools/gates/author-socials-live-gate.sh` | bylines RESOLVE socials from the profile store, never mirror ACF | loopback |
| 10 | `tools/gates/react-types-cover-standalone-gate.sh` | a rendered react button's type is one the endpoint ACCEPTS | loopback |
| 11 | `tools/gates/shop-planner-url-gate.sh` | `/shop-layout-planner/` still answers 200 **and still mounts the planner** | loopback, or `ssh live-ro` with `--live` |
| 12 | `tools/gates/anon-signin-reachable-gate.py` | an ANON visitor can **reach** Sign in in ≤1 tap at every width — visible *and* hit-tested, not merely in the DOM | **a browser on CDP :9222** |
| 13 | `tools/gates/dev-files-anon-unreachable-gate.py` | lane/dev tooling inside a deployed plugin tree is **unreachable by an anonymous request** — 404/403, not "absent from my checkout" | starts its own **gate-free** nginx (dev2's armed gate would 403 everything and false-pass) |

| 13 | `tools/gates/follow-digest-gate.py` | **PROMOTED into the sequence by this merge** (it was HELD OUT and red on purpose while the sender did not exist). The follow-digest flag's OFF state sends NOTHING: no cron armed, no cadence stored, the suppression filter proven to be the identity function, and the resolver proven to return zero recipients | wp-cli |

**Gate 13 is written BEFORE the feature it guards, and that is the point.** Every
other gate here encodes a defect class found twice. Email is unrecallable, so the
second occurrence is not survivable — this one is gated before the first. It was held
out of the runner only because a deliberately-red gate in the shared sequence would
block every other lane's push for a feature that does not exist; **the commit that
defines `LG_FOLLOW_DIGEST_ENABLED` must promote it into the sequence in the same
commit.** A gate guarding an unrecallable channel that is never promoted is worse
than no gate, because it reads as covered.

It also carries the answer to the trap that makes most OFF-state gates worthless:
**an absence assertion passes vacuously whenever the machinery that would produce the
presence is itself missing.** "No cron event is scheduled" is true on a box with no
WordPress at all. So every absence assertion in gate 13 is paired with a *liveness*
assertion proving the mechanism exists and is reachable first. Absence without
liveness is not evidence.

All thirteen run from `tools/gates/run-all.sh`. Two more are deliberately HELD OUT of
the runner because they pass standalone but flake red in sequence (CDP under load,
and loopback `/whoami` tripping infra's `limit_req` zone) — see the note at the
foot of `run-all.sh` for how to run `forum-visibility-gate.sh` and
`editor-rail-reachable-gate.sh` by hand.

**A gate that CANNOT RUN is not a gate that passed, and not one that failed.**
Gate 2 drives a real Chrome; with no engine on :9222 it reports one `GATE-ERROR`
per page and exits 1, which is indistinguishable from finding real violations —
it spent weeks looking red while it was in fact dead. Treat "no engine" as *no
verdict* and say so, rather than reporting a pass or a failure it never reached.
`origin/events-fix-verify` carries the three-state fix (exit 2 = CANNOT RUN);
until that lands, check for the engine before you believe gate 2 either way.

## Why this works when 13 fixes didn't

The visibility model stopped leaking the week it became ONE function plus a
test that fails. Nothing else in this project has ever stopped a recurrence.
This document exists to make that the default move, not the last resort.
