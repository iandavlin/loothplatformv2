# Design — Stripe identity, retraction, and the road to launch

**Written 2026-08-08 by the `stripe-build` lane.** Companion to
`STRIPE-PHASE0-FINDINGS.md` (what live actually looks like) and the 7/30
`STRIPE-MEMBERSHIP-AUDIT.md` (how it got that way).

Scope: the kill plan for the email-keyed minter, the retraction protocol Ian's
dual-wield ruling makes mandatory, and one structural defect that ruling exposes.

---

## 1. Ian's ruling, and the single thing it changes

> *"dual wielding stripe and patreon [for] some time. Two tiers on patreon for 5 and
> 11 dollars … still be able to log in with patreon and have the two tiers respected
> for gating … move to ONE tier for the stripe memberships and have ALL tiered content
> open to the one tier through stripe."*

| | Decision |
|---|---|
| Patreon | unchanged — $5→`looth2`, $11→`looth3`, live `lgpo_tier_map` already encodes it |
| Stripe | one membership, always resolves `looth3` |
| Gating | **unchanged.** No gate, no `_render-card.php`, nothing member-facing |
| Login | Patreon OAuth unchanged; a WP-native path is added beside it (Phase 2) |
| Dual holders | the Arbiter's max-of-sources already returns `looth3`. Correct as-is |

The audit left "replacing, or alongside?" open and said the answer *"changes item 10
substantially — a permanent dual-source world needs a real retraction protocol."* The
ruling picks that branch. Retraction is now a launch blocker, not a nicety.

---

## 2. The defect the ruling exposes: `payment_source` is single-valued

**This is the finding in this doc.** Three coexistence guards all key on the
`payment_source` user meta:

1. **Sweep skip** (`class-lgpo-sync-engine.php:581-584`) — the Patreon sweep skips a
   user with `payment_source=stripe` **and** an active paid role.
2. **Reader skip** (`PatreonSourceReader:36`) — returns `null` for any
   `payment_source !== 'patreon'`, so that member contributes no Patreon opinion.
3. **Arbiter skip** (`Arbiter.php:51-54`) — a `payment_source=stripe` user with no
   `looth1` role and no persisted stripe row is skipped rather than downgraded.

`payment_source` holds **one** value. Ian has just declared a world where a member can
legitimately be both. Walk a dual holder through it:

- Member pays Patreon $5 **and** Stripe. We set `payment_source=stripe`.
- Guard 1 makes the Patreon sweep **skip them forever**. Their Patreon opinion freezes
  at whatever it last was.
- Guard 2 silences the live Patreon adapter for them too.
- Today the outcome still *looks* right, but only by luck: Stripe resolves `looth3`,
  which is the max, so the frozen Patreon opinion never matters.
- **Then they cancel Stripe.** Retraction correctly drops the Stripe opinion — and the
  Patreon opinion behind it is stale or absent, because guards 1 and 2 have been
  suppressing it the whole time. **A member still paying $5/month falls to `looth1`.**

That is not hypothetical: it is precisely the shape of the four members found on live
today (§3.1 of the findings doc), where a stale `patreon` row shadowed a live one and
a dead Stripe row was the only thing holding the tier up. Dual-wielding turns that from
an accident into the designed behaviour.

**Recommendation: demote `payment_source` to descriptive, and stop keying role logic on
it.** `lg_role_sources` *is* the multi-source model, and the Arbiter already merges it
correctly. `payment_source` is a vestige of the single-source world — it should say
"where to send this member to manage their billing", which is a UI question, and
nothing more.

Concretely, the guards become source-shaped rather than exclusive:

| Guard | Today | Should be |
|---|---|---|
| 1. Sweep skip | skip if `payment_source=stripe` | never skip. The sweep writes only the **`patreon`** row; it cannot damage a Stripe opinion because it does not own that row |
| 2. Reader skip | `null` unless `payment_source=patreon` | return the Patreon opinion whenever the member has a Patreon linkage (`lgpo_patreon_user_id`), independent of Stripe |
| 3. Arbiter skip | skip `payment_source=stripe` with no stripe row | skip when the member has **no source rows at all** — which the `$sources === []` branch already does |

This is a Phase 2 change, it is member-visible, and it must go behind its own flag. I
have **not** implemented it. It needs Ian's awareness first because it changes what
"a Patreon member" means in code.

---

## 3. Killing the email-keyed minter

### 3.1 What shipped today (flagged OFF)

`lgms_identity_gate`, default OFF — live is byte-identical to before, proven by a gate
that exercises the old mint path in the ABSENT state and requires it to still mint.

ON, `UserProvisioner::findOrProvision` routes through `IdentityMatcher::match()`:

| # | Claim | Strength |
|---|---|---|
| 1 | `wp_user_bridge` on `customer_id` | authoritative — already decided |
| 2 | `customers.metadata.wp_user_id` | an **explicit** bridge, asserted at checkout |
| 3 | `customers.metadata.patreon_user_id` → `lgpo_patreon_user_id` usermeta | stable cross-system id; **1,777 accounts carry it on live** |
| 4 | email | one signal, never grounds to create |
| 5 | no match | **refuse** — notify + throw. Never mint |

Every step also refuses a WP user already bridged to a *different* customer, which
closes the read side of R3.

`looth_uid` is **not** in the chain. The audit named it, but it has **0 rows** in live
usermeta — building on it would be building on nothing.

### 3.2 The step that makes branch 2 the normal case

