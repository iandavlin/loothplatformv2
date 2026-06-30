# Mobile / Desktop split — STANDING ARCHITECTURE (do not re-litigate)

Mobile and desktop are DELIBERATELY separate code surfaces across the Hub and the
notification system. They were split because shared mobile+desktop code kept
CLOBBERING each other (a change for one breakpoint silently broke the other).
**Never merge them back into one file/component.**

## Where the split lives
- **Hub CSS:** `forums.css` (>=641 desktop) vs `mobile-hub.css` (<=640 mobile). Never merge.
- **Hub composer (posting):** desktop = NTM `#ntm-form`; mobile = Buck's `fbStyleComposer`.
- **Notifications:** desktop bell = `lg-shared/social-modals.js` (clickthrough marks ONE read,
  Clear-all, opens the Hub MODAL). Mobile = `webroot/bottom-nav.js` sheet (unread-only,
  auto-mark-on-view, watermark Clear). **Different read models BY DESIGN.**
- **Topic deep-links:** `/hub/?topic=<forum>%2F<topic>` opens the discussion MODAL on
  **desktop** (>=641, `forums.js` §4f); on **mobile** it REDIRECTS to the standalone
  `/hub/<forum>/<topic>/` permalink. Both intended.

## Consequences (read before touching either surface)
1. A feature spanning both surfaces = **TWO lanes** (mobile + desktop), or one lane that edits
   each surface's own file and never unifies them. **Mobile is the JS-dept surface.**
2. Desktop fixes do NOT reach mobile automatically (and vice-versa) — different code.
3. Test BOTH viewports. "Works on desktop" != "works on mobile." Most "it's broken" reports
   here are actually "tested the other surface."
4. Separate problem, do not confuse: the DEAD twin tree `lg-shell/lg-shared/` duplicates
   `lg-shared/` (#twin-cleanup) — that is NOT the mobile/desktop split.

_Keeper-owned. Added 2026-06-30 after repeated re-discovery in chat (notif modal + mobile auto-clear)._
