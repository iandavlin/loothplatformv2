# LIVE: the TWO migrations the follow feature cannot ship without

**Rule (Ian, 2026-07-30): "if you are creating a symlink add to runbook and to repo."**
The same principle applies to a migration: the runbook is where the knowledge lives, the
repo is where the artifact lives. The artifact is **`cutover/topic-follow-migrate.sh`**.

**Ian's ordering, verbatim 2026-07-30:** *"Get it working on dev2 for my approval and then
set up a runbook that fixes it."* Working on dev2 came first; this is the runbook.
**Migration FIRST, then deploy, in one window** — Ian's call, taken over deploying now and
migrating after.

**Live is Ian's hands.** Nothing here has been run on live. This lane holds read-only
access (`ssh live-ro`) and used it only for the `SELECT`s quoted below.

---

## ⚠️ WHY THIS IS NOT OPTIONAL — and it is now the CRITICAL PATH, not a follow-up

**The follow feature does NOT wait for the consolidation modal. It ships on the next
`lg-deploy` regardless.** Four thread-follow merges are already on `main` and inside the
168-commit deploy window (`c57b70f..510ccd8`):

```
e84dae7  discussion follow toggles
2c0a4c0  long-press gate fix (bell/envelope persist)
46d0575  desktop topic-page toggles + orange ON state
7eb4685  desktop feed-card action row
```

So the 🔔/✉ controls become **newly visible to members on this same deploy**. Without the
two migrations below, on that same deploy:

- **The toggle** — `follow.php:93`'s read sits inside a `try/catch` at `:96` that logs and
  swallows, so every 🔔 and ✉ renders **permanently OFF for everyone**. The write at
  `:195`/`:198` falls to the outer `catch` at `:218` and returns **HTTP 500 on every click**.
- **The bell** — leg 4 (`lg-shared/notify-bridge.php`) raises notification type
  `forum.followed_topic`. Live's `notifications_type_check` **does not list it**, so the
  INSERT violates the CHECK, and `internal-notify.php:106-108` catches it and returns
  **HTTP 500 `db_error`**. A member would follow a thread successfully and then
  never be told anything.

A control that reads OFF, accepts the click, and never persists is exactly the "the UI
lies" class Ian ruled against (SPEC §8.1.3) and that §14 was spent eliminating.

**Ian's decision, 2026-07-30: migration FIRST, then deploy, in one window** — chosen over
deploying now and migrating after. The deploy hold he had been carrying was not delaying a
finished feature; it was protecting him from shipping a broken one.

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

## IS THE TABLE ALONE SUFFICIENT? NO — there are TWO migrations. Full delta below.

The `forums` schema on dev2 and live was enumerated object-by-object (`pg_tables`,
`information_schema.columns`, `pg_indexes`, `pg_constraint`, `pg_trigger`, `pg_proc`,
`pg_type`, `information_schema.sequences`, raw `pg_class.relacl`, `pg_default_acl`).
**This is the complete delta, not the one table anyone noticed.**

| object | dev2 | live | needed for the feature? |
|---|---|---|---|
| `forums.topic_follow` table | present | **MISSING** | **YES — step 1** |
| its PK `(user_id, topic_id)` | present | missing | yes, created with the table |
| `idx_topic_follow_topic` | present | missing | yes, step 1 creates it |
| `GRANT SELECT … TO looth_ro` | present | missing | yes, step 1 grants it |
| `looth-dev` = `arwd` on the new table | present | **arrives automatically** | yes — see below |
| `profile-app` = `r` on the new table | present | **arrives automatically** | yes — see below |
| `profile_app.notifications_type_check` accepts `forum.followed_topic` | yes | **NO** | **YES — step 2** |
| enum `subscription_target_kind` | present | present | n/a, unchanged |
| enum `attachment_parent_kind` | present | present | n/a, unchanged |
| sequences | `attachment_id_seq` only | same | **none needed** — `topic_follow` has no serial |
| foreign keys on `topic_follow` | none, deliberately | n/a | none — a follow may outlive a sync gap (`schema.pg.sql:251`) |
| `subscription_purge_for_target()` + its 2 triggers | present | **missing** | **no — hygiene only, see below** |

