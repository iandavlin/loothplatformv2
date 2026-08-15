# Stripe membership — the lane's brief

**Written 2026-08-15 by the stripe-membership lane.** This is the lane's memory.
It replaces the two prior chats, which will be pruned. Everything below was
re-verified against the code and the dev2 database on 2026-08-15 — not copied
forward on trust. Where a prior document is now wrong, this says so.

---

## 1. What we are doing, in one paragraph

Looth is moving membership from Patreon to Stripe, on **one tier** (`looth3`,
shown as "Looth PRO"). The switch-on is a **soft launch**: a hand-picked list of
members — the **Stripe Test Group** — get the real join / gift / regional-pricing
/ refund pages, and nobody else sees any change at all. Everything is built and
merged **switched off**, so it can reach the dev server harmlessly and be looked
at before it is ever armed.

**Three rules outrank everything in this lane:**

1. **Sandbox only.** Ian, 8/15: *"lets keep building with the sandbox for now.
   I'll take it out of sandbox for cutover."* Test mode only. A live key is a
   stop-and-report event.
2. **Nothing reaches members.** Every member-facing surface merges behind a
   switch defaulted OFF, and the OFF state is gated red-first.
3. **Live is read-only.** Live writes are Ian's, handed to him through keeper.

---

## 2. What is already built and merged — do not rebuild it

All of this is in `main` today (verified with `git merge-base --is-ancestor`):

| Thing | State |
|---|---|
| **Phase 0 — lifecycle plumbing** (`0ffb32f`) | Merged, switched off. Webhook → identity → grant/retract, rehearsed end to end in test mode. |
| **Test Group list + admin dash** (`32b7961`, `de1b208`, `4e8fe5e`) | Merged, switched off. Add a member by email, login or id, with a confirm; one-click remove. |
| **Gate 34** (`test-soft-launch-allowlist.php`) | Merged and **passing today — I ran it, 39 checks, all green.** |
| **Plain-English rename** (`f840d95`) | "Cohort" became "Stripe Test Group" everywhere on screen. Ian asked what cohort meant; that was the answer. |
| **The member pages** (`membership-pages/`) | Built and serving, behind an admin-only pre-launch gate. |
| **Test Group unlocks the pages** (gate 34b) | Built 8/15, **switched off**. Adds a `testgroup` visibility to the pages router. |
| **Price in the dash** (gate 34c) | Built 8/15, **no price set**. Settings → LG Member Sync → Stripe Price. |
| **The gift bypass, closed** (gate 34d) | Built 8/15, **switched off**. The list now fences the entitlement sweep, not just the webhook. |

The bespoke private signup page that the 8/12 chat was building is **dead** —
Ian superseded it on 8/14 in favour of reusing the existing pages, and it never
got committed anyway. Do not rebuild it.

---

## 3. The thing most likely to trip up the next person

**There are two versions of these pages, and the obvious one is not the one that
serves.**

- The membership plugin has WordPress shortcode pages (`Shortcodes.php`,
  `Pages.php`) for join, gift, manage-subscription and the rest. The WordPress
  pages genuinely exist (post ids 71413–71418, all published).
- **They never render.** An nginx regular-expression rule in
  `/etc/nginx/snippets/strangler-membership.conf` captures those slugs and sends
  them to the standalone app at `/srv/membership-pages/web/router.php`. A regex
  location outranks a prefix one, so the standalone app wins every time.

So: **gate and change `membership-pages/`, not the shortcodes.** Reading the
shortcode file and concluding anything about what a member sees is a wrong turn
that looks completely reasonable.

**How the pages are hidden today.** `membership-pages/web/router.php` gives every
page two visibility settings — one for pre-launch, one for go-live — and a single
switch (`lgms_stripe_pages_live`, **0 on dev2**) chooses which column applies.
Off means the Stripe purchase pages are administrator-only and everyone else gets
a stub.

