# profile-2.0 lane — Session Handoff

> Lane: the block-model profile/practice system replacing slice-0→3.5
> profile-app surfaces. Bootstrap: `docs/bootstrap-profile-2.0.md`.
> Retired predecessor: `profile-app/SESSION-HANDOFF.md` (chat a847d1aa —
> social+location backfills DONE @ `23fe81b`, don't redo). This file is the
> ACTIVE profile-2.0 chat's state.

## Status — Phase 1 GREENLIT; this turn = PLAN + SCAFFOLD only (2026-05-29).

Ian approved Phase 1 (relay `docs/reply-to-profile-2.0-phase1-go.md`); visibility
model is FINAL (commit `0641744`): **header is the ceiling**, effective block vis =
min(header, block); header private→profile private, header member→members-only
(logged-out hit join-gate, a 'public' block caps to member), header public→public
blocks peek through. Header DEFAULT (member vs public) = the one deferred knob
(next mockup; non-blocking).

**This turn produced PLAN + SCAFFOLD STUBS only — nothing applied/run/committed.**
Real build is interactive with Ian next. Deliverables:
- Build plan: `docs/plan-profile-2.0-phase1-build.md` (schema deltas + decisions
  A/B/C, pilot blocks, spine blocks, slice-4 crib, avatar store, build order).
- Checklist: `profile-app/PHASE-1-CHECKLIST.md`.
- Schema stub (NOT applied): `profile-app/sql/2026-05-30-block-system-spine.sql`.
- `profile-app/src/Block.php` (skeleton — block SETS + header-ceiling
  `effectiveVisibility`/`gateDecision`, stubbed).
- `profile-app/web/_render_blocks.php` (skeleton render loop + members-gate).
- `profile-app/bin/migrate-crib-slice4.php` (skeleton; exits 2, refuses to write).

**Key reuse insight:** the existing `profile_sections(key, visibility, data jsonb,
sort_order)` already holds per-block pmp — blocks map onto it; header vis lives in
a `key='header'` row (DECISION C). DB enum is `members` (plural) vs posts' `member`
(singular) — `Block::normalizeVis` bridges; keep the DB literal.

### Open decisions blocking the schema apply (Ian)
A. two-coord geo facet (coarse approx + exact). B. tighten approximate enum to
(public,members). C. header-vis home (sections row vs column). + vocab + header
default. See plan §1 / checklist top.

### Mockups (cookie-gated; `/var/www/dev/mockups/`, throwaway design artifacts)
- `profile-composer.html` — sidebar-palette block editor (the centerpiece).
- `profile-block.html` — `/u/` profile on the block model.
- `practice-repair.html` — typed practice (`practices.type=repair`).
View: `https://dev.loothgroup.com/mockups/<file>.html`.

### Iteration 2 applied two coordinator inputs (both now canon)
From `docs/reply-to-profile-2.0-block-sets.md` (APPROVED) +
`docs/reply-to-...` avatar contract (`plan-profile-block-system.md` "Avatar =
single source", `STRANGLER-COORDINATION.md` "Avatar / author-identity — SINGLE
SOURCE", `marching-orders` slice-4 image backfill):

1. **Entity-aware palette / overlapping block sets.** Composer is one tool, two
   libraries: `/u/` palette = shared + profile blocks; `/p/` palette = shared +
   practice blocks. Shared = location/about/gallery. **Storefront (hours,
   services, turnaround) = practice-only**; pulled off `/u/`. Composer has an
   entity switch that swaps palette filter + canvas.
2. **Separate headers.** Single identity-block-with-subject-toggle RETIRED →
   distinct **profile-header** ("me at a glance") + **practice-header** (name /
   type / tagline / location) block types.
3. **Only the header is REQUIRED**; everything else optional. Composer shows the
   header-only minimal state ("Preview minimal" → "Add your first block").
4. **pmp baseline = MEMBER** (the old `identity → public` default is RETIRED).
   Header comes in Member; opt up to Public (storefronts will) or down to
   Private. Profile mockup's viewer-switch: a Member-baseline profile shows a
   **members-only gate** to the public/logged-out web (profiles are
   members-community by default; public visibility is opt-in, per block).
5. **Avatar = single source.** Profile-header avatar is a spine-owned,
   user-editable field (initials-circle empty state), with an in-mock note that
   it's the one platform-wide image (header/forum/archive/bylines), edited only
   here. Practice "bench" notes staff faces are the same single-source avatars.

## Connect block — built + tested GREEN on dev (2026-05-31, HANDS-ON)

The last open spine block. Built ON the social-layer `Connections` backend; the full
`/u/` block set (header · location · craft · socials · connect) is now complete.

- `src/Block.php` — `loadConnect($userId,$viewerUserId)` (count + ≤12 preview avatars +
  mutuals-for-visitor + owner pending in/out), `CONNECT_KEY`. Block-level pmp; no new schema
  (reuses `connections` + `profile_sections`).
- `api/v0/me-connect.php` — GET assembled / PATCH visibility (owner).
- `web/_render_blocks.php` — `looth_render_connect_block` (ceiling-capped; owner pmp chip +
  pending hint + empty-state); wired into `looth_render_profile_blocks`, viewer id threaded.
- `web/u.php` — passes viewer id, connect CSS, `connect` in pmp endpoint map.
- **nginx (hands-on):** added `/me/connect` rewrite + `me-connect` to the authed-/me allowlist; reloaded.
- **Verified green end-to-end:** GET 200 (count 1, "Ian B Davlin", pending_in/out 1), PATCH
  public/member round-trip, bad value 400, `/u/profileapp-test` renders `lg-block--connect`.
  Test plan: `profile-app/PHASE-1-CONNECT-TEST.md`.
- **Division flag:** Connect/Message *actions* stay in the header slot
  (`Social::renderProfileActions`); the block is the list/count surface. Coordinator to ratify.

**Mode note:** Ian put the lane in HANDS-ON (apply/provision/test/no-commit) this session.
Option-2 "make spine run on dev" was already provisioned by coordinator + verified green;
this connect turn applied its own nginx route hands-on. `config.php`/`Whoami.php` untouched.
Next: spine sign-off → practice storefront blocks → fixture-only crib at/near cut.

## Avatar single-source upload + social-actions slot (2026-05-31, WRITE-ONLY)

**Avatar single-source:** profile-app now stores the bytes + serves a versioned URL.
- `sql/2026-05-31-avatar-version.sql` — `users.avatar_version int NOT NULL DEFAULT 0`
  (deferred inc-1 column; new idempotent file).
- `api/v0/me-avatar.php` — POST multipart; validates jpeg/png/webp ≤5MB (getimagesize),
  writes `<store>/<uuid>/<v>.<ext>`, bumps `avatar_version`, sets `avatar_url` =
  `/profile-media/avatars/<uuid>/<v>.<ext>?v=<v>`, purges /whoami (reuses
  `Cache::purgeWhoami` — NO new hook). Store = `/srv/profile-app-media/avatars` (const).
- `web/u.php` — header 📷 affordance wired → file picker → POST → reload.
- **Coordinator provisions:** the store dir (mkdir+chown to FPM user, 0775); nginx serve
  `^~ /profile-media/avatars/` (alias store, cookie-gated); nginx route+allowlist for
  `me-avatar` (mirror me-craft). `LG_AVATAR_*` are endpoint consts (config.php shim-shared).

**Social actions slot:** `web/u.php` renders the social lane's
`Social::renderProfileActions($viewer.uuid, $row.uuid)` and threads it through
`looth_render_profile_blocks(…, $headerActions)` → `looth_render_header_block` echoes it
inside the header card (below the identity row). Self-suppresses for owner/self;
auth-gated logged-out. `Social` is in config.php's require list.

`config.php`/`Whoami.php` untouched.

## practice-header block + /p/ page + Leaflet fix (2026-05-30, WRITE-ONLY)

Built the `practice-header` block (the /p/ equivalent of profile-header) end-to-end,
the `/p/<slug>` page, the Leaflet map fix, and confirmed the header-socials drop.

- **`src/Block.php`:** `loadPracticeHeader` (name/type/tagline/website + the OWNER's
  single-source avatar + owner city/region), `savePracticeHeader`, `practiceHeaderCeiling`,
  `practiceOwnerId`, `isPracticeOwner`. Header-as-ceiling, default members.
  **No new schema:** practice header vis is stored namespaced in the OWNER's
  `profile_sections` (`key='practice-header:<id>'`); a `practice_sections` table is the
  clean future home (flagged).
- **`api/v0/me-practice-header.php`:** GET assembled / PATCH vis; owner-only (403 else);
  `?practice=<id>` or `?slug=`. NEW endpoint → needs an nginx route (mirror me-craft).
- **`web/_render_blocks.php`:** `looth_render_practice_blocks` + `looth_render_practice_header_block`
  + `looth_render_practice_gate`; practice-header pmp control for the owner.
- **`web/p.php`:** rewired to the block render (shared chrome, View-as owner toggle, block
  loop, practice-header pmp menu JS) — replaces the slice-3 `_render_practice` path.
- **Leaflet fix (`web/u.php`):** Leaflet 1.9.4 from unpkg CDN + init JS — the location
  block's `.lg-loc__map` (coarse circle) / `.lg-loc__pin` (exact marker) now render real
  OSM tiles from the managed coords (was a grey-grid placeholder; standalone shell can't
  enqueue from WP).
- **Header socials:** verified the profile-header is ALREADY identity-only (avatar/name/
  glance) — socials live solely in the dedicated Links block. No change needed.

⚠️ Owner of a practice = `practices.created_by` (or `practice_members.role='owner'`).
Test needs a practice owned by the test user. `config.php`/`Whoami.php` untouched; no schema.

## Slice-4 crib implemented (2026-05-30, WRITE-ONLY)

`bin/migrate-crib-slice4.php` is no longer a stub — it orchestrates the 4 existing
sub-scripts to seed the spine with real profiles. Coordinator runs it (dry-run → commit).

- **Schema sanity** (abort if the dev-final spine columns are missing: at_a_glance,
  location_address, location_exact_visibility, location_pin_precision, practices.type;
  avatar_version intentionally NOT required — deferred). + bridge/population check.
- **Fixture spot-check (user 3 / wp 1918):** prints its current spine state + whether BB
  has xprofile/ACF source, and the per-script clobber semantics — name/business/slug are
  **only-if-empty** (merge, safe), socials precedence-protected, avatar NULL-only; **only
  `snapshot-location` overwrites** (the one to watch if wp 1918 has field 96).
- **Orchestration:** 1 xprofile (--commit) → 2 snapshot-location (direct write, idempotent)
  → 3 socials (--commit) → 4 backfill-avatars (direct write, NULL-only). Aborts the chain
  on any non-zero exit. Dry-run runs the two dry-run-capable scripts in preview (their
  UPDATEs are `$COMMIT`-guarded → no writes) + read-only candidate counts for the other two.
- **Idempotent** re-commit (each sub-script guards its own writes). Header members-only
  default comes from `Block::headerCeiling`'s fallback — crib does NOT seed 1,812 header rows.
- Commands: `sudo -u profile-app php bin/migrate-crib-slice4.php` (dry-run) / `--commit`.

⚠️ **FLAG:** step 4 is the EXISTING URL-based avatar backfill; the avatar single-source
**bytes-into-app-store + versioned URL** is a separate unbuilt increment, NOT in this crib.
`config.php`/`Whoami.php` untouched; no schema changes (orchestrates applied migrations).

## Inline per-block pmp control (2026-05-30, WRITE-ONLY)

The read-only vis chips are now interactive privacy controls (owner/Me view only):
- `_render_blocks.php` → `looth_pmp_control($block, $visNorm, $ceilingDb)` renders the
  chip as a `<button.lg-pmp>` with `data-pmp-block/-vis/-ceiling`; swapped in at all 5
  sites (header, craft, socials, location-approx, location-exact). `looth_vchip` kept
  but now unused.
- `web/u.php` → pmp menu JS + CSS (gated `$isOwner`). Click → tier menu → persist via
  the EXISTING endpoints (header/craft PATCH `visibility`; socials PUT `visibility`;
  location PUT `location_visibility`/`location_exact_visibility`) → `location.reload()`
  so the server re-derives ceilings + the gate (View-as stays honest). Server remains
  source of truth (validation + the gate; nothing loosened).
- **Header ceiling surfaced** per the plan's allow-but-cap model: a block can still be
  STORED more-open than the header, but the menu marks those options "limited by header"
  and a capped chip shows ⚠ + a tooltip (`Block::effectiveVisibility`). The gate enforces
  the effective (capped) vis for viewers.
- **Vocab:** JS sends DB-literal values (`public/members/private/on_request`) — the set
  every endpoint accepts (location-approx's validator requires plural `members`; the
  header/craft/socials validators accept either via `visFromInput`).

Files: `_render_blocks.php`, `web/u.php`, `PHASE-1-CHECKLIST.md`. `config.php`/`Whoami.php`
untouched. No schema, no new endpoints (reused inc1–3). Coordinator CDP-tests as owner 1918.

## /u/<slug> block render + View-as toggle (2026-05-30, WRITE-ONLY)

The spine is now VISIBLE: `web/u.php` rewired from the slice-3.5
`Profile::renderForViewer`/`looth_render_public` path to the block model
`looth_render_profile_blocks()` (header-ceiling gate + per-block renderers), shared
chrome (`_chrome.php`) + footer kept, block CSS inlined in u.php (no new nginx route).

- **View-as (owner only): Public / Member / Me** — `?view=public|member|me` sets the
  effective role and drives the SAME `looth_render_profile_blocks` (no forked render).
  Non-owners never see the control. The old owner→`/profile/edit` redirect was REMOVED
  so the owner can view + preview their own page; an "Edit profile" button in the
  View-as bar links to the editor.
- **Header default = member** (RULED) flows through `Block::gateDecision`: logged-out /
  `view=public` on a member-header profile → the members-gate; public blocks peek
  through a public header. Member/Me see the blocks (Me adds vis chips + avatar cam).
- Avatar single-source + initials fallback already lives in the header renderer.

Files: `profile-app/web/u.php` (rewritten), `PHASE-1-CHECKLIST.md` (updated).
⚠️ **FLAG — subject tier badge:** passed `null`. The subject's tier isn't on the
spine (dropped slice-3) — the header tier pill needs a membership-tier lookup for an
arbitrary user; deferred. ⚠️ Block CSS is inlined in u.php for now (no new served
asset/route); can move to a routed stylesheet later. `config.php`/`Whoami.php` untouched.

## Spine build · increment 3 — craft + socials blocks (2026-05-30, WRITE-ONLY)

Two more spine blocks, same pattern. **NO new schema** (block data uses existing
tables; block-level vis lives on `profile_sections` key `craft`/`socials` — no key
CHECK, only a vis CHECK). Mint CLI is live → inc1/inc2/inc3 HTTP tests now runnable.

- **craft** = instruments + skills + highlights (search-fuel), one block vis.
  `Block::loadCraft` reuses `Profile::loadFull` (canonical assembler). New endpoint
  `api/v0/me-craft.php` (GET assembled / PATCH vis) — **needs an nginx route +
  allowlist entry** (test §4 has the two lines; coordinator's infra step).
- **socials/links** = website (kind='web') + platform links, one block vis. Built
  ON the existing `me-socials.php` (added GET + optional `visibility` in PUT; items
  path preserved). `Block::loadSocials`.
- Generic `Block::blockVisibility` / `saveBlockVisibility` (any composable block's
  pmp on its profile_sections row). Render: `looth_render_craft_block` +
  `looth_render_socials_block`, ceiling-capped, wired after location.

Files (write-only, nothing applied/committed):
- `profile-app/src/Block.php` — +loadCraft/loadSocials/blockVisibility/saveBlockVisibility + keys.
- `profile-app/api/v0/me-craft.php` — NEW (needs nginx route).
- `profile-app/api/v0/me-socials.php` — extended (GET + visibility; items now optional).
- `profile-app/web/_render_blocks.php` — +craft/socials render.
- `profile-app/PHASE-1-INCREMENT-3-TEST.md` — block logic + render + the unblocked HTTP curls.
- `profile-app/PHASE-1-CHECKLIST.md` — updated.

⚠️ **Coordinator infra:** add nginx route + allowlist for `me-craft` (test §4).
⚠️ **Ruling:** socials now render in BOTH the inc-1 header (inline) and the new
socials block — keep both or drop the header row? (left header untouched).
config.php gap still stands (Block.php self-required in the new/extended endpoints).

## Spine build · increment 2 — location block (2026-05-30, WRITE-ONLY)

Two-tier location block, built ON the existing `api/v0/me-location.php` (not
duplicated), mirroring increment 1. Coordinator applies + tests next.

- **Approximate tier:** city/region + a town-level **coarse-from-city** coord
  (derived by rounding the stored pin — NO approx column; `Block::coarsen`),
  governed by `users.location_visibility`. Drives "near me"/map.
- **Exact tier:** the user-placed `users.lat/lng` pin at the chosen display
  **precision**, + address/postcode, governed by `users.location_exact_visibility`
  (members|private|on_request; default private, from inc 1). Never the open web.
- **User-managed pin:** placement (`pin:{lat,lng}`), precision selector
  (exact→neighborhood→city, NEW col `users.location_pin_precision`), per-tier vis.
  precision='city' folds the exact tier away (coarse only) = "fuzz to town-level".
- **Ceiling applies per tier:** effective vis = more-restrictive(header, tier vis)
  via `Block::effectiveVisibility`; `visRank` FAILS CLOSED on unknowns so
  'on_request' gates like private (never under-exposes).

Files (write-only, nothing applied/committed):
- `profile-app/src/Block.php` — +`loadLocation`/`coarsen`/`exactVisFromInput`/
  `visRank` + `EXACT_VIS_VALUES`/`PRECISION_VALUES`; `effectiveVisibility` now fail-closed.
- `profile-app/api/v0/me-location.php` — +GET assembled block; +exact vis/precision/pin
  writes (conflict-guarded); PUT returns re-assembled block.
- `profile-app/web/_render_blocks.php` — +`looth_render_location_block` + `looth_vchip`.
- `profile-app/src/Profile.php` — location address/exact_visibility/pin_precision in `loadFull`.
- `profile-app/sql/2026-05-30-location-pin-precision.sql` — NEW idempotent add (NOT applied).
- `profile-app/PHASE-1-INCREMENT-2-TEST.md` — truth-table + render + SQL-sim round-trip.
- `profile-app/PHASE-1-CHECKLIST.md` — updated.

⚠️ HTTP authed round-trip BLOCKED on shim `/mint-token` (can't mint a `looth_id` on
dev yet) — test the block LOGIC directly via `sudo -u profile-app php` (see test §5).
config.php gap (inc-1 flag) still stands: `me-location.php` `require_once`s Block.php
itself; add Block.php to config.php's require list when convenient.

## Spine build · increment 1 — profile-header block (2026-05-30, WRITE-ONLY)

Schema is **dev-final** (canon: plan "Schema — RESOLVED dev-final"). Built the
profile-header (identity) block end-to-end, **write-only** — coordinator applies
the schema + runs the test plan next.

Resolved schema reflected: 3 adds (`users.at_a_glance` = single-source author bio,
backfill from WP `description`; `users.location_exact_visibility` default private;
`practices.type`, greenfield/user-set, NOT backfilled). Header vis = the profile's
OWN vis = section cap on `profile_sections` key='header' — NO column. NO approx-coord
column (centroid from geocoder). `members` DB literal kept; `Block::normalizeVis` is
the one DB↔UI ('members'↔'member') point. Migration default = everyone members-only
at cut (crib is a later turn, profiles-only).

Files written/finalized:
- `profile-app/sql/2026-05-30-block-system-spine.sql` — apply-ready, idempotent, NOT applied.
- `profile-app/src/Block.php` — block sets + header-ceiling rule
  (`effectiveVisibility`=more-restrictive, `headerCeiling`, `gateDecision`, `canSee`,
  `isCappedByHeader`) + `loadHeader`/`saveHeader` (pilot block from spine).
- `profile-app/web/_render_blocks.php` — `looth_render_profile_blocks` gate +
  `looth_render_header_block` (author-identity card) + members-gate.
- `profile-app/api/v0/me-header.php` — GET assembled header / PATCH at_a_glance +
  ceiling vis; WP `description` mirror + whoami purge (best-effort).
- `profile-app/src/Profile.php` — `at_a_glance` added to `loadFull` (additive).
- `profile-app/PHASE-1-INCREMENT-1-TEST.md` — apply cmd + truth-table/gate/round-trip tests.

⚠️ **config.php gap (flag):** config.php `require_once`s each src class but is
shared w/ shim-replacement (hard stop — didn't edit). `me-header.php` +
`_render_blocks.php` therefore `require_once .../src/Block.php` themselves.
Coordinator should add `Block.php` (and later social classes) to config.php's
require list for consistency, then the per-file requires can drop.

## Social-layer round — PLAN + SCAFFOLD + mockup iter3 (2026-05-30)

Ian locked the **social LAYER** (connections + messaging) into profile-app's scope
(`STRANGLER-COORDINATION.md` social block) + finalized the visibility model. This
turn = plan + scaffold + mockup only (same hard stops). Scope: build-thin in-house
on postgres, home = profile-app, **CUT-DAY-REQUIRED** (P-list blocker with the
spine), seed history from `wp_bp_friends` + `wp_bp_messages_*`. UI split: Connect/
Message buttons on `/u/` (profile-app) + header modals (lg-shell P9) → one
profile-app backend.

Deliverables:
- Plan: `docs/plan-profile-2.0-social-layer.md` (connections + threads/messages/
  recipients schema, crib, API, gating, build order).
- Checklist: `profile-app/SOCIAL-LAYER-CHECKLIST.md`.
- Schema stub (NOT applied): `profile-app/sql/2026-05-30-social-layer.sql`.
- `src/Connections.php`, `src/Messaging.php` (skeletons).
- API stubs (501): `api/v0/me-connections.php`, `me-messages.php`, `me-thread.php`,
  `me-social-counts.php`.
- Crib skeleton: `bin/migrate-social-from-bb.php` (exits 2, refuses to write).
- Mockup iter3: `profile-block.html` now shows Connect + Message buttons,
  View-as Public/Member/Me, a **header-ceiling toggle (Member vs Public)** to
  compare Ian's deferred default, and **peek-through** (public header → public
  blocks peek + "members see more — join"); effective vis = more-restrictive
  (header, block).

### Visibility model — FINAL (commit `0641744`, render/UI logic; schema unchanged)
Header is the CEILING; effective block vis = more-restrictive of (header, block).
header private→profile private; header member→members-only (logged-out join-gate,
'public' block caps to member); header public→public blocks peek through.
**Header DEFAULT (member vs public) = the one deferred knob** — Ian rules on the
mockup; non-blocking.

### Social-layer open decisions (Ian)
who-can-DM (any-member vs connections-only) · ship follow now? (verify
`wp_bp_follow` on live) · header counts via dedicated `me-social-counts` (rec.)
vs `/whoami` (shared — needs sign-off) · contact-reveal hybrid pilot timing.

## Locked decisions carried forward (don't regress)
- Block-level pmp (no per-row socials vis). **Baseline now MEMBER** (changed this
  iteration; was public for identity).
- Location = the one visibility×specificity block: approximate (public|member) /
  exact (member|private|on_request); coarse-coord geo; exact never in search idx.
- `tier_badge` derived from /whoami — never user-authored / LLM-drafted.
- Only the header is required per entity.
- Avatar single source = profile spine owns `users.avatar_url` + image store +
  versioned per-uuid URL; slice-4 adds the avatar IMAGE backfill.

## Next (after reaction-confirm) — Phase 1 SPINE
Migration target; must be dev-FINAL before the data crib (one migration). The
profile-header / practice-header split + the **member pmp baseline** must be
reflected in the spine schema before it's frozen. Schema adds still owed:
`users.at_a_glance`, `users.location_exact_visibility`, `practices.type`
(`users.location_address` already shipped @ `23fe81b`).

## Coordination
- profile-app/ shared with shim-replacement chat (`d9380b73`). **Flag coordinator
  before editing `Whoami.php` / `config.php`.** Phase 0 touched neither.
- Cross-lane / contract changes route through the coordinator.
- §0 commit discipline: edit in repo, stage by pathspec, commit + push. Mockups
  live outside the repo (`/var/www/...`) and are throwaway.

## Lineage
This chat session ID: `1c98b564-ae29-4bc2-af2d-b06f80498aa4`. Spawned by
coordinator. Phase 0 iter 1 (21:31) → iter 2 (this turn) per Ian relay.
