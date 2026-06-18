# Post-launch list — tabled by Ian's call (started 2026-06-11)

Good work, wrong week. Nothing here ships before the cut. Each entry names the
owner-of-record and where the work already lives, so nothing gets rebuilt.

## Tabled features (built, dormant or mobile-only)
- **Messenger sheet** (Buck) — FB-Messenger-style chats over the canonical
  /me/messages API. `/var/www/dev/messenger-sheet.js` v1, mobile-only, NOT in
  the fork mirror yet. Decide track-or-park at pickup.
- **Shop surfaces** (Buck) — desktop Loothtool modal (shop-bubble, dormant
  behind the FAB guard, ships zero CSS/fonts as of v20) + the /shop/ page
  reskins + vendor-logo mirror. The header Loothtool link does a plain nav
  meanwhile. Re-enable = delete the one guard line in shop-bubble buildUI.
- **Follow / Following feed** — server side ALREADY ENABLED: bp activity-follow
  active, wp_bp_follow has 9,307 imported edges; zero app surfacing. v1 needs a
  small follow endpoint (coord lane) + buttons (Buck lane). Buck's own rec was
  post-launch; Ian agreed 6/11.
- **5-col ultrawide tuning beyond the canonical ladder** — canonical does
  3/4(≥2400)/5(≥3200) full-width per Ian's expand-to-banner call; any further
  ultrawide refinement waits.

## Standing post-launch items (from earlier calls, gathered here)
- lg-layout-v2 dark overhaul (charter post-launch list; insulation patch interim).
- Overlay absorb completion (theme/chrome block → canonical; flash-fix C-items).
- Server-side theme cookie (kills dark first-paint flash structurally) — option B
  from the 6/11 flash discussion.
- Header nav ~83px font-race shift (filed w/ evidence in docs/SESSION-HANDOFF.md).
- Buck asks parked 6/10: push VAPID/sender config, footer canonical CSS.
- /directory → directory-mockup-v1 redirect decision.
- Profile 2.0 Phase 1 spine (mockups awaiting Ian reaction).
