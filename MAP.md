# MAP.md — Members-Map lane boot doc

Boot doc for the Map/Profile lane (like the hub's HUB.md). Read this first.
Dev box = this machine (50.19.198.38, ubuntu). Act locally, never SSH to "dev".

## Scope
Members MAP (pull pins → render) + the profile location/privacy that feeds it (push).
Work mostly in the MAIN tree (`profile-app`) + the buck-owned OVERLAY. NOT the
bespoke-cutover hub worktree. Announce before editing shared profile-app files;
cross-lane contract changes route through Ian.

## Surfaces (located 2026-06-15)

### Server — DATA FEED (main tree `/home/ubuntu/projects/profile-app`, served via `/srv/profile-app`)
- `api/v0/directory-members.php` — the finder feed. List (paginated cards) + pin
  feed (`?pins=1`). Privacy/precision centralized in `Visibility::locationPrecision`
  + `Block::locationDisplay`; a pin never leaks more than the card. Trilateration
  guard: radius/distance computed on the COARSENED point. Anon = public audience
  (luthier finder, Ian 6/12), no uuid in payload, no contact PII (scrape-proof).
- `api/v0/directory-pins-public.php` — anon Strava-heatmap aggregate: grid cells
  `[lat,lng,count]` rounded to 1 decimal, no identity. Population = same set anon
  sees as teaser dots. 15-min public cache.
- `web/directory-members.php` — canonical SSR page + Leaflet map init. Initial
  `setView([39,-98],3)` then **`fitBounds(pts)` to all pins** once loaded (line ~611).
  This IS the fit-to-all behavior Ian wants — the overlays currently override it.

Routes: callers use `/profile-api/v0/directory/members` (+`?pins=1`) and
`/directory/pins-public`. Dead `/wp-json/looth/v1/members-geo` re-point is DONE —
only a code comment in fp-map.js still names it. ✔ verified anon via curl:
pins-public→658 cells, members?pins=1→real pins + gated dots, list→visible profiles.

### Client — OVERLAY (out-of-git live copy `/var/www/dev/`, buck-owned, hot-deployed via `/pwa.js`)
- `directory-desktop.js` (≥641) — two-pane map+results, Leaflet. Hover card↔pin
  sync; **pin hover already opens a full card-clone popup** (`lgdd-cardpop`).
- `directory-mobile.js` (≤640) — map-first, draggable peek/half/full sheet,
  two-stage member tap. Pin tap opens canonical popup (NOT upgraded to full card).
- `privacy-sheet.js` — owner edit surface; per-section visibility sliders (the
  slider that gates each pin). Reuses existing PATCH endpoints; server stays gate.
- `/pwa.js` injects `directory-mobile.js?v=12` / `directory-desktop.js?v=11`.

### Split-brain status (IMPORTANT)
The 3 overlay files ARE mirrored into git at `projects/webroot/` (commit f2df500)
and are currently **byte-identical** to live. BUT there is no symlink — git is a
snapshot, live is buck-owned & hot-deployed, so they CAN drift. Before any overlay
edit: re-diff `/var/www/dev/X` vs `projects/webroot/X`; edit live + re-capture to
webroot in the same change. A stale fork also exists at
`worktrees/bespoke-cutover/hub-overlay-flag/` (~drift) — NEVER cp it over live.

### Front-page map (DO NOT EDIT — archive-poc / dev2-cut lane)
- `archive-poc/web/fp-map.js` — front-page map + Bento "You-pin". Reads
  `/directory/pins-public` + `/directory/members`. Scope/flag only; coordinator
  clearance required.

## Governing rulings (enforce)
- members-geo (6/11): RESTORE the map; EACH pin follows that member's own slider
  decision. No blanket coarse-ing, no blanket pull.
- Parity gate: no user-facing control ships on one surface without its counterpart
  in the same change; privacy UI converges on the slider panel.
- profile-app holds identity/location (keyed on user_uuid), NOT WP/BuddyBoss meta.

## Ian 6/15 hard map-behavior requirements
1. FULL MAP on load — fit-to-all-pins / world extent. NO IP/geolocation, no
   auto-center on viewer; viewer's pin is just another pin.
2. PIN HOVER → FULL CARD (same as sidebar card), not a bare tooltip. Desktop owns
   hover; mobile must define a tap equivalent.

### Gap analysis vs requirements
- (1) No geolocation anywhere ✔. But BOTH overlays deliberately override the
  canonical `fitBounds(pts)`: desktop `centerDefault()` → `setView([39.5,-98.5],4)`
  (US center); mobile `centerOnDefault()` → `setView(DAN 39.3,-82.1, z8)` (centers
  on member "Dan"). Fix = neuter both overrides so canonical world-fit stands.
  ⚠ COUPLING: overrides exist because world-fit → list query (500mi radius cap) is
  empty at world zoom. Removing them surfaces empty-list-at-world-extent → needs an
  Ian decision (show all / nearest-N / "zoom in to see list").
- (2) BROKEN on BOTH surfaces — ROOT CAUSE VERIFIED LIVE (chrome-dev, uncontested,
  6/15). The bare popup is NOT a list-dependency; it fails for in-list members too
  (iandavlin's card is in `#dir-results`, still bare). Cause = overlay↔canonical
  popup-contract drift:
  • Canonical `plotPins` (web/directory-members.php:582-584) builds each pin popup
    as a FREE-FLOATING `L.popup` (content set inline = name+location stub), opened
    via `popup.setLatLng().openOn(dirMap)` — deliberately NOT `m.bindPopup`
    (comment L562; the manual model + closeOnClick:false protects the click-through
    nav gate at L585-586).
  • Overlay `directory-desktop.js` assumes marker-BOUND popups: `hoverOpenPopup`
    (L327) calls `marker.openPopup()` → NO-OP (verified `markerHasBoundPopup:false`);
    `popupopen` clone (L580) keys on `e.popup._source.__lgddSlug` → a popup opened
    via `openOn(map)` has no `_source` (verified `hasSource:false`) → handler hits
    `if(!slug) return`, never clones. So the overlay's entire rich-popup mechanism
    is dead code against the current canonical popup model.
  The canonical page was refactored to manual `L.popup` (to fix click-through nav),
  silently severing the overlay hook — textbook split-brain.

  FIX APPROVED (Ian 6/15, coord-endorsed): (a) build the full card IN canonical
  `plotPins` via a shared `dirCardHTML(it)` extracted from `renderResults`, so
  desktop-hover + mobile-tap inherit from ONE markup source (kills the split-brain,
  satisfies parity). Pin feed is slim, so lazy-fetch the member's full card by slug
  on hover/tap, cache it, swap popup content. Change set: docs/MAP-POPUP-CHANGESET.md.

## Verify protocol
curl feeds (gate cookie `loothdev_auth=<$loothdev_token>`); chrome-dev-login skill
for real map render/hover. Run `tools/gates/run-all.sh` before any push. Commit
small by pathspec; NEVER push without Ian's review (commits + diffstat).