**This lane added a third setting to that router: `testgroup`.** It is a strict
*widening* of administrator-only — administrators as before, plus signed-in
members on the Test Group list — so with its switch off, or the list empty, it
behaves exactly as administrator-only did. That is what makes the off state
byte-identical to today's site. Its own switch is `lgms_stripe_testgroup_pages`,
and **either** it or an empty list refuses everyone. An administrator is never
held behind the list, deliberately: Ian must not be able to lock himself out of
the pages he is building on by forgetting to add himself.

---

**The second thing that will trip you up: the list guarded ONE road, not all of
them.** Until 8/15 the Test Group fenced only the Stripe webhook. A redeemed
gift never touches the webhook — it goes through the separate billing app, which
writes an entitlement and pings `/sync-customer` (a route registered
unconditionally), and `Sync::customer()` turns that into a role. The five-minute
cron reaches the same place on its own, and `lgms_stripe_frozen` does **not**
stop it, because that option guards the Stripe *poll*, a different pass. So a
gift to somebody not on the list let them in anyway, within minutes.

Now fenced in `Sync::customer()` — the one choke point both roads pass through.
If you add a *third* road to a membership grant, it must pass through there too,
or the fence has another hole. Gate 34d.

---

## 4. Ian's price question, answered

> *"I'd like to be able to set the price. In the dash. Do we need poller logic
> for price changes?"* — Ian, 2026-08-15

