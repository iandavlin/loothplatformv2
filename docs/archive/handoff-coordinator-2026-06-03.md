# Coordinator handoff — 2026-06-03

Supersedes `handoff-coordinator-2026-06-02.md`. This session was dominated by a **full WP DB reset from CF backup** and standing up the **video-conversion pipeline**. Read the four "critical state" items first.

## ⚠ CRITICAL STATE — read before touching anything

1. **WP DB was wiped + reloaded from CF backup.** `looth_import` is now a fresh restore of `db/backup_wp_loothgroup_20260602_1500.sql` (the 6/2 15:00 dump). All prior dev conversion experiments are GONE (intended). Source: CF bucket `loothgroup-backups` via rclone remote `cfbk` (creds in `~/.config/rclone/rclone.conf`). Local copy: `~/db-import/backup_wp_loothgroup_20260602_1500.sql`.
2. **Mail cap is ON and must stay on while on live data.** iptables OUTBOUND DROP on 25/465/587 is armed (in-memory — won't survive reboot, re-add if rebooted). `fluent-crm` is *installed but inactive* in the dump (only `fluentform`/`fluentformpro` active — forms, not the bulk mailer). Do NOT activate FluentCRM/SMTP.
3. **Nothing is pushed.** Everything is local on `main`. Many uncommitted changes (render.php, callout shell.css, the parser, Buck's merges). Present commits + diffstat before any push (standing rule).
4. **CF S3 creds + a CF API token were pasted in chat** (bucket `loothgroup-backups`). Rotate them — the message said they won't be shown again.
5. **Logged-in dev sessions from BEFORE the reload are STALE.** The reload replaced `wp_usermeta` (incl `session_tokens`), so any pre-reload WP login cookie no longer validates → WP treats you as logged-OUT: Comments say "must be logged in", `/profile-api/v0/whoami` returns `authenticated:false`, gated content walls you — even though the nav still shows your name (driven by the still-valid `looth_id` JWT, not the WP session). One root cause, several weird symptoms. **Fix: log out + back into WP** (writes a fresh session into the reloaded user table, re-mints `looth_id`). The whoami PLUMBING is fine — verified: `/looth/auth/issue|refresh` routes present, JWT private+public keys intact, auth mu-plugins load regardless of active_plugins.

## What the DB reset did
- **Reloaded** `looth_import` from CF (341 videos, 1801 users, siteurl `dev.loothgroup.com`).
- **Kept untouched (by decision):** `profile_app` (1815 users/666 profiles) and `lg_membership` (Stripe = test/QA fixtures; the 1527 `lg_patreon_members` look like a real roster — NOT in any backup, so don't wipe it).
- **Wiped the derived stores clean**, then **dropped the dead `discovery` migration tables** — `discovery` now holds ONLY `article_blobs` (the live CPT render store). The dead SQLite→PG dup (`discovery.content_item/content_tag/tag/person`) is gone; `backfill-pg.php` is retired (nothing reads PG content_item — search reads the SQLite copy). See [[project_discovery_pg_migration]].
- **Reindexed** the archive SQLite from fresh WP: 1957 content_items incl **1258 discussions** (the archive/feed is repopulated and browsable; click → WP fallback render unless converted).
- **9 `_lg_layout_v2` layouts survived** the reload (5 post-imgcap + 4 video) — these are *live* conversions baked into the backup. OPEN DECISION: keep them or wipe for a pure zero-conversion slate.

## The video-conversion pipeline (the active work)
**Goal:** convert 341 video posts (`post-type-videos`) → v2 layouts → fast standalone render. Doing it **one-at-a-time** for now (user's call), not bulk.

- **Parser:** `tools/video-parse.php` — deterministic, **no AI**. Reads `post_content` (the pasted YouTube description) → emits a v2 layout (post-header, embed, wysiwyg desc, Chapters callout w/ `?t=` deep-links, `Label:`-grouped link callouts, post-footer). Stamps `_meta.importer="video-parse/1"`, `schema:1`. Has a **skip-if-already-converted** guard.
  - Run one (dry-run, writes JSON to `/tmp/lg-parse-<id>.json`): `sudo -u www-data env LG_PARSE_POST=<id> wp --path=/var/www/dev eval-file tools/video-parse.php`
  - The tidbits live in WP `post_content` (descriptions were pasted in). **YouTube is bot-walled from this AWS IP** (yt-dlp + WebFetch both fail) — so the parser works off WP content, not YouTube. ~175 video posts have rich content (chapters/links); ~135 are URL-only (would get an embed-only layout).
- **Per-post workflow:** parse (review) → `wp lg-layout-v2 import --post-id=<id> --file=<json>` → materialize → verify.
- **Materialize a post's blob:** `curl -s -X POST https://dev.loothgroup.com/archive-api/v0/_materialize --resolve dev.loothgroup.com:443:127.0.0.1 -k -H 'Content-Type: application/json' -d '{"post_id":<id>,"action":"upsert"}'`
- **Done so far:** 71302 (3D Club — already had a layout, just baked), 70899 ("Where Does the Sound…" — first real parser run; shook out 2 parser bugs, now fully converted + rendering).
- **Versioning settled:** keep `_lg_layout_v2` — the parser emits the SAME format; "standalone" is a render path, not a format. Provenance via the `_meta.importer` stamp. No v3/v2standalone.
- **Tier gating is automatic by post tier** — a looth-lite post gates its money-shot video for non-members; public doesn't. Parser needs NO gating logic.

## Standalone-renderer fixes this session (all systemic → every converted post)
All in `archive-poc/standalone/render.php` + the callout `shell.css` (both the `engine/blocks/...` copy AND `lg-layout-v2/blocks/...` source):
1. **Video facade play JS** — ported `lg-front.js`'s click-to-play handler into the standalone page shell (the standalone never loaded the WP-plugin asset, so no video could play).
2. **Admin gate-bypass** — `lg_standalone_build_viewer` hardcoded `is_admin=false`; now resolves from whoami `capabilities.edit_archive_poc`. Admins bypass tier gates (preview badge) instead of being walled out.
3. **Chapter-link styling** — `.lg-ts-link` had no CSS anywhere (browser-default blue). Added a rule consuming the dash var `--lg-link-color` (sage `#6b7c52` fallback) → on-brand AND dash-adjustable.
4. **Container width** — `.lg-standalone-main` hardcoded `max-width:760px` overrode the dash's `--lg-article-max` (the engine's `.lg-article` is the real, dash-controlled, self-centering container). Removed the clamp.
5. **Band above banner** — `.lg-standalone-main` top padding left a 24px strip above the full-bleed hero. Set `padding-block: 0 64px` → hero flush to nav (verified gap 0).

NB: render.php CSS is served via a content-hashed bundle that auto-regenerates on shell.css change; opcache `validate_timestamps=On` (2s) so PHP edits go live without restart. The `engine/blocks/*` files are `archive-poc`-owned → edit with sudo.

## Buck's queue (profile-app lane) — canonical HEAD now `d3ff3c0`
LANDED: caddy-used-flag-fix (CRITICAL palette regression), applyrole-guard, section-icons, view-as-gap, member-map SQL (file only — **migration not applied to PG**, open Q), caddy-toggle-label, the builder reskin + my freeform-delete reconciliation, and **buck/profile-dropoffs (`d3ff3c0`)** — Buck rebased it onto canonical; I merged + wired the `/me/dropoffs` nginx rewrite + allowlist in BOTH `strangler-profile-app.conf` and `preview-buck-profile-app.conf`.
- **Bug fixed while wiring dropoffs:** `me-banner`/`me-resume`/`me-freeform` were all nginx-**403'd** — their rewrites existed but they were never added to the `/me/*.php` auth-gated allowlist alternation when those branches merged, so those save-endpoints were DEAD. Added all four (incl dropoffs) to the allowlist in both confs; verified they reach PHP now. (This was part of "profile in a terrible state.")
- STILL BOUNCED (needs rebase): **buck/profile-css-polish**.
- Briefings: **`docs/briefing-buck-coord.md`** (Buck merges) and **`docs/briefing-conversion-coord.md`** (the video-conversion lane — paste into a fresh chat to run conversions). See [[project_profile_app_buck_lineage_divergence]].

## Open decisions / TODO
- The **9 surviving live layouts** — keep or wipe?
- **Bulk vs one-at-a-time** conversion of the remaining ~340 videos (currently one-at-a-time).
- Apply **`2026-06-01-location-legacy-defaults.sql`** to `profile_app` PG? (in repo, not run)
- **Double-title hero** on title-card thumbnails (image has title baked in + hero overlays another) — wants a no-overlay hero variant? Per-post/product call.
- **Two unexplained reboots** (from prior handoff) — still not root-caused; relevant before piling load on one box.
- **Rotate the CF creds.**
- **Commit the uncommitted work** (render.php, shell.css, parser, Buck merges) when reviewed.

## Architecture reference
Ground-truth serving + DB diagrams (cookie-gated): `https://dev.loothgroup.com/mockups/arch-audit/` (repo copy `docs/arch-audit/`). The split-brain (events/profile-app/billing on stale `looth_dev`) was fixed this session — all repointed to `looth_import`. `looth_dev` (old fixture, 18k posts) is now vestigial/droppable.
