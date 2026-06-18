# BB-mirror — Session Handoff (2026-05-28, P5 live rehearsal + reconcile cron)

## What this project is

Read-side strangler for BuddyBoss/bbPress forum threads. Reads from postgres
mirror at native speed; writes still round-trip through BB REST. Mu-plugin
syncs WP→postgres in real time on bbPress + BP-groups hooks; systemd timer
runs reconcile cron every 10 min as belt-and-suspenders.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage: [§3i](../docs/STRANGLER-COORDINATION.md).
P5 briefing: [reply-to-bb-mirror-p5.md](../docs/reply-to-bb-mirror-p5.md).

## Current state — P5 live rehearsal done, reconcile timer running

### Live rehearsal (2026-05-28, all 4 hooks verified end-to-end)

| Hook | Verification |
|---|---|
| `bbp_new_topic` | Topic 69460 created → landed in `forums.topic` within 2s |
| `bbp_edit_topic` | Content updated → reflected at `:32:05` (1s after edit) |
| `bbp_new_reply` | Reply 69462 created → landed in `forums.reply` at `:32:12` |
| `bbp_trashed_topic` | `status` updated to `trash` at `:32:17` |
| `bbp_deleted_topic` / `bbp_deleted_reply` | Test rows removed from pg (cleanup) |

Note: `bbp_insert_topic()` is a low-level helper that **bypasses** `do_action('bbp_new_topic')` — used for imports. The real UI path goes through `bbp_new_topic_handler()` which fires the hook. Tests fired the hook explicitly via `do_action()` — same code path as form submission.

Sync POSTs from WP show as `499` in nginx access logs (client disconnected) — that's the mu-plugin's `blocking=false, timeout=1` fire-and-forget pattern. The receiver still completes; 499 is **expected, not a failure**.

### Reconcile cron live

- Script: [bin/reconcile.php](bin/reconcile.php)
- Service: `/etc/systemd/system/bb-mirror-reconcile.service` (runs as `looth-dev`)
- Timer: `/etc/systemd/system/bb-mirror-reconcile.timer` — every 10 min, 30s accuracy, 2 min OnBootSec
- Bookmark: `sync_state.last_reconcile_at` (60s overlap absorbs clock skew + in-flight POSTs)
- First-run bootstrap: 24h window if bookmark unset
- Behavior verified: first run picked up 1 forum + 1 topic + 1 reply + 20 groups; immediate re-run touched 0 rows besides groups (groupmeta has no `modified` column so we walk all groups each pass — cheap, 20 rows)
- Refreshes both rollups (`total_last_active_at`, `effective_group_id`) sitewide on every run

### Shared materializer lib (refactor this session)

[lib/materializers.php](lib/materializers.php) — extracted from `_sync.php`. Required by both:
- [api/v0/_sync.php](api/v0/_sync.php) — receiver (now ~120 lines, just dispatch)
- [bin/reconcile.php](bin/reconcile.php) — cron walker

Single source for `bb_mirror_upsert_forum`, `bb_mirror_upsert_topic`, `bb_mirror_upsert_reply`, `bb_mirror_upsert_bp_group`, `bb_mirror_refresh_effective_group`, `bb_mirror_person_for`, `bb_mirror_post_meta_all`, plus internal helpers.

## Files changed this session

| File | Change |
|---|---|
| `lib/materializers.php` | **new** — shared upsert helpers, single source |
| `api/v0/_sync.php` | slimmed to dispatch-only; requires materializers lib |
| `bin/reconcile.php` | **new** — delta walk + rollup refresh + bookmark management |
| `/etc/systemd/system/bb-mirror-reconcile.service` | **new** — oneshot service |
| `/etc/systemd/system/bb-mirror-reconcile.timer` | **new** — every 10 min |

## Postgres infrastructure on dev (unchanged)

- DB `looth`, schema `forums`, role `bb-mirror`
- 9 tables; 55 forums, 1128 topics, 4405 replies (1592 threaded), 465 persons, 20 bp_groups
- `forum.effective_group_id` covers 46/55 forums via ancestor inheritance

