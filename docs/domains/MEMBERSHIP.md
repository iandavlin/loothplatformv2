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
  Full multi-voice redesign stays PARKED under the 8/19 ruling (door closed
  instead). ⚠️ The "four live members in that shape" is NOT REPRODUCIBLE as
  of 8/19: live has zero `payment_source='stripe'` (all 1,214 that carry it
  say 'patreon') and zero stripe rows in `lg_role_sources`. Repaired since,
  or measured on dev2 — unknown from here. Do not requote the four as
  current.
- **THREE purchase doors, not two.** The two in #150 were the Slim API
  (CheckoutController.php:124) and lgjoin's active-sub redirect
  (lgjoin.php:86). A THIRD was found 8/19 while building #150:
  `POST /wp-json/lg-member-sync/v1/me/checkout-session`
  (Wp/CheckoutRestController.php) mints a subscription session for any
  logged-in member. All three were Patreon-blind; all three now ask
  LGMS\Membership\PatreonStanding. Gate 75 asserts all three, so the
  third cannot go back to being the unwatched one.
- **The Slim app cannot read WordPress. Measured, not assumed (8/19):** its
  DB user holds `ALL ON lg_membership` + `USAGE ON *.*` only, so wp_users
  and wp_options are closed to it. The `WP_DB_NAME` / `WP_TABLE_PREFIX`
  keys in its `.env` are VESTIGIAL — no code in src/, config/, bin/ or
  public/ reads either. Anything the billing app needs from WordPress goes
  over the X-LGMS-Token REST channel (WpSync's pattern), never a query.
- `lg_patreon_members` lives in `lg_membership` — the billing app's OWN
  database — which makes a direct read tempting and wrong: it would be a
  SECOND definition of "paying Patreon", keyed on email rather than on the
  member. One definition, owned by the poller.

## State (8/19)
- **#150 + #149 BUILT** on 150-double-pay-block, flag `lgms_double_pay_block`
  defaulted OFF, gate 75. One wp_option row read three ways; the Slim app's
  OFF state is the WordPress route not existing (404 ⇒ unknown ⇒ proceed),
  so there is no second switch. Fail-open by design: an unknown answer never
  blocks a sale. The unblockable reverse direction is surfaced BOTH ways: a
  read-only "Dual Payers" tab on Settings → LG Member Sync for Ian, and a
  notice on /manage-subscription/ for the member — so the first person to
  notice a double charge is the one paying it. Never reconciled silently.
- **Dual-payer census (read-only, 8/19). LIVE: ZERO.** 1,737 Patreon rows /
  1,221 paying patrons; 0 Stripe customers, 0 live subs, 0 bridge rows. The
  Stripe rail has never granted a live member, so enforcement can arm with
  nobody to reconcile first. dev2: 11, all seeded test accounts, and ALL of
  them found via `wp_user_bridge` — an email-only probe returns zero on the
  same data, so a census that matches on email alone under-reports.
  Re-run: `deploy/remediation/report-dual-payers.sql` (pure SELECT; the
  cross-database COLLATE is load-bearing — the two DBs differ, so removing
  it raises ERROR 1267 rather than returning nothing).
- Phase 0 merged + rehearsed (8/9): test runs go through EXISTING member
  pages. Billing endpoint live (/billing/v1/products = 200, repaired 8/17).
- ⚠️ **Every membership-pages surface 404s on dev2** (join, lgjoin,
  manage-subscription, gift, my-gifts, refund, welcome, membership-guide,
  connect-your-patreon) — `fastcgi_param LG_MS_SLUG $1` delivers a wrong
  value, so the router never reaches its REQUEST_URI fallback. Code is
  byte-identical to main and renders correctly when driven straight at the
  membership FPM socket. Infra, not code; it is the whole cause of gate 34b
  being RED on main, and it means NO Stripe member page can be verified over
  HTTP on dev2 until it is fixed. Attributed on issue #150.
- Live catalogue EMPTY (planned 8/11 reset; zz_truncsnap_20260811 snapshots
  hold the old test rows). Dev2 registers Looth LITE ($5/$60) + Looth PRO
  (placeholder $11/$132). Registration at go-live gates on IAN'S PRICE
  DECISION (dash: Settings → LG Member Sync, per-cadence options
  lgms_stripe_price_month/_year — per-tier control is #148's plan).
- Owed: over-tiered 3 held; card-retry grace follow-up (Aron); #148
  multi-tier reconnection (plan-ready); the `lgms_*` runtime-option flag
  family is INVISIBLE to gate 62 (it only scans `LG_*` symbols and
  `platform/config/*.php`), so registering those flags is a discipline here
  and not an enforced one.
