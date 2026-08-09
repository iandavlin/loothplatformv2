# Runbook — truncate the Stripe mirror in `lg_membership` (LIVE)

**For Ian.** Prepared by the keeper's worker on branch `stripe-build`, 2026-08-09.
**Nothing here has been run against live. All live writes are yours.**

Your ruling, 2026-08-09: **"Truncate — clean start."** The Stripe mirror in
`lg_membership` is sandbox alpha debris promoted dev→live; Phase 1 starts from a
clean table.

Background: `docs/atlas/STRIPE-BUILD-HANDOFF-2026-08-08.md` (§5 item 2, §6),
`docs/atlas/STRIPE-PHASE0-FINDINGS.md`.
Script: `truncate-lg-membership.sh` (this directory). Sibling runbook this one
sequences against: `ORPHAN-REVOKE-RUNBOOK.md`.

---

## What this clears

**16 tables, 948 rows, all sandbox alpha.** Every count below was measured on live
2026-08-09 via `live-ro` (`COUNT(*)`, never `information_schema` estimates), and
every table is positively attributed to the Stripe mirror — by code
(`lg-stripe-billing/db/schema.sql` + migrations; poller `src/Schema.php`,
`src/Stripe/*`) and by content:

| table | rows | what it is |
|---|---|---|
| `customers` | 4 | mirror of sandbox Stripe customers (one soft-deleted QA recipient) |
| `wp_user_bridge` | 3 | customer↔WP binding — FK child of `customers`, see the open question |
| `subscriptions` | 3 | sandbox subs; one still lies `active` with `current_period_end` 2026-06-29 |
| `entitlements` | 7 | derived from the sandbox subs |
| `orders` | 0 | mirror (empty) |
| `order_items` | 0 | mirror (empty) |
| `products` | 11 | cache of the **sandbox** Stripe catalog — `prod_looth2_test`, `prod_dev_looth3`, "Test Product For Web Hook Testing" |
| `prices` | 21 | same — test-mode `price_…` ids, useless against the live key |
| `gift_codes` | 4 | QA codes, purchased by sandbox customer 135, redeemed by the QA recipient |
| `gift_recipients_pending` | 0 | gift flow (empty) |
| `pending_sessions` | 6 | literally `cs_test_…` checkout sessions, June |
| `admin_action_log` | 4 | four failed `self_gift_send` tests by user 1003, June 10 |
| `audit_log` | 0 | billing-app audit (never written) |
| `lg_processed_events` | 883 | sandbox event dedup ledger, 2026-05-10 → 05-17 |
| `lg_event_cursor` | 1 | the Stripe poll cursor, stalled at 2026-05-17 (sole row is `source='stripe'` — verified; the script refuses if that ever changes) |
| `trial_fingerprints` | 1 | one trial fingerprint from 2026-05-06 alpha testing (kept = it could wrongly deny a real member a trial; cleared = one alpha tester could trial again) |

**Every writer of every one of these is frozen or routeless on live** (re-verified
8/08: `lgms_stripe_frozen` absent → defaults true, no `/billing` nginx route,
reconcile-pending inert, cursor stalled since May). So the counts above are also a
**tripwire**: the script refuses to run if any of them moved, because on this data
"drift" means something wrote through a system we believe is off.

## Deliberately excluded — 10 tables, never touched

| table | rows | why it stays |
|---|---|---|
| `lg_patreon_members` | 1730 | **LIVE PATREON DATA.** The named trap: it lives in `lg_membership`, not `looth_import`. |
| `lg_role_sources` | 482 | live multi-source role opinions — Patreon current, `manual_admin`, and the stripe rows under the sibling runbook's jurisdiction. Phase 0's whole work product is in here. |
| `lg_test_feedback` | 46 | human-written QA feedback, not mirror data |
| `price_regions` | 44 | hand-seeded country→region pricing config; no Stripe ref; not a mirror |
| `banned_emails` | 0 | policy config |
| `affiliates` | 4 | first-party affiliate program; slugs `81guitarworks`/`burt` map to real WP users 213/1895. (Contains one XSS-test row, id 3 — a separate cleanup candidate, say the word.) |
| `affiliate_clicks` | 2 | same program |
| `affiliate_conversions` | 0 | same program |
| `affiliate_debits` | 0 | same program |
| `lg_affiliate_payouts` | 1 | poller-side payout ledger (one live request from affiliate 19) |

The script enforces this with a forbidden-list self-check (it aborts everything if
a future edit ever puts a forbidden table in the truncate set) and with sentinel
counts on `lg_patreon_members` / `lg_role_sources` asserted unmoved after apply.

