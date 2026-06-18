# Coordinator → profile-2.0: directory + location batch (Ian, 2026-05-30)

Four items, all profile-app lane, from Ian reviewing the live `/directory/members/`.
Canon: `plan-profile-block-system.md` (location two-tier + user-managed pin),
`marching-orders-profile-2.0.md` (slice-4 crib), `STRANGLER-COORDINATION.md §0a`
(header consumer contract).

## 1. 🔴 Shared header is MISSING on the directory
`/directory/members/` renders bare — no site nav. It must render the shared header
like every other standalone page: `lg_shared_render_site_header($ctx)` with
**`active_nav => 'members'`** (so Members highlights/suppresses) + `logout_url` per
§0a. Known carry-forward that never got wired.

## 2. Location block = a user-MANAGED pin (build this BEFORE the map)
Specificity + privacy are the USER's to control on the location block:
- **Place the pin** — drag/place where they want it shown.
- **Pick precision** — exact → neighborhood → city/region.
- **Per-tier visibility** — public / member / private (the two tiers: approximate
  gate public|member, exact gate member|private|on_request).
A storefront drops an exact public pin; a private maker fuzzes to town-level or
hides it. Same approximate/exact model already in the plan, but **user-driven
placement + precision**, not auto-coarsened-only.

## 3. Members MAP on the directory
Plots each member's **managed pin at their chosen precision + visibility** (depends
on #2). Honor `location_visibility` per viewer (member-visible shows to members,
not logged-out). "Near me" / the radius filter still rank on the **coarse** tier.
The map never plots an exact address the user didn't choose to expose — the pin
the user set IS what shows. (Cluster pins at low zoom for 664+ members.)

## 4. Name backfill → profile name ONLY
Slice-4 crib: xprofile **field 1 (name) → profile name only**. Field 2 (business)
is **NOT** merged into the profile name — business fills at the **practice** level.
Literal backfill; don't auto-split mingled "Name + Business" strings (e.g.
"Anthony Lattanze Lattanze Guitars") — the name field goes in as-is and the editor
is the user's self-correct path to move the shop bit to their `/p/`.

## Sequence
#1 is a quick fix (wire the header). #2 → #3 (map needs the managed pin first).
#4 rides the slice-4 crib. All review-first per the existing hard stops; flag
coord before any `/whoami` shape change.

— coordinator
