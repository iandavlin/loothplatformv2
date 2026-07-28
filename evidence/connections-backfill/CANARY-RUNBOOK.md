# Connections restore — run order for Ian

**Every command below runs on live and is Ian's to run. This lane never touched live.**

There are **two** sets of files. They are numbered so there is no way to paste the wrong one.

| Set | Files | Scope | Tag table |
| --- | --- | --- | --- |
| **Canary — Ian only** | `4-DRYRUN` / `5-APPLY` / `6-ROLLBACK` | **135** rows touching Ian | `connections_restore_20260727_ian` |
| **Full — everyone** | `1-DRYRUN` / `2-APPLY` / `3-ROLLBACK` | **746** rows, 302 members | `connections_restore_20260727` |

All live in `profile-app/sql/2026-07-27-connections-*.sql`.

The canary's 135 rows are a **strict byte-identical subset** of the full 746 — proven by diffing
the generated payload lines against `2-APPLY` (0 lines differ). The canary cannot insert anything
the full set would not.

**The two tag tables are separate on purpose.** Rolling back the canary cannot disturb the full
restore and vice versa. Consequence to remember: if both have been applied, undoing *everything*
means running **file 6 and file 3**.

---

## Run order

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

## Expected numbers

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

## Safety properties (all four rehearsed, see below)

1. **Wrong-database abort** — apply resolves WP user 1 through `wp_user_bridge` and aborts unless
   it equals the uuid the payload was built for.
2. **Truncated-paste abort** — aborts unless the payload is exactly 135 rows.
3. **Idempotent** — the real `UNIQUE (requester_uuid, addressee_uuid)` plus a `NOT EXISTS`
   reverse guard, because that constraint is directional. Running twice inserts nothing.
4. **Exact rollback** — deletes by primary key via the tag table. No date window, no pair
   matching. `ON DELETE CASCADE` means a connection a member deletes afterwards drops its tag,
   so rollback stays correct.

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
