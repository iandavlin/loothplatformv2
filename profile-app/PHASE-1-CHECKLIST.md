# Phase 1 — spine build checklist (profile-2.0)

Plan: `docs/plan-profile-2.0-phase1-build.md`. The spine is the migration target —
**steps 1–8 must be dev-FINAL before step 9 (the crib) runs.** Surface each step
for reaction. Nothing here is executed yet (scaffold turn).

## Decisions — RESOLVED dev-final (plan-profile-block-system.md "Schema — RESOLVED")
- [x] **A.** NO approx-coord column — coarse "near me" comes from the city/state
      centroid the geocoder already returns; exact `lat/lng` stays the gated pin.
- [x] **B.** No enum tighten — approximate-vis clamp is app-layer (Block).
- [x] **C.** Header vis = the profile's OWN vis = section cap, on `profile_sections`
      key='header' row. NO column.
- [x] **Vocab.** `members` DB literal kept; one normalize point `Block::normalizeVis`.
- [ ] **Header default** (member vs public) — still Ian's open knob; NON-BLOCKING.

## Schema (review → apply on dev) — increment 1
- [x] `sql/2026-05-30-block-system-spine.sql` finalized to the resolved schema
      (3 adds; members literal; no approx col; idempotent). **NOT applied — coordinator runs it.**
- [x] Adds: `users.at_a_glance`, `users.location_exact_visibility` (default private),
      `practices.type` (+ CHECK). (avatar_version deferred to the avatar-edit increment.)
- [ ] Apply on dev; `\d users` / `\d practices` verify (test plan §0–1).

## Pilot block — profile-header (identity), increment 1 — DONE (write-only)
- [x] `src/Block.php` — block sets, normalize point, `effectiveVisibility`,
      `headerCeiling`, `gateDecision`, `canSee`, `isCappedByHeader`, `loadHeader`, `saveHeader`.
- [x] `web/_render_blocks.php` — header-as-ceiling gate + profile-header card + members-gate.
- [x] `api/v0/me-header.php` — GET assembled header; PATCH at_a_glance + ceiling vis
      (+ WP `description` mirror, whoami purge). `members`→`member` normalize wired.
- [x] `at_a_glance` added to `Profile::loadFull` read shape.
- [x] Test plan: `PHASE-1-INCREMENT-1-TEST.md`.
- [ ] **Coordinator: apply schema + run the test plan.**

## Pilot block — location (two-tier, user-managed pin), increment 2 — DONE (write-only)
- [x] `src/Block.php` — `loadLocation` (two tiers from spine), `coarsen` (no approx
      column — round stored pin), `EXACT_VIS_VALUES`/`PRECISION_VALUES`,
      `exactVisFromInput`, `visRank` fail-closed (on_request → private).
- [x] `api/v0/me-location.php` — built ON the existing endpoint: + GET (assembled
      block), + `location_exact_visibility`, + `precision`, + user-managed `pin`
      placement (conflict-guarded), PUT returns the re-assembled block.
- [x] `web/_render_blocks.php` — `looth_render_location_block` (ceiling-capped per
      tier; exact hidden to non-permitted; coarse approx dot; precision-aware pin).
- [x] `src/Profile.php` — address/exact_visibility/pin_precision in `loadFull`.
- [x] New idempotent schema: `sql/2026-05-30-location-pin-precision.sql` (NOT applied).
- [x] Test plan: `PHASE-1-INCREMENT-2-TEST.md` (HTTP authed pass noted BLOCKED on shim mint).
- [ ] **Coordinator: apply precision schema + run the test plan.**

## Spine blocks — craft + socials/links, increment 3 — DONE (write-only)
- [x] `src/Block.php` — `loadCraft` (instruments+skills+highlights via loadFull),
      `loadSocials` (website + links), generic `blockVisibility`/`saveBlockVisibility`,
      `CRAFT_KEY`/`SOCIALS_KEY`. No new schema (vis on profile_sections key).
- [x] `api/v0/me-craft.php` — NEW: GET assembled craft / PATCH visibility.
- [x] `api/v0/me-socials.php` — extended: +GET assembled block, +`visibility` in PUT
      (items path preserved, now optional).
- [x] `web/_render_blocks.php` — `looth_render_craft_block` + `looth_render_socials_block`,
      ceiling-capped, wired after location.
- [x] Test plan `PHASE-1-INCREMENT-3-TEST.md` (incl. inc1/inc2/inc3 HTTP curls, now
      unblocked) + runnable `PHASE-1-HTTP-TESTS.sh` (mints token, hits every /me block).
- [ ] **Coordinator: add nginx route + allowlist for `me-craft` (test §4); run HTTP tests.**
- [ ] **Ruling needed:** socials render in BOTH the inc-1 header (inline row) and the new
      socials block — keep both, or drop the header's inline row? (didn't touch header.)

