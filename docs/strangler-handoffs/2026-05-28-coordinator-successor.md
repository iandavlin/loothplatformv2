# Strangler Coordination — Session Handoff

You are the coordinator chat for the Looth Group strangler rollout. Read this file + `STRANGLER-COORDINATION.md` + `CHATS-MENU.md` + `MEMORY.md` (auto-loaded) and you're oriented in ~5 min.

This handoff captures **session state** — what's been routed, what's owed back, what's in flight. The **contract / spec** lives in `STRANGLER-COORDINATION.md` (durable, edit in place, don't rotate).

> **Prior coordinator session: `7deff0ff-4cf1-450b-9a5c-1e59ec7d5025`** — context was getting long; handed off cleanly. Snapshot pre-rotation at [strangler-handoffs/2026-05-28-pre-handoff-rotation.md](strangler-handoffs/2026-05-28-pre-handoff-rotation.md). Earlier snapshot at [strangler-handoffs/2026-05-28-pre-bp-mirror-spinup.md](strangler-handoffs/2026-05-28-pre-bp-mirror-spinup.md).

## Your role

You hold the cross-cutting contract (`STRANGLER-COORDINATION.md`) and route decisions between Ian and the project chats. Ian is the human-in-the-loop bus + final-decision-maker. You don't talk to project chats directly; they talk to Ian, Ian talks to you, you draft replies, Ian relays.

## Canonical patterns (loaded via memory)

- **Relay format (you → chat, via Ian):** `feedback_relay_link_format.md`
- **Report-back format (chat → you, via Ian):** `feedback_chat_report_back_format.md`
- **CHATS-MENU.md** is the live roster; **CHAT-LINEAGE.md** is the history of chat replacements
- **Mobile lens** for every decision: §3j of coord doc

## Active chats — see CHATS-MENU.md for current outliner titles + session IDs

| Chat | Status |
|---|---|
| coordinator (this) | active — your session, replaces `7deff0ff` |
| profile-app | building slice 3.5 (`/whoami` + batch users + cache + self-purge). No coordinator asks open. |
| BB-mirror | postgres LIVE on dev; render-bug bundle queued for relay (pending Ian); v2 restyle + threading next session |
| poller | spawned as tracked chat 2026-05-28 10:53 (session `7c518e34`); awaiting BATCH-04 paste-back for Patreon adapter spec |
| archive-poc | fresh session 2026-05-28 11:11 (`aec4f10b`); postgres prep DONE; P3 reassigned away; UX request bundle queued for relay |
| cutover | holding for BATCH-04 paste-back (session ID unconfirmed) |
| lg-shell | not spawned yet — briefing ready at `docs/briefing-lg-shell.md` |

## Architecture settled (in coord doc)

| Decision | Section |
|---|---|
| Tier vocab `public | lite | pro`; looth1→public, looth4→pro+comp | §1 |
| `/whoami` born in profile-app; tier source dual-implementation (Stripe-via-poller dev / Patreon-via-adapter live) | §2 |
| Stub-tier `tier_unavailable: true` transition pattern | §2 |
| Poller endpoint shape + auth + capabilities map | §2 |
| Cache invalidation: WP action `looth_tier_changed`, PurgeNotifier subscribes | §2 |
| BB-mirror scope: forum threads only, write-proxy through BB REST, soak fallback `?bb_native=1` | §3f |
| Anonymizer plugin coordination flagged for forum-privacy (name/location TBD) | §3f |
| Single sitewide mod model (Ian); per-forum mod migration N/A | §3f |
| BB-theme decommission pattern (reskin/replace/drop) | §3d |
| Group primitive collapses into forum-with-decoration; word "group" stays as UX label | §3d |
| Parent forums (Repair/New Builds/etc.) are hierarchy, NOT vestigial | §3d |
| nginx snippet pattern per-strangler under /etc/nginx/snippets/ | §3g |
| LG_PROFILE_APP_URL needed pre-cutover | §3g |
| B-now/A-later cutover: strangler ships now, Stripe dormant by absence | §3h |
| **Storage: one postgres, three schemas; mobile-imminent is the driver** | §3i |
| Cross-schema discipline (schema = API) | §3i |
| Per-strangler DSN provisioning (peer auth, hyphenated role names) | §3i |
| Shared `looth-dev` write-side role (sync + backfill) | §3i |
| **Mobile lens for every decision** | §3j |
| BB decommission inventory + collapse details | BB-DECOMMISSION-INVENTORY.md |
| BP usage audit (dev numbers — verify live) | BB-DECOMMISSION-INVENTORY.md |

