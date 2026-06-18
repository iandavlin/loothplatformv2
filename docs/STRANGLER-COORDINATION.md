# Strangler Coordination

Shared contract for surfaces that live outside the WordPress monolith but
need to know **who the viewer is** and **what they're entitled to**.

Consumers today (or imminently):

- **lg-patreon-stripe-poller** — sole writer of paid-tier roles (Arbiter)
- **lg-layout-v2** — runs inside WP; reads tier in-process
- **archive-poc** — separate PHP service at `/archive-poc/` (postgres `discovery` schema, see §3i)
- **profile-app** — separate Postgres service, JWT-authed
- **BB-forum strangler** — early planning, no shape yet

> **Status:** v0 draft, 2026-05-27. Open questions called out inline.
> Push back on anything that doesn't fit what you're building.

---

## ⭐ GOVERNING INVARIANT — dev-complete + fully tested BEFORE the cut (Ian, 2026-05-30)

**Everything we build works on DEV, completely tested, before the cut. Cut day is
LIVE day — a FLIP, not a build. No half-baked features ship at launch.**

- A feature is **cut-eligible only when it is dev-complete AND dev-proven** (tested
  on dev, not just written). Generalizes the auth invariant ("only dev-proven auth
  on cut day") to **every** feature.
- **Cut day does zero building** — it flips dev-finished, soaked work to live and
  migrates data once into the final shape. Anything not done + tested by then is
  **cut from launch scope, not shipped half-baked.**
- ∴ anything **cut-day-required** (profile spine, social layer, …) must be built +
  tested on dev **with runway to spare** — which is exactly why those lanes spawn
  **early, not late.** "Cut-day-required" = *finished before* cut day, never *built
  on* cut day.
- Every lane owes a **dev test pass** before it's marked cut-eligible; coordinator
  tracks the P-list as "done = dev-complete + tested."

---

## 0. Repo + commit discipline (2026-05-29) — ALL CHATS

looth-platform is now a **git repo**: working tree `/home/ubuntu/projects`,
remote `github:iandavlin/looth-platform`. Everything that deploys to live lives
here (see `deploy/MANIFEST.md`).

**Standing protocol — every chat, every change set:**
1. **Edit in the repo, deploy to target.** NEVER edit live-serving copies in
   place (`wp-content/`, `/srv/`, `/etc/`) — that caused the 10:55 shim clobber.
   Source of truth = the repo subtree; `deploy/deploy.sh` places it.
2. **Commit at the end of every change set, then push.** A change isn't done
   until it's committed. **Stage by pathspec, NOT `git add -A`** — the working
   tree is shared across lanes; a blanket `add -A` sweeps a neighbor's
   uncommitted files into your commit (this happened: bb-mirror's forum work
   landed in another chat's `d657ce8`). Stage only your lane's paths:
   `git -C /home/ubuntu/projects add <your/paths> && git commit -m "…" && git push`
3. Plugins / mu-plugins / server config: edit the repo copy (under the plugin/
   app dir or `platform/`), then deploy — don't hand-edit the deployed copy.

## 0a. Shared-header consumer contract — EVERY consumer must pass

Every caller of `lg_shared_render_site_header()` must pass, in addition to the
viewer fields (`authenticated`, `tier`, `display_name`, `avatar_url`,
`capabilities`, `msg_unread`, `notif_unread`):
- **`active_nav`** — which top nav item to highlight (suppress on the matching page)
- **`logout_url`** — `wp_logout_url()` (nonce'd) so the account menu can sign out

Missing either = nav doesn't highlight + no working logout. archive-poc +
bb-mirror pass them; events-landing + membership-chrome were missing both (fixed
2026-05-29). **New consumers: include them.** Mirrored in the site-header.php
docblock (lg-shell owns that file). The header is "dumb" — it renders what it's
handed; these are the consumer's responsibility.

## 0b. Launch invariant — pages served STANDALONE, not WP-templated (2026-05-29, Ian)

Every user-facing launch page is served **standalone** (nginx → standalone PHP →
`require site-header.php`), like archive-poc/bb-mirror/profile-app — **NOT** as a
WP-templated page (`template_include` on a WP page). A WP-templated page **boots
WordPress on every load** (slow, ~2.6s floor); **the shim does NOT fix that** (it
only kills the identity *loopback*, not WP-boot-for-the-page). Standalone pages
read their data from *outside* WP (direct read-only DB query or a mirror).

- ✅ Already standalone: front page (archive-poc), forum (bb-mirror),
  profiles/directory (profile-app).
- 🔧 Stragglers to convert: **events-landing** (light — read `wp_posts`) +
  **membership pages** (heavy — 388KB shortcode UX; money-engine already
  standalone in lg-stripe-billing).

**Fast first experience = pages standalone (no WP boot) + shim (no identity
loopback).** Both required, orthogonal.

## 0c. Post-shim identity contract — what consumers read (2026-05-29, ratified)

After the shim-replacement ships, surfaces render their header with **zero WP
boot, zero loopback** by splitting identity by volatility:
- **`looth_id` JWT** carries: `sub`(uuid), `wp_user_id`, **`display_name`,
  `avatar_url`, `slug`** (stable; minted by profile-app, re-minted at login;
  self-purged on profile edit).
- **`lg_tier` cookie** carries coarse **tier** (volatile → never in the token;
  owned by `lg-viewer-tier.php`, set at login + refreshed on WP requests, TTL =
  WP session, coarse first-paint HINT that may lag a mid-session role change).
- **`/whoami`** reconciles **capabilities** + authoritative tier **only on
  sensitive gates** (rare on read surfaces; 30s cache; §3a: cookie=hint,
  /whoami=truth where it matters).

**Consumers (archive-poc, bb-mirror, shared-header) render from JWT + `lg_tier`
on the hot path; loop back only for sensitive gates.** This is what kills the
per-render WP-bootstrap tax. (Design: `design-shim-replacement.md` §C.)

## 0d. Canonical launch URLs (2026-05-29, Ian: "clean the urls")

POC URLs retire. Canonical launch paths — every surface builds to these:

| Surface | Clean URL | From | When |
|---|---|---|---|
| Front feed | `/` (served directly, no redirect chain) | `/`→`/archive-poc/`→`/front-page/` | now |
| Archive | `/archive/` | retire `/archive-poc/` | now |
| Forum | `/forum/` | `/forums-poc/` | now |
| Events | `/events/` | ✓ done | — |
| Profiles / practices | `/u/<slug>` / `/p/<slug>` | ✓ done | — |
| Member directory | `/members/` | `/directory/members` | **cutover** (BB holds `/members/`) |
| Membership | (optional) `/join/`, `/membership/*` | `/lgjoin/` etc. | later/optional |

**Non-breaking transition:** add clean route alongside POC → lane switches its
self-links to the clean base → verify → 301 the POC path. **Each app must
parameterize/update its self-link base path** (don't hardcode POC). nginx routing
+ 301s = sysadmin; self-links = each lane; nav = lg-shell.

## 0e. Multi-dev git-native workflow (2026-06-01, Ian: bringing on a 2nd dev)

Two+ human devs, each running their **own** Claude in their **own** Linux account
(`ian`, `buck`, …). They do NOT share one working tree and do NOT talk to each
other directly. They stay on the same path through exactly two things:

1. **This doc = shared *intent*.** It's versioned in the repo; every lane-Claude
   reads it on startup (+ the project `CLAUDE.md` + `TEAM-CHANGELOG`). **Only the
   coordinator edits it**; when the contract changes, coordinator commits it and
   everyone `git pull`s. The doc keeps direction aligned.
2. **One coordinator + one `main` = shared *code*.** Every change funnels through a
   single merge point. The doc aligns what we build; the coordinator's merges align
   the code. (The doc alone is not enough — that's how two accounts would drift.)

**Topology**
- **GitHub** `github:iandavlin/looth-platform` (remote alias `github-looth`) is upstream truth.
- **Canonical local tree** `/home/ubuntu/projects` — the **coordinator/sysadmin** (`ubuntu`)
  clone. The only clone with GitHub push creds + sudo for deploys.
- **Each dev** has their own clone in `$HOME` (e.g. `/home/buck/looth-platform`), `origin`
  = the canonical tree (so `git pull` works **cred-free**; no GitHub creds for devs).

**Merge-on-behalf flow** (the coordinator is the gateway; devs never push to GitHub):
1. Dev branches in their clone (`<user>/<lane>-<topic>`), commits **by pathspec**
   (never `git add -A` — §0).
2. Dev tells the coordinator the branch is ready (via Ian / a `~/temp` note).
3. Coordinator: `git -C /home/ubuntu/projects fetch /home/<user>/looth-platform <branch>`,
   reviews the diff, runs the **dev test pass** (governing invariant: dev-complete AND
   dev-proven before merge).
4. Coordinator merges to `main`, **pushes `main` to GitHub**, then `deploy/deploy.sh`
   places it (most targets — `/srv`, `/etc/nginx`, FPM pools — need **sudo**, so deploy
   is a coordinator job; team accounts are no-sudo, see `/etc/skel/.claude/CLAUDE.md`).
5. Devs stay current with `git pull` (reads `main` from the canonical tree).

**Review-together rule (Ian, 2026-06-01):** the coordinator presents the full set of
commits + their diffstat **before** every push to GitHub. No silent pushes.

**Shared WordPress:** all devs work the one shared dev site at `/var/www/dev`
(`ian:loothdevs`, group-writable + setgid) — no per-dev WP install. The repo holds the
plugins/apps that *deploy into* `/var/www/dev` or `/srv`; the running WP is shared, so a
WP-plugin change is visible to everyone on dev (coordination point, not isolation need).

## 1. Tier vocabulary

> **📍 CANONICAL COPY MOVED → [`TIER-TAXONOMY.md`](TIER-TAXONOMY.md)** (single
> source of truth, 2026-06-02). The section below is retained for context but
> `TIER-TAXONOMY.md` wins on any disagreement.

The user identity has two axes. Don't collapse them into one enum.

### Axis A — Authenticated?

| State | Means |
|---|---|
| `anon` | No WP login cookie. No identity. |
| `auth` | Logged-in WP user. Has identity, profile, can comment, etc. |

### Axis B — Paid tier (gating)

| Role written by Arbiter | Canonical tier | Gating treatment |
|---|---|---|
| (none, anon) | `public` | sees public content only |
| `looth1` | `public` | logged-in but no validated paid entitlement — same content as public |
| `looth2` | `lite` | Lite paying |
| `looth3` | `pro` | Pro paying |
| `looth4` | `pro` | Pro comp (admin / VIP / guest) |

**Why looth1 maps to `public` for gating:** looth1 is the Arbiter's resting
state — every new WP user lands there so the parser has a row to write to,
and a lapsed Pro falls back there when no source reports a paid tier. It
carries no paid entitlement. Identity-aware features (commenting, profile,
BB read access, personalized rails) check `authenticated` instead.

### Provenance (sidecar, not part of the tier enum)

Most consumers don't care. Billing-aware UIs do:

- `provenance: paid` — looth2 / looth3 backed by an active Stripe or Patreon source
- `provenance: comp` — looth4 (admin/VIP/guest)
- `provenance: lapsed` — looth1 with at least one historical source row
- `provenance: new` — looth1 with no source rows yet

Use this to suppress "Manage subscription" for comps, or to surface
"Resubscribe" for lapsed users — not for content gating.

---

## 2. `/whoami` contract

Single canonical identity + entitlement endpoint for strangler consumers
that don't run inside WP. Mints once, every consumer reads the same shape.

**Home:** profile-app, served at `/profile-api/v0/whoami` on the
profile-app service. profile-app owns identity (reads from its own
Postgres from day one — see pre-cutover note below); tier is a lookup
it performs against WP via an internal user-context endpoint. Born
in profile-app from day one — no "start in poller, move to profile-app
later" intermediate step.

**Tier source has two implementations** behind the same internal
`/wp-json/looth-internal/v1/user-context/{id}` contract:

| Environment | Active tier-writer | user-context implementation |
|---|---|---|
| dev | `lg-patreon-stripe-poller` (Stripe + Patreon source-types, Arbiter picks winner) | `InternalRestController` reads from poller's source rows |
| live (cutover day 1) | `lg-patreon-onboard` + `lg-looth4-expiry` + `mu-plugins/looth-roles.php` + code-snippet #44 | **Patreon adapter** (new) reads from those existing writers, emits the same shape |
| live (post-Stripe-enable) | Stripe poller takes over Patreon source-type too; `lg-patreon-onboard` retires | Same poller's `InternalRestController` as dev |

Consumers (`/whoami`, archive-poc, BB-mirror) see the same response
shape regardless. Adapter is owned by the poller chat (same
InternalRestController pattern, just a different read source).

**Pre-cutover identity source:** profile-app reads its own Postgres
from day one (not WP). Slice 2.75 already snapshotted xprofile into
Postgres with adequate fidelity (display_name, slug, location). Any
drift between Postgres and WP usermeta in the pre-cutover window is
bounded and surfaces via the existing visual-audit ritual. Accepting
small drift avoids writing throwaway WP-reader code that gets ripped
out at cutover.

A thin WP shim at `GET /wp-json/looth/v1/whoami` proxies to profile-app
for any WP-side consumer that finds it more convenient (lg-layout-v2
generally doesn't need it — it has `$current_user` in-process).

**Endpoint:** `GET /profile-api/v0/whoami` (canonical) or
`GET /wp-json/looth/v1/whoami` (WP shim, same shape)

**Auth:** WP login cookie OR `Authorization: Bearer <JWT>` (profile-app style).

**Response (anon):**
```json
{
  "authenticated": false,
  "tier": "public"
}
```

**Response (authed):**
```json
{
  "authenticated": true,
  "user_uuid": "f20ad778-1e5e-5508-853b-ad928c499f2f",
  "wp_user_id": 1234,
  "slug": "evan-gluck",
  "display_name": "Evan Gluck",
  "avatar_url": "https://.../bpfull.jpg",
  "tier": "lite",
  "provenance": "paid",
  "capabilities": {
    "edit_posts": false,
    "manage_options": false,
    "edit_archive_poc": false
  },
  "cache": {
    "etag": "w/\"abc123\"",
    "max_age": 30
  }
}
```

`user_uuid` is the canonical identity primitive — consumers should key
off it, not `wp_user_id`. `wp_user_id` stays in the response as a
legacy bridge (WP shim consumers may need it) but is deprecated for new
code.

`avatar_url` ships in the same response so header renders don't need a
second round-trip.

**Stub-tier transition state:** profile-app may ship `/whoami` before
the poller's user-context endpoint lands. In that window, `/whoami`
returns `tier: "public"` for everyone and a `tier_unavailable: true`
flag. Consumers MUST treat `tier_unavailable: true` as "don't gate on
this response yet" — render as public, skip premium UI, but don't
permanently deny. Cleared one-line when the poller endpoint ships.

**Caching:**
- Server-side: Redis (or Postgres unlogged table fallback if profile-app
  isn't already running Redis), 30s TTL, key by `user_uuid` (or session
  token for anon).
- Response: `Cache-Control: private, max-age=30` + ETag.
- Invalidation: two triggers — (a) WP fires `do_action('looth_tier_changed', ...)`
  which POSTs purge to profile-app, (b) profile-app self-purges when any
  write to `/profile-api/v0/me/*` mutates identity for the same user.

**Internal-channel auth (poller ↔ profile-app):**

- Secret file: `/etc/lg-internal-secret` (root-readable, deploy-provisioned, single key used both directions)
- PHP constant: `LG_INTERNAL_SECRET`
- Header: `X-LG-Internal-Auth` (matches archive-poc's `X-LG-` prefix convention)
- Verify with `hash_equals()` (constant-time)

Same shape applies to both the poller's `user-context` endpoint
(profile-app calls) and profile-app's `purge-whoami` endpoint
(poller calls).

**Cache invalidation — centralized via WP action:**

Rather than wiring purges into Arbiter alone, every WP-side writer of
tier state fires a single action:

```php
do_action('looth_tier_changed', $user_id, $old_role, $new_role, $provenance);
```

Writers that fire it: Arbiter, UserProvisioner (signup grant), admin
role edits, refund/cancel paths. The purge handler subscribes to that
single action and POSTs `/profile-api/v0/internal/purge-whoami` with
`wp_remote_post` (blocking=false, 1s timeout, no retry — fire-and-forget).

This catches non-Arbiter writes that would otherwise leave stale cache.

**Capabilities map currently in scope:**

- `edit_posts` (lg-layout-v2 admin)
- `manage_options` (admin gates anywhere)
- `edit_archive_poc` (archive-poc FE editor)
- `moderate_forums` (BB-mirror mod actions) — computed from WP's
  bbp_moderator / bbp_keymaster / administrator capability set

The capability set is intentionally narrow — only flags a consumer
actually checks. Add new ones as consumers need them; don't speculate.

**Companion endpoint — batch identity lookup:**

`GET /profile-api/v0/users?uuids=<csv>` →
`[{ "user_uuid": "...", "slug": "...", "display_name": "...", "avatar_url": "..." }, ...]`

BB-mirror (and any future consumer rendering a feed/thread with many
author identities) needs this — calling `/whoami` per author is wrong;
that returns the current viewer's identity, not a third party's. Ships
alongside `/whoami` in the same profile-app slice.

**Avatar / author-identity — SINGLE SOURCE, every surface (2026-05-29, Ian):**

One avatar per user, identical on every surface, editable in exactly one place.

**The author-identity CARD = avatar + `display_name` + bio (`at_a_glance`)** — all
single-source from the profile spine, all read together via `/whoami` (viewer) + the
batch `users` lookup (authors). `at_a_glance` is the bio: it fills WP's "about author"
field and is the bio shown on **any content the person authors** (post author-header/
footer bylines, author box) — same single-source rule as the avatar. Edit once on the
profile, updates everywhere. (Below is written avatar-first; the same delivery +
backfill model carries the bio and display_name.)

- **Source of truth = the profile spine** (`users.avatar_url`, profile-app) —
  NOT WordPress/BuddyBoss, NOT Gravatar. Those are legacy we read ONCE to seed,
  then decommission; truth can't live in the thing we're turning off. profile-app
  **stores AND serves** the image file too — a canonical, stable per-user URL
  keyed by `user_uuid` (mirrors store the URL string once; it never goes stale).
- **Read paths (already contracted above):** the **viewer's own** avatar →
  `/whoami`; **any author's** avatar → the batch lookup
  `GET /profile-api/v0/users?uuids=`. No surface calls Gravatar or reaches into
  BuddyBoss, and nobody copies the image bytes — surfaces reference the user and
  resolve the URL.
- **Every surface reads from those two, with the initials circle as the universal
  empty-state fallback** (`avatar_url` null):
  - shared header (current viewer) — **lg-shell**, via `/whoami` ✔ already does
  - forum threads/feed (authors) — **bb-mirror**, via batch lookup (today:
    Gravatar `d=`-to-gated-fallback + initials → switch to spine avatar)
  - archive author banner (authors) — **archive-poc**, via batch lookup
  - post **author-header** + post **author-footer** bylines — **lg-layout-v2**
    content, via batch lookup keyed on the post's author `user_uuid`
  - directory + profile/practice pages — **profile-2.0**, native to the spine
- **Edit once → propagates everywhere:** user changes their picture in the
  profile-2.0 editor → profile-app writes the spine + new bytes, bumps an
  `avatar_version`, and fires the existing identity purge (generalize
  `looth_tier_changed` → an identity-changed signal) so mirrors re-pull the person
  record. Versioned URL (`?v=<avatar_version>`) cache-busts the image. No stale
  snapshots — that's the whole point of resolve-by-reference, not copy.
- **Backfill (slice-4, ONE pass — the image bytes, not just the pointer):** copy
  each user's CURRENT avatar into the new store + set `avatar_url`/version.
  Literal source→target: BB-uploaded avatar files copy directly. Gravatar-only
  users (no uploaded file) → one-time fetch-and-store the gravatar (severs the
  external dep, keeps their picture) or initials — lean toward the fetch so nobody
  loses a picture on cut day.
- **Owners:** profile-app owns source + store + serve + version + editor +
  backfill. Each surface lane owns swapping its render to the batch-lookup avatar
  with the initials fallback.

**User-uploaded media — own our storage (2026-05-29, Ian):** the avatar storage
decision GENERALIZES. ALL user-generated media — avatars, **forum reply images**
(bb-mirror), profile/practice gallery + storefront images (profile-2.0) — should
land in **app-owned storage**, NOT `wp-content/uploads/` (`bb_medias/`, `avatars/`,
…). Writing new uploads into WordPress's media tree entrenches a WP dependency
exactly as we're cutting WP loose. Each lane adding an upload path confirms its
target store up front (app-owned dir / object store + a stable served URL);
legacy `wp-content` media migrates once at cutover (same one-pass model as the
avatar backfill). Don't solve avatars and reply-images two different ways.

**Messaging — OFFER IT (keep + rebuild, 2026-05-30, Ian):** member-to-member DMs
are a KEEP, not a decommission. Usage (dev's ~4-month-old snapshot; live is ahead):
**1,881 msgs / 370 threads / 219 senders** (~12% of 1,812 users), ~140/mo steady,
**5.1 msgs/thread** — real conversations, not pings. Ian: "we have to offer it."
Implications:
- lg-shell **"messages" modal gets a real backend**, not a stub.
- **Message history is a migration target** — `wp_bp_messages_*` (threads +
  recipients) carries into the new store; users expect their DMs to survive the cut.
- **Storage = app-owned** (per the media/single-source direction), not wp-content.
- **Home = profile-app (DECIDED, Ian 2026-05-30: "kinda going to live in profile").**
  It already owns identity (`/whoami`, `looth_id`, the spine); connections +
  messaging are people-to-people, so they live where the people do.
  **UI lives in TWO surfaces (matching BB, Ian):** the **Connect + Message buttons
  on the profile page** (`/u/`, rendered by profile-app natively) and the **header
  modals** (messages / notifications / friends — lg-shell's P9 work). **Both call
  the one profile-app social backend** — profile-app owns the data + the on-profile
  buttons; lg-shell owns the header-modal UI; no double ownership. profile-app's
  scope thus grows: spine + blocks + **connections + messaging + their migrations**.
- **Timing = CUT-DAY-REQUIRED (DECIDED, Ian: "has to be there when we turn on the
  lights").** NOT a fast-follow — on the critical path alongside the profile spine.
  So the `wp_bp_friends` + `wp_bp_messages_*` migrations join the pre-cut crib
  scope, and the social layer is a **cutover-eligibility (P-list) blocker.**

**It's the whole social LAYER, not just DMs (Ian, 2026-05-30):** scope = the
BuddyPress social cluster — **connections** (friends / follow / requests) +
**messaging** + the lg-shell modals (friends / follow / messages / notifications).
- **Connections = the easy half:** build the STORE ourselves on postgres (better
  DB — `connections(a, b, status[pending|accepted|blocked], type[friend|follow])`
  keyed on `looth_id`, queryable next to the directory; can't stay in BB, it's being
  decommissioned). **SEED it from BB** one-pass in the crib — `wp_bp_friends`
  (+ follow table if present) → `connections` — so existing friend graphs survive
  the cut. Same build-thin-store + migrate-history pattern as messaging. No realtime;
  ties to directory + profile; gates who-can-DM-whom (and the optional contact-reveal).
- **Build vs adopt for messaging (Ian asked re: an OTS server to skin):** decided
  by VOLUME — ~5 msgs/day, async 1:1, NOT realtime chat.
  - SaaS drop-in (Sendbird/CometChat/Stream): fastest skin, but DMs live with a
    3rd party (breaks own-our-data), paid, awkward history import + identity bridge.
  - Self-host OSS (Matrix/Rocket.Chat/Mattermost/Tinode): data stays local but a
    whole extra service + still a custom client + migrate history into its schema —
    overkill for the volume. (Tinode = closest "server you skin" if we go this way.)
  - **Build thin on the existing stack** (postgres + profile-app pattern): identity
    solved (`/whoami`/`looth_id`), history imports SQL→SQL, consistent with all lanes.
  - **DECIDED (Ian, 2026-05-30): build thin, in-house.** A `threads` / `messages` /
    `recipients` schema on postgres (profile-app pattern), identity via `/whoami`
    + `looth_id`, history imports SQL→SQL from `wp_bp_messages_*`. No OTS server, no
    SaaS. (OTS/SaaS/WhatsApp analysis below kept as the recorded rationale.)
  - **WhatsApp / consumer apps considered (Ian) — NOT a backend fit:** WhatsApp
    Business API is business→customer, can't broker member↔member DMs (needs phone
    numbers + opt-ins + per-msg fees; hands DMs + history to Meta → breaks
    own-our-data + the history migration). Legit lighter roles only: an opt-in
    **"WhatsApp" contact field** on profiles (≈ a social link, zero infra) and/or
    **WhatsApp/SMS as a notification channel** for in-house DMs (fast-follow). If
    the goal is "don't build it," the category is chat-as-a-service SDKs
    (Sendbird/CometChat/Stream), not WhatsApp.
  - **"Connection-gated contact exchange" (Ian's angle):** uses NO WhatsApp API
    (the API has no introduce-two-users / number-lookup / deliver-an-account
    primitive — business→customer only). Pattern: member stores WhatsApp as a
    PRIVATE contact field; **connecting reveals it** (or a `wa.me` link) between
    accepted connections; chat happens on WhatsApp, platform just brokers the intro.
    Cheap, but pure handoff = NOT hosting messaging: abandons the 1,881-msg history,
    no on-platform experience, needs members to share personal numbers, and loses
    the keep-it-on-platform crowd. **Lean = HYBRID:** the **connection doubles as a
    contact-reveal gate** (WhatsApp / email / phone — member's choice, connections
    only) AND a thin in-house DM preserves history + serves on-platform users. The
    connection graph powers both.

**Social decisions RULED (2026-05-30, Ian):**
- **Follow DROPPED as a user feature.** Connections = **mutual only**. If a feature
  (feed, etc.) needs a follow signal, **auto-follow on connect** — derived from the
  connection, not a separate graph or UI. **Do NOT migrate `wp_bp_follow`** (the
  9,002 one-directional follows don't carry). Schema: drop the `follow` type.
- **Messaging = connections-only** — preserve current BB behavior: a **mutual
  connection gates DM** (connect first, then message). No any-member DM.
- **Notifications: start FRESH** — don't port the 49,603 BP rows; seed current-unread
  DMs + pending connection requests so the bell isn't empty at cut.
- **Notification counts** — badge **caps at "9+"** for display (endpoint returns the
  true count); **+ a retention job auto-deletes notifications older than 30 days**
  (keeps the table lean — BB's grew to 49,603 unbounded; the DM/connection persists,
  only the bell alert prunes).
- **Header counts via dedicated `me-social-counts`** (additive; no `/whoami` change).

**Schema impact:** one revision — drop the `follow` type/graph; then the social
schema is dev-final. Rest is logic (DM connection-gate), UI (9+ badge), cron (prune).

**Cookie fast-path (optional):**
- `lg_tier` cookie keeps current archive-poc behavior — a fast hint so the
  first paint doesn't have to wait on `/whoami`.
- Consumers MUST treat `/whoami` as truth and reconcile if the cookie
  disagrees. Cookie is a hint, not authority.
- See §3 for the staleness problem this leaves open.

**Capabilities map:**
- Start narrow: only flags a consumer actually checks.
- archive-poc needs `edit_archive_poc` (currently planned as cookie-gated;
  fold into this instead).
- profile-app needs `edit_own_profile` + `manage_options` for admin tools.
- BB-mirror TBD.

---

## 3. Open seams

### 3a. Cookie staleness on mid-session role change

Today `lg_tier` is set at login. If Arbiter flips a role mid-session
(gift redeem, subscription canceled, refund-and-block), the cookie lies
until next login. archive-poc and the BB-mirror will both gate on stale
data.

**Options:**
1. Arbiter writes the cookie via a small REST endpoint the user's browser
   pokes on next request (needs a sentinel).
2. Strangler consumers always call `/whoami` (30s cache absorbs the cost).
3. Short cookie TTL (e.g. 5 min) + revalidate.

**Recommendation:** option 2. Cookie stays as a first-paint hint; `/whoami`
is the truth on every request that actually gates anything sensitive.

### 3b. Identity provider for strangler services

profile-app uses JWT minted by a WP webhook. archive-poc uses the WP login
cookie directly (same domain). BB-mirror is undecided.

**Recommendation:** every strangler hits `/whoami` regardless of auth
mechanism. The endpoint accepts both cookie and JWT. The consumer doesn't
care which the viewer presented — it gets the same response shape.

### 3c. looth1 semantics — placeholder, do not gate on

looth1 exists so the Arbiter has a row to write. New signups + lapsed
ex-Pros both land there. **Do not introduce gating logic that treats
looth1 as anything other than `public`.** If a future feature needs to
distinguish "never paid" from "lapsed", read `provenance` from `/whoami`,
not the role.

---

## 3d. BuddyBoss surface inventory + roadmap

The cohesion problem at cutover is that BB-themed pages (groups, profile,
old activity) wear different chrome than lg-layout-v2 posts and
archive-poc. Solving it doesn't require ditching BB entirely — it
requires ditching the BB *theme* while keeping BB *plugin* features that
are still in use.

> **RULED 2026-05-30 (Ian): BB groups pages DROPPED from cut scope entirely.**
> No reskin, no group-as-forum-with-decoration at cutover. Groups are deferred
> post-cut. Removes a cutover dependency.

### Group inventory (dev, 2026-05-27)

| Pattern | Groups | Real usage? | Disposition |
|---|---|---|---|
| **Regional "Local Looths"** (9) | SoCal (770), Tri State NYC (772), DMV (284), SW Ontario (282), PNW (285), Middle TN (279), Basque Country (268), Ohio (11), Ireland (10) | **Yes — only real group usage on the site** | ~~Reskin at cutover~~ **DROPPED from cut scope (2026-05-30)** — post-cut. |
| **Auto-enroll topic groups** (5) | Business, Market Place, New Builds, Repair & Restoration, Tools/Spaces/Robots/Widgets — all ~1784 members | **No — vestigial from an old per-forum activity-feed display scheme** | Delete after cutover. Frees ~9000 junk memberships. |
| **Small conversational topic** (4) | General Chat (97), Dank Memes (53), Music (36), Charla General (14) | Light | Reskin at cutover; revisit later. |
| **Internal/admin** (2) | The Jannies (2, hidden), Looth Group Partners (5, private) | Internal | Reskin or leave. Negligible. |

### Reskin approach (cutover scope)

CSS-only. Capture BB's rendered group-page HTML + relevant CSS rules, drop
into our own templates so groups inherit the unified header/footer/
typography. BB plugin keeps running the group machinery (membership,
posting, forum threads) underneath. BB is GPL'd — no licensing concern.

Same approach applies to any other BB-rendered surface that's lightly
used but still needed: messages, notifications, member-typing area.
Reskin to match site chrome; don't reimplement.

### Group inventory — correction (2026-05-28)

**Earlier framing said the 5 big "auto-enroll" topic groups were vestigial
and should be deleted. That's wrong** — those entries (Repair and
Restoration, New Builds, Tools, Business, Market Place) are **parent
forums whose subforums hold the actual content**. They look empty
because `topic_count` is direct-children-only; `total_topic_count`
shows hundreds-to-thousands of topics in subforums. They stay.

The 1786-member-each count comes from auto-enrolling everyone for
visibility at the parent level; topics live one level deeper. BB-mirror
initially missed this; now corrected.

**Real group categorization, updated:**

| Pattern | Status |
|---|---|
| Parent forums with subforums underneath (Repair, New Builds, Tools, Business, Market Place) | **Stay — functional hierarchy, not vestigial** |
| Regional "Local Looths" groups (9) | The only true "group" usage. Collapse into forum-with-decoration at cutover (see §3f BB-mirror updated scope) |
| Small conversational topic forums (General Chat, Dank Memes, Music, Charla General) | Stay as forums |
| Internal / admin (The Jannies, Looth Group Partners) | Stay, visibility-filtered out of public list |

### Group-as-forum-with-decoration (collapse, 2026-05-28)

Ian's call: BB-groups primitive collapses into "forum with extra
decoration" at cutover. The word "group" stays as UX label. Underneath:

- Each Local Looths group becomes a forum (each already has one
  attached anyway)
- Forum schema adds `avatar_url` (+ keep existing `description`)
- "Subscribe to forum" semantics relabeled to "join group" in UI
- Custom header per group-forum (e.g. "SoCal Looths" header above the
  forum topic list)
- No separate `/groups/` surface needed — directory of groups becomes
  a "Local Looths" category in the forum index
- Per-group activity, photos, docs, etc. — handled per BB-DECOMMISSION-INVENTORY

What we lose: very little. The word stays; member-list semantics stay
(via subscriptions relabeled); custom header preserves visual identity.
What gets simpler: no archive-poc group-landing composer needed, no
group-directory rail, no `/groups/` surface, no group-scoped activity
filter (group is just a forum).

### Decision rule for BB features not yet surveyed

### Decision rule for BB features not yet surveyed

When you find a BB feature in use post-cutover, pick one:

- **Reskin** if it works fine but looks wrong (cheap, days)
- **Replace** if it's central enough to invest in a strangler version (weeks)
- **Drop** if the inventory shows nobody uses it (free)

Don't replace what reskinning solves. Don't reskin what dropping solves.

---

## 3f. BB-mirror scope (confirmed 2026-05-27)

Read-side strangler for forum threads only. Reskin everything else.

| Surface | BB-mirror? | Where it lives instead |
|---|---|---|
| Forum threads (forums, topics, replies, pagination, search) | **yes — primary** | — |
| Forum subscriptions (read state) | yes | — |
| Activity feed | no | archive-poc |
| Group membership (user ↔ group) | consume only | profile-app Postgres post-cutover |
| Group home pages / member directory | no | BB plugin + reskinned CSS |
| Messages, notifications, presence | no | BB REST authoritative; reskin only |

**Service shape:** own FPM pool, own nginx location `/forums/*`,
mu-plugin sync. Storage = `forums` schema in the shared postgres
instance (see §3i). Failure isolation comes from schema separation,
not from a separate DB engine.

**Write path:** all writes (post reply, new topic, subscribe) round-trip
through BB REST (`/wp-json/buddyboss/v1/{reply,topics,forums/subscribe}`)
so mentions, moderation, notifications, and presence keep working
unchanged. Pattern is JS → fetch BB REST → reload, same as
`lg-fe-editor.js`. BB-mirror is read-side strangler only.

**Sync:** mu-plugin `bb-mirror-sync.php` on `bbp_new_topic`,
`bbp_new_reply`, `bbp_edit_*`, trash/spam, merge/split. Reconciliation
cron walks `wp_posts WHERE post_type IN (forum,topic,reply) AND modified
> last_reconcile` as belt-and-suspenders for missed webhooks.

**Forum visibility data:** mirrored into BB-mirror's `forums` schema
at sync time (BB postmeta `_bbp_forum_visibility` etc). Forum
visibility changes near-never; mirroring it locally avoids per-request
WP calls and keeps the mirror's database the source of truth for its
queries.

**Logged-out anonymizer coordination (flagged 2026-05-28 by Ian):**
There's an anonymizer plugin on live that handles what logged-out
viewers see. Forum-privacy logic in BB-mirror needs to check in with
that plugin's behavior — i.e. an anon viewer hitting `/forums/<slug>/`
should see whatever the anonymizer says they should see, not bypass
it. Plugin name + location TBD — Ian to point at it when BB-mirror
gets to anon-visibility work. Until then: render conservative
(don't expose anything to anon that isn't already exposed via the
existing WP rendering path).

**Single-mod model:** Ian moderates everything. No per-forum mod
migration needed. BB-mirror's open Q on `forum_moderator` table is
formally closed — defer indefinitely. Mod actions stay sitewide
through BB admin until/unless that changes.

**Search:** BB-mirror owns its own postgres FTS index (tsvector + GIN)
for forum content. archive-poc indexes editorial posts (events,
articles), not forum replies — different domain, different schema.
Don't cross-couple. Cross-schema queries are available if a feature
genuinely wants them.

**Cutover-day routing fallback:** when nginx flips `/forums/*` to the
BB-mirror upstream, keep BB's native templates available behind
`?bb_native=1` for the first week as a kill-switch. Cheap escape hatch;
production routing flips always need one.

**Group-scoped views:** the 9 regional Local Looths groups read
membership from profile-app post-cutover (not from BB's
`bp_groups_member`). Pre-cutover, mock with a hardcoded membership
table or no-op the group-scoped view.

---

## 3j. Mobile considerations — lens for every decision

Mobile app is in the immediate horizon (Ian: "we are def going to produce a mobile app"). No mobile codebase exists yet, but every cross-cutting decision should pass through the mobile lens.

**Questions every cross-cutting decision answers:**

1. **Latency budget** — does this introduce per-request latency that won't fit mobile UX expectations (sub-200ms perceived)?
2. **Offline behavior** — does this assume an always-on connection, or can the mobile client cache + reconcile?
3. **API shape for non-browser consumers** — is the contract usable from a non-browser HTTP client (clean JSON, no cookie-only auth dependency, no SSR-only data)?
4. **Push notification fit** — does the data have a natural event-stream shape that could surface as push later?
5. **Concurrency under fan-out** — does this scale to N mobile clients reading simultaneously without serializing?
6. **Auth pattern** — does it support JWT/bearer-token auth alongside cookie auth, since mobile won't have a browser cookie jar?
7. **Read/write split** — can mobile read directly from data sources without going through full WP-render pipelines?

**Already baked-in (record):**
- Postgres-everywhere chosen because mobile concurrency is the binding constraint (SQLite writer-lock model doesn't scale to fan-out)
- `/whoami` contract includes `user_uuid`, `avatar_url`, `capabilities` — all mobile-friendly shapes
- BB-mirror data model designed for mobile read API patterns (cached + queryable, not BB-REST-only)
- lg-shell modal layer naturally translates to mobile sheets/drawers (same UX primitive)
- profile-app JWT auth + cookie auth dual-supported

**When mobile is being built:** spawn a real "mobile" workstream chat (similar to BB-mirror, lg-shell). It becomes the mobile-decisions authority by owning the codebase + API contract. No standing "mobile warden" needed — the lens lives in this canon + chat awareness.

---

## 3i. Storage architecture — one postgres, three schemas

**Primary driver: mobile is imminent.** Not a "someday" feature — Ian
expects it soon at current pace. Storage decisions are made against
that constraint, not against today's "single-user dev box" workload.

All three strangler surfaces share **one postgres server** (the existing
instance currently hosting `profile_app`). Each gets its own schema:

| Strangler | Schema | Datastore today |
|---|---|---|
| profile-app | `profile_app` | postgres (already there) |
| BB-mirror | `forums` (or `bb_mirror` — chat's call) | SQLite — **migrates at cutover** |
| archive-poc | `discovery` (or `archive_poc` — chat's call) | SQLite — **migrates at cutover** |

**Why postgres, not SQLite (per-surface):**
- Mobile = concurrent reads + writes from many clients. SQLite's
  single-writer model becomes a real bottleneck; postgres's MVCC
  doesn't.
- Mobile UX wants composite views ("forum activity in my groups,"
  "events near me with friends attending") — those are cross-schema
  joins, trivial in postgres, painful across SQLite files.
- Mobile-native tooling (PostgREST, RLS, realtime via NOTIFY/LISTEN,
  Supabase-style patterns) only exists for postgres.
- Schema iteration velocity matters as mobile features evolve;
  postgres's DDL is more permissive than SQLite's.
- Production observability (pg_stat_statements, pg_stat_activity)
  exists in postgres, opaque in SQLite.

**Trade-off being made:** archive-poc's individual search latency may
move from ~2ms (SQLite FTS5 in-process) to ~8ms (postgres FTS over a
socket) — still well under user-perception threshold (~100ms). The
loss at zero-load is offset under mobile concurrency: SQLite serializes
writes, postgres scales linearly with cores.

**Why one server, not three:**
- Combined workload is tiny relative to postgres capacity even with
  mobile. Three servers would compete for the same RAM anyway, with
  the overhead of three postmasters + three autovacuums.
- Cross-schema queries possible when wanted. Across separate servers
  needs foreign data wrappers (slow + fragile).
- One backup, one monitoring target, one set of credentials to manage.
- Splitting later is straightforward if any one workload outgrows
  shared hosting (`pg_dump` the schema, restore elsewhere, swap
  connection string). Don't pre-split.

**Why each strangler keeps its own schema:**
- Failure isolation at the schema level (bad migration in `forums`
  doesn't touch `profile_app`)
- Each chat owns its own schema migrations / DDL in its own code
- Clear data-ownership boundaries — no accidental shared tables

**Migration to postgres is a cutover-day task** for BB-mirror and
archive-poc. Both use `pgloader sqlite:///path/to/file.sqlite
postgresql:///dbname` for the data move. SQLite datasets are small;
migrations run in seconds. Each chat owns its own schema design + the
adapter from current SQLite shape to new postgres shape.

**Per-strangler DSN provisioning (canon, surfaced by archive-poc 2026-05-28):**

Mirrors the per-strangler nginx-snippet + per-strangler secret-file
patterns. Each strangler gets:

- Postgres role named after the OS service user (e.g. `archive-poc`,
  `bb-mirror`, `profile-app`) — hyphenated to match the OS user for
  peer auth. Owns its own schema. Granted `USAGE` on other schemas per
  cross-schema discipline below. Role names with hyphens require
  quoting in SQL (`"archive-poc"`); acceptable trade-off for clean
  peer auth.
- Password file at `/etc/lg-<strangler>-db` mode 640
  `root:<strangler-unix-user>`
- FPM pool env var `LG_<STRANGLER>_DSN` exported via `env[]` in
  `/etc/php/8.3/fpm/pool.d/<strangler>.conf`
- DSN format: `pgsql:host=/var/run/postgresql;dbname=looth` (Unix
  socket peer-auth, no user/password needed — pg role identity comes
  from the FPM pool's OS user)

Cutover checklist must include `apt install php8.3-pgsql && systemctl
reload php8.3-fpm` — easy to forget, breaks every strangler.

**Shared write-side role (`looth-dev`, surfaced by BB-mirror 2026-05-28,
extended by archive-poc 2026-05-28):**

Each strangler's web pool runs as its own pg role (e.g. `archive-poc`,
`bb-mirror`) — that role owns the schema and handles READS for page
renders. WRITES go through a shared `looth-dev` role:

- **Loopback `_sync.php` endpoint** runs on the `looth-dev` FPM pool
  because it needs `$wpdb` access
- **Backfill** runs as `sudo -u looth-dev wp eval-file ...` for the
  same reason (WP read + matching peer-auth role)

So `looth-dev` is the strangler's write-side role; the strangler's own
role is the read-side / schema-owner role.

Pattern:
- pg role `looth-dev` — used by the looth-dev FPM pool only (peer-auth
  match to `looth-dev` OS user)
- Granted `USAGE` on each strangler schema (`forums`, `discovery`,
  `profile_app` as applicable) + INSERT/UPDATE/DELETE on the specific
  tables the sync path writes to. Plus matching `ALTER DEFAULT
  PRIVILEGES` so future tables inherit (per archive-poc's
  2026-05-28 implementation).
- NOT granted SELECT-everywhere — minimum needed for sync
- Each strangler's own role still owns the schema (no ownership
  transfer); `looth-dev` is just an additional grant

When standing up a new strangler that has a `_sync` endpoint on the
looth-dev pool, **add the equivalent GRANT statements at schema-creation
time** so the sync writer can write. BB-mirror's pattern (sql/grants.sql
or equivalent) is the reference.

**Cross-schema discipline (canon, surfaced by profile-app 2026-05-28):**

> When schema A needs data from schema B, **A reads from B's schema or
> calls B's endpoints. B does not reach into A.**

Concretely:
- BB-mirror wants "topic author display info" → BB-mirror queries
  `profile_app.users` (read-only) or calls profile-app's
  `/users?uuids=` endpoint. profile-app does NOT add a query that
  reaches into `forums`.
- archive-poc wants "post author display info" → same pattern.
- profile-app exposes data through its own surfaces (schema reads or
  REST endpoints). It doesn't pull data from other schemas to enrich
  its responses.

**Why:**
- Data-flow direction is one-way per consumer (clear in code review)
- Ownership boundaries match call boundaries (the chat that owns the
  data owns the contract)
- Schema migrations in one lane don't break queries in another
- Future split-to-separate-server stays clean (each consumer already
  knows where its dependencies live; not silently coupled by JOINs)

The schema owner's contract is: stable column names, stable
relationships, deprecation warnings before structural changes. Same
discipline as REST APIs — schema is API.

---

## 3h. Stripe shipped dormant on live (cutover-day pattern)

`lg-patreon-stripe-poller` ships to live at cutover, but **disabled by
absence of credentials**. The Stripe poll tick runs but exits cleanly
when no Stripe creds are present; no `stripe` source rows get written;
Arbiter sees only Patreon-source rows (from the Patreon adapter, §2).
Effectively a no-op on the Stripe side until Ian's pricing decisions
land and Stripe creds get added.

**Why this pattern over a feature flag:**
- Disabled-state is *absence*, not a code branch — no "what if Stripe
  is enabled but in safe mode" bug surface
- Existing dev code ships unchanged; no flag-handling to add or test
- When Stripe is ready: add creds → polling lights up → real
  transactions begin. No deploy.

**Cutover-day checklist for the poller plugin:**
- Plugin code deployed to `wp-content/plugins/lg-patreon-stripe-poller/`
- `LG_INTERNAL_SECRET` define in wp-config (reads `/etc/lg-internal-secret`)
- `LG_PROFILE_APP_URL` define in wp-config (per §3g)
- `LG_PROFILE_APP_URL` populated with live profile-app host
- **NO Stripe API key, NO Stripe webhook secret** — these come later when Ian flips Stripe on
- nginx route `^~ /wp-json/looth-internal/` added (mirrors lg-member-sync exempt pattern)

**Stripe-enable later:**
- Add Stripe credentials (test-mode first)
- Run low-cash real transactions to verify Arbiter promotes from `stripe` source rows
- When clean: switch new signups to Stripe checkout flow
- lg-patreon-onboard retires gradually as Patreon-paying users migrate or churn

---

## 3k. Membership-page IA + account menu (2026-05-29)

**Decision:** the membership/Stripe pages stay **poller-rendered standalone
pages**, surfaced through the **shared header's account dropdown** — NOT folded
into profile-app's UI.

**Rationale:** authority separation. profile-app owns *identity*; the poller
owns *tier/billing/subscription* truth (profile-app does not store tier, §1/§2).
Putting "Manage Subscription" *inside* profile-app's surface would imply it owns
billing. IA ≠ code ownership: the unified header is the layer that makes
cross-app pages feel like one site, so account items can route to poller pages
and profile-app pages from one menu without merging codebases.

**Page buckets + homes:**
- **Account self-service** (manage-subscription, my-gifts, request-refund,
  affiliate-earnings) → shared-header **account dropdown**, next to "Edit Profile".
- **Acquisition** (join `/lgjoin/`, gift-buy, redeem-gift) → public CTA. "Join"
  is the header's anon CTA; lg-layout-v2 gate-cta block throws join CTAs on
  paywalled content. Can't live "in profile" — public users need them.
- **Informational** (membership-guide, billing/refund policy) → footer + content links.

**Consequences:**
- **lg-shell** converts `.lg-chrome__account` from a plain link into a dropdown
  (canonical sitewide account menu + sign-out, which the header currently lacks).
- **poller** membership pages drop their own `[lg_member_nav]` strip once on the
  shell — the header dropdown is the account nav now.

## 3e. Stripe poller out of WordPress (post-cutover)

> **NOW A FULL PLAN (2026-05-30, Ian "pull the trigger"):** this section's
> direction is superseded/expanded by **`design-membership-rebuild.md`** — the
> approved plan to relocate the Arbiter out of WP + move tier authority into
> profile-app so WP stops being the entitlement authority. Owned by the
> **billing-rebuild** lane (`bootstrap-billing-rebuild.md`). Includes a stern
> security review (§5b there). Below is the original sketch, kept for context.

The poller currently lives partly in WP (`lg-patreon-stripe-poller`
plugin) and partly in `/srv/lg-stripe-billing/`. The WP-side piece
carries Stripe API keys + webhook secret inside the WordPress filesystem
alongside arbitrary plugin/theme code. Any WP RCE → Stripe key exfil →
real money.

**Direction (not blocking cutover):**

Shrink the WP plugin to a thin shim. Move out of WP:
- Stripe webhook reception (own endpoint, own service)
- Polling loop
- Customer/subscription state cache
- Gift code logic
- Stripe API key + webhook secret storage

Keep in WP (necessary minimum):
- `wp_capabilities` writer (Arbiter — small mu-plugin, receives "user X
  is now tier Y" from the external service over an internal channel)
- Welcome modal footer hook (no secrets)
- Admin UI for now (eventually migrate to strangler dashboard)

External service runs as its own systemd unit, own user
(`stripe-poller`), no read access to `wp-config.php`. WP RCE no longer
equals Stripe compromise.

**Why not blocking cutover:** no specific threat triggered this; it's
hygiene. Same direction-of-travel as BB removal: clear path, no urgency,
queue it.

**Move it up the list if:** a security audit demands it, a PCI-adjacent
requirement lands, a near-miss happens, or the WP plugin surface grows
enough that the blast radius becomes uncomfortable.

---

## 3g. nginx organization

Strangler nginx routes are extracted into per-app snippet files:

- `/etc/nginx/snippets/strangler-profile-app.conf`
- `/etc/nginx/snippets/strangler-archive-poc.conf`
- `/etc/nginx/snippets/strangler-bb-mirror.conf`

Each is `include`d from `dev.loothgroup.com.conf` between the cookie-gate
exempt paths and the WP fallback `location /`. The main conf stays
scannable; new strangler = new snippet + one include line.

**Source-of-truth pattern:** each project's repo carries a
`nginx-snippet.conf` matching its deployed copy. Update flow:

1. Project chat edits its own `nginx-snippet.conf`
2. Ubuntu sysadmin (this coordinator) `sudo cp`s it to `/etc/nginx/snippets/`
3. `sudo nginx -t && sudo systemctl reload nginx`
4. Smoke-curl the affected routes

No more "edit the giant shared conf and pray" merges.

**Pre-cutover hardcoded URLs that need to become config:**

- `PurgeNotifier` in poller hardcodes `https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami`.
  Needs `LG_PROFILE_APP_URL` constant (or wp-option) before live cutover
  so the call routes to the correct host.

---

## 3n. Patreon launch onboarding — new members create a WP account from Patreon (2026-06-01, Ian)

**Launch-critical goal:** a net-new member authorizes Patreon → a WordPress account is
created **anchored to their Patreon user ID** with the correct `looth1–4` tier role; the
**hourly** Patreon poll keeps that role in sync (upgrades AND churn/cancel demotions).
Scope is **new-member account creation** (not existing-user linking — the code handles that
too, but it's not the launch target).

**Owner: the poller lane** (`lg-patreon-stripe-poller`). Most of this already exists there —
**verify/harden, don't rebuild:**
- *Linking:* `[lg_patreon_onboard]` shortcode → Patreon `oauth2/authorize` → `/patreon-callback/`
  (rewrite rule) → verify active patron → create WP user, map tier→role, password-setup email.
  Identity anchor = Patreon user ID (not email). Dev is configured (client_id, campaign_id,
  tier_map, redirect_uri=dev).
- *Polling:* `LGPO_Sync_Engine::run` on the `lgpo_patreon_auto_sync` cron (hourly) — fetches all
  campaign members (Patreon API v2), maps tier→role, writes usermeta.
- *Authority:* `PatreonSourceReader` → `Arbiter::sync` (`source='patreon'`, highest tier wins,
  looth4 protected). `/manage-subscription/` is the post-account read surface.

**Onboarding page (coordinator decision):** signup is pre-account/anonymous → it must NOT live
behind login or on `/manage-subscription/`. → **dedicated public `/join/` page**, per §0d clean
URLs. Built **standalone** (wears the shared header, like `/manage-subscription/`): it renders the
"Connect your Patreon" CTA linking to the poller's WP-side authorize entry; OAuth + WP-account
creation happen WP-side (poller); on success it returns to `/manage-subscription/`. Keeps the page
in the strangled stack while the account-creation engine stays in WP.

**Lanes this touches: standalone (the page) · shell (nav) · poller (the engine).**

**Lane work:**

*poller (owns the engine — most already built, verify/harden):*
1. Dev-prove the NEW-member happy path end-to-end: fresh authorize → WP account created +
   anchored to Patreon ID + correct tier role + password-setup email delivered.
2. Dev-prove the hourly poll: a pledge upgrade reflects within the hour; **cancel/churn demotes
   the role** (downgrade path — most likely under-tested; the key gate).
3. Refresh-token lifecycle (creator token for the member sweep + per-user tokens) survives so
   polling doesn't silently die.
4. Edge flows fire + notify admin: already-onboarded ("you're all set"), not-a-patron error,
   email collision → manual-review flag.
5. Poll-failure visibility → devmsg/email the coordinator (not just `error_log`).
6. Expose a stable **authorize-entry URL** the standalone `/join/` page links to (e.g.
   `/patreon-connect` → builds the OAuth `state` + redirects to Patreon); define the
   post-callback return target (`/manage-subscription/` or a success page) + the copy/states it
   passes back (success / not-a-patron / already-onboarded).

*standalone (owns the page):*
1. Build the public `/join/` page (standalone, shared header). **Launch scope = lean + connect-first:**
   - Primary: "Connect your Patreon" → the poller's `/patreon-connect?return=/join/`; render the
     post-auth states (success / already_onboarded / not_a_patron / email_collision / fail).
   - Secondary: a light "Not a patron yet? → Become a patron" link to
     `patreon.com/loothgroup/membership`.
   - **Build it funnel-shaped (a pitch/CTA scaffold)** because **`/join/` is slated to become the
     Stripe join/checkout page** once Stripe goes live (Patreon→Stripe membership migration, ref
     §3h). Design so the PRIMARY CTA can swap "Become a patron (Patreon)" → "Subscribe (Stripe
     checkout)", with Patreon-connect demoting to a "already a patron? connect" secondary path.
     **Do NOT build the Stripe checkout now** (Stripe is dormant) — just leave the seam. Keep a
     full marketing funnel for later; launch stays lean.
2. Add a "not linked yet → /join/" hint to `/manage-subscription/`.

*shell:* public nav/account entry to `/join/` for logged-out visitors.

*coordinator (me) — launch-day prerequisite (not a lane):* register
`https://loothgroup.com/patreon-callback/` as a Patreon dev-portal redirect URI + load live
client_id/secret/campaign_id/tier_map into live config; confirm the live tier_map (Patreon tier
IDs → looth1–4) is current. Own this section + dev-proof sign-off.

*profile (light lane — onboarding touchpoints, confirmed involved):*
- **Provisioning:** a profile is born when the WP user is. Patreon onboard creates the WP user →
  the existing `/profile-api/v0/hooks/user-created` path provisions the profile row + looth_id.
  VERIFY it fires for Patreon-created users (not only the old BB path).
- **New-member defaults** live here: `location_visibility`/`pin_precision` → members/city is the
  location-default task — i.e. onboarding defaults (buck's first task).
- **Post-onboard landing:** after account + password setup, route the new member to
  `/profile/edit` to complete their profile (final redirect target decided with the poller's
  return contract).
- **Tier reflection:** Arbiter tier → role → looth_id → whoami → tier pill + gating on
  profile/directory (mostly automatic via role→tier).

**Sequence:** poller dev-proves new-member-create + churn-demote loops (GATE) → `/join/` page →
shell nav → live OAuth client registered → launch.

**STATUS 2026-06-01 (poller report-back):**
- ✅ Pre-existing + verified: shortcode→OAuth→`/patreon-callback/`→user-creation, tier→role,
  Patreon-ID anchor, already-onboarded + email-collision paths, hourly sync, Stripe/looth4/admin
  guards, `lg_patreon_members` cache, admin settings UI.
- ✅ **Authorize-entry + return contract LIVE** (the contract `/join/` consumes):
  `GET /patreon-connect[?return=/path/]` → 302 Patreon authorize (path-only validation,
  open-redirect clamped); callback → `<return>?onboarded=<status>`,
  `status ∈ {success | already_onboarded | not_a_patron | email_collision | fail}`; default
  return `/manage-subscription/`; legacy `[lg_patreon_onboard]` shortcode entry unchanged.
- ✅ Churn-demote proven (synthetic: former_patron → downgrade looth3→looth1; apply_change wiring
  proven by earlier uid=1906 promote/revert tests).
- ✅ Poll-failure alerting: `lgpo_alert_failure()` (email + error_log) on validate_config + null
  member-fetch + the explicit 401 "creator token expired" path.
- ⚠️ **ONE OPEN GAP — creator-token refresh lifecycle.** Token is a manually-pasted string, no
  `refresh_token`, ~31-day expiry → silent death (now LOUD via the 401 alert). **Coordinator
  call: BUILD it** (~2h: refresh routine + retry-on-401 + a one-shot creator-OAuth button in
  Settings to capture `refresh_token` + `expires_at`). Not day-0-blocking (fresh token at launch)
  but required for "polling fully functional." Poller builds next.

(Dev note: Fluent SMTP is active for WP `wp_mail` on dev → the poller's alerts reach real inboxes,
not mailpit. msmtp/sendmail paths — e.g. the sudo-queue notifier — still go to mailpit.)

---

## 3o. View-As (admin impersonation) — browse the front end as any user (2026-06-01, Ian)

**Goal (for live):** an admin selects a user and browses the *whole* strangled front end as
that member — to see exactly what they see. Reversible, with a persistent banner and an audit
trail. Replaces BuddyBoss's "View As" (BB is being strangled out, so its view-as won't cover the
new standalone surfaces). Plus an admin **"open this user in wp-admin"** button alongside it.

**Why it's an identity-layer feature (coordinator-owned):** every strangled surface decides "who
are you" from the `looth_id` JWT → `Whoami::resolve()`. So "view as user X" = make the effective
`looth_id` resolve to X. Do that once at the identity layer and *every* surface (profile,
directory, archive, shared header) reflects X with little per-lane work.

**Mechanism (recommended — reuse the existing mint):**
1. Admin-only **"Switch to <user>"** action → switch the WP session to the target (the WP
   *User Switching* `switch_to_user` pattern; retain the real admin id for return).
2. Re-mint `looth_id` as the target — the existing mint path already mints from
   `wp_get_current_user()`. Add an **`act` (actor) claim = the real admin id** so the token is
   self-identifying as an impersonation (never looks like a real login).
3. `Whoami::resolve()` reads it; all strangled surfaces render as X.
4. **Return:** restore the admin's WP session + re-mint their own `looth_id`.

**Safety (non-negotiable — impersonation is sensitive):**
- `manage_options` only; the switch endpoint nonce-protected; impersonation **logged** (who, as
  whom, when).
- **Full functionality (Ian, 2026-06-01):** the admin *acts as* the user — writes are NOT
  blocked. The `act` (actor=admin) claim rides every request so all actions are **attributed to
  the real admin** in logs. CAUTION: billing self-service (cancel/switch-plan/payment) is live
  while impersonating — recommend a confirm-guard on irreversible money actions; audit covers
  attribution either way.
- Persistent **"Viewing as X — Return to admin"** banner on every surface while active.

**Lanes:**
- **coordinator (me):** the switch + return endpoints, `looth_id` re-mint w/ `act` claim,
  `Whoami` honoring it, the manage_options gate + nonce + audit log. Lives in the WP mu-plugin
  (`profile-auth.php`) + a small admin trigger UI. Owns the banner *contract* (what surfaces read).
- **shell:** render the "Viewing as X — Return" banner in the shared header when the `act` claim
  is present; the admin-only **"open in wp-admin"** button (`/wp-admin/user-edit.php?user_id=X`).
- **profile (buck) — also owns the trigger:** the admin-only **"View as this user"** control on
  the **user profile page** (`/u/<slug>`) — Ian's chosen entry point. Also suppress owner-only UI
  (the View-as profile bar, edit links) when the viewer is an impersonating admin, not the real
  owner — so admin sees the member's *actual* view.
- **poller/billing:** no write-block (full functionality, per Ian) — ensure any money action taken
  while `act` is set is logged with the actor (admin) for audit/refunds.

**Entry point (Ian, 2026-06-01):** the **user profile page** (`/u/<slug>`) — an admin viewing a
profile gets an admin-only "View as this user" control (built in the profile lane). Other entry
points (directory, wp-admin users list) can be added later.

**Sequence:** coordinator builds switch + re-mint(+act) + Whoami + banner contract on dev → profile
lane adds the "View as" trigger on `/u/<slug>` + owner-UI suppression → shell banner + "open in
wp-admin" button → dev-prove the full loop (trigger on a profile → browse as X across surfaces →
act as X → return) → ship to live.

**Decisions (Ian, 2026-06-01):** entry point = the user profile page; **full functionality** while
impersonating (NOT read-only) — audit via the `act` claim + the banner are the guardrails.

---

## 4. Cutover sequence

> **⚠️ MODEL CHANGED 2026-05-28 — blue-green, not in-place.**
> Ian's decision: cutover is now **stand up a fresh EC2, build the full
> stack, backfill with current production data, swing DNS.** NOT in-place
> surgery on 54.157.13.77. Relaxed pace (build can take days); old box stays
> up through DNS propagation as natural rollback.
>
> **The authoritative execution plan is `/home/ubuntu/projects/cutover/CUTOVER-PLAN.md` (v0.3, 12-step blue-green).**
> Killed at launch: CF cache-purge (natural miss post-swing), user-visible
> comms (DNS swing is the only event), DNS-01 cert (HTTP-01 post-swing — no
> CF token). On-box postgres confirmed (migrate to RDS later if mobile load
> demands).
>
> The numbered list below is retained as the **dependency ordering** (what
> must exist before what) — NOT as the execution runbook. As of this
> session, steps 1–5 of the dependency chain are ✅ (`/whoami`, archive-poc
> gating, shared header all shipped on dev). What remains is migration
> scripts + dormant smoke + lg-shell modals, then the new-box build.

> **🔒 AUTH INVARIANT (2026-05-29, Ian):** Cutover ships ONLY dev-proven auth.
> **No first-time identity/auth changes on cut day.** Whatever auth model we cut
> over to must already be running and tested on dev before the flip. Corollary:
> the login-*authority* inversion (profile-app owning credentials, WP demoted to
> consumer) is a SEPARATE post-cut project with its own dev rehearsal — it does
> NOT ride the big cut.
>
> **xprofile wording fix:** the `migrate-from-xprofile.php` step is a *slim,
> non-clobbering data backfill* (field 1 → display_name, field 2 → business_name;
> location done separately in 2.75; socials via BATCH-06 backfill). It is NOT an
> "identity authority transfer" — earlier §4 phrasing oversold it. Identity DATA
> crib (dev-proven, run-once at cut) ≠ login AUTHORITY (deferred). Keep them separate.
>
> **In flight (pre-cut, dev-tested):** "mint looth_id at wp_login, retire the
> shim + per-page whoami loopback" — design doc commissioned from profile-app
> (lead) + lg-shell (`briefing-shim-replacement-design.md`). Issues our token at
> WP's login moment; WP still verifies passwords. Satisfies this invariant.

**profile-app cutover is the unifying event.** Templating fragmentation
between BB pages, lg-layout-v2 posts, and archive-poc has pushed this
from "slice 3 someday" to "the coordination event everything else keys
off of." Window: when ready, not scheduled.

Dependency order (NOT the execution runbook — see CUTOVER-PLAN.md v0.3):

**Cutover-day target architecture on live:** B-now/A-later (§2). Strangler
surfaces ship to live now with the Patreon adapter feeding `/whoami`.
Stripe poller ships dormant in same cutover. Stripe-enable is a later
config change (add creds), not a deploy.

1. **Postgres provisioned on live** (on-box install matches dev; ops
   simplicity). `profile_app`, `forums`, and `discovery` schemas all
   created here (§3i). One server, three schemas.
2. **`/whoami` ships in profile-app on dev.** Pre-req for everything
   else. Born in profile-app, not the poller. Returns identity (from
   profile-app's Postgres post-cutover) + tier (read from WP roles via
   poller on dev; via Patreon adapter on live).
3. **archive-poc migrates SQLite → postgres** (`discovery` schema).
   pgloader run in seconds. Application code updated to use postgres
   PDO; nginx route unchanged.
4. **BB-mirror migrates SQLite → postgres** (`forums` schema). Same
   pattern. Schema extended with `reply` + `attachment` tables for
   image and threading support.
5. **archive-poc switches from cookie-only to `/whoami`-backed** for
   any gate decision more sensitive than first-paint. `lg_tier` cookie
   stays as a first-paint hint only.
3. **Shared header partial** included by BB-theme replacement,
   lg-layout-v2, and archive-poc. Solves the visual-fragmentation
   problem at cutover without depending on full BB removal.
4. **profile-app slice 3 cutover** — runs `bin/migrate-from-xprofile.php`.
   This is the moment WP stops being identity authority. Reskinned BB
   group pages, archive-poc, lg-layout-v2 all pointing at `/whoami`
   before this fires.
5. **BB-mirror first read** — only after profile-app cutover so it can
   read identity from profile-app + tier from `/whoami` without ever
   touching xprofile directly.
6. **Post-cutover cleanup** — see §3d roadmap (delete vestigial groups,
   reskin remaining BB surfaces, eventually strangler-replace groups).
7. **Poller role-shape changes** (if any) — last. Every consumer reads
   through `/whoami`, so role renames become a one-place change.

---

## 5. "Who depends on whom" — at a glance

```
                    ┌──────────────────────────┐
                    │  lg-patreon-stripe-poller│
                    │   (Arbiter, sole writer  │
                    │    of looth1..4 roles)   │
                    └────────────┬─────────────┘
                                 │ writes roles
                                 ▼
                    ┌──────────────────────────┐
                    │     WordPress core       │
                    │   wp_users + wp_caps     │
                    └────────────┬─────────────┘
                                 │ reads
                                 ▼
                    ┌──────────────────────────┐
                    │  /wp-json/looth/v1/whoami│  ◄── single canonical
                    │  (identity + tier + caps)│      contract
                    └────┬────────┬─────────┬──┘
                         │        │         │
              ┌──────────┘        │         └───────────┐
              ▼                   ▼                     ▼
     ┌────────────────┐  ┌─────────────────┐  ┌──────────────────┐
     │  archive-poc   │  │   profile-app   │  │   BB-mirror      │
     │  (Postgres+PHP)│  │   (Postgres+JWT)│  │   (Postgres+PHP) │
     └────────────────┘  └─────────────────┘  └──────────────────┘

     lg-layout-v2 runs inside WP — reads tier from $current_user directly,
     does not need /whoami. But the gate-tier values it checks against
     (public/lite/pro) MUST match this table.
```

---

## 6. Open questions for each chat

**Stripe-poller chat:**
- Will you write the `/whoami` endpoint, or should it live in its own
  mu-plugin? (Poller owns the tier truth; reasonable home.)
- Arbiter invalidation hook for the `/whoami` Redis cache — agree to add it?

**profile-app chat:**
- Confirm profile-app does NOT store tier locally — always reads via
  `/whoami` (or carries it in JWT claims, refreshed every N min).
- Confirm cutover timing constraint with BB-mirror plans.

**BB-mirror chat:**
- Read identity from `/whoami` + profile-app, not from BB directly.
- Confirm `public | lite | pro` is enough for forum-read gating, or
  flag if you need looth4-vs-looth3 distinction (probably don't).

**archive-poc:**
- Switch admin-edit gate from `lg_edit_capable` cookie plan to
  `capabilities.edit_archive_poc` from `/whoami` — already noted as
  the cleaner option in the FE-editor handoff.
