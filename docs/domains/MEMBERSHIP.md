# MEMBERSHIP — roles, tiers, content gating, and both payment rails

## Laws (Ian's rulings, quotable)
- **THE FOUNDING LAW (Ian 8/19, verbatim):** *"We have two ways to become a
  member. Patreon and Stripe. And they both need to work together to produce
  a logical result."* Every membership behavior is judged against this: when
  both rails have an opinion about one person, the outcome must be coherent —
  never an accident of which rail spoke last.
- **Dual-rail (8/15, permanent):** every member-facing flow fires for BOTH
  Patreon and Stripe; hooks key on membership activation, never one rail's
  events. Gate 34d asserts there is NO THIRD ROAD to a grant.
- **One payment source per member (8/19, verbatim):** *"We should disallow
  double payment source for the same user."* Block at checkout, not warn.
  Enforcement keys on ACTUAL Patreon standing (lgpo_patreon_user_id +
  lgpo_tier_map), never the one-slot `payment_source` meta — that field is
  descriptive only ("where to manage billing"), a vestige of the
  single-source world. The reverse direction is unblockable (nothing of ours
  runs at patreon.com): site-payers who also pledge get SURFACED to Ian.

## The map (verified 8/19 against main)
- Tiers: docs/TIER-TAXONOMY.md is authoritative (looth1–4 roles; content
  gates public/lite/pro; WP roles are the system of record; profile-app
  deliberately dropped its tier column). Catalogue products carry ref
  looth2/looth3; lg-stripe-billing EntitlementManager::activeTier() resolves
  a customer's ref (highest wins). Deployed multi-tier gating runs TODAY on
  the Patreon rail.
- Dual-holder trap (war-gamed in docs/atlas/STRIPE-IDENTITY-AND-LIFECYCLE-
  DESIGN.md §2, re-verified 8/19): sweep skip (class-lgpo-sync-engine.php
  :581) + reader skip (PatreonSourceReader.php:36) freeze a dual payer's
  Patreon opinion; cancel Stripe ⇒ still-paying patron falls to looth1.
  §3.1 found four live members in that shape by accident. Full multi-voice
  redesign stays PARKED under the 8/19 ruling (door closed instead).
- Checkout double-sub guard (CheckoutController.php:124) and lgjoin's
  active-sub redirect (lgjoin.php:86) check STRIPE ONLY — Patreon-blind.
  #150 (+#149 riding) closes this per the ruling.

## State (8/19)
- Phase 0 merged + rehearsed (8/9): test runs go through EXISTING member
  pages. Billing endpoint live (/billing/v1/products = 200, repaired 8/17).
- Live catalogue EMPTY (planned 8/11 reset; zz_truncsnap_20260811 snapshots
  hold the old test rows). Dev2 registers Looth LITE ($5/$60) + Looth PRO
  (placeholder $11/$132). Registration at go-live gates on IAN'S PRICE
  DECISION (dash: Settings → LG Member Sync, per-cadence options
  lgms_stripe_price_month/_year — per-tier control is #148's plan).
- Owed: over-tiered 3 held; card-retry grace follow-up (Aron); #148
  multi-tier reconnection (plan-ready).
