# Bootstrap — profile spine, increment 2: the LOCATION block

You're building **increment 2 of the profile-2.0 spine: the location block**,
end-to-end, in `profile-app`. Increment 1 (the profile-header block) is done +
tested and **establishes the pattern you copy.** This turn is the same rhythm.

## Orient (read these first)
- `docs/STRANGLER-SESSION-HANDOFF.md` → "LATEST — build phase" (where everything is).
- `docs/plan-profile-block-system.md` → "Schema — RESOLVED dev-final", the location
  decision #4 + **"User-MANAGED pin"**, "Visibility model — FINAL" (header = ceiling).
- `docs/spec-block-identity-location.md` → the pilot block contract (identity + location).
- **The increment-1 files ARE your template** — copy their shape:
  `profile-app/src/Block.php`, `profile-app/web/_render_blocks.php`,
  `profile-app/api/v0/me-header.php`, `profile-app/PHASE-1-INCREMENT-1-TEST.md`.

## What increment 2 builds
The **location block**, mirroring the header block's pattern:
JSON shape ↔ relational mapping ↔ render ↔ block-level pmp (ceiling-capped) ↔ `/me` read/write.

**Location is the one block with two-tier specificity (per the resolved schema):**
- **Approximate tier** — city/region; coords come from the **city/state centroid the
  geocoder already returns** (NO stored approx column). Governed by the existing
  `users.location_visibility`. Drives "near me" + the directory map.
- **Exact tier** — `users.lat/lng` (the precise pin), GATED. Governed by the NEW
  `users.location_exact_visibility` (default `private`; CHECK members|private|on_request,
  applied in increment 1).
- **User-MANAGED pin (Ian):** the user places the pin, picks precision
  (exact → neighborhood → city), sets per-tier visibility. A storefront drops an exact
  public pin; a private maker fuzzes to town-level or hides it. The map plots the
  *managed* pin at the chosen precision + visibility; never an exact pin the user
  didn't choose to expose.
- **Ceiling still applies:** the location block's effective visibility = the
  more-restrictive of (header ceiling, the tier's own vis) — use `Block::effectiveVisibility`.

**`api/v0/me-location.php` ALREADY EXISTS** (it's in the nginx rewrite list) — build
ON it, don't duplicate. Add the pin-manager (placement + precision + per-tier vis) +
the exact/approx two-tier read/write + the coarse-from-city derivation.

## The discipline (same as increment 1)
- **WRITE-ONLY turn.** Do NOT apply schema, run migrations/crib, deploy, git-commit,
  or touch `Whoami.php`/`config.php` (shared with shim — flag coordinator).
  **Coordinator commits by pathspec + applies + tests after** — that's the "tested" gate.
- **Serialize the `profile-app` tree** — ONE profile-app turn at a time (social shares
  it). Don't run while a social turn is live.
- Background turns: `claude --resume <id> --print --permission-mode acceptEdits`; set
  `touch /tmp/no-idle-shutdown` for the run, `rm` after.

## Apply/test recipe (coordinator's step after the write)
- Likely **no new schema** (`location_exact_visibility` landed in increment 1). If any
  add, append to a NEW idempotent sql file; apply `sudo -u profile-app psql -d profile_app -f <sql>`.
- Run PHP as **`sudo -u profile-app php`** (peer-auth DB; plain `php` as ubuntu fails —
  no pg role). **`UID` is a readonly shell var — use `U=` or a literal.**
- Test (mirror `PHASE-1-INCREMENT-1-TEST.md`): loadLocation assembles both tiers;
  render honors ceiling + per-tier vis (exact hidden to non-permitted, approx shows);
  coarse-from-city derivation; `/me/location` write round-trip (pin, precision, both
  vis fields). Fixture user: **id 3 ("Profile App Test", wp 1918)** — already seeded a
  header row; add location.
- **HTTP authed round-trip is BLOCKED on shim's `/mint-token`** (JWT key is `looth-dev`
  group, DB is `profile-app` peer — can't mint a `looth_id` on dev yet). Test the
  block LOGIC directly via `php` (like increment 1's truth-table); the authed HTTP
  pass closes once shim unblocks. See `reply-to-shim-mint-dev-priority.md`.

## After increment 2
craft + socials spine blocks → then the **migration crib (profiles-only**, practices
greenfield; gated until the whole spine is dev-final) → the View-as toggle render.
Open knob: **header default** (member vs public) — Ian's, non-blocking.

— coordinator
