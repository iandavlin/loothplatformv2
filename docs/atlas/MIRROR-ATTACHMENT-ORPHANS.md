# The mirror's attachment orphan leak — mechanism, scale, and the fix

*`mirror-delete-orphans` lane, 2026-07-28. Owned after `reply-images-count` kept
tripping over it: that lane created two orphans (72225, 72240) simply by testing,
and its "max 11 images per reply" phantom — which made a cap of 6 look lossy to
three separate people — was orphan pollution and nothing else.*

> ## LANE CLOSED 2026-07-29 — `mirror-delete-orphans` @`a3b0041`
>
> **Nothing here has been applied.** Live is untouched; dev2's `looth` is
> untouched (5126 replies / 1313 topics / 1859 attachments, 0 purge triggers).
> The code ships in `schema.pg.sql` for fresh databases.
>
> **Two items were handed to Ian, each rollback-first, neither run:**
> 1. the attachment migration + rollback — `bb-mirror/deploy/2026-07-28-attachment-purge-live*.sql`, 24 orphan rows (§6)
> 2. a one-statement reconcile-bookmark rewind fixing 5 drifted rows, including the live forum crediting one member's post to another (§7)
>
> **One thing is UNPROVEN and is written up rather than dropped: the WordPress
> hook → HTTP → mirror hop (§9).** No dev2 window was available. §9 states
> exactly what that does and does not leave uncertain, and how to close it in
> about ten minutes.
>
> **The charter's bug turned out to be the smallest thing here.** The attachment
> leak was real (24 live rows). Underneath it: the previously committed fix was
> itself broken for hand-run SQL (§4); the mechanism was five delete paths, not
> one (§7); ghosts are permanent, member-visible, and invisible to the orphan
> census everyone was using (§7); two members' replies never rendered for six
> weeks (§7); and three live posts show the wrong author (§7). Four of those five
> trace to one non-blocking HTTP call whose result nobody checks — see §10.

> ## UPDATE 2026-07-29 — `mirror-dispatch` lane, @`aa4f403`
>
> The lane §10 called for was chartered by Ian and built. **Three claims in this
> document are now wrong and are corrected in place below rather than deleted**,
> because how they were wrong is the useful part.
>
> | was | now |
> |---|---|
> | §9 "the WP hook → HTTP → mirror hop is UNPROVEN" | **PROVEN**, both directions, against the DEPLOYED endpoint — §9 |
> | §7 path 5 "wp-admin/WP-CLI delete: NO — no hook exists" | **wrong.** It does reach the mirror, by an accident of ordering in WP core — §7 |
> | §7 "the shape of the fix (not built, not applied)" | **built and verified** against the real mirror — `1576134`, verified `a4a121e` |
>
> **Still true, and still unapplied:** every runbook in §5/§6 (the attachment
> migration, the bookmark rewind, the ghost cleanup, re-materializing 71678 and
> 71723). Those are Ian's. Nothing in this update touched live, and nothing in it
> touched dev2's data — all four tests restore their baseline and assert it.
>
> **The new hole this lane found**: nothing is *running*. The outbox worker's
> systemd timer is not installed on dev2, and `~/loothplatformv2-clean` does not
> contain the reverse pass at all. Code merged is not code running — see §11.

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
| 5 | **wp-admin bulk delete, `wp_delete_post()`, WP-CLI** | ~~NO~~ → **yes, by accident** (2026-07-29) | **yes** |
| 5b | direct SQL against `wp_posts` | **NO** — the only genuinely hookless path | no |
| 6 | **dispatch lost in flight** (fire-and-forget) | **NO** → **closed by the outbox** (2026-07-29) | **yes** |
| 7 | `TRUNCATE` on the mirror tables | n/a | no (nothing truncates them) |

**1–4 are closed by this change. 5 and 6 are a different defect**, and they do not
produce orphans at all — they produce **ghosts**.

### Why 5 exists: ~~the mu-plugin only hooks bbPress, not WordPress~~

