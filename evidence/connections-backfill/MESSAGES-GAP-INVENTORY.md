# The messages gap — inventory of what the cutover left behind

**Lane:** connections-backfill · **Measured against LIVE 2026-07-28**
**INVENTORY ONLY — nothing here is fixed, nothing is proposed as built. Every measurement is a read.**

Section B of `BRIDGE-GAP-INVENTORY.md` recorded this as "16 members lost their history". That
undersold it in one direction and overstated it in another. Measured properly:

## The one-line answer

**It is not 17 people losing their own history. It is 24 threads that now render wrong for
everyone in them — including 13 members who were never part of the late cohort at all.**

## The numbers, all from live

| | |
| --- | --- |
| late members with BuddyBoss message history | **17** |
| of those, who have **any** of it in the app | **0** |
| messages they sent in BuddyBoss | **60** |
| of those present in the app | **0** |
| threads affected | **24** (all 24 exist in the app) |
| threads that render **completely empty** | **9** |
| threads that render **partial** | **11** |
| threads intact | 2 |
| messages missing from the 22 enumerated threads | **57** of 78 — **73% of the content** |
| **other, non-late members who can open these threads** | **13** |
| date range of the lost messages | 2025-02-06 → 2026-05-20 |

## Why it is two-sided — the asymmetry in the migration

`bin/migrate-social-from-bb.php` seeds messaging in three passes, and **only two of them touch the
bridge**:

| pass | keyed on | late cohort |
| --- | --- | --- |
| `message_threads` | `bp_thread_id`, **no uuid lookup at all** | ✅ landed |
| `messages` | `$uuidOf(sender_id)` → `msg_skip_bridge` | ❌ dropped |
| `message_recipients` | `$uuidOf(user_id)` → `rcpt_skip_bridge` | ❌ dropped |

That is the whole defect. **The thread shell was created for everybody; its contents were not.**
So the damage did not stay inside the late cohort the way the connections gap did — it leaked into
the inboxes of the people they were talking to. A cutover member opens a real conversation and
finds most or all of it gone, with no indication anything is missing.

This is a materially different shape from connections, where a missing row was simply invisible.
Here the migration produced a **visibly wrong artifact** rather than an absence.

## Controls run — the numbers survive them

**Deleted messages: not the explanation.** The migration deliberately skips `is_deleted=1`, so
that had to be ruled out before calling anything a defect. In these threads there are **zero**
deleted messages — 78 of 78 are live, and all 60 late-sent messages are `is_deleted=0`. Nothing
here was legitimately skipped.

**Post-cutover activity is not surviving history — and this is where the old figure of 16 came
from.** Five late members do have rows in `messages` / `message_recipients`, which reads at a
glance like "their history partly survived". It did not: **every one of those rows has
`bp_message_id IS NULL`**, meaning it was written by the app after cutover, not by the migration.
Migrated messages for late members: **0**. Late members holding a recipient row in any affected
thread: **0**.

So the corrected figure is **17, not 16** — all of them, with none of it. The "1" that appeared to
be fine was a member who has used the app since. Same class of error as the socials scare in the
main sweep: a raw count that was never controlled for what it actually meant.

## What a fix would involve — described, NOT built, NOT recommended yet

Recorded so the shape is known, not as a proposal. **Ian has ruled nothing on this.**

- The idempotency handles already exist and are the same shape as the connections work:
  `messages.bp_message_id` is UNIQUE and `message_recipients` is PK `(thread_id, user_uuid)`, so
  an insert-if-absent is natural and a second run is a no-op.
- All 24 `bp_thread_id → id` mappings are present in `message_threads`, so nothing needs a thread
  created; the join target already exists.
- **It would need its own tag table and rollback**, on the same principle as 13/14/15 — and it
  touches two tables, so a rollback has to unwind both.

**The notification question, which is the one Ian will ask first.** Restoring `message_recipients`
verbatim is **not** silent: BuddyBoss carries `unread_count > 0` on **2 rows for 2 members**, 10
unread between them. Everything else is zero. So there is a silent variant available exactly as
there was for connections — restore the rows with `unread_count = 0` and nobody is pinged, at the
cost of not marking genuinely-unread mail as unread. **That is Ian's call, not mine.** No
recipient row here is `is_deleted` or `is_hidden`, so nothing needs that judgement.

## What is NOT established

- **Whether the 13 other members ever noticed.** No support tickets were checked; nobody has been
  asked. The claim here is what the data renders, not what anyone experienced.
- **Whether restoring is even wanted.** These are private conversations from up to 18 months ago.
  Putting them back is a product decision with a privacy dimension, not a data-repair chore, and
  it is not mine to make.
- **Message media.** `2026-06-30-message-media.sql` exists and this sweep did not look at whether
  attachments on the missing messages have their own gap. Flagged, unmeasured.

## Re-running this

Every figure above comes from live via `ssh live-ro`, MySQL `looth_import` for BuddyBoss and
Postgres `profile_app` for the app. The late cohort is `wp_user_bridge.synced_at >= '2026-06-03'`,
the same boundary the main sweep uses.
