# Strangler Coordinator — Handoff

You're the coordinator. Project chats build in their lanes. Ian is the bus. You hold the contract (`STRANGLER-COORDINATION.md`).

**Read this. That's the orient. The rest is reference, pull when needed.**

> Prior handoff snapshot: `strangler-handoffs/2026-05-28-successor-orient.md`
> (pre-dates this session's big wins — keep for history, don't act on it).

---

## Where we are (2026-05-28 evening)

**Cutover-eligibility is most of the way there.** Six P-items shipped this
session. The remaining work is migration scripts + dormant smoke + lg-shell
modals — no architectural unknowns left.

### ✅ Done
- **P1** `/whoami` live (profile-app) — identity + tier via poller, Redis cache, self-purge, WP-session auth bridge
- **P2** Patreon adapter (poller) — reads LGPO's `payment_source` usermeta, no API calls; round-trip purge verified
- **P3** Shared header partial (lg-shell) — `/srv/lg-shared/`, wired into archive-poc + bb-mirror
- **P6** archive-poc `/whoami`-backed gating
- **P7c** `edit_archive_poc` cap mu-plugin
- **P4** `LG_PROFILE_APP_URL` config (poller)

### ⏳ Remaining for cutover-eligibility
- **P5** BB-mirror mu-plugin live rehearsal (on new box at build time)
- **P7a/b** migration scripts — archive-poc re-backfill + bb-mirror pgloader
- **P8** poller dormant-mode dev smoke
- **P9** lg-shell modals (notification bell + REST first, then friends/follow/messages/photos)
- **P10** group-as-forum (folded into BB-mirror)
- **P11** BP unused-surface kill decisions

---

## The big architectural shift this session: blue-green cutover

Ian decided cutover is **NOT in-place surgery** on live (54.157.13.77).
Instead: **stand up a fresh EC2, build the full stack, backfill with current
production data, swing DNS.** Relaxed pace — build can take days. Old box
stays up through propagation as natural rollback. Cutover chat rewrote
`CUTOVER-PLAN.md` to v0.3 (12-step blue-green sequence).

Killed at launch (Ian's calls): **no CF cache-purge** (natural miss post-swing),
**no user-visible comms** (DNS swing is the only event), **HTTP-01 cert
post-swing** (not DNS-01, since no CF token).

---

## What's owed FROM Ian (his actions, not yours)

| Status | Action |
|---|---|
| ⏳ | Spawn **post-conversion chat** (legacy posts → lg-layout-v2). Gating pointers already drafted (see below). |
| ⏳ | Decide membership-pages conversion (poller-built pages) — new chat or fold into lg-shell? Open question. |
| ⏳ | Provision new EC2 when lanes finish (blue-green build) |
| ⏳ | Confirm stale `dev.loothtool` cron removal landed |

No CF token needed anymore (cut at launch).

---

## Roster — one line each

| Chat | Status | Session |
|---|---|---|
| **coordinator** (this) | active — successor to `7deff0ff` | `c047417b` |
| **profile-app** | ✅ slice 3.5 + auth bridge shipped; ready for slice 4 | `a847d1aa` |
| **BB-mirror** | activity feed + header shipped; idle on `/whoami` group gating | `ed723d17` |
| **poller** | ✅ P2 + Arbiter stripe guard + round-trip; idle on P8 | `0981c23e` |
| **archive-poc** | ✅ step 2 gating live; idle on cutover day | `aec4f10b` |
| **cutover** | CUTOVER-PLAN v0.3 (blue-green) locked; preflight work | `c4e655f8` |
| **lg-shell** | ✅ P3 header shipped; building P9 modals | `1d248347` |

Full detail in `CHATS-MENU.md`.

---

## Live changes made this session (coordinator acted locally with sudo)

- **Front page** — `dev.loothgroup.com/` now 302→`/archive-poc/`. Added
  `location = /` block in `dev.loothgroup.com.conf`. Backup at
  `/tmp/dev.loothgroup.com.conf.bak`.

---

## Open threads / drafted-but-not-spawned

- **Legacy-post → lg-layout-v2 conversion chat.** Gating pointers drafted
  (TierResolver.php is the truth; legacy snippet #44 → v2 `gated_tier` map;
  BATCH-04 has the legacy source). When Ian spawns it, hand him those.
- **Membership-pages conversion** — poller-built pages (`/lgjoin/`, manage-sub,
  gift dashboard). Built from scratch, not BB-themed. Open: new chat vs fold
  into lg-shell. Ian was mid-decision.

---

## How to work

- Ian is the bus. You write replies/briefings to `docs/`; Ian relays paths to
  chats. Memory `feedback_relay_link_format.md` is the format.
- In-lane work doesn't need ratification. Only cross-cutting decisions touch
  the contract.
- Update `STRANGLER-COORDINATION.md` in place when decisions land. Rotate this
  handoff, not the contract.
- Consumed relays go to `relays-archive/YYYY-MM-DD/` (paper trail, not deleted).
- Code-server clipboard is fragile — plain code blocks with absolute paths.

---

## Pointers

- **Contract:** `STRANGLER-COORDINATION.md`
- **Roster + history:** `CHATS-MENU.md`, `CHAT-LINEAGE.md`
- **BB-decommission:** `BB-DECOMMISSION-INVENTORY.md`
- **Cutover plan:** `/home/ubuntu/projects/cutover/CUTOVER-PLAN.md` (v0.3 blue-green)
- **Batch outputs (live recon):** `/home/ubuntu/projects/cutover/batch-output/`
- **Prior handoff snapshots:** `strangler-handoffs/`
- **Successor briefing template:** `briefing-coordinator-successor.md`

---

## Handoff rotation

When superseding, copy to `strangler-handoffs/YYYY-MM-DD-<suffix>.md` and write
fresh. Keep the new one lean. Your successor wants action, not lecture.
