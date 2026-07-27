# Connections backfill — the June cutover gap

**Lane:** connections-backfill · **Date:** 2026-07-27 · **Branch:** `connections-backfill`
**Live was READ-ONLY throughout.** No row on live was created, altered or deleted by this lane.

---

## A. Ian's question, answered

**Ian is missing 136 connections — 83 accepted and 53 pending.**

His hypothesis was *nearly* right but not quite, and the difference matters. The connections
**were** backfilled from the old BuddyBoss DB: 10,609 of the 11,363 legacy friendships are
present in `public.connections` today, carrying their original timestamps back to
2023-06-15. So the migration ran and it mostly worked.

What went wrong is narrower and more specific. The backfill could only import a friendship
if **both** people already had a row in `wp_user_bridge` when it ran. 241 members were added
to that bridge *after* the backfill — 181 of them on 2026-06-11, nine days after cutover —
and every friendship touching one of those members was silently skipped. **All 136 of Ian's
missing connections are with people in that late-added group.** He did not lose a random
slice; he lost exactly the people the backfill could not yet see.

## B. The whole membership

**750 friendships are missing** — 355 accepted, 395 pending — across **306 affected members**.

**748 of the 750** involve at least one late-bridged member. Only 2 rows are unexplained by
that single cause. The other candidate explanations were tested and ruled out:

| Hypothesis | Verdict |
| --- | --- |
| Migration never ran for this table | **No** — 10,609 of 11,359 mappable rows are present with original dates |
| Unmappable users (no bridge row) | **No** — only 4 legacy rows have an unmappable endpoint |
| Status/state mismatch | **No** — `is_confirmed=1→accepted` holds 7,169:73; the off-diagonal is post-cutover member activity |
| Direction flipped on import | **No** — 10,598 matched same-direction vs 11 reversed |
| Self-pairs / duplicate pairs collapsing | **No** — 0 self-friendships, 11,358 distinct pairs from 11,359 rows |
| **Users bridged after the backfill ran** | **YES — 748 of 750** |

There are two distinct classes of victim, and they are not equally hurt:

- **The late-added members — 70 of them lost more than 80% of their connections.** They log
  in and the platform looks empty. This is the severe class and none of them have complained,
  which is worth noting on its own.
- **Long-established, heavily-connected members** lost 5–12%: Ian 136/1762 (8%),
  Grace Da Maren 101, Michael Bashkin 86, Thom Abell 59. Less visible per-person, but it is
  why Ian noticed at all.

### Root cause

`tools/backfill-bb-connections.sh` **predicted this in its own header**:

```
# Run on dev 2026-06-11 (10,377 imported); RE-RUN AT CUTOVER against live data —
# rows skipped today (~1,018) are users not yet in wp_user_bridge (unprovisioned),
# self-pairs, and reverse-duplicate pairs; the ON CONFLICT guard makes re-runs safe.
```

The re-run it asks for never happened. This is not a mystery — it is a documented follow-up
step that fell off the cutover list.

## C. Dry run

- **`dry-run-750-rows.tsv`** — all 750 proposed rows: both member names, both UUIDs, status,
  original date, and the attributed cause per row.
- **`gap-report.html`** — the same, as a bannered report for Ian.

## D. The command for Ian

### Rollback — read this first

```bash
sudo -u postgres psql -d profile_app \
  -f /home/ubuntu/loothplatformv2-clean/profile-app/sql/2026-07-27-connections-backfill-gap-ROLLBACK.sql
```

Deletes exactly the 750 pairs the backfill inserted and nothing else. Safe by construction:
none of those pairs existed on live beforehand (that is the definition of the set), and the
`UNIQUE (requester_uuid, addressee_uuid)` constraint means organic activity cannot have
re-created one while the backfill row occupied the slot. It cannot delete a member-made
connection.

### The command

Requires this branch merged to main and deployed (`lg-deploy`), so the file is traceable to a
commit. Then, on live:

```bash
sudo -u postgres psql -d profile_app \
  -f /home/ubuntu/loothplatformv2-clean/profile-app/sql/2026-07-27-connections-backfill-gap.sql
```

It runs in a single transaction, prints before/after counts and Ian's own totals, and aborts
on any error. Expected output:

```
accepted 7423 -> 7778   (+355)
pending  3439 -> 3834   (+395)
Ian:  accepted 1251 -> 1334,  pending 427 -> 480
```

### One deliberate difference from the original script

The original relied only on `ON CONFLICT (requester_uuid, addressee_uuid)`. That constraint is
**directional**, so it cannot see an existing *reverse* row — which is why live already carries
**10 duplicated bidirectional rows**. Re-running the original script as-is would have added
**11 more**. The delivered script adds a `NOT EXISTS` reverse guard and adds none.

### Verification performed

Executed against a local throwaway Postgres replica on dev2, loaded from this same live
snapshot (1,876 users / 10,862 connections):

- insert adds **exactly 750** rows, and the +355/+395 split lands as predicted
- Ian's totals land on **1,334 accepted / 480 pending** — exactly +83/+53
- second run adds **0** (idempotent)
- bidirectional duplicates stay at **10** — the guard holds
- rollback restores the table to **byte-identical** state (matching MD5) vs the live snapshot

## Open items for Ian

1. **The 2 unexplained rows** are included in the backfill. Both are unconfirmed legacy
   requests to Magnús Gunnar (WP 408) from Markus Heidarsson (690) and John Husted (1605);
   nobody involved is archived and no cause was found. They are legitimately absent and
   restoring them is correct, but they are not explained by the cohort theory.
2. **The 10 pre-existing bidirectional duplicate rows** are a separate, older defect from the
   original script. Not fixed here — this lane's scope was the missing rows, and deleting
   existing connection rows is a different risk conversation. Worth its own pass.
3. **The late-bridged members were also skipped by anything else keyed on `wp_user_bridge` at
   cutover.** Connections is the table Ian noticed. It is worth checking whether the same 241
   members are short on other migrated data.
