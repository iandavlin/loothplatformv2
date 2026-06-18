> ⚠️ **SUPERSEDED / DO NOT DISPATCH (2026-05-31).** Stale orient: craft was already
> built + tested. The lane SESSION-HANDOFF-profile-2.0.md records craft + socials +
> slice-4 crib + /u/ block-render + View-as as COMPLETE. The genuinely-open spine item
> is the **connect block**, then spine sign-off. Kept only as a record of the misfire.

# Bootstrap — profile spine, increment 3: the CRAFT block

Build **increment 3 of the profile-2.0 spine: the craft block** (the person's
search-fuel — instruments / skills / specialties), end-to-end in `profile-app`.
Increments 1 (profile-header) + 2 (location) are done + tested and **establish the
pattern you copy.** Same rhythm this turn.

## Orient (read first)
- `docs/STRANGLER-SESSION-HANDOFF.md` → "LATEST — build phase" (inc 1 + 2 done+tested+applied).
- `docs/plan-profile-block-system.md` → "Block sets + pmp baseline" (profile `/u/` =
  profile-header + **craft**, connect, socials, location), the craft = "search-fuel"
  notes, the JSON-shape example (~line 346), and "Visibility model — FINAL" (header = ceiling).
- `docs/spec-block-identity-location.md` → the pilot block contract (pattern reference).
- **The increment-1 + increment-2 files ARE your template** — copy their shape:
  `profile-app/src/Block.php`, `web/_render_blocks.php`, `api/v0/me-header.php` +
  `me-location.php`, `PHASE-1-INCREMENT-1-TEST.md` + `PHASE-1-INCREMENT-2-TEST.md`.

## What increment 3 builds
The **craft block**, mirroring header/location: JSON shape ↔ relational mapping ↔
render ↔ block-level pmp (ceiling-capped via `Block::effectiveVisibility`) ↔ `/me`
read/write.
- **Craft = search-fuel:** instruments, skills/specialties (the old profile's
  Instruments + Skills sections). Derive the exact field shape from the canon — it
  must be a **structured, searchable list** so the directory/search can match on it
  (not free text).
- **Storage:** determine from the canon + existing schema whether craft lives in a
  **new table** (e.g. `profile_craft`) or a JSON column. If a new table/column is
  needed, append a **NEW idempotent** sql file (`sql/2026-05-31-craft-block.sql`) —
  do **NOT** apply it (coordinator applies after review). Never edit the already-applied
  inc-1/2 migrations.
- **pmp:** the craft block carries its own visibility, ceiling-capped by the header.

## The discipline (same as inc 1 & 2)
- **WRITE-ONLY turn.** Do NOT git-commit, apply schema, run migrations, deploy, or
  touch `Whoami.php`/`config.php` (shared with shim — flag coordinator). Coordinator
  commits by pathspec + applies + tests after = the "tested" gate. You CANNOT run
  git/`php -l`/screenshots (sandbox).
- **Serialize the `profile-app` tree** — ONE profile-app turn at a time.
- Run PHP as **`sudo -u profile-app php`** (peer-auth DB). **`UID` is readonly — use
  `U=` or a literal.**
- Fixture user: **id 3 ("Profile App Test", wp 1918)** — already has header + location
  rows; add craft.
- Write **`PHASE-1-INCREMENT-3-TEST.md`** mirroring inc 1/2 (block-logic truth table
  runnable via `sudo -u profile-app php`; note the authed HTTP pass is BLOCKED on shim
  `/mint-token`).
- `Block.php` is still self-`require`d — note it for `config.php` (coordinator's call);
  don't touch `config.php`.

## After increment 3
socials block (kind+url, block-level pmp) + connect → then the migration crib
(profiles-only, gated) → the View-as toggle render.

— coordinator
