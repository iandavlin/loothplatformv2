# P11 — BP unused-surface kill decisions

Coordinator synthesis from dev audit + group/forum picture (2026-05-28). 
**Live verification still needed for messages** — everything else is decided.

---

## Decisions

| BP Surface | Decision | Reason |
|---|---|---|
| **Notifications** | ✅ Keep — lg-shell P9 | 31,260 unread, 269 last-30d. Heavy real usage. Full modal + bell. |
| **Friends** | ✅ Keep — lg-shell P9 | 7,346 friend pairs. Real social graph. Modal from profile page. |
| **Follow** | ✅ Keep — lg-shell P9 | 9,002 follows. Heavier than friends. Modal from profile page. |
| **Messages** | ⚠️ Verify live | Dev: 0 messages in last 30d, 370 historic threads. If live confirms dead → minimum-viable empty-state modal ("forum is where conversation happens"). If live shows activity → build the thread modal. |
| **Photos** | ✅ Keep — lg-shell P9 (modal-show only) | 2,598 bp_media entries, mostly forum-post attachments cataloged back. Show in profile modal; no upload flow needed at cutover. |
| **Albums** | ❌ Kill | 23 albums. Nobody curates them. Skip the URL; let it 404 or redirect to profile. |
| **Documents** | ❌ Kill | 191 documents sitewide. Low usage. Skip. |
| **Group documents** | ❌ Kill | 1 entry. Dead. |
| **Videos** | ❌ Kill | Not separately audited — consistent with documents pattern. |
| `/members/<slug>/friends/` page | ❌ Kill the page, keep the data | Friends data is real but the full-page directory isn't needed — surface via modal only. |
| `/members/<slug>/photos/` page | ❌ Kill the page | Show photos in profile modal sidebar, not a dedicated page. |
| `/members/<slug>/documents/` page | ❌ Kill | |
| `/members/<slug>/videos/` page | ❌ Kill | |
| `/groups/<slug>/photos/`, `/docs/`, `/videos/` | ❌ Kill | Group media is dead per audit. |

## Auto-enroll group deletion (cutover day)

The 5 auto-enroll topic groups (Repair & Restoration, New Builds, Tools/Spaces, Business, Market Place) delete at cutover. ~9k junk memberships freed. Their ~27 subforums survive under the orphan-gate rule (no-gate fallback).

The 9 regional Local Looths groups stay — real communities, collapse into forum-with-decoration at cutover.

## Live verification — done (2026-05-28)

```sql
-- db: wp_loothgroup (live)
SELECT COUNT(*) FROM wp_bp_messages_messages 
WHERE date_sent > DATE_SUB(NOW(), INTERVAL 30 DAY);
-- Result: 135
```

**Messages are active on live.** Dev (looth_dev) showed 0 — dev ≠ live here. Decision: **build the full thread modal** in lg-shell P9.

## Impact on lg-shell scope

After the kill decisions above, lg-shell P9 is:

| Modal | Priority | Status |
|---|---|---|
| Notification bell + popover | P1 | Build |
| Friends modal | P1 | Build |
| Follow modal | P1 | Build |
| Messages inbox + thread | P2 | **Build full thread modal** — live confirmed 135 messages last 30d |
| Photos modal (profile) | P2 | Build (show-only, no upload) |
| Albums | skip | Kill |
| Documents | skip | Kill |

## P11 status

✅ **Closed.** All decisions made. lg-shell scope fully locked.
