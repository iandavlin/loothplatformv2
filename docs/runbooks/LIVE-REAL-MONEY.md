# TAKING REAL MONEY ON LIVE, ALLOWLISTED ONLY

Ian, 2026-08-21: *"I need to prepare to take real money on live with the
whitelisted users."* This is the ordered pack. **Every live write is Ian's** —
keeper verifies between steps from the read-only eye.

Written against what was MEASURED on live 2026-08-21, not against the design.

---

## 0. What is true on live right now (keeper measured, 8/21)

| thing | state | consequence |
|---|---|---|
| today's code | **NOT deployed** — live sits on last night's commit | step 1 |
| `lgms_shared_secret` | **ABSENT** | checkout would refuse EVERYONE (fail-closed) |
| tester allowlist | **absent/empty** | nobody can buy — the safe state |
| `lgms_stripe_pages_live` | `0` | join pages closed to the public — correct |
| Stripe catalogue | **empty** (0 products/prices/customers, measured 8/20) | nothing is purchasable yet |
| billing app `.env` | **unreadable to keeper** | Ian must confirm mode + sync URL |
| chunker role caps | Ian applying 128MB | unrelated to money, same session |

**The two that would bite silently:** the absent shared secret, and the
billing app's sync URL — on dev2 it pointed at `dev.loothgroup.com`, a host
that does not exist, which had been making the double-pay probe answer UNKNOWN
on every call. Live's copy is unverified.

---

## 1. Deploy (Ian)

    lg-deploy

Then the one manual coupling if any NEW mu-plugin arrived — keeper names it at
the time; a plain pull does not create symlinks.

**Keeper verifies:** live HEAD == main, nginx reloaded if confs changed.

---

## 2. Confirm the billing app is in LIVE mode and points at live (Ian)

    grep -E '^(STRIPE_SECRET_KEY|STRIPE_PUBLISHABLE_KEY|LGMS_SYNC_URL)=' /srv/lg-stripe-billing/.env \
      | sed -E 's/=(sk|pk)_live.*/= LIVE key/; s/=(sk|pk)_test.*/= TEST KEY  <-- WRONG FOR LIVE/; s#=(https?://[^/]+).*#= \1#'

**Expect:** two LIVE keys, and a sync URL on `loothgroup.com`.
**If either is wrong, stop** — a test key here means no real money can be
taken, and a wrong host means the membership sync silently degrades.
Live keys were parked at `/srv/lg-stripe-billing.bak-20260816-183554`.

---

## 3. Shared secret (Ian) — WITHOUT THIS, CHECKOUT REFUSES EVERYONE

Read the billing app's value and write the same into WordPress:

    S=$(grep '^LGMS_SHARED_SECRET=' /srv/lg-stripe-billing/.env | cut -d= -f2- | tr -d '"'"'"'')
    echo "len=${#S}"    # sanity only, never paste the value anywhere
    sudo -u looth-live wp --path=/var/www/dev eval "update_option('lgms_shared_secret', '$S'); echo 'set, len=' . strlen((string)get_option('lgms_shared_secret','')) . \"\n\";"

**Keeper verifies:** the audience route answers 200 instead of 401.

---

## 4. Live catalogue in LIVE mode (Ian, Stripe dashboard)

Create the products and prices **in live mode** — test-mode IDs cannot be
copied, they do not exist in live.

Ian's ruled prices (8/20): **LITE $5/mo + $55/yr · PRO $11/mo + $120/yr.**

Then point a **live-mode webhook** at the live site's billing endpoint and put
its signing secret in the app's `.env`.

**Keeper verifies:** products/prices resolve, and the tier mapping answers for
each price id.

---

## 4b. The catalogue to recreate, exactly (Ian ruled the FULL set, 8/21)

Measured out of dev2's own tables 8/21 — this IS the sandbox you already built,
so live day is copy-work, not design work.

**SIX products, not two.** Regional pricing is carried on the PRODUCT
(`products.region_tag`), and routing picks a different product rather than a
different price on the same one.

| tier ref | product name | region_tag | monthly | yearly |
|---|---|---|---|---|
| `looth2` | Looth LITE | *(none)* | $5.00 | $55.00 |
| `looth2` | Looth LITE — Regional A | `regional_a` | $4.00 | $30.00 |
| `looth2` | Looth LITE — Regional B | `regional_b` | $3.00 | $20.00 |
| `looth3` | Looth PRO | *(none)* | $11.00 | $120.00 |
| `looth3` | Looth PRO — Regional A | `regional_a` | $8.00 | $65.00 |
| `looth3` | Looth PRO — Regional B | `regional_b` | $6.00 | $40.00 |

All USD, priority 100, recurring. Twelve prices in total.

⚠️ **THE TIER LINK IS OURS, NOT STRIPE'S.** `products.ref` (`looth2`/`looth3`)
is what makes a price mean a membership; Stripe only syncs `name` and `active`
down. A price created in Stripe and left unmapped **grants nothing** and
checkout refuses it with "not mapped to a membership tier" — which is precisely
what has been protecting live while the catalogue was empty.

⚠️ **NONE of the dev2 IDs transfer.** They are test-mode objects
(`prod_RerXcVx8RqqS0P`, `price_1U6YbG…`); live mode issues its own. Record the
live IDs as they are created.

**Housekeeping while in there:** two stale prices are still `active=1` on dev2
with no interval — $66.00 and $145.00, left from early setup. Do not recreate
them, and deactivate them on dev2 so nothing can select them.

---

## 5. The allowlist — the door (Ian)

Testers must each have a WordPress account, then:

    sudo -u looth-live wp --path=/var/www/dev eval '
    $ids = [/* tester user ids */];
    update_option("lgms_stripe_lifecycle_allowlist", array_values(array_unique(array_map("intval", $ids))));
    echo count($ids) . " on the list\n";'

**Why it is the only door:** checkout enforcement (#181) defaults to
`allowlist` — absent config reads as enforcing — and **there is deliberately NO
admin bypass**, so Ian must be on the list to buy. An email with no account is
refused.

---

## 6. Flags, in this order (Ian) — flip, verify, next

1. **Join pages for testers** — the header/join state to `allowlist`
   (`lgms_stripe_testgroup_pages`), leaving `lgms_stripe_pages_live` at `0`.
2. **Comp timers** — `platform/config/comp-expiry.local.php` with
   `enabled => true` and `effective_from => '2026-08-21'`, so nothing already
   overdue is touched.
3. Anything else only when its own verify passes.

⚠️ **Verify a flip as a listed NON-ADMIN member.** An admin passes locks a
tester may not — the failure an admin check cannot see.

---

## 7. The first real charge

Do it deliberately, with a real card, as a listed tester:

- one **monthly** and one **annual**, so both price shapes are proven;
- confirm the role lands, the receipt arrives, and the member sees their tier;
- then **refund from the Stripe dashboard** and confirm the role is retracted.

**The retraction half is the one that gets skipped.** A grant that cannot be
undone is how a refunded member keeps paid access.

---

## Rollbacks

- **Close the doors instantly:** set the checkout audience to `off`, or empty
  the allowlist. Both stop new purchases without touching code.
- **Code:** revert the merge on main and `lg-deploy` — every flag ships OFF, so
  a revert is a return to today's behaviour.
- **Money:** refunds are Stripe-side and always available; the retraction path
  is what must be proven working BEFORE volume, not after.
