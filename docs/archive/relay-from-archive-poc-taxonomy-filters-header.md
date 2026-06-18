# Relay → Coordinator: archive-poc session wrap-up (taxonomy, filters, header Step 1)

**From:** archive-poc lane
**Date:** 2026-06-04
**Re:** Search-type taxonomy curation, archive filter changes, events disposition,
and Header Convergence Step 1. All landed; **commits held locally pending Ian's
push sign-off** (3 commits + an uncommitted taxonomy follow-up — see Status).

---

## 1. Archive search-type taxonomy — finalized

The `/archive/` type filter and the underlying `content_item.kind` taxonomy were
cleaned up. **Final user-facing type chips:**

> Articles · Videos · Loothprints · **Loothcuts** · Discussions · Benefits ·
> Sponsor Posts · Shorts · Useful Links

Changes from before:
- **sponsor-post / shorty / useful_links** — broken out of the generic `misc` bucket
  into their own kinds (kind map ×3 indexers + `ALLOWED_KINDS` + client labels/order).
- **loothcuts** — broken out of the `loothprint` kind into its own `loothcuts` kind
  (was folded into Loothprints by commit ba882e1). 7 published loothcuts now index as
  their own type; `loothprint` count dropped 179→172 accordingly.
- **misc** — removed from the filter chips (dropped from client `KIND_ORDER`). The 7
  remaining misc items (sponsor-page ×6, sponsor ×1) still appear in "All" results and
  keep a "Misc" label on their cards; they're just not independently filterable.
- **events** — removed from the type filter (see §2).
- Legacy WP `post` — remains structurally excluded (not in any kind map).

## 2. Events disposition — indexed, hidden from the archive page

Per Ian: **keep events in the index, don't render them on the archive page.**
- `event` stays in the kind map → indexed normally, so the **front-page "Upcoming
  events" rail** (`archive_poc_run_events_upcoming()`, reads `kind='event'` directly)
  keeps working. Verified: 9 events present.
- The archive **search API** hard-excludes events (`ci.kind != 'event'` in the base
  WHERE) from results AND facets; **search-suggest** excludes them from its posts
  queries (`kind NOT IN ('discussion','event')`). Verified: 0 events leak into `/archive/`.
- (Detail also in docs/relay-events-pulled-from-archive.md.)
- Still untouched & independent: the WP-fed **activity strip** can still show
  `event`-type activity. Ask if you want that filtered too.

## 3. Header Convergence Step 1 (your relay-header-convergence.md) — DONE

`archive-poc/web/_chrome.php` now sources `$ctx` identity from `/whoami` **verbatim**:
- `tier` was coming from `$GLOBALS['LG_VIEWER_TIER']` (default `public`) → the tier pill
  never showed for members. Now `tier`/`authenticated`/`capabilities` come from
  `$_whoami`; `profile_url` → slug-based `/u/<slug>`; the `LG_VIEWER_TIER` global path is
  deleted. Now byte-parallel with the reference `profile-app/web/_chrome.php`.
- Verified by composition: feeding `tier:'pro'` renders the `lg-chrome__tier--pro` "Pro"
  pill; both `/archive-poc/` and `/archive/` serve 200 with the shared header.

**⚠️ Cross-cutting flag for lg-shell / whoever owns the poller:** I could not show a
live Lite/Pro pill on **dev**, and it is NOT a consumer bug. `/whoami` resolves real
members (`authenticated:true`, name, caps) but returns `tier:'public'` for **everyone**,
because the poller-tier endpoint **`/wp-json/looth-internal/v1/user-context/<id>` 404s on
dev**. That suppresses the tier pill site-wide across ALL consumers equally (`/u/`,
`/hub/`, `/archive/`) — so archive-poc now *matches* them, which is the convergence goal;
the literal "PRO sees PRO pill" check will pass on live (real poller) or once that dev
endpoint is restored. Also noted: `/whoami` returns `capabilities` as a **list** of cap
strings, but the shared header's Admin-pill override checks an **assoc** map
(`$caps['manage_options']`), so the Admin override currently no-ops uniformly across all
consumers — a `$ctx` contract detail for lg-shell to reconcile if the Admin pill matters.

## Status / what coord needs

- [x] taxonomy + filters + events + header Step 1 implemented & verified on dev
- [x] **3 archive-poc commits ALREADY in `origin/main`** (pushed a prior session — they are
  ancestors of `d239cc8`; the "held, pending sign-off" status was stale/incorrect):
  `5b39a09` (taxonomy breakout) · `b9b61bf` (events read-layer) · `a9e130c` (header /whoami).
- [x] **Separately pushed 2026-06-04** (different lane, coord-reviewed + verified): bb-mirror
  `c6fba86` + loothprint `e67bf96` / `ba882e1` / `50ba49f`.
- [ ] **loothcuts + misc-filter follow-up still UNCOMMITTED** — tangled in the working tree
  with stream-launch WIP, the held bb-mirror `materializers.php` guard, and the
  `index.sqlite` binary. Needs surgical scoping (named hunks, no broad `git add`) before commit.
- [x] **Archive filter ask — RESOLVED:** Ian confirms it was the loothcuts type chip (already
  shipped); no tier/tag/date filter pending.
- [ ] **lg-shell / poller owner:** dev poller-tier 404 (blocks tier pill site-wide on
  dev) + the capabilities list-vs-map `$ctx` detail.
- [ ] (carry-over, unrelated to this batch) `/hub/` deep-links still 302→login until
  bb-mirror deploys the `^~ /hub/` location + `/forum/→/hub/` 301.

— archive-poc lane
