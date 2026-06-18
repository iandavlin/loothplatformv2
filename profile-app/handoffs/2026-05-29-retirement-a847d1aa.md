# profile-app — Session Handoff (2026-05-29, retirement)

> **This chat (a847d1aa) is retiring.** It owned slice 0 through 3.5
> plus the cross-lane coordination that landed during cutover-prep.
> All cutover-critical backfills are now closed out. Profile-2.0 (the
> block system) is fully specced and queued for a fresh chat starting
> from `docs/marching-orders-profile-2.0.md`.
>
> Prior handoff: `handoffs/2026-05-29-slice-three-five-and-backfills.md`
> (the full as-built record of slice 3.5 + the shim regression fix +
> the cross-lane coordination thread).

## Current state

**Repo:** profile-app source now lives in the `looth-platform` git repo
under `projects/profile-app/`. Edit in repo, commit at end of each
change set, push. Don't edit deployed copies in place.

**Status:** dev is in cutover-ready shape. All slice-4 prep is dev-
rehearsed. Next live work is the actual cutover (run the migrations on
prod) — outside this chat's scope; coordinator owns the timing.

## What just shipped (closing this chat out)

### Schema (`sql/2026-05-29-block-system-precursors.sql`)
Applied to dev. Two precursor adds that ride slice-4 cutover:
- `users.location_address text` — exact-precision address tier from
  the block-system spec. Populated at cutover from BB xprofile field 96
  so users don't need a back-pass through the editor.
- `profile_socials.kind_ck` extended with `linktree`. Locked
  2026-05-29: `SOCIAL_KINDS` gains exactly one new entry.

### Code
- `src/Profile.php`: `SOCIAL_KINDS` includes `linktree`.
- `web/edit.js`: same enum + `linktree:'🌳'` in the glyph map.
- `bin/snapshot-location-from-bb.php`: writes `location_address` from
  the same BB field-96 source as `location_text`. Idempotent check
  expanded.
- `bin/migrate-socials.php` (NEW): xprofile field 266 (primary) + ACF
  `author_*` (fallback). Three-tier precedence
  (`profile_socials` existing > xprofile > ACF > skip). Locked mapping:
  facebook/instagram/youtube/website → same; twitter → x; reddit →
  web (folded, URL preserved); linktree → linktree. **Writes
  kind+url only** (block-level pmp wins per the converged design;
  no per-row visibility column).

