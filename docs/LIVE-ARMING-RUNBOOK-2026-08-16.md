# Live arming runbook — from today's state to members joining via Stripe

**Every "current state" below was MEASURED on live, read-only, 2026-08-16.**
Nothing here is inferred from dev2. All live writes are Ian's.

---

## Live's actual state today

| Switch | Live | Means |
|---|---|---|
| `lgms_stripe_pages_live` | `0` | purchase pages are administrator-only |
| `lgms_stripe_lifecycle` | **absent** | off — no route, no read, no grant |
| `lgms_identity_gate` | **absent** | off — the lifecycle refuses while this is dark |
| `lgms_stripe_lifecycle_allowlist` | **absent** | closed to everyone |
| `lgms_stripe_testgroup_pages` | **absent** | off |
| `lgms_stripe_invites_on` | **absent** | off |
| `lgms_stripe_frozen` | **absent** | **frozen** — `get_option(..., true)` defaults TRUE, so the old price-keyed poll leg is skipped. Absent is the SAFE state here, which is worth stating because absent usually means "off" and here it means "on-guard". |
| `lgms_stripe_secret_key` | **absent** | **live cannot talk to Stripe at all** |
| `lgms_stripe_webhook_secret` | **absent** | no webhook secret |

**Everything member-facing is off on live.** The gap is not that something is
armed; it is that four things are missing.

---

## ✅ Already done — do NOT re-run

**The Phase 0 retraction run is COMPLETE on live.** Measured today; all four
members sit at exactly the tiers `STRIPE-HELD-MEMBERS-2026-08-15.md` said the run
would produce:

| WP id | Who | Now | Intended |
|---|---|---|---|
| 1860 | Grant Gong | `looth2` (Lite) | Pro → Lite ✅ |
| 1862 | Michael Swisher | `looth2` (Lite) | Pro → Lite ✅ |
| 1884 | John Catches | `looth2` (Lite) | Pro → Lite ✅ |
| 1861 | Aron Bach | `looth1` | Pro → free floor ✅ |

Re-running it would be a no-op at best and confusing at worst. It is listed as
"owed" in older notes; it is not.

---

## The sequence

### 0. Fix the billing routing — BLOCKER, and it blocks page one
Live carries the `/billing/` alias bug: `/billing/v1/products` answers 404 HTML,
so the join page shows "Failed to load memberships". See
`LIVE-BILLING-PREFLIGHT-2026-08-16.md` for the paste block. **Nothing below
matters until step 0's proof is green.**

### 1. Open the REST route for Stripe's webhooks — BLOCKER
Verified today: live's `bb-enable-private-rest-apis-public-content` holds
`looth/v1`, `looth-internal/v1` and a wc URL — **not `lg-member-sync/v1`**.
BuddyBoss restricts the whole REST API to authenticated callers, so Stripe gets a
**401 before any of our code runs**, silently. One admin line at
**WP Admin → BuddyBoss → Settings → General → Public Content**, then `wp cache
flush` (the option reads through object cache and a stale read hides the change).

*Proven on dev2: 401 → 400 invalid-signature, which is the correct next error.*

### 2. Wire a live Stripe key — Ian's, personally
`lgms_stripe_secret_key` is absent on live. Until it exists, nothing can create a
checkout, set a price or receive a customer. Ian holds the dashboard; the live
key has never been on this box and should not arrive through anyone else.

### 3. Register the webhook and set its secret
Events per `lg-stripe-billing/PROD-CUTOVER.md`. Then set
`lgms_stripe_webhook_secret`. Step 1 must be done first or Stripe's test delivery
401s and the endpoint looks broken.

### 4. Choose the price
The control exists and ships with **no price set** — the number is Ian's. Creating
the Stripe price and writing our own `prices` row is ONE action; a price created
in the dashboard alone makes an existing subscription VANISH from the join page's
inner join.

### 5. Arm the interlock, in this order
```
lgms_identity_gate  = 1     # first
lgms_stripe_lifecycle = 1   # second — it refuses while the gate is dark
```

### 6. Name the test group
`lgms_stripe_lifecycle_allowlist` — Ian names real people. An empty list means
nobody, which is the safe default it currently holds.

### 7. Open the pages to that list
```
lgms_stripe_testgroup_pages = 1
```
Leave `lgms_stripe_pages_live` at `0` — that is the *public* column and belongs to
full launch, not the soft launch.

### 8. Optional — invite a fresh recruit
```
lgms_stripe_invites_on = 1
```
Only around an actual rehearsal. It is a scoped gate bypass on the payment path
and has no reason to sit armed between tests.

---

## Verify after arming (not before)

- a **listed** member reaches `/lgjoin/` and sees the real page;
- an **unlisted** member still gets the stub;
- `/billing/v1/products` answers JSON;
- a test checkout reaches the webhook and grants the tier.

**Both rails fire, indefinitely.** Patreon does not sunset at the Stripe launch —
every downstream flow keys on membership activation, never on one rail's events.
"Does it fire for both rails?" applies to anything touched during arming.
