# HANDOFF — audit the Patreon poller / onboarding: is it broken, and can it be smaller?

**Written 2026-07-29. Serve/main at 0995f2b.** Start a fresh session here. This
is an AUDIT brief, not a fix brief — the goal is to establish what is true, then
say whether the code should shrink. Do not change poller code until the audit is
written and Ian has read it.

## Why this exists

A member had a public profile URL of `/u/hxn7djggwx` — their email prefix.
Pulling that thread found **29 members with two accounts each**, and a poller
whose identity logic nobody could describe with confidence, spread across four
files totalling ~4,200 lines inside a ~24,700-line plugin. The keeper working it
gave three contradictory answers in one session by grepping and guessing instead
of reading. **This handoff exists so the next session reads first and concludes
once.**

## What is believed true right now (VERIFY every line — do not inherit it)

- **Every user is onboarded through Patreon.** Stripe provisioning has never
  created a user on this platform — Ian, stated plainly. `wp_wc_stripe_customer_*`
  meta on ~1,278 users is WooCommerce/old-stack residue, not our provisioner.
- **Members log in with email + password.** Email is a LOGIN CREDENTIAL, not just
  a lookup key. **A member's WP email must equal their current Patreon email** —
  it is both the login and the Patreon-verification key. This single fact is the
  root of the duplicate problem.
- **The duplicates were minted by the OLD poller** keying on email: when a
  patron's Patreon email did not match their existing WP account, it created a
  NEW account carrying the Patreon email instead of updating the existing one.
- **The Patreon path is now believed fixed** (since ~2026-06-25): the sync engine
  looks up by Patreon ID first, then email, backfills the ID, mirrors the email.
  Measured signal: **zero full-name duplicates minted since 2026-04-30** (checked
  2026-07-29). Causal link between the fix date and the stop is NOT established —
  it could be coincidence; a low-traffic 8 weeks would look the same.
- The 29 existing duplicates are being merged by a separate lane (`dupe-merge`),
  survivor = the account whose email matches the member's current Patreon email.
  That is data repair and is OUT OF SCOPE for this audit — audit the CODE.

## The map (confirmed by reading, 2026-07-29)

Four files create or match users. Sizes and caller counts:

| File | Lines | Role | Callers |
|---|---|---|---|
| `lg-patreon-onboard.php` | 1966 | live OAuth onboarding (returning patron) | the OAuth flow |
| `includes/class-lgpo-sync-engine.php` | 1316 | roster sync (cron), never mints | cron |
| `src/UserLifecycle.php` | 824 | declares itself "canonical user CREATE front-door" | **0 — DEAD** |
| `src/Wp/UserProvisioner.php` | 133 | Stripe path, email-keyed, mints | 3 (Stripe — never fires) |

The identity-matching decision itself is small: ~52 lines in the sync engine
(around 492–544, `lgpo_get_user_by_patreon_id` first), ~150 lines in onboard.php
(around 1222–1372, patreon-id → email → skeleton → mint). **A small decision
inside big files, plus an 824-line dead file that shadows it** — that is why it
has been mis-described repeatedly.

## The audit to perform (write findings to docs/atlas/ as you go)

1. **Is it actually broken?** Trace EVERY path that can create a user
   (`grep wp_insert_user`, four hits today). For each, state: when it fires, how
   it looks a member up before minting, and whether it can mint a duplicate given
   "WP email must match Patreon email". Prove the live onboarding path by reading,
   not grep. Confirm or refute the "fixed since June" claim with more than the
   duplicate-count coincidence — read the git history of the match logic if the
   repo's single-seed commit allows, or reason from the code paths.

2. **The dead file.** `UserLifecycle::provision` has zero callers yet its docblock
   says "every creator routes through here." Either it is a loaded gun (the first
   caller that obeys the docblock mints duplicates) or it is abandonment. Decide:
   delete it, or make it real and route the others through it. Recommend one.

3. **The Stripe path.** `UserProvisioner` is reachable in code (Sync.php:36) but
   has never run (no `stripe_customer` bridge table exists on live; zero users
   from it). Confirm it is truly dead, then recommend: remove it, or leave it
   inert. A reachable-but-email-keyed minter is a latent duplicate source if
   Stripe is ever switched on.

4. **The email-change gap (already found, verify and scope).** Live
   `profile-sync.php` hooks `user_register` only, NOT `profile_update`. The
   profile-app receiver `applyEmailChange` (keeps uuid stable, adds new email as
   alias, moves primary_email) EXISTS and is CORRECT but is **never called** —
   nothing fires on a WP email change. Consequence: a member changing their email
   stays logged in (uuid is stored, not recomputed) but profile-app keeps the old
   email as primary and never records the new one. AND — given email must match
   Patreon — a WP email change that diverges from Patreon may break login/verify
   on the next sync. Trace this fully and say what should fire on `profile_update`.

5. **Can it be smaller?** Only after 1–4. The question is not "shrink for its own
   sake" — it is: how much of the ~4,200 user-touching lines is dead
   (UserLifecycle 824), duplicated (onboard vs sync-engine both implement the
   match), or defensive scar tissue from past incidents (the mikelle.davlin
   double-account is referenced in comments)? Propose a target shape: one match
   function, one create function, one email-mirror function, called by both the
   OAuth and cron entry points. Estimate the line reduction and name what must
   NOT be lost (the admin-collision guard, the different-patreon-id conflict
   routing to human review, the skeleton-adoption path).

## Rules for whoever picks this up

- **Read before concluding. State findings once, with file:line.** The failure
  this handoff is a reaction to was guessing from samples.
- **Nothing touches live.** Audit is read-only (`ssh live-ro`). Live WP DB is
  `looth_import`; `looth_dev` there is a decoy. profile-app is postgres.
- **wp-cli needs root** (`sudo wp --allow-root`); as www-data it fatals on
  `/etc/looth/live-wp-keys.php`.
- Do NOT re-open the merge of the existing 29 — that is `dupe-merge`'s job.
- Deliverable: `docs/atlas/POLLER-ONBOARDING-AUDIT.md` — is it broken (per path,
  yes/no, with proof), plus a simplification proposal with a before/after line
  count and an explicit "must not lose" list. Then Ian decides whether to refactor.