> **CORRECTION, 2026-07-29 (`mirror-dispatch` lane). The claim below is wrong,
> and it was wrong in the direction that matters: it says a path is broken when
> it actually works.** It was made from code reading. §9 step 4 predicted that a
> test there would FAIL and said "a pass would mean §7's matrix is wrong and
> should be reopened." It passed. Reopened.
>
> Measured on dev2 with every outbound HTTP request intercepted and recorded:
>
> | path | dispatched? |
> |---|---|
> | reply `wp_delete_post($id, true)` | `{"kind":"reply","action":"delete"}` |
> | reply `wp_trash_post()` | `{"kind":"reply","action":"trash"}` |
> | topic `wp_delete_post($id, true)` | `{"kind":"topic","action":"delete"}` |
> | **direct SQL `DELETE FROM wp_posts`** | **none** — the one real gap |
>
> **The bridge is BuddyBoss's, not ours.** `bp-forums/core/actions.php` registers
> `add_action('deleted_post', 'bbp_deleted_reply')`, and on WP 6.9.5
> `deleted_post` fires at `post.php:3936` — one line BEFORE `clean_post_cache()`
> at `:3938`. So `bbp_deleted_reply()`'s `bbp_is_reply()` guard still finds a warm
> post cache, passes, and re-emits the `bbp_*` action this file already hooks.
>
> **It is closed by an accident of ordering, which is why it still got a
> backstop.** The chain rests on two lines of WP core staying in that order and on
> the post cache being warm at that instant. Neither is a contract: reorder them
> upstream, or run an external object cache that has already evicted the row, and
> `bbp_is_reply()` returns false, the `bbp_*` action never fires, and every
> WP-native delete goes silently missing again — exactly the failure that produced
> live's 13 ghost replies and 2 ghost topics.
>
> So `platform/mu-plugins/bb-mirror-sync.php` now hooks the WP-native path
> directly: capture the post type in `before_delete_post` (while `wp_posts` still
> has the row and the answer is knowable from the database rather than a cache),
> enqueue on `deleted_post`. Hooking `deleted_post` alone would not work — by then
> the type is exactly what you cannot look up, which is the same cliff bbPress is
> standing on. Proven by `bin/test-native-delete-hooks.php`, which **deletes
> BuddyBoss's bridge** and asserts our backstop carries reply, topic and forum
> regardless; 20 checks, negative control included.
>
> **A forum delete was never dispatched at all** — `api/v0/_sync.php` has handled
> `['forum','delete']` since it was written and nothing in WordPress had ever sent
> one, so a deleted forum stayed in the mirror forever, and so did every topic and
> reply beneath it, because the Postgres cascade never got a DELETE to fire on.
> `bbp_deleted_forum` is now hooked too.

The original claim, for the record:

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

### The same comparison, run the other way: replies that NEVER arrived

The ghost check compares mirror→WordPress. Run WordPress→mirror and a second,
member-facing defect falls out: **6 replies and 1 topic exist in WordPress but
not in the mirror.** Because the mirror *is* the forum read path, those replies
never rendered. Members wrote them and nobody saw them.

They are not one thing, and the difference decides who can fix them:

| WP id | author | parent topic | why it never arrived |
|---|---|---|---|
| **71678** | Karl Borum | 71649 — **present in mirror** | **genuine lost sync** |
| **71723** | Robert Owens | 71484 — **present in mirror** | **genuine lost sync** |
| 71720, 71722 | Roger Sadowsky, Michael Minton | `_bbp_topic_id` = **71685, which is an `attachment`** (an image, `archtop-invisible-repair-005`) | corrupt bbPress metadata — the reply is parented to a media item, so the mirror's FK can never accept it |
| 71728 | Colin O'Brien | 71671 — **does not exist in WordPress at all** | parent topic deleted; nothing to hang it on |
| 4298 | — | — | a 2023 **draft** — correctly not mirrored, not a defect |

**The two genuine losses are visible on live right now**, because `reply_count` is
recomputed from WordPress (authoritative) while the thread renders from the
mirror. The count and the content disagree:

| topic | advertises | actually renders |
|---|---|---|
| 71484 "Back Braces Shape" | **9 replies** | **8** |
| 71649 "Nominal Normal Thickness…" | **4 replies** | **3** |