## /u/<slug> block render + View-as — DONE (write-only)
- [x] `web/u.php` rewired to `looth_render_profile_blocks()` (block model), replacing
      the slice-3.5 `Profile::renderForViewer`/`looth_render_public` path. Shared chrome
      (`_chrome.php`) + footer kept; block CSS inlined (no new nginx route).
- [x] **View-as (owner only): Public / Member / Me** — `?view=` drives the SAME
      `looth_render_profile_blocks` with the effective role (no forked render). Non-owners
      never see it. Owner→`/profile/edit` bounce REMOVED so the owner can view+preview.
- [x] Header default = member wired via `Block::gateDecision` (logged-out → members-gate;
      public blocks peek through a public header). Avatar single-source + initials fallback
      (in the header renderer).
- [ ] **Coordinator: browser-check `/u/profileapp-test`** — the 3 View-as states + the
      logged-out members-gate (see report test steps).
- [ ] **FLAG:** subject **tier badge** passed `null` (the subject's tier isn't on the spine
      post tier-drop — needs a membership-tier lookup). Header renders no badge for now.

## Inline pmp control (per-block privacy editing) — DONE (write-only)
- [x] `_render_blocks.php` — `looth_pmp_control()` turns the read-only vis chips into
      `<button.lg-pmp>` carrying `data-pmp-block / -vis / -ceiling`. Wired at all 5
      sites: header, craft, socials, location-approx, location-exact. Owner/Me only
      (rendered inside the existing `$isOwner` branches).
- [x] `web/u.php` — pmp menu JS + CSS. Click a chip → menu of tiers → persists via
      the existing endpoints (header/craft PATCH `visibility`; socials PUT `visibility`;
      location PUT `location_visibility` / `location_exact_visibility`); on success
      `location.reload()` so the server re-derives ceilings + the gate (View-as honest).
