# PERF-BASELINE.md — Performance Czar baseline

> **Audit #2 (2026-06-04)** — see [Audit #2](#audit-2--2026-06-04-re-sweep) at the bottom.
> - **`/lgjoin/` migrated off BuddyBoss+Elementor** (another lane): logged-in member render
>   **7.67 MB / 182 req → 0.38 MB / 13 req**, LCP 8.5 s → ~2.2 s. The #1 offender is resolved. 🎉
> - **No real regressions** — the profile/cpt-video "regressions" on first re-run were single-run noise
>   (profile TBT is actually 11–36 ms). Hub stays volatile (LCP 0.7–3.2 s) — same standing image+JS issue.
> - **Archive unchanged** — the card-resize fix is still pending a `content_item` rebuild (not re-ingested).
> - **Method note:** single runs swing 2–4× on LCP/TBT for client-rendered pages — take the **median of 3**.
>   Harness now lives durably at `tools/perf/` (it was wiped from `/tmp`).

> **Proven wins log (2026-06-03)** — see [Proven changes](#proven-changes-2026-06-03) at the bottom.
> - **whoami repoint:** logged-in identity call **~590 ms → ~5 ms** per authenticated page (~99% faster).
> - **archive/hub card images:** resolver fix takes real card images **4051 KB → 709 KB (82% smaller)**;
>   projected `/archive/` first-paint **~2.78 MB → ~0.69 MB**. (Lands on next `content_item` rebuild.)
> - **Correction:** the archive **CLS 0.151** in the first baseline was a single-run outlier — CLS is
>   reliably **0** (card CSS already reserves `aspect-ratio`). No CLS fix needed.

**Owner:** perf-czar lane · **First captured:** 2026-06-03
**Method:** headless Chrome 148 via CDP (`chrome-dev.service`, 127.0.0.1:9222), **logged in** as
`gerryhayes` (WP user 4, administrator) through the cookie gate. **Cold cache**
(`Network.setCacheDisabled=true`) — i.e. worst-case asset weight, what a first/forced-reload visit pays.
LCP/CLS/TBT via buffered `PerformanceObserver`; transfer = CDP `encodedDataLength` (on-wire bytes);
request count = CDP `Network.responseReceived`. Harness: `/tmp/perf-capture.py` (+ `dump-res.py`, `summ.py`).

> Single run per surface — treat LCP/TBT as ±15% indicative, not gospel. Re-run before/after any fix.
> Server TTFB figures below are separate `curl` measurements (anon vs logged-in), not the browser run.

## Baseline table (logged-in, cold cache)

| Surface | URL | Req | Transfer | LCP | FCP | TBT | CLS | DOM nodes | Server TTFB (anon / logged-in) |
|---|---|--:|--:|--:|--:|--:|--:|--:|---|
| Archive (search) | `/archive/` | 18 | **2.78 MB** | 2.21 s | 1.38 s | 134 ms | 0.000¹ | 800 | 0.20 s / 0.10 s |
| CPT standalone (video) | `/post-type-videos/3d-club-…/` | 12 | 0.61 MB | 0.79 s | 0.43 s | 107 ms | 0.001 | 472 | 0.18 s / 0.17 s |
| CPT standalone (imgcap) | `/post-imgcap/f5-l-mandolin-…/` | 14 | 0.84 MB | 0.91 s | 0.47 s | 99 ms | 0.001 | 544 | 0.25 s / 0.18 s |
| Hub (bb-mirror → archive front) | `/` | 47 | **2.90 MB** | 1.19 s | 0.42 s | **623 ms** | 0.000 | 1985 | 0.15 s / 0.10 s |
| Profile | `/members/gerryhayes/` | 21 | 0.45 MB | 1.18 s | 0.58 s | 144 ms | 0.029 | 448 | 0.10 s / 0.09 s |
| **Membership — Join** | `/lgjoin/` | **182** | **7.67 MB** | **8.50 s** | **8.50 s** | **1364 ms** | 0.086 | 1000 | **2.31 s / 2.34 s** |
| Membership — Guide | `/membership-guide/` | 8 | 0.09 MB | 1.79 s | 1.79 s | 0 ms | 0.000 | 232 | 1.02 s / 1.98 s |

Bold = out of budget. Budget targets proposed at the bottom.
¹ First baseline showed CLS 0.151; on re-test it is reliably **0** across 4 cold loads — that figure was a
single-run outlier (cold web-font swap on first paint). The card CSS already reserves `aspect-ratio: 16/9`.

### Asset mix per surface (cold)
- **Strangler surfaces** (archive, CPT, hub) all share one lean bundle: **~106 KB JS + ~119 KB CSS**.
  Their weight is almost entirely **images**: archive 2.51 MB / 6 imgs, hub 2.69 MB / 35 imgs.
- **CPT standalone** renders (archive-poc renderer) are the healthiest pages on the site: ~0.6–0.8 MB,
  LCP < 1 s, single 23 KB JS file. No action needed — do not micro-optimize these.
- **`/lgjoin/`** loads the entire legacy classic stack — **58 CSS + 108 JS files**:

  | Source | Files | Decoded |
  |---|--:|--:|
  | buddyboss-theme | 26 | 2.28 MB |
  | buddyboss-platform | 29 | 1.85 MB |
  | elementor | 11 | 0.99 MB |
  | buddyboss-platform-pro | 23 | 0.43 MB |
  | dynamic-content-for-elementor | 27 | 0.40 MB |
  | advanced-custom-fields-pro | 2 | 0.26 MB |
  | search-filter (+pro) | 13 | 0.33 MB |
  | wp-includes + misc | ~40 | ~1.7 MB |

## The headline finding (inverts the briefing's assumption)

The "hub" (`/`) is **not** the heavy BuddyBoss surface anymore — it has been mirrored onto the lean
archive front (LCP element is `H3.acard__title`). The strangler surfaces are **fast** server-side
(100–180 ms logged-in TTFB) and carry a small shared bundle.

The BuddyBoss/Elementor bloat now lives on the **legacy WordPress `page` surfaces — chiefly the
membership/conversion pages** (`/lgjoin/`). `/lgjoin/` is the single worst page on the property by
every metric: 7.7 MB, 182 requests, 8.5 s LCP, 1.36 s TBT, **and** 2.3 s server TTFB. It is both a
front-end and a back-end problem, and it is a high-intent conversion page.

## Top offenders — effort vs impact

### 1. `/lgjoin/` + legacy BuddyBoss/Elementor pages — 7.7 MB / 182 req / LCP 8.5 s / TTFB 2.3 s
**Impact: critical. Effort: high.** This is the strongest "site used to be faster" candidate and it's a
revenue page. Renders through buddyboss-theme + platform(+pro) + Elementor + Dynamic-Content + ACF Pro +
Search-Filter. Slow in PHP (2.3 s TTFB) *and* on the wire (166 unbundled CSS/JS files).
- **Long term:** this is exactly what the strangler/stream migration retires — rebuild the membership
  pages on the lean stack.
- **Short term, in reach:** dequeue the asset stacks that the join page doesn't actually use
  (Elementor + Dynamic-Content + Search-Filter are almost certainly dead weight here), and check why
  PHP render is 2.3 s. Could plausibly halve both numbers without a rebuild. **Audit needed before touching.**

### 2. Full-resolution images on archive + hub cards — 2.5–2.7 MB ✅ FIXED (pending rebuild)
**Impact: high. Effort: low. Fully in-lane (archive-poc ingest). Code landed 2026-06-03.** Cards served
the **original uploads** scaled down in the browser: the worst archive card was a **3024×4032 / 1.9 MB**
phone photo (`IMG_5649.jpeg`) painted into a ~320 px slot. Root cause: the three ingest scripts resolved
the thumbnail with `wp_get_attachment_image_url($id, 'full')`.
**Fix:** a `sized_thumb_url()` helper that returns a card-sized derivative — named sizes
(medium_large→medium→large) for standard images, and a metadata scan of `$meta['sizes']` for BuddyBoss
media (`bb-media-*` keys, which the named sizes miss). **Measured 4051 KB → 709 KB (82%)** across the
real card images; the two `bb_medias` monsters drop 1.9 MB→76 KB and 1.14 MB→32 KB. See [Proven
changes](#proven-changes-2026-06-03). (The CLS concern from the first baseline was an outlier — see ¹.)
*Known minor edge:* an already-small ~42 KB social image grows to ~62 KB (768 px re-encode of a near-768
original) — trivial against the multi-MB wins; a byte-size guard could be added if it recurs.

### 3. Logged-in `/whoami` ≈ 1.0–1.5 s, client-side, on every logged-in page ✅ RESOLVED (coordinator)
**Status: FIXED 2026-06-03 by the coordinator/header-keeper lane.** whoami was repointed from the WP-shim
REST route (`/wp-json/looth/v1/whoami`) to profile-app's JWT endpoint (`/profile-api/v0/whoami`) — see
`archive.js:1449`. **~590 ms → ~5 ms** per authenticated page (verified in-browser as pilot_pro on
`/archive/`; independently corroborated here by curl: old shim **0.72–0.90 s** cold vs direct endpoint
**0.06–0.10 s** cold). Original diagnosis retained below for the record.

> _Original finding (pre-fix):_
**Impact: medium–high. Effort: medium. Route through coordinator — do not touch in-lane.**
Anon whoami is ~9.5 ms (short-circuits). **Logged-in is 1.0–3.1 s** because the WP shim
(`mu-plugins/profile-whoami-shim.php`) handles `/wp-json/looth/v1/whoami` as a normal authenticated WP
REST route — so it **boots the full WP + BuddyBoss + Elementor plugin stack** and then makes a fresh
**loopback HTTPS** call (new TLS handshake, HTTP/1.1, no keepalive) to profile-app just to proxy a
~550-byte JSON identity blob, minting a `looth_id` JWT each time. The **30 s Redis cache lives on the
profile-app side and only saves its internal DB work — the dominant cost (WP bootstrap + loopback TLS)
is uncached and paid on every call.** It does *not* block initial paint (strangler TTFB is fine; the
header fetches it async), but it delays header hydration and hurts INP/perceived readiness for
logged-in users. Suspected fixes (for coordinator/header-keeper to weigh): serve whoami from a
lightweight path that skips full plugin bootstrap, reuse a keepalive/UNIX-socket upstream, or let the
browser reuse the `looth_id` JWT for the cache window instead of re-proxying.

### 4. Hub `/` — TBT 623 ms, 1985 DOM nodes, load 4.4 s
**Impact: medium. Effort: medium. In-lane.** Same lean bundle as archive, but the front-end JS renders
35 cards into ~2000 DOM nodes — 8 long tasks (max 232 ms). Largely downstream of fix #2 (fewer/smaller
images = less decode/layout); revisit after the image fix and re-measure before optimizing the card JS.

### 5. 404s serve a 226 KB, 2–3 s BuddyBoss-themed error page
**Impact: low (but a trap). Effort: low.** Any missing asset or bad link on a strangler page pays a
full themed 404. Worth a lightweight 404 once the membership-page work is underway.

## Recommended FIRST fix — ✅ DONE
Card-image resizing at ingest (offender #2). Landed 2026-06-03 — see [Proven changes](#proven-changes-2026-06-03).
**Next recommended:** the `/lgjoin/`-class legacy pages (offender #1) — biggest remaining win, needs an
asset-dequeue audit (cross-checks Elementor/Search-Filter usage) before touching.

## Proven changes (2026-06-03)

### whoami repoint (offender #3) — coordinator/header-keeper lane
| | Before (WP shim) | After (profile-app JWT) |
|---|--:|--:|
| In-browser, warm (pilot_pro, `/archive/`) | ~590 ms | ~5 ms |
| curl, cold (this lane, gerryhayes) | 0.72–0.90 s | 0.06–0.10 s |

The header now calls `/profile-api/v0/whoami` directly (`web/archive.js:1449`) instead of routing through
a full authenticated WP REST bootstrap + loopback TLS. **~99% faster per authenticated page.**

### Card-image resize (offender #2) — perf-czar lane, in-lane
**Files:** `archive-poc/bin/{indexer,backfill,backfill-pg}.php` — replaced
`wp_get_attachment_image_url($id,'full')` with a new `sized_thumb_url()` / `archive_poc_sized_thumb_url()`
helper (named sizes for standard images; `$meta['sizes']` scan for BuddyBoss `bb-media-*` keys; full only
as last resort). Authoritative per-image before/after (resolver run in WP over the real `/archive/` cards):

| Card image | full | sized | Δ |
|---|--:|--:|--:|
| IMG_5649.jpeg (bb_media) | 1901 KB | 76 KB | −96% |
| IMG_3925.jpeg (bb_media) | 1136 KB | 32 KB | −97% |
| f5-mando-01-1.jpg | 238 KB | 167 KB | −30% |
| GuitarTek-…-Trial.jpg | 186 KB | 73 KB | −61% |
| April-13-2-1.jpeg | 141 KB | 64 KB | −55% |
| IMG_7264.jpeg | 132 KB | 52 KB | −61% |
| (others, 5 images) | — | — | mixed |
| **Total, 11 real card images** | **4051 KB** | **709 KB** | **−82%** |

Projected `/archive/` first-paint transfer: **~2.78 MB → ~0.69 MB**. Lands on the live page at the next
`content_item` rebuild (the PG search index that feeds the cards); **not yet re-ingested** — dev is
fixtures-only and `content_item` is the fragile half-migrated PG path, so the page-level number is a
verified projection, not a post-rebuild measurement. A scoped `content_item.thumb_url` re-resolution can
produce the live number on request.

## Proposed budgets (per surface, logged-in)
| Metric | Strangler pages (archive/CPT/hub/profile) | Legacy/membership pages |
|---|---|---|
| LCP | ≤ 2.0 s | ≤ 2.5 s (target after rebuild) |
| TBT | ≤ 200 ms | ≤ 300 ms |
| CLS | ≤ 0.05 | ≤ 0.1 |
| Transfer (cold) | ≤ 1.0 MB | ≤ 2.0 MB |
| Requests | ≤ 25 | ≤ 60 |

Today the CPT standalone renders, `/membership-guide/`, and (as of audit #2) `/lgjoin/`'s front-end are
inside budget; profile passes too. Archive will join once the card-resize fix is rebuilt into
`content_item`. **Hub** (transfer + LCP/TBT volatility) is the main surface still out of budget.

## Audit #2 — 2026-06-04 (re-sweep)

Same method, logged in as gerryhayes (now with a `looth_id` JWT so the archive front authenticates via
the repointed `/profile-api/v0/whoami`). Volatile surfaces sampled 3× → median.

| Surface | Req (was→now) | Transfer (was→now) | LCP (was→now) | TBT (was→now) | Verdict |
|---|---|---|---|---|---|
| Archive | 18→18 | 2.78→2.78 MB | 2.21→~1.8 s | 134→65 ms | unchanged (fix not re-ingested) |
| CPT video | 12→13 | 0.61→0.65 MB | 0.79→0.65 s | 107→~15 ms | stable |
| CPT imgcap | 14→15 | 0.84→0.89 MB | 0.91→0.78 s | 99→41 ms | stable |
| Hub `/` | 47→47 | 2.90→2.97 MB | 1.19→**0.7–3.2 s** | 623→**~317 ms** | volatile, standing issue |
| Profile | 21→20 | 0.45→0.43 MB | 1.18→~0.9 s | 144→**~30 ms** | fine (first run was noise) |
| **Join `/lgjoin/`** | **182→13** | **7.67→0.38 MB** | **8.5→2.2 s** | **1364→136 ms** | ✅ **migrated off Elementor** |
| Membership guide | 8→8 | 0.09→0.09 MB | 1.79→0.28 s | 0→0 | stable |

**`/lgjoin/` resolved (offender #1):** logged-in it now renders a lean 83 KB HTML page — no
`buddyboss-theme`, no `elementor` markup — vs the 7.67 MB / 166-file Elementor stack on 2026-06-03.
Server TTFB is still ~1.9 s (PHP render) so there's a smaller follow-up there, but the front-end
catastrophe is gone. (Anon `/lgjoin/` is a 9.5 KB gate/redirect stub.)

**No regressions confirmed.** First-pass spikes on profile (LCP 2.6 s / TBT 623 ms) and cpt-video
(TBT 218 ms) did not reproduce — profile re-ran at LCP 0.78–2.0 s / TBT 11–36 ms, video at TBT 0–30 ms.
**Lesson:** trust the median of 3, never a single run, on client-rendered pages.

**Remaining offenders, re-ranked:**
1. **Hub `/`** — now the heaviest live surface: 2.97 MB / 35 unsized images, LCP swings to 3.2 s, TBT
   ~317 ms. Lands the same card-resize fix once `content_item` rebuilds; the LCP/TBT volatility is the
   card-render JS over ~2000 DOM nodes.
2. **Archive** — image fix coded but not live (awaiting `content_item` rebuild).
3. **`/lgjoin/` server TTFB ~1.9 s** — small PHP-render follow-up, front-end already fixed.

## How to re-run
```bash
# harness now persists at projects/tools/perf/ (was wiped from /tmp between sessions)
# 1. log the browser in (chrome-dev-login skill): gate + WP cookies + a looth_id JWT
# 2. capture each surface 3x, take the median:
python3 /home/ubuntu/projects/tools/perf/perf-capture.py "<label>" "https://dev.loothgroup.com/<path>/" 5
```
