# Proposal — move the profile Sections picker out of the privacy controls

## ✅ RULED AND BUILT — Ian chose **option A**, 2026-07-28

Built in `04113b2` on branch `profile-audit`. **Not yet merged to main, so not yet on
the serve.** This document is kept as the record of what was decided and why.

What shipped, exactly as specified in §4A below:
- The Sections opener is **gone from the `.lg-viewas` privacy panel** (both emit
  sites). That panel is now privacy only: View-as, Profile visibility, Discussion
  posts.
- **"Your layout" row** under the identity card, with a live section count and a
  `＋ Sections` button.
- **Dashed "＋ Add a section" card** at the end of the block list, rendered only when
  something is actually addable.
- **Desktop ≥1380 untouched** — the permanent sidebar still wins; both new affordances
  are `display:none` there.

Two things §5's must-not-break list actually caught during the build:
1. **The drawer would have become impossible to close.** The close button, backdrop,
   Escape handler and the `#caddy` re-open-after-mobile-add were all nested inside
   `if (toggle && caddy)`. Removing the toggle would have silently disabled every one
   of them. Now gated on `caddy` alone, with a `[data-caddy-open]` opener list.
2. **Layout-order safety.** `order()` reads the section order straight off the DOM via
   `.lg-block:not(.lg-block--header)` → `data-block`. Neither new element carries
   either attribute, so the saved order cannot be corrupted. Verified: 0 matches.

### VERIFIED — rendered markup, all four audiences (no engine, no serve)