- [x] **Header ceiling surfaced:** options more-public than the header are marked
      "limited by header" in the menu; a capped chip shows ⚠ + a tooltip
      (`Block::effectiveVisibility` / `isCappedByHeader` server-side). Block CAN still
      store the more-open value (plan's allow-but-cap model) — the gate enforces effective.
- [x] Vocab: JS sends DB-literal values (`public/members/private/on_request`) — the set
      every endpoint accepts (location-approx's validator wants plural `members`).
- [ ] **Coordinator: CDP as owner 1918** — click each block's chip, change vis, confirm
      persist + reload + View-as reflects it; header-ceiling edge cases below.

## practice-header block + /p/ page — DONE (write-only)
- [x] `src/Block.php` — `loadPracticeHeader` (name/type/tagline/website + owner avatar +
      owner city/region), `savePracticeHeader`, `practiceHeaderCeiling`, `practiceOwnerId`,
      `isPracticeOwner`. **No new schema:** practice header vis stored namespaced in the
      OWNER's `profile_sections` (`key='practice-header:<id>'`) — a `practice_sections`
      table is the clean future home (flagged).
- [x] `api/v0/me-practice-header.php` — GET assembled / PATCH vis; owner-only (403 else);
      resolves `?practice=<id>` or `?slug=`. **Needs nginx route (mirror me-craft).**
- [x] `web/_render_blocks.php` — `looth_render_practice_blocks()` + `looth_render_practice_header_block()`
      + `looth_render_practice_gate()`; practice-header pmp control (owner).
- [x] `web/p.php` — rewired to the block render: shared chrome, View-as (owner), block loop,
      practice-header pmp menu JS. Replaces the slice-3 `_render_practice` path.
- [ ] **Coordinator: add nginx route + allowlist for `me-practice-header`; browser-test `/p/<slug>`**
      (needs a practice owned by the test user — see test steps).

## Leaflet maps + header socials
- [x] **Leaflet fix in `web/u.php`** — enqueue Leaflet 1.9.4 from unpkg CDN (standalone shell
      has no WP head); location block `.lg-loc__map` (coarse circle) / `.lg-loc__pin` (exact
      marker) now render real OSM tiles from the managed coords. Replaced the grey-grid placeholder.
- [x] **Socials dropped from profile-header** — VERIFIED already identity-only (avatar/name/
      glance only; socials live solely in the dedicated Links block). No change needed.

## connect block — DONE + TESTED GREEN ON DEV (hands-on, 2026-05-31)
- [x] `src/Block.php` — `loadConnect($userId, $viewerUserId)` built ON the social-layer
      `Connections` backend (count + preview avatars + mutuals-for-visitor + owner pending
      in/out); `CONNECT_KEY`; block-level pmp via `saveBlockVisibility`. No new schema.
- [x] `api/v0/me-connect.php` — GET assembled / PATCH visibility (owner). Routed.
- [x] `web/_render_blocks.php` — `looth_render_connect_block` (ceiling-capped; owner pmp chip
      + pending hint; empty-state). Wired into `looth_render_profile_blocks` (viewer id threaded).
- [x] `web/u.php` — passes viewer id; connect CSS; `connect` in the pmp endpoint map.
- [x] **nginx (hands-on):** `/me/connect` rewrite + `me-connect` in the authed-/me allowlist; reloaded.
- [x] **Verified green:** GET 200 (count:1 "Ian B Davlin", pending_in/out:1); PATCH public/member
      200 round-trip; bad value 400; `/u/profileapp-test` renders `lg-block--connect`. `PHASE-1-CONNECT-TEST.md`.
- [ ] **Division flag for coordinator:** the connect *actions* (Connect/Message buttons) stay in
      the header slot (`Social::renderProfileActions`); this block is the list/count surface. Keep split?

## Spine blocks — ALL CORE BLOCKS DONE → next
- [x] header · location · craft · socials · **connect** — the full `/u/` profile block set.
- [ ] **Spine sign-off** (coordinator) → then practice storefront blocks (hours/services/staff).
- [ ] Match the mockups: `/var/www/dev/mockups/profile-block.html`, `practice-repair.html`.

## Remaining spine blocks (additive)
- [ ] craft (catalogs), socials, practices-card (`/u/`), staff/bench (`/p/`).

## Avatar single-source + media store — DONE (write-only)
- [x] Store layout `<LG_AVATAR_STORE>/<uuid>/<version>.<ext>` =
      `/srv/profile-app-media/avatars/<uuid>/<v>.<ext>` (app-owned, NOT wp-content).
- [x] Schema: `sql/2026-05-31-avatar-version.sql` — `users.avatar_version int NOT NULL
      DEFAULT 0` (the deferred inc-1 column). New idempotent file.
- [x] `api/v0/me-avatar.php` — POST multipart, validates jpeg/png/webp ≤5MB via
      getimagesize, writes bytes, bumps `avatar_version`, sets `avatar_url` to the
      versioned served URL (`/profile-media/avatars/<uuid>/<v>.<ext>?v=<v>`), purges
      /whoami (reuses `Cache::purgeWhoami` — no new hook). Initials fallback unchanged.
- [x] `web/u.php` — wired the header 📷 affordance → file picker → POST `/me/avatar` → reload.
- [ ] **Coordinator provisions:** (1) `mkdir -p /srv/profile-app-media/avatars` + chown to
      the profile-app FPM user (0775); (2) nginx serve `^~ /profile-media/avatars/` (alias
      the store dir, cookie-gated); (3) nginx route + allowlist for `me-avatar` (mirror me-craft).
- [ ] ⚠️ `LG_AVATAR_*` are consts in the endpoint (config.php is shim-shared — didn't touch);
      move to config.php when convenient.

## Social actions slot on /u/ — DONE (write-only)
- [x] `web/u.php` computes `Social::renderProfileActions($viewer['uuid']??null, $row['uuid'])`
      and threads it through `looth_render_profile_blocks(... , $headerActions)` →
      `looth_render_header_block` echoes it inside the header card (below the identity row).
      Self-suppresses for owner/self; auth-gated when logged out (the widget owns its state).

## Spine sign-off
- [ ] Coordinator declares the spine dev-FINAL. Only then →

## Slice-4 crib (one pass) — IMPLEMENTED (write-only)
- [x] `bin/migrate-crib-slice4.php` now runs: spine-schema sanity (abort if missing),
      bridge/population check, fixture spot-check (user 3 — clobber semantics per script),
      then orchestrates the 4 sub-scripts in order. Dry-run default; `--commit` to apply.
- [x] Dry-run = runs the two dry-run-capable sub-scripts (xprofile, socials) in preview
      (writes nothing — their UPDATEs are `$COMMIT`-guarded) + read-only candidate counts
      for the two direct-write ones (snapshot, avatars). Diffs vs slice-3.5 rehearsal.
- [x] Idempotent: re-`--commit` safe (xprofile only-if-empty; snapshot skip-on-match;
      socials precedence; avatars NULL-only). Aborts the chain on any sub-script non-zero.
- [ ] **Coordinator:** dry-run → review → `--commit` on dev; then browse `/u/<slug>` for
      a few real members + walk-onboarding green.
- [ ] **FLAG — avatar bytes/store:** step 4 uses the EXISTING URL-based `backfill-avatars`
      (BB-upload/Gravatar URL). The "copy bytes into app-owned store + versioned URL"
      (avatar single-source media) is a SEPARATE unbuilt increment — NOT in this crib.
- [ ] (Cutover = coordinator-timed; outside Phase 1.)

## Hard stops (this turn observed)
No migration run · no schema apply/commit · no deploy · no git commit · no
`Whoami.php`/`config.php` edit.
