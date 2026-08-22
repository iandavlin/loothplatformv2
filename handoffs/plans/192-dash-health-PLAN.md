# 192 — the membership health panel

Lane `192-dash-health`, issue #192, split out of #190. One new **Health** tab on
the existing top-level **LG Member Sync** dash. Read-only. No new screen.

## What I measured before planning (all of it, today, both boxes)

Every number below is measured, not assumed. This is the panel's job, done by
hand once, which is the argument for building it.

### dev2 — one real disagreement, one dead channel

| thing | WordPress | billing app (`/srv/lg-stripe-billing/.env`) | verdict |
|---|---|---|---|
| shared secret | present, 64 ch | present, 64 ch | **AGREE** (same sha256) |
| Stripe webhook secret | **ABSENT** | present, 38 ch, `whsec_…` | **DISAGREE** |
| Stripe secret key | present, `sk_test_` | present, `sk_test_` | both **test**, different keys |
| sync URL | — | `https://dev2.loothgroup.com/…/sync-customer` | host correct (the dead-host bug is fixed) |
| checkout audience | option absent ⇒ `allowlist` | — | fail-closed default, correct |
| cohort | 6 ids `[854,1887,1938,1953,2047,1]` | — | populated |

And the channel, probed over loopback with the real shared secret:

- `POST …/v1/checkout-audience` → **200**, our own JSON. (#181's exemption works.)
- `POST …/v1/sync-customer` → **401 `bb_rest_authorization_required`**.
  BuddyBoss is still eating the route the billing app calls after every
  checkout. Reproduced just now.

### LIVE — nothing is configured yet

- `lgms_shared_secret` — **ABSENT.** Still, today. (Charter was right.)
- `lgms_stripe_webhook_secret` — ABSENT.
- `lgms_stripe_secret_key` — ABSENT.
- `lgms_checkout_audience` — absent ⇒ `allowlist` (fail-closed, correct).
- `lgms_stripe_lifecycle_allowlist` — **ABSENT: the cohort is EMPTY on live.**
- `lgms_stripe_pages_live` — 0.

⚠️ **Live and dev2 are not the same shape and the panel must not assume they
are.** On dev2 `/srv/lg-stripe-billing` is a symlink into the serving checkout
and its `.env` is world-readable. **On live it is a real directory owned by
`www-data` with `.env` at mode 0640.** Live's WordPress pool user `looth-dev` is
in group `www-data`, so it *should* read — but that is a permission I cannot
test from here, and it is exactly the thing the panel must report honestly
instead of rendering a blank.

## The design

### Q1 — are webhooks arriving? **Nothing records this today.**

`WebhookController::handle()` verifies, dispatches and returns. It writes no
receipt, so there is no data source at all. `audit_log` in `lg_membership` is
**empty on dev2 (0 rows)**, has no foreign key on `subject_id`, already carries
`'webhook'` in its own `actor_type` comment, and has an index on
`(action, created_at)` — the exact query a "last event" read needs. So:

- record a receipt there on every **verified** event (Stripe-signed, cannot be
  spammed, low volume);
- record **signature failures** too, because a rising signature-failure count
  next to a silent success count *is* the mismatched-secret failure mode showing
  itself. Rate-limited to one row per 5 minutes, because that endpoint is
  unauthenticated and anyone can POST rubbish at it;
- wrapped in try/catch and swallowed. A receipt must never change what Stripe
  gets back.

**No migration, therefore no live DDL for Ian to run.**

"Never" will read loudly, and will say *why* it might legitimately be never —
that recording started with this change — rather than implying breakage.

### Q2 — do the two halves agree?

Read `/srv/lg-stripe-billing/.env` from the dash and compare `sha256` of the
trimmed values against the WP options. **Direct file read, deliberately, not an
HTTP call to the app** — the panel has to work when every channel is broken,
which is the one moment it matters.

Four distinct states, never conflated: *file missing* / *file present but
unreadable* / *key absent* / *present, agree or disagree*.

Shown: present-or-absent, length, agree-or-disagree. **No value, no fingerprint,
no prefix.** Comparison happens in code.

### Q3 — test or live mode

`STRIPE_MODE` plus the `sk_test_` / `sk_live_` prefix on **both** sides. Flags
mode disagreement between the halves. Different keys in the same mode is
reported as fact, not as an error — that is legitimate.

### Q4 — does the catalogue resolve to tiers

`StripePrice::tiers()` and `configuredTiers()` already exist. The panel adds the
count that is missing: active membership products with **no `ref`**, which grant
nothing and make checkout refuse with *"not mapped to a membership tier"*.

### Q5 — audience and cohort

`CheckoutAudience` state, cohort size, and — one compact line — the page-gate
partners `lgms_stripe_pages_live` / `lgms_stripe_testgroup_pages`, because
#165 and #170 both recorded that a mismatched pair means a button wired
perfectly that lands nowhere.

### Q6 — the channel (failure #1 and #3, which Q2 does not cover)

- the app's `LGMS_SYNC_URL` host vs this site's `home_url()` — a dead host is
  the exact bug that cost an hour;
- one loopback `POST` to the exempted `checkout-audience` route with the shared
  secret, 3s timeout: ours, BuddyBoss's 401, or unreachable — each named;
- `bb-enable-private-rest-apis` reported as the cause when the 401 appears;
- `GET /billing/health` (already exists, unauthenticated) for "is the app up".

Timeouts and failures render as **UNKNOWN — could not reach**, never as green
and never as blank.

## Verification

Gate, red-first, proving each answer against a **deliberately broken** state,
not only a healthy one: secrets mismatched, one side absent, env file missing,
env file unreadable, sync URL pointed at a dead host, cohort emptied, catalogue
with an unmapped product, webhook table empty, webhook stale. A panel that only
goes green is worth nothing.

⚠️ Every source assertion goes through PHP's **tokenizer**, not a regex — #190's
gate matched its own explanatory prose twice. And the placement assertions
assert **unconditionally**; #190's §G stopped watching the moment the thing it
watched broke.

Gates run individually (`run-all.sh` exits early on main's gate-72 red, #175).

## Flag

**None**, matching #190 / #148 / #183: this is dash-only plus a swallowed
INSERT on a path that already runs. Nothing member-facing changes. I will prove
the no-op rather than argue it.

## Files I expect to touch (guessing wide)

**New**
- `lg-patreon-stripe-poller/src/Membership/Health.php` — all reads, no output
- `lg-patreon-stripe-poller/src/HealthPanel.php` — renders (follows `TesterUnlockPanel`)
- `lg-stripe-billing/src/Core/WebhookReceipts.php` — the recorder
- `tools/gates/membership-health-gate.php`  — **gate 91** (minted by keeper)
- `tools/gates/membership-health-redfirst.py`
- `handoffs/2026-08-21-192-dash-health.md`

**Edited**
- `lg-patreon-stripe-poller/src/Admin.php` — tab registration + dispatch
- `lg-stripe-billing/src/Http/Controllers/WebhookController.php`
- `lg-stripe-billing/config/container.php` — only if DI wiring is needed
- `tools/gates/run-all.sh`
- `docs/CRAFT-STANDARD.md` — gate table row
- `docs/domains/MEMBERSHIP.md` — required in the same commit

**Not touched:** any member-facing file, any config symlinked to a live service,
the serving checkout.

## Open

Nothing blocking. Keeper minted **gate 91** (`membership-health-gate.php`).
