# Coordinator → profile-2.0: GREENLIGHT — Phase 1 spine. Visibility model final.

Ian ruled the gate semantics. The spine's visibility model is now FINAL — you're
clear to build Phase 1 (the migration target). All Phase-0 design is closed:

## The ruling — three tiers, header is the CEILING
Tiers (unified with posts): **public** = open web/logged-out (= a public post),
**member** = signed-in members, **private** = owner only.

**The header caps the whole profile** (effective block vis = more restrictive of
header & block; a block can lock down further, never open past the header):
- header **private** → profile private, nothing else renders.
- header **member** → members-only; logged-out get the join-gate; a "public" block
  is **capped to member** (public info needs an attributable identity — Ian).
- header **public** → on the open web; **public blocks peek through** to logged-out,
  member/private blocks gate beneath.

**UX:** block pmp control shows a **hover when the header is overriding** the
block's setting. **Header default** (member vs public) = the one open knob, decide
on the next mockup; non-blocking. Canon: `plan-profile-block-system.md` →
"Visibility model — FINAL". (Render/UI logic — schema unchanged.)

## Everything locked for the spine
- Two block sets (profile / practice), overlapping; storefront = practice-only ✓
- Split profile-header / practice-header; header is the only REQUIRED block ✓
- pmp = per-block tri-state, **member default, public peeks through** ✓ (final)
- Location two-tier (approximate/exact) ✓
- Avatar = single-source spine field; profile-app stores+serves versioned URL;
  slice-4 **image** backfill; initials fallback ✓
- Media (avatars, reply images, galleries) → app-owned storage, not wp-content ✓
- **View as: Public / Member / Me** = shipped owner control (render layer) ✓
- Avatar circular on profile-header ✓

## Build Phase 1 — the spine, dev-FINAL, ONE migration target
Per `marching-orders-profile-2.0.md`: schema adds + block-level pmp (tri-state,
member-default, public=web) + location two-tier + pilot identity/location blocks
(now profile-header + practice-header) → then the slice-4 crib (name/business,
location, socials, **avatar image**) into the dev-final spine, one pass.
View-as toggle + peek-through render are render-layer (build on top; don't gate
the schema). Surface progress for reaction as you go.

GO.

— coordinator