Branch 2 only fires if checkout writes it. That is the real fix, and it is a
`lg-stripe-billing` change:

- A **logged-in** member starting checkout: pass `wp_user_id` (and
  `lgpo_patreon_user_id` when present) into `Stripe\Checkout\Session` metadata, which
  Stripe copies onto the customer. Identity is then *asserted by the member's own
  session*, not guessed from a string they typed into Stripe.
- A **logged-out** visitor: there is no identity to assert. They must land on
  "create your account / sign in" **before** payment, not after. A checkout that
  completes with no resolvable WP identity is the exact case that used to mint.

Until that ships, branch 5 (refuse) will fire for genuinely new customers. **That is
the correct interim**: while ingest is frozen and no member can reach Stripe
onboarding, a refusal costs nothing, and a duplicate account costs a week.

### 3.3 Kill sequence

1. ✅ Gate merged, OFF. Live unchanged.
2. Deploy by pull; confirm OFF is inert on the dev2 serve.
3. Ship checkout metadata (§3.2) so branch 2 is populated.
4. Flip `lgms_identity_gate` ON — **before** `lgms_stripe_frozen` goes falsey.
5. Once branch 5 has been quiet through a real checkout cycle, **delete the
   `wp_insert_user` branch entirely.** The flag is scaffolding; the goal is that the
   minting code no longer exists.

---

## 4. Retraction protocol

The audit's §7 names the root defect exactly: *"nothing ever retracts an opinion when
its source dies."* All 41 orphans are that bug. At Stripe volume it recurs forever.

A source opinion must be retracted on **every** way a source can die:

| Death | Signal | Action |
|---|---|---|
| Subscription canceled | webhook `customer.subscription.deleted` | `report(uid,'stripe',null)` |
| Payment failed past grace | `customer.subscription.updated` → `past_due`/`unpaid` | `report(uid,'stripe',null)` |
| Refund / chargeback | `charge.refunded`, `charge.dispute.created` | `report(uid,'stripe',null)` |
| Customer deleted | `customer.deleted` | **delete the row** — this is the orphan case |
| Entitlement expired | expiry sweep | already handled (`activeTier` → null) |
| **Customer vanished silently** | nothing | **reconcile sweep** — the backstop below |

**The mirror cannot be trusted, so the sweep is not optional.** Live proves it today:
customer 7's subscription row says `active` with `current_period_end` a month past.
Webhooks get missed, and while ingest was frozen the mirror could never learn better.
So retraction needs two independent legs:

- **Webhook leg** — fast, authoritative, but lossy.
- **Reconcile leg** — a periodic sweep that asks Stripe for the truth on every customer
  holding a live stripe opinion, and retracts what Stripe no longer confirms. This is
  the leg that would have caught all 41.

Critically, the sweep must iterate **`lg_role_sources` where `source='stripe'`**, not
`customers`. Sweeping `customers` is what let the 41 survive: their customer rows were
deleted, so nothing ever visited their opinions again. **Iterate the opinions, not the
subjects.**

Retraction writes `tier=NULL` rather than deleting, except on customer deletion — a
`NULL` row is a live source saying "no tier", which the Arbiter reads correctly, and it
preserves the audit trail. Deletion is for debris.

---

## 5. Launch checklist (ordering is the point)

The audit's Phase 4 listed these; the ordering below is what makes them safe.

1. **`lgms_identity_gate` → ON.** Before anything else. The window between "we started
   building" and "identity is safe" must never be open.
2. Retraction (§4) live, **both legs**, with the reconcile sweep proven against a
   deliberately-orphaned test customer on dev2.
3. `lgms_poller_mail_enabled` → ON, **or** tag `WelcomeMailer` with
   `X-LG-Poller-Intent`. Prefer the flag; it is the designed control. Note the welcome
   email **cannot send on live today** and fails silently — and per the dev2-mailpit
   trap, a send path cannot be proven on dev2 at all, so this one gets verified on live
   by Ian with a real address or not at all.
4. `lgms_shared_secret` + `lgms_billing_base_url` set, so reconcile-pending stops
   being inert.
5. **Then** `lgms_stripe_frozen` → falsey and `lgms_stripe_secret_key` set. Last.
6. First unfreeze **will** produce corrections against the stale mirror (§4). They are
   only visible because the tick can log again — which is why unblinding came first.

Independent of all of it, and Ian's alone: **rotate the `sk_live_…` key** still sitting
in live `wp_options`, then delete the orphaned `pmpro_*` rows. Rotate before deleting —
deleting the row does not invalidate the key.

---

## 6. Deploy shape (unchanged, still unsolved)

`lg-stripe-billing` is a real directory with its own `.git` in `/srv` on both boxes —
the only app there that is not a symlink into the serving checkout — and **has no nginx
route on live at all** (`grep -rl billing /etc/nginx/` returns nothing; re-verified
8/08). So shipping the Slim app to live is an unsolved deploy step, not a pull.

Recommendation stands from the audit: **fold it into the monorepo** and symlink it like
every other `/srv` app. Everything this lane has built lands in the monorepo already;
the Slim app is the one piece that would still be untraceable to a commit.

Note this couples to the mu-plugin symlink problem (R8): the poller's loader at
`/var/www/dev/wp-content/mu-plugins/lg-patreon-stripe-poller.php` is a **regular file**,
not a symlink, so a repo edit to it does not reach live via `git pull`. It is
byte-identical today, so there is no drift — but this branch adds churn to that plugin,
and the loader should be symlinked before that churn matters.
