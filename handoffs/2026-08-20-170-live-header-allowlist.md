# #170 — three states for the header Join (BUILD DONE, awaiting merge)

Branch `170-live-header-allowlist`, cut level with `origin/main` @ `5833f38`
(#165 already merged in). Flag `header-join-stripe` is now three-state and
tracked `'off'`. Keeper merges; the flip is Ian's.

## What Ian asked for

> "We need the join button in the header to still go to patreon unless a test
> user is there on live."

## The measurement that shaped it — read this before reviewing the diff

`.lg-chrome__join` sits in the **anon** branch of the aside. Rendering the
partial four ways on main:

| viewer | Join pill | `/lgjoin/` |
|---|---|---|
| anon | 1 | 0 (patreon) |
| member, not listed | 0 | 0 |
| member, test group | **0** | 1 (account menu) |
| admin | **0** | 1 (account menu) |

A signed-in test user **never saw the header Join button at all**. So the
literal reading of this issue — swap the href for a listed member — would have
rendered byte-identically to `off` for every viewer and gated green having
measured nothing. Ian chose Option 1: in `allowlist`, the tester gets a real
pill. `off` and `on` keep signed-in renders byte-for-byte what #165 proved.

## The three states

| state | who gets `/lgjoin/` | anon sees |
|---|---|---|
| `'off'` | nobody | patreon.com, new tab (the 6/12 anchor, byte-for-byte) |
| `'allowlist'` | the Stripe cohort, signed in | patreon.com — **byte-identical to `off`** |
| `'on'` | everybody | `/lgjoin/`, same tab |

No second list: `allowlist` reads `$caps['stripe_testgroup']`, which the poller
already computes (`manage_options || inCohort($uid)`) from the one wp_option
`lgms_stripe_lifecycle_allowlist`. Admins are in the cohort by construction.

## ⚠️ THE FLIP IS TWO SWITCHES, AND WHICH SECOND SWITCH DEPENDS ON THE STATE

| state | partner switch | where |
|---|---|---|
| `'on'` | `lgms_stripe_pages_live` | WP admin → Settings → LG Member Sync |
| `'allowlist'` | **`lgms_stripe_testgroup_pages`** | same page |

The predicates are **not the same shape**. The header pill has ONE lock (the
cohort list). The door — `lg_membership_in_stripe_test_group()` — has TWO (that
flag AND the list). A **listed non-admin** can therefore be handed a pill and
refused at the door, while an **admin passes both and sees nothing wrong**.

**Verify any flip by clicking Join signed in as a listed NON-ADMIN member.** An
admin check cannot see this failure.

## The flip kit, in the order it has to happen

1. Merge. Nothing changes anywhere: tracked default is `'off'`, and dev2 keeps
   running `'on'` off its existing `.local.php` (see the migration note below).
2. To arm the live soft launch: place
   `platform/config/header-join-stripe.local.php` on live with
   `return array('state' => 'allowlist');` — **`php -l` it first**, this partial
   renders on every page of seven apps and a parse error is a site-wide 500.
3. In the same window, turn on `lgms_stripe_testgroup_pages` and confirm the
   cohort list holds the intended WP user ids **for that box** (ids differ
   between live and dev2).
4. Click Join signed in as a listed non-admin, at a desktop width and at 390px.
5. Go-live later is `'state' => 'on'` plus `lgms_stripe_pages_live`.

## ⚠️ dev2 needs NO file edit, and that is deliberate

dev2's `.local.php` says the legacy `enabled => true`. The reader still maps
that to `'on'`, and gate 79 asserts it against dev2's byte-exact on-box shape.
Had the legacy key been tidied away, merging this branch would have reverted
dev2's header to patreon.com on the next `pull --ff-only` with nobody having
flipped anything and nothing in any diff to explain it. Keeper may rewrite the
file to `'state' => 'on'` for clarity, but nothing breaks if it never happens.

## Files touched

| file | what |
|---|---|
| `lg-shared/site-header.php` | three-state reader, render rule, the tester pill |
| `platform/config/header-join-stripe.php` | `'state' => 'off'`, docblock rewritten |
| `webroot/bottom-nav.js` | tester Join row in the authed "You" sheet |
| `tools/gates/header-join-gate.py` | gate 79: 104 assertions, 157 with the browser |
| `tools/gates/header-join-redfirst.sh` | 21 mutations + 2 no-ops, 23/23 |
| `tools/gates/run-all.sh` | gate 79's description only |
| `docs/FLAGS.md`, `docs/CRAFT-STANDARD.md`, `docs/domains/MEMBERSHIP.md` | the register, the standard, the dossier |
| `handoffs/plans/170-live-header-allowlist-PLAN.md` | the plan and its measurement |

Nothing outside the plan. `lg-shell/lg-shared/site-header.php` was deliberately
left alone — a 6/18 seed snapshot, carrying no flag and a different Join
(`/join/`), untouched since and untouched by #165 either.

## Noticed, not fixed

- **The 821–904px dead band** (#165's `KNOWN_MAIN_GAPS`, gate 79) is unchanged
  and still Ian's call. The tester pill lands in the authed cluster, not the
  anon one that overflows.
- **The three anon Patreon join CTAs** #165 listed — `directory-members.php:154`,
  `archive-poc/web/defaults.php:88`, `_chrome-footer.php:40` — are still
  untouched and still where this question lands next.
- **The lane branch's upstream was `origin/main`**, not its own remote branch, so
  a bare `git push` would have targeted main. Repointed to
  `origin/170-live-header-allowlist`. Worth checking on other lanes.
- `gh` is not authenticated in this worktree, so #170's issue body could not be
  read directly; the charter's verbatim quote was the ground truth.
