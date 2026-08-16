# LIVE PASTE — backlog 3.9, the five unmirrorable replies

**For Ian. Live is read-only to the lane; every command below is yours to run.**
Written 2026-08-16. Every ID is **pre-resolved from live** via `live-ro` — there are
no placeholders in the commands that act, and every one carries a numeric guard so
it cannot touch a row other than the one named.

## What is wrong, in one line

Five published replies cannot be mirrored to the hub, so **members cannot see them**.
Reconcile cannot repair them and never will: it only walks rows modified since its
bookmark, and all five are 60–73 days older than that.

They split into two very different groups, and **only the first is safe to act on
blind**.

---

## Group A — 2 junk test posts (safe, evidenced)

| id | author | body | parentage |
|---|---|---|---|
| 71432 | `deleted-member` (1890) | `my forum reply` | **none** — `post_parent=0`, no `_bbp_topic_id` |
| 71433 | `deleted-member` (1890) | `my forum reply` | **none** — `post_parent=0`, no `_bbp_topic_id` |

Both posted 2026-06-04 by an account that no longer exists, with placeholder text and
no thread. They are the two rows that have been keeping `mirror-sync-watch` alerting.

**Run this to trash them.** The `AND` clauses are the guard: if either row is not
still exactly what we measured, it updates 0 rows rather than the wrong one.

```
ssh live 'cd /var/www/loothgroup.com && wp db query "UPDATE wp_posts SET post_status='"'"'trash'"'"' WHERE ID IN (71432,71433) AND post_type='"'"'reply'"'"' AND post_status='"'"'publish'"'"' AND post_parent=0 AND post_author=1890"'
```

Expected: **2 rows affected**. If it says 0, stop and tell the lane — the data moved
and the paste is stale.

---

## Group B — 3 real member replies: make them FIRST-CLASS ORPHANS

**Ian's ruling:** *"If the topic is deleted, the replies should be orphaned."*
So these are **not** deleted and **not** re-homed. They stay, preserved, and they
stop claiming a parent that was never theirs.

| id | author | says | currently claims |
|---|---|---|---|
| 71720 | patreon_63883555 | "I spray the wood with veneer softener and put a s…" | topic **71685** — an *attachment* |
| 71722 | patreon_120178820 | "You could try wetting it and then compressing in…" | topic **71685** — an *attachment* |
| 71728 | colingobrien | "The bubbles do appear to follow the grain." | topic **71671** — *does not exist* |

### What happened, for the record

Their topic was **hard-deleted** in the 14–16 June window — not trashed, so there is
no row to restore and no trash to empty. The dupe-merge journal is provably clean,
so it was not that. The actor is unrecoverable: there is no audit trail for the
period and the logs have rotated. That is why nothing can re-home these replies —
**the destination genuinely no longer exists**, which is exactly the case Ian's
ruling covers.

### Why marking them matters even though nothing shows today

All three are **absent from the mirror** (verified: 0 rows) — the sync has been
correctly refusing them. The problem is what they still *assert*: 71720 and 71722
name **71685**, which is a real attachment belonging to something else. Left as is,
any future repair — a healer, a backfill, a well-meant re-run — would file two
members' answers under an object that is not their conversation. Marking them
topic-less removes that trap permanently.

Afterwards the pipe treats them as a **legitimate, loud, graceful** state: the
receiver answers 202 with `missing parentage meta`, and reconcile reports them as
unrepairable rather than silently dropping them. That is the orphan state working
as designed, not a fault.

### Step 1 — cut the false parent (2 replies under the attachment)

```
ssh live 'cd /var/www/loothgroup.com && wp db query "UPDATE wp_posts SET post_parent=0, post_modified_gmt=UTC_TIMESTAMP(), post_modified=NOW() WHERE ID IN (71720,71722) AND post_type='"'"'reply'"'"' AND post_status='"'"'publish'"'"' AND post_parent=71685"'
```

Expected: **2 rows affected.**

### Step 2 — cut the false parent (1 reply under the absent topic)

```
ssh live 'cd /var/www/loothgroup.com && wp db query "UPDATE wp_posts SET post_parent=0, post_modified_gmt=UTC_TIMESTAMP(), post_modified=NOW() WHERE ID=71728 AND post_type='"'"'reply'"'"' AND post_status='"'"'publish'"'"' AND post_parent=71671"'
```

Expected: **1 row affected.**

### Step 3 — drop the false topic claim

The guard names the exact bad values, so this cannot remove a healthy `_bbp_topic_id`
from anything else:

```
ssh live 'cd /var/www/loothgroup.com && wp db query "DELETE FROM wp_postmeta WHERE meta_key='"'"'_bbp_topic_id'"'"' AND ((post_id IN (71720,71722) AND meta_value=71685) OR (post_id=71728 AND meta_value=71671))"'
```

Expected: **3 rows affected.**

**`_bbp_forum_id` is deliberately left in place** (3837 for the pair, 3829 for
71728). It is the only surviving record of which conversation these answers came
from, it costs nothing, and the mirror already refuses a reply without a topic — so
keeping it changes no behaviour and preserves provenance if the thread is ever
reconstructed.

Bumping `post_modified_gmt` is also deliberate: it brings each row into reconcile's
window once, so the pipe announces the orphan state immediately instead of leaving
it to the six-hourly deep sweep.

## Verify (run after either group)

```
ssh live 'cd /var/www/loothgroup.com && wp db query "SELECT r.ID, r.post_status, r.post_parent, MAX(CASE WHEN m.meta_key='"'"'_bbp_topic_id'"'"' THEN m.meta_value END) AS topic_meta FROM wp_posts r LEFT JOIN wp_postmeta m ON m.post_id=r.ID WHERE r.ID IN (71432,71433,71720,71722,71728) GROUP BY r.ID, r.post_status, r.post_parent"'
```

Healthy afterwards means: 71432 and 71433 show `trash`; and 71720, 71722, 71728 show
`post_parent = 0` with `topic_meta` **NULL** — preserved, published, and honestly
parentless. They are orphans by ruling, not casualties.

## The success signal

**`mirror-sync-watch` goes quiet.** It runs every 15 minutes and has been alerting for
hours on these rows. Within one cycle of the fix its board post should stop naming
them. That is the check that matters — not the SQL output, but the watcher agreeing.

If it still alerts after ~30 minutes, tell the lane rather than re-running anything:
that would mean a sixth row we have not measured.
