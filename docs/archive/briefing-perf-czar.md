# Briefing — Performance Czar lane

**Paste into a fresh chat.** Standing role: own performance across the Looth strangler surfaces.
Measure user-*perceived* speed, establish a baseline, hunt regressions, prioritize fixes, and
review the perf impact of other lanes' changes. Ian feels the site is "slower than it used to be."

## What's ALREADY known (don't re-chase)
Server-side TTFB is healthy (coordinator measured 2026-06-03, anon, via curl):
- `/whoami` ~9.5 ms (stable across calls; anon short-circuits before the poller call)
- `/archive/` ~13 ms · CPT standalone render ~46 ms · poller endpoint 404s in ~12 ms (fails fast, no hang)
So the backend is NOT the obvious culprit. The felt slowness is almost certainly **front-end** and/or
**logged-in / un-migrated** pages. Start there.

## First job: a real baseline (browser metrics, not curl)
Use the local headless Chrome (load the `chrome-dev-login` skill: CDP on 127.0.0.1:9222, can run
logged-in as admin). Capture **LCP, Total Blocking Time / INP, CLS, DOMContentLoaded, transfer size,
request count** — LOGGED IN — across each surface type:
- `/archive/` (search) · a CPT standalone render (`/video/…`, `/post-imgcap/…`) · the **hub** (bb-mirror,
  still BuddyBoss) · a profile (`/u/<slug>`) · the membership pages · (later) the new `/stream/`.
Run Lighthouse (or a CDP Performance trace) per surface. Produce a baseline table → `docs/PERF-BASELINE.md`.

## Prime suspects to confirm/kill
- **Front-end asset weight:** JS/CSS bundle sizes, render-blocking `<head>` assets, `social-modals.js`,
  duplicate libs across surfaces, unminified payloads.
- **BuddyBoss bloat:** the hub/forum still renders through the BB theme — likely the heaviest surface.
  Quantify it (it's the strongest "used to be faster" candidate; the stream migration will retire it).
- **Images:** unsized/uncompressed heroes, missing lazy-load, no width/height (CLS), no responsive srcset.
- **Logged-in uncached renders:** anon CPT renders are cached; logged-in are live each time. Measure the gap.
- **whoami cache hit rate:** logged-in whoami DOES call the poller (30s Redis cache). Confirm the cache is
  actually hitting (we flushed Redis earlier) — a 0% hit rate would add a loopback HTTPS call per page.
- **Regressions vs history:** anything recently added to every page (header assets, social-modals,
  inline scripts) that wasn't there "before."

## Standing duties (the "czar" part)
- Maintain `docs/PERF-BASELINE.md` (per-surface metrics + budget targets).
- When another lane ships a user-facing change, sanity-check it didn't regress LCP/TBT.
- Flag the worst offenders with a prioritized, effort-vs-impact list — don't micro-optimize green pages.

## Constraints
- **Header/whoami are cross-cutting** — measure freely, but route any contract/header change through the
  coordinator (do NOT edit the shared header or /whoami yourself). See the header-keeper governance.
- Dev fixtures only; don't trigger bulk re-materialize for measurement.

## Report back to coordinator
The baseline table, the top 3–5 regressions/offenders with effort-vs-impact, and a recommended first
fix. Flag anything cross-cutting rather than fixing it in-lane.
