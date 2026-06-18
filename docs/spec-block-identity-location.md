# Spec — Identity + Location blocks (profile block-system, pilot #1)

> **⚠️ SUPERSEDED in part (2026-05-29 Phase-0, Ian) — see
> `reply-to-profile-2.0-block-sets.md` + plan "Block sets + pmp baseline":**
> (1) the single `identity` block with `subject: person|practice` toggle is
> **split into two blocks** — `profile-header` (`/u/`) + `practice-header` (`/p/`);
> (2) the header pmp default is now **member**, not public (public is opt-up).
> The JSON-shape/relational-mapping/render/pmp *pattern* below still stands —
> apply it to each of the two header blocks, with the member baseline.

Buildable contract for the first two blocks. Sets the pattern every later block
follows: **JSON shape → relational mapping → block-level pmp → render →
LLM-draftable.** Design rationale: `plan-profile-block-system.md`. Owner:
profile-app.

Both are **spine blocks** — canonical, relational-backed, exactly one per
profile/practice, not removable from the palette. (Storefront/composable blocks
come in pilot #2.)

**pmp defaults LOCKED (2026-05-29, ratified w/ profile-app):**
- `identity` block (name/avatar/at_a_glance/website/socials) → **public**
- location **approximate** → **member** (user may open to public)
- location **exact** → **private**
- **`contact` (email/phone) is storefront/practice-side only — NOT a personal
  profile field.** Personal email/phone on the header is a privacy footgun;
  commission-context contact belongs on the practice page.
- Socials: write **`kind` + `url` only** — no per-row visibility (block-level
  pmp covers it; supersedes the social-consolidation plan's per-row vis).

---

## Block: `identity`

The shared `.idhead`. One per subject; default visibility **public** (you need
a findable identity).

```json
{
  "block": "identity",
  "subject": "person",                          // "person" | "practice"
  "vis": "public",                              // block-level pmp
  "fields": {
    "display_name": "Max Monte",
    "avatar":       "https://…/bpfull.jpg",
    "at_a_glance":  "Acoustic builder & repair — offset soundholes",   // person; practice uses "tagline"
    "website":      "https://…",
    "socials":      [ { "kind": "instagram", "url": "…" } ]            // kind ∈ SOCIAL_KINDS
  },
  "tier_badge":    "auto",        // person-only — DERIVED from /whoami, never stored/drafted
  "practice_type": null           // practice-only — repair|build|touring_tech|retail
}
```

**Relational mapping**
- person → `users.display_name`, `users.avatar_url`, `users.at_a_glance` (NEW
  column — the person's summary line), socials → `profile_socials` rows.
- practice → `practices.name`, `practices.avatar_url`, `practices.tagline`,
  `practices.website`, `practices.type` (NEW).
- `tier_badge` derived at render from `/whoami` tier — NOT a column.

**pmp**: one block-level `vis`. Socials no longer carry per-row visibility (the
social-consolidation plan's per-row vis collapses to this one block vis — simpler).

**Validation**: `display_name` required; `socials[].kind` must be in
`SOCIAL_KINDS`; `practice_type` required iff subject=practice; reject
`tier_badge` from drafts.

**Render**: the existing shared `.idhead` (profile-app `_render.php` /
`_render_practice.php`). Block render adds nothing new visually — it's the
current header, now fed from the block.

**LLM-draftable**: yes — name/at_a_glance/website/socials from a bio or site.
Never drafts `tier_badge`. `practice_type` inferred but flagged for user confirm.

---

## Block: `location`

The one block with **visibility × specificity** (safety-sensitive + it's the geo
facet). Two precision tiers, each its own visibility.

```json
{
  "block": "location",
  "subject": "person",
  "approximate": {
    "vis": "member",                            // default member; user may set public
    "city": "Guelph", "region": "ON", "country": "CA"
  },
  "exact": {
    "vis": "private",                           // member | private | on_request
    "address": "…", "postcode": "N1H …"
  }
}
```

**Relational mapping**
- approximate → `users.location_city/region/country` + **coarse coords**
  (city-centroid `lat/lng`) + `users.location_visibility` (= approximate vis).
- exact → `users.location_address` (NEW) + `users.location_postcode` + **exact**
  `lat/lng` + `users.location_exact_visibility` (NEW).
- (same columns exist on `practices` for practice locations.)

**Geo facet**: proximity search runs on the **coarse (city-centroid) coords**,
gated by approximate `vis`. "Near me" ranks a member without exposing the exact
pin. Exact `lat/lng` resolves only for viewers permitted by `exact.vis`, and is
**never in the search index**.

**pmp**: two visibilities — approximate (drives findability) + exact (drives
booking/visiting). Reverses slice-2.75's precision-drop, intentionally.

**Validation**: approximate `vis` ∈ {public, member}; exact `vis` ∈ {member,
private, on_request}; exact precision can't be looser than approximate.

**LLM-draftable**: approximate (city/region) from a bio/site — yes. Exact
address — only from an explicit source; defaults `exact.vis = private`. Never
auto-publishes a home address.

---

## What this pilot establishes (the template)

- `{block, subject, vis, fields}` shape + the relational fan-out.
- Block-level pmp as the uniform rule (location = documented exception with
  specificity).
- person/practice duality in one block def.
- derived-not-stored fields (tier_badge).
- LLM-draft contract + guardrails (validate, human-approve, conservative vis).

Once these two land end-to-end on dev, the storefront pilot (`store_hours`,
pilot #2) proves the composable/palette half, and the rest is additive.

## Schema adds (small)
`users.at_a_glance`, `users.location_address`, `users.location_exact_visibility`;
`practices.type`. (`practices` already has name/tagline/website/location.)
