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

## Group B — 3 REAL member replies (do NOT delete)

These are genuine answers that are currently invisible on the hub. **Their true topic
is not recoverable from the data** — I checked: `71685` is an attachment
(`archtop-invisible-repair-005`) whose own `post_parent` is 0, and `71671` does not
exist in WordPress at all. So no query can tell us where they belong; only you can.

| id | author | says | claims topic |
|---|---|---|---|
| 71720 | patreon_63883555 | "I spray the wood with veneer softener and put a s…" | 71685 (an **attachment**) |
| 71722 | patreon_120178820 | "You could try wetting it and then compressing in…" | 71685 (an **attachment**) |
| 71728 | colingobrien | "The bubbles do appear to follow the grain." | 71671 (**absent**) |

**Step 1 — read them in full so you can recognise the thread:**

```
ssh live 'cd /var/www/loothgroup.com && wp db query "SELECT ID, post_date_gmt, post_content FROM wp_posts WHERE ID IN (71720,71722,71728)"'
```

**Step 2 — once you know the right topic id for one of them, re-parent it.** Replace
`<REPLY_ID>` and `<TOPIC_ID>` with real numbers; the guard refuses anything that is
not a published topic, so a typo cannot attach a reply to a page or an attachment:

```
ssh live 'cd /var/www/loothgroup.com && wp db query "UPDATE wp_posts r JOIN wp_posts t ON t.ID=<TOPIC_ID> AND t.post_type='"'"'topic'"'"' AND t.post_status='"'"'publish'"'"' SET r.post_parent=t.ID, r.post_modified_gmt=UTC_TIMESTAMP() WHERE r.ID=<REPLY_ID> AND r.post_type='"'"'reply'"'"'"'
```

```
ssh live 'cd /var/www/loothgroup.com && wp post meta update <REPLY_ID> _bbp_topic_id <TOPIC_ID>'
```

```
ssh live 'cd /var/www/loothgroup.com && wp post meta update <REPLY_ID> _bbp_forum_id $(ssh live "cd /var/www/loothgroup.com && wp post meta get <TOPIC_ID> _bbp_forum_id")'
```

Bumping `post_modified_gmt` is deliberate: it puts the reply back inside reconcile's
window, so the mirror picks it up on the next pass without anything else being run.

---

## Verify (run after either group)

```
ssh live 'cd /var/www/loothgroup.com && wp db query "SELECT r.ID, r.post_status, r.post_parent, MAX(CASE WHEN m.meta_key='"'"'_bbp_topic_id'"'"' THEN m.meta_value END) AS topic_meta FROM wp_posts r LEFT JOIN wp_postmeta m ON m.post_id=r.ID WHERE r.ID IN (71432,71433,71720,71722,71728) GROUP BY r.ID, r.post_status, r.post_parent"'
```

Healthy afterwards means: 71432 and 71433 show `trash`, and any reply you re-parented
shows a `topic_meta` that is a real published topic.

## The success signal

**`mirror-sync-watch` goes quiet.** It runs every 15 minutes and has been alerting for
hours on these rows. Within one cycle of the fix its board post should stop naming
them. That is the check that matters — not the SQL output, but the watcher agreeing.

If it still alerts after ~30 minutes, tell the lane rather than re-running anything:
that would mean a sixth row we have not measured.
