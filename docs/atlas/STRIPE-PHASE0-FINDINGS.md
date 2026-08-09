# Phase 0 — re-verification of the Stripe audit, and the corrected blast radius

**Written 2026-08-08 by the `stripe-build` lane.** Branch `stripe-build`, cut from
`862feb9`. Read-only against live (`ssh live-ro`; `looth_import`, `lg_membership`).
**Nothing was written to live.**

Companion to `origin/stripe-audit:docs/atlas/STRIPE-MEMBERSHIP-AUDIT.md` (7/30). That
audit is sound and I did not redo it. This doc records (a) which of its live claims
still hold a week later, (b) the one place its live claim is now **wrong**, and (c) the
data-fix that follows — which is materially different from the one it proposed.

---

## 0. Headline

**The audit said cleaning the 41 orphaned `source=stripe` rows would downgrade 3
members. The real number is 10 — and 4 of them are actively-paying Patreon members
who would be wrongly demoted, two of them from `looth3` all the way to `looth1`.**

A naive "revoke the 41 orphans" — which is what the audit's Phase 0 item 2 reads as —
takes four paying customers' membership away. That is the finding.

The cause is a second layer of stale data the audit did not reach: for those four, the
persisted `lg_role_sources.patreon` row says `tier=NULL` while Patreon says they are
paying. Their paid role is currently being carried **by the dead alpha Stripe row**.
Delete it and nothing is left holding them up.

---

## 1. What still holds from the 7/30 audit (re-verified 2026-08-08)

| Audit claim | Status |
|---|---|
| Tick runs every 5 min on live | ✅ **Re-proven.** Two `cron` reads: `lgms_poll_tick` advanced `1786226777 → 1786227077`, exactly +300s. It fired and rescheduled. |
| Lock A `lgms_stripe_frozen` absent | ✅ Still absent → defaults `true`. |
| Lock B `lgms_stripe_secret_key` absent | ✅ Still absent. |
| `lgms_shared_secret`, `lgms_billing_base_url` absent | ✅ Still absent (reconcile-pending still inert). |
| `lgms_poller_mail_enabled` absent | ✅ Still absent → member mail still suppressed. |
| `sk_live_…` in live `wp_options` (R6) | ✅ **Still there.** `pmpro_live_stripe_connect_secretkey`, 107 chars, `acct_1LJOi5Hg6gcIV22b`. Plus 8 `woocommerce_stripe_*` rows, 4 autoloaded. |
| `lgms_db_pass` autoloaded | ✅ Still `autoload=auto`. |
| No `tick.log` anywhere on live (R4) | ✅ **Still none.** Plugin dir is `ubuntu:ubuntu drwxrwxr-x`; WP runs as `looth-dev`. Unwritable, `@`-silenced. Zero operational record. |
| mu-plugin loader is a regular file, not a symlink (R8) | ✅ Still `-rw-r--r-- looth-dev … Jun 27`. Not covered by `git pull`. |
| `/srv/lg-stripe-billing` is a real dir, not a symlink (R7) | ✅ Confirmed — the only non-symlinked app in `/srv` besides `lg-push`. |
| No `/billing` nginx route on live (R7) | ✅ Confirmed: `grep -rl billing /etc/nginx/` returns **nothing**. |
| 44 `source=stripe` rows, 3 bridged / 41 orphaned | ✅ Exactly 44 / 3 / 41, unchanged. |
| `customers` 4, bridge 3, subs 3, entitlements 7 | ✅ All unchanged. |
| Customer 7 `active` with `current_period_end` 2026-06-29 | ✅ Unchanged — still `active`, still a month+ stale. |

**Drift since 7/30:** `lg_role_sources` grew **446 → 481** (+35, all Patreon). The
Patreon sweep is healthy and current — it last ran `2026-08-08 21:39:00 UTC`, 27
minutes before I looked (`lgpo_last_sync_time`, per the last-checked-vs-last-changed
trap; row `synced_at` would have misled).

---

## 2. Where the audit's live claim is now wrong

> §5.2: *"Three real members … 1862, 1884, 1894 … are holding `looth3` on the strength
> of dead alpha data."*

Two corrections, both verified:

**a) 1894 is not affected.** They also carry a **`manual_admin = looth3`** row. The
Arbiter takes the max across sources, so removing their stripe row leaves
`manual_admin=looth3` still winning. **1894 does not move.** The audit's per-user table
did not show the `manual_admin` column, which is how this was missed.

**b) 1860 joined the over-tiered set after the audit.** Their persisted `patreon` row
(`looth2`) was written during the week; combined with the stale stripe `looth3` it now
over-tiers them. This is drift, not an audit error — and it is the mechanism by which
this problem *grows on its own*.