A member counts the replies, gets one fewer than the header promises, and the
missing one is a real answer somebody took the time to write. Both replies are
`publish`, both parent topics are live and rendering, and both have been missing
since mid-June.

These two are trivially repairable — re-materializing ids 71678 and 71723 inserts
them — but that is a **write to live, so it is Ian's**. The reconcile timer will
never do it on its own: its delta walk only looks at posts modified since the
bookmark, and these have not been touched since June.

The other three are a WordPress-side data problem, not a mirror problem. The
mirror is right to reject a reply whose parent is an image or does not exist.

### The shape of the fix ~~(not built, not applied)~~ — BUILT, and verified

Reconcile needs a **reverse pass**: walk the mirror's own ids and drop any whose WP
post is gone. It belongs in the existing reconcile job rather than a new script —
the same reason this leak got a trigger and not a sweeper. Note the two changes
compose: with the purge triggers installed, deleting a ghost row also removes its
attachment rows, so ghost repair is complete rather than trading a ghost for an
orphan.

> **STATUS 2026-07-29.** `bb_mirror_sweep_ghosts()` plus reconcile's call to it
> landed in `1576134` on 2026-07-28 — the heading above was already stale when it
> was written. The `mirror-dispatch` lane therefore **verified rather than
> rebuilt** it, in two places:
>
> - `bin/test-ghost-sweep.php` — scratch DB, passes in full.
> - `bin/test-ghost-sweep-live-shape.php` (`a4a121e`) — the same function against
>   the **real** `forums` schema and real `looth` rows, because "works on a
>   scratch DB" and "works on the serving mirror" are two different claims.
>   14 checks. **The shield is the interesting part**: dev2 holds 15 real
>   pre-existing ghosts, so the `wp_ids_for` callback returns real WP ids *plus*
>   those 15, leaving exactly one ghost to sweep — the manufactured one. That the
>   15 survive is itself the blast-radius assertion. Negative control:
>   re-manufacture, run report-only, the ghost is still there; only `apply`
>   removes it.
>
> **`bin/ghost-census.php`** (read-only) makes drift a one-command question in
> both directions. On **dev2**, 2026-07-29:
>
> | | |
> |---|---|
> | ghosts (mirror → WP, still rendering) | 2 topics, 13 replies |
> | never arrived (WP → mirror) | 2 genuine losses (71678, 71723), 4 WP-side corrupt, 1 correct draft |
> | orphaned attachments | 16 rows / 12 lost topic parents |
> | attachment-purge triggers | **0 of 2 — the leak is still open on dev2** |

## 8. The rest of the mirror, checked the same way

The arithmetic-closed comparison (mirror ids vs WordPress ids, both directions,
totals reconciling) found three defect classes in `reply`/`topic`. Applied to the
remaining tables, 2026-07-28:

| table | result |
|---|---|
| `forum` | **clean.** 55 mirrored, 59 in WP; the 4 unmirrored are **all `draft`** — correctly excluded |
| `person` | **clean.** 5 rows whose WP user is gone, **all our own dead test accounts** (`qa-disposable`, `visibility-matrix-qa`, `claude_admin`, `deltest_admin`, one hex fixture) |
| `bp_group` | **clean.** 20 in the mirror, 20 in WP, **id sets identical** |
| `forum_subscription` | **empty — and that is the finding.** See below |

In each case the count alone would have read as a defect. `forum`'s 4 are drafts.
`person`'s 5 are our own fixtures — though one of them, `qa-disposable`, is
attached to real live content, which is how §7's author misattribution surfaced.

### `forum_subscription` is empty, and it is dormant rather than broken

Live holds **zero** rows. WordPress holds **1,563** real forum/topic subscriptions
in `wp_bb_notifications_subscriptions` (1,517 topic + 46 forum, ~400 members),
none of them mirrored.

That reads like a 1,563-row outage and is not one. `forum_subscription` is
referenced **only by the write path** in `api/v0/_sync.php` — grep finds no read
path, and there is no subscribe control on the mirror's forum surface. Nothing has
ever backfilled the existing subscriptions and nothing consumes them. It is a
table that was built ahead of its feature.

