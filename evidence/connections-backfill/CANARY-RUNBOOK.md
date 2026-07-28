# Connections restore — run order for Ian

**Every command below runs on live and is Ian's to run. This lane never touched live.**

There are **three** sets of files. They are numbered so there is no way to paste the wrong one,
and each has its **own tag table** so rolling one back cannot disturb another.

| Set | Files | Scope | Who gets notified | Tag table |
| --- | --- | --- | --- | --- |
| **START HERE — accepted only** | `7-DRYRUN` / `8-APPLY` / `9-ROLLBACK` | **83** accepted rows touching Ian | **nobody** | `…_ian_acc` |
| Ian only, incl. pending | `4-DRYRUN` / `5-APPLY` / `6-ROLLBACK` | **135** rows touching Ian | 52 members get a badge | `…_ian` |
| Full — everyone | `1-DRYRUN` / `2-APPLY` / `3-ROLLBACK` | **746** rows, 302 members | 142 members get a badge | `connections_restore_20260727` |

All live in `profile-app/sql/2026-07-27-connections-*.sql`. **Ian's ruling 2026-07-28: run 7/8/9
first and judge the result before deciding anything that touches other people.** Files 1/2/3 and
4/5/6 stay parked until then.

## Getting the files onto live — never check out the branch

The SQL is on `origin/connections-backfill`, not on main. **Do not check that branch out in the
serving checkout** — that is the 2026-07-26 outage. `git show` reads a blob straight out of a
fetched ref without touching the working tree:

```bash
git fetch origin connections-backfill
git show origin/connections-backfill:profile-app/sql/2026-07-27-connections-canary-ian-7-DRYRUN.sql   > /tmp/7-dryrun.sql
git show origin/connections-backfill:profile-app/sql/2026-07-27-connections-canary-ian-8-APPLY.sql    > /tmp/8-apply.sql
git show origin/connections-backfill:profile-app/sql/2026-07-27-connections-canary-ian-9-ROLLBACK.sql > /tmp/9-rollback.sql
```

`fetch` only moves a remote ref; `show` only reads. Neither touches the working tree.

## The accepted-only set (7/8/9) — start here

```
0.  psql -f /tmp/9-rollback.sql    # the escape hatch — know it before you start
1.  psql -f /tmp/7-dryrun.sql      # read-only, writes nothing
2.  psql -f /tmp/8-apply.sql       # inserts 83
3.  look at the profile            # ← the actual canary
4.  psql -f /tmp/7-dryrun.sql      # verify: WILL INSERT = 0, already present = 83
```

**Expected, measured against live 2026-07-28.** Stop if `UUID MATCH` is not `true`.

```
rows in this accepted-only canary          83
every row is accepted                    true
UUID MATCH                               true
WILL INSERT (after guards)                 83
already present (skipped)                   0
members who get an incoming-request badge   0
members who get a bell notification         0
members who get an email                    0
Ian before: 1251 accepted, 0 pending IN, 427 pending OUT
```

After apply: **Ian on 1334 accepted, pending untouched at 0 IN / 427 OUT.** Nobody is notified —
the only visible change is that these connections appear in both members' lists and counts, which
is the point of the restore.

---

## APPLIED — the 83 landed on live 2026-07-28

Verified read-only on live: the tag table `connections_restore_20260727_ian_acc` is **present**,
Ian is on **1334 accepted** (1251 + 83, exactly as the dry run predicted) and **pending is
unchanged at 427**. Nobody was notified. File 9 remains the rollback if he wants it undone.

Only that one tag table exists on live — 1/2/3 and 4/5/6 are confirmed **not** applied.

# The re-request fix (10/11/12) — a DIFFERENT defect

Ian found this from the UI: people he re-requested "stayed as requested". These pairs **already
have a row**, with the wrong status, so no INSERT can fix them — they need an UPDATE. The restore
sets are blind to them by construction (their predicate is "no row at all").

| | |
| --- | --- |
| rows | **81** pending that should be accepted |
| whose | 5 members re-requested by hand: wp 197 (53), Ian (23), wp 1431 (3), wp 303 (1), wp 244 (1) |
| when | four batches in July — a cleanup, not an ongoing bug |
| notifies | **nobody.** It removes 81 stale requests; **71 members' badges go DOWN** |
| tag table | `connections_restatus_20260728`, records **prior_status** |

