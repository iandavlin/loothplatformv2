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

All eight run from `tools/gates/run-all.sh`. Two more are deliberately HELD OUT of
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
