# Coordinator → profile-2.0: Phase-0 iteration — block sets, headers, pmp baseline

Ian reacted to the Phase-0 mockups (composer + `/u/` + `/p/`). Good start; he's
going to "play with it like forum." Two design refinements to fold into the
mockups **before** Phase 1 spine. Both now canon in `plan-profile-block-system.md`
("Block sets + pmp baseline" section).

## 1. Profile blocks vs practice blocks — overlapping, not one shared palette
- **Two entity views** (`/u/` profile, `/p/` practice), **overlapping** block sets:
  - **Shared blocks** work on both (gallery, about/text, …).
  - **Specific blocks** are entity-only — **storefront / hours = practice-only**;
    a person doesn't have store hours. The storefront block in the current
    composer mockup is a **practice** block; it shouldn't appear on a `/u/`.
- **Composer filters the palette by entity** = shared blocks + that entity's own.
  Same composer TOOL, two block libraries.

## 2. Separate headers
- **Supersedes** the single identity block with `subject: person|practice` toggle.
  Split into **profile-header** ("me at a glance") + **practice-header**
  (name / type / location) — two distinct block types. (`spec-block-identity-location.md`
  banner-flagged accordingly.)

## 3. Only the header is REQUIRED; pmp baseline = MEMBER
- **Only the header is required** — everything else optional/composed. A minimal
  profile or practice is *just a header*; the user adds the rest. (Mockups should
  show the empty/minimal state = header-only, "add your first block" affordance.)
- **The header comes in at member-only pmp** — this **changes the locked default**
  (`identity → public` is retired). New profile/practice baseline = **member**;
  the user opts **up to public** (storefronts will, for findability) or **down to
  private**, per block.
- **Product stance to reflect in the pmp viewer-switch:** profiles are
  members-community by default — the public/logged-out web doesn't see a profile
  unless the owner opens it. Directory works because members see members; public
  visibility is opt-in. (The `/u/` mockup's viewer-switch should default the
  "public/logged-out" view to *not* showing the profile unless opted public.)

## Net for the mockups
- Composer: two palettes (entity-aware) — profile-blocks vs practice-blocks, with
  the shared subset in both; storefront moves to the practice palette only.
- Empty state: header-only minimal profile/practice + add affordance.
- Header block: member default in the pmp control, opt-up-to-public visible.
- Viewer-switch: public view of a member-default profile = hidden/locked.

Iterate the mockups, surface for reaction, THEN Phase 1 spine. Spine schema +
the profile-header/practice-header split should reflect this before the migration
target is frozen.

— coordinator
