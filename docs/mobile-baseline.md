# Mobile Baseline — Looth Phone Viewport Conventions

**Owner:** Mobile Czar · **Last updated:** 2026-06-05

## Canonical breakpoints

| Breakpoint | Width | Use |
|---|---|---|
| Phone small | ≤320px | Samsung Galaxy A series, older iPhones |
| Phone mid | ≤390px | iPhone 14/15 Pro (primary test target) |
| Phone wide | ≤480px | Large Android phones |
| Tablet/phablet | ≤640px | Shared-chrome collapse point |
| Narrow desktop | ≤820px | Nav hamburger activates |

## Overflow testing method (CDP)

```js
// Correct check — innerWidth expands when there's overflow, clientWidth doesn't
const overflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;
// overflow > 2 = bug
```

## Shared chrome files

| File | Owner | Git |
|---|---|---|
| `/srv/lg-shared/site-header.css` | lg-shell / coordinator | Not git-tracked |
| `/srv/lg-shared/site-header.php` | lg-shell / coordinator | Not git-tracked |
| `/var/www/dev/app-mobile-fixes.js` | Buck (JS stop-gap) | wp-content? |

## Landed fixes

### Footer horizontal scroll — 2026-06-05
**Bug:** `.lg-chrome-foot__inner` had `grid-template-columns: 320px 1fr` with no mobile breakpoint.
At ≤640px viewports the footer was wider than the viewport → horizontal scroll on every page.

**Fix:** Appended to `/srv/lg-shared/site-header.css` after line 353:
```css
@media (max-width: 640px) {
  .lg-chrome-foot__inner { grid-template-columns: 1fr; gap: 28px; padding: 32px 18px 24px; }
  .lg-chrome-foot__brand { max-width: 100%; }
  .lg-chrome-foot__cols  { grid-template-columns: repeat(2, 1fr); gap: 18px 20px; }
  .lg-chrome-foot__legal { flex-direction: column; align-items: flex-start; gap: 8px; }
  .lg-chrome-foot        { margin-top: 36px; }
}
@media (max-width: 380px) {
  .lg-chrome-foot__cols  { grid-template-columns: 1fr; }
}
```

**Verified:** Footer renders as single stacked column at 390px, 360px, 320px on hub/events/members/archive.
JS stop-gap (`app-mobile-fixes.js`) now a harmless duplicate — can be retired.

**Screenshots:**
- Before: `https://dev.loothgroup.com/mockups/hub-footer-390px-before.png`
- After: `https://dev.loothgroup.com/mockups/events-footer-390px-FIXED.png`

## Open tickets (routed to lanes)

### HUB-M01 — Feed cards 715px wide on 390px viewport
- **Lane:** hub/bb-mirror
- **Overflow:** 337px (documents scrollWidth=727 vs clientWidth=390)
- **Elements:** `.feed-card` (width=715), `.feed-card__header-body`, `.feed-card__title`
- **Fix:** Add `max-width: 100%; box-sizing: border-box;` to feed card layout, or ensure the feed container is width-constrained
- **Screenshot:** `https://dev.loothgroup.com/mockups/hub-390px-before.png`

### HUB-M02 — lg-chrome__aside header overflow (shared chrome)
- **Lane:** lg-shell / coordinator
- **Overflow:** 87–123px across hub/events/members at 390px
- **Elements:** `.lg-chrome__aside` (width=395 in 390px viewport), `.lg-chrome__account-wrap` (right=513)
- **Root cause:** Aside contains 6 items totalling 395px (search 38 + edit-pill 73 + 3×icon-btn 114 + account-wrap 130 + 5×gap 40). Available space in header at 390px ≈ 248px.
- **Proposed CSS** (needs coordinator to land in `site-header.css`):
  ```css
  @media (max-width: 640px) {
    .lg-chrome__edit { display: none; }   /* admin edit pill — hide on mobile */
  }
  @media (max-width: 480px) {
    /* Hide lowest-priority icon button(s) to fit; coordinator to decide which */
  }
  ```
  Hiding `.lg-chrome__edit` alone saves 81px → aside=314px, still 66px over. Need to also collapse or hide 2 of the 3 icon-btns to fit cleanly at 390px.
- **Decision needed:** Which icon buttons to hide vs move into hamburger menu.

### EVENTS-M01 — Leaflet map tile overflow on /directory/members/
- **Lane:** profile-app
- **Overflow:** Leaflet tile at right=572 in 390px viewport (182px overflow)
- **Fix:** Constrain the Leaflet map container: `max-width: 100%; overflow: hidden;` on `.leaflet-container`

### EVENTS-M02 — Event card banner text contrast
- **Lane:** events
- **Issue:** White title text over busy event photo backgrounds on `/events/` cards — low contrast
- **Fix:** Add `background: linear-gradient(to top, rgba(0,0,0,0.55) 60%, transparent)` scrim behind title text
- **Source:** Buck's initial audit
