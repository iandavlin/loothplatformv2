# Strangler Coordinator — Handoff

You're the coordinator. Project chats build in their lanes. Ian is the bus. You
hold the contract (`STRANGLER-COORDINATION.md`) + the docs + routing. You do NOT
make live changes; you capture decisions, write relays, wire dev nginx (you're
also box sysadmin `ubuntu`).

**Read this for the orient. Prior snapshot: `strangler-handoffs/2026-05-31-evening.md`.**

---

## LATEST — 2026-06-01 session (game-day functionality + housekeeping)

Tree is **clean + pushed to origin/main**. All lane work committed. One dirty file:
`profile-app/web/directory-members.php` (map chat in progress — leave alone).

### What shipped this session (all committed, verified, live on dev)

**Forum → "The Hub" rebrand (COMPLETE)**
- `/hub/` is the canonical forum URL. `LG_BB_MIRROR_PUBLIC_PATH='/hub'` in bb-mirror.
- 301s: `/forum/`, `/forums/`, `/forums-poc/` → `/hub/` (nginx `51a15ec`).
- bb-mirror chrome + labels: "Forum"/"Forums" → "The Hub", `active_nav='hub'`.
- archive-poc: 1169 stored URLs flipped `/forum/` → `/hub/`; all rails emit `/hub/`.
- lg-shell: header nav + footer "The Hub" → `/hub/`. `ec8a5a5`.
- Verified: `/hub/` 200, title "The Hub — Looth Group", 0 stray `/forum/` links.

**`/manage-subscription/` standalone (COMPLETE, DEPLOYED)**
- Read-only Patreon membership view (poller DB direct PDO). Anon → sign-in card;
  member → Patreon tier/status; admin → Patreon + Stripe iframe (`/__lg-stripe-panel/`).
- Clickjacking headers on the stripe panel (`X-Frame-Options: SAMEORIGIN` + CSP).
- nginx route live. WP fallback intact. Committed `f7ca461`.
- mu-plugin mirror committed to `platform/mu-plugins/` (`ddbe50a`).

**Social modals (lg-shell) — FIXED + VERIFIED**
- `social-modals.js` rebuilt against real endpoint shapes (was guessing stale paths).
- All endpoints correct: `/me/social-counts/`, `/me/connections/`, `/me/messages/`, `/me/notifications/`.
- Notifications: user-controlled mark-read (no auto-clear on open); bell = connection events only.
- Message button on connections dispatches `lg:open-dm`. Search in connections modal.
- Mirror in `lg-shell/lg-shared/` — versioned. `6e6245f`.
- **Unified modal ticket pending (shell):** Messages + Connections → one tabbed modal.
  Relay: `docs/relay-to-shell-unified-social-modal.md`.

**Footer cleanup (COMPLETE)**
- Removed BB-themed links: Membership (`/lgjoin/`), Billing & Refund (`/request-refund/`), Shops.
- `/members/` → `/directory/members/` (pending shell nav ticket).
- Privacy + Terms → loothtool.com (already done). `c1457ca`, `9e72dff`.

**Poller — CUTOVER READY**
- `/membership-guide/` ✅ + `/manage-subscription/` ✅ standalone.
- P4 (`LG_PROFILE_APP_URL`) ✅, P8 (dormant-mode smoke) ✅.
- 8 remaining money pages: **Stripe-A-later** (not launch-blocking).
- Nonce-strategy (Q1) still open — gates the form-heavy pages, not needed at cut.

**lg-shell — My Profile fix**
- "My Profile" → `$profile_url` (= `/u/<slug>`) — the new profile page, not the legacy editor.
- `$ctx` doc correction: `profile_url` = public `/u/` profile (consumers were right).
- Relay: `docs/relay-to-shell-profile-url-doc.md`.

**Social layer — message-notif removed**
- `Messaging::insertMessage()` no longer pushes `message`-type notifications.
- DMs → message badge only; bell = connection events only. Committed `5697e3e`.

**Cutover plan — step 7h added**
- Bulk-set `location_visibility='members'` + `location_pin_precision='city'` for existing
  members at cut (where both are still at old defaults). `6d9e7f3`.

**Profile-app schema default relay (pending)**
- New members: `location_visibility` default → `'members'`, `location_pin_precision` → `'city'`.
- Relay written: `docs/relay-to-profile-app-location-default.md`. Not yet applied.

### Next session — priority order

**1. nginx catch-all for CPT renderer — ✅ DONE (`f6c9457`, 2026-06-01)**
Collapsed the 7 type-equals-segment permalink blocks (post-imgcap, post-type-videos,
sponsor-post, loothprint, loothcuts, useful_links, member-benefit) into ONE regex
that captures `post_type` as `$1`, `slug` as `$2`. Net −58 lines.
- **Deviation from the planned wide-open regex:** used an EXPLICIT type alternation,
  NOT `^/([\w-]+)/([\w-]+)/?$`. An open two-segment regex would shadow extensionless
  WP core paths — e.g. `/wp-json/lg-member-sync` — because a regex location beats the
  `location /` prefix WP falls through to. Verified post-cut: wp-json still hits WP
  REST (`rest_no_route`), `/u/<slug>` still hits profile-app.
