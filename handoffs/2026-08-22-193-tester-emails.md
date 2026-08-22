# 193 — the tester list takes ADDRESSES, not only accounts that already exist

**Lane** `193-tester-emails` · **BUILD DONE 2026-08-22** · branch pushed, not merged.
Plan approved by keeper the same day (D1 read-side union, D2 no flag, D3 the
`/auth` exemption with three conditions).

**The merge moves nobody on either box.** Live's cohort is empty; dev2's holds
six plain ints. With no addresses listed every path short-circuits on the id
check and the address half is never read — that empty state is what stands in
for a flag here, and it is gated with a call spy rather than argued.

---

## What Ian asked, and what was actually wrong

> *"Is this an accurate test? I thought the whitelist would have them generating
> a wp-user like a normal new member join. Is that not possible?"* — 8/22

It was not. `UserProvisioner::findOrProvision` creates a WP account from the
checkout email — that IS the normal new-member journey — but #181's
`CheckoutAudience::allowsEmail()` resolved the address to a WP user and refused
when there wasn't one. **An address with no account was refused before it could
be provisioned.** Every tester had to pre-exist, so the one path a real stranger
takes at GA was the one path the test could not exercise.

## The shape

**ONE store, two forms.** `lgms_stripe_lifecycle_allowlist` holds positive ints
and email strings in the same array. Safe by construction rather than by care:
`StripeLifecycle::allowlist()` and the standalone app's
`lg_membership_stripe_test_group_ids()` have **always** accepted only ints and
digit-strings, so an address entry was already inert to every reader predating
this.

| | |
|---|---|
| the address reader | `StripeLifecycle::allowlistEmails()` — trim, lower-case, `is_email()`; malformed widens nothing |
| the address predicate | `StripeLifecycle::inCohortEmail()` |
| the union | `StripeLifecycle::inCohort()` — id-set **OR** that user's address |
| the decision | `CheckoutAudience::allowsEmail()` — address first, then today's resolve-to-user |
| the writer | `CohortAllowlist::addEmail/removeEmail/emails/count`, union-preserving `write()` |
| the dash | `Admin.php` — Testers tab, two handlers, address rows |
| the page door | `membership-pages/config.php` — `lg_membership_stripe_test_group_emails()` + the union in `lg_membership_in_stripe_test_group()` |
| the panel | `Health::checkAudience()` counts both halves |
| D3 | `RestController::exemptAuthFromBuddyBossRestriction()`, wired in `Plugin.php` |

## The two things that decided the design

**1. THE UNION IS READ-SIDE, AND IT HAD TO BE.** The tempting alternative is
`Invites::consumeForUser()`'s shape — when the account appears, promote its id
onto the list. It fails the one constraint #181 is built on: *a session minted
for an address LATER removed must still fail to provision*. A promoted id
outlives the address that earned it. Read-side, striking an address shuts every
door in the same instant, including a checkout already minted. Gate 86 §J7 is
the assertion that chose it.

**2. AND IT IS REQUIRED, NOT COSMETIC.** Without the union a listed address
mints a session and provisions an account, and then `Sync::customer`'s fence —
asking the same predicate with the NEW user's id — skips the grant. **The tester
ends with an account and no membership, and the rehearsal reads as passed.**

## D3 — the `/auth` exemption, and its three conditions

Measured on dev2 over loopback **before** the change:

    POST /wp-json/lg-member-sync/v1/auth  ->  401 bb_rest_authorization_required

That route is what creates the account for a logged-out visitor at `/lgjoin/`
(`lgjoin.php`, `CONFIG.authUrl`), so a listed tester would type their address,
press Continue, and be told *"Sign-in failed"* — the whitelist landing correctly
and the rehearsal still impossible.

1. **The route's own checks are untouched.** `permission_callback` is still
   `__return_true` (it IS the sign-in). Per-IP 20/hour, per-email 5 fails/15min,
   `is_email()`, the 8-character minimum and `wp_check_password()` all still run.
   Gate 86 §K asserts each **comparison**, not the key names.
2. **#162's coverage: confirmed HALF, and this is the finding.** ENFORCEMENT
   yes — `platform/nginx/lg-auto-ban-doors.conf.template` gives `/auth` and
   `/gift-auth` their own exact-match locations returning JSON to a listed
   address. DETECTION **no**, and #162 says so itself: *"The stuffing detector
   has never watched it — it checks passwords with `wp_check_password()` and so
   fires no `wp_login_failed` hook."* Verified independently: `giftAuth()` fires
   `wp_login` on success and never `wp_login_failed`. **So a ban earned at
   wp-login.php is enforced here; a stuffing run conducted only against this
   route earns none** — its two throttles are what stand there. **And neither
   box has #162 installed**: the flag defaults false and the nginx snippet
   exists on neither, so the enforcement half is absent on both today. The
   one-line change that would close detection (fire `wp_login_failed` from the
   wrong-password branch) is #162's design call and was deliberately NOT made.
3. **Surgical.** Two routes named, never the namespace. `/sync-customer`,
   `/patreon-standing` and `/send-gift-codes` stay shut exactly as #181 left
   them (§K3, and a mutation widening it goes red).

**Side effect worth having:** the same 401 is why a gift recipient with no
account could not redeem one. Repaired in the same breath.

## The four proofs