```bash
git show origin/connections-backfill:profile-app/sql/2026-07-28-connections-restatus-10-DRYRUN.sql   > /tmp/10-dryrun.sql
git show origin/connections-backfill:profile-app/sql/2026-07-28-connections-restatus-11-APPLY.sql    > /tmp/11-apply.sql
git show origin/connections-backfill:profile-app/sql/2026-07-28-connections-restatus-12-ROLLBACK.sql > /tmp/12-rollback.sql
```

Same order: know 12 first, then 10 (read-only), 11, then 10 again to verify. Live dry run says
81 found / 81 will flip / 0 already accepted / 0 gone since measurement / 0 new badges / 71 cleared.

**Rollback caveat, unique to this set:** file 12 restores **status** exactly, replaying the
recorded `prior_status` rather than assuming it. It cannot restore `updated_at` — the
`connections_touch` trigger stamps `now()` on every UPDATE. The restore sets rolled back
byte-identically; an UPDATE-based fix cannot.

## Re-verified against live 2026-07-28 13:30 UTC — NO DRIFT, payload still exact

This defect is produced **by hand**, so the payload was re-checked ~10 hours after it was frozen
rather than assumed. Two independent methods, both read-only:

1. The shipping `10-DRYRUN` re-run against live: `UUID MATCH true`, **81 found / 81 will flip /
   0 already accepted / 0 other status / 0 gone since measurement**, 71 badges clear, Ian 23.
2. A full re-derivation from **both stores** (live MySQL `wp_bp_friends` + live Postgres
   `connections`, joined off-box), which reproduces every number in the classification:

```
legacy confirmed friendships   7609
  correctly accepted           7251
  missing entirely              271     <- the restore's territory
  WRONG STATUS (pending)         81     <- this fix
  both directions (shape c)       5     (4 accepted/accepted, 1 accepted/pending)
  unmappable (no bridge)          1
                        TOTAL  7609     (sums exactly)
shapes of the 81: same-direction 74, opposite 7
```

