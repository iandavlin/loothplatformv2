# Tier taxonomy — single source of truth

**Status:** authoritative · **Owner:** Ian · **Last set:** 2026-06-02
**Supersedes** the scattered restatements (STRANGLER-COORDINATION §1, the lane
briefings, lg-viewer-tier.php docblock, archive-poc-sync). When those disagree
with this file, **this file wins** — update them to match, don't fork.

> Why this doc exists: the tier definitions were correct but restated in ~12
> places. "Correct in twelve places" is how drift starts. One map, here.

---

## There are TWO vocabularies. Never collapse them.

| | Vocabulary | Values | Lives where |
|---|---|---|---|
| **User tier** | what a *person* is | `looth1` `looth2` `looth3` `looth4` | **WordPress roles** (source of truth) |
| **Gate bucket** | what a *piece of content* requires | `public` `lite` `pro` | the `tier` **taxonomy** on the post |

Gating = compare the viewer's bucket (derived from their user tier) against the
content's bucket. Three buckets only — user tiers map *down* into them.

---

## User tiers (looth1–4) — Ian's definitions, reconciled with deployed behavior

| Tier | Ian's plain meaning | Deployed reality | Gate bucket |
|---|---|---|---|
| **looth1** | placeholder for **former members** | Resting/default state. New & POS-provisioned users start here too; a lapsed paid member is **demoted to looth1** on churn. `provenance` distinguishes them: `lapsed` = former member, `new` = never paid. Arbiter **never removes looth1** (sticky) — POS flow depends on the row existing. | `public` |
| **looth2** | **Looth Lite** | Lite paying member (active Stripe/Patreon source). | `lite` |
| **looth3** | **Looth Pro** | Pro paying member (active source). | `pro` |
| **looth4** | **permanent bypass** (admin / VIP / comp / guest) | Arbiter short-circuits with `"looth4 protected, skipped"` — no role sync ever runs; the bypass is permanent until manually removed. | `pro` |
| *(admins)* | see-everything for QA | Anyone with `manage_options` resolves to `pro` regardless of membership role. | `pro` |

**looth1 nuance worth keeping straight:** "former member" (Ian's framing) is the
*salient* population of looth1, but looth1 is technically the **authenticated-but-
unpaid resting tier** — it also holds brand-new and POS/parser-provisioned users.
Either way it gates as `public`. Identity-aware features (commenting, profile, BB
read, personalized rails) check **`authenticated`**, not tier. Don't "clean up" the
UserProvisioner `looth1` default — the POS/parser flow needs that row.

### Provenance (sidecar — not part of the tier enum)
- `paid` — looth2/looth3 backed by an active Stripe or Patreon source
- `comp` — looth4 (admin/VIP/guest)
- `lapsed` — looth1 with ≥1 historical source row (← **former member**)
- `new` — looth1 with no source rows yet

---

## Where the data lives (the part that surprises people)

- **User tier = WordPress roles.** WP is the system-of-record. The poller's
  WP-side half (`Arbiter`/`RoleSourceWriter`) writes the looth1–4 role.
- **profile-app stores identity, NOT tier.** It had a `users.tier` column and
  **deliberately dropped it** (`sql/2026-05-28-drop-tier.sql`): *"profile-app owns
  identity, poller owns tier."* profile-app **fetches** tier from WP.
- **The standalone billing app** (`/srv/lg-stripe-billing`) processes the *money*,
  then the resulting tier is written **back into WP roles**. So payment is
  standalone but the "who-paid" answer still lives in WP. Moving that authority out
  of WP is the big deferred strangler flip (see `design-membership-rebuild.md`).

### How tier reaches a viewer (distribution — all trace back to WP)
1. **`/whoami`** (canonical) — `profile-app/api/v0/whoami` → asks the poller's
   `/wp-json/looth-internal/v1/user-context/{id}` → WP roles. The WP route
   `/wp-json/looth/v1/whoami` is just a **proxy** to the profile-app one
   (`profile-whoami-shim.php`), not a second source.
2. **`lg_tier` cookie** (LEGACY, being retired) — `lg-viewer-tier.php` mints a
   `public|lite|pro` cookie each page load so archive-poc can gate without a DB
   hit. Cutover **P6** migrates archive-poc off this cookie onto `/whoami`.

---

## Content side — tier rides the publish webhook

The clean model (Ian, 2026-06-02): **stamp the gate bucket into the post at
publish time** so the standalone serving layer gates locally, no WP call at render.

- **Already proven:** `archive-poc-sync.php` reads the WP **`tier` taxonomy** term
  on a post (`looth-pro`/`pro`→`pro`, `looth-lite`/`lite`→`lite`, else `public`)
  and ships it in the synced payload; drops gated rows for anonymous viewers.
- **Gap to close:** unify the two content-gating mechanisms —
  (a) the post-level WP `tier` taxonomy (archive-poc), and
  (b) lg-layout-v2's per-block `gated_tier` inside the layout JSON —
  into **one taxonomy that every publish webhook carries**.

---

## Open / parked

- **looth1 definition** — confirm with Ian whether the doc should treat looth1 as
  "former members" specifically or the broader "authenticated-unpaid resting tier"
  (current wording above keeps both; "former" = `provenance: lapsed`).
- **Timed-bypass plugin for teaser accounts** → port into the new repo.
  **PAUSED 2026-06-02 per Ian** — not investigated yet; placeholder only.
- **Unify content-tier taxonomy** across archive-poc + lg-layout-v2 publish paths.
