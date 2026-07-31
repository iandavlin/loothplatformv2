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

All eleven run from `tools/gates/run-all.sh`. Two more are deliberately HELD OUT of
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
