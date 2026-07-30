# LIVE: create `forums.topic_follow` — the migration the follow feature cannot ship without

**Rule (Ian, 2026-07-30): "if you are creating a symlink add to runbook and to repo."**
The same principle applies to a migration: the runbook is where the knowledge lives, the
repo is where the artifact lives. The artifact is **`cutover/topic-follow-migrate.sh`**.

**Ian's ordering, verbatim 2026-07-30:** *"Get it working on dev2 for my approval and then
set up a runbook that fixes it."* This runbook is step 2. **It does not run until he has
seen the feature working on dev2 and approved it.**

**Live is Ian's hands.** Nothing here has been run on live. This lane holds read-only
access (`ssh live-ro`) and used it only for the `SELECT`s quoted below.

---

## ⚠️ WHY THIS IS NOT OPTIONAL — the deploy hold was protecting a real outage

This is bigger than a missing table. **Without `forums.topic_follow` on live, the follow
feature is not one merge from shipping — it is one merge from a member-facing outage:**

- **Read path** — `bb-mirror/api/v0/follow.php:93` sits inside a `try/catch` at `:96` that
  logs and swallows. Every 🔔 and ✉ would render **permanently OFF for everyone**, silently.
- **Write path** — `:195`/`:198` is not individually guarded; it falls to the outer `catch`
  at `:218` and returns **HTTP 500 on every single click**.

A control that reads OFF, accepts the click, and never persists is exactly the "the UI
lies" class Ian ruled against (SPEC §8.1.3) and that §14 was spent eliminating.

So the deploy hold Ian has been carrying was **not** delaying a finished feature. It was
protecting him from shipping a broken one. That is the reason this migration is a
precondition of the deploy and not a follow-up to it.

---

## ⚠️ AND THE TABLE IS NOT THE ONE THE ORIGINAL REPORT NAMED

The missing table was first reported as **`forums.topic_subscription`**. **That table has
never existed on any box, and nothing reads or writes it.** The only occurrence of that
string in the monorepo is the *name of a trigger* — `topic_subscription_purge`
(`bb-mirror/schema.pg.sql:487`) — which is what a `grep` for the table finds and misreads.

The real store is **`forums.topic_follow`** (`schema.pg.sql:254`; writer/reader
`follow.php:93,195,198`). **Creating `topic_subscription` would add a table no code path
touches and leave this defect exactly where it is.** Confirm a table name against the code
that *writes* it before concluding a migration was lost.

---

## CURRENT STATE — pre-resolved read-only from live, 2026-07-30

Queried with `ssh live-ro` / `psql -U looth_ro -d looth`. **Literal values, so nothing in
the script below has to look anything up at runtime.**

| fact | live | dev2 |
|---|---|---|
| `forums.topic_follow` | **MISSING** | present |
| `forums.forum_subscription` | present | present |
| owner of schema `forums` | `bb-mirror` | `bb-mirror` |
| owner of `forum_subscription` | `bb-mirror` | `bb-mirror` |
| `looth_ro` role exists | yes | yes |
| grants on `forum_subscription` (non-owner) | `looth_ro:SELECT` | — |
| default ACL entries on schema `forums` | 3 | 3 |
| PostgreSQL | 16.14 | — |

`bb-mirror` owning the schema on **both** boxes is why the script runs as that role. Running
as `postgres` would create a table the application's own role may not be able to write.

---

## WHAT IT TOUCHES, AND WHAT IT CANNOT UNDO

**Touches — all three are additive:**
1. creates `forums.topic_follow` (empty: `user_id`, `topic_id`, `created_at`, PK on
   `(user_id, topic_id)`);
2. creates index `idx_topic_follow_topic` on `(topic_id)`;
3. grants `SELECT` on it to `looth_ro`.

**Does NOT touch:** any existing table, `forum_subscription`, the WordPress MySQL side, any
row of member data. It drops nothing and alters nothing.

**⚠️ WHAT IT CANNOT UNDO.** The migration itself is fully reversible **only while the table
is still empty**. The moment a member clicks a bell on live, the rows are real follow state
with no other home — the ✉ bit lives in MySQL, the 🔔 bit lives *only* here. After that,
`DROP TABLE` silently discards live member preferences and there is no second copy to
restore from. **Rollback is a same-window action, not a next-day one.**

**There are no ids, slugs or interpolated values anywhere in this migration.** The DDL is
fixed text. That is deliberate — the failure this repo has already paid for was an empty
variable inside a chained `$(...)` in a paste-block, which re-baked an entire catalog on
2026-07-30 and held Ian's terminal. Nothing here is substituted, so nothing can come out
empty.

---

