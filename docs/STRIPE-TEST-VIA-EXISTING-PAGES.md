# Stripe soft-launch = the EXISTING member pages, whitelist-unlocked

Ian, 2026-08-14, verbatim: "Can we do tokens for visiting the stripe membership
stuff and have all features available to the user that is white listed instead
of a bespoke page? I'd really like to test stuff like regional pricing and
gifts etc. We really are only switching to a single tier. Much has already
been built."

**This SUPERSEDES the bespoke private-page plan** (which died un-committed in
the 8/12 shutdown anyway — do not rebuild it). The soft launch now reuses the
already-built member surfaces: [lg_join], [lg_gift], [lg_redeem_gift],
[lg_manage_subscription], [lg_refund_request], [lg_regional_fail],
[lg_subscription_success].

## The shape

1. **Visibility gate = the Test Group list** (lgms_stripe_lifecycle_allowlist,
   same option, same empty=closed semantics). A logged-in member ON the list
   sees the full join/gift/regional experience; everyone else gets the
   invitation-only state (or the pages' existing hidden/404 behavior). The
   LIST is the key; being logged-in is the identity.
2. **Tokens (Ian's word):** optional layer — a claim-style tokened invite link
   (the dev-gate /claim pattern) that a listed member can follow pre-login.
   Seat decides whether login+list alone suffices for the test; simplest that
   works wins. Tokens must never BYPASS the list — at most they route to it.
3. **Every grant path keeps the allowlist check** — join, gift redemption,
   regional: identical fail-safe (non-listed = journaled no-op 200).
   ⚠ Gift care point: during the test, gift RECIPIENTS must also be listed
   (or gifting constrained to listed recipients) — a gift to an unlisted
   member would pay Stripe and grant nothing.
4. **Single tier reality check:** audit each built page against single-tier —
   tier pickers collapse to one option, copy updated (plain English: Ian-rule).
   Regional pricing is COMPATIBLE with the single-tier engine: many regional
   prices, one granted tier (lifecycle grants TIER const regardless of price).
   Confirm price_id handling accepts the regional price set (webhook side).
5. These pages come from the pre-freeze era: verify each drives the NEW
   lifecycle (identity-stamped checkout → webhook → allowlist → grant), not
   the frozen legacy leg. Anything wired to the frozen leg gets re-pointed,
   not unfrozen.

## Sequence unchanged otherwise

Test-mode dress rehearsal (Ian, fake card, full loop incl. a regional price and
a gift) → price decision (his, before real money) → live flips per the standing
activation checklist. Aron revisit 8/18 still owed. Key rotation still owed by
Ian (working master key to his charges-enabled account acct_1LJOi5Hg6gcIV22b).
