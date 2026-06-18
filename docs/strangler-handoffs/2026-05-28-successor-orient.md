# Strangler Coordinator — Handoff

You're the coordinator. Project chats build in their lanes. Ian is the bus. You hold the contract (`STRANGLER-COORDINATION.md`).

**Read this. That's the orient. The rest is reference, pull when needed.**

---

## What to do first (most likely scenarios)

**Scenario A — Ian engages with a result.** Most likely: BATCH-04 paste-back from live, or a chat reply. Route per memory's relay format. Update the relevant chat's row in `CHATS-MENU.md` if status moved.

**Scenario B — Ian engages with a new ask.** Ratify if cross-cutting, decline if in-lane (chats decide their own lanes).

**Scenario C — Ian is quiet.** You're idle. Don't manufacture work. Skim what's in flight, sit on your hands.

There is **no relay currently queued**. All chats have orders. Nothing to push until something comes back.

---

## What's owed FROM Ian (these are his actions, not yours)

| 🔥 | Action |
|---|---|
| 🔥 | Run BATCH-04 on live (read-only; unblocks poller P2 + cutover) |
| 🔥 | Run BATCH-05 on live (locks cutover window timing) |
| 🔥 | Spawn profile-app **build** session — separate from the coordination chat. Paste `/home/ubuntu/projects/docs/reply-to-profile-app-build-now.md` + `/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md`. P1 is blocked until this happens. |
| ⏳ | Confirm stale `dev.loothtool` cron removal landed |
| ⏳ | CF API token → `/etc/lg-cloudflare-token` 0600 |
| ⏳ | Anonymizer plugin name/location |

---

## Roster — one line each

| Chat | Status | Session |
|---|---|---|
| **profile-app** (coordination) | idle, no asks | `a847d1aa` |
| **profile-app** (build) | NOT SPAWNED — Ian to start, blocks P1 | — |
| **BB-mirror** | burning queue (search box → read-state → attachments → stickies → SQLite retire) | `ed723d17` |
| **poller** | idle, waiting on BATCH-04 | `0981c23e` |
| **archive-poc** | idle, waiting on cutover day | `aec4f10b` |
| **cutover** | waiting on BATCH-04 + BATCH-05 | `c4e655f8` |
| **lg-shell** | building P3 header + P9 modals | `1d248347` |

Full outliner titles + last-status in `CHATS-MENU.md`. Capture your own session ID + outliner title at spawn and update row #1.

---

## Cutover-eligibility (P1–P11)

✅ P4 (`LG_PROFILE_APP_URL`) · ✅ P5 (mu-plugin live rehearsal + cron) · ✅ P11 (BP kill decisions)
⏳ P1 (`/whoami`, blocked on build spawn) · ⏳ P3 (lg-shell header) · ⏳ P6 (archive-poc /whoami gating) · ⏳ P7 (migration scripts) · ⏳ P8 (poller dormant smoke) · ⏳ P9 (lg-shell modals) · ⏳ P10 (group-as-forum)
🔒 P2 (Patreon adapter, blocked on BATCH-04)

When all ✅: cutover-eligible. Window = maintenance mode + nighttime + Ian-triggered.

---

## Facts you'd otherwise re-derive

- Live WP DB is `wp_loothgroup` (not `wordpress`)
- BB-mirror schema uses singular table names: `forums.topic`, `forums.reply`, `forums.bp_group`
- profile-app secret file access: `setfacl -m u:profile-app:r /etc/lg-internal-secret`
- Messages are alive on live (135 in last 30d) → build full thread modal in lg-shell P9, not empty-state
- Orphan-gate rule: subforums whose ancestor group is deleted at cutover fall back to no-gate (all-authenticated)
- Header for poller↔profile-app: `X-LG-Internal-Auth` (ratified)
- poller session `0981c23e` opened with `briefing-stripe-poller.md` (prior was `7c518e34`)

---

## How to work

- Ian is the bus. You write replies/briefings to `docs/`; Ian relays paths to chats. Memory `feedback_relay_link_format.md` is the format.
- In-lane work doesn't need ratification. Only cross-cutting decisions touch the contract.
- Update `STRANGLER-COORDINATION.md` in place when decisions land. Rotate this handoff, not the contract.
- Code-server clipboard is fragile. Use plain code blocks (click-to-copy) with absolute paths, no markdown links.
- If you don't know about a Claude Code feature, delegate to `claude-code-guide` rather than guess.

---

## Pointers (pull when needed)

- **Contract:** `STRANGLER-COORDINATION.md`
- **Roster + history:** `CHATS-MENU.md`, `CHAT-LINEAGE.md`
- **BB-decommission picture:** `BB-DECOMMISSION-INVENTORY.md`
- **Cutover plan:** `/home/ubuntu/projects/cutover/CUTOVER-PLAN.md`
- **Prior handoff snapshots:** `strangler-handoffs/`
- **Successor briefing template:** `docs/briefing-coordinator-successor.md`

---

## Handoff rotation

When superseding, copy to `strangler-handoffs/YYYY-MM-DD-<suffix>.md` and write fresh. Keep the new one lean. Your successor wants action, not lecture.
