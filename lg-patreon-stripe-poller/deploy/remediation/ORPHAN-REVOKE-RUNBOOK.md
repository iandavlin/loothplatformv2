# Runbook — retract the 41 orphaned `source=stripe` role opinions (LIVE)

**For Ian.** Prepared by the `stripe-build` lane, 2026-08-08. Nothing here has been
run. All live writes are yours.

Background and evidence: `docs/atlas/STRIPE-PHASE0-FINDINGS.md`.
Script: `revoke-orphan-stripe-sources.php`. Gate: `test-revoke-orphan-stripe-sources.php`.

---

## What this fixes

44 rows in `lg_role_sources` say `source=stripe`. Three belong to real bridged
customers. **41 are debris from the April–June alpha** — the customers were deleted,
their role opinions were not. The Arbiter takes the highest tier across all sources,
so those dead opinions keep voting, forever.

## Read this before you run anything

**It is not "delete 41 rows."** Doing that demotes **four members who are paying you
right now**, two of them from `looth3` to `looth1`:

| uid | email | pays | holds | naive delete would give |
|---|---|---|---|---|
| 1817 | jdorweiler@protonmail.com | $5/mo, paid 7/17 | `looth2` | `looth1` |
| 1840 | bjornlaglin@hotmail.com | $5/mo, paid 8/03 | `looth2` | `looth1` |
| 1861 | aron@gabachmasterjoiner.com | **$11/mo**, paid 7/15 | `looth3` | `looth1` |
| 1881 | alden@daltonhill.com | **$11/mo**, paid 8/01 | `looth3` | `looth1` |

Their Patreon row in `lg_role_sources` is a stale `NULL`, and a stale `NULL` row
*shadows* the live Patreon reader. So the only thing currently holding their paid tier
up is the dead Stripe row. The script repairs the Patreon row first — writing the tier
their live pledge already entitles — and only then deletes. **Net effect on those four:
nothing changes.** That is asserted, not hoped: the repair is refused unless it
provably moves the member nowhere.

---

## Step 0 — back up (2 min)

```bash
# on live
wp db export ~/backup-orphan-revoke-$(date +%Y%m%d-%H%M).sql --tables=wp_options,wp_usermeta

# lg_membership is a SEPARATE database and is NOT in the wp db export
mysqldump lg_membership lg_role_sources > ~/backup-role-sources-$(date +%Y%m%d-%H%M).sql
```

The script's own journal is the intended rollback path; these dumps are the backstop.

## Step 1 — review (writes nothing)

```bash
cd /home/ubuntu/loothplatformv2-clean/lg-patreon-stripe-poller/deploy/remediation
sudo -u looth-dev wp eval-file revoke-orphan-stripe-sources.php --path=/var/www/dev
```

Three path facts, each verified on live 2026-08-08, because getting any of them wrong
wastes a session:

- The poller is an **mu-plugin loaded from the serving checkout** — it is *not* under
  `wp-content/plugins/`. `deploy/remediation/` lives at the path above.
- The WP system user is **`looth-dev`**. There is **no `looth-live` user on this box**;
  the sibling `README.md` says `looth-live` and every command in it fails as written.
