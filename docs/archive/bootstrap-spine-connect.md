# Bootstrap — profile spine: the CONNECT block

Build the **connect block** for the profile-2.0 spine in `profile-app` — per the lane's
own SESSION-HANDOFF, this is the **one genuinely-open spine block** (header, location,
craft, socials, slice-4 crib, `/u/` block-render + View-as are all DONE; connect is next,
then spine sign-off).

## FIRST: verify connect is actually open (the craft re-dispatch was stale — don't repeat)
Before building, confirm from `docs/SESSION-HANDOFF-profile-2.0.md` + the code that the
connect block isn't already built. The social **backend** exists (`api/v0/me-connections.php`,
`me-connections-pending.php`, `Social::renderProfileActions` Connect/Message slot in the `/u/`
header card). If "connect block" is already complete, or means only that header slot (not a
distinct spine block), **STOP and report that back** — don't build a duplicate.

## What the connect block is (scope from canon if this is thin)
`docs/plan-profile-block-system.md` "Block sets" lists the profile `/u/` set as
header + craft + **connect** + socials + location. Connect = the person's connections
surface as a first-class block: connection state / mutuals / Connect + Message actions,
with its own block-level pmp (ceiling-capped by the header). Build ON the existing
`me-connections*` backend — don't re-implement it.

## Pattern (copy the done blocks)
Mirror header/location/craft: JSON shape ↔ relational mapping ↔ render ↔ block-level pmp
(via `Block::effectiveVisibility`) ↔ `/me` read. Template files: `src/Block.php`,
`web/_render_blocks.php`, `api/v0/me-{header,location,craft}.php`,
`PHASE-1-INCREMENT-{1,2}-TEST.md`.

## Discipline (same as every prior increment)
- **WRITE-ONLY.** No git-commit, no schema apply, no migrations, no deploy, don't touch
  `Whoami.php`/`config.php` (shim-shared — flag coordinator). Coordinator commits by
  pathspec + applies + tests after.
- **Serialize the `profile-app` tree** — ONE turn at a time (social lane shares it).
- Run PHP as `sudo -u profile-app php`. `UID` is readonly — use `U=` or a literal.
- If new storage is needed, append a NEW idempotent sql file; do NOT apply it, don't edit
  applied migrations.
- Write `PHASE-1-CONNECT-TEST.md` (block-logic truth table runnable via `sudo -u profile-app php`;
  note the authed HTTP pass is blocked on shim `/mint-token`). Fixture user id 3.

When done, report: files touched (for pathspec commit), any new sql, exact test commands —
OR, if connect was already built, just say so.

— coordinator
