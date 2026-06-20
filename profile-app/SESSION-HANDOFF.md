# profile-app — Session Handoff

**Last refreshed: 2026-06-20.** Keep this file SHORT and current. When it goes
stale, rotate it: `git mv SESSION-HANDOFF.md handoffs/YYYY-MM-DD[-suffix].md`,
add a SUPERSEDED banner to the rotated copy, and write a fresh pointer here.
(Convention: `projects/CLAUDE.md` → "Handoffs rotate per-project.")

## ⚠️ Read this before trusting ANY handoff
- **Source of truth = the live code on dev2 + `git log`**, never a handoff's
  to-do bullets. Handoffs drift; a lane has re-done shipped work off a stale list.
- **Environment:** all dev work is on **dev2** (`dev2.loothgroup.com`, EIP
  34.193.244.53). The **old dev box (50.19.198.38) is DECOMMISSIONED** (Ian, 6/20).
- **Workflow is git-native off `main`:** lanes edit in their own worktree
  (`git worktree add ~/worktrees/<lane> -b <lane> main`), commit small tested
  increments, and route to `main` through the **keeper** (no self-push, no
  self-merge). The old **buck-clone / `buck.dev.loothgroup.com` preview /
  coordinator-merge** flow is DEAD — ignore any handoff that assumes it.
- Pre-2026-06-20 handoffs in `handoffs/` are historical only (old-box era).

## Where the code is
- Serve clone (renders live dev2): `~/loothplatformv2-serve/profile-app`
- Edit clone / worktrees: `~/loothplatformv2`, `~/worktrees/<lane>` (both on `main`)
- WP-CLI: `sudo -u looth-dev wp --path=/var/www/dev …`

## Current surface (verify against code before working)
- `/u/<handle>` public profile → `web/u.php` → `looth_render_profile_blocks()`
  in `web/_render_blocks.php`. Live block set: **header** (identity/avatar/
  at-a-glance) + owner **Business pill**, then body blocks in owner-chosen order:
  about · location · skills · services · instruments · music · gallery · resume ·
  connect · socials.
- `/p/<slug>` practice/business page → `web/p.php` → `looth_render_practice_blocks()`
  (practice-header + about/location/dropoffs/hours/links/staff).
- Owner controls: inline per-block privacy chips (pmp) + View-as (Public/Member/Me).
- Editor: `web/edit.js`, styling `web/directory.css`.
- Identity via `/profile-api/v0/whoami` (self) + `/users` (others), keyed on
  `user_uuid`; data in the profile-app Postgres DB (NOT WP/BuddyBoss). Tier =
  WP roles. **Keep the location render patch (2-decimal + text fallback) intact.**

## Lane boundaries
profile-app owns pages/blocks/editor. **Members-MAP sorting** and the
**front-page composition** are SEPARATE lanes (own worktrees: `map-sort-buttons`,
`frontpage`) — coordinate, don't collide. Cross-cutting changes route via the keeper.

## History
Detailed phase/slice records: `handoffs/` (esp.
`2026-06-01-old-dev-box-buck-era.md` for the buck/spine-build era — superseded).
Phase-1 build records: `PHASE-1-CHECKLIST.md` + `docs/plan-profile-2.0-phase1-build.md`,
`docs/plan-profile-block-system.md` (verify "done" items against `git log`).
