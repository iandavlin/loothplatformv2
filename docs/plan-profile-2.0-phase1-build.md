# Phase 1 build plan — profile-2.0 spine (the migration target)

> Status: **PLAN + SCAFFOLD for Ian to approve — nothing applied/run/deployed.**
> The spine is the ONE migration target: it must be reviewed **dev-final** before
> the slice-4 crib runs (one migration into the final shape, never two).
> Canon: `plan-profile-block-system.md` (model + Visibility model FINAL),
> `spec-block-identity-location.md` (pilot blocks), `marching-orders-profile-2.0.md`
> (build order), `reply-to-profile-2.0-phase1-go.md` (greenlight). Lane state:
> `docs/SESSION-HANDOFF-profile-2.0.md`.

## 0. What already exists (build ON this — don't rebuild)

The slice-0→3.5 spine is most of the relational model already:
- `users` — identity, avatar_url, location_city/region/country/postcode, lat/lng,
  **location_visibility** (`public|members|private`, default `members`),
  location_address (shipped by retired chat), legacy_xprofile jsonb.
- `profile_sections (user_id, key, visibility, data jsonb, sort_order)` —
  **per-section pmp already lives here.** Blocks map onto this. ← key insight
- `profile_socials (user_id, kind, value, sort_order)` — `kind` CHECK includes
  `linktree` (shipped). No per-row vis (block-level pmp covers it).
- Typed catalogs: `profile_instruments/skills/scenes/credentials/highlights` +
  `*_catalog` tables → these ARE the "craft" search-fuel.
- `practices` + `practice_members` — `/p/` entity, location_visibility default
  `public`, staff roster M2M. `avatar_url`, tagline, about, website.
- Render: `web/_render.php` (profile), `web/_render_practice.php`, `_render_public.php`.
- Viewer roles in `Profile::canSee()`: `me|friend|member|public`.

**Vocabulary note (decide):** DB enum is `members` (plural); the FINAL model + posts
say `member` (singular). Recommend: keep the **`members` DB literal** (avoid
renaming ~1800 rows + every CHECK) and map to "member" only in the JSON/UI layer.
One-liner in `Block.php` normalizes. Flagged for Ian; non-blocking.

## 1. Schema adds — the spine deltas (review BEFORE applying)

Stub migration: `profile-app/sql/2026-05-30-block-system-spine.sql` (written, **NOT
applied**). Three confirmed adds + two flagged decisions:

**Confirmed (from `spec-block-identity-location.md` "Schema adds"):**
1. `users.at_a_glance text` — the person's header summary line.
2. `users.location_exact_visibility text` — exact-address tier vis. Enum
   `member|private|on_request`, default `private`. (Approximate tier stays
   `users.location_visibility`.)
3. `practices.type text` — `repair|build|touring_tech|retail|…`. Required at
   creation (app-enforced); existing dev practices need a one-time backfill value
   (flag: pick a default or hand-set — there are few on dev).

