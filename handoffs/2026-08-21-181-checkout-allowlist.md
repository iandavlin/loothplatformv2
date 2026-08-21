# Lane 181 — the cohort becomes real in the checkout path

Branch `181-checkout-allowlist`. Issue #181, Ian 8/21 decision box: **"Fix before go-live."**

## The hole, reproduced (not re-derived from the charter)

On dev2 **as served today**, anonymous, no cookies, no session, with a real price id
taken from the **public** `/billing/v1/products` catalogue:

```
POST /billing/v1/checkout   {"price_id":"price_1U6YbGHg6gcIV22bQuAPBln1","gift":false}
→ HTTP 200
  {"clientSecret":"cs_test_b1eAL6Yut17fjR1ADDCMCq23enJkvkRlkEZiJmVx2phtmprMQie9JijU50_secret_…"}
```

A live Stripe session, for anyone who asked. Paying it ran `Sync::customer` →
`UserProvisioner::findOrProvision`, which creates a WordPress user by email and grants
the tier.

## The four proofs keeper required

Measured end to end against **the branch's own Slim app** (see *How the after-state was
measured* below), same endpoint, same price id, same anonymous posture:

| # | Proof | Result |
|---|---|---|
| 1 | The exact request now refuses | **403** — `"Memberships are not open for sale yet…"` (was 200 + client secret) |
| 2 | A cohort member still completes checkout | **200** + a real `cs_test_…` client secret |
| 3 | A session minted before the list changed cannot provision | throws → `provision failed`; **no user, no bridge, no opinion, no Arbiter run** (gate 86 §C1) |
| 4 | An already-bridged member still sweeps | grants **and** retractions both land, driven through the **real** `Arbiter` (§C3/§C4) |

Also measured: a **non-cohort real member** → 403. An **anonymous gift** → 403. WordPress
unreachable → **503**, `audience=unknown`, with the *other* sentence.

## What was built

**One option, three states, one list.** `lgms_checkout_audience` = `off` / `allowlist` /
`on`, reading `lgms_stripe_lifecycle_allowlist` through `StripeLifecycle::inCohort()`.
No second list; gate 86 §D asserts no file names a cohort option of its own.

**Default is `allowlist` — enforcing** (keeper ruling (a)). The only flag on this rail
that does not default dark, deliberately: the enforcing state must be the state the
boxes run, or it is never exercised before the night it has to work.

**No admin bypass** (keeper ruling (b)). The header's `$caps['stripe_testgroup']` is
`manage_options || inCohort()` — right for a button, wrong for a fence. Ian is in the
dev2 cohort **by list** (id 1), not by privilege.

**Two halves, and only one can be routed around:**

- **MINT** — an honest, early refusal at all three doors. Slim `/billing/v1/checkout`
  gains a guard fed by a shared-secret REST probe; the WP `/me/checkout-session` door
  reads the option directly. Placed before any customer lookup or Stripe call, so a
  refusal costs nothing and creates nothing.
- **PROVISION** — the backstop, in `UserProvisioner::findOrProvision`, **one line below
  the existing-bridge early return**. Reads the option in-process with **no network**, so
  it fails CLOSED where the Slim probe cannot.

The placement is the design: below the bridge return, an already-bridged member is
untouched in every state. Above it, the fence would freeze real members the moment the
cohort narrowed — silently.

## Things found on the way that outlive this issue

### 1. The shared-secret REST channel is dead — and #150's probe with it

Measured on dev2 from 127.0.0.1 **with the correct secret**: every server-to-server route
in `lg-member-sync/v1` answers **401 `bb_rest_authorization_required`**. BuddyBoss's
`bb_restricate_rest_api` pre-empts the REST stack before any route's own
`permission_callback`, whenever `bb-enable-private-rest-apis` is `1` — it is, on dev2 and
live, and it is **re-armed by every DB reload**.

Consequences nobody had noticed:

- **#150's double-pay probe has been answering UNKNOWN on every call.** It fails open by
  design, so it has been silently blocking nobody.
- **The Slim app's post-checkout `/sync-customer` ping is dead.** The five-minute
  `Sync::all()` sweep has been covering for it, which is why nothing looked broken.

#181 exempts **exactly one route** — its own — through BuddyBoss's own documented
`bb_exclude_endpoints_from_restriction` hook. The route's shared-secret check still runs,
so it is not an auth bypass; it only decides which check does the refusing.
`/sync-customer`, `/patreon-standing` and `/send-gift-codes` are **deliberately still
401**. Widening an auth surface past what this lane needs is not a decision to make in
passing. **The one-line fix is the same shape** if keeper wants it as its own issue.

### 2. `Sync::customer`'s existing cohort fence was never the answer

