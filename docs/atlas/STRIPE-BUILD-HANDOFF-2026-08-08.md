# Handoff — `stripe-build` lane → keeper chat

**2026-08-08.** Branch `stripe-build`, 7 commits off `main` (`862feb9`), all pushed.
Ian is driving from the keeper chat from here; this lane parks after this doc.

**Nothing was written to live. No Stripe API call was made. No gating surface was
touched.** Every live figure below came from `ssh live-ro` on 2026-08-08 22:00–23:00 UTC
(host `ip-172-31-67-175`, `looth_import` confirmed via `siteurl=https://loothgroup.com`).

Read alongside:
- `docs/atlas/STRIPE-PHASE0-FINDINGS.md` — what live actually looks like, and where the
  7/30 audit is now wrong.
- `docs/atlas/STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md` — identity chain, retraction
  protocol, launch ordering, and the `payment_source` defect.
- `lg-patreon-stripe-poller/deploy/remediation/ORPHAN-REVOKE-RUNBOOK.md` — the live
  runbook Ian actually runs from.

---

## 0. The one paragraph that matters

The 7/30 audit's Phase 0 says *"revoke the 41 orphaned `source=stripe` rows, expect 3
members to drop `looth3 → looth2`."* **Applied as written that moves 10 members, and
four of them are actively-paying Patreon members who would be wrongly demoted — two of
them two tiers, `looth3 → looth1`, both $11 Looth-Pro.** The shipped script handles
those four automatically (repair-then-delete, net zero change) and **holds** the six
that genuinely move, for Ian to rule on member by member. §2 is that ruling sheet.

Ian has confirmed the Stripe customers are sandbox, promoted dev→live, and the Stripe
system is dormant. Both true and verified. But the orphan rows are activated by the
**Patreon sweep**, which is awake and hourly — see §2.4. Dormant data is out-voting a
live system on production roles.

---

## 1. Phase 0 — state of every item

| # | Charter item | State | SHA |
|---|---|---|---|
| 0a | 41 orphaned `source=stripe` rows → journalled data-fix | **DONE** — 35 auto, 6 held for Ian | `f181a2b` + `655916a` |
| 0b | Email-keyed minter must not survive to launch | **DONE** — replacement built, gated, OFF | `4386df3` + `ca62d7b` |
| 0c | Unblind the pipeline (logs where PHP can write, no `@`) | **DONE** | `d8d7344` |
| — | Re-verify the audit's live claims a week on | **DONE** — §4 | `5ef0c6c` |
| — | *(added)* encode the defect class as a check | **DONE** | `2c2f0f5` |

Commits, oldest first:

```
5ef0c6c  docs: Phase 0 re-verification — orphan cleanup blast radius is 10, not 3
f181a2b  remediation: journaled retraction of the 41 orphan stripe role-sources
655916a  docs: live runbook for the orphan retraction, + fix the run instructions
d8d7344  poller: unblind the tick — LGMS\Log, no @-silencing, writable path
4386df3  poller: identity gate — the email-keyed minter can no longer mint (flagged OFF)
ca62d7b  docs: identity + retraction design — and the defect dual-wielding exposes
2c2f0f5  poller: stuck-source detector — encode the "dead source keeps voting" class
```

Four red-first gates, all green, all runnable with plain `php` on dev2:

```bash
cd ~/worktrees/stripe-build/lg-patreon-stripe-poller/deploy/remediation
php test-revoke-orphan-stripe-sources.php   # 32 assertions
php test-log-unblinding.php                 # 19
php test-identity-gate.php                  # 24
php test-stuck-sources.php                  # 14
```

`tools/gates/run-all.sh` was **not** run: it covers user-facing surfaces, and this
branch changes backend PHP, remediation scripts and docs only — no template, CSS, JS or
page. Nothing here renders.

---

## 2. The orphan retraction — Ian's ruling sheet

44 rows carry `source='stripe'`. Three belong to real bridged customers (users 1, 596,
1003) and are **never touched**. The other 41 are alpha debris: customer deleted, role
opinion left behind. The Arbiter takes the max across sources, so a dead opinion keeps
voting.

