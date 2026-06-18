# Coordinator → BB-mirror: P5 — mu-plugin live rehearsal

Render bugs are clean, group model is honest. You're cleared for P5.

## What P5 means

`bb-mirror-sync.php` running on dev, syncing live BB actions into the postgres `forums` schema in real time. By the end of this, posting a topic in BB triggers an upsert in postgres within seconds, and your read path serves the mirrored content without a manual backfill run.

## Hooks to wire (from coord §3f)

- `bbp_new_topic` → upsert topic row
- `bbp_new_reply` → upsert reply row
- `bbp_edit_topic`, `bbp_edit_reply` → update row
- topic/reply trash + spam → update `visibility` / soft-delete flag
- merge/split → re-parent affected rows

Reconciliation cron: walks `wp_posts WHERE post_type IN (forum,topic,reply) AND modified > last_reconcile` as belt-and-suspenders for missed hook fires.

## How to verify

1. Post a test topic in BB on dev
2. `SELECT * FROM forums.topic ORDER BY created_at DESC LIMIT 1;` — should appear within a few seconds
3. Post a reply to it — same check on `forums.reply`
4. Trash the topic in BB admin — confirm `visibility` updates or row is soft-deleted
5. Run the reconciliation cron manually — confirm it catches anything the hooks missed

## Also: group sync hooks

While you're in the mu-plugin, wire the group sync too (from the audit relay):
- `groups_create_group` → upsert `forums.groups`
- `groups_delete_group` → mark deleted (orphan-gate rule: attached forums fall back to no-gate)
- `groups_update_group` → update name/member_count

This keeps the group table live, not just backfilled once.

## Definition of done for P5

- Post topic → appears in postgres within 10s
- Edit/trash → reflected correctly
- Reconciliation cron runs clean (0 missed rows after hook fires are confirmed)
- Group sync wired alongside

## Report back

```
**BB-mirror → coordinator:** P5 mu-plugin live rehearsal done

```
/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md
```
```

**Note from Ian:** coordinator is a fresh session — push back if any instructions feel off or don't match your current state. You know your codebase better than coordinator does.

— coordinator
