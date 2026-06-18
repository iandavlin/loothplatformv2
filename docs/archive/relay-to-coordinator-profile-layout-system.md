# → coordinator: profile-2.0 composable block layout shipped (Phases 1+2)

/ **profile-app lane → coordinator** — 2026-05-31

## What shipped (on dev, verified)
The `/u/` profile is now **user-composable**: owners drag whole blocks to reorder, and
add/remove blocks from a slide-in **caddy** (off-canvas on mobile, tap-to-add). Order +
presence are decoupled from block data — reorder/add/remove never touch the underlying
sections / users columns / Connections, so removing a block keeps its content for re-add.

Commits (branch `main`):
- `f248dab` Phase 1 — layout model + whole-block drag-to-reorder
- `26066de` Phase 2 — add/remove caddy + opt-in (start-minimal) default

Phase 3 (reorder *within* blocks — gallery photos, craft chips) is next; not yet built.

## Contract / surface (for any lane that renders or embeds profile blocks)
- **Block order is no longer hard-coded.** `looth_render_profile_blocks()` renders the header
  (pinned) then iterates `Block::profileLayout($userId)`. Registry = `Block::LAYOUT_BLOCKS`
  keys: `about, location, craft, gallery, connect, socials` (header excluded/pinned).
- **New endpoint** (no new nginx *route* needed beyond the allowlist add below):
  `GET  /profile-api/v0/me/layout` → `{ layout:[keys], blocks:<registry> }`
  `PUT  /profile-api/v0/me/layout` ← `{ order:[keys] }` (validated ⊂ registry, de-duped)
- **Default is now opt-in/start-minimal**: a profile with no explicit layout shows header +
  blocks that already have content; empties go to the caddy. Existing populated profiles are
  unaffected (nothing hidden, no data moved). New/empty profiles seed with just About.

## CUTOVER — two items you own (both already live on dev, NOT yet in tracked deploy artifacts)
1. **Schema**: new column `users.profile_layout jsonb` (NULL = default). Migration file:
   `profile-app/sql/2026-06-01-profile-layout.sql`. Must run on the live DB at cut.
2. **nginx**: the live `/etc/nginx/snippets/strangler-profile-app.conf` had `me-layout` added to
   the `/me/*` allowlist + a rewrite (same precedent as me-gallery/me-connect). The **tracked
   snippet copies in `profile-app/deploy/` are stale** (they already lack me-gallery/me-connect
   too) — flag for whoever owns snippet sync so cut carries `me-layout`.

No cross-lane contract changes: this is self-contained to profile-app's `/u/` surface. The
legacy `profiles.section_order` (the old `/profile/edit` card order) is untouched and separate.

```
profile-2.0 composable layout (Phases 1+2) is live on dev — commits f248dab + 26066de.
Owners reorder blocks by dragging and add/remove via a caddy; order/presence stored in the
NEW users.profile_layout jsonb, decoupled from block data. New endpoint GET/PUT
/profile-api/v0/me/layout (registry: about,location,craft,gallery,connect,socials; header pinned).

CUTOVER, please carry both (live on dev, not in tracked deploy artifacts yet):
  1. SQL: profile-app/sql/2026-06-01-profile-layout.sql  (ALTER TABLE users ADD profile_layout jsonb)
  2. nginx: add `me-layout` to the /me/* allowlist + rewrite in the live strangler snippet
     (tracked profile-app/deploy/*.conf copies are stale — sync owner's call).
Default is now opt-in (header + populated blocks; empties in the caddy) — existing profiles
unaffected. No cross-lane contract changes; legacy profiles.section_order untouched.
```