---

## ⚠️ OPEN QUESTION — the 3 bridged role rows. Needs your ruling before Step 2.

Phase 0 preserved the `source=stripe` rows of the 3 bridged customers as "real,
never touched." **That promise was made under the repair-the-mirror framing your
truncate ruling supersedes.** Truncating `customers` + `wp_user_bridge` deletes
the *subjects* of those three opinions — leaving them as exactly the orphan class
Phase 0 just spent a week clearing (dead source, opinion keeps voting). Nothing
here is applied until you rule.

The three rows, verbatim from live 2026-08-09:

| wp_user | who | stripe row says | all their sources | WP roles today |
|---|---|---|---|---|
| 1 | **you** (`iandavlin`, customer 7, `cus_UQYOD88vPgxTQy`) | `looth2` | stripe only | administrator, bbp_keymaster, **looth2** |
| 596 | `Jamesroadman@gmail.com` (customer 25 — never even reached Stripe: `stripe_customer_id` NULL) | `NULL` | stripe only | **looth4**, bbp_participant |
| 1003 | Buck Van Laarhoven (customer 135, both subs canceled 6/10) | `NULL` | patreon=`looth3` + stripe | looth3, administrator, bbp_participant |

What retraction would actually do, computed against the code (`Arbiter.php`,
`RoleSourceWriter.php`), same method as Phase 0:

- **596 — provable no-op.** The Arbiter early-returns for looth4 holders, and the
  row asserts `NULL` anyway. Deleting it moves nothing, ever.
- **1003 — provable no-op.** His persisted `patreon=looth3` row keeps winning.
  The stripe `NULL` is a correctly-retracted dead opinion; delete is cleanup.
- **1 — the only real decision, and it is your own account.** Your `looth2` is
  held up *solely* by the sandbox `$5` test sub (mirror still claims it is
  `active`, period ended 6/29 — the mirror lies, which is the whole point of the
  clean start). Delete the row and the next Arbiter run for you drops `looth2`
  and sets BB member type `starter` (administrator and keymaster are untouched —
  the Arbiter only manages looth1–4).

**Options:**

- **A. Exclude the bridge + customers rows from truncation.** Rejected: it
  contradicts the clean start, keeps a mirror row lying `active`, and — worse —
  `TRUNCATE` resets AUTO_INCREMENT, so a *future real* customer would eventually
  be minted with id 7/25/135 and a surviving bridge row would silently bind your
  WP account to a stranger's Stripe customer. The bridge cannot outlive the
  customers table.
- **B. Retract the 3 rows in the same window (recommended).** 596 and 1003 are
  proven no-ops. For your own row, either **(B1)** delete it and let `looth2`
  drop from your account (you are administrator; gating does not change for
  you), or **(B2)** the 1894 pattern: write `manual_admin = looth2` first, then
  delete the stripe row — proven no-op, zero orphans, and you can drop the
  manual row whenever you like. **Recommendation: B with B2** — it moves nobody,
  leaves no orphan, and keeps the decision about your own tier reversible in one
  row. B1 is equally clean if you'd rather not hold a tier you aren't paying for.
- **C. Truncate the mirror, leave the 3 rows, document them.** Rejected:
  manufactures three orphans the day after Phase 0, and the stuck-source
  detector will (correctly) flag them forever.

**CHOSEN 2026-08-09, keeper default: B with B2 — the nothing-changes option.**
Ian was asked and the question was not worth his time; keeper picked the option
that provably changes nothing (`manual_admin = looth2` written first, stripe row
deleted after, user-1 role snapshot required byte-identical in Step 5) and stays
reversible with a single row delete whenever Ian cares. His admin role and login
are not involved in any option and are asserted unchanged by the snapshot.
Step 3 below carries the literal SQL for B2.

---

## Sequencing — read before you run anything