**What it is instead is a loaded gun.** It is keyed `(user_id, target_kind,
target_id)` with `target_kind` an ENUM — polymorphic, no foreign key, no
`ON DELETE CASCADE`. Byte for byte the shape that leaked `attachment` rows for
months. The moment somebody backfills those 1,563 rows, every topic delete starts
stranding subscriptions.

So the same triggers went in now, while the table is empty and the change is free
and unobservable (§4's function, `subscription_purge_for_target`, on `forum` and
`topic`). Proven in the live-shaped rehearsal DB: deleting a topic cleared its 7
subscriptions with siblings untouched; deleting the forum cascaded and cleared the
rest; with the triggers dropped the same delete stranded all 7.

**This is deliberately NOT in the migration Ian was handed.** That block is
unchanged and still extracts byte-identically — moving the goalposts under a
command someone is about to paste is its own kind of defect. The subscription
triggers ship in `schema.pg.sql` for fresh databases and can go to live in a later
window, because on an empty unread table there is nothing to race.

> **Cross-lane note:** the same WordPress table holds **12,948 `group`
> subscriptions** — the number the thread-follow lane's ruling turns on. Different
> `type`, same store.

## 9. ~~UNPROVEN~~ **PROVEN**: the WordPress hook → HTTP → mirror hop

> **CLOSED 2026-07-29, `mirror-dispatch` lane — `bin/test-sync-hop-e2e.php`,
> 21 checks, run twice on dev2.** §9 estimated ten minutes once a window existed;
> that is about what it took. The section below is kept as written because the
> reasoning that bounded the risk was correct, and because it is the record of
> what was and was not known before the measurement.
>
> **Both directions, against the serving mirror:**
>
> | | | |
> |---|---|---|
> | create | WP topic → outbox → real HTTPS → `forums.topic` **exists** | 1313 → **1314** |
> | delete | `wp_delete_post()` → hooks → outbox → HTTPS → row **gone** | 1314 → **1313** |
>
> Delivery is the worker's own path — blocking raw curl, `CURLOPT_RESOLVE`
> pinning `dev2.loothgroup.com` to `127.0.0.1`, response read and parsed. Round
> trips 0.31–0.54s warm.
>
> **The receiver is the DEPLOYED one, which is what makes this worth having.**
> `/srv/bb-mirror/api/v0/_sync.php` contains the string `outbox` **zero** times —
> it does not carry the dispatch branch. So the hop is proven against the endpoint
> as it runs today, and the rows were acked by the **worker**, not the receiver.
> The fast-path ack cannot work on dev2 until the branch is deployed; asserted,
> not assumed.
>
> **The fast path was predicted to lose the race and it WON, both runs** — so that
> result is printed and never asserted. `wp_remote_post(blocking=>false,
> timeout 1)` against an endpoint measured at 7.3s cold / ~1.1s warm wins on an
> idle box and loses on a cold or saturated one. A leg whose bias moves with load
> is not a guarantee; the bulk delete firing N at once into `pm.max_children = 8`
> is the case that made the ghosts. What is asserted is that **the outbox delivers
> regardless**.
>
> **Negative control:** the same fixture with its WP row removed via `$wpdb`, so
> no hook fires and nothing is enqueued — and the mirror row **survives**. A ghost,
> manufactured the way live got its 15. Without it the other steps could be
> passing because something else was tidying up.
>
> Containment: teardown runs in a `finally`, so a mid-run failure still cleans the
> serving mirror; baselines re-asserted (1313 / 5126 / 1859, `wp_posts` back to
> 20291, zero outbox residue). The 15 real dev2 ghosts were never touched.

### Exactly what was unproven

One segment of the delete path, end to end on a running system:

```
bbPress delete → bbp_deleted_reply fires → bb_mirror_sync_dispatch()
   → wp_remote_post (non-blocking, 1s) → nginx → api/v0/_sync.php
   → DELETE FROM reply WHERE id = ?          ← everything from here is proven
```

