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
    sudo -u looth-dev wp --path=/var/www/dev eval "update_option('lgms_shared_secret', '$S'); echo 'set, len=' . strlen((string)get_option('lgms_shared_secret','')) . \"\n\";"

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

### 4c. How to create each one (Ian confirmed test mode 8/21, so live is empty)

**Per PRODUCT**, set Metadata:

    kind = membership
    ref  = looth2   (Lite)   |   looth3   (Pro)

Ian already does this in the sandbox — `prod_RerXcVx8RqqS0P` carries
`kind=membership`, `ref=looth2`.

**Per PRICE**, set Metadata only where it applies:

    region_tag = regional_a | regional_b     (omit entirely for standard)
    priority   = 100

⚠️ **METADATA ALONE DOES NOT MAP THE TIER.** `ProductSyncHandler` syncs only
`name` and `active` from product events — *"`ref` and `kind` are set once
manually and are never overwritten by webhook events"*. So the Stripe metadata
is documentation; the row in our `products` table is the mapping. **Confirm each
live product's `ref` on our side after the webhook has created the row**, or
checkout will refuse the price with "not mapped to a membership tier".

Price metadata IS honoured on sync: `region_tag`, `priority`,
`grants_duration_days`, `lgms_discount_scale`, `lgms_trial_days`.

**Create ONLY the twelve wanted prices.** dev2's catalogue carries strays that
prove the point — two active $5/month (Aug 20 and Apr 29), both $55 and $60
yearly, and a $66 with no billing period at all. Harmless in a sandbox; on live
a stray active price is something a checkout can be pointed at.

---

## 5. The allowlist — the door (Ian)