## RUN IT — dry run first, always

The script is `set -euo pipefail`, **dry-run by default**, and refuses to do anything if the
table is already present.

```bash
cd /home/ubuntu/loothplatformv2-clean

# 1. DRY RUN — prints the current state and the exact SQL, writes nothing.
bash cutover/topic-follow-migrate.sh

# 2. APPLY.
bash cutover/topic-follow-migrate.sh --apply
```

**How long it runs: well under a second.** Measured on dev2 against a scratch schema:
**0.13s wall** for the whole transaction. It creates an empty table and an index on an
empty table — there is no data to scan and no lock worth planning around. If it hangs,
something else holds a lock on the `forums` schema; that is a reason to stop, not to wait.

**Idempotent, verified not assumed:** the DDL was run twice against a scratch schema on
dev2. The second run returned `NOTICE: relation "topic_follow" already exists, skipping`
and exited 0. The script additionally short-circuits with `ALREADY PRESENT — nothing to do`
before reaching the SQL at all.

---

## ROLLBACK

Only while the table is still empty — see the warning above.

```bash
sudo -u bb-mirror psql -d looth -v ON_ERROR_STOP=1 \
  -c "DROP TABLE IF EXISTS forums.topic_follow;"
```

Verified on dev2 against the scratch schema: drops clean, `to_regclass` returns `MISSING`.

---

## VERIFICATION — query the real table on the real box

**A tool that sanitises on read cannot audit the store.** The script prints all of this
after `--apply`; run it by hand if you want it independently.

```bash
sudo -u bb-mirror psql -d looth -tAc "
select to_regclass('forums.topic_follow');
select count(*) from forums.topic_follow;
select string_agg(column_name||' '||data_type, ', ' order by ordinal_position)
  from information_schema.columns
 where table_schema='forums' and table_name='topic_follow';
select string_agg(indexname, ', ' order by indexname)
  from pg_indexes where schemaname='forums' and tablename='topic_follow';
select string_agg(grantee||':'||privilege_type, ',')
  from information_schema.role_table_grants
 where table_schema='forums' and table_name='topic_follow' and grantee<>'bb-mirror';"
```

**Expected, matching dev2 exactly:**

```
forums.topic_follow
0
user_id bigint, topic_id bigint, created_at timestamp with time zone
idx_topic_follow_topic, topic_follow_pkey
looth-dev:INSERT,looth-dev:SELECT,looth-dev:UPDATE,looth-dev:DELETE,profile-app:SELECT,looth_ro:SELECT,membership:SELECT
```

> **⚠️ The grants line is the one that can quietly be wrong.** `looth_ro:SELECT` comes from
> the explicit `GRANT`; **`looth-dev`'s INSERT/UPDATE/DELETE come from the schema's DEFAULT
> PRIVILEGES**, which is why the script never grants them by hand. If `looth-dev` is missing
> from that list, the default privileges did not fire and **the API will be able to read but
> not write** — every toggle would appear to work and then revert. Report it; do not paper
> over it with an ad-hoc grant without finding out why the default did not apply.

### Then smoke it through the real UI, not just the table

Creating the table proves the store exists, not that the feature works. On live, as a
logged-in member, on a real discussion:

1. Click the follow control and turn 🔔 **on**.
2. Confirm a row appears — `select * from forums.topic_follow where user_id=<your id>;`
3. **Reload the page** and confirm the control still reads as following.
4. Turn it **off** and confirm the row is gone.

Step 3 is the one that matters: an optimistic UI that flips and silently reverts looks
identical to a working one until the page is reloaded, and only the store can tell the two
apart.

---

## RELATED, AND DELIBERATELY NOT IN THIS MIGRATION

Live is also missing `forums.subscription_purge_for_target()` and the
`forum_subscription_purge` / `topic_subscription_purge` triggers.

**They are not bundled here on purpose.** On dev2 the 🔔 purge lives *inside a shared
function that also purges `forum_subscription`* — a table this lane does not own — so
installing it on live would change behaviour on someone else's table inside a migration
labelled as thread-follow's. That is how an unrelated regression ships under a trusted name.

**Consequence of deferring:** deleting a topic on live leaves its follower rows behind.
They are inert — keyed to a `topic_id` that no longer resolves, costing storage and never
wrong behaviour — and fully fixable later by installing the full layer
(`schema.pg.sql:455-490`) **after a word with whoever owns the subscription mirror.**

---

*Written 2026-07-30 by the thread-follow lane. Every "live" figure above came from a
read-only query against live; every "verified" claim about the DDL came from running it
against a scratch schema on dev2, which was dropped afterwards. Nothing was run on live.*
