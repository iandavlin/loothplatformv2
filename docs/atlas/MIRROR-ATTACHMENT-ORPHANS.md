# The mirror's attachment orphan leak — mechanism, scale, and the fix

*`mirror-delete-orphans` lane, 2026-07-28. Owned after `reply-images-count` kept
tripping over it: that lane created two orphans (72225, 72240) simply by testing,
and its "max 11 images per reply" phantom — which made a cap of 6 look lossy to
three separate people — was orphan pollution and nothing else.*

---

## 1. The mechanism, in one line

**`forums.attachment` is polymorphic, so it cannot have a foreign key, so it
never got the `ON DELETE CASCADE` that every other child of topic/forum has.**

```
forums.attachment (parent_kind ENUM('topic','reply'), parent_id BIGINT)
   -- no FK is *possible*: parent_id points at two different tables
```

Everything around it cascades:

| constraint | behaviour |
|---|---|
| `reply.topic_id → topic(id)` | **ON DELETE CASCADE** |
| `reply.forum_id → forum(id)` | **ON DELETE CASCADE** |
| `topic.forum_id → forum(id)` | **ON DELETE CASCADE** |
| `forum_read_state.topic_id → topic(id)` | ON DELETE CASCADE |
| **`attachment.parent_id`** | **no constraint at all** |

Identical on dev2 and LIVE (both checked 2026-07-28).

And the sync receiver deletes exactly one row:

```php
// bb-mirror/api/v0/_sync.php
case ['forum','delete']: case ['topic','delete']: case ['reply','delete']:
    $db->prepare("DELETE FROM $kind WHERE id = ?")->execute([$id]);
```

**Correction (2026-07-28, later pass).** An earlier draft of this doc — and the
message of `5d67099` — claimed there is *no* `DELETE FROM forums.attachment`
anywhere in the repository. That is false, and worth correcting because it is
the reason the table was wrongly believed to be append-only:

```php
// bb-mirror/lib/materializers.php:276, inside bb_mirror_sync_attachments()
$db->prepare("DELETE FROM attachment WHERE parent_kind = ? AND parent_id = ?")
```

That is the idempotent *replace* on re-sync — delete this parent's rows, insert
the current set. It is harmless and correct. What never existed is a delete
keyed on the parent **going away**.

**And the endpoint is not the only place a parent row is deleted.** Reconcile
deletes them too, nowhere near `_sync.php`:

| site | what deletes the parent |
|---|---|
| `api/v0/_sync.php:98` | the sync endpoint's `delete` action (mu-plugin hooks) |
| `lib/materializers.php:348` | `bb_mirror_upsert_topic` — WP post gone/retyped |
| `lib/materializers.php:418` | `bb_mirror_upsert_reply` — same, for replies |
| Postgres itself | `ON DELETE CASCADE` from a deleted topic or forum |
| hand-run SQL | an admin cleaning up by hand |

A fix written at the endpoint would have covered exactly one of these five.

## 2. It is worse than "deleting a reply leaks its images"

That was the framing this started with, and it is only the smallest case.
Measured in a scratch DB against the real schema — 3 replies (6, 2 and 1 images)
under 2 topics (3 images on one) under 1 forum, 12 attachment rows total:

| action | replies removed | **orphan rows left** |
|---|---|---|
| delete one reply | 1 | **6** |
| delete a topic | 2 (by cascade) | **11** |
| delete a forum | 3 (by cascade) | **12 — all of them** |

**The cascade cases are the dangerous ones, and a PHP fix cannot reach them.**
When a topic is deleted, Postgres removes the reply rows *internally*. The
application issues one `DELETE FROM topic WHERE id = ?` and never learns which
replies died — so any cleanup written next to that statement silently misses the
entire subtree. A forum delete orphans every image beneath it in one statement.

## 3. Scale on LIVE (2026-07-28, `ssh live-ro`, direct)

Measured twice on 2026-07-28, hours apart, and **it moved between the two**:

