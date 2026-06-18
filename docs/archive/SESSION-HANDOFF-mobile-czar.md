# Session Handoff — Mobile Czar · 2026-06-05

## DONE

### Footer horizontal-scroll — LANDED ✅
- **File changed:** `/srv/lg-shared/site-header.css` (appended ~15 lines after line 353)
- **Change:** `@media (max-width: 640px)` + `@media (max-width: 380px)` footer stacking rules
- **Verified:** Footer renders 1-column at 390/360/320px on hub, events, members, archive
- **JS stop-gap** (`app-mobile-fixes.js`) is now a duplicate — still safe, can be retired
- **Note:** `/srv/lg-shared` is NOT git-tracked — change is live on filesystem only

### Overflow audit complete — all surfaces at 390px ✅
| Surface | Doc overflow | Footer | Source of overflow |
|---|---|---|---|
| /hub/ | 🔴 +337px | ✅ fixed | feed-card 715px wide (hub-specific) + aside 87px (shared) |
| /events/ | 🔴 +131px | ✅ fixed | lg-chrome__aside 123px (shared) + leaflet map 182px |
| /directory/members/ | 🔴 +123px | ✅ fixed | lg-chrome__aside 123px (shared) + leaflet map 182px |
| /archive-poc/ | ✅ | ✅ | Clean |

## ROUTED (tickets in docs/mobile-baseline.md)

- **HUB-M01** → hub/bb-mirror lane: `.feed-card` width=715px in 390px viewport (337px overflow)
- **HUB-M02** → lg-shell/coordinator: `.lg-chrome__aside` 395px wide in 390px header. Needs decision on which icon buttons to collapse/hide at ≤640px.
- **EVENTS-M01** → profile-app lane: Leaflet map tile overflow on /directory/members/
- **EVENTS-M02** → events lane: Event card banner text contrast (white on busy photo)

## BLOCKED / OPEN DECISIONS

- **Header aside fix (HUB-M02):** Cannot determine which icon buttons to hide without UX call. Edit pill (73px) safe to hide on mobile. Two more icon buttons need collapsing to fully fit. Needs coordinator/Ian decision on which to remove vs move into hamburger.
- **JS stop-gap retirement:** `app-mobile-fixes.js` can be cleaned up once footer CSS lands on live. Buck owns that file.

## Screenshots
All in https://dev.loothgroup.com/mockups/
- `hub-390px-before.png` — hub feed card overflow (before)
- `hub-390px-after.png` — hub after footer fix (feed card still overflows — hub lane ticket)
- `hub-footer-390px-before.png` — footer before fix
- `hub-footer-390px-after.png` — footer after fix (1-column)
- `events-390px-top.png` — events page overflow
- `events-footer-390px-FIXED.png` — events footer fixed
- `footer-390px-after.png`, `footer-360px-after.png`, `footer-320px-after.png` — 3-width proof

## Next session
1. Land HUB-M02 header aside fix (coordinator gate)
2. Re-verify hub after bb-mirror lane fixes feed cards
3. Sweep /events/ at 360/320px
4. Sweep profile-app /u/ and /p/ pages