**0 new, 0 gone.** Nobody re-requested anything in the window, so the frozen 81 is still the
whole defect. Re-run method 1 before applying if more than a day has passed — if `WILL FLIP` is
below 81 someone resolved a pair by hand, and if a *new* wrong-status row appears it will **not**
be in this payload (the payload is a fixed list; file 11's abort checks the payload's own row
count, not live's, so a new defect row is silently left unfixed rather than causing a failure).

**A pending row appears for 82 pairs, not 81 — that is correct.** The 82nd is the shape-(c) pair
wp 1 ↔ wp 733: conn **13753** (`1→733`, pending, dated 2024-05-11) sits alongside conn **20594**
(`733→1`, **accepted**, 2026-04-27). The relationship already reads as accepted, so it is
excluded by design. Any recount that collapses pairs without checking for a second row will find
82 and appear to contradict this file.

## The 83 that were applied are all still there

Checked on the same pass: of Ian's legacy-confirmed friendships, **zero** are missing a
`connections` row. Before the restore 83 were missing; nobody has removed a restored connection.
Ian reads **1334 accepted / 427 pending OUT / 0 pending IN** — unchanged from the apply.

*Method trap, recorded because it cost a wrong number first:* resolving connections to WP ids by
joining `wp_user_bridge` on **both** sides silently drops every row whose counterparty is a
profile-app-native member (Patreon-provisioned, no `wp_users` row). That read Ian as 1333/426 and
missing as 272. Map through uuids and compare pairs by uuid — then the classification sums to
7609 exactly. A total that does not sum to the population is the tell.

# PARKED — do not run until Ian has judged the 83

Everything below concerns the **135-row (4/5/6)** and **746-row (1/2/3)** sets. Both put an
incoming request in front of other members. Ian's 2026-07-28 ruling parks them.

## The 135-row set (4/5/6)

The canary's 135 rows are a **strict byte-identical subset** of the full 746 — proven by diffing
the generated payload lines against `2-APPLY` (0 lines differ). The canary cannot insert anything
the full set would not.

**The two tag tables are separate on purpose.** Rolling back the canary cannot disturb the full
restore and vice versa. Consequence to remember: if both have been applied, undoing *everything*
means running **file 6 and file 3**.

---

### Run order for 4/5/6 (parked)

```
1.  psql -f 2026-07-27-connections-canary-ian-4-DRYRUN.sql     # read-only, writes nothing
2.  psql -f 2026-07-27-connections-canary-ian-5-APPLY.sql      # inserts 135
3.  look at the profile                                        # ← the actual canary
4.  psql -f 2026-07-27-connections-canary-ian-4-DRYRUN.sql     # verify: WILL INSERT = 0
    ... only then ...
5.  psql -f 2026-07-27-connections-restore-1-DRYRUN.sql        # read-only
6.  psql -f 2026-07-27-connections-restore-2-APPLY.sql         # inserts the remaining 610
7.  psql -f 2026-07-27-connections-restore-1-DRYRUN.sql        # verify: WILL INSERT = 0
```

Rollback at any point: file **6** undoes the canary, file **3** undoes the full set.

### Expected numbers for 4/5/6

**Canary dry run** — `UUID MATCH` must say `true`; if it does not, stop, you are on the wrong box.

```
rows in this canary          135
WILL INSERT (after guards)   135
  of which accepted           83
  of which pending            52
    pending Ian SENT          52     ← outbound; lands on THEIR side
    pending Ian RECEIVED       0     ← nothing new appears in Ian's incoming
other members whose badge +1  52
```

**Canary apply** — `canary rows inserted and tagged (cumulative) = 135`, and Ian's totals move
**+83 accepted / +52 pending**.

**Full apply after the canary** — `rows inserted and tagged = 610`, measured against live on
2026-07-28 (746 − Ian's 135, which are already present and are skipped by the guards, − 1 pair a
member created organically on 2026-07-27). The dev2 rehearsal predicted 611 because it had no
such drift; live is the number to expect.

## What Ian will actually see, and what 52 other people will see

- **Ian:** +83 accepted connections. His 52 pending appear in his **outgoing / awaiting** list,
  not his incoming. His own incoming-request badge does not move.
- **52 other members:** each gets **one** new incoming connection request from Ian, dated when it
  was originally sent (2023-06-21 → 2026-04-18). Their header friends badge goes +1.
- **No bell, no email, for anyone.** Notification rows are written only by
  `Notifications::push()` in PHP; a direct SQL insert mints nothing. Verified on live that
  `connections` carries exactly one trigger, `connections_touch`, `BEFORE UPDATE` only. Confirmed
  against the email strategy in `docs/atlas/NOTIF-EMAIL-STATE.md`: no per-event email exists at
  all, and the weekly digest reads the `notifications` table, which stays empty of these.
- **Do not re-run** `profile-app/bin/migrate-social-from-bb.php` afterwards — its
  `seedNotifications()` would turn every one of these silent badges into a real bell.

## Re-sent-request check — re-run for the 83 on 2026-07-28

Ian manually re-sent a batch of connection requests in the last few days. Measured on live: **56
rows created in the last 14 days** (52 pending OUT, 2 accepted IN, 2 accepted OUT).
**Direction-agnostic overlap with the 83 counterparties is ZERO**, and with all 135 also zero. So
no row in this restore collides with anything he has just done.

**One correction on the mechanics.** An opposite-direction re-request would *not* create a
duplicate relationship row with these files. That hazard belongs to the original backfill script,
which had only the directional `ON CONFLICT`. Every file in this lane also carries a `NOT EXISTS`
reverse guard added for exactly this case. Tested rather than argued: planting a reverse pending
row for one of the 83 made the apply insert **82**, skip that pair, and leave **0** reciprocal
duplicates.

## Safety properties (all rehearsed, see below)

1. **Wrong-database abort** — apply resolves WP user 1 through `wp_user_bridge` and aborts unless
   it equals the uuid the payload was built for.
2. **Truncated-paste abort** — aborts unless the payload is exactly the expected row
   count for that set (83 for 7/8/9, 135 for 4/5/6, 746 for 1/2/3).
3. **Idempotent** — the real `UNIQUE (requester_uuid, addressee_uuid)` plus a `NOT EXISTS`
   reverse guard, because that constraint is directional. Running twice inserts nothing.
4. **Exact rollback** — deletes by primary key via the tag table. No date window, no pair
   matching. `ON DELETE CASCADE` means a connection a member deletes afterwards drops its tag,
   so rollback stays correct.
5. **Accepted-only assertion (files 7/8/9)** — the apply aborts if *any* payload row is not
   `accepted`, so the file cannot be edited into something that notifies people without failing
   loudly. Proven: flipping one row to `pending` aborts with *1 non-accepted rows in an
   ACCEPTED-ONLY file* and changes nothing.

## Pre-flight — both dry runs executed against LIVE, 2026-07-28

Both dry-run files are read-only and were run against live itself as `looth_ro`
(`psql -h 127.0.0.1 -U looth_ro -d profile_app -f -`, SQL piped over stdin). They execute
cleanly on the real box, so steps 1 and 4 below are proven, not just rehearsed.

**Canary dry run on live** — `UUID MATCH` = **true**, so the wrong-box guard passes:

```
rows in this canary          135      already present (skipped)   0
WILL INSERT                  135      Ian now: 1251 accepted
  accepted                    83               0 pending IN
  pending                     52             427 pending OUT
    pending Ian SENT          52
    pending Ian RECEIVED       0
other members whose badge +1  52
```

After the canary Ian lands on **1334 accepted / 479 pending out**, and his incoming stays at
**zero**. The direction finding is confirmed on live, not inferred from dev2.

**Full dry run on live** — `746` payload, **`745` will insert**, 354 accepted / 391 pending,
302 members. The 1 already-present row is the pair a member created organically at 22:31 on
2026-07-27; the guards skip it correctly against real drift. It is not one of Ian's, so **after
the canary the full apply inserts 610**.

**The write path is still unproven and stays that way here.** `looth_ro` is read-only by design
— a `CREATE TABLE` probe returns *permission denied for schema public*. The apply commands need
a role that can write, and that is Ian's to run.

## Rehearsal — 2026-07-27, throwaway replica on dev2, dropped after

Replica built from dev2's `profile_app` (`connections`, `users`, `wp_user_bridge`, plus the
`connections_touch` trigger), then all 746 payload pairs deleted in **both** directions to
reconstruct live's gap. dev2 resolves WP user 1 to the same uuid as live — the uuids are
deterministic — so the guards exercise truthfully.