It is real, but it sits **after** `findOrProvision` and behind a **different** flag
(`lgms_stripe_lifecycle`, off on every box). It has only ever withheld the ROLE — the
account, the bridge row, the welcome mail and `looth_tier_changed` all fired for a
stranger who paid. Worth knowing because the fence *looks* like it already covered this.

### 3. `lgms_identity_gate` is OFF on dev2

`UserProvisioner`'s own docblock says it **"MUST be ON before any member can reach Stripe
onboarding… item 1 on the launch checklist."** It is absent on dev2. Not this lane's to
flip — flagged.

## looth4 (Ian 8/21) — and where #183 inherits from

**The respect is the Arbiter's, not mine.** `Arbiter::sync` has an unconditional looth4
early-return and is the **only** writer of `wp_capabilities`; `RetractionSweep` is
detection-only and never runs the Arbiter. Gate 86 §I proves it with the **real** Arbiter
class (not a stub — a stub would only prove the stub's manners), paired with a liveness
leg showing the same sweep *does* demote a non-comp member. #181's fence never calls
`remove_role`/`add_role` at all (§I3).

**`LGMS\Membership\CompStanding` is the unexpired-comp predicate** keeper specified —
`holdsComp`, `expiresAt`, `isActiveComp`, `isExpiredComp`, `describe`. Read-only;
it enforces nothing and demotes nobody.

> ### ⚠️ #183 SHOULD INHERIT THIS CLASS RATHER THAN WRITE A SECOND ONE
> `lg-patreon-stripe-poller/src/Membership/CompStanding.php`. It is the **first and only**
> reference to `looth4_expires_at` in the monorepo. Two things #183 must decide, both
> deliberately left open here:
> 1. **The timezone.** Stored values are bare `Y-m-d H:i:s` with no offset
>    (`2026-07-11 15:25:00`). This class reads them in the site's timezone when WordPress
>    can supply one and UTC otherwise — safe for a predicate nobody acts on, a real
>    decision for one that demotes people. Note the recorded trap:
>    `wp_date('G', current_time('timestamp'))` double-shifts.
> 2. **Where enforcement hooks in.** The natural seat is `Arbiter::sync`'s looth4
>    early-return, which today protects expired and unexpired holders alike.

**An expired comp is still protected today**, measured and gated as such (§I9): nothing
enforces the date — the expiry plugin is not installed, not in mu-plugins, not in
`active_plugins`, no cron event mentions it. Ian ruled the two overdue accounts (1829,
1865) are left alone.

### The honest edge — Ian's question, not silently changed

A comp member who somehow reaches Stripe checkout **while outside the cohort is refused
like anyone else**. They lose nothing — no demotion, no role write, no opinion (§I6,
§I10) — and the operator notice now names their comp standing so they are not logged as a
stranger. **If Ian wants comp holders admitted to checkout**, that is a one-line change to
`CheckoutAudience::allowsUser` (`|| CompStanding::isActiveComp($uid)`) and a gate case.
It was not made, because "a comp member should be able to buy a membership they already
have for free" is a product question, not a bug.

## Gate 86

`tools/gates/checkout-audience-gate.php` — **156 assertions**, exit 0/1/3.
`tools/gates/checkout-audience-redfirst.py` — **23/23 mutations caught, 2/2 no-ops green.**

Number minted from `origin/main` (highest there was 85). Registered in `run-all.sh` and
`docs/CRAFT-STANDARD.md`.

**The red-first earned its keep twice**, both recorded in the gate footer:

- **§B8c was a genuine blind spot.** It compared the 503 sentence against a 403 whose
  message *WordPress* had supplied, so the guard's own two constants could be made
  identical and it still passed. §B8d now drives the fallback path.
- **One "mutation" changed no decision at all.** `if ( ! $user )` → `if ( false )` still
  refuses, because `$user->ID` on a bool is null and `allowsUser(0)` is false. The code
  was right twice over; the gate was innocent. Replaced with one that expresses the actual
  wrong decision.

**The gate also caught its own stub drifting from a real signature:**
`RoleSourceWriter::readAllForUser` returns a `source => tier` **map**, not a row list. My
first stub returned rows, and the real Arbiter then computed `looth1` for everyone — one
assertion failed and its neighbour passed *by coincidence*.

## Four neighbouring gates were reddened by the enforcing default — all fixed here

| File | Main | Branch before | Branch after |
|---|---|---|---|
| `double-pay-block-gate.php` (75) | 85 pass | **exit 255, fatal, no FAIL line** | 85 pass |
| `stripe-multi-tier-gate.php` (76) | 40 pass | 36 pass / 4 fail | 40 pass |
| `test-identity-gate.php` | 24 pass | **exit 255, fatal** | 24 pass |
| `test-checkout-session-metadata.php` | 20 pass | **exit 255, fatal** | 20 pass |

All four drive the WP checkout door or the provisioner. Each now loads the real
`CheckoutAudience` and pins it `off` **at every `$GLOBALS['OPTS']` reset**, never once at
module scope — a pin set once is silently gone by the first assertion, which is exactly how
gate 76 first read as four unrelated failures.

`test-checkout-session-metadata.php`'s own comment already warned this had happened twice
before (StripePrice, then PatreonStanding). Mine was the third; the comment now says so.

`stripe-testgroup-sweep-gate.php` (34d) and `test-soft-launch-allowlist.php` were
unaffected: 33 and 39 pass, unchanged.

## How the after-state was measured, and what was stubbed

The dev2 serve runs **main**, so the branch cannot be verified there. The branch's
`lg-stripe-billing` was staged to a scratch dir with the real `.env` and run under
`php -S` at the production base path.

> ⚠️ **The first attempt silently tested MAIN and returned 200 for everything.** `vendor`
> was symlinked to the serving checkout, and composer's PSR-4 `$baseDir` resolves through
> the symlink — so `LGSB\` mapped to **main's** `src`. Copying `vendor` for real fixed it.
> This is the repo's own recorded trap wearing new clothes; anyone re-running this must
> check `autoload_psr4.php` resolves to their own tree.

**Real:** the branch's whole Slim stack (routes, container, guard, probe, controller), the
real `.env`, real Stripe test mode, the real `CheckoutAudience` decision class, the real
dev2 cohort `[854,1887,1938,1953,2047,1]` read live from `wp_options`, and a real
email→user-id map read from the dev2 DB.

**Stubbed:** the WordPress *bootstrap* only — the audience endpoint runs the real
`CheckoutAudienceRestController::decide()` over the real cohort with `get_option` and
`get_user_by` shims, rather than booting WordPress. The controller's own logic is
separately proven against the real class in gate 86 §G6.

## Deploy

**One pull deploys both halves atomically — verified, not assumed.**
`/srv/lg-stripe-billing` → `~/loothplatformv2-clean/lg-stripe-billing` (symlink), and the
poller is `wp-content/mu-plugins/lg-patreon-stripe-poller` → the same checkout. There is
no deploy-order hazard between the Slim guard and the WordPress route.

**No env edit on any box.** The probe URL derives from `LGMS_SYNC_URL`.

**No `.local.php` to place**, unlike #165/#170/#180 — the switch is a wp_option.

On merge, dev2 enforces immediately (the option is absent → `allowlist`). Nothing to flip
to make it work; the flip that exists is `wp option update lgms_checkout_audience on`,
which is GA and is Ian's call at the end of the test phase.

## Files touched

**New (6):** `Membership/CheckoutAudience.php`, `Membership/CompStanding.php`,
`Wp/CheckoutAudienceRestController.php` (poller) · `Contracts/CheckoutAudienceProbe.php`,
`Adapters/HttpCheckoutAudienceProbe.php`, `Core/CheckoutAudienceGuard.php` (billing) ·
plus `tools/gates/checkout-audience-gate.php`, `tools/gates/checkout-audience-redfirst.py`,
this handoff.

**Edited:** poller `Plugin.php`, `Wp/CheckoutRestController.php`, `Wp/UserProvisioner.php`,
`Sync.php` (comment only) · billing `Contracts/SettingsStore.php`,
`Adapters/EnvSettingsStore.php`, `Http/Controllers/CheckoutController.php`,
`config/container.php` · `tools/gates/run-all.sh`, `double-pay-block-gate.php`,
`stripe-multi-tier-gate.php` · `deploy/remediation/test-identity-gate.php`,
`test-checkout-session-metadata.php` · `docs/FLAGS.md`, `docs/CRAFT-STANDARD.md`,
`docs/domains/MEMBERSHIP.md`.

**Beyond the approved plan, declared:** `CompStanding.php` (keeper's looth4 sharpening,
arrived mid-build), the BuddyBoss exemption (without it the probe could never get an
answer and the fence would refuse the cohort too), and the four neighbouring test files
(reddened by this lane, so this lane fixes them).

## Open / owed

- **#183 (comp expiry)** — inherit `CompStanding`; two decisions listed above.
- **The other three 401 routes** — reported, not opened. Keeper's call.
- **`lgms_identity_gate` is OFF on dev2** — its own docblock calls it launch-checklist
  item 1.
- **Ian's question:** should an unexpired comp holder be admitted to Stripe checkout?
  One line + a gate case if yes.
- Unchanged from #165: the **821–904px dead band** is still open and still Ian's call.