Proving it means letting a real WordPress delete reach dev2's `looth`, which
needs the purge triggers installed there first. That was requested at 13:30 on
2026-07-28 and not granted.

### Exactly what IS proven, so the gap is not overstated

The trigger sits *downstream* of the unproven segment, and it has been fired from
three independent issuers against the real schema:

| issuer | proven by |
|---|---|
| PHP/PDO running the **verbatim** `DELETE FROM $kind WHERE id = ?` from `_sync.php:98` | `bin/test-attachment-purge.php`, through the real materializers, six-image reply |
| `psql` with `forums` absent from `search_path` | §4, the case that used to raise and abort |
| Postgres itself, via FK cascade | topic- and forum-delete cases, §4 |

So the residual risk is **not** "the trigger might not fire on that statement".
It is "the statement might never be issued" — which is **path 6 in §7, already
documented as a known unfixed gap**: the dispatch is
`wp_remote_post(..., 'blocking' => false, timeout 1)` with no response read, no
retry and no log. The unproven hop and the known defect are the same stretch of
wire. Verifying it would have *measured* that transport, not fixed it.

### How to close it — about ten minutes, once a window exists

1. Apply the triggers to dev2: `bb-mirror/bin/fix-attachment-orphans.sh apply`
   (as `bb-mirror` — see §6 on the role).
2. In the dev2 UI, post a reply with images to any topic, then delete it through
   the normal bbPress control.
3. `SELECT count(*) FROM forums.attachment WHERE parent_kind='reply' AND
   parent_id=<id>;` → expect **0**, and the reply row gone.
4. Repeat once with the reply deleted from **wp-admin's** post list rather than
   the bbPress control. That is path 5, and it is expected to FAIL — the mirror
   should keep both rows, because no WP-native delete hook exists. A pass there
   would mean §7's matrix is wrong and should be reopened.

Step 4 is the valuable one. Steps 1–3 confirm something already strongly
evidenced; step 4 tests a claim made from code reading alone.

### Everything else in this lane is proven or explicitly measured

Nothing else is left dangling. The fix, the rollback, both live runbooks, the
ghost sweep and its safety rails, and the whole-mirror comparison all have
measurements behind them, including negative controls.

## 10. Out of scope, and still true

The **WP side** has the same shape: deleting a reply in WP removes its `bp_media`
rows, but nothing reconciles a `bp_media_ids` meta that lists ids whose media rows
are gone. That is what made LIVE's reply image count read 236/380 when the truth
was 233/374 — see `REPLY-IMAGE-COUNT-CEILING.md` §1. Different store, same class
of defect: a delete that does not clean up after itself.

~~Also unaddressed by design, from §7's matrix: **path 5** (no WP-native delete
hook) and **path 6** (fire-and-forget dispatch).~~ Neither is fixable with a
database constraint. Path 6 in particular is the root cause behind the ghosts, the
two replies that never rendered, and the author drift — three of the four defect
classes this lane found trace back to one non-blocking HTTP call that nobody ever
checks the result of. **That is the next piece of work in this area**, and it is
larger than a lane-closing note: it means giving the dispatch a durable queue or a
verification pass, not a retry bolted onto `wp_remote_post`.

> **Done, 2026-07-29 — that next piece of work is the `mirror-dispatch` lane.**
> Path 6 is closed by the outbox (§11); path 5 turned out to be already closed by
> accident and got a backstop anyway (§7). The paragraph above is left standing
> because it is the charter that produced them.
>
> **What remains genuinely out of scope and still true** is the first paragraph of
> this section: the WP side has the same shape. Deleting a reply in WP removes its
> `bp_media` rows, but nothing reconciles a `bp_media_ids` meta listing ids whose
> media rows are gone. Different store, same class of defect — a delete that does
> not clean up after itself.

## 11. The dispatch is durable now — and nothing is running it

*Added by the `mirror-dispatch` lane, 2026-07-29.*

### The outbox