### Dev rehearsal (committed on dev)
`migrate-socials.php --commit` on dev:
- 1812 users walked, 0 no_bridge
- 165 xprofile inserts, 45 ACF inserts
- 2 kept_existing (Ian's editor edits)
- 1689 skipped_empty
- Final dev profile_socials distribution: 107 IG, 56 FB, 28 YT, 15 web
  (includes reddit folds), 4 linktree, 2 x, 1 email
- Precedence verified per-user (Ian, wp=46, wp=269)
- walk-onboarding green: `/var/www/dev/mockups/walks/20260529T194240Z`

Pushed in commit `23fe81b` to origin/main.

## What's still owed for live cutover (slice 4)

All scripts are dev-proven; slice 4 runs them on prod:

- [ ] **Triage review** with Ian (`/tmp/triage.tsv` from slice 2.75)
- [ ] **Test-data residue wipe** on real prod accounts before re-running
      the migration (Ian's synthetic-test About, Plek/Local Vintage Co.
      credentials — see slice 2.75 audit notes)
- [ ] **Hand-jigger the 6 unresolved locations** (342, 880, 889, 1076,
      1163, 1347) — BB-text without geocode_96
- [ ] **GeoLite2 + nginx rate-limit** deploy (cosmetic for now)
- [ ] **Re-run `bin/backfill-avatars.php` on prod** after cutover
      (dev has no BB avatar files; URLs resolve on live)
- [ ] **Apply schema migrations on prod:**
  - `sql/2026-05-27-slice-275.sql` (location_visibility column)
  - `sql/2026-05-27-slice-275-drop-vestigial.sql` (drops + business_name)
  - `sql/2026-05-28-drop-tier.sql` (drop users.tier)
  - `sql/2026-05-28-slice-3-practices.sql` (practices + practice_members)
  - `sql/2026-05-29-block-system-precursors.sql` (location_address + linktree)
- [ ] **Run on prod (in order):**
  - `bin/reconcile-bridge.php` (115 ghost users + any new ones since dev)
  - `bin/snapshot-location-from-bb.php` (now populates location_address too)
  - `bin/backfill-avatars.php`
  - `bin/migrate-from-xprofile.php --commit` (slim version: name +
    business_name + slug from user_nicename)
  - `bin/migrate-socials.php --commit` (NEW)
- [ ] **BB hijack on prod** — mirror the dev nginx redirects
  (`/members/<slug>/` → `/u/<slug>`)
- [ ] **Deploy `profile-whoami-shim` mu-plugin** on prod with WP-session
      bridge intact
- [ ] **Deploy `internal-mint-token` endpoint** if shim-replacement
      design ratifies in time, else defer (shim stays interim)
- [ ] **Re-run `bin/walk-onboarding.sh` post-cutover** to verify

## What's queued (NOT this chat's job)

### Profile 2.0 — block system
Owner: fresh chat. Starting point:
`docs/marching-orders-profile-2.0.md` + this handoff.

Already specced and locked:
- `docs/plan-profile-block-system.md` (the model: relational spine +
  composable storefront blocks, block-level pmp, typed practices,
  tier-gating, JSON+LLM authoring layer)
- `docs/spec-block-identity-location.md` (the buildable pilot:
  identity + location blocks with pmp defaults locked)

Schema adds for the post-cutover build:
- `users.at_a_glance` (person summary line on the header)
- `users.location_exact_visibility` (the address-tier visibility column)
- `practices.type` (repair / build / touring_tech / retail / …)

The 4th post-cutover schema add (`users.location_address`) is **already
done** in this chat's 2026-05-29 migration since it rides cutover.

### Shim replacement design — `docs/design-shim-replacement.md`
Owner: profile-app (whichever chat picks it up — could be the
profile-2.0 chat or a sibling). Awaiting ratification by coordinator
+ lg-shell + archive-poc + bb-mirror. Pre-cutover but parallel-track
with profile-2.0; not on the cutover critical path. Open questions
in §10 need a review-pass before build starts.

## Surprises worth carrying forward

(Beyond the slice 3.5 surprises in the prior handoff.)

1. **xprofile field 266 stores `youTube` (camelCase).** BATCH-06 confirmed
   the platform set includes `youTube` not `youtube`. The mapping table
   in `bin/migrate-socials.php` has both variants → `youtube`. Trivial
   gotcha; would have been a silent data loss.

2. **Same BB source feeds two columns** (`location_text` + `location_address`).
   Currently they're identical (both come from field 96). That's deliberate:
   `location_text` is the legacy display string, `location_address` is the
   new block-system exact tier. The block-system build can diverge them
   (e.g., parse approximate vs exact) without a back-pass against this
   migration.

3. **Precedence in migrate-socials is per-user × per-kind, not all-or-nothing.**
   wp=269 had xprofile IG + YT (primary) AND ACF linktree (no xprofile
   linktree). All three insert. The precedence rule only blocks within
   a single (user, kind) tuple; cross-kind it's additive. Important for
   future migrations following the same pattern.

4. **profile_socials has no per-row vis column** — block-level pmp won
   the design battle. The social-consolidation plan was updated to
   match before the migration ran. If a future block-system schema add
   tries to introduce one, that's a regression of a settled decision.

## Pointers

- Repo: `/home/ubuntu/projects/looth-platform/` (this directory is
  the repo root). profile-app source at `profile-app/`.
- Coordination docs: `docs/STRANGLER-COORDINATION.md`,
  `docs/plan-profile-block-system.md`, `docs/spec-block-identity-location.md`,
  `docs/marching-orders-profile-2.0.md`, `docs/plan-social-consolidation.md`,
  `docs/design-shim-replacement.md`, `docs/reply-to-profile-app-batch06-results.md`
- Rotated handoffs: `handoffs/2026-05-25` through `2026-05-29` series
- CUTOVER-CHECKLIST: `CUTOVER-CHECKLIST.md`
- Walk transcripts:
  - Slice 3.5: `/var/www/dev/mockups/walks/20260528T203343Z`
  - This session (backfills landed): `/var/www/dev/mockups/walks/20260529T194240Z`
- nginx snippet: `/etc/nginx/snippets/strangler-profile-app.conf`

## Lineage

This chat session ID: **a847d1aa-8252-4c06-8d90-3e470d3cc265**
- Slice 0 through 3.5 (identity backbone, practices, /whoami, batch users, WP shim)
- Cross-lane coordination: STRANGLER, social-consolidation, block-system
- Cutover-prep backfills (this final session)

**Next**: profile-2.0 build, fresh chat. Coordinator will record lineage
once spawned.

## Next-session opening move (if this chat ever resumes)

Don't. This chat is retired. Open a fresh session for any further
profile-app work — start from `docs/marching-orders-profile-2.0.md`
or the cutover scripts depending on what's in queue.