### The GRANT question, answered properly — because getting it wrong fails silently

**`looth-dev`'s write access is NOT granted by this migration and does not need to be.**
It arrives from the schema's DEFAULT PRIVILEGES, which are **byte-identical on both boxes**
(`pg_default_acl`, verified 2026-07-30):

```
bb-mirror | tables    | "looth-dev"=arwd/"bb-mirror"  "profile-app"=r/"bb-mirror"
bb-mirror | sequences | "looth-dev"=rwU/"bb-mirror"
postgres  | tables    | looth_ro=r/postgres
```

So a table created **by `bb-mirror`** automatically grants `looth-dev` INSERT/SELECT/
UPDATE/DELETE — which is precisely what `follow.php` needs to write. `looth_ro` does **not**
arrive that way (that default belongs to role `postgres`), which is why step 1 grants it
explicitly, exactly as `forum_subscription` has it.

> ### ⚠️ A TRAP THAT ALMOST PUT A PHANTOM IN THIS RUNBOOK
> Querying `information_schema.role_table_grants` **as `looth_ro`** returns only the grants
> visible to that role. Compared naively against dev2 (queried as `bb-mirror`) it reported
> **127 missing GRANTs on live** — every table, every role. **All of it was an artifact of
> the view filtering by current user.** The raw `pg_class.relacl` shows the two boxes'
> ACLs are *identical* apart from `topic_follow` itself and a dev2-only `membership` role.
> A tool that sanitises on read cannot audit the store — and `information_schema` is such
> a tool. Audit ACLs with `relacl`.

**`membership` is a dev2-ONLY role.** It does not exist on live at all (`pg_roles` checked
on both). Its absence from the post-migration grant list is **correct** and is not a
failure.

### What it touches

**Step 1 (`looth`, as `bb-mirror`)** — creates one empty table, one index, one grant.
**Step 2 (`profile_app`, as `profile-app`)** — DROP-then-ADD of one CHECK constraint,
widening the accepted `type` vocabulary by exactly one value.

**Does NOT touch:** any existing row of member data, `forum_subscription`, the WordPress
MySQL side, or any other constraint. Step 2 rewrites a constraint definition but changes no
data and only *widens* what is accepted, so nothing already stored can become invalid.

### ⚠️ WHAT CANNOT BE UNDONE

Step 1 is fully reversible **only while the table is still empty**. The moment a member
clicks a bell on live, those rows are real follow state with no other home — the ✉ bit
lives in MySQL, the 🔔 bit lives *only* here. After that, `DROP TABLE` silently discards
live member preferences with no second copy. **Rollback is a same-window action.**

Step 2 is reversible at any time (narrowing the constraint back), **but** narrowing it while
`forum.followed_topic` rows exist will fail the constraint check — delete those rows first,
which is what the DOWN block in `profile-app/sql/2026-07-28-followed-topic.sql` does.

**There are no ids, slugs or interpolated values anywhere in either step.** Both are fixed
text. That is deliberate — the failure this repo has already paid for was an empty variable
inside a chained `$(...)` in a paste-block, which re-baked an entire catalog on 2026-07-30
and held Ian's terminal. Nothing here is substituted, so nothing can come out empty.

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

### STEP 2's verification — a different database, a different role

```bash
sudo -u profile-app psql -d profile_app -tAc \
  "select pg_get_constraintdef(oid) from pg_constraint where conname='notifications_type_check';"
```

**Must contain `forum.followed_topic`.** If it does not, the toggle will work and the bell
will never arrive — the two failures are independent and look nothing alike from the UI.

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

**Then prove the BELL, which step 1 alone does not give you.** With 🔔 on for a thread you
do not otherwise participate in, have someone reply to it, and confirm a notification row
appears:

```bash
sudo -u profile-app psql -d profile_app -tAc \
  "select type, created_at from notifications
    where type='forum.followed_topic' order by created_at desc limit 5;"
```