1. **A listed address with NO account completes checkout and gets its account
   from the payment** — gate 86 §J6 (provisions, exactly one user minted, for
   the listed address, at `looth1` as a real new member), and gate 34 drives the
   whole thing through the **real** webhook: a member listed only by address
   transitions and the grant lands.
2. **An unlisted address with no account is refused** — §J2, with §J3/§J3b/§J3c
   keeping #181's absent-email refusal intact.
3. **A listed address that already has an account behaves as today** — §J5:
   admitted, provisioning returns the **existing** account, no duplicate minted.
4. **#181's four proofs pass unchanged** — §B/§C/§F/§H all green; `allowsUser()`
   is textually unchanged with no admin bypass (§J11d–f); the fence still sits
   below the existing-bridge return (§J8); a session minted for a since-removed
   address still fails to provision (§J7).

## Gates

| gate | assertions | red-first |
|---|---|---|
| **86** checkout-audience (+§J address, +§K auth) | 212 | **42/42** + 3 no-ops |
| **34** test-soft-launch-allowlist (real normalizer + union + store) | 67 | covered by 86's harness, targeted at this gate |
| **90** tester-dash (+§I the tab) | 119 | **43/43** + 3 no-ops |
| **34b** stripe-testgroup-pages (+the address door) | 136 | **6/6** + 1 no-op |
| **91** membership-health (+both-halves count) | 100 | +2 mutations |

Neighbours re-run standalone and green: 34d sweep, 75 double-pay, 76 multi-tier,
`test-identity-gate`, `test-checkout-session-metadata`.

## ⚠️ What red-first found, because it is all one lesson

**Five assertions of mine were blind, and every one asserted that a STRING was
PRESENT rather than that a DECISION was MADE.**

- §K looked for `lgms_ga_ip_` and `wp_check_password`, so `if ( $ipHits >= 20 )`
  → `if ( false )` disabled the per-IP throttle with the gate green — keeper's
  condition 1 unguarded by the very section written to guard it.
- §I6 looked for `lgms_cohort_confirm_email` anywhere in the handler, so
  neutering the branch left the string sitting there unreachable.
- §I8 looked for `CohortAllowlist::emails()`, so `foreach ( [] as $addr )`
  rendered nothing and passed.
- 34b's address reader legs stayed green when validation was dropped, because
  the invented junk entries were not addresses any test viewer carried — the
  same lesson `idsFor()` already records one reader over.
- 34b's anon guard could not be exercised through the gate **at all**: the gate
  refuses an unauthenticated ctx on its own `authenticated` clause, so deleting
  the guard stayed green for an unrelated reason, and my own email stub
  returning `''` for id 0 masked it a second time.

**A sixth was a different shape worth naming: a fixed-target assertion satisfied
by the WRONG OCCURRENCE.** §I9 matched the count expression anywhere in the tab,
so reverting the HEADING stayed green on the CHIP's identical copy.

**And six "blind spots" that were nothing of the kind.** Mutations to the real
`StripeLifecycle` stayed green against gate 86 — which **stubs** that class on
purpose. The harness now targets **a gate per mutation**. Pointing a mutation at
the wrong gate is a false green, and it will recur.

## ⚠️ Traps this lane paid for

- **`CohortAllowlist::write()` rebuilt the option from the ids alone.** The first
  dash edit of any MEMBER would have silently deleted every tester address — no
  error, no notice. `addedMap()`'s `(int) $k > 0` had the same shape for the
  date column. Red-first M39/M40 model both.
- **The page door's case-correctness lived in someone else's helper.** Gate 34b
  caught it: the first draft leaned on `lg_membership_user_email()` to
  lower-case. Normalized at the compare now.
- **A `[^}]*` regex window stopped at the `if`-block's brace**, not the
  function's — #190's fixed-width-window defect one step earlier. Gate 86 gained
  a `fn_body()` brace-matcher it did not have.
- **Backticks in a `git commit -m` inside double quotes are command
  substitution.** Commit `f44a222` lost two fragments of its message that way.
  Code and gates unaffected; the full wording survives in the gate's own
  docblock. **Not amended — that needs a force-push, which LANE-RULES forbids.**
  Commit messages after that one go through `-F <file>`.
- **A 2-minute Bash timeout killed a red-first mid-run and left a mutation on
  disk.** It was the last case and only a comment reword, verified by reading
  the diff before restoring. Long harnesses run backgrounded now.

## Owed / not reached

- **Ian looks at the Testers tab on dev2 after the merge** and lists a real
  address. ⚠️ **Nothing in this lane can be verified over HTTP until it is
  merged** — the dev2 serve runs `main`, which is also where the `/auth` 401
  above was measured. That 401 is the state that changes on the pull.
- **Live writes stay his.** The two live gaps that still block go-live are
  unchanged and are #192's: `lgms_shared_secret` absent, and the cohort empty —
  though the cohort is now much easier to fill, since it takes addresses.
- **Not reached, deliberately:** firing `wp_login_failed` from `giftAuth()`'s
  wrong-password branch so #162's stuffing detector watches that door. It is
  #162's design call, and widening a neighbouring lane's scope in passing is
  what #181 declined to do with these same routes.
- **Not reached:** `Invites` still mints links, and its raw token still rides a
  query arg into the admin URL, history and every onward Referer. Observed by
  #190, still true, still a different token and a different issue. Its panel
  copy is updated to say the list usually makes it unnecessary now.