| Step | Result |
| --- | --- |
| Canary dry run | 135 / 83 acc / 52 pend / 52 outbound / 0 inbound; table hash **unchanged** |
| Canary apply | inserted + tagged **135**; Ian 1233→**1316** accepted, 370→**422** pending |
| Canary apply again | **0** more |
| Canary dry run as verify | `WILL INSERT` **0**, `already present` **135** |
| Full apply on top | inserted **611**; tag-table overlap with canary **0** |
| Canary rollback | deleted exactly **135**; full tag table still **611** |
| Full rollback | deleted **611**; table hash **80194fb4fcfc56d6af37cb6bda5e8967 = baseline** |

Guard tests: pointing the bridge at a different uuid aborted with
`wrong database, ABORTING`; a 134-row payload aborted with `file is truncated, ABORTING`. After
both, the table was unchanged and the tag table absent — the transaction rolled back cleanly.

**Caveat, stated plainly:** the rehearsal proves mechanics, guards and rollback exactness. It
does **not** predict live's insert counts, because dev2's `connections` is not live's. The
canary dry run on live is what confirms the real numbers, and it writes nothing.

## Rehearsal — files 7/8/9, 2026-07-28, throwaway replica, dropped after

Same method as 4/5/6: replica from dev2's `profile_app`, all 746 payload pairs deleted in both
directions to reconstruct live's gap.

| Step | Result |
| --- | --- |
| 7-DRYRUN | 83 / all accepted / UUID match; table hash **unchanged** |
| 8-APPLY | inserted + tagged **83**; Ian 1233→**1316** accepted, **pending unchanged at 370** |
| 8-APPLY again | **0** more |
| 7-DRYRUN as verify | `WILL INSERT` **0**, `already present` **83** |
| Opposite-direction test | reverse row planted → inserted **82**, pair skipped, **0** reciprocal duplicates |
| 9-ROLLBACK | deleted exactly **83**; hash back to baseline, **byte-identical** |
| Tamper test | one row flipped to `pending` → **aborted**, table unchanged, tag table absent |

Then `7-DRYRUN` was executed against **live** read-only: `UUID MATCH true`, `WILL INSERT 83`,
`already present 0`, `0` badges / `0` bells / `0` emails.
