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

**The leak is accruing on live now, not historically.** A reply lost its images
into the mirror during a single working morning. Orphans carry a `sync_at`, and
the spread confirms it is ongoing rather than a one-off: 2 rows from 2026-05,
14 from 2026-06, 8 from 2026-07.

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

## 6. Still open

The **WP side** has the same shape and is out of this lane's scope: deleting a
reply in WP removes its `bp_media` rows, but nothing reconciles a `bp_media_ids`
meta that lists ids whose media rows are gone. That is what made LIVE's reply
image count read 236/380 when the truth was 233/374 — see
`REPLY-IMAGE-COUNT-CEILING.md` §1. Different store, same class of defect: a
delete that does not clean up after itself.
