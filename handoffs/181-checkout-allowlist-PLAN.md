# Lane 181 — PLAN (approval required before any code)

## Verified, cheaply, on dev2 just now — the hole is REAL and OPEN

    POST https://dev2.loothgroup.com/billing/v1/checkout
    {"price_id":"price_1U6YbGHg6gcIV22bQuAPBln1",
     "email":"lane181-probe@example.invalid","name":"Lane 181 Probe"}
    -> HTTP 200  {"clientSecret":"cs_test_b1HbhNe...","ui_mode":"custom"}

Anonymous. No account. No cohort. A real Stripe session, for an address that
cannot exist. `/billing/v1/products` is 200 to anon so the price id is public.
That is the whole issue, reproduced in one request.

## What I found that changes the shape of the fix

1. **There is already a cohort fence in the provision path — and it is in the
   wrong place and behind the wrong flag.** `Sync::customer()` (Sync.php:75)
   checks `inCohort`, but only while `lgms_stripe_lifecycle` is ON, and that
   flag is OFF on every box. Worse, it sits **AFTER**
   `UserProvisioner::findOrProvision()`, so even with the flag on a stranger
   who pays gets a **WP account minted**, a bridge row, and an onboarding
   notice — only the tier grant is skipped. "Cannot provision" is not what
   that code does.
2. **There is exactly ONE production caller of `findOrProvision`** (Sync.php:39).
   So the admission point is a single seam.
3. **The Slim app cannot read wp_options** (measured previously, restated in
   MEMBERSHIP.md). It must ask WordPress, exactly as `#150`'s
   `HttpPatreonStandingProbe` does.

## The build — three doors, one decider, one list

**Decider (WordPress side, new):** `LGMS\Membership\CheckoutAudience`
Reads ONE new wp_option `lgms_stripe_checkout_audience`, three states
`off | allowlist | on`, and answers `allows(int $wpUserId)` by delegating to
`StripeLifecycle::inCohort()` — **the same predicate the webhook fence and the
Sync fence already use. No second list, no second normalisation.**

