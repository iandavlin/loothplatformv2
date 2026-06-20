> # ⛔ SUPERSEDED — DO NOT USE AS CURRENT STATE (rotated 2026-06-20)
> This handoff was written in the **OLD DEV-BOX era** (50.19.198.38), which is
> **DECOMMISSIONED** as the dev env (Ian, 6/20 — all dev work now on dev2). The
> workflow it describes is dead: **buck driving from his own clone**, the
> `buck.dev.loothgroup.com` previews, and the coordinator-fetch-merge flow — none
> of that exists on dev2. The profile-app **code has also moved well past** the
> block set listed below.
>
> **Current truth = the live code + `git log` on dev2, not this file.**
> Kept only as a historical record. See `profile-app/SESSION-HANDOFF.md` for the
> current pointer.

# profile-app — Session Handoff (2026-06-01)

> ⚠️ **SNAPSHOT — verify every open/queued to-do against `git log` before working it (flagged 2026-06-15).** Items marked open/TODO/next here may already be shipped — a lane re-did a done task off this file. Source of truth = `git log` + `tools/gates/run-all.sh`, not these bullets.

> **The map + editor chats are RETIRED (2026-06-01).** Their work is committed;
> the tree is clean. **`buck` now owns profile-app's profile + member-map lanes**,
> working from his own clone (`~buck/looth-platform`), git-native per
> `docs/STRANGLER-COORDINATION.md` §0e. Do NOT resume the old chats — new work on
> these files routes through buck + the coordinator.
>
> Prior handoff (slice 0–3.5 + backfills, chat a847d1aa):
> `handoffs/2026-05-29-retirement-a847d1aa.md`.

## Retired chats (tombstones)

**profile-app map — RETIRED 2026-06-01.** Committed, tree clean. Last work:
anonymized pins for hidden members (`2a3ec2e`), facets list only in-use tags
(`330c83e`), location→private off the map (`5ed0e9b`). Successor: buck.
Unfinished: none known.

**profile-app editor — RETIRED 2026-06-01.** Committed, tree clean. Successor:
buck. **Handed off:** the View-as ↔ header-card CSS gap (profile-app web CSS) —
buck takes it after location-default.

## Ownership + workflow
- **buck** drives profile (`web/u.php`, `web/p.php`, `web/_render_blocks.php`,
  `/profile-api/v0/me-*`) + member map (`web/directory-members.php`,
  `api/v0/directory-members.php`).
- Git-native: buck branches/commits in his clone; coordinator fetches, reviews,
  merges to `main`, pushes, deploys (incl. anything needing sudo — e.g. applying
  a migration as the `profile-app` pg role).
- **Preview:** `https://buck.dev.loothgroup.com/{directory/members/,u/<slug>,p/<slug>}`
  serves buck's clone's `web/` via the profile-app FPM pool (shared dev DB). `web/`
  previews live; `src/`+`vendor/` load from the shared tree (src changes need a merge).

## Recent state on main (so you're not surprised)
- `looth_id` mint bounce on directory + profile for logged-in WP users without a
  `looth_id` — shared helper `looth_issue_bounce_if_needed()` in `config.php`
  (`f78b869`); `*.dev.loothgroup.com`→dev env detection (`c7b2e0e`).
- `GET /profile-api/v0/users?wp_ids=` shipped (`a80dd1e`) — author-bio for WP consumers.

## buck's queue — ✅ BOTH DONE (2026-06-15 reconcile; do NOT re-work — verify against git first)
1. ~~**location-default**~~ — ✅ DONE: `32632a3` (buck, 6/3) shipped
   `profile-app/sql/2026-06-01-location-legacy-defaults.sql` (idempotent members/city defaults for new members).
2. ~~**View-as ↔ header-card CSS gap**~~ — ✅ DONE: `e7b0412` (6/11, "View-as chrome fix").

## Onboarding (profile is a light lane — see STRANGLER-COORDINATION.md §3n)
New-member Patreon onboarding touches profile at: provisioning (the
`/profile-api/v0/hooks/user-created` path must fire for Patreon-created users — VERIFY),
new-member defaults (= the location-default task), post-onboard landing → `/profile/edit`,
and tier reflection (Arbiter role → looth_id → whoami → tier pill/gating).

## Escalation (buck has no sudo)
sudo queue `/srv/lg-sudo-queue/REQUESTS.md` (devmsgs the coordinator); chrome-dev is
passwordless-restartable for `%loothdevs`. Talk to the coordinator via devmsg
(`msg send ubuntu "…"`). Full bootstrap: `~buck/.claude/CLAUDE.md`.