Already shipped (DON'T redo): `users.location_address`, `profile_socials` +linktree.

**Flagged decisions for Ian (the reason this is review-first):**
- **A. Two coordinate pairs for the geo facet.** Spec wants proximity search on
  **coarse city-centroid** coords while **exact** lat/lng resolves only for
  permitted viewers and is **never indexed**. Existing `users.lat/lng` is a single
  pair. Proposal: repurpose `lat/lng` as **exact**, add
  `location_approx_lat/location_approx_lng numeric(9,6)` (city-centroid) →
  search/"near me" runs on the approx pair gated by `location_visibility`. Included
  in the stub, commented `-- DECISION A`.
- **B. Tighten approximate enum.** Spec: approximate vis ∈ {public, member};
  exact ∈ {member, private, on_request}; exact never looser than approximate.
  `location_visibility` currently allows `private` too. Tighten, or enforce in
  app-validation only? Stub leaves the column enum as-is and notes app-level
  validation (`Block.php`), commented `-- DECISION B`.
- **C. Where header vis lives.** The header is the ceiling. Per the relay
  ("schema unchanged — vis is per-block tri-state"), store header visibility as a
  `profile_sections` row `key='header'` (and a practices equivalent), **not** a new
  column. Render derives the ceiling from that row. No schema change. Confirmed
  approach unless Ian prefers a dedicated `users.header_visibility` column.

## 2. Pilot blocks — identity (→ profile-header / practice-header) + location

Establishes the block template: `{block, subject, vis, fields}` → relational
fan-out → header-ceiling pmp → render → LLM-draftable.

**`src/Block.php` (new, stubbed)** — the block model + the ONE load-bearing rule:
- `BLOCK_SETS` — registry: which block keys are shared / profile-only /
  practice-only (mirrors the composer palette). Drives validation + palette.
- `effectiveVisibility(headerVis, blockVis)` = **more restrictive of the two**
  (`min` on the public<member<private ordering). The header-ceiling rule, one
  function, used by every render path.
- `canViewerSee(role, effectiveVis)` — wraps existing `Profile::canSee`.
- `headerCeiling($entity)` — reads the header section's vis (Decision C).
- `gateWholeProfile($role, headerVis)` — header `private`→owner-only;
  header `member`+logged-out→ join-gate; header `public`→ blocks peek through.

**profile-header** maps to: `users.display_name`, `users.avatar_url`,
`users.at_a_glance` (NEW), website (→ a social `web` or a users col — confirm),
socials → `profile_socials`. Tier badge derived from `/whoami`, never stored.
**practice-header** maps to: `practices.name/avatar_url/tagline/website/type`(NEW).

**location** maps two-tier: approximate → city/region/country + approx coords +
`location_visibility`; exact → `location_address`/`location_postcode` + exact
lat/lng + `location_exact_visibility` (NEW). Exact never enters the search index.

**Render (`web/_render_blocks.php`, new stub):** the block render loop —
for each block in order, `effectiveVisibility`, gate by viewer role, draw. Reuses
the existing `_render.php` markup pieces; adds the **header-ceiling gate** + the
**"View as: Public / Member / Me"** owner toggle (render-layer; build on top).
Public mockup already demonstrates the target output (`/var/www/dev/mockups/`).

## 3. Remaining spine blocks — craft / socials / practices

Additive once the pilot template lands:
- **craft** = the typed catalogs (instruments/skills/scenes) already loaded by
  `Profile::loadFull` → render as the search-fuel chips. Block vis via its
  `profile_sections` row.
- **socials** = `profile_socials` rows; block-level vis (no per-row).
- **practices** (on `/u/`) = `Practice::forUser()` cards linking to `/p/`.
- **staff/bench** (on `/p/`) = `Practice::members()` linking back to `/u/`.

## 4. Slice-4 migration crib — ONE pass into the dev-final spine

Stub: `profile-app/bin/migrate-crib-slice4.php` (written, **does nothing yet** —
`--commit` guarded, body is TODO). Runs only AFTER the spine above is approved
dev-final. Single pass, three-tier precedence (existing > xprofile > ACF > skip):
- **name / business** ← xprofile field 1 / 2 (slim; reuses `migrate-from-xprofile.php`).
- **location** ← xprofile field 96 → `location_address` + city/region extract +
  approx/exact split (Decision A) + geocode for coords.
- **socials** ← xprofile field 266 (primary) + ACF `author_*` (fallback), locked
  mapping (twitter→x, reddit→web fold, youTube camelCase, linktree→linktree) —
  this logic already exists in `bin/migrate-socials.php`; the crib orchestrates it.
- **avatar IMAGE** ← copy each user's current avatar bytes into the app-owned
  store (see §5), set versioned URL; Gravatar-only → one-time fetch or initials.
  Extends `bin/backfill-avatars.php` (today writes URLs; slice-4 writes bytes).
- `at_a_glance` ← NOT auto-swept (no clean BB source); user authors, or LLM drafts
  later (Phase 3). `tier_badge` never swept. `brand_*` NOT swept (practice-side).

**Rehearsal gate:** dry-run on dev first (no `--commit`), diff counts vs the
slice-3.5 rehearsal numbers (1812 users, 165 xprofile + 45 ACF social inserts),
then commit on dev, walk-onboarding green, THEN it's a cutover script.

## 5. Avatar single-source + app-owned media store

FINAL contract (`plan-profile-block-system.md` "Avatar = single source",
`STRANGLER-COORDINATION.md` "Avatar / author-identity — SINGLE SOURCE"):
- profile-app **owns** `users.avatar_url` + **stores AND serves** the image: a
  canonical, stable, **versioned per-`user_uuid`** URL (not wp-content, not
  Gravatar). Add `users.avatar_version int default 0` (stub) — bump on edit.
- **Store layout (stub, propose):** `media/avatars/<uuid>/<version>.<ext>` under an
  app-owned dir served by the profile-app nginx pool (not WP). Same store later
  holds forum reply images + galleries (coord note: own our store, commit `0a3939b`).
- **Editor = single edit point:** avatar upload writes spine row + bytes, bumps
  `avatar_version`, fires the existing **identity-purge** (`internal-purge-whoami`)
  so mirrors (shared header, bb-mirror, archive bylines) re-pull.
- **Read paths (already contracted):** viewer's own avatar → `/whoami`; any
  author's avatar → batch `GET /profile-api/v0/users?uuids=`. Initials fallback
  when `avatar_url` null. (No new contract — just populate the store.)
- **Slice-4:** the avatar IMAGE backfill in §4 seeds the store.
- ⚠️ Touches storage + `/whoami` shape adjacents but **NOT `Whoami.php`/`config.php`
  logic** without flagging the coordinator (shared w/ shim-replacement `d9380b73`).

## 6. Build order (each step surfaces for reaction; spine reviewed before crib)

1. ▢ Approve schema deltas (§1 + decisions A/B/C) → apply `…-spine.sql` on dev.
2. ▢ `Block.php` — block sets + `effectiveVisibility` + header-ceiling/gate. Unit-ish test.
3. ▢ profile-header + practice-header render via `_render_blocks.php` + header gate.
4. ▢ location two-tier render + approx/exact vis + geo-facet (coarse coords).
5. ▢ "View as: Public / Member / Me" owner toggle (render layer).
6. ▢ craft / socials / practices-card / staff blocks (additive).
7. ▢ Composer editor parity (Phase 2 cribs the lg-layout-v2 *model*, standalone).
8. ▢ **Spine declared dev-FINAL — coordinator sign-off.**
9. ▢ Slice-4 crib dry-run → dev commit → walk-onboarding green.
10. ▢ (cutover, coordinator-timed — outside this plan.)

Steps 1–8 are the spine that must freeze before step 9. Steps 7 (composer) and the
LLM authoring layer (Phase 3) are dev-built before the flip but DON'T gate the crib.

## HARD STOPS (this plan does not execute)
No migration run, no schema apply/commit, no deploy, no git commit, no edit of
`Whoami.php`/`config.php`. Spine is review-first by design.
