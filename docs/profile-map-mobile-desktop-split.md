# Profile + Map — mobile/desktop split (CONFIRMED 2026-06-07, Ian)

**Decision:** split the **profile page** and the **directory/map** into a desktop layer and a mobile layer
at **640px** — the SAME breakpoint as the Hub (`hub-mobile-desktop-split.md`). One mobile cutoff
site-wide. **Buck owns BOTH layers** (desktop ≥641 + mobile ≤640) — it's his profile-app, so no
cross-lane shared-file dance; but keep the markup flat + stable so the two CSS layers stay disjoint.

These are **two surfaces with two different rule-sets** — don't conflate them.

## A. Profile page (`profile-app/src/Profile.php` render) — pure Hub template
Copy the Hub split exactly:
- **One server-rendered FLAT markup** — `Profile.php` emits every region as a sibling with stable
  class names + data-attrs. The markup IS the contract.
- **Desktop CSS `@media (min-width:641px)`** + **mobile CSS `@media (max-width:640px)`**, disjoint files.
- **Mobile CSS is a media-gated `<link>` in `<head>`** (`media="(max-width:640px)"`) so it paints on
  first load — **NOT injected by JS** (deferred JS = the flash).
- **CSS-ARRANGE** (`grid-template-areas`) per breakpoint. **Never JS-reshape the DOM** (= the flash we
  killed on the Hub).

## B. Directory / Map (`profile-app/web/directory.css`, Leaflet) — split the LAYOUT, not the widget
The map is an **interactive Leaflet JS widget** — the "never JS-reshape" rule applies to the layout
*around* it (filter bar, member list), not the map canvas:
- **CSS** arranges the surrounding layout per breakpoint (desktop: map + filter rail + list; mobile:
  single column, map pinned to top — already roughly built).
- **Leaflet options per breakpoint at init** (zoom level, control visibility, `scrollWheelZoom`) is
  config, NOT reshape — that's allowed and expected.
- **Re-cut the existing `@media (max-width:760px)` layer to 640** to match. ⚠️ Caveat: the desktop
  directory layout (map+rail+list) may feel cramped in the **640–760 band** that now renders desktop —
  if so, tweak the *desktop* layout internally; the split line still stays **640**.
- Same no-flash loading: mobile directory CSS media-gated in `<head>`.

## Breakpoint
**640 everywhere** — Hub, profile, map. One mobile cutoff site-wide.

## Who needs to know
Buck (owns both layers, both surfaces), profile-app lane, perf-czar (new media-gated `<link>`s),
git-tsar (new mobile CSS files). Mirrors `hub-mobile-desktop-split.md` — same discipline, same breakpoint.