**No — not for the membership grant.** The grant is a fixed ruling in code, not a
price lookup. `StripeLifecycle.php:88` says so outright ("a ruling, not a lookup
— no ProductRepo, no price map"), and `applyConfirmed()` grants the tier constant
whatever price the subscription carries; the price id is only **recorded** on the
subscription row. Changing the price never needs a poller edit, and **re-keying
to the product is unnecessary — the grant is already product-shaped.**

The old price-keyed code (`Stripe/EventHandler.php`, via
`ProductRepo::tierForPrice()`) still exists but is **dead**: it runs only when
`lgms_stripe_frozen` is off, and that option is **1 on dev2** (checked in the
database, not just read in the source). It is flagged for retirement. Do not
extend it.

### The one real catch — and it is a double-charge shape

The **pages** do not use the ruling. They look the price up in our own `prices`
table, and our webhook handles only checkout and subscription events — it does
**not** handle `price.created`. So a price created in the Stripe dashboard never
lands in our table, and:

- `membership-pages/web/request-refund.php:36` — returns an empty string, so the
  member sees a **blank plan name**. Mild.
- `membership-pages/web/lgjoin.php:49` — the "do you already have a
  subscription?" check **inner-joins** to `prices`, so a missing price row makes
  the whole subscription **disappear**. An already-paying member would be shown
  the join flow **again**. Not mild.

Proven on dev2 with the real query: the same lookup returns **4 rows** when the
price is in our table and **0 rows** when it is not.

**Therefore the build rule for the price control:** creating the Stripe price and
writing our own `prices` row must be **one action**. Do that and no poller logic
is needed anywhere. The single tier's product already exists in the sandbox —
`prod_UQXMEMmbIKNEn6`, "Looth PRO", database product id 9 — so the control adds a
price under that one existing product and never touches the product itself.
Existing subscribers keep their price; migrating them is a separate Ian decision
and is **not** being built.

---

## 5. Where the switches stand (dev2, verified 2026-08-15)

| Switch | Value | Meaning |
|---|---|---|
| `lgms_stripe_lifecycle` | absent | Off. No route, no read, no log. |
| `lgms_identity_gate` | absent | Off. Interlock — the lifecycle refuses while this is dark. |
| `lgms_stripe_lifecycle_allowlist` | absent | **Closed to everyone.** Absent, empty and malformed all fail closed — gated. |
| `lgms_stripe_price_id` | unset | No price chosen. Ian's decision. |
| `lgms_stripe_webhook_secret` | unset | Set on live before any flip. |
| `lgms_stripe_pages_live` | `0` | Purchase pages are administrator-only. |
| `lgms_stripe_frozen` | `1` | Old poll leg frozen. |

Flip order at go-live: **identity gate on → lifecycle on → the list governs who.**

---

## 6. What is now waiting on Ian

Everything below is a decision, not a task. Nothing here is blocked on code.

### The four he must make

1. **The price.** How much, and monthly or yearly. The control is built and
   ships with **no price set** — this is the one number nobody else can pick.
   Not needed until real money.
2. **Grandfather or correct** the four over-tiered members. They are **held,
   untouched**. Three are paying for Lite and receiving Pro; the fourth has
   stopped paying entirely. Names and amounts:
   `docs/STRIPE-HELD-MEMBERS-2026-08-15.md`. **Aron Bach needs deciding
   separately** — his card was declined on 14 Aug, and his revisit was already
   owed for the 18th.
3. **Who is in the Test Group.** The list is empty, which means closed. Adding
   people is a dash action, no deploy.
4. **When to switch the pages on.** Flip order: identity gate on → lifecycle on
   → the list governs who → the pages flag on.

5. **The Stripe keys — this now BLOCKS the rehearsal.** dev2 holds **no key of
   ours at all**, so no checkout can be created, no price can be set, and the
   money half of a soft launch (payment, gift purchase, regional pricing) cannot
   be tested by anyone. Pasting the two **sandbox** values is his step one.
   Meanwhile dev2 *does* hold a **live** payments key under the retired old
   membership plugin — so the box has exactly the wrong key: a real one nobody
   uses, and no sandbox one for the work we want. Both are fixed in one sitting.

### The two he must do himself

- **Rotate the key.** He already has a real, charges-enabled Stripe account
  ("Loothgroup", `acct_1LJOi5Hg6gcIV22b`) that predates this work, and a working
  master key to it sat in the website database for years. Roll it, then we stage
  removal of the old copy.
- **The live retraction run**, once he has ruled on item 2. Keeper carries the
  command; the run is his.

### Not built, on purpose

- **No migration of existing subscribers** to a new price — that is a separate
  decision of his, and grandfathering is the default until he says otherwise.
- **No `payment_source` writes** — the dual-holder guards await his ruling, and
  writing that meta early would arm them wrongly against members paying both.
- **No tokened invite links.** Login plus the list is enough for the test, and a
  token that bypassed the list would defeat it. Revisit only if he wants
  pre-login invites.

## 6b. The dress rehearsal (2026-08-15) — and what it caught

Walked the pages on the real serve as a whitelisted member. **The unlock did not
work at all**, and the reason is worth keeping:

**These pages are gated TWICE.** The router decides who may reach a page, and
then *every page file* calls `lg_membership_prelaunch_gate_or_exit()` and
re-checks on its own authority. The first cut changed only the router, so the
router admitted a listed member and their own page then refused them. The page's
door now delegates to the same gate the router uses, rather than keeping a
private copy of the rule.

**Gate 34b was GREEN at 54 assertions while the feature was completely broken.**
It asked the gate *function* what it would decide and read the router's *table*;
it never asked whether a member could reach a page. That is
presence-is-not-reachability. Section 8 now drives the page's own door per state,
and mutation R1 (the bug as it shipped) reddens it.

Access half: **working**, proven end to end with real sessions — listed member
gets the real join/gift/refund pages, unlisted and anon get the stub.
Money half: **blocked on Ian's keys** (see decision 5).

---

## 7. Corrections to this lane's charter

The charter named three transcripts. Two were misidentified — worth recording so
nobody re-reads 30MB looking for Stripe:

- `4d1ea595…` (8/12) — **is** the Stripe chat. Ian's own. Useful.
- `8d64b15c…` (8/11) — **not** Stripe. Article and layout work.
- `78b037a8…` (8/9, 23MB) — **not** Stripe. Fleet and keeper chat.

The genuine build detail lives in that chat's subagent transcripts and, far more
reliably, **in the commit history** — which is the source this brief was built
from, because it does not get pruned.
