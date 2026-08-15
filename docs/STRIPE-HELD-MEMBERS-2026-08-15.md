# The held over-tiered members — who they actually are

**Measured on live, read-only, 2026-08-15 by the stripe-membership lane.**
Answers Ian's question of the same day: *"all stripe users are currently sandbox
users. I thought we got rid of all of those. Who are they?"*

**The decision is Ian's and nothing here has been executed.** The retraction run
is his to run; this only measures what it would do.

---

## Short answer

**Ian is right that there are no live Stripe users.** Live holds **zero** Stripe
customers, **zero** subscriptions, **zero** prices and **zero** products. Nothing
has ever been charged through Stripe outside the sandbox.

**The held item is not a Stripe account, which is why the cleanup missed it.**
What remains is **four dead rows in our own roles table** (`lg_role_sources`,
`source='stripe'`), written during the retired April–June trial, each still
saying *"Stripe says this person is Pro."* The customers they referred to were
deleted; the opinions were not. The August truncate emptied the Stripe **mirror**
tables — these rows live in a **different table** and were deliberately held back,
because deleting them **changes what a real member sees**.

**It is four, not three.** The "three over-tiered members" figure in the charter
and the 7/30 audit is stale: user 1894 has already been cleaned, and 1860 and
1861 are in the group. Current count on live is **four**.

---

## The four, by name

All four hold **Pro (`looth3`) right now**. All are real people; three are
currently paying, on **Patreon**.

| WP id | Who | Patreon status | Actually pays | Patreon tier | Effect of the run |
|---|---|---|---|---|---|
| 1860 | **Grant Gong** — grant.gong@gmail.com | active | **5.00/month**, last paid 8 Aug | Looth-Lite | Pro → **Lite** |
| 1862 | **Michael Swisher** — Swisher Guitars, swisherguitars@gmail.com | active | **60.00/year**, last paid 17 Jul | Looth-Lite | Pro → **Lite** |
| 1884 | **John Catches** — Catches Guitars & Banjos, jscatches@icloud.com | active | **60.00/year**, last paid 5 Jun | Looth-Lite | Pro → **Lite** |
| 1861 | **Aron Bach** — Guitar Maker, aron@gabachmasterjoiner.com | **declined** | **nothing** — card declined 14 Aug; pledge would be 11.00 | **none recorded** | Pro → **free floor** |

Amounts are the pledge figures Patreon reports (`currently_entitled_amount_cents`),
converted to currency units.

---

## Why this is a real decision and not a tidy-up

The three paying members are all on **Looth-Lite, the lower tier**, and have been
receiving **Pro** since May or June purely because of the dead row. Correcting it
**takes Pro away from three people who are currently paying**. That is precisely
the grandfather-versus-correct ruling Ian has been holding, and it is a fair thing
to want to grandfather.

**Aron Bach needs handling separately** — he is the Aron whose revisit was already
owed for 8/18. He is not a lapsed freeloader by choice: his card was declined on
14 August. He should not be swept along with the other three.

---

## What the run would do

Delete the four `source='stripe'` rows. Then, per member:

- Grant Gong → Lite
- Michael Swisher → Lite
- John Catches → Lite
- Aron Bach → the free floor (`looth1`)

Nothing else moves. **No Patreon data is touched.**

### One trap for whoever writes the live command

Aron's Patreon row **exists but its tier is empty**, which is *not* the same as
having no row at all. Conflating those two states nearly demoted four paying
members on 8 August. His outcome must be read from the empty-tier case
deliberately, never assumed — see `trap-null-source-row-shadows-live-reader`.

---

## How this was measured

Read-only over `ssh live-ro`; nothing written.

- `lg_membership.lg_role_sources` — the four remaining `source='stripe'` rows and
  each member's Patreon row beside it.
- `lg_membership` counts of `customers` / `subscriptions` / `prices` / `products`
  — all zero, which is the evidence for "no live Stripe users".
- `looth_import.wp_users` and `wp_usermeta.wp_capabilities` — names and the roles
  they hold **today**, rather than the roles we believe they hold.
- `lg_membership.lg_patreon_members` — real pledge status, amount and last charge.