So the audit's "3 over-tiered" is really **3 over-tiered — 1860, 1862, 1884** — a
different set than the one named.

---

## 3. The blast radius, computed properly

I replicated `Arbiter::computeWinningTier` + `RoleSourceWriter::readAllForUser` +
`PatreonSourceReader::readForUser` offline (`deploy/remediation/classify-orphans.php`)
and ran it over all 41 orphan rows exported from live, comparing **effective tier
before** vs **effective tier after** the row is deleted.

**Replica validation:** for **40 of 41** rows the computed *before* tier equals the
member's actual `wp_capabilities` on live. The single mismatch is 1879, and it is
explained (§3.3) rather than waved away. That 40/41 agreement is the evidence the
model is right; without it these numbers would be assertion, not measurement.

### The trap that made this non-obvious

`readAllForUser` consults the live Patreon adapter **only when there is no persisted
`patreon` row**:

```php
if ( ! array_key_exists( 'patreon', $out ) ) {  // key EXISTS even when tier is NULL
    $patreon = PatreonSourceReader::readForUser( $wpUserId );
```

A persisted `patreon` row with `tier = NULL` therefore **shadows** an adapter that
would have said `looth2`/`looth3`. "No patreon row" and "patreon row saying nothing"
are opposite situations and look identical in any query that `IFNULL`s the tier — which
is exactly the mistake I made on my first pass and caught only by also selecting the
row **count**.

### 3.1 Group V1 — 4 paying members a naive cleanup would demote ⚠️

Their persisted `patreon` row is stale (`tier=NULL`); Patreon says they are active and
charged. The dead Stripe row is the only thing holding their tier up.

| uid | email | now | after naive delete | Patreon truth (live) |
|---|---|---|---|---|
| 1817 | jdorweiler@protonmail.com | `looth2` | ~~`looth1`~~ | active, $5 Looth-Lite, paid 7/17, next 8/17 |
| 1840 | bjornlaglin@hotmail.com | `looth2` | ~~`looth1`~~ | active, $5 Looth-Lite, paid **8/03**, next 9/03 |
| 1861 | aron@gabachmasterjoiner.com | `looth3` | ~~`looth1`~~ | active, **$11 Looth-Pro**, paid 7/15, next 8/09 |
| 1881 | alden@daltonhill.com | `looth3` | ~~`looth1`~~ | active, **$11 Looth-Pro**, paid **8/01**, next 9/01 |

Two of these ($11 Looth-Pro) fall two tiers, `looth3 → looth1`.

**These must not be deleted naively.** The correct fix is to **repair the `patreon` row
first** — write the tier their live pledge entitles (`lgpo_patreon_tier_id` through
`lgpo_tier_map`, corroborated by `tier_label` and `currently_entitled_amount_cents`) —
after which deleting the stripe row is a **provable no-op**:

| uid | tier_id | → map | cents / label | repaired tier | current role | net change |
|---|---|---|---|---|---|---|
| 1817 | 22199086 | looth2 | 500 / Looth-Lite | `looth2` | `looth2` | **none** |
| 1840 | 22199086 | looth2 | 500 / Looth-Lite | `looth2` | `looth2` | **none** |
| 1861 | 22207438 | looth3 | 1100 / Looth-Pro | `looth3` | `looth3` | **none** |
| 1881 | 22207438 | looth3 | 1100 / Looth-Pro | `looth3` | `looth3` | **none** |

The repair is independently correct — it is exactly what the sweep itself writes via
`RoleSourceWriter::report()` on the next change it applies to that member. We are not
inventing an entitlement; we are un-breaking a row that lost one.

### 3.2 Group V2 — 6 genuine, member-visible corrections (Ian's call, per member)

These are real. Ordered by how defensible the correction is.

**Over-tiered — paying $5, receiving $11-tier access:**

| uid | email | now | correct | Patreon truth |
|---|---|---|---|---|
| 1860 | grant.gong@gmail.com | `looth3` | `looth2` | active, $5 Looth-Lite, paid **8/08** |
| 1862 | swisherguitars@gmail.com | `looth3` | `looth2` | active, $60/yr Looth-Lite, next 2027-07-17 |
| 1884 | jscatches@icloud.com | `looth3` | `looth2` | active, $60/yr Looth-Lite, next 2027-06-05 |

**Lapsed — holding a paid tier while not paying at all:**

| uid | email | now | correct | Patreon truth |
|---|---|---|---|---|
| 1863 | andy.gleeman89@gmail.com | `looth2` | `looth1` | **declined_patron**, 0¢, declined 8/07 |
| 1869 | rob@ripcustomguitars.com | `looth2` | `looth1` | **former_patron**, 0¢ |
| 1870 | mike.larkin212@gmail.com | `looth3` | `looth1` | **former_patron**, 0¢, last charge 5/18 |