1. **Run `ORPHAN-REVOKE-RUNBOOK.md` first — all of it that you intend to run**
   (step 2's safe 35, plus any of the held 6 you release via `allow=`). That
   script tells orphaned from bridged by joining `wp_user_bridge`; truncate the
   bridge first and its pinned "41 orphaned / 3 bridged" reads 44/0 and it
   refuses on drift. As of 2026-08-09 it has **not** been applied (live still
   holds all 44 `source=stripe` rows).
2. **No service stop, no quiesce.** The 5-minute tick's Stripe half is frozen;
   the hourly Patreon sweep writes only excluded tables. The truncate window is
   seconds.
3. **This script is not on live until merged to `main` and pulled.** Deploy is
   one pull: `git -C ~/loothplatformv2-clean pull --ff-only origin main`.

## Step 0 — back up (2 min)

The script takes its own dump of the 16 tables before touching anything; this
full-schema dump is the belt-and-braces backstop, same house rule as always:

```bash
# on live
mysqldump lg_membership > ~/backup-lg-membership-full-$(date +%Y%m%d-%H%M).sql
```

## Step 1 — review (writes nothing)

```bash
cd /home/ubuntu/loothplatformv2-clean/lg-patreon-stripe-poller/deploy/remediation
bash truncate-lg-membership.sh
```

Expect the plan table with **rows == pinned for all 16**, and:

```
NEVER touched (sentinels before): lg_patreon_members=1730 lg_role_sources=...
```

(`lg_role_sources` will be below 482 once the orphan revoke has run — that is
that script's doing, not drift; this script does not pin it, it only asserts it
never *shrinks during the truncate window*.)

**If it prints `!! DRIFT`, stop.** Every known writer of these tables is off; a
moved count means something wrote through a system believed frozen. Find out
what before you truncate the evidence. `--accept-drift` exists, deliberately
loud, for after you understand the diff.

Independent before-counts, one paste (run now, keep the output):

```bash
mysql -N -B lg_membership -e "
SELECT 'customers',COUNT(*) FROM customers UNION ALL SELECT 'wp_user_bridge',COUNT(*) FROM wp_user_bridge
UNION ALL SELECT 'subscriptions',COUNT(*) FROM subscriptions UNION ALL SELECT 'entitlements',COUNT(*) FROM entitlements
UNION ALL SELECT 'orders',COUNT(*) FROM orders UNION ALL SELECT 'order_items',COUNT(*) FROM order_items
UNION ALL SELECT 'products',COUNT(*) FROM products UNION ALL SELECT 'prices',COUNT(*) FROM prices
UNION ALL SELECT 'gift_codes',COUNT(*) FROM gift_codes UNION ALL SELECT 'gift_recipients_pending',COUNT(*) FROM gift_recipients_pending
UNION ALL SELECT 'pending_sessions',COUNT(*) FROM pending_sessions UNION ALL SELECT 'admin_action_log',COUNT(*) FROM admin_action_log
UNION ALL SELECT 'audit_log',COUNT(*) FROM audit_log UNION ALL SELECT 'lg_processed_events',COUNT(*) FROM lg_processed_events
UNION ALL SELECT 'lg_event_cursor',COUNT(*) FROM lg_event_cursor UNION ALL SELECT 'trial_fingerprints',COUNT(*) FROM trial_fingerprints
UNION ALL SELECT 'SENTINEL lg_patreon_members',COUNT(*) FROM lg_patreon_members
UNION ALL SELECT 'SENTINEL lg_role_sources',COUNT(*) FROM lg_role_sources;"
```

Expected first 16 lines: 4, 3, 3, 7, 0, 0, 11, 21, 4, 0, 6, 4, 0, 883, 1, 1.

And the WP-side role snapshot for the three bridged users (again after Step 4;
byte-identical unless you chose B1):

```bash
sudo -u looth-dev wp eval --path=/var/www/dev \
  'foreach([1,596,1003] as $u){ echo $u." ".implode(",", get_userdata($u)->roles)."\n"; }'
```

## Step 2 — apply

```bash
bash truncate-lg-membership.sh apply
```

What it does, in order, aborting before the first `TRUNCATE` on any failure:
dumps the 16 tables to `~/backup-lgms-truncate-<stamp>.sql`; snapshots each to
`zz_truncsnap_<stamp>_<name>` and verifies 16/16 counts; truncates all 16 in one
session (`SET SESSION FOREIGN_KEY_CHECKS=0` — session-scoped, required because
bare `TRUNCATE customers` fails with ERROR 1701 on its FK children, proven on
dev2); verifies all 16 empty and both sentinels unmoved.

**It prints a rollback handle (`<stamp>`) — write it down.**

## Step 3 — the 3 role rows (HELD until your ruling on the open question)

Option **B2** (recommended — proven no-op, zero orphans):

```bash
mysql lg_membership -e "
INSERT INTO lg_role_sources (wp_user_id, source, tier) VALUES (1,'manual_admin','looth2')
  ON DUPLICATE KEY UPDATE tier='looth2';
DELETE FROM lg_role_sources WHERE source='stripe' AND wp_user_id IN (1,596,1003);"
```

Option **B1** for your row instead (drop the tier you aren't paying for): skip
the INSERT above, run the DELETE, then make the change happen now rather than
latently — Phase 0's lesson about loaded rows:

```bash
sudo -u looth-dev wp eval --path=/var/www/dev 'var_export( \LGMS\Arbiter::sync(1) );'
```

(Checked 2026-08-09: user 1 carries no `payment_source` usermeta, so the
Arbiter's stripe-coexistence guard cannot silently skip this sync.)

Verify either way — expect `0`:

```bash
mysql -N -B lg_membership -e "SELECT COUNT(*) FROM lg_role_sources WHERE source='stripe' AND wp_user_id IN (1,596,1003);"
```

## Step 4 — verify

```bash
bash truncate-lg-membership.sh verify
```

Expect `Clean — every mirror table is empty.`, both sentinels printed, and the
16 snapshot tables listed. Then re-run the Step 1 one-paste count command —
expect sixteen zeros with both sentinels ≥ their before values — and the WP role
snapshot (identical unless B1).

## Rollback

```bash
bash truncate-lg-membership.sh rollback <stamp>     # the handle apply printed
```

Restores all 16 tables from the `zz_truncsnap_<stamp>_*` snapshots and verifies
**`CHECKSUM TABLE` equality per table** — byte-identical restore, proven on the
dev2 clone. AUTO_INCREMENT self-adjusts to MAX(id)+1. It refuses to restore into
non-empty tables (no silent merge). Backstops behind it: the script's own
16-table dump, and your Step 0 full dump.

If Step 3 ran, restore those rows verbatim (values are live 2026-08-09; the
explicit `updated_at` survives insert — `ON UPDATE` only fires on update):

```bash
mysql lg_membership -e "
INSERT INTO lg_role_sources (wp_user_id, source, tier, updated_at) VALUES
  (1,   'stripe','looth2','2026-05-29 23:13:39'),
  (596, 'stripe',NULL,    '2026-05-04 18:04:08'),
  (1003,'stripe',NULL,    '2026-06-09 22:12:22');
DELETE FROM lg_role_sources WHERE wp_user_id=1 AND source='manual_admin' AND tier='looth2';"
```

(Drop that DELETE line if you had chosen B1. If B1's Arbiter run already dropped
your `looth2`, re-running `Arbiter::sync(1)` after the INSERT restores it.)

Snapshots are kept until you say otherwise; `drop-snapshots <stamp> confirm`
removes them once Phase 1 is underway and you are done with them.

## What this does NOT touch

- `lg_patreon_members`, `lg_role_sources` (Step 3 excepted, only on your word) —
  enforced by forbidden-list self-check + sentinel assertions, not just intent.
- The affiliate program, QA feedback, pricing-region config, banned emails.
- No member's WP role moves (B1 moves only yours, only if you choose it). No
  user is created, deleted, emailed, or logged out. No Stripe API call. Both
  freeze locks stay on. No code path changes — only stored rows.

## After the truncate — what the world looks like

- The tick keeps no-opping: the Sync sweep iterates `customers` (now 0), the
  expiry sweep iterates `entitlements` (now 0), the Stripe poll stays frozen.
  On first unfreeze the Poller finds no cursor and stamps *current* time — it
  will not try to replay May's sandbox events.
- AUTO_INCREMENT is reset: the first real customer is id 1. "Customer 7" in the
  audits refers to the snapshots from here on.
- Any billing surface that renders from `products`/`prices` shows empty rather
  than sandbox prices until the real catalog is imported
  (`lg-stripe-billing/bin/stripe-import-catalog.php` against the live account —
  a Phase 1 step, not this runbook's).
- The `sk_live_…` rotation (audit item, re-verified 8/08) is untouched by this
  and still yours to do — it lives in `wp_options`, not `lg_membership`.

## Proven on dev2, 2026-08-09

The exact script above was rehearsed end-to-end on a scratch clone
(`lg_membership_truncproof`) of dev2's own `lg_membership` — same schema, richer
data (65 customers, 41 subs, 31 gift codes, 883 events) — then dropped:

- bare `TRUNCATE customers` fails: ERROR 1701 on `fk_aal_customer` — the FK
  ordering in the script is load-bearing, not style;
- review refused on drift (dev2 counts ≠ live pins) until `--accept-drift`;
- apply: snapshots 16/16 count-verified, truncate left all 16 at zero,
  sentinels (1704 / 425) unmoved;
- rollback: **16/16 `CHECKSUM TABLE` identical** to snapshots; AUTO_INCREMENT
  self-adjusted (next id 138 = MAX+1); double-rollback and unconfirmed
  drop-snapshots both refused.

Dev2 rehearsal used `LGMS_DB=lg_membership_truncproof`; on live the script runs
against `lg_membership` with no overrides.

---

*Staged only. Nothing run on live. — keeper's worker, 2026-08-09*
