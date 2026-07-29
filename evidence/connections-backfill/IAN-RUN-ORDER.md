# Connections — run order for Ian

**Everything below runs on LIVE and is Ian's to run.** Nothing is on `main`; it is all on
`origin/connections-backfill`. Don't try to match a commit SHA — the branch moves whenever this
document is edited. The check that matters is the payload row count in step 1.

**Ian's ruling 2026-07-29: his own stuck-as-requested rows go first, as a canary.** He looks at
his own profile, and only when he is happy do the other members follow. Same pattern that earned
trust for the 83.

| order | files | what it does | rows | who is notified |
| --- | --- | --- | --- | --- |
| ✅ done | `7`/`8`/`9` | restored Ian's missing connections | 83 | nobody |
| **▶ 1. CANARY** | **`16`/`17`/`18`** | **fixes Ian's stuck-as-requested** | **23** | **nobody** |
| then 2 | `13`/`14`/`15` | restores everyone else's missing | 271 | nobody |
| then 3 | `10`/`11`/`12` | fixes everyone else's stuck | 58 more | nobody |
| ❌ never | `1`–`6` | superseded (they include pending) | — | — |

---

## Step 1 — get the files onto live

The SQL is on the branch, not on main. **Do not check the branch out** — `git show` reads the blob
without touching the working tree. This is the wall you hit before.

```bash
git fetch origin connections-backfill
B=origin/connections-backfill

# the canary — run these first
git show $B:profile-app/sql/2026-07-29-connections-restatus-ian-16-DRYRUN.sql   > /tmp/16-dryrun.sql
git show $B:profile-app/sql/2026-07-29-connections-restatus-ian-17-APPLY.sql    > /tmp/17-apply.sql
git show $B:profile-app/sql/2026-07-29-connections-restatus-ian-18-ROLLBACK.sql > /tmp/18-rollback.sql

# the rest — only after you are happy with the canary
git show $B:profile-app/sql/2026-07-28-connections-restore-all-13-DRYRUN.sql   > /tmp/13-dryrun.sql
git show $B:profile-app/sql/2026-07-28-connections-restore-all-14-APPLY.sql    > /tmp/14-apply.sql
git show $B:profile-app/sql/2026-07-28-connections-restore-all-15-ROLLBACK.sql > /tmp/15-rollback.sql
git show $B:profile-app/sql/2026-07-28-connections-restatus-10-DRYRUN.sql      > /tmp/10-dryrun.sql
git show $B:profile-app/sql/2026-07-28-connections-restatus-11-APPLY.sql       > /tmp/11-apply.sql
git show $B:profile-app/sql/2026-07-28-connections-restatus-12-ROLLBACK.sql    > /tmp/12-rollback.sql

chmod 644 /tmp/1[0-8]-*.sql     # you create these as ubuntu; psql reads them as another user
ls -l /tmp/1[0-8]-*.sql         # nine files, all -rw-r--r--
```

Integrity check — quicker to know now than to have an apply abort:

```bash
grep -c "::uuid," /tmp/17-apply.sql   # expect  23   <- the canary
grep -c "::uuid," /tmp/14-apply.sql   # expect 271
grep -c "::uuid," /tmp/11-apply.sql   # expect  81
```

**Which role.** `ubuntu` is not a Postgres role. Use the same invocation you used for the 83 —
database `profile_app`, the **write** role, not `looth_ro` (`looth_ro` is read-only and the applies
will fail on it). The dry runs are read-only and will run as either.

---

## Step 2 — THE CANARY (files 16/17/18): your 23 stuck-as-requested

These are people you were genuinely connected to in the old site, whom you re-requested on
2026-07-27, and who have sat as "requested" ever since. They already have a row with the wrong
status, so no insert can fix them — they need an update.

**Know the escape hatch before you start. Do not run it now — just have it.**

```bash
#  ROLLBACK, if you want this undone:   psql -f /tmp/18-rollback.sql
```

```bash
psql -f /tmp/16-dryrun.sql     # 1. read-only, writes nothing
psql -f /tmp/17-apply.sql      # 2. flips 23
#    ... look at your profile ...          <- THE ACTUAL CANARY
psql -f /tmp/16-dryrun.sql     # 3. verify
```

### What the dry run must print — measured against live 2026-07-29

