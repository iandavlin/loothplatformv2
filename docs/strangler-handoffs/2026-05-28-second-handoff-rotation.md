# Strangler Coordination — Session Handoff

You are the coordinator chat for the Looth Group strangler rollout. Read this file + `STRANGLER-COORDINATION.md` + `CHATS-MENU.md` + `MEMORY.md` (auto-loaded) and you're oriented in ~5 min.

This handoff captures **session state** — what's been routed, what's owed back, what's in flight. The **contract / spec** lives in `STRANGLER-COORDINATION.md` (durable, edit in place, don't rotate).

> **Prior coordinator session: `c047417b-6581-4b1a-b2ae-62496b785bca`** — clean handoff 2026-05-28. Snapshot at [strangler-handoffs/2026-05-28-coordinator-successor.md](strangler-handoffs/2026-05-28-coordinator-successor.md).

## Your role

You hold the cross-cutting contract (`STRANGLER-COORDINATION.md`) and route decisions between Ian and the project chats. Ian is the human-in-the-loop bus + final-decision-maker. You don't talk to project chats directly; they talk to Ian, Ian talks to you, you draft replies, Ian relays.

## Canonical patterns (loaded via memory)

- **Relay format (you → chat, via Ian):** `feedback_relay_link_format.md`
- **Report-back format (chat → you, via Ian):** `feedback_chat_report_back_format.md`
- **CHATS-MENU.md** is the live roster; **CHAT-LINEAGE.md** is the history of chat replacements
- **Mobile lens** for every decision: §3j of coord doc

## Active chats — see CHATS-MENU.md for outliner titles + session IDs

| Chat | Status |
|---|---|
| coordinator (this) | active — successor to `c047417b`, handoff 2026-05-28 |
| profile-app (coordination) | idle — coordination chat, `a847d1aa`. Build chat needs spawning (see below) |
| profile-app (build) | **needs fresh session** — open new session, paste build order + SESSION-HANDOFF.md |
| BB-mirror | burning queue: search box → read-state → attachments → stickies → SQLite retire. Session `ed723d17`. |
| poller | idle, waiting on BATCH-04. P4 ✅ shipped. Session `0981c23e`. |
| archive-poc | idle, waiting on cutover day. Session `aec4f10b`. |
| cutover | waiting on BATCH-04 + BATCH-05. Session `c4e655f8`. |
| lg-shell | building P3 header partial + P9 modals. Session `1d248347`. |

## What landed this session (2026-05-28)

| Item | Result |
|---|---|
| Live BP / group+forum audit | Done — 20 groups, 55 forums, orphan-gate rule ratified |
| BB-mirror render bugs | All 6 fixed — verified via curl |
| BB-mirror group table + `forum.group_id` + `effective_group_id` | Done — 32 group pills live |
| P4 `LG_PROFILE_APP_URL` | ✅ Shipped by poller |
| P5 mu-plugin live rehearsal + reconcile cron | ✅ Shipped by BB-mirror |
| P11 BP kill decisions | ✅ Closed — messages alive on live (135/30d), build full modal |
| BB-mirror reply form JS + threading | ✅ Shipped — write→sync→render loop verified |
| Header name ratified | `X-LG-Internal-Auth` confirmed, profile-app to mirror |
| Live WP database name | `wp_loothgroup` (not `wordpress`) |
| Session IDs | All confirmed from filesystem except coordinator successor (pending) |

## Open Ian decisions / actions

| Item | Priority | Status |
|---|---|---|
| Run BATCH-04 on live | 🔥 | Unblocks Patreon adapter (P2) + cutover chat |
| Run BATCH-05 on live | 🔥 | Locks cutover window timing |
| Spawn profile-app BUILD session | 🔥 | Paste `reply-to-profile-app-build-now.md` + `profile-app/SESSION-HANDOFF.md` into a new session |
| Confirm stale `dev.loothtool` cron removal | ⏳ | Hygiene |
| CF API token → `/etc/lg-cloudflare-token` 0600 | ⏳ | Cutover steps 3, 10, 12 cache purge |
| Point at anonymizer plugin name/location | ⏳ | BB-mirror anon-visibility logic |

## Pending relays in `docs/`

None currently queued. All chats have their orders.

If a chat reports back, check their SESSION-HANDOFF.md and respond with next work order. Relay format is in memory.

## Cutover-eligibility checklist (P1–P11)

- P1 ⏳ `/whoami` ships on dev (profile-app build session — not started yet)
- P2 🔒 Patreon adapter (poller, blocked on BATCH-04)
- P3 ⏳ Shared header partial (lg-shell)
- P4 ✅ `LG_PROFILE_APP_URL` in poller PurgeNotifier
- P5 ✅ BB-mirror mu-plugin live rehearsal + reconcile cron
- P6 ⏳ archive-poc switches to `/whoami`-backed gating
- P7 ⏳ pgloader/rebackfill scripts for SQLite→pg migrations
- P8 ⏳ Poller dormant-mode dev smoke
- P9 ⏳ lg-shell modals (notifications, friends, follow, messages, photos)
- P10 ⏳ Group-as-forum-with-decoration (BB-mirror, post-/whoami)
- P11 ✅ BP unused-surface kill decisions

When all ✅: cutover-eligible. Cutover-window = maintenance mode + nighttime + Ian-triggered.

## Key facts learned this session

- **Live WP DB is `wp_loothgroup`** (not `wordpress` or `looth_dev`)
- **BB-mirror schema uses singular table names**: `forums.topic`, `forums.reply`, `forums.bp_group` (not plural)
- **profile-app secret file access**: use `setfacl -m u:profile-app:r /etc/lg-internal-secret` (more surgical than www-data group)
- **Messages are alive on live**: 135 sent in last 30d — build the full thread modal in lg-shell P9
- **poller session `0981c23e`** opened with `briefing-stripe-poller.md` (new; prior was `7c518e34`)
- **Orphan-gate rule**: subforums whose ancestor group is deleted at cutover fall back to no-gate (all-authenticated)

## What you do NOT do

- Don't talk to project chats directly (Ian is the bus)
- Don't edit project chats' SESSION-HANDOFF.md (they own theirs)
- Don't make live changes (Claude-free; commands → Ian → live)
- Don't expand scope beyond what's needed for cutover-eligibility
- Don't pre-spawn workstreams (mobile especially) until they're being built

## Handoff rotation

When superseding this file, rename to `strangler-handoffs/YYYY-MM-DD[-suffix].md` and write fresh.

`STRANGLER-COORDINATION.md` is NOT a handoff — edit in place.
