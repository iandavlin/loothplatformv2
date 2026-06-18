# Coordinator → profile-app: add social-media backfill from xprofile

> **SUPERSEDED — read `docs/plan-social-consolidation.md` instead.** That plan
> adds a second source (ACF author socials as fallback) and the precedence
> rules. This file covers only the xprofile half; the plan is authoritative.

New scope on the xprofile migration: backfill social-media links. Good news —
you already have everything but the data move.

## What you already have (confirmed)

- `profile_socials` table (FK to users) ✅
- editor socials modal + `PUT /me/socials` write path ✅ (the self-correct path)
- `SOCIAL_KINDS = ['instagram','youtube','bandcamp','web','email','phone','x','tiktok','facebook','patreon']` ✅

So this is a **backfill**, not a feature build — same shape as the field-1/field-2
crib in `migrate-from-xprofile.php`, extended to socials → `profile_socials`.

## The catch: source is live-only

Dev's `wp_bp_xprofile_field` is empty (stripped after the earlier migration),
so you can't see the social source structure on dev. A live recon is queued —
`cutover/BATCH-06-xprofile-socials.md` — capturing: the social field id(s) +
type, the platform set, the value serialization, and a row count. **Wait for
that paste-back before writing the mapping.**

## Likely shapes (the recon will confirm)

BuddyBoss usually stores social one of two ways:
- a single `socialnetworks`-type field with serialized `platform => url` pairs, OR
- separate fields per platform.

## The one judgment call: platform-name mapping

Map BuddyBoss platform slugs → your `SOCIAL_KINDS`. Known gotchas:
- BuddyBoss `twitter` → your `x`
- `bandcamp` / `patreon` are scene-specific — they may not exist in live's
  default socialnetworks set (confirm from recon #57); only map what's present.
- Anything in live's data with no `SOCIAL_KINDS` equivalent → decide: drop, or
  land in `web` as a generic link. Your call (escape-hatch: user fixes in editor).

## Discipline (per the migration's existing rules)

- **Literal crib, non-clobbering** — only write a social row if the user has no
  existing one for that kind (don't overwrite editor edits). Same `if empty`
  guard as the name/business fields.
- **Don't derive/enrich** — copy what's there; the editor is the self-correct path.
- Build a **dev fixture** from the BATCH-06 sample value so you can rehearse the
  parse on dev (since dev has no live social data to test against).

## Sequence

1. Wait for BATCH-06 paste-back.
2. Extend `migrate-from-xprofile.php` (or a sibling `migrate-socials.php`) with
   the social field → `profile_socials` mapping.
3. Dev-rehearse against the fixture; `--commit` runs on live at the same point
   as the name/business backfill (cutover, dev-proven first).

Report back:
```
profile-app → coordinator: social backfill ready, mapping <BB→SOCIAL_KINDS>, N users
```

— coordinator