Note the three lapsed members land on `looth1` (free floor), not on nothing: the
Arbiter's `$winning === null` branch explicitly preserves `looth1`. I checked this
rather than assumed it, because "strips every tier role" was the plausible reading.

**This whole group is held out of the default run.** Per the lane charter,
member-visible downgrades go to Ian member-by-member before any of them ship.

### 3.3 Group S — 31 rows that are safe, and one that is actively good to remove

30 rows are inert: same effective tier before and after. Includes the two `looth4`
holders (1829, 1865 — Arbiter returns early, protected) and the users whose Patreon
adapter already supplies the same or a higher tier.

**1879 is the one worth calling out.** Their stripe row says `looth3`; Patreon says
`looth2`; they are *currently* `looth2`. So the Arbiter has **not run for them since
the row was written (2026-05-06)** — the sweep only calls `Arbiter::sync()` when it
applies a change. The stale row is a **loaded upgrade waiting to fire**: the next time
anything touches 1879, they get silently promoted to `looth3`. Deleting the row is
invisible today and defuses that. This is the pattern the audit's §7 named — *nothing
ever retracts an opinion when its source dies* — caught mid-flight.

### 3.4 Summary

| Class | Rows | Default action |
|---|---|---|
| Inert / defusing (incl. 1879) | 31 | **delete** |
| V1 — stale patreon row, paying member | 4 | **repair patreon row, then delete** (net no-op) |
| V2 — genuine member-visible correction | 6 | **HELD** — needs Ian's per-member go |
| **Total** | **41** | 35 cleaned by default, 6 held |

---

## 4. Ian's tier ruling, applied

> *"dual wielding stripe and patreon [for] some time. Two tiers on patreon for 5 and 11
> dollars … still be able to log in with patreon and have the two tiers respected for
> gating … move to ONE tier for the stripe memberships and have ALL tiered content open
> to the one tier through stripe."*

This settles the audit's open question 2 (**"replacing, or alongside?"**) as
**alongside, indefinitely**. Consequences for the build:

1. **A real retraction protocol is required, not a cutover.** The audit flagged this as
   the fork; the ruling picks the harder branch. Every one of the 41 orphans exists
   because a source died without retracting its opinion. At Stripe production volume
   that recurs continuously.
2. **Stripe writes `looth3` rows only.** One membership, always pro.
3. **Patreon keeps both tiers** ($5→`looth2`, $11→`looth3`) — the live `lgpo_tier_map`
   already encodes exactly this and needs no change.
4. **The Arbiter's max-of-sources already gives dual-holders the right answer.** A
   member paying both gets `looth3` from Stripe regardless of their Patreon tier. No
   gating-surface change is needed, and none will be made.

---

## 5. What I did not prove

- **I did not talk to Stripe.** No API call, no dashboard. Whether customer 7's
  subscription is still active *at Stripe* remains unknown and unknowable from here —
  same limit the 7/30 audit hit, unchanged.
- **I did not read `/srv/lg-stripe-billing/.env` on either box** (mode `640 www-data`).
  Key values on live remain unverified.
- **The classifier is a replica, not the live Arbiter.** Its agreement with live is
  40/41 with the one disagreement explained (§3.3). It models the code as read on this
  branch; if the Arbiter changes, the replica must be re-derived. The remediation
  script does **not** rely on it — it recomputes from live state at run time and
  refuses on any disagreement.
- **Group V1's repair rests on `lgpo_tier_map` + the `lg_patreon_members` snapshot**,
  not on a fresh Patreon API call. All four corroborate across three independent
  fields (tier_id→map, tier_label, cents). A fresh roster fetch would be stronger and
  the script offers `--verify` for exactly that, but it is not required for a no-op.

---

## 6. Provenance

- Code read at `stripe-build` (from `862feb9`); line references are against that tree.
- Live reads: `ssh live-ro`, 2026-08-08 22:01–22:20 UTC, host `ip-172-31-67-175`.
  `looth_import` confirmed as the real WP DB; `looth_dev` is the decoy.
- `lg_patreon_members` lives in **`lg_membership`**, not `looth_import` — a `SHOW
  TABLES LIKE '%patreon%'` against the WP DB returns nothing and reads as "the table is
  gone".
- Counts are `COUNT(*)`, never `information_schema.table_rows` (the audit's warning
  holds — the estimates are wrong by an order of magnitude on these tables).
- **Nothing was written to live. No Stripe API call was made.**
