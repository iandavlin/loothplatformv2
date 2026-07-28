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

There is **no `DELETE FROM forums.attachment` anywhere in the repository.** Rows
are inserted and never removed.

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

| | rows | parents |
|---|---|---|
| orphaned, `parent_kind='topic'` | **16** | 12 |
| orphaned, `parent_kind='reply'` | **6** | 1 |
| **total leaked** | **22** | 13 |
| live topic attachments | 1,017 | 516 |
| live reply attachments | 893 | 514 |

Small today — but note **12 of the 13 lost parents are topics**, which is the
path nobody was looking at.

**On the rate, stated precisely rather than dramatically:** storage was never
capped, so a deleted reply always stranded *all* its rows. What changed on
2026-07-28 is that multi-image replies became a feature members can see (`6ef25e3`
shipped up to 6 per reply to live). The per-delete behaviour is unchanged; the
*population* of multi-image replies is what will grow, and with it the leak.

## 4. The fix — the CASCADE a polymorphic column can't have

`bb-mirror/schema.pg.sql`, alongside the existing search-doc triggers:

```sql
CREATE OR REPLACE FUNCTION attachment_purge_for_parent() RETURNS trigger AS $$
BEGIN
  DELETE FROM attachment
   WHERE parent_kind = TG_ARGV[0]::attachment_parent_kind
     AND parent_id   = OLD.id;
  RETURN OLD;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER topic_attachment_purge AFTER DELETE ON topic
  FOR EACH ROW EXECUTE FUNCTION attachment_purge_for_parent('topic');
CREATE TRIGGER reply_attachment_purge AFTER DELETE ON reply
  FOR EACH ROW EXECUTE FUNCTION attachment_purge_for_parent('reply');
```

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

Scratch DB, real `schema.pg.sql`:

| case | before fix | after fix |
|---|---|---|
| delete one reply | 6 orphans | **0** |
| delete a topic (cascade) | 11 orphans | **0** |
| delete a forum (2-level cascade) | 12 orphans | **0** |
| siblings untouched | — | r101 kept 2, r102 kept 1, t10 kept 3 |
| `topic:100` row when **reply** 100 deleted | — | **survives** |
| rollback (triggers dropped) | — | **leak returns** — rollback is real |

## 5. Applying it — `bb-mirror/bin/fix-attachment-orphans.sh`

```
./fix-attachment-orphans.sh dry-run    # default; changes nothing
./fix-attachment-orphans.sh apply
./fix-attachment-orphans.sh rollback
```

- **Dry run** prints the orphan census, the rows that must survive, and the
  user-visible reply-image counts.
- **Apply** backs up the exact rows to `/tmp/<tag>.orphan-rows.tsv`, installs the
  triggers *first* (so nothing leaks during the sweep), then sweeps, then
  **asserts the user-visible counts are unchanged** and fails loudly if not.
- **Rollback** drops the triggers. It does not resurrect swept rows — they
  pointed at parents that no longer exist and nothing could render them.

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
