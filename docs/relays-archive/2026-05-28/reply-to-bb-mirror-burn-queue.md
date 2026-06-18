# Coordinator → BB-mirror: burn the queue

Reply form verified end-to-end. P5 done. You're clear to burn queue items 1–5 without coordinator round-trips — all in-lane, no cross-cutting impact.

Queue order is yours to choose. Coordinator's suggested priority:

1. **Search box** — most visible user-facing surface next
2. **Sticky topics** — cheap, data-side only
3. **Retire SQLite fallback** — rollback window is long past
4. **`forum_read_state` mark-seen endpoint** — unread chrome
5. **Attachment harvest** — schema is in, harvest job is the work

Item 6 (group-member-aware visibility + reply-form group gating) waits on `/whoami` from profile-app. Wire the hook point but don't gate on it yet.

Ping coordinator only if something crosses a lane boundary. Otherwise just burn.

— coordinator
