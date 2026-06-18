# Bootstrap — profile-2.0 chat

You own the **profile-2.0** lane: the block-model profile/practice system that
replaces the slice-0→3.5 profile-app surfaces. This is a multi-week arc. The
prior chat (`a847d1aa`) is **RETIRED** — do not resume it; its work is committed
and handed off. Start here.

## ⛔ Phase 0 ONLY this turn — MOCKUPS FIRST, no build

**Ian, 2026-05-29: design-confirm before building.** Same cadence the shim chat
used — mock the novel UX, surface it for coordinator/Ian reaction, iterate, and
only THEN proceed to Phase 1 (spine). **Do not write any profile-app code, schema,
or migration this turn.** Phase 0 output is HTML mockups + a reaction request.

Mock these, on the **block model**, as static gated HTML:
1. **The composer** — the sidebar-palette editor. This is the central novel
   interaction; nail it visually first: block-card palette, **Pro-locked blocks
   badged**, drag-to-canvas, settings-panel-on-select. (Phase 2 will crib
   lg-layout-v2's FE-editing *model* — palette/drag/inline-config/autosave/JSON
   round-trip — reimplemented standalone, NOT the WP code. Mock the *feel* now.)
2. **Profile page** on the block model — identity / location-with-pmp / craft /
   Connect / a storefront block.
3. **A typed practice page** — e.g. a repair shop (`practices.type`).

## Read first (in order)
- `docs/marching-orders-profile-2.0.md` — your build order (Phase 0→3). Phase 0
  is this turn; Phases 1–3 are the arc.
- `profile-app/SESSION-HANDOFF.md` — what the retired chat shipped. **The social +
  location backfills are DONE (commit `23fe81b`) — don't redo them.** Verify the
  schema adds, build on top.
- `docs/plan-profile-block-system.md` — the model + all locked decisions
  (relational spine + composable storefront blocks, block-level pmp, typed
  practices, tier-gating, JSON+LLM authoring).
- `docs/spec-block-identity-location.md` — the buildable pilot block contract
  (identity + location, pmp defaults locked).
- `docs/STRANGLER-COORDINATION.md` §0 (commit discipline), §2 (`/whoami`).

## Where mockups go
- **Write to:** `/var/www/dev/mockups/` (web docroot, cookie-gated).
- **View at:** `https://dev.loothgroup.com/mockups/<file>.html` (behind the dev
  cookie gate — already authorized in a normal browser session).
- **Reuse the shared shell + tokens:** `/srv/lg-shared/site-header.php` +
  `site-header.css` (+ `site-footer.php`). Match the live chrome; don't invent a
  new visual language.
- **Prior profile mockups to build from / supersede:** `profile-page.html`,
  `profile-v2.html`, `profile-directory.html` already in that dir. Coordinator
  notes earlier directory + profile-page mockups exist — start from them.

## Locked invariants (carry forward — don't regress)
- **No per-row visibility column** on `profile_socials` — block-level pmp won the
  design battle. Settled. Don't reintroduce a per-row vis.
- **pmp = public / member / private**, block-level.
- **Location two-tier specificity:** approximate (public|member) / exact
  (member|private|on_request), coarse-coord geo.
- **`tier_badge` is never user-authored / never LLM-drafted.**
- Spine (Phase 1) is the **migration target** — must be dev-FINAL before the
  data crib runs. One migration into the final model, not two. (Phase 0 doesn't
  touch this; just know the spine is downstream and load-bearing.)

## Repo + §0
Everything's in the **looth-platform** repo (`/home/ubuntu/projects`).
profile-app source at `profile-app/`. Edit in the repo, **commit at end of each
change set + push**, deploy to target. Don't hand-edit deployed copies. (Mockups
under `/var/www/dev/mockups/` are throwaway design artifacts — committing them is
optional, but the eventual block code lives in the repo.)

## Coordination
- **profile-app/ is shared with the shim-replacement chat** (`d9380b73`). It adds
  a mint endpoint + touches `Whoami.php` / `config.php`. **Flag the coordinator
  before editing `Whoami.php` or `config.php`.** Low collision (mostly new code),
  but coordinate.
- Anything cross-lane / contract-shaped routes **through the coordinator**, not
  lane-to-lane.

## When you spawn
Capture your session ID + outliner title, report to coordinator for roster +
lineage. (Coordinator is spawning you with session ID
`1c98b564-ae29-4bc2-af2d-b06f80498aa4`.)

## Report-back format
```
**profile-2.0 → coordinator:** <one-line status>
<mockup URLs + path to any handoff / changed files>
```

— coordinator