## Open Ian decisions

| Item | Priority | Status |
|---|---|---|
| Run BATCH-04 on live | 🔥 | Unblocks Patreon adapter spec → P2 |
| Confirm stale `dev.loothtool` cron removal landed | 🔥 | Hygiene |
| Capture cutover's session ID + outliner title | 🔥 | Manifest accuracy |
| Capture coordinator's own session ID + outliner title | 🔥 | Manifest accuracy |
| CF API token → `/etc/lg-cloudflare-token` 0600 | ⏳ | Cutover step 3, 10, 12 cache purge |
| Point at anonymizer plugin name/location | ⏳ | BB-mirror anon-visibility work |

## Resolved since last handoff (2026-05-28, this session)

- ✅ **Live BP audit done** — full group/forum picture captured in `reply-to-bb-mirror-audit-findings.md`. Orphan-gate rule ratified: subforum whose ancestor group deleted → falls back to no-gate (all-authenticated).
- ✅ **archive-poc relays delivered + acknowledged** — P3 off their plate (lg-shell owns it); UX #1 (bare landing) already done; UX #2+#3 post-cutover. archive-poc is waiting on cutover day.
- ✅ **lg-shell scaffolded** — `/home/ubuntu/projects/lg-shell/` created; lg-shell chat briefed.
- ✅ **BB-mirror audit relay written** — `reply-to-bb-mirror-audit-findings.md`; step 1 authorized.

## Pending relays in `docs/`

Ready to paste using canonical relay format:

**BB-mirror** *(outliner: "Reskin BB Forums and plan mobi…")*
```
/home/ubuntu/projects/docs/reply-to-bb-mirror-render-bugs.md
/home/ubuntu/projects/docs/reply-to-bb-mirror-audit-findings.md
```

**profile-app** *(outliner: "Profile app next session planning")*
```
/home/ubuntu/projects/docs/marking-order-profile-app.md
```

**poller** *(outliner: "Review briefing poller promotion…")*
```
/home/ubuntu/projects/docs/marking-order-poller.md
```

**cutover** *(outliner: confirm exact title from panel)*
```
/home/ubuntu/projects/docs/marking-order-cutover.md
```

## Cutover-eligibility checklist (P1–P11)

- P1 ⏳ `/whoami` ships on dev (profile-app)
- P2 🔒 Patreon adapter (poller, post-BATCH-04)
- P3 ⏳ Shared header partial (lg-shell, reassigned from archive-poc)
- P4 ✅ `LG_PROFILE_APP_URL` constant in poller PurgeNotifier — shipped 2026-05-28
- P5 ⏳ BB-mirror mu-plugin live rehearsal
- P6 ⏳ archive-poc switches to `/whoami`-backed gating
- P7 ⏳ pgloader-or-rebackfill scripts for SQLite→pg migrations
- P8 ⏳ Poller dormant-mode dev smoke
- P9 ⏳ lg-shell modals (notifications, friends, follow, messages, photos)
- P10 ⏳ Group-as-forum-with-decoration (subsumed into BB-mirror)
- P11 ⏳ BP unused-surface kill decisions (post live audit)

When all ✅: cutover-eligible. Cutover-window = maintenance mode + nighttime + Ian-triggered.

## What you do NOT do

- Don't talk to project chats directly (Ian is the bus)
- Don't edit project chats' SESSION-HANDOFF.md (they own theirs)
- Don't make live changes (Claude-free; commands → Ian → live)
- Don't expand scope beyond what's needed for cutover-eligibility
- Don't pre-spawn workstreams (mobile especially) until they're being built

## Handoff rotation

When superseding this file, rename to `strangler-handoffs/YYYY-MM-DD[-suffix].md` and write fresh.

`STRANGLER-COORDINATION.md` is NOT a handoff — edit in place.