### 2.1 What runs automatically (35 rows, no member moves)

| Class | Rows | Action |
|---|---|---|
| INERT | 30 | delete — effective tier identical before and after |
| DEFUSES | 1 (1879) | delete — invisible today; the stale row is a loaded `looth3` promotion that fires on the next Arbiter run |
| REPAIR | 4 | repair the shadowed Patreon row first, **then** delete → net zero change |

**The REPAIR four are the ones a naive cleanup breaks.** Their persisted
`lg_role_sources.patreon` row says `tier=NULL` while Patreon says they are paying. A
`NULL` row *shadows* the live Patreon reader (`readAllForUser` consults it only when no
patreon row exists, and `array_key_exists` is TRUE for `NULL`), so the dead Stripe row
is the only thing holding their tier up.

| uid | email | pays | holds | naive delete | after the fix |
|---|---|---|---|---|---|
| 1817 | jdorweiler@protonmail.com | $5/mo, charged 7/17 | `looth2` | ~~`looth1`~~ | `looth2` (unchanged) |
| 1840 | bjornlaglin@hotmail.com | $5/mo, charged 8/03 | `looth2` | ~~`looth1`~~ | `looth2` (unchanged) |
| 1861 | aron@gabachmasterjoiner.com | **$11/mo**, charged 7/15 | `looth3` | ~~`looth1`~~ | `looth3` (unchanged) |
| 1881 | alden@daltonhill.com | **$11/mo**, charged 8/01 | `looth3` | ~~`looth1`~~ | `looth3` (unchanged) |

The repair writes the tier their live pledge already entitles (`lgpo_patreon_tier_id`
through `lgpo_tier_map`, corroborated by `tier_label` and
`currently_entitled_amount_cents`) — exactly what the sweep itself writes on its next
applied change. It is **refused** unless it provably moves the member nowhere.

### 2.2 HELD — 6 members, Ian rules one at a time

Nothing happens to these until released explicitly. There is deliberately **no bulk
form**:

```bash
sudo -u looth-dev wp eval-file revoke-orphan-stripe-sources.php --path=/var/www/dev apply allow=1863
```

**Group A — lapsed. Holding a paid tier while not paying at all.**

| uid | email | before | after | Patreon truth (live) |
|---|---|---|---|---|
| 1863 | andy.gleeman89@gmail.com | `looth2` | `looth1` | **declined_patron**, $0, declined 8/07 |
| 1869 | rob@ripcustomguitars.com | `looth2` | `looth1` | **former_patron**, $0 |
| 1870 | mike.larkin212@gmail.com | `looth3` | `looth1` | **former_patron**, $0, last charge 5/18 |

**Group B — over-tiered. Paying $5, receiving the $11 tier.**

| uid | email | before | after | Patreon truth (live) |
|---|---|---|---|---|
| 1860 | grant.gong@gmail.com | `looth3` | `looth2` | active, $5 Looth-Lite, charged **8/08** |
| 1862 | swisherguitars@gmail.com | `looth3` | `looth2` | active, **$60/yr**, paid through **2027-07-17** |
| 1884 | jscatches@icloud.com | `looth3` | `looth2` | active, **$60/yr**, paid through **2027-06-05** |

All six land on a real tier — `looth1` is the free floor and the Arbiter explicitly
preserves it (checked, not assumed; "strips every tier role" was the plausible reading).

**Lane recommendation:** apply Group A, hold Group B. Group A is the system working as
designed — the same correction Patreon lapsing already applies to everyone else, and the
sweep has been trying to make it hourly. Group B is different: 1862 and 1884 have
**pre-paid a full year**, so removing a tier from someone paid through 2027 is a support
conversation, not a data fix.

**On grandfathering Group B:** the audit suggested grandfathering them *and* deleting the
rows anyway. That cannot be one step — deleting the row **is** what demotes them. The
clean version is a `manual_admin = looth3` row (exactly what already protects 1894), then
delete the stripe row. A journalled `grandfather=` mode is ~30 lines and is **not built**;
say the word.

