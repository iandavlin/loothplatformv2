# Duplicate connection rows — 5 pairs, all Ian's, pre-existing

**Found 2026-07-29 while checking what Ian would see during his canary. Measured on LIVE.
INVENTORY ONLY — nothing fixed, nothing proposed as built.**

This is **not** caused by any of the restore or re-status work, and none of that work fixes it.
It is written down because Ian is about to inspect his own connections list as a canary, and two
of these are visible to him. Without this note a working fix reads as a broken one.

## What it is

`connections` has a **directional** `UNIQUE (requester_uuid, addressee_uuid)`, so a pair can hold
two rows — one each way — describing one relationship. Live has **5 such pairs**, and **all 5
involve Ian**:

| statuses | pairs | what the member sees |
| --- | --- | --- |
| accepted / accepted | **4** | the person appears **twice** in the connections list |
| accepted / pending | **1** | shows as **connected AND requested** at the same time |

**5 distinct members** have a double-counting list: Ian (who sees 4 duplicates) and the 4
counterparties (who each see Ian twice).

The render has no dedupe. `Connections.php:207-213` selects one row per `connections` row —
`WHERE c.status='accepted' AND :u IN (c.requester_uuid, c.addressee_uuid)`, no `DISTINCT`, no
pair collapse. So both rows of a pair render.

**Consequence for Ian's numbers:** his 1334 accepted counts 4 relationships twice. His true
distinct count is **1330**.

## It is not ongoing — the app cannot create these today

Checked rather than assumed, because "is something still producing this?" is the question that
changes what to do. Every writer guards against it:

- `Connections::request()` calls `edge()` first, and `edge()` matches **both directions**
  (`Connections.php:28-38`), so a reverse-direction request is rejected with `edge_exists`.
- `migrate-social-from-bb.php` runs its own bidirectional `edgeExists` check before inserting.
- Every file in this lane carries an explicit `NOT EXISTS` reverse guard.

So the 5 are historical residue. The rows are old — the lowest connection id involved is **9**,
among the first rows the app ever wrote, and the pairs span 2023-06-18 to 2026-06-06.

**What I cannot establish** is exactly how they arose. One row of each pair carries a migrated
legacy `created_at` and the other an in-app timestamp, which is consistent with the migration and
early app use overlapping — but all the known writers guard, so I can't reconstruct the sequence
from the data alone, and I am not going to guess.

## If it is ever fixed

Two independent halves, and they are worth separating:

1. **The render** — add pair-level dedupe to the accepted query. Safe, touches no data, and fixes
   the double-listing for all 5 members immediately. This is the half worth doing.
2. **The data** — deleting one row per pair. **Higher risk and not obviously right**: the two rows
   carry different `created_at` values, so choosing which to keep is a judgement about which
   history is truthful, and deleting live rows needs its own tag table and rollback like
   everything else in this lane.

**Neither is in scope for the connections restore, and neither should be bundled into it.** They
have different risk profiles and different rollbacks. Offered as separate work; nobody owns it
today.