Every mirror-relevant event writes a row in `wp_bb_mirror_outbox` at the moment of
the WP change; the existing non-blocking POST stays the **fast path** and carries
its `outbox_id`; `api/v0/_sync.php` **acks the row through the database** once it
has materialized the change — which is the trick that keeps the fast path
non-blocking and still makes it verifiable; and `bin/outbox-worker.php` redelivers
whatever nobody acked over a blocking raw curl whose response *is* read, then backs
off and dead-letters. **A row still `pending` past its grace window IS the alarm.**

Four decisions worth not re-litigating:

- **The outbox lives in MySQL, not the mirror's Postgres.** The event is recorded
  in the same database as the fact that generated it. Putting the durability
  record in Postgres would put it in the exact failure domain it exists to survive.
- **Ordering is load-bearing.** Events replay per object in enqueue order and a
  group stops at its first failure. Replaying upsert→delete→upsert out of order is
  a way to manufacture the very ghost this document is about.
- **4xx dead-letters immediately**; only 408/429 and 5xx/curl failures get the
  backoff ladder. Retrying a malformed payload twelve times just delays the human.
- **Loading the lib is optional by design.** Absent `/srv/bb-mirror`, every
  dispatch degrades to exactly its old behaviour. A broken outbox must never make
  the site worse than no outbox.

Alerting is the **systemd exit code**, not `wp_mail` — on dev2 `wp_mail` is a known
false positive (mailpit swallows it).

### A topic delete enqueues TWO rows, and always has

Traced with an `all`-hook tracer, 2026-07-29: bbPress's own `bbp_delete_topic()`
calls `bbp_unstick_topic()`, which fires the `bbp_unstick_topic` action — mapped to
`upsert` in this mu-plugin since long before this lane. So every topic delete is
**upsert-then-delete**. It converges rather than corrupts, for two independent
reasons, both now asserted rather than assumed:

- ordering is preserved, so `delete` is terminal; and
- `bb_mirror_upsert_topic()` opens `if (!$p || $p->post_type !== 'topic')` →
  `DELETE FROM topic` (`lib/materializers.php:346`), so with the WP post already
  gone **the upsert deletes too**. Both rows drive the mirror to the same state.

### THE FINDING THAT OUTRANKS THE CODE: none of this is running

| | |
|---|---|
| `bb-mirror-outbox.timer` installed on dev2 | **no** — only `bb-mirror-reconcile.timer` is |
| `~/loothplatformv2-clean` contains the reverse pass (`1576134`) | **no** — the serving checkout was 47 commits behind |
| `/srv/bb-mirror/api/v0/_sync.php` contains the outbox ack | **no** — `outbox` appears 0 times |

Which is why reconcile's journal shows no ghost lines every ten minutes, and why
the end-to-end proof in §9 had to be acked by the worker rather than the receiver.
**Code merged is not code running.** The deploy of this branch has two couplings a
`git pull` does not handle: the systemd unit for the outbox timer must be installed
and enabled, and the mu-plugin symlink set must be refreshed.

### The tests, and what each one would catch

| test | asserts | negative control |
|---|---|---|
| `bin/test-outbox.php` | 28 checks — enqueue, collapse, backoff, dead-letter, stats | a real connection failure, by pinning delivery at a dead port |
| `bin/test-native-delete-hooks.php` | 20 checks — reply/topic/forum native delete recorded | **BuddyBoss's bridge deleted**, then ours too: the event vanishes |
| `bin/test-sync-hop-e2e.php` | 21 checks — §9's hop, both directions, real HTTPS | a ghost manufactured with `$wpdb`, which survives |
| `bin/test-ghost-sweep-live-shape.php` | 14 checks — the reverse pass on the real mirror | report-only leaves the ghost; only `apply` removes it |

> **A trap that cost two red runs, recorded so it costs a third nobody.**
> `wp eval-file` includes the file from inside a **method**, so everything at
> "top level" is function-local. A helper doing `global $TABLE` imports an *unset*
> global and interpolates the empty string into ``FROM `` ``. `$wpdb` answers that
> with **0 rows instead of raising**, so the helper silently does nothing and every
> downstream assertion fails while the code under test is perfectly fine. Ask
> `bb_mirror_outbox_table()` for the name; never trust scope.