**Door A — Slim `POST /billing/v1/checkout`** (the anon hole above).
New `CheckoutAudienceProbe` contract + `HttpCheckoutAudienceProbe` (loopback,
X-LGMS-Token, 2s cap, 127.0.0.1-pinned — the #150 shape) + `CheckoutAudienceGuard`.
Placed right beside the existing `DoublePayGuard` call, before any Stripe call.

**Door B — WP `POST /wp-json/lg-member-sync/v1/me/checkout-session`.**
Direct `get_current_user_id()` check. No probe needed, it is inside WordPress.

**Door C — provision.** The fence moves to the real admission point: inside
`UserProvisioner::findOrProvision()`, immediately after the bridge lookup misses
and **before** anything is minted. Already-bridged customers are untouched, so an
existing member's sweep — grants AND retractions — keeps working. A session
minted before the list changed reaches this and is refused: **no user, no bridge,
no grant.**

### Two decisions I want ruled, because they are not free

**(a) DEFAULT = `allowlist` (enforcing), not off.** Absent / empty / malformed
reads as `allowlist`, the same fail-safe-closed posture
`StripeLifecycle::allowlist()` already takes. This is a deliberate departure from
"merge behind a flag defaulted OFF" and the charter asks for it: the enforcing
state must be the one dev2 runs. **Consequence, stated plainly:** dev2's
`lgms_stripe_lifecycle_allowlist` is currently UNSET, and unset = nobody. On
merge, dev2 Stripe checkout closes until the cohort is populated (one action:
Settings -> LG Member Sync -> Stripe Test Group). Live is unaffected today — zero
Stripe customers, empty catalogue. Alternative if you would rather: default
`off`, and the flip becomes a go-live checklist item that can be forgotten.

**(b) NO ADMIN BYPASS. The list is the list.** #170's header pill uses
`manage_options || inCohort`. I am NOT copying that here, for two reasons: the
two fences already in this path (`StripeLifecycle::applyConfirmed`,
`Sync::customer`) use raw `inCohort` and a third predicate one line away is how
fences drift; and MEMBERSHIP.md's own warning — *"an admin passes and sees
nothing wrong, the person most likely to check is the one person who cannot see
the failure"* — is worse on a money door than on a button. **Ian: you will need
to add yourself to the Stripe Test Group to buy anything.** That is one dash
click and it makes your test identical to a tester's.

### Unknown answers

The Slim probe failing (WordPress silent, route unreachable, secret unset) is
**UNKNOWN, and unknown REFUSES** — the opposite of #150's fail-open, on purpose:
#150 protects a member from a double charge and a hiccup must not stop sales;
this protects an unlaunched product and a hiccup must not open it. It refuses
with **503 and "try again in a moment"**, deliberately a different status and a
different sentence from the **403** cohort refusal, so a log line tells you which
happened. There is **no env-var escape valve** for this probe — the wp_option is
the only switch.

### Gifts

Fenced too. During a soft launch a stranger buying anything is a stranger
transacting; a cohort tester buying a gift for an outsider still works at
purchase and is refused at the outsider's provision, which is the coherent
result.

## Gate 86 (minted from MAIN: run-all max 84, CRAFT max 85, siblings max 84)

`tools/gates/checkout-audience-gate.php` + `checkout-audience-redfirst.sh`,
following gate 75's PHP-harness shape. Asserts, per state absent/off/allowlist/on:
anon-with-a-REAL-price-id is refused at the API; a cohort member completes;
a session minted for a non-cohort email cannot provision (and mints NO WP user);
an admin not on the list is refused; unknown-probe refuses 503 not 403; `on`
grants everybody; `off` is byte-identical to today. Red-first driver mutates each
assertion and proves it goes red, plus two no-op mutations that must stay green.

## Files I expect to touch (guessing wide)

New: `lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php`,
`lg-patreon-stripe-poller/src/Wp/CheckoutAudienceRestController.php`,
`lg-stripe-billing/src/Contracts/CheckoutAudienceProbe.php`,
`lg-stripe-billing/src/Adapters/HttpCheckoutAudienceProbe.php`,
`lg-stripe-billing/src/Core/CheckoutAudienceGuard.php`,
`tools/gates/checkout-audience-gate.php`, `tools/gates/checkout-audience-redfirst.sh`,
`handoffs/181-checkout-allowlist.md`, possibly
`lg-stripe-billing/tests/checkout-audience-probe-drive.php`.

Edited: `lg-patreon-stripe-poller/src/Plugin.php`,
`lg-patreon-stripe-poller/src/Wp/CheckoutRestController.php`,
`lg-patreon-stripe-poller/src/Wp/UserProvisioner.php`,
`lg-patreon-stripe-poller/src/Sync.php` (comment only — the old fence's
relationship to the new one), `lg-stripe-billing/src/Http/Controllers/CheckoutController.php`,
`lg-stripe-billing/config/container.php`,
`lg-stripe-billing/src/Contracts/SettingsStore.php`,
`lg-stripe-billing/src/Adapters/EnvSettingsStore.php`,
`docs/FLAGS.md`, `docs/CRAFT-STANDARD.md`, `docs/domains/MEMBERSHIP.md`,
`tools/gates/run-all.sh`.

Not touched: any page layer (`lgjoin.php`, `router.php`, membership-pages config)
— that is lane 180's, and the charter puts enforcement below the page.

## Noticed, not fixing (flagging now so it is not a surprise)

`/lgjoin/` **creates the WP account BEFORE it calls checkout** (lgjoin.php
~line 955: it POSTs to the auth/signup URL, then to `/v1/checkout`). So a
stranger walking the page will have an account minted and then be refused at
the money door, leaving an orphan `looth1` account. Pre-existing ordering, and
on live lane 180's work is what keeps anon off that page at all. Out of scope
here; worth an issue.
