# Stripe soft-launch allowlist — design spec (for the stripe seat)

Ian, 2026-08-11: "generate a whitelist that we can soft launch on live … take
some select members and transition them to test on live." Cohort = **hand-picked
by Ian** (keeper resolves names → WP user IDs). Build owner = **stripe seat**.

The lifecycle core is merged flag-OFF (`0ffb32f`) and single-tier (`looth3`). This
adds ONE gate so a live flag-flip touches only a named cohort, not the whole base.

## The mechanism

- **New option `lgms_stripe_lifecycle_allowlist`** — an array of **WP user IDs**.
  Per-box (WP option), so dev2 and live hold their own lists.
- **Gate location:** inside the lifecycle AFTER identity resolution (bridge →
  IdentityMatcher gives the WP user id) and BEFORE any membership mutation —
  i.e. at the top of `applyOpinion()` (or wherever the resolved `$wpUserId` first
  exists and before the Arbiter/EntitlementRepo write).
- **Semantics:**
  - resolved user IS in the list → normal lifecycle (grant/revoke `looth3`).
  - resolved user NOT in the list → **acknowledge the webhook 200** (so Stripe
    does not retry-storm) but make **no** membership change; journal
    `skipped: not in soft-launch cohort (uid=N)`.
  - **empty/unset list = CLOSED (nobody).** Flipping lifecycle ON with an empty
    allowlist must be a proven no-op — this is the fail-safe and must be GATED,
    not just coded (the OFF-state-must-be-asserted rule).
- **Add/remove a tester = edit the option.** No code redeploy. Pulling a member
  mid-test is removing their id from the array.

## Interlock ordering (unchanged, allowlist is additive)

`lgms_identity_gate` ON → `lgms_stripe_lifecycle` ON → and now the allowlist
governs WHO inside that. All three must line up for a member to transition:
gate armed, lifecycle on, member on the list.

## Admin surface

The list needs a non-DB-surgery way to edit on live. Options for the seat:
- extend `Admin.php` with a textarea of ids (simplest, matches existing admin), OR
- a `wp option update lgms_stripe_lifecycle_allowlist` documented runbook line.
Prefer the Admin.php surface so Ian edits it himself; whichever, DOCUMENT it in
the runbook and FLAGS register.

## Red-first gate (the acceptance bar)

A new gate, allocated number by keeper, that asserts BOTH sides on the real stack:
1. a cohort member's `active` event → `looth3` granted (the existing e2e path).
2. a NON-cohort member's byte-identical event → **no role change, journal shows
   skip** — reddened first (must fail before the gate exists), then green.
3. empty allowlist + lifecycle ON → the active event is a no-op for everyone.
Wire into `e2e-stripe-lifecycle-dev2.php` so it runs on the real WP/MySQL/Arbiter.

## Deliberately NOT in this slice

- No change to identity/confirm/dedupe/retraction — all as merged.
- Still no `payment_source` writes (dual-holder guards await Ian's ruling).
- No member-facing join page (separate charter item).

## The initial live cohort

Keeper generates it from Ian's hand-picked names/emails, resolved to LIVE WP user
ids (live DB `looth_import`, NOT dev2 — ids differ per box). Delivered as the exact
`update_option` / Admin-textarea value for Ian to set on live at flip time.
Everything flag-OFF and cohort-empty until Ian runs the live flip himself.
