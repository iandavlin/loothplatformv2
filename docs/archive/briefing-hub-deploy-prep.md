# Briefing — Hub deploy-prep (2026-06-06)

Goal: get the **Hub** (the front door, `/hub/`, bb-mirror) **deploy-ready** — looking right on first
paint, no flash, no shadow-layer debt baked into production. The polish Buck shipped is good; it's in
the wrong *delivery mechanism*. This lane moves the **look** into canonical and stages the behaviors.

Read first: `docs/hub-up-to-speed.md` (the two-layer trap) and this file.

## The architecture target (the plan)
> **Canonical responsive CSS paints the Hub content (both viewports, first paint). PWA JS only adds
> app-shell chrome (bottom nav, shop, push). JS never paints the content.**

Today it's backwards: canonical paints, then `/var/www/dev/hub-polish.js` (loaded via `/pwa.js` on
`window.load`) repaints the mobile look → the **flash** + a re-clobbering shadow layer. We fix the
delivery, not the design.

## Scope of `hub-polish.js` (1,558 lines — captured at `live-webroot-capture/2026-06-06/` + `f378da3`)
- **~400 lines CSS** (hero, app-card shell, cover caps, kind-badge pills, control-rail pills, mobile
  declutter, and the **grid-overflow fix**: `minmax(0,1fr)` tracks + `min-width:0` + ellipsis).
- **~1,100 lines behavior**: an FB-style inline-reply system (`fbStyleReply`/`openReplyBox`/
  `submitReply`/optimistic append), fast-filters (optimistic mute + debounced server reconcile),
  top-search typeahead, text-size toggle, share/copy, preview-reply truncation, mutation observers.
- App-shell (`bottom-nav.js`, `shop-bubble.js`) + reactions stub (`wireReactions`) are SEPARATE — not
  this lane's job (see Boundaries).

## STEP 1 — deploy-critical: absorb the CSS into `forums.css` (do this first)
This is what makes the Hub deploy-ready. Mechanical, low-risk.
1. Lift the CSS strings from `hub-polish.js` (`onHubPath()` ~L46–451 + `injectFont()` Cabin loader)
   into `bb-mirror/web/forums.css` as `@media (max-width:640px)` rules (and the all-width rail-pill
   rules where noted). Keep the grid-overflow fix — it's a real bug fix, not just polish.
2. Source-order: these are mobile rules; ensure they sit so they win without `!important` (same as the
   JS injection did by appending after forums.css).
3. **Retire the CSS half of `hub-polish.js`** — once `forums.css` has the rules, delete the
   `injectStyles()` style block (leave the behaviors for now). Bump the `/pwa.js` hub-polish ref.
4. **Verify:** Hub on a phone (390/360/320) paints the polished look on FIRST load — **no flash**,
   no canonical-then-repaint. Desktop **unchanged**. `document.scrollWidth == innerWidth` (overflow
   still fixed). Use the `chrome-dev-login` skill; screenshot to `/var/www/dev/mockups/`.

## STEP 2 — stage the behavior layer (post-deploy stream, not a cut blocker)
- Stop loading the behavior JS on `window.load` — move to `DOMContentLoaded` (or load earlier) so it's
  not janky. It can keep living as a recognized enhancement file for now.
- Then absorb behaviors into canonical `bb-mirror/web/forums.js` one feature at a time: reply system →
  reconcile with the existing action-row (Buck's reply fix already got clobbered once — fold it so it
  sticks), fast-filters, top-search, text-toggle, share. Each lands + verifies independently.
- Retire `hub-polish.js` only when both CSS and behaviors are absorbed. Until then it's the live
  source for the un-absorbed behaviors — don't delete it wholesale.

## Boundaries (don't collide)
- **App-shell** (`bottom-nav.js`, `shop-bubble.js`, push) stays a PWA JS layer — NOT absorbed here.
  Shop is unscoped/awaiting Ian; don't build on it.
- **Reactions** → the comments+reactions lane (uses the approved BuddyBoss palette, persists). Buck's
  `wireReactions` emoji stub is a placeholder; don't canonicalize it.
- **Shared chrome** (header/footer responsive) → lg-shell / mobile-czar, not here.
- Coordinate with the **mobile-czar** (owns the pwa.js→canonical convergence) — this lane is the Hub
  slice of that.

## Safety
Everything is backed up twice (`f378da3` in the repo + `/home/buck/webroot-backup/2026-06-06/`), so
reverting any absorbed piece is painless. Commit in clean, tested increments (CSS absorption, then each
behavior). Commit ≠ push — Ian + git-tsar gate the push.

## Deploy-readiness checklist (Hub)
- [ ] CSS absorbed → `forums.css`; flash gone on first paint; desktop unchanged; overflow fixed.
- [ ] Behavior JS loaded cleanly (not `window.load`); no console errors.
- [ ] Gating still server-side (below-tier viewer sees right subset).
- [ ] Category filter + accordion working on the unified feed (taxonomy labels live).
- [ ] No horizontal scroll at 390/360/320.

## Report back (to coordinator)
`DONE · FILES · VERIFIED (first-paint no-flash + widths + desktop-unchanged + gating) · STAGED (which
behaviors remain in hub-polish.js) · BLOCKED`.
