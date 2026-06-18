# Lane briefing — Member directory / MAP, DESKTOP layer (2026-06-07)

You own the **desktop (≥641) layer** of the member **directory + map** — the Leaflet map, the filter bar,
and the member-card grid at desktop widths. Part of the site-wide 640 split
(`docs/profile-map-mobile-desktop-split.md`).

Sanity-check the box: `curl -s ifconfig.me` → `50.19.198.38` = act locally, do NOT SSH. Commit by pathspec;
coordinator reviews, **git-tsar pushes — no silent pushes**.

## Scope (files)
- `profile-app/web/directory-members.php` — the directory page + member cards (the shared markup + desktop
  arrangement) and `profile-app/api/v0/directory-members.php` (the data endpoint, if you need fields).
- `profile-app/web/directory.css` — **desktop band ≥641** (map + filter bar + card grid). Leaflet init
  options for desktop (zoom, controls) live in the page's JS.

## The split — read it (`docs/profile-map-mobile-desktop-split.md`)
- The map is a **Leaflet JS widget**: split the **layout AROUND it** (filters, card grid) per breakpoint +
  feed Leaflet **per-breakpoint options** (zoom/controls) at init — that's config, NOT a JS reshape.
- **Breakpoint = 640** (site-wide). `directory.css` currently cuts at **760** — re-cut desktop to **≥641**.
- ⚠️ Watch the **640–820 band**: the desktop layout (map + filters + card grid) can feel cramped there;
  tune the desktop layout, the split line stays 640.

## Boundary with Buck (IMPORTANT — don't recreate the Hub collision)
- **Buck owns the MOBILE map layer (≤640)** (he owns `mobile-hub.*` + the profile/map mobile per the split).
  You own desktop ≥641. **First decision to settle with buck-coord:** mirror the Hub pattern and split
  `directory.css` into **two files** (desktop `directory.css` ≥641 + a `mobile-directory.css` ≤640 for Buck) —
  cleanest, no shared-file dance — OR keep one file with strict band ownership. Route via coordinator.
- `directory-members.php` (the shared card markup) is the contract between you + Buck — **announce any
  markup change to buck-coord** (this is exactly the discipline that broke on the Hub cards).

## Data provenance — already clean ✅
Member cards (avatar, name, location/about, profile link) source from profile-app
`/profile-api/v0/directory/members` (`users` table) — verified profile-app, no WP/gravatar. The
**Connect / Connected** buttons hit `/profile-api/v0/connections`; dev has a **10-row fixture** (real
friends-graph backfill runs at cutover, not dev — [[project_social_backfill_cutday]]).

## Report back (to coordinator)
`DONE · FILES · VERIFIED (desktop ≥641 + 640–820 band OK + no mobile regression) · NEEDS-BUCK · BLOCKED`.
Report your session ID + outliner title for CHATS-MENU + lineage.
