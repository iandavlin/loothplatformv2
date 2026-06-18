# Strangler Coordination — Session Handoff

This is the coordinator's session-state doc. The coordinator chat runs
across multiple workstreams (profile-app, BB-mirror, archive-poc,
poller, cutover, lg-bp-mirror) and routes decisions between them via
Ian.

The contract / spec lives in
[STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md).
That doc is the durable artifact. This handoff captures *session state*
— what's been routed, what's owed back, what's in flight.

> **Prior handoff rotated 2026-05-28** to
> [strangler-handoffs/2026-05-28-pre-bp-mirror-spinup.md](/home/ubuntu/projects/docs/strangler-handoffs/2026-05-28-pre-bp-mirror-spinup.md).
> This fresh snapshot covers the post-BB-decommission-inventory state,
> group-as-forum-with-decoration collapse, BP audit findings, and
> lg-bp-mirror workstream spinning up.

## Active chats

| Chat | Session ID | Last seen | Status |
|---|---|---|---|
| coordinator (this) | `7deff0ff-4cf1-450b-9a5c-1e59ec7d5025` | active | running |
| profile-app | `a847d1aa-8252-4c06-8d90-3e470d3cc265` | 2026-05-27 23:10 | Slice 3.5 building (`/whoami` + batch users + cache + self-purge). No coordinator asks open. |
| BB-mirror | `ed723d17-00e9-4d6c-8ca5-9dafe057f49d` | 2026-05-28 (current) | Postgres migration LIVE on dev. Forum-list + topic-list rendering from postgres. Single-topic + v2 restyle queued. Render-bug bundle relayed (counts wrong, &nbsp;, timestamps, hidden Jannies, vestigial=actually-hierarchy). |
| poller | *(checklist session)* | 2026-05-27 23:50 | SHIPPED user-context/action/PurgeNotifier. Backlog burned. Idle waiting on profile-app purge receiver. Patreon adapter spec gated on BATCH-04 paste-back. |
| archive-poc | `e1421b41-c84f-419d-8b4a-1e424fbdb824` (or fresh) | 2026-05-28 (P3 assigned) | Postgres prep COMPLETE. Now owns P3 (shared header partial). Brief at docs/reply-to-archive-poc-p3-owner.md. |
| cutover | *(first session)* | 2026-05-28 | CUTOVER-PLAN.md v0 drafted with 5 sharpenings folded. Holding for BATCH-04 paste-back. Will then bundle plan revisions + role-writer trace + window recommendation. |
| lg-bp-mirror | *(none yet)* | 2026-05-28 (briefing ready) | New workstream. Brief at docs/briefing-lg-bp-mirror.md. Scaffold at /home/ubuntu/projects/lg-bp-mirror/. **Pending Ian decision: spin as separate chat vs fold into existing chat (see Open Decisions below).** |

## Architecture settled (in coord doc)

| Decision | Section |
|---|---|
| Tier vocab `public | lite | pro`; looth1→public, looth4→pro+comp | §1 |
| `/whoami` born in profile-app; tier source dual-implementation (Stripe-via-poller dev / Patreon-via-adapter live) | §2 |
| Pre-cutover identity from profile-app postgres (slice 2.75 snapshot adequate) | §2 |
| Stub-tier `tier_unavailable: true` transition pattern | §2 |
| Poller endpoint: GET `user-context/{id}`, header `X-LG-Internal-Auth`, secret at `/etc/lg-internal-secret` | §2 |
| Capabilities map: edit_posts, manage_options, edit_archive_poc, moderate_forums | §2 |
| Internal-channel auth: peer auth, secret file, shared `looth-dev` write-side role | §3i |
| Cache invalidation: WP action `looth_tier_changed`, PurgeNotifier subscribes | §2 |
| BB-mirror scope: forum threads only, write-proxy through BB REST, soak fallback `?bb_native=1` | §3f |
| Logged-out anonymizer plugin coordination flagged for forum-privacy | §3f |
| Single sitewide mod model (Ian); per-forum mod migration N/A | §3f |
| Stripe poller out-of-WP roadmap (post-cutover hygiene) | §3e |
| BB-theme inventory + reskin-then-replace pattern | §3d |
| Parent forums (Repair/New Builds/etc.) are functional hierarchy not vestigial | §3d (corrected 2026-05-28) |
| Group-as-forum-with-decoration collapse; word "group" stays as UX label | §3d |
| nginx snippet pattern per-strangler under /etc/nginx/snippets/ | §3g |
| Storage: one postgres server, three schemas; mobile is the primary driver | §3i |
| Cross-schema discipline (schema = API) | §3i |
| Per-strangler DSN provisioning (peer auth, hyphenated role names) | §3i |
| Shared write-side role `looth-dev` (sync + backfill via this role) | §3i |
| LG_PROFILE_APP_URL needed pre-cutover (currently hardcodes dev) | §3g |
| B-now/A-later cutover: strangler ships now, Stripe dormant by absence | §3h |
| BB decommission inventory + collapse details | BB-DECOMMISSION-INVENTORY.md |
| BP usage audit (dev, pending live verify): notifications + friends + follow real; messages dead; albums/docs dead | BB-DECOMMISSION-INVENTORY.md |