| | 03:31 UTC | ~13:20 UTC | |
|---|---|---|---|
| orphaned, `parent_kind='topic'` | 16 rows / 12 parents | **16 / 12** | — |
| orphaned, `parent_kind='reply'` | 6 rows / 1 parent | **8 / 2** | **+2 rows, +1 parent** |
| **total leaked** | **22 / 13** | **24 / 14** | |
| live topic attachments | 1,017 | 1,017 | |
| live reply attachments | 893 | 895 | |

**The leak is accruing on live now, not historically.** Orphans carry a
`sync_at`, and the spread confirms it is ongoing rather than a one-off: 2 rows
from 2026-05, 14 from 2026-06, 8 from 2026-07.

The two orphaned **reply** parents on live are worth naming, because between
them they are the whole argument:

| live reply | leaked rows | rows synced at | |
|---|---|---|---|
| **72256** | **6** | 2026-07-17 19:22 UTC | **a six-image reply, deleted — every row still there** |
| 72389 | 2 | 2026-07-28 03:30 UTC | posted this morning, deleted this morning |

`72256` is the **largest reply-image group on the whole live mirror**, and its
reply no longer exists. That is the six-wide case this was predicted to cause,
already having happened, ten days before anyone looked. It is also why live's
user-visible `max_on_one_reply` reads 5 while a raw `GROUP BY parent_id` reads
6 — the 6 belongs to a reply nobody can see.

`72389` explains the +2 between the two measurements: its rows were written at
03:30 UTC with a living parent (so they were *not* orphans when the 03:31
census ran), and the reply was deleted later that morning. A member deleted a
two-image post and the mirror kept the images.

> **Beware the ID collision.** dev2 and live have independent ID sequences that
> overlap. `bin/test-attachment-purge.php` happened to be assigned reply 72256
> on **dev2** during this work; live's 72256 is an unrelated post on a different
> box and a different database. Always say which box a number came from.

Note **12 of the 14 lost parents are topics**, which is the path nobody was
looking at. Live already carries a 6-image reply, so the new shape is live data,
not a hypothetical: 280 replies with 1 image, 98 with 2, 132 with 3, 3 with 4,
1 with 5, 1 with 6.

Live carries only the two `search_doc` triggers — **the fix is not installed
there.**

**On the rate, stated precisely rather than dramatically:** storage was never
capped, so a deleted reply always stranded *all* its rows. What changed on
2026-07-28 is that multi-image replies became a feature members can see (`6ef25e3`
shipped up to 6 per reply to live). The per-delete behaviour is unchanged; the
*population* of multi-image replies is what will grow, and with it the leak.

## 4. The fix — the CASCADE a polymorphic column can't have

`bb-mirror/schema.pg.sql`, alongside the existing search-doc triggers:

```sql
CREATE OR REPLACE FUNCTION forums.attachment_purge_for_parent() RETURNS trigger AS $$
BEGIN
  DELETE FROM forums.attachment
   WHERE parent_kind = TG_ARGV[0]::forums.attachment_parent_kind
     AND parent_id   = OLD.id;
  RETURN OLD;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER topic_attachment_purge AFTER DELETE ON forums.topic
  FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('topic');
CREATE TRIGGER reply_attachment_purge AFTER DELETE ON forums.reply
  FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('reply');
```

### Every name is schema-qualified, and that is load-bearing

The first version of this trigger (`5d67099`) left the function body
unqualified — `DELETE FROM attachment`, `::attachment_parent_kind`. **plpgsql
resolves unqualified names when the function RUNS, against the CALLER's
`search_path`**, not the one in force where it was created. Every application
path is fine, because `config.php:92` issues
`SET search_path = forums, public` on every connection. But
`sudo -u postgres psql -d looth` runs as `"$user", public`, and there:

```
=# DELETE FROM forums.reply WHERE id = 100;
ERROR:  relation "attachment" does not exist
CONTEXT:  PL/pgSQL function forums.attachment_purge_for_parent() line 6
```

