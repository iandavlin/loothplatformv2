# Strangler Coordination — Session Handoff

This is the coordinator's session-state doc. The coordinator chat runs
across multiple workstreams (profile-app, stripe-poller, BB-mirror,
archive-poc, lg-layout-v2) and routes decisions between them via Ian.

The contract / spec lives in
[STRANGLER-COORDINATION.md](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md).
That doc is the durable artifact. This handoff captures *session state*
— what's been routed, what's owed back, what's in flight.

## Active chats (sessions located 2026-05-27)

| Chat | Session ID | Last seen | Status |
|---|---|---|---|
| coordinator (this) | `7deff0ff-4cf1-450b-9a5c-1e59ec7d5025` | active | running |
| profile-app | `a847d1aa-8252-4c06-8d90-3e470d3cc265` | 2026-05-27 23:10 | slice 3.5 building, no asks; pings on `/whoami` shape clean |
| BB-mirror | `ed723d17-00e9-4d6c-8ca5-9dafe057f49d` | 2026-05-28 (current) | Postgres migration LIVE on dev. 10-step plan executed. DB=`looth`, schema=`forums`. 55F/1128T/4405R backfilled, 1592 threaded (36%). E2E sync proven. Rollback flag still active. Forum-list + topic-list rendering from pg. Single-topic next session. Surfaced shared `looth-dev` sync-writer role pattern → §3i. |
| stripe-poller | *(checklist session)* | 2026-05-27 23:50 | SHIPPED user-context/action/PurgeNotifier. Backlog burned: affiliate (CDP shadowing artifact, not a bug), cancel-immediate (code-review verified), gift-qty/MG selectors. Idle waiting on profile-app purge receiver. |
| cutover | *(first session)* | 2026-05-28 (re-briefed) | Through BATCH-04 queued. Path A/B/C collapsed → B-now/A-later. Storage architecture update sent (one pg / 3 schemas). |
| archive-poc | `e1421b41-c84f-419d-8b4a-1e424fbdb824` (or fresh) | 2026-05-28 (prep complete + P3 assigned) | Postgres prep complete on dev. **Now owns P3 (shared header partial)** per Ian 2026-05-28. Brief at docs/reply-to-archive-poc-p3-owner.md. |

## Decisions ratified (folded into coordination doc)

- Tier vocabulary: `public | lite | pro` for gating; looth1→public,
  looth4→pro+comp. Two-axis (authenticated + tier). §1
- `/whoami` born in profile-app, served at `/profile-api/v0/whoami`. §2
- Response shape: `user_uuid` canonical, `wp_user_id` legacy bridge,
  `avatar_url` included to avoid round-trip. §2
- Companion batch endpoint `/profile-api/v0/users?uuids=<csv>` ships
  alongside `/whoami` in same slice. §2
- Pre-cutover identity from profile-app's Postgres (slice 2.75 snapshot
  is adequate). §2
- Stub-tier transition state — `tier_unavailable: true` flag when
  profile-app ships before poller endpoint. §2
- Poller exposes `GET /wp-json/looth-internal/v1/user-context/{id}`
  returning `{ tier, provenance, capabilities }`. GET not POST. §2
- Capabilities map: edit_posts, manage_options, edit_archive_poc,
  moderate_forums. §2
- Internal-channel auth: secret at `/etc/lg-internal-secret`, constant
  `LG_INTERNAL_SECRET`, header `X-LG-Internal-Auth` (matches archive-poc
  prefix). Same key both directions. §2
- Cache invalidation centralized via WP action `looth_tier_changed`
  fired by all role writers (Arbiter, UserProvisioner signup,
  admin edits, refund/cancel). Purge handler subscribes; transport
  fire-and-forget. §2
- BB-mirror scope: forum threads only, reskin rest, separate SQLite,
  writes round-trip BB REST. §3f
- BB-mirror routing fallback: `?bb_native=1` for first week. §3f
- Stripe poller off-WP as post-cutover roadmap (not blocking). §3e
- BB-theme inventory + reskin-then-replace pattern. §3d
- BB-mirror scope: forum threads only. §3f
- Stripe poller out of WP roadmap. §3e
- nginx snippet pattern: per-strangler files under `/etc/nginx/snippets/`,
  source-of-truth in project dirs. §3g
- LG_PROFILE_APP_URL needed pre-cutover (poller's purge POST currently
  hardcodes dev host). §3g
- B-now/A-later: strangler ships to live with Patreon adapter feeding
  /whoami; Stripe poller ships dormant (no creds = no behavior). §3h
- Storage: one postgres server, three schemas (`profile_app`, `forums`,
  `discovery`). BB-mirror + archive-poc migrate from SQLite at cutover.
  Primary driver: mobile imminent. §3i
- Cross-schema discipline: consumers read from owners' schemas/endpoints;
  owners don't reach across. Schema = API. §3i (surfaced by profile-app 2026-05-28)
- Cutover sequence: profile-app cutover is the unifying event. §4

## In flight

- **profile-app** — slice 3 (practices + business_name + identity
  mirror) shipped on dev. Handoff rotated 2026-05-27 22:55. Slice 3.5
  scope settled: `/whoami` + `/users?uuids=` + Redis-or-pg cache +
  self-purge + drop `users.tier`. Build order laid out in their
  SESSION-HANDOFF.md §next-session-opening-move. Will ping coordinator
  when `/whoami` returns clean shape on dev. Note: their handoff line
  222 references the pre-refactor `tier/{id}` endpoint shape — actual
  shape is `user-context/{id}` with capabilities map per coord doc §2.
  They'll catch on next briefing read.
- **stripe-poller** absorbed briefing v2 and returned positions
  (addendum on docs/SESSION-HANDOFF.md). All four positions accepted
  by coordinator. Two upgrades folded into doc: X-LG- header rename
  and `looth_tier_changed` centralizing action. Plus one design
  decision ratified 2026-05-27 23:04: provenance derivation uses
  option (b) — refactor `RoleSourceWriter::readAllForUser` to return
  `[source_type => tier]` for deterministic provenance. Scope now
  ~3.5h. Green-lit to build.
- **BB-mirror** scaffolded artifacts (schema, mu-plugin, nginx snippet,
  template chrome, 3 forum sketches) + 7-step deploy plan. Handoff
  rotated (scaffold-stub → handoffs/). Coordinator answered their 4
  open questions; green-lit to proceed steps 1-6, step 7 (live mu-plugin
  deploy) gated on Ian confirm. First live read still held on
  profile-app cutover per §4.

## Open / pending

- Decide whether to drop the 5 vestigial auto-enroll BB groups at
  cutover or earlier (cleanup, not blocking).
- Shared header partial — who builds it, where does it live? Probably
  lg-layout-v2 since that's the most-current template engine. Not
  assigned yet.
- Redis vs Postgres unlogged table for profile-app's `/whoami` cache.
  profile-app picks based on what's already running on the box.

## Handoff rotation

When superseding this file, rename it
`strangler-handoffs/YYYY-MM-DD[-suffix].md` and write fresh per the
project schema in [/home/ubuntu/projects/CLAUDE.md](/home/ubuntu/projects/CLAUDE.md).

The `STRANGLER-COORDINATION.md` spec is NOT a handoff — it's the
durable contract. Don't rotate it; edit it in place.
