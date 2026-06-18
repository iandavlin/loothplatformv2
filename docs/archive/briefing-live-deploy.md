# Live-deployment / cutover lane — charter (2026-06-09)

You own **getting the strangler stack onto LIVE** — the cut from current-live (BuddyBoss-era) to the
new stack (archive-poc / bb-mirror / profile-app + the lg-* plugins). Your specialty is the **"doesn't
ride a git pull" layer**: the DB state, `/etc`, secrets, grants, DDL, ownership, and loose files that a
`git pull` + search-replace will NOT carry. You assemble the runbook and produce the **bash Ian runs on
live** — you do not push to live yourself.

## The model (Ian's plan)
**Clone live → run the whole cut on the clone (dev2) → flip traffic → old-live stays untouched = instant
rollback.** Rehearse on the fork, not on production.

## Access constraints (hard)
- **The coordinator's deploy key is NOT authorized on live** (`claude-deploy@…` → permission denied). Ian
  holds the live keys **under wraps**. **Working model: you write bash, Ian runs it on live, pastes output
  back.** Never assume you can reach live directly.
- **dev↔live channel = GitHub** (`origin = iandavlin/looth-platform`); both boxes pull `main`. Plus
  public-HTTP zips in `/var/www/dev/.well-known/`. No box-to-box keys, by design.
- You ARE `ubuntu` on the **dev** box (sudo, superuser psql) — so you can build/verify the runbook against
  dev's clone of the data, then translate it to live commands for Ian.

## Sanity-check the box
`curl -s ifconfig.me` → `50.19.198.38` = you're on DEV, act locally, do NOT SSH. `whoami` → `ubuntu`.

## Companion docs (read in order)
1. **`docs/cutover-keep-list.md`** — the plugin/snippet/app keep-vs-drop checklist (Ian marks it).
2. **`docs/CHATS-MENU.md`** → the "Coordinator-held / awaiting Ian" cutover bullets — the authoritative
   running list of re-apply items (mirrored + expanded below).
3. **`docs/STRANGLER-COORDINATION.md`** — the durable contracts (identity source, /whoami, tiers).

## THE CUT-DAY "DOESN'T RIDE GIT" CHECKLIST (assemble into a sequenced runbook)

### A. Database — DDL / extensions / grants (re-apply on the cut DB; search-replace won't add these)
- [ ] `profile-app` DB: `sql/2026-06-08-discussion-visibility.sql` → `users.discussion_visibility`
      (text NOT NULL default `member`, CHECK public|member). **Apply WITH the code push** — whoami/users
      hard-reference the column. Value is singular `member` (load-bearing).
- [ ] `forums` schema: `ALTER TABLE forums.person ADD COLUMN discussion_visibility …`; `ALTER TABLE
      forums.topic/reply ADD COLUMN is_anon BOOLEAN`.
- [ ] `discovery` schema: `ALTER TABLE discovery.comments ADD COLUMN IF NOT EXISTS edited_at`.
- [ ] `CREATE EXTENSION pg_trgm` + 4 GIN trigram indexes (`forums.topic.title`/`author_name`,
      `discovery.content_item.title`/`author_name`).
- [ ] GRANT list: `GRANT SELECT ON discovery.comments TO "bb-mirror"` (+ the `content_item` grant); audit
      every cross-role SELECT/UPDATE the apps need (WP-pool UPDATE on `comments`, etc.).
- [ ] Postgres **peer→password DSNs** — dev uses peer auth; live needs password DSNs in each app config.

### B. nginx (`/etc`, not in repo)
- [ ] `strangler-archive-poc.conf` — comment-delete/edit `location =` blocks + clean-URL rewrites.
- [ ] `strangler-profile-app.conf` — the `discussion-visibility` rewrite + the `/me` authed regex group.
- [ ] `strangler-bb-mirror.conf` — `/bb-mirror-api/v0/` routes (auth/reply loopback-vs-browser split).
- [ ] The whole strangler routing set + the cookie-gate block (token, claim, exempt paths).

### C. Code-snippets (DB-stored in `wp_snippets` — NOT in git)
- [ ] 8 connected snippets already folded into `lg-snippets/` (in git) + their DB copies deactivated.
- [ ] **~28 still active in the DB** — triaged (see chat): ~7 fold into `lg-snippets`, ~10 drop
      (Elementor/Woo/loop era), ~11 await Ian's keep/drop call. Fold the keepers; export/import or leave
      the rest per the keep-list.
- [ ] **Plugin active/inactive STATE is DB** — the fork's search-replace won't change which plugins are
      active. Set it explicitly on the cut DB per `cutover-keep-list.md`.

### D. Loose `/var/www` files (deployed, some not in git)
- [ ] `mobile-hub.css` + `mobile-hub.js` — **still box-only** (queued to Buck to commit). Until then,
      carry them by hand.
- [ ] Deployed mu-plugins: `bb-mirror-sync.php` (source in `bb-mirror/deploy/`, live copy is an artifact) —
      redeploy + confirm it captures `_lg_anon` on `bbp_new_topic`/`bbp_new_reply`.
- [ ] `pwa.js`, `sw.js`, `app-mobile-fixes.js`, `hub-polish.js` — in git; confirm versions/`?v=` refs.

### E. Secrets (`/etc`) — re-apply, never in repo
- [ ] Stripe / Patreon OAuth / VAPID (web-push) / JWT / HMAC / the WP↔profile-app bridge secret.

### F. Post-import "re-arm /whoami" (a DB import breaks tier 4 ways — see memory)
- [ ] Reactivate the **poller** (a reload deactivates it).
- [ ] Restore `lgms_db_*` creds (wiped by reload).
- [ ] BB REST gate re-arms → re-open; bridge gaps → re-bridge.
- [ ] `siteurl`→live + cache flush FIRST (logout/login bounce), then sessions re-login.
- [ ] Full **person-resync** (bb-mirror person rows key on recyclable WP IDs → stale names after reload).

### G. Ownership + storage
- [ ] Collapse strangler file ownership to generic **www-data** on live (resolve peer→password DSNs first).
- [ ] R2 / rclone → point at the **LIVE** bucket (dev's token is dev-scoped + IP-locked).

## How you work
- Build + verify each step against **dev's** data first (you have sudo + superuser psql here), then emit the
  **live** version as copy-paste bash for Ian. Label clearly which box each block runs on.
- Cautious + reversible: this is production. Snapshot/backup before destructive steps; keep old-live as the
  rollback. Surface anything irreversible to Ian before he runs it.
- No silent changes to canonical lane code — if a gap needs a code fix, route it to the owning lane via the
  coordinator.

## Report back (to coordinator / Ian)
`RUNBOOK-SECTION · COMMANDS (which box) · VERIFIED-ON-DEV · NEEDS-IAN-ON-LIVE · BLOCKED`. Report your
session ID + outliner title for CHATS-MENU + lineage.