The trigger raised, so **the DELETE aborted**. The first version claimed to
cover "hand-run SQL for free"; it in fact converted a silent leak into a hard
failure on precisely that path — the one an admin uses to clean up by hand, and
the one least likely to be tested before someone needs it at speed. Qualified,
the same statement succeeds from any `search_path`. The cast needs qualifying
just as much as the table: a type name is no less a name.

**Known limit, stated rather than discovered later:** a `FOR EACH ROW` trigger
does not fire on `TRUNCATE`. Nothing in the repo truncates `topic` or `reply`
(checked 2026-07-28), and `bin/init-db.php` uses `DROP SCHEMA … CASCADE`, which
takes `attachment` with it. If a TRUNCATE path is ever added, list `attachment`
in the same statement.

**Why the database and not the application:**

- An `AFTER DELETE ... FOR EACH ROW` trigger fires for **cascaded** rows too, so
  the topic and forum cases clean themselves — the two cases PHP cannot see.
- It covers every delete path at once: the endpoint, wp-admin, bulk deletes,
  reconcile, and hand-run SQL. No caller has to remember anything.
- It is **not a sweeper**. A cleanup job is a second thing to remember to run; a
  correct delete path is not. (keeper's instruction, and the right one.)
- Matching on **both** kind and id is load-bearing: topic 100 and reply 100 are
  different parents. A kind-blind delete would take the wrong one's images —
  asserted explicitly in the test below.

### Proven, both directions

Scratch DB (`orphan_proof`) built from the real `schema.pg.sql`, seeded with a
reply carrying the **new six-image shape**, and driven from a session whose
`search_path` is `"$user", public` — i.e. the case that used to error:

| case | before fix | after fix |
|---|---|---|
| delete one reply (6 images) | 6 orphans | **0** |
| delete a topic (cascade to 2 replies) | 11 orphans | **0** |
| delete a forum (2-level cascade) | 12 orphans | **0** |
| siblings untouched | — | r101 kept 2, r102 kept 1, r200 kept 1 |
| `topic:100` row when **reply** 100 deleted | — | **survives** |
| rollback (triggers dropped) | — | **leak returns** — rollback is real |

The `topic:100` decoy is worth spelling out, because a first pass got it wrong:
it must be a row whose parent topic **actually exists**, or it is itself an
orphan and pollutes the very count the test is asserting on. With a real
`topic` 100 present alongside `reply` 100, baseline orphans are 0, and 0 is
then an unambiguous pass.

The script was then exercised in all three modes against that database:
dry-run changed nothing; `apply` swept 8 orphans with the user-visible counts
identical before and after (`PASS 2|1|1|2`); `rollback` dropped the triggers and
the next delete leaked 3 rows again.

### Proven again through the real materializers — `bin/test-attachment-purge.php`

The table above is a database-level proof. This is the code-level one, and it is
checked in as a runnable test because the defect class has now been found twice
(the `reply-images-count` lane first, then this lane) — which is the point at
which `docs/CRAFT-STANDARD.md` says to encode it rather than fix it again.

```
sudo -u looth-dev php bb-mirror/bin/test-attachment-purge.php
```

It drives the **real** `lib/materializers.php` over **real** WordPress rows — a
topic with a cover and a reply carrying **six** inline images — then issues the
**verbatim** delete statement from `api/v0/_sync.php:98`, for both the direct
reply delete and the topic-cascade case. 11 checks, all passing:

```
2. delete the reply — verbatim statement from api/v0/_sync.php
  [PASS] all 6 reply images purged        got 0, want 0
  [PASS] topic's own cover untouched      got 1, want 1
3. delete the topic — the CASCADE case a PHP-side fix cannot reach
  [PASS] cascaded reply images purged     got 0, want 0
4. negative control — drop the triggers, the leak must return
  [PASS] leak returns without triggers    got 6, want 6
```

**Step 4 is the one that matters most.** Without it the other ten assertions
could all pass vacuously. With the triggers dropped, the same six-image reply
delete strands all 6 rows — so the test can fail, and therefore its passing
means something.

Two things it deliberately does *not* do, both because they would write to the
serving mirror: it targets a scratch database (`orphan_proof`) rather than
`looth`, and it inserts its WP fixture with `$wpdb` directly rather than
`wp_insert_post`, so the `bb-mirror-sync` mu-plugin hooks never fire. A dispatch
would POST to the real endpoint and land rows in `looth`. Verified after every
run: `looth` attachment rows unchanged at 1,859, and zero `ZZ TEST` rows left in
WordPress.

**What this does not cover, stated plainly:** the HTTP hop and the mu-plugin
hook wiring. Proving those end-to-end means letting a real delete reach dev2's
`looth`, which needs the triggers installed there first. The residual risk is
small — `_sync.php`'s delete is `DELETE FROM $kind WHERE id = ?`, and the
trigger has now been shown to fire on that statement issued from PDO, from
`psql`, and by FK cascade — but it is not zero, and it is not proven.

### dev2 is leaking too, on the same path

Measured 2026-07-28: dev2's `looth` carries **16 orphan rows across 12 lost
parents, every one of them under `topic`**, none under `reply`. A lane reported
"forums.attachment orphans = 0" on dev2 earlier the same day; that was true of
the reply orphans it had created and cleaned up, but not of the table. The topic
path is the one nobody watches, on both boxes. dev2 and live show the same 16/12
under topic, consistent with dev2's mirror having been built from the same
source rather than having leaked independently.

## 5. Applying it — `bb-mirror/bin/fix-attachment-orphans.sh`

```
./fix-attachment-orphans.sh dry-run    # default; changes nothing
./fix-attachment-orphans.sh apply
./fix-attachment-orphans.sh rollback
```

- **Dry run** prints the orphan census, the rows that must survive, the
  user-visible reply-image counts, and **the exact SQL apply would run**.
- **Apply** backs up the exact rows to `/tmp/<tag>.orphan-rows.tsv`, installs the
  triggers *first* (so nothing leaks during the sweep), then sweeps, then
  **asserts the user-visible counts are unchanged** and fails loudly if not.
- **Rollback** drops the triggers. It does not resurrect swept rows — they
  pointed at parents that no longer exist and nothing could render them.

**Apply installs the two triggers and nothing else.** An earlier version piped
the whole of `schema.pg.sql` at the database, which is a large and unreviewable
thing to do to production in order to add two triggers. The forward migration is
now the block between the `-- >>> BEGIN/END attachment-purge <<<` markers in
`schema.pg.sql`, *extracted* rather than copied, so the file that builds a fresh
database and the statements that touch live cannot drift apart. The script
aborts if extraction fails and asserts both triggers exist **before** it sweeps
— installing nothing while still sweeping is the worst available outcome,
because it looks like it worked.

**Rollback, stated before apply, as one line:**

```sql
DROP TRIGGER IF EXISTS topic_attachment_purge ON forums.topic;
DROP TRIGGER IF EXISTS reply_attachment_purge ON forums.reply;
DROP FUNCTION IF EXISTS forums.attachment_purge_for_parent();
```

> **NOBODY IS NOTIFIED, AND NO USER-VISIBLE NUMBER MOVES.** Every read path starts
> `FROM forums.reply` / `forums.topic` and joins outward, so a row whose parent is
> gone cannot render, cannot be linked, and is in nobody's feed or email. The
> dry run prints the before/after counts precisely so this does not have to be
> taken on trust. **No image files are touched** — `attachment` stores URLs, not
> blobs; the files live under `wp-content/uploads` (R2).

Verified on dev2 2026-07-27 that a materializer re-sync does **not** clear
orphans: `bb-mirror-reconcile.service` was run deliberately and the census was
byte-identical afterwards. The triggers are the only thing that fixes this.

## 6. The live run — Ian's, and the rollback comes first

### Run it as `bb-mirror`. Not `postgres`, not `ubuntu`.

Checked read-only on live 2026-07-28: role **`bb-mirror`** owns the `forums`
schema, all three tables (`topic`, `reply`, `attachment`), the
`attachment_parent_kind` enum, **and both existing trigger functions**. The system
user exists (uid 993), so `sudo -u bb-mirror psql -d looth` peer-auths.

| role | verdict |
|---|---|
| `ubuntu` | **not a Postgres role at all** — this is the "role ubuntu does not exist" error |
| `profile-app` | does not own these tables |
| `postgres` | superuser, so it *works* — and plants a landmine, see below |
| **`bb-mirror`** | **correct: matches every existing object's owner** |

The `postgres` trap is worth spelling out because it fails much later and looks
unrelated. If `postgres` creates the function, the function is *owned by*
`postgres` — and a subsequent re-apply of `schema.pg.sql` as `bb-mirror` (which is
how `bin/init-db.php` and any normal schema refresh run) then dies:

```
ERROR:  must be owner of function attachment_purge_for_parent
```

Reproduced deliberately rather than assumed. Using `bb-mirror` keeps ownership
uniform and `schema.pg.sql` re-appliable.

### Rollback, stated before anything is applied

Three statements, no data dependency, safe to run at any time and safe to run
twice (a second run only prints `skipping`):

```sql
DROP TRIGGER IF EXISTS topic_attachment_purge ON forums.topic;
DROP TRIGGER IF EXISTS reply_attachment_purge ON forums.reply;
DROP FUNCTION IF EXISTS forums.attachment_purge_for_parent();
```

or `bb-mirror/bin/fix-attachment-orphans.sh rollback`. This has been exercised,
not just written down: after running it, the next delete leaks again. It restores
the *old leaky behaviour* — it does not resurrect swept rows, and does not need
to, because those rows point at parents that no longer exist.

**Live dry run, run read-only through `ssh live-ro` on 2026-07-28:**

| | |
|---|---|
| orphan rows to sweep | **24** (16 topic / 12 parents, 8 reply / 2 parents) |
| rows that must survive | **1,888** |
| replies with images / multi-image / extra images / max | **513 / 233 / 374 / 5** |
| purge triggers currently on live | **0** — not installed |

The third row is the assertion. `apply` re-measures it afterwards and **fails
loudly if any of those four numbers move.** They must not: the sweep only removes
rows whose parent is already gone, and no such row can appear in that query,
which starts `FROM forums.reply`.

**The command:**

```bash
cd /srv/bb-mirror
./bin/fix-attachment-orphans.sh dry-run     # read-only; prints the exact SQL
./bin/fix-attachment-orphans.sh apply
```

`apply` backs the exact rows up to `/tmp/<tag>.orphan-rows.tsv` first, installs
the two triggers **before** sweeping so nothing leaks mid-run, aborts if it does
not end up with exactly 2 triggers, then sweeps and re-asserts the counts above.

> **NOBODY IS NOTIFIED AND NO USER-VISIBLE NUMBER MOVES.** Stated here so it does
> not have to be asked. Every read path starts `FROM forums.reply` /
> `forums.topic` and joins outward, so a row whose parent is gone cannot render,
> cannot be linked, and is in nobody's feed, digest or email. Nothing about this
> is member-facing: it is invisible rows being removed from a table. **No image
> files are touched** — `attachment` stores URLs, not blobs, and the files live
> in R2 under `wp-content/uploads`.

## 7. Does the trigger fix EVERY delete path? No. Here is the matrix.

Keeper asked the right question: *a path that deletes a parent without touching
the mirror is not fixed by a constraint.* Answered with evidence, 2026-07-28.

| # | delete path | reaches the mirror? | trigger fixes it? |
|---|---|---|---|
| 1 | bbPress delete (UI / moderation) → `bbp_deleted_topic\|reply` → endpoint | yes | **yes** |
| 2 | reconcile's upsert-delete, WP post gone or retyped (`materializers.php:348/418`) | yes | **yes** |
| 3 | Postgres FK cascade (topic→replies, forum→topics→replies) | yes, internally | **yes** |
| 4 | hand-run SQL against the mirror | yes | **yes** |
| 5 | **wp-admin bulk delete, `wp_delete_post()`, WP-CLI, direct SQL** | **NO — no hook exists** | **no** |
| 6 | **dispatch lost in flight** (fire-and-forget) | **NO** | **no** |
| 7 | `TRUNCATE` on the mirror tables | n/a | no (nothing truncates them) |

**1–4 are closed by this change. 5 and 6 are a different defect**, and they do not
produce orphans at all — they produce **ghosts**.

### Why 5 exists: the mu-plugin only hooks bbPress, not WordPress

`platform/mu-plugins/bb-mirror-sync.php` registers `bbp_deleted_topic` and
`bbp_deleted_reply` and no WP-native delete hook — there is no `deleted_post`,
`before_delete_post` or `wp_trash_post` anywhere in it. Those `bbp_*` actions fire
only from bbPress's own deletion flow. **Anything that deletes the post another
way removes it from WordPress while the mirror hears nothing.**

### Why 6 exists: every dispatch is fire-and-forget

```php
wp_remote_post(BB_MIRROR_SYNC_URL, [
    'timeout'   => 1,
    'blocking'  => false,   // <-- never waits, never checks, never retries
```

No response is read, no failure is logged, nothing is retried. If the endpoint is
down or takes longer than a second, WordPress never finds out.

### A ghost is worse than an orphan, and the orphan census cannot see it

| | orphan | **ghost** |
|---|---|---|
| what remains | attachment row, parent gone | **whole reply/topic row, WP post gone** |
| member-visible? | **no** — nothing can render it | **YES — it still renders in the thread** |
| found by the orphan census? | yes | **no. It reads 0.** |
| self-heals? | n/a | **no. Permanent.** |

**Measured on LIVE, 2026-07-28: 13 ghost replies and 2 ghost topics, every one
`status='publish'` with its parent topic still present — so all 15 are rendering
to members right now.** They were deleted in WordPress and the forum still shows
them. They carry 3 attachment rows between them, and the orphan census returns
**0** for all of it (confirmed by direct query).

All 15 share one `sync_at` — 2026-06-13 16:43 — so they are a single cohort last
touched by one batch run and deleted afterwards, not a steady drip. Whether that
was a bulk delete via path 5 or a lost dispatch via path 6 cannot be determined
from the data that survives; both are open.

### Correction: reconcile does NOT catch these

An earlier note in this lane said ghosts were what `bb-mirror-reconcile.timer`
exists to catch. **That is wrong, and it matters, because it made the gap sound
already-covered.** `bin/reconcile.php:70` walks:

```php
SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_modified_gmt >= %s
```

It is driven entirely from the WordPress side. **A ghost has no WordPress row, so
it can never appear in that query.** Nothing in the system will ever remove these
15 rows. Reconcile repairs drift in rows that still exist in WP; it is structurally
incapable of noticing rows that only exist in the mirror.

### The shape of the fix (not built, not applied)

Reconcile needs a **reverse pass**: walk the mirror's own ids and drop any whose WP
post is gone. It belongs in the existing reconcile job rather than a new script —
the same reason this leak got a trigger and not a sweeper. Note the two changes
compose: with the purge triggers installed, deleting a ghost row also removes its
attachment rows, so ghost repair is complete rather than trading a ghost for an
orphan.

## 8. Still open

The **WP side** has the same shape and is out of this lane's scope: deleting a
reply in WP removes its `bp_media` rows, but nothing reconciles a `bp_media_ids`
meta that lists ids whose media rows are gone. That is what made LIVE's reply
image count read 236/380 when the truth was 233/374 — see
`REPLY-IMAGE-COUNT-CEILING.md` §1. Different store, same class of defect: a
delete that does not clean up after itself.