**Testers no longer need a WordPress account first (#193).** List their email
addresses and they join, pay, and get their account created by the join —
which is the journey a real new member takes at go-live, and the reason Ian
asked: *"I thought the whitelist would have them generating a wp-user like a
normal new member join."*

Easiest: **wp-admin → LG Member Sync → Testers**, type the address, confirm.
An address that matches no account is offered for listing instead of refused.

By hand — **ids and addresses go in the SAME array**:

    sudo -u looth-dev wp --path=/var/www/dev eval '
    $ids = [/* tester user ids */];
    update_option("lgms_stripe_lifecycle_allowlist", array_values(array_unique(array_map("intval", $ids))));
    echo count($ids) . " on the list\n";'
    sudo -u looth-dev wp --path=/var/www/dev eval '
    $list = [/* tester user ids */, /* "tester@example.com", ... */];
    update_option("lgms_stripe_lifecycle_allowlist", array_values($list));
    echo count($list) . " on the list\n";'

⚠️ **Ian must be on it himself** — #181 has no admin bypass, deliberately, so
that the person most likely to check can actually see the fence fail.

**Why it is the only door:** checkout enforcement (#181) defaults to
`allowlist` — absent config reads as enforcing. **An address that is not on the
list is refused whether or not it has an account**, and removing an address
shuts every door for it immediately, including a checkout already started.

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

---

# THE LIVE-TESTING CHECKLIST (Ian asked for it 8/21 night)

Work top to bottom. Keeper verifies after each from the read-only eye.
**Every command here runs on LIVE, and every one of them is Ian's.**

## A. Make the tester link storable  ▢

The dash tells you this itself. One-time root step:

    sudo install -d -o looth-dev -g looth-dev -m 755 /srv/lg-shared-state

Then **Testers tab → Create the tester link**. Copy it somewhere safe — it is
shown once and stored as a hash. Rotate remakes it and kills the old one.

## B. The shared secret  ▢  ← WITHOUT THIS, CHECKOUT REFUSES EVERYONE

Still ABSENT on live (measured 8/21). Copy the billing app's value into WP:

    S=$(grep '^LGMS_SHARED_SECRET=' /srv/lg-stripe-billing/.env | cut -d= -f2- | tr -d '"'"'"'')
    sudo -u looth-dev wp --path=/var/www/dev eval "update_option('lgms_shared_secret', '$S'); echo strlen((string)get_option('lgms_shared_secret','')) . \"\n\";"

## C. Confirm the app is in LIVE mode and points at live  ▢

    grep -E '^(STRIPE_SECRET_KEY|STRIPE_PUBLISHABLE_KEY|LGMS_SYNC_URL)=' /srv/lg-stripe-billing/.env \
      | sed -E 's/=(sk|pk)_live.*/= LIVE key/; s/=(sk|pk)_test.*/= TEST KEY  <-- WRONG/; s#=(https?://[^/]+).*#= \1#'

Two LIVE keys, and a sync URL on `loothgroup.com`. **A test key here means no
real money can be taken.** Live keys were parked at
`/srv/lg-stripe-billing.bak-20260816-183554`.

## D. The catalogue, in Stripe  ▢

**Copy to live mode** on each of the six products (Lite, Lite Regional A, Lite
Regional B, Pro, Pro Regional A, Pro Regional B) — prices ride along. Then
**PRUNE**: dev2's Lite carries ten prices including strays ($2, $60, $66, a
duplicate $5). Keep only the twelve wanted. **Copy once per product** — Stripe
will happily duplicate.

Add a **live-mode webhook** at `https://loothgroup.com/billing/v1/webhook` and
put its signing secret in the app's `.env`.

**Optional, for outside testers:** a temporary $1/month and $1/year price.
Archive them and CANCEL those subscriptions afterwards — Stripe grandfathers
people onto the price they joined at.

## E. Map the tiers  ▢

After the webhook creates the rows, confirm each live product's `ref`
(`looth2`/`looth3`) on our side. **Stripe never sets this** — a price with no
`ref` grants nothing and checkout refuses it.

## F. The tester list — **list their email addresses**  ▢

**No tester needs a WordPress account first (#193).** Put their address on the
list; the account is created by their join, exactly as it will be for a real
new member. **Ian must be on it himself** — #181 has no admin bypass.

wp-admin → LG Member Sync → **Testers** → type the address → confirm. Or by
hand, ids and addresses in one array:

    sudo -u looth-dev wp --path=/var/www/dev eval '
    $ids = [/* tester user ids */];
    update_option("lgms_stripe_lifecycle_allowlist", array_values(array_unique(array_map("intval", $ids))));
    echo count($ids) . " on the list\n";'
    sudo -u looth-dev wp --path=/var/www/dev eval '
    $list = [/* tester user ids */, /* "tester@example.com", ... */];
    update_option("lgms_stripe_lifecycle_allowlist", array_values($list));
    echo count($list) . " on the list\n";'

⚠️ **The account is made at SIGN-UP, by `/lgjoin/`'s own sign-in call — which
#193 had to unblock.** BuddyBoss's blanket `bb-enable-private-rest-apis` was
401ing `POST /wp-json/lg-member-sync/v1/auth` for anonymous visitors on both
boxes, so a listed tester would type their address, press Continue and be told
*"Sign-in failed"*. That route is now exempted (its own throttles, password
check and validation untouched). **If a tester reports "Sign-in failed", check
that first** — and note the same 401 was why a gift recipient with no account
could not redeem one either.

## G. Open the door for them  ▢

Header/join state to `allowlist` (`lgms_stripe_testgroup_pages`), leaving
`lgms_stripe_pages_live` at `0` so the public still sees Patreon.
⚠️ **Verify as a listed NON-ADMIN member** — an admin passes locks a tester may
not.

## H. The first real charge, by Ian  ▢

One monthly and one annual, real card, as a listed tester. Confirm the role
lands, the receipt arrives, the member sees their tier. **Then refund from
Stripe and confirm the role is retracted.** The retraction half is the one that
gets skipped, and it is the one that matters.

## I. Then invite the testers  ▢

Small group, short window. Their posts and purchases are real.

## Optional, any time

- **Comp timers on live** — `platform/config/comp-expiry.local.php` with
  `enabled => true`, `effective_from => '2026-08-21'` (nothing already overdue
  is touched).
- **Upload cap** — Settings → EWWW Image Optimizer → Resize. Limits are `0`
  today, i.e. none. 2560px wide + delete-originals caps what you STORE.