### 2.3 Corrections to the 7/30 audit §5.2

- **1894 is NOT affected.** They also carry `manual_admin = looth3`, which still wins
  after the stripe row goes. The audit named them; they do not move.
- **1860 joined the over-tiered set during the past week.** Their persisted `patreon`
  row was written mid-week and combined with the stale stripe `looth3`. The problem
  grows on its own — `lg_role_sources` went 446 → 481 in the same window.
- So the audit's "3 over-tiered" is a **different set** than the one it named.

### 2.4 Why "dormant" is doing less work than it sounds

The **Stripe** half is genuinely dormant, re-verified 8/08: `lgms_stripe_frozen` absent
(defaults true), `lgms_stripe_secret_key` absent, no `/billing` nginx route on live
(`grep -rl billing /etc/nginx/` returns nothing).

The rows are activated by the **Patreon sweep**, which is awake and hourly
(`lgpo_last_sync_time` = 2026-08-08 21:39 UTC). Live's own 3-day changelog
(`lgpo_sync_changelog`, 72 sweep batches, most recent 22:39 UTC) shows seven members
where the sweep proposes a correction and it does not take — **effective role moved for
zero of them in three days**:

```
#1870 stays looth3 [downgrade]     #1860 stays looth3 [update]
#1869 stays looth2 [downgrade]     #1862 stays looth3 [update]
#1863 stays looth2 [downgrade]     #1884 stays looth3 [update]
                                   #1894 stays looth3 [update]  (held by manual_admin — fine)
```

The engine already has the phrase, at `class-lgpo-sync-engine.php:960`:
*"Patreon tier ended; effective role stays {role} **(held by another source)**."* It only
`error_log()`s it — into a log that, until `d8d7344`, did not exist on live at all.

---

## 3. Open question — bring both Stripe audits onto `main`

**Neither Stripe audit is on `main`.** Both sit on unmerged branches, so they are
invisible to anyone who does not already know the branch name. That is exactly how Ian
came to ask "is there a stripe audit kicking around?" while two existed.

**What I want merged — docs only, zero code:**

| From | To | Size |
|---|---|---|
| `origin/stripe-audit:docs/atlas/STRIPE-MEMBERSHIP-AUDIT.md` | `docs/atlas/` on `main` | 519 lines, 7/30 |
| `origin/stripe-poller-audit:docs/STRIPE-POLLER-AUDIT.md` | `docs/atlas/STRIPE-POLLER-AUDIT.md` on `main` | 243 lines, 6/20 |

**Why:** they are the two most expensive artefacts this project has, they are read-only
prose, and they cost nothing to carry. The alternative is the next lane re-deriving them
against live.

**One condition, and it matters:** `STRIPE-MEMBERSHIP-AUDIT.md` §5.2 is **now wrong** in
the dangerous direction. If it lands on `main` unqualified, the next reader gets "expect
3 downgrades" and may act on it. It should land with a header pointing at
`STRIPE-PHASE0-FINDINGS.md`, or with §5.2 amended in place. **I have not merged
anything — this is a request, not a fait accompli.**

---

## 4. Email-keyed minter — replacement status

**Built and gated. Not yet armed.** `4386df3`; design in
`STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md` §3.

`UserProvisioner::findOrProvision` keyed on **email and nothing else**, with
`wp_insert_user` as its third branch. Because the platform rewrites a member's WP email
to their current Patreon email, a Stripe customer with a different address — a second
email, or an Apple Private Relay alias, **both of which occur on live** — misses the
lookup and mints a second account.

New `IdentityMatcher` chain, strongest claim first:

1. `wp_user_bridge` on `customer_id` — authoritative
2. `customers.metadata.wp_user_id` — an **explicit** bridge set at checkout
3. `customers.metadata.patreon_user_id` → `lgpo_patreon_user_id` usermeta — **1,777
   accounts carry this on live**
