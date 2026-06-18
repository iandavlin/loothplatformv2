# Briefing — lg-shell (new workstream)

You're a new workstream in the Looth Group strangler rollout. **You own the shared visual shell** — header partial + modals + auth reskin + canonical design tokens — that wraps every strangler surface at cutover. Peer to profile-app / archive-poc / BB-mirror / poller / cutover. The coordination chat is your routing partner; Ian is the human-in-the-loop bus.

This scope absorbs what was previously briefed as two separate items: P3 (shared header partial, originally assigned to archive-poc) + lg-bp-mirror (modals + REST + auth reskin). One chat owns both because they're the same thing — the unified shell that every strangler page sits inside.

**Coordination peers:** see [CHATS-MENU.md](/home/ubuntu/projects/docs/CHATS-MENU.md) for the current roster + status. Lineage at [CHAT-LINEAGE.md](/home/ubuntu/projects/docs/CHAT-LINEAGE.md). Don't edit either — coordinator owns. Re-read on session resume.

**Capture your session ID at spawn** and report back to coordinator so the menu stays current.

## Read first (in order)

1. [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md) — the target architecture. §1 (tier vocab), §2 (`/whoami` — you'll be a consumer), §3d (BB inventory), §3f (BB-mirror scope — mirror their pattern), §3g (nginx snippet), §3h (Stripe dormant), §3i (storage — your data lives in postgres + uses shared sync-writer pattern), §4 (cutover sequence).
2. [/home/ubuntu/projects/docs/BB-DECOMMISSION-INVENTORY.md](/home/ubuntu/projects/docs/BB-DECOMMISSION-INVENTORY.md) — the full picture of what BB renders today and what each piece needs. You own most of the modal candidates + the BP-features-as-modals row.
3. [/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md](/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md) — the pattern you're inheriting. Their architecture (read mirror in postgres, write proxy through BB REST, `_sync.php` loopback on `looth-dev` FPM pool) is your template.

## Critical constraint — live is Claude-free

You cannot SSH to live, run commands on live, or push files to live. Same rules as every other strangler:
- Build on dev first
- Coordinator + Ian handle live deploys
- Write commands flagged explicitly with `Rollback:` + `Risk:`

Live host: `54.157.13.77`. Dev (this box): `50.19.198.38`. Sanity check: `curl -s ifconfig.me`.

## Scope

Build the layer that lets `/messages/`, `/notifications/`, `/members/<slug>/{photos,documents,videos,friends}`, and (small bonus scope) `/wp-login.php` render in our own chrome — not BB's. Ian's framing: "modals calling the originals" — BB plugin keeps producing the data, we present it in our shell.

### What you own — priorities reflect dev BP audit (verify live)

Priority by audit signal (dev numbers; verify on live first):

| Surface | Audit signal | Priority |
|---|---|---|
| Notification bell + popover (shared header) | 31k unread / 269 last-30d active | **P1 — real heavy use** |
| Friends modal (per-profile, list confirmed friendships) | 7346 confirmed friendships | **P1 — real social primitive** |
| Follow modal (per-profile, list follows/followers) | 9002 follow relationships | **P1 — real social primitive** |
| Inbox modal (`/messages/` replacement) | 1881 total / 0 last-30d on dev | **P2 — verify live before scoping** — if also dead on live, build minimum-viable empty-state |
| Thread modal (`/messages/<id>/`) | 370 distinct threads | P2 with inbox |
| Compose/reply form | — | P2 with inbox |
| Photo modal (per-profile) | 2598 photos but mostly forum-attachment cataloged | **P3 — minimal modal**, show or empty-state |
| Auth reskin (`/wp-login.php`) | Login + password reset — small but high-stakes | **P2** |
| `/register/` | Patreon onboarding handles signup; this is unused | **P3 — redirect to `/lgjoin/`** |
| Documents modal | 191 total, 1 group doc — effectively dead | **Skip or kill the URL** |
| Photo albums | 23 total — dead | **Skip** |
| Videos | Plugin probably not installed | **Verify, likely skip** |
| Per-forum moderation | Ian moderates everything sitewide — single-mod model confirmed | **N/A — don't build** |

### What you do NOT own

- Profile page itself (profile-app)
- Forum render (BB-mirror)
- Activity feed (archive-poc)
- Tier truth / `/whoami` source (poller / Patreon adapter)
- BB plugin itself — keeps running, keeps owning writes, you're a read mirror + write proxy

## Architecture

**Mirror BB-mirror's pattern:**

- Own FPM pool, own nginx location, own postgres schema (`bp_mirror` — or `messages` if you prefer domain-named — your call)
- Reads from `wp_bp_notifications`, `wp_bp_messages_threads`, `wp_bp_messages_messages`, `wp_bp_messages_recipients`, BP attachments, BP friends tables
- Optionally cache reads in your own postgres tables (sync via mu-plugin hooks on `bp_notifications_*`, `messages_message_sent`, etc.) — only if BP REST is too slow
- Writes proxy through BB REST (`/wp-json/buddyboss/v1/messages`, etc.) so notification + email side-effects keep working
- `_sync.php` on `looth-dev` FPM pool if you go the cached-mirror route (matches BB-mirror)

**Storage decision is yours:**

You can run with two patterns:
1. **Pure proxy** — your REST endpoints just call BB REST, no postgres needed. Simpler. Latency higher per-request because each call hits PHP + BB plugin.
2. **Cached mirror** — sync to your own postgres tables, serve from them, write-through to BB REST. Mirrors BB-mirror's pattern. More complex but faster reads + mobile-friendly.

My (coordinator) lean: start with pure proxy. Add caching only if profiler shows BP REST is too slow under mobile concurrency. You're a smaller surface than BB-mirror; less need to mirror.

### Where the JS modals live

The notification bell + message icon + their popovers ship as part of the **shared header partial (P3, owned by archive-poc)**. Coordinate with archive-poc on:
- Where the JS lives (in the partial's directory or your own)
- The REST endpoint contract (what URLs + response shape they call)
- Visual integration (badge counts, popover positioning, design tokens)

Other modals (inbox, thread, BP features) ship as standalone JS that any page can include.

## Coordination expectations

- Tier vocabulary, `/whoami` contract, internal auth, capabilities map → unchanged from coord doc
- You're a **consumer** of `/whoami` for current viewer identity + tier; not a provider
- Your REST endpoints follow the same auth model as profile-app's `/profile-api/v0/*` — JWT or cookie, both supported
- Cross-schema discipline (§3i): if you go the cached-mirror route and want author data for a message, you READ from `profile_app.users` — profile-app doesn't reach into your schema
- Nginx snippet at `/etc/nginx/snippets/strangler-lg-bp-mirror.conf` per §3g pattern

## Suggested first moves

1. **Read all three docs above.** Push back if anything doesn't fit reality. The BP audit findings + priority table above are based on dev counts — verify on live before locking scope.
2. **Decide pure-proxy vs cached-mirror** for v1. Document the choice + reasoning in your SESSION-HANDOFF.
3. **Run the live BP audit** (coordinator will get the bash queued through Ian) before committing modal scopes. If messages are also dead on live, the inbox can ship as empty-state-first.
4. **Build P1 items first** — notification bell + REST, friends modal, follow modal. These have proven real usage.
5. **Then P2** — messages (minimal if live-dead, full if live-active), auth reskin.
6. **Then P3** — photos modal (mostly empty-state), `/register/` redirect.
7. **Skip** documents, albums, videos, per-forum mod — audit + coord §3f says no usage / single-mod model.

## Reporting back — canonical format

Use the symmetric report-back format every time you have something for coordinator. Lead with chat name, single code block with absolute paths:

```
**lg-shell → coordinator:** <one-line subject>

/home/ubuntu/projects/lg-shell/SESSION-HANDOFF.md
/home/ubuntu/projects/lg-shell/<any-specific-file>.md
```

Files do the substance; the message is a pointer to them. Optional 1-3 line inline summary if it saves coordinator a read.

**At spawn:** report your session ID + outliner title once so coordinator can update `CHATS-MENU.md`. If you can't see your own session ID, say "session ID unknown, Ian please capture."

Full canon: see `~/.claude/projects/-home-ubuntu-projects/memory/feedback_chat_report_back_format.md` (auto-loaded into your context via MEMORY.md).

Update `/home/ubuntu/projects/lg-shell/SESSION-HANDOFF.md` as you go. Ping coordinator when:
- A finding might affect another chat
- You hit a blocker outside your lane
- A scope question surfaces
- You're ready for the coordinator-review ping bundle

Burn lane work without round-trip. Only ping on cross-cutting impact.

## Setup tasks I (coordinator) need to do for you

1. Scaffold `/home/ubuntu/projects/lg-bp-mirror/` directory + `SESSION-HANDOFF.md` stub + `handoffs/` dir
2. Run the BP usage audit on dev so you have data on whether BP photos/docs/videos/friends are even used (informs scope of those modals)
3. Add `lg-bp-mirror` to coord doc §3 with its scope (deferred — landing as a real lane)

These happen in parallel with you orienting.

— coordinator
