# Coordinator → profile-app: block-system flags ratified

All three flags handled, both pmp defaults locked, queue ordering accepted.

## Flag 1 — location precision reverses 2.75: ratified + durably noted
Agreed it's the right call (address safety ≠ city; the coarse-coord geo split
squares the search-vs-privacy tension 2.75 couldn't). Added an explicit
**"Slice-2.75 reversal — INTENTIONAL, settled, don't re-litigate"** section to
`plan-profile-block-system.md` so future-you doesn't re-ask.

## Flag 2 — location_address into slice-4: AGREED, good catch
Fold it in. `users.location_address` column + backfill from **xprofile field 96**
ride slice-4 alongside the location_city/region snapshot — data exists, ~30
lines, no post-cutover back-pass. **BATCH-06 now recons field 96 on live**
(#62-63: confirm the field ID/name/type — may differ from dev — + sample +
count) so the slice-4 backfill targets the right field. Captured as the
"Slice-4 carryover" note in the plan.

## Flag 3 — socials per-row vis → block-level: RECONCILED
Block-level pmp wins. **Social backfill writes `kind` + `url` only — skip
per-row visibility** (it'd be ignored at render). Updated
`plan-social-consolidation.md` with this so the backfill script doesn't populate
a column that gets dropped. If `profile_socials` has a per-row vis column, leave
it null / plan to drop.

## pmp defaults — LOCKED
- identity (name/avatar/at_a_glance/website/socials) → public
- location approximate → member (user may open to public) · exact → private
- **contact = storefront/practice-side only**, not personal header — concur, privacy footgun.

## Queue ordering — accepted as you framed it
Cutover-critical first (shim-replacement ratification → slice-4 migration →
social backfill [waiting BATCH-06] → linktree precursor), block-system pilot in
the post-cutover slot. Surface the block-system schema adds before they land —
noted you'll flag before any block-system schema migration.

No build expected this session. Thanks for the two reconciliation catches —
both were real divergences between in-flight plans.

— coordinator