4. email — one signal, never grounds to create
5. no match → **refuse**: notify + throw. Never mints.

Every step also refuses a WP user already bridged to a *different* customer, closing the
read side of R3 (the `ON DUPLICATE KEY` collision on `uk_wp_user` that silently updates
the other customer's row).

`looth_uid` is **not** in the chain — the audit named it, but it has **0 rows** in live
usermeta.

**Flag: `lgms_identity_gate`, default OFF.** OFF is proven byte-identical by exercising
the old mint path and requiring it to *still* mint. An **option, not an env var** —
WP-Cron carries no environment and the tick is the main caller, so an env flag would read
unset in exactly the context that matters.

**Not done:** branch 2 only fires once `lg-stripe-billing` writes `wp_user_id` into
checkout session metadata. Until then branch 5 (refuse) fires for genuinely new
customers — correct while ingest is frozen and nobody can reach onboarding. The end state
is deleting the `wp_insert_user` branch outright; the flag is scaffolding.

---

## 5. Next steps, ordered

1. **Ian rules on the 6 held members** (§2.2). Blocked on him. Group A is
   low-controversy; Group B needs a grandfather-vs-correct decision.
2. **Ian rules: truncate `lg_membership`, or repair it?** Blocked on him. If the Stripe
   customers are all sandbox — as Ian says — there is nothing worth reconciling and
   Phase 1 should start from a clean table. **This lane's earlier "reconcile the stale
   mirror" framing was written before that was known and should be treated as
   superseded.** Nothing that deletes rows has been written.
3. **Retraction sweep** (Phase 1) — the permanent fix for the class. Design is in
   `STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md` §4. Not built. Independent of 1 and 2.
   The load-bearing detail: iterate **`lg_role_sources WHERE source='stripe'`**, not
   `customers`. Sweeping `customers` is precisely how these 41 survived — their customer
   rows were deleted, so nothing ever visited their opinions again. **Iterate the
   opinions, not the subjects.**
4. **`payment_source` is single-valued in a dual-source world** (§2 of the design doc).
   All three coexistence guards key on it. A member paying both gets
   `payment_source=stripe`, the Patreon sweep skips them forever, and when they cancel
   Stripe the frozen Patreon opinion drops them to `looth1` **while still paying $5**.
   Same shape as the four in §2.1, but by design instead of by accident.
   **Needs Ian's awareness before implementation** — it changes what "a Patreon member"
   means in code. Recommendation: demote `payment_source` to descriptive and key the
   guards on source rows.
5. **Ian's, unblocked by anything here:** rotate the `sk_live_…` key still in live
   `wp_options` (`pmpro_live_stripe_connect_secretkey`, same Stripe account
   `lg-stripe-billing` targets, orphan of a deleted plugin). **Rotate before deleting the
   row** — deleting does not invalidate the key.
6. **Deploy shape, unsolved:** `lg-stripe-billing` is a real directory with its own
   `.git` in `/srv` on both boxes and has **no nginx route on live**. Shipping it is not
   a pull. Recommendation stands: fold it into the monorepo. Related: the poller's
   mu-plugin loader on live is a **regular file, not a symlink**, so a repo edit to it
   does not reach live via `git pull` — byte-identical today, but this branch adds churn
   to that plugin.

### Blocked

**Blocked on Ian:** items 1, 2, and 4 (and the §3 audits-to-main request). Item 3 is
buildable now and is what this lane would have done next.

---

## 6. Two traps worth carrying forward

**A `NULL` source row is not an absent one.** `array_key_exists` is TRUE for `tier=NULL`,
so a stale row shadows the live reader. Any query that does `IFNULL(tier, '-none-')`
conflates the two, and they are opposite situations. Select the row `COUNT(*)` alongside
the tier. This lane got it wrong on its first pass and caught it only by doing that.

**`lg_patreon_members` lives in `lg_membership`, not `looth_import`.** A `SHOW TABLES
LIKE '%patreon%'` against the WP DB returns nothing and reads as "the table is gone".