## In flight

- **profile-app** building slice 3.5 (`/whoami`). No coordinator asks open. Pings when shape clean on dev.
- **BB-mirror** has render-bug bundle (counts wrong, &nbsp;, timestamps, hidden forums, vestigial-was-actually-hierarchy correction) to fold into next session. Postgres migration done.
- **poller** idle in lane. Will round-trip verify against profile-app's purge receiver when it lands. Patreon adapter spec needs BATCH-04 output first.
- **archive-poc** has P3 (shared header partial) ownership. Postgres prep done. Sketching shared-header mockup is next.
- **cutover** holding for BATCH-04 paste-back from Ian. Then bundled ping with plan revisions + role-writer trace + window recommendation.
- **lg-bp-mirror** scaffolded + briefed. Pending Ian decision on chat structure.

## Outstanding Ian actions

| Item | Status |
|---|---|
| **Run BATCH-04 on live** — read-only, unblocks Patreon adapter spec → step 7 of cutover | Top priority |
| **Run live BP audit** (bash in this turn's earlier message) to verify dev numbers | Quick |
| **Verify + remove stale dev.loothtool cron** on live — bash drafted, awaiting paste-back | Done? need confirm |
| **Decide modal chat structure** (separate lg-bp-mirror vs fold into existing) | Pending |
| **CF API token deployment** `/etc/lg-cloudflare-token` mode 0600 | Whenever |
| **Relay outstanding briefings**: lg-bp-mirror, archive-poc P3, archive-poc prep-complete, BB-mirror render-bugs | Several pending |

## Decisions Ratified (folded since prior handoff rotation)

- Cutover window: **maintenance mode + nighttime + Ian-triggered** when P1-P11 ✅
- Cloudflare cache strategy: **zone-wide purge** (simple, lower-stakes than I framed since logged-in pages aren't cached)
- P3 owner: **archive-poc** (shared header partial)
- Memory updates approved: Rank Math active corrected; dev.loothtool stale-cron addendum
- BB-decommission scope: zero BB theme at cutover (modal-ize messages/notifications/photos/etc, reskin auth, kill unused)
- Group primitive collapses into forum-with-decoration; word "group" stays as UX label
- Parent forums (Repair, New Builds, Tools, Business, Market Place) are hierarchy, NOT vestigial — keep them, subforums hold content

## Open decisions

- **Modal chat structure** (this turn's question — pending)
- Patreon adapter packaging (poller chat decides single-plugin vs separate, post-BATCH-04)
- Live BP audit results may change lg-bp-mirror priorities further
- Anonymizer plugin location/name (Ian to point at when BB-mirror gets to anon-visibility work)

## Cutover-eligibility checklist

P1-P11 from CUTOVER-PLAN.md + BB-DECOMMISSION-INVENTORY (consolidated):

- P1 ⏳ `/whoami` ships on dev (profile-app)
- P2 🔒 Patreon adapter (poller, post-BATCH-04)
- P3 ⏳ Shared header partial (archive-poc)
- P4 ⏳ `LG_PROFILE_APP_URL` constant in poller PurgeNotifier
- P5 ⏳ BB-mirror mu-plugin smoke + backfill rehearsed (done on dev; live rehearsal queued)
- P6 ⏳ archive-poc switches to `/whoami`-backed gating
- P7 ⏳ pgloader-or-rebackfill scripts for SQLite→pg migrations
- P8 ⏳ Poller dormant-mode dev smoke
- P9 ⏳ lg-bp-mirror modals (notifications, friends/follow, messages, photos, auth)
- P10 ⏳ Group landing/directory composition (likely subsumed into BB-mirror's group-as-forum work)
- P11 ⏳ BP unused-surface kill decisions (post live audit)

## Handoff rotation

When superseding this file, rename to `strangler-handoffs/YYYY-MM-DD[-suffix].md` and write fresh.

The `STRANGLER-COORDINATION.md` spec is NOT a handoff — it's the durable contract. Don't rotate; edit in place.
