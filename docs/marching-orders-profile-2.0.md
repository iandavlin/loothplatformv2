# Coordinator → profile-app: profile 2.0 — marching orders

**Direction (Ian, 2026-05-29): build the FULL profile 2.0 on dev to
fully-functioning, then flip.** No development on live. One migration into the
final model. Profile 2.0 is now the long pole of the profile side of the cut —
the flip waits for it to be dev-complete.

**Framing correction:** "post-cutover" was the wrong word — it implied building
on live. Everything below is **dev work, proven before any flip.** The cut
happens when dev is fully functioning.

---

## Build order (all on dev, dev-proven)

### Phase 0 — MOCKUPS FIRST (design-confirm before building). (Ian, 2026-05-29)
Before code, mock the novel UX and get coordinator/Ian reaction — same cadence
the shim chat used. Mock (HTML on `/srv/.../mockups/`, shared tokens):
- **The composer** — the sidebar-palette editor (block-card palette, Pro-locked
  blocks badged, drag-to-canvas, settings-panel-on-select). This is the central
  novel interaction; nail it visually before building it.
- **Profile page** on the block model (identity / location-with-pmp / craft /
  Connect / a storefront block) + a **typed practice page** (e.g. repair shop).
Surface the mockups for reaction; iterate; THEN proceed to Phase 1. (Coordinator
already has earlier directory + profile-page mockups to build from.)

### Phase 1 — SPINE (foundation + migration target). HIGHEST priority.
Must be dev-FINAL before the data crib runs — you can't migrate into a model
you're about to change. (One migration, not two.)

> **Already closed out by the retiring profile-app chat (a847d1aa), 2026-05-29:**
> the **social + location backfills** — linktree→`SOCIAL_KINDS` + migration,
> social backfill script (kind+url, three-tier precedence, reddit→web,
> linktree-only), `location_address` folded into `snapshot-location-from-bb.php`.
> These write into the FINAL spine shape (forward-compatible). **Fresh chat:
> don't redo them** — start at the block system below; verify the schema adds
> they made, build the identity/location block render on top. See that chat's
> SESSION-HANDOFF.

- **Schema adds:** `users.at_a_glance`, `users.location_address`,
  `users.location_exact_visibility`; `practices.type`; `SOCIAL_KINDS` +=
  `reddit`, `linktree`.
- **Block-level pmp** (public/member/private); **location two-tier specificity**
  (approximate public|member / exact member|private|on_request; coarse-coord geo).
- **Pilot blocks: identity + location** (`spec-block-identity-location.md`),
  then craft / socials / practices spine blocks.
- **Migration crib (slice-4) — PROFILES ONLY, into the final spine, ONE pass.**
  Practices are NOT backfilled (greenfield, user-created — Ian 2026-05-30). Person
  data only:
  - xprofile field **1 (name) → profile name ONLY** (field 2 business NOT merged —
    business fills at the practice level, which the user builds from scratch;
    literal backfill, don't auto-split mingled name+business strings, editor self-corrects).
  - field 96 (→ location_address + city/region extract).
  - field 266 + ACF `author_*` socials (final mapping, three-tier precedence).
  - **avatar IMAGE** (BB-uploaded `uploads/avatars/<id>/` → profile-app avatar store
    + `users.avatar_url`/version; Gravatar-only = one-time fetch or initials).
  - **bio → `users.at_a_glance`** from WP user `description` (the "about author"
    field). at_a_glance is the single-source author bio (see avatar contract).
  - **Everyone defaults to MEMBERS-ONLY at cut** — no profile/location public at the
    flip (member baseline; location approx→member, exact→private). Opt-up post-cut.
  - `brand_*` / business NOT swept (practice-side, greenfield).
  **Avatar + bio + display_name = the single-source author-identity card** — read by
  every surface via `/whoami` + the batch users lookup; see
  `STRANGLER-COORDINATION.md` "Avatar / author-identity — SINGLE SOURCE."

### Phase 2 — COMPOSABLE STOREFRONT.
- **Block engine:** crib lg-layout-v2's FE-editing *model* (palette, drag-drop,
  inline config, autosave, JSON round-trip) — reimplement standalone, NOT the WP
  code. Study the `lg-layout-v2` skill's editor gotchas.
- **Storefront block library:** store-hours, gallery, carousel, services, etc. —
  user-defined content (titles/captions/labels). Block-level pmp. Block limits
  (per-tier + per-type).
- **Tier-gating:** free = spine + basics; Pro = storefront palette.

### Phase 3 — JSON authoring + LLM skill.
- Versioned validated JSON profile schema (maps INTO spine relational; IS storage
  for the storefront block region).
- Importer: fan-out to existing `/me/*` endpoints.
- LLM draft skill (`write-article-v2` pattern): draft profile/practice from
  existing material → validated JSON → user reviews → commits. Guardrails:
  validate; human-approve before publish; conservative pmp; `tier_badge` never
  drafted.

---

## Cross-cutting (parallel, also pre-cut, independent of the profile model)
- **shim replacement** (mint looth_id at wp_login, retire shim) — proceed per
  `briefing-shim-replacement-design.md`. Independent of profile 2.0.

## Reference docs
- `plan-profile-block-system.md` (the model + all locked decisions)
- `spec-block-identity-location.md` (pilot block contract)
- `plan-social-consolidation.md` (final social mapping + live shape)
- `reply-to-profile-app-batch06-results.md` (confirmed live sources)

## Sequencing in one line
Spine (Phase 1) is the migration target → dev-final FIRST. Phases 2-3 are
dev-built before the flip but don't gate the migration. All dev-proven; the cut
flips the complete, working profile 2.0 and migrates users once into the final
model.

— coordinator