- `--path=/var/www/dev` is required — the working directory is outside the docroot.
  (Live's docroot really is named `dev`.)

**This script is not on live until it is merged to `main` and pulled.** Deploy is one
pull: `git -C ~/loothplatformv2-clean pull --ff-only origin main`.

Expect, exactly:

```
source=stripe rows: 41 orphaned, 3 bridged (bridged are never touched)
--- VISIBLE (6)   1860 1862 1863 1869 1870 1884
--- REPAIR (4)    1817 1840 1861 1881
--- DEFUSES (1)   1879
--- INERT (30)
Pinned expectation matches live exactly (41 rows; repair/visible/defuses sets identical).
PLAN: 35 rows to delete (4 repair-first), 6 HELD as member-visible.
```

**If it prints `!! DRIFT`, stop and read it.** Drift on this data means a member's
entitlement moved since 8/08. The script refuses to apply until you pass
`accept-drift`, deliberately.

## Step 2 — apply the safe 35

```bash
sudo -u looth-dev wp eval-file revoke-orphan-stripe-sources.php --path=/var/www/dev apply
```

Prints a batch id (`ross-<stamp>-<hash>`) — **write it down**, it is the rollback
handle. Deletes 35 rows, repairs 4 Patreon rows, touches no member's effective tier.

## Step 3 — verify

```bash
sudo -u looth-dev wp eval-file revoke-orphan-stripe-sources.php --path=/var/www/dev verify
```

Expect `6 orphaned source=stripe rows remain … Clean`. The 6 are the held ones.

Independent confirmation that no member moved — run **before** step 2 and again after,
and diff:

```bash
sudo -u looth-dev wp eval --path=/var/www/dev \
  'foreach([1817,1840,1861,1881,1879,1825,1864,1894] as $u){
     echo $u." ".implode(",", get_userdata($u)->roles)."\n"; }'
```

Expect byte-identical output across the two runs. That is the assertion that matters:
absence of change on the members we claim not to have touched.

## Rollback

```bash
sudo -u looth-dev wp eval-file revoke-orphan-stripe-sources.php --path=/var/www/dev revert <batch-id>
sudo -u looth-dev wp eval-file revoke-orphan-stripe-sources.php --path=/var/www/dev revert  # lists batches
```

Restores every deleted row with its original tier **and original `updated_at`**, undoes
the four Patreon repairs to exactly the state found, and re-runs the Arbiter only where
it was run on the way in. Proven byte-identical by the gate. Refuses to double-revert.

Caveat: if a Patreon sweep ran between apply and revert it may have legitimately
rewritten a Patreon row. The revert says so; re-run `verify`.

---

## The 6 held rows — your decision, member by member

**Not included in step 2.** Nothing happens to these until you say so, one at a time:

```bash
sudo -u looth-dev wp eval-file revoke-orphan-stripe-sources.php --path=/var/www/dev apply allow=1863
```

### Over-tiered — paying $5, receiving the $11 tier

| uid | email | now | correct | evidence |
|---|---|---|---|---|
| 1860 | grant.gong@gmail.com | `looth3` | `looth2` | active, $5 Looth-Lite, charged **8/08** |
| 1862 | swisherguitars@gmail.com | `looth3` | `looth2` | active, $60/yr Looth-Lite, next 2027-07-17 |
| 1884 | jscatches@icloud.com | `looth3` | `looth2` | active, $60/yr Looth-Lite, next 2027-06-05 |

The 7/30 audit's recommendation was to **grandfather these three** and delete the rows
anyway so the state stops being accidental. That is not possible as one step — deleting
the row *is* what demotes them. If you want to grandfather, the clean way is a
`manual_admin = looth3` row (which is exactly what is already protecting 1894), then
delete the stripe row. Say the word and I will add a `grandfather=` mode that does both
in one journalled action.

### Lapsed — holding a paid tier while not paying at all

| uid | email | now | correct | evidence |
|---|---|---|---|---|
| 1863 | andy.gleeman89@gmail.com | `looth2` | `looth1` | **declined_patron**, $0, declined 8/07 |
| 1869 | rob@ripcustomguitars.com | `looth2` | `looth1` | **former_patron**, $0 |
| 1870 | mike.larkin212@gmail.com | `looth3` | `looth1` | **former_patron**, $0, last charge 5/18 |

These three land on `looth1` (free), not on nothing — the Arbiter preserves the free
floor. They have been receiving a paid tier for free since roughly May.

**My recommendation:** apply the three lapsed ones (1863, 1869, 1870) — that is simply
the system working as designed, and it is the same correction Patreon lapsing already
applies to everyone else. Hold the three over-tiered ones (1860, 1862, 1884) until you
decide grandfather-vs-correct, because 1862 and 1884 pre-paid a full year and taking a
tier off a member who has already paid through 2027 is a support conversation, not a
data fix.

---

## What this does NOT touch

- The 3 **bridged** stripe rows (users 1, 596, 1003) — real customers, left alone.
- `customers`, `entitlements`, `subscriptions`, `wp_user_bridge` — untouched.
- No user created, deleted, emailed, or logged out. No Stripe API call.
- Both freeze locks stay on. This changes no code path — only stale rows.

## Still yours to do separately (from the audit, re-verified 8/08 and unchanged)

1. **Rotate the `sk_live_…` key** in the Stripe dashboard, *then* delete
   `pmpro_live_stripe_connect_secretkey` + siblings. **Rotate before deleting** —
   deleting the row does not invalidate the key. It is a live key, on the same account
   the new system will use, belonging to a plugin that no longer exists.
2. The 8 `woocommerce_stripe_*` option rows (4 autoloaded on every request) are dead
   weight from a deactivated plugin.

Neither is blocked by this runbook, and this runbook is not blocked by them.
