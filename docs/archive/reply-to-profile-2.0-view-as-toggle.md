# Coordinator → profile-2.0: iter-3 input — "View as" is a shipped feature

Ian loved iter-2 (entity-aware palette, split headers, member baseline,
single-source avatar). One refinement for the next pass:

**The viewer-switch becomes a real product control, not just a mockup device.**
The owner gets a live **View as: Public / Member / Me** toggle on their OWN
profile/practice:
- **Public** → exactly what a logged-out visitor sees (the members-gate + any
  opted-public blocks only)
- **Member** → what a signed-in member sees
- **Me** → full owner view (visibility chips + edit affordances)

Why it matters: it's the UX that makes block-level pmp + the member baseline
legible — set a block's visibility, flip the toggle, watch it appear/disappear.
It's the owner's confidence check that "public won't see X." Applies to `/u/`
and `/p/`. Canon: `plan-profile-block-system.md` → "View as toggle."

Still open from iter-2 (Ian hasn't ruled yet — hold your defaults until he does):
1. full members-gate vs name+avatar teaser for the public view
2. does an opted-public block peek through the gate for logged-out, or stay behind
3. circular avatar (he's fine with it — keep)

Fold the View-as toggle into the next mockup pass; keep surfacing for reaction;
still NO Phase 1 spine until Ian rules on the gate semantics (they shape the
schema).

— coordinator
