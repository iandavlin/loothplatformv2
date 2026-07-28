# Connections — run order for Ian, 2026-07-28

**Both sets, restore first. Everything below runs on LIVE and is Ian's to run.**
Nothing here is on `main` — it is all on `origin/connections-backfill`.

Don't bother matching a commit SHA; the branch moves every time this document is edited. The
check that actually matters is the row count of each extracted payload, in step 1.

Two independent fixes. Neither notifies anyone. Each rolls back on its own.

| | what | rows | members |
| --- | --- | --- | --- |
| **A** — files 13/14/15 | restores connections that are **missing** | 271 | 164 |
| **B** — files 10/11/12 | corrects connections **stuck as "requested"** | 81 | 76 |

---

## Step 1 — get the six files onto live

The SQL is on the branch, not on main. **Do not check the branch out** — `git show` reads the
blob without touching the working tree. This is the wall you hit last night.

```bash
git fetch origin connections-backfill

B=origin/connections-backfill
git show $B:profile-app/sql/2026-07-28-connections-restore-all-13-DRYRUN.sql   > /tmp/13-dryrun.sql
git show $B:profile-app/sql/2026-07-28-connections-restore-all-14-APPLY.sql    > /tmp/14-apply.sql
git show $B:profile-app/sql/2026-07-28-connections-restore-all-15-ROLLBACK.sql > /tmp/15-rollback.sql
git show $B:profile-app/sql/2026-07-28-connections-restatus-10-DRYRUN.sql      > /tmp/10-dryrun.sql
git show $B:profile-app/sql/2026-07-28-connections-restatus-11-APPLY.sql       > /tmp/11-apply.sql
git show $B:profile-app/sql/2026-07-28-connections-restatus-12-ROLLBACK.sql    > /tmp/12-rollback.sql

chmod 644 /tmp/1[0-5]-*.sql        # you create these as ubuntu; psql reads them as another user
ls -l /tmp/1[0-5]-*.sql            # six files, all -rw-r--r--
```

Sanity-check the two payloads actually arrived whole — if a file is truncated the apply aborts
rather than doing half the job, but it is quicker to know now:

```bash
grep -c "::uuid," /tmp/14-apply.sql   # expect 271
grep -c "::uuid," /tmp/11-apply.sql   # expect  81
```

Use **the same psql invocation you used for the 83 last night** (database `profile_app`; the
write role, not `looth_ro`). Everything below is written as plain `psql -f`.

---

## Step 2 — SET A: restore the 271 missing connections

**Know the escape hatch before you start. Do not run it now — just have it.**

```bash
#  ROLLBACK, if you want this undone:   psql -f /tmp/15-rollback.sql
```

```bash
psql -f /tmp/13-dryrun.sql     # 1. read-only, writes nothing
psql -f /tmp/14-apply.sql      # 2. inserts 271
psql -f /tmp/13-dryrun.sql     # 3. verify
```

### What the dry run must print — step 1

```
rows in this restore                        271
every row is accepted                      true
members affected                            164
UUID MATCH (must say true)                 true      <- STOP if not true
payload uuids NOT found in users              0      <- STOP if not 0
WILL INSERT (after guards)                  271
already present, same direction               0
already present, OPPOSITE dir                 0
members who get an incoming-request badge     0
members who get a bell notification           0
members who get an email                      0
pending rows created by this file             0
rows touching Ian                             0      <- your 83 were already done
oldest connection restored          2023-06-20
newest connection restored          2026-06-02
```

**Nobody is notified, and here is why.** The friends badge counts `status='pending'` only, and
every row here is `accepted`, so no badge moves. A bell is a row in `notifications`, written only
by `Notifications::push()` in PHP — a raw SQL INSERT mints none. No per-event email exists at all,
and the weekly digest reads `notifications`, which stays empty of these. `connections` carries
exactly one trigger, `connections_touch`, and it is `BEFORE UPDATE` only — this is an INSERT.
The only visible change is that these connections reappear in both members' lists and counts.

**After apply, step 3 must print `WILL INSERT = 0` and `already present, same direction = 271`.**
That is the proof it landed. `rows touching Ian` stays `0` — your own totals do not move at all in
set A.

---

## Step 3 — SET B: correct the 81 stuck as "requested"

These are the ones you spotted in the UI. They already have a row, with the wrong status, so no
insert can fix them — they need an update.

```bash
#  ROLLBACK, if you want this undone:   psql -f /tmp/12-rollback.sql
```

```bash
psql -f /tmp/10-dryrun.sql     # 1. read-only, writes nothing
psql -f /tmp/11-apply.sql      # 2. flips 81
psql -f /tmp/10-dryrun.sql     # 3. verify
```

### What the dry run must print — step 1

```
pairs in this fix                             81
UUID MATCH (must say true)                  true      <- STOP if not true
rows found on this box                        81
WILL FLIP pending -> accepted                 81
already accepted (skipped)                     0
other status (skipped)                         0
gone since measurement                         0
members who get a NEW incoming-request badge   0
members who get a bell notification            0
members who get an email                       0
members whose STALE incoming badge CLEARS     71
badges cleared in total                       81
Ian rows in this fix                          23
Ian accepted now                            1334
Ian pending OUT now                          427
```

**This one is the opposite of a notification — it removes 81 stale requests.** Nobody is pinged;
71 members stop seeing a request they never asked for. Your own incoming stays at 0.

**After apply, step 3 must print `WILL FLIP = 0` and `already accepted = 81`**, and
`Ian accepted now` moves **1334 → 1357** (+23).

---

## Step 4 — optional, one command that checks both sets at once

Not required — each set's own step 3 already proves it landed. This adds the things a dry run
cannot see: that no reciprocal duplicate was created, that the original `created_at` survived, and
that the two tag tables do not overlap (which is what keeps the two rollbacks independent).

```bash
git show $B:evidence/connections-backfill/POST-APPLY-CHECK.sql > /tmp/check.sql
chmod 644 /tmp/check.sql
psql -f /tmp/check.sql          # read-only; safe before, between or after
```

Expect `rows tagged 271` / `rows tagged 81` / `tag-table OVERLAP 0` / `reciprocal duplicate rows
10` (that 10 is **pre-existing** — 5 bidirectional pairs that predate all of this work; more than
10 would mean something created one) and `Ian accepted 1357 / pending OUT 404`.

## If anything looks wrong

- **`UUID MATCH` not `true`, or `payload uuids NOT found in users` not `0`** — wrong database.
  Stop. Nothing has been written; the dry runs write nothing and the applies abort on this.
- **A number is lower than expected** — someone resolved a connection by hand since this was
  measured (14:23 UTC 2026-07-28). Not dangerous: the guards skip what is already correct. Send
  me the output.
- **Anything else** — `psql -f /tmp/15-rollback.sql` for set A, `/tmp/12-rollback.sql` for set B.
  Each deletes/reverts only rows its own set touched, by primary key.

**The two sets never interact.** Set A inserts rows that are absent; set B updates rows that
exist. The two populations are provably disjoint — intersection **0** — and they use separate tag
tables, so rolling back one cannot disturb the other.

*One honest asymmetry:* set A's rollback is byte-identical, because it only inserted. Set B's
rollback restores the **status** exactly (it records the prior value rather than assuming it) but
**not** `updated_at` — the `connections_touch` trigger stamps `now()` on every UPDATE. Nothing can
avoid that.