```
pairs in this canary                            23
UUID MATCH (must say true)                    true      <- STOP if not true
every pair touches Ian                        true      <- STOP if not true
rows found on this box                          23
WILL FLIP pending -> accepted                   23
already accepted (skipped)                       0
other status (skipped)                           0
gone since measurement                           0
members who get a NEW incoming-request badge     0
members who get a bell notification              0
members who get an email                         0
members whose STALE incoming badge CLEARS       23
Ian accepted now                              1334
Ian accepted AFTER                            1357
Ian pending OUT now                            427
Ian pending OUT AFTER                          404
Ian pending IN (untouched)                       0
```

**Nobody is notified — this fix only removes requests.** The friends badge counts
`status='pending'` only, so every affected inbox goes **down**. A bell is a row in
`notifications`, written solely by `Notifications::push()` in PHP — a raw SQL UPDATE mints none.
No per-event email exists at all. You sent all 23 of these yourself, so the only people affected
are 23 members who stop seeing a stale request from you.

**After apply, step 3 must print `WILL FLIP = 0` and `already accepted = 23`.**

### Two things you WILL see that this fix did not cause and does not fix

Read these before you judge the canary, or a working fix will look broken.

1. **One person will still show as "requested" afterwards.** That is **deliberate**. This pair has
   **two rows** — one saying you requested them, one saying you are already connected — so the
   relationship already reads as accepted and the stale outgoing request is a *different* defect
   from the 23. Touching it means deleting a live row, which is a separate risk conversation I
   have not opened without you. If you want it gone, say so and I will scope it properly.

2. **Four people appear twice in your connections list, and your count is inflated by 4.** Your
   1334 counts 4 relationships twice, because each has a duplicate row from before the migration
   (both marked accepted). The list query has no `DISTINCT`, so both rows render. **Pre-existing,
   nothing to do with any of this work, and not fixed by it.** Worth knowing it exists — it is a
   real render defect and I can scope it separately.

---

## Step 3 — once you are happy: everyone else

Only after the canary looks right. Rollback line first, as before.

```bash
#  ROLLBACKS:  psql -f /tmp/15-rollback.sql   (the 271)
#              psql -f /tmp/12-rollback.sql   (the 58)

psql -f /tmp/13-dryrun.sql     # 271 restore — read-only
psql -f /tmp/14-apply.sql      # inserts 271
psql -f /tmp/13-dryrun.sql     # verify: WILL INSERT 0, already present 271

psql -f /tmp/10-dryrun.sql     # the stuck-as-requested set — read-only
psql -f /tmp/11-apply.sql      # flips the remaining 58
psql -f /tmp/10-dryrun.sql     # verify: WILL FLIP 0, already accepted 81
```

**Expected for the 271:** `WILL INSERT 271`, `already present` **0** in both directions,
`rows touching Ian` **0** (yours were file 8), 0 badges / 0 bells / 0 emails.

**Expected for the stuck set, and this WILL look surprising — it is correct.** That file says 81
in its header, but after the canary its dry run reads:

```
pairs in this fix                 81
WILL FLIP pending -> accepted     58      <- NOT 81. Your 23 are already done.
already accepted (skipped)        23      <- the canary's rows
```

**58 + 23 = 81.** This is the guards working, not drift. Rehearsed: running the canary first and
the 81 afterwards flips exactly 58 more, and the two tag tables share no rows.

---

## If anything looks wrong

- **`UUID MATCH` or `every pair touches Ian` not `true`** — wrong database. Stop. Nothing has been
  written; dry runs write nothing and the applies abort on this.
- **A number lower than expected** — someone resolved a connection by hand since this was measured
  (2026-07-29 01:30 UTC). Not dangerous: the guards skip whatever is already correct. Send me the
  output.
- **Anything else** — the rollback for that set. Each reverts only rows its own set touched, by
  primary key, and each has its own tag table, so undoing one cannot disturb another.

**One honest asymmetry, stated before you run rather than after.** The 271 restore rolls back
byte-identically, because it only inserted. The two *status* fixes (16/17/18 and 10/11/12) restore
the **status** exactly — they record the prior value rather than assuming it — but **cannot**
restore `updated_at`, because the `connections_touch` trigger stamps `now()` on every UPDATE.
Nothing can avoid that.

**Optional at any point** — `POST-APPLY-CHECK.sql` reports what the dry runs cannot: reciprocal
duplicates, `created_at` survival, and tag-table overlap across all the sets.