## Notes / gotchas

- **`bbp_insert_topic()` and `bbp_insert_reply()` do NOT fire the high-level hooks.** They're import helpers. If you're testing the sync path from CLI, either use the actual form-submit code path or `do_action()` the hook explicitly. wp-admin posting + REST POSTs DO fire the hooks correctly.
- **Sync POSTs return 499 in nginx logs — that's normal.** WP's `wp_remote_post(['blocking' => false, 'timeout' => 1])` fires off the request and immediately disconnects, but the receiver still completes the work. If you see 5xx (not 499), that's a real failure.
- **Reconcile bootstraps with a 24h window** on first run (when `sync_state.last_reconcile_at` is unset). After that, it walks the modified-since window only. Bookmark updates after successful completion.
- **Groups walked on every reconcile** because `wp_bp_groups` has no `modified` column. 20 rows × cheap upserts = ~50ms; not worth optimizing.
- `_sync.php` SCRIPT_FILENAME is still set to absolute path in nginx — don't "clean this up" without re-testing.
- SQLite fallback code paths still present in `config.php`, `lib/materializers.php`, `bin/init-db.php`, `bin/backfill.php`. Rollback window not closed.
- `person.is_moderator` still all false; data-side fill remains a separate work item.

## Next session queue (queue #1 still next — reply form JS)

1. **Reply form JS fetch handler** — POST to `/wp-json/buddyboss/v1/reply` with `parent_reply_id`, reload on 200. Gating-aware: anonymous → "Sign in"; authenticated + missing group membership on group-gated forum → "Join SoCal to post"; otherwise enabled. Group-membership check upstream-blocked on `/whoami`; can render generic "Sign in to post" CTA without it.
2. **Search box** — FTS index populated; UI not built
3. **`forum_read_state` "mark seen" endpoint** — table exists, endpoint not built
4. **Attachment harvest** — schema in; harvest job not built
5. **Sticky topics** — `_bbp_sticky_topics` (CSV on forum) not read at backfill
6. **Retire SQLite fallback** — once rollback window closes
7. **Group-member-aware private visibility** — needs `/whoami` + user-group membership table

The **reconcile cron is no longer in the queue** — landed this session.

## How to test

```bash
# Manually run reconcile
sudo systemctl start bb-mirror-reconcile.service
sudo journalctl -u bb-mirror-reconcile.service -n 30 --no-pager

# Check timer schedule
sudo systemctl list-timers bb-mirror-reconcile.timer

# Fire bbp_new_topic + watch it land
cd /var/www/dev && sudo -u www-data wp eval '
$tid = bbp_insert_topic(["post_title"=>"test","post_content"=>"x","post_status"=>"publish","post_author"=>1],["forum_id"=>3876]);
do_action("bbp_new_topic", $tid, 3876, false, 1);
echo "topic_id=$tid\n";
'
sleep 2 && sudo -u bb-mirror psql -d looth -c "SELECT id, slug FROM forums.topic ORDER BY id DESC LIMIT 1;"

# Cleanup after smoke test
sudo -u www-data wp post delete <topic_id> --force
sudo -u www-data wp eval 'do_action("bbp_deleted_topic", <topic_id>);'

# Verify reconcile bookmark
sudo -u bb-mirror psql -d looth -c "SELECT value, updated_at FROM forums.sync_state WHERE key='last_reconcile_at';"
```

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- P5 briefing: [/home/ubuntu/projects/docs/reply-to-bb-mirror-p5.md](../docs/reply-to-bb-mirror-p5.md)
- Audit briefing: [/home/ubuntu/projects/docs/reply-to-bb-mirror-audit-findings.md](../docs/reply-to-bb-mirror-audit-findings.md)
- Mockup v2: https://dev.loothgroup.com/mockups/forums.html
- Prior handoffs: [handoffs/](handoffs/) — latest before this is `2026-05-28-audit-steps-1-4.md`

## Handoff rotation

When superseding this file, rename `handoffs/YYYY-MM-DD[-suffix].md` and write
fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