Zero rows after a reply means step 2 did not take, or leg 4 is not reaching the endpoint.
Check `error_log` for `[internal-notify] forum.followed_topic … failed`.

---

## ⚠️ THE MIGRATION IS NOT THE ONLY UNSTAGED LIVE STEP — read this before the window

Asked to say whether anything else in the 168-commit window (`c57b70f..510ccd8`) needs a
live step nobody staged. **It does. Three of them are this lane's, and one of them makes the
migration pointless on its own.**

### 1. nginx must be reloaded, or `/bb-mirror-api/v0/follow` DOES NOT EXIST on live

`platform/nginx/strangler-bb-mirror.conf` gained the follow endpoint's `location` block in
`a424c70`. **Live's running nginx has ZERO occurrences of `bb-mirror-api/v0/follow`**
(grepped read-only on the box, 2026-07-30).

So: create both database objects perfectly and the toggle still **404s**, because nothing
routes the request. The conf file is symlinked out of the serving checkout, so `git pull`
deploys the *file* — but **the running workers keep the old config until reloaded.** A
disclosure fix once sat inert on dev2 for three hours this exact way.

```bash
sudo nginx -t && sudo systemctl reload nginx
# verify the WORKERS restarted, not the master:
ps -eo lstart,cmd | grep "[n]ginx: worker"
```

### 2. Three new mu-plugins need symlinks — two of them are this lane's

`platform/mu-plugins/` symlinks each plugin **individually** (35 as of 2026-07-30), and the
symlink SET is not in the repo, so a pull alone leaves a new plugin dark:

| plugin | lane | what breaks without it |
|---|---|---|
| `lg-discussion-unsub.php` | **thread-follow** | the per-discussion unsubscribe link in every reply email 404s — members get a mail they cannot opt out of |
| `lg-discussion-group-gate.php` | **thread-follow** | Ian's 2026-07-28 ruling is unenforced — LAYOUT groups start producing notifications and emails |
| `lg-author-socials.php` | profile-social-links | already staged; see `docs/runbooks/deploy-symlink-couplings.md` |

Both thread-follow plugins are self-contained: `lg-discussion-group-gate` registers no
routes at all, and `lg-discussion-unsub` renders on `template_redirect` rather than a
rewrite rule — **so neither needs a permalink flush or an nginx route.** The symlink is the
whole step.

```bash
REPO=/home/ubuntu/loothplatformv2-clean WP=<live docroot> \
  bash cutover/symlink-farm.sh                 # DRY RUN, all of them
REPO=/home/ubuntu/loothplatformv2-clean WP=<live docroot> \
  bash cutover/symlink-farm.sh --apply
```

### 3. The other two nginx confs in the window are not this lane's

`strangler-profile-app.conf` (profile-social-links, already staged) and
`strangler-archive-poc-buck.conf` (archive-poc). Both are covered by the same single
`nginx -t && reload` in step 1 — **one reload serves all three**, which is the point of
doing this in one window.

### The order Ian should run it in

```
1. migrations       bash cutover/topic-follow-migrate.sh          (dry run)
                    bash cutover/topic-follow-migrate.sh --apply  (both steps)
2. deploy           lg-deploy
3. symlinks         cutover/symlink-farm.sh --apply    (3 new mu-plugins)
4. nginx            sudo nginx -t && sudo systemctl reload nginx
5. verify           this runbook's verification section, then the UI smoke
```

**Migrations first is Ian's call and it is also the safe order here:** the objects exist
before any code can reach them, so there is no window in which the feature is live and the
store is not. The reverse order gives every member a 500 for however long step 1 takes.

**I have NOT audited the other lanes' 168 commits for steps beyond the files above.** What
is listed here came from diffing the window for new mu-plugins, new webroot files, changed
nginx confs and changed `.sql` files. That catches the coupling classes we know about; it
would not catch a lane that needs, say, a one-off backfill script run. **Each lane should
confirm its own.**

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