`profile-app/bin/render-check.php` calls the renderer directly and inspects the HTML,
so the branch could be verified without the browser engine (scarcest thing on the box)
and without a serve window (not a lane's to take). Run per audience:

```
sudo -n -u profile-app php profile-app/bin/render-check.php 1849 <me|member|public|admin>
```

| audience | `lg-layoutrow` | `lg-addsec` | `data-caddy-open` | order u.php would read |
|---|---|---|---|---|
| **me** (owner) | 1 | 1 | **2** | `[about, location, gallery]` |
| member | 0 | 0 | 0 | `[about, location, gallery]` |
| public | 0 | 0 | 0 | `[about]` |
| admin (viewing) | 0 | 0 | 0 | `[about, location, gallery]` |

- **No leak.** The openers render for the owner only. Admin *viewing* correctly gets
  none; admin *editing* does get them, because `u.php` maps `admin_edit` → `role='me'`.
- **Order is intact and identical** across audiences (the `public` row is shorter
  because that fixture's location and gallery are members-only — a privacy result, not
  a layout one).
- **`non-block elements inside order()`: 0** in every case. This is the check that
  matters most and the one no screenshot would ever show: had either new element
  picked up `.lg-block` or a `data-block` attribute, it would have silently rewritten
  members' saved section order on their next save. A data defect, invisible to the eye.
- Removed markers confirmed gone: `lg-caddy-toggle` 0, `lg-viewas__caddy` 0.

**What this does NOT prove:** anything visual, anything about the 1380px breakpoint,
and that clicking an opener actually opens the drawer — those are behaviour and layout,
and they need the engine (`editor-rail-reachable-gate.sh`, rewritten in `deeefef`).

**Still open — the §7 ruling has NOT been given** (see below): should the openers
disappear in View-as preview? They currently inherit the old behaviour, because both
are gated on `$role === 'me'`, which is the same condition as `$editing`.

The gate that guards this change (`tools/gates/editor-rail-reachable-gate.sh`) was
rewritten in `deeefef` — it had rotted against a surface that moved. It asserts the
*property* (a visible opener exists below 1380) rather than a specific button id, and
**has not been run yet**: it is CDP-based and the box has no engine headroom.

---

**Original proposal, as put to Ian, preserved below.**
Mockup (dev-gated): `https://dev.loothgroup.com/footer-mockups/profile-sections/`
Evidence: `PROFILE-SYSTEM-AUDIT.md` §6. Lane: profile-audit, 2026-07-28, dev2.

> Ian: *"I don't currently like the way the section picker is deployed on mobile.
> It's a weird button in the privacy controls."*

Confirmed. This document says exactly what it is, why it is worse than "on mobile",
what to do instead, what that costs, and what it must not break.

---

## 1. What it is today

The section picker is the **caddy** (`<aside class="lg-caddy">`, `u.php:816`). Below
1380px it is a hidden off-canvas drawer whose **only** opener is:

```php
u.php:748
<button class="lg-viewas__caddy" id="lg-caddy-toggle" aria-label="Open sections menu">
```

That button is emitted **inside** `<div class="lg-viewas" role="group"
aria-label="Profile controls">` (`u.php:729`) — the panel whose other three rows are
View-as, **Profile visibility**, and **Discussion posts**. It is pushed to the far right
by `margin-left:auto` (`u.php:403`) and styled as an amber pill, the only warm-coloured
control in a black box of privacy settings.

**The only control that ADDS CONTENT to a profile lives in the PRIVACY panel.**

## 2. Four things wrong with it, in order of severity

1. **Wrong conceptual group.** "Sections" is layout. Its three neighbours are privacy.
   A member scanning the panel for privacy settings must ignore it; a member looking for
   layout has no reason to look there at all.
2. **It is not "on mobile" — it is below 1380px.** That is every phone, every tablet,
   **and most laptops** (`u.php:487`). A 1280×800 laptop gets the drawer and the button.
   Only ≥1380px gets the permanent sidebar the design intended. **The complaint is
   roughly 3× wider than it sounds.**
3. **It is unreachable after scrolling.** The opener is pinned to the top of the page.
   A member who has scrolled down through their blocks — precisely the moment they think
   "I want another section" — must scroll all the way back up.
4. **It is intermittent.** `$editing` goes false in View-as mode (`u.php:127`), so the
   button *vanishes* whenever a member previews as Public or Member. The one entry point
   is not merely misplaced, it disappears.

## 3. The number

**67 of 1,925 dev2 members have ever arranged their layout. 1,858 have not** — 96.5%
have never successfully used the picker (audit §3.7, measured in Postgres).

**This is a signal, not a proof.** Imported members may simply not have engaged, and
dev2 is not live. But it is consistent with all four problems above, and it is the only
usage evidence available. **Re-run on live before treating it as decisive.**

## 4. Options

### A — Recommended: own row, plus an end-of-list add card

Two changes, both in normal document flow, both `<1380px` only:

1. **"Your layout" row** directly under the identity card — label, section count, and a
   sage `＋ Sections` pill. Sections leaves the privacy panel entirely.
2. **Dashed "＋ Add a section" card** at the end of the block list, opening the same
   drawer.

**Why:** fixes the grouping error outright (1); the end-of-list card fixes reachability
(3) by putting the control where the task actually ends; and it matches the mental model
the drawer already states — *"Drag a section into your profile — or tap to add"*
(`u.php:821`) — because a dashed slot at the end of the list **is** the drop target,
drawn. No new floating layer, no collision with existing chrome.

### B — Alternative: sticky bar docked above the mobile tab bar

Best thumb reach, always visible while editing.

**Why not:** the bottom of a mobile screen is **already taken**. `bottom-nav.js` puts a
fixed 54px + safe-area tab bar on `/u/` with a raised centre Post button. A second
docked bar stacks on it and competes with that button. It also costs vertical height on
every screen, permanently, for a control most members use **once** — a member sets up
their layout and then mostly edits content.

### C — Do nothing

Legitimate. The picker works; this is discoverability, not breakage. If the guide is
about to explain it with a screenshot and an arrow, some of the cost is absorbed. **But
the guide would then be documenting around a known IA problem**, and the standing rule
means that documentation has to be maintained forever.

## 5. What either fix must NOT break

- **Desktop ≥1380px is untouched.** The permanent sidebar stays; `.lg-viewas__caddy`
  stays `display:none` there (`u.php:500`). Both proposals are `<1380px` only.
- **Drag-to-add must survive.** The caddy supports both tap-to-add and drag-to-position
  (`u.php` caddy JS: `addBlock(key, atIndex)`, plus the `dragstart`/`dragover` handlers).
  An end-of-list card is a natural drop target — but it must not swallow the existing
  drop-index logic that lets a member drop *between* blocks.
- **`#caddy` hash re-open must survive.** After a mobile add, the page reloads with
  `location.hash = 'caddy'` and re-opens the drawer. Any new opener must keep that.
- **The counter-managed extra galleries stay out of the palette.** `gallery-2`/`3` are
  `'caddy' => false` and deploy from the in-rail "Add gallery (N left)" control
  (`Block.php:69`, Ian 2026-07-24).
- **`aria-expanded` / `aria-controls` wiring** must move with the button, not be dropped.

## 6. Cost

**Small, and confined to two files.** No API change, no schema change, no new endpoint —
this is placement only; `me-layout.php` and the drawer itself are untouched.

- `profile-app/web/u.php` — move the toggle out of `.lg-viewas`; add the layout row and
  the end-of-list card; extend the caddy JS to bind the two new openers (the existing
  `openCaddy()`/`closeCaddy()` already do the work).
- CSS lives inline in `u.php` alongside the existing `.lg-viewas__caddy` / `.lg-caddy`
  rules.

**Gate:** `tools/gates/run-all.sh` before pushing, per the quality-gates rule — this is a
user-facing surface change.

**Risk:** low. The failure mode is cosmetic (a badly placed row), not functional, because
the drawer and its persistence are unchanged.

## 7. The question that needs answering either way

**Is it intended that the Sections button disappears in View-as mode?** (§2, item 4.)

It follows from `$editing` gating the whole editor, which is coherent — preview means
preview. But it means the single entry point to the picker is intermittent, and if A is
built, the new "Your layout" row would inherit exactly the same behaviour unless it is
deliberately given a different one. **Worth a ruling before any build, not after.**

---

## 8. Recommendation

**Build A.** It fixes the complaint Ian actually made, fixes a second problem he didn't
(reachability after scroll), costs two files and no API surface, and leaves the desktop
design intact. Draw the ruling on §7 first.

**Not building it. Awaiting Ian.**