- Onboarding a new CPT (sponsor-page, sponsor-product, …) is now a **one-word add** to
  the alternation — not automatic, by design (each needs blob coverage + a real permalink).
- `error_log()` on blob-miss added to render.php; verified emitting + WP fallback intact.
- Friendly aliases (`/article`,`/video`,`/sponsor`) + `/document/<id>` stay explicit.
- Deployed to `/etc/nginx/snippets/strangler-archive-poc.conf` + repo source-of-truth
  `archive-poc/nginx-snippet.conf` (kept identical); backup `.bak.20260601-170056`.

**2. Lanes with open tickets (hand to their chats)**
- **lg-shell:** unified Messages+Connections tabbed modal (`relay-to-shell-unified-social-modal.md`)
  + My Profile fix (`relay-to-shell-profile-url-doc.md`) + nav-to-loothtool (`relay-to-shell-nav-loothtool.md`).
- **profile-app:** location default change (`relay-to-profile-app-location-default.md`)
  + `?wp_ids=` endpoint for author bio (`relay-to-profile-app-users-wpids.md`).
- **map chat:** `profile-app/web/directory-members.php` in progress (leave alone).
- **editor chat:** profile page gap between View-as bar + header card (profile-app CSS, not shell).

**3. Sponsor content conversion (authoring work)**
- sponsor-post: 1/13 have v2 layouts. Use `write-article-v2` skill to convert the rest.
- sponsor-page (5), sponsor-product (16): 0 blobs, not in materializer's managed CPT list yet.
- `/sponsors/` listing page: already standalone ✅.

**4. Standalone launch inventory (remaining builds)**
See `docs/standalone-launch-inventory.md` for the full list. Key remaining:
- `docs/relay-to-standalone-launch-batch.md` — calendar/sponsors/about, video→WP fallback, weekly-email archive.
- Archive-poc sidebar: remove "Add Forum Post" + "Member Map"; add "Report a Bug" (modal with form); update "Weekly Email" link.

### Architecture notes (from audit this session)
- ~~**Biggest dumb thing:** 9 identical nginx CPT blocks~~ → ✅ fixed (catch-all, `f6c9457`).
- **Now #1:** 3 separate whoami implementations → post-cutover cleanup (not worth mid-migration).
- ~~blob-miss fallback is silent~~ → ✅ fixed (one `error_log()` line in render.php, `f6c9457`).
- `/tmp` activity cache, host constants, dead bb-mirror files → LOW, ignore for now.

### Ops reminders
- **Commit by pathspec always** — shared tree, multiple lanes.
- **Resume UUID gotcha:** `claude --resume` with `--print` needs full UUID, not short id.
- **idle-hold:** `touch /tmp/no-idle-shutdown` before a lane turn, `rm` after.
- **Never two profile-app turns at once** — map + editor chats are currently both live.
- **nginx snippets:** repo copy + deployed copy can drift. Always diff before deploying.
- **`/srv/lg-shared/*`** is www-data-owned, NOT in git. Mirror to `lg-shell/lg-shared/` after every edit.

### 2nd dev onboarded — buck (2026-06-01)
- **Lanes:** **profile** (`web/u.php`,`p.php`,`_render_blocks.php`, `me-*` endpoints) +
  **member map** (`web/directory-members.php`,`api/v0/directory-members.php`). Reassigns
  the prior coordinator-run map+editor profile-app work to buck — wind those down / hand
  to him; never two profile-app turns at once.
- **Model:** git-native per §0e. buck works in `~buck/looth-platform` (origin = canonical
  tree), branches + commits; coordinator fetches, reviews, merges, pushes, deploys. No
  GitHub creds for buck.
- **Preview:** `https://buck.dev.loothgroup.com/{directory/members/,u/<slug>,p/<slug>}`
  serves his clone's `web/` via the profile-app pool (`preview-buck-profile-app.conf`
  included in his vhost). `src/`+`vendor/` from the shared tree → src changes need a merge.
- **Escalation:** no sudo. sudo queue `/srv/lg-sudo-queue/REQUESTS.md` (watcher devmsgs
  the coordinator). chrome-dev: passwordless `systemctl restart chrome-dev.service` for
  `%loothdevs`. Bootstrap in `~buck/.claude/CLAUDE.md` + `~buck/sudo-queue-README.md`.
- **Landed this session (main):** `looth_id` mint bounce on directory+profile
  (`f78b869`) + `*.dev.loothgroup.com`→dev env detection (`c7b2e0e`).

### Lane roster (current)
| Lane | Chat/Status |
|---|---|
| profile-app: profile + member map | **buck** (own clone) — assigned 2026-06-01. Prior **map + editor chats RETIRED 2026-06-01** (work committed, tree clean); buck owns these files now. First task: location-default. Editor's View-as/header-card CSS gap is his once he's clear of it. |
| lg-shell | `1d248347` — unified modal + My Profile + nav-loothtool queued |
| archive-poc/standalone | active — launch batch in flight |
| poller/membership | cutover-ready, idle |
| bb-mirror | idle (rebrand done) |
| social/profile-2.0 | active — `?wp_ids=` + location default pending |
