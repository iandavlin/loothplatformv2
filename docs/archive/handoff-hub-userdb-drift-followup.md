# Handoff — Hub (bb-mirror) user-DB drift — RESOLVED + follow-ups

**Paste this into a fresh chat. You are the hub / bb-mirror user-DB-drift lane.**
The diagnosis from the prior session is done; this brief carries the *resolution* +
the open follow-ups. Sanity-check the box first: `curl -s ifconfig.me` (→ 50.19.198.38
means you ARE the dev box, act locally with sudo, do NOT SSH).

## ROOT CAUSE (settled) — one mechanism, two faces
A **dev DB reload (~6/3, live-dump cutoff = newest `wp_users.user_registered` 2026-06-03
16:13:55)** reassigned/removed WP user IDs. bb-mirror's `forums.person` is keyed
`id = wp_users.ID` (a **recyclable** numeric ID) and literal-snapshots the name at sync
time (`bb-mirror/lib/materializers.php` `bb_mirror_person_for`, `bin/backfill.php:381`).
The incremental reconcile cron only re-syncs persons whose **posts** changed, so identity
changes on idle posts are never caught. Result:
- **Reused ID → wrong name:** a `tst-staple-1778082999` fixture ("T") held WP ID 1877 during
  the 6/2 21:00 full re-sync; the reload then made 1877 a real human ("Ron Stein Photography"),
  but person 1877 stayed "T". (Thread: "Help needed to pinpoint my mistakes please!", topic 71184.)
- **Dropped ID → nameless author:** 6 orphan author_ids (14, 63, 205, 766, 1330, 1509) reference
  WP users that **no longer exist** post-reload → `get_userdata()` null → empty `author_name` → nameless.
- The "~17:38 backfill" was NOT a mass re-sync — only 1–2 person rows (reconcile). That's *why* the
  drift persisted. `siteurl`/`home` already = dev → reload was completed/search-replaced, not mid-flight.

See [[project_bb_mirror_person_staleness]], [[project_cf_reload_whoami_casualties]],
[[feedback_db_reload_stale_sessions]].

## DONE this session
- **Data fix (applied to DB):** re-synced person 1877 through the canonical materializer →
  `forums.person` 1877 + topic 71184 + its replies now read "Ron Stein Photography". 0 stale
  `tst-staple`/1-char person rows remain; served `/hub/` page verified (HTTP 200, real name, no "T").
- **Guard (UNCOMMITTED — `M`, not in HEAD `d239cc8`):** `bb_mirror_person_for()` in
  `bb-mirror/lib/materializers.php` now refuses to overwrite a mirrored person with a
  `tst-staple-*` nicename or ≤1-char display_name; preserves the existing real row instead.
  Used by both `api/v0/_sync.php` and `bin/reconcile.php`. **Leave uncommitted** per Ian; needs
  review-before-push.
- **Symptom 2 ("lost reply/lightbox functionality"):** NOT a code regression or data loss on dev —
  code intact, data rich (1262 topics / 4900 replies / 1721 attachments), forums.js served 200,
  `?replies=` returns nested HTML. The 5-rows-per-page reply pagination fix shipped (`34a61bf`),
  verified. If Ian still sees it, it's on **live** (deploy lag) — confirm env.

## Follow-up status (Ian decisions, 6/4)
1. **Guard — DONE, committed `9778151` (local, NOT pushed).** Held for review-before-push.
2. **Nameless orphans (6 IDs) — WONTFIX, leave as-is.** Ian deleted those users on live, so they're
   gone by design; nameless is correct. No dev/cutover backfill needed for them.
3. **Reload checklist — DONE.** Added "run a FULL bb-mirror person backfill after every reload" as item 6
   of [[project_cf_reload_whoami_casualties]] (incremental reconcile can't catch reload ID churn).
4. **Logged-out header — handed off to lg-shell.** Paste-ready brief at
   `docs/briefing-loggedout-header-lgshell.md` (it's a /whoami reload-casualty, header is consumer-only).
5. **Long-term identity key = EMAIL** (Ian: "email is really our main identifier"). Re-key `forums.person`
   on email, not the recyclable wp_user_id — aligns with the profile-app email-keyed UUIDv5 bridge.
   Coordinator/architecture call. Recorded in [[project_bb_mirror_person_staleness]].

## Constraints
- **Dev = fixtures only.** No mass name backfill on dev — that's a cutover job.
- bb-mirror code is this lane; **header / whoami / nginx are cross-cutting → coordinator.**
- **Leave changes uncommitted** for review-before-push.

## Quick-ref
- DB: postgres `looth`, schema `forums` (`sudo -u postgres psql -d looth`; `SET search_path=forums,public;`).
- Name source: `bb_mirror_person_for()` reads `wp_users.display_name`/`user_nicename` (NOT xprofile).
- Find a thread's author: `wp --path=/var/www/dev post list --post_type=topic --s="<title>" --field=post_author`.
- Orphan check: `SELECT DISTINCT author_id FROM topic/reply LEFT JOIN person … WHERE person.id IS NULL`.
- `/hub/` served by bb-mirror front controller (FPM `php8.3-fpm-bb-mirror.sock`), gate-cookied; mint via `/claim?t=<token>`.
- Files: `bb-mirror/lib/materializers.php` (person upsert+guard), `bin/{backfill,reconcile}.php`,
  `web/forums/{_feed,_topic-replies,_single-topic}.php`, `web/forums.js`. Master spec: `docs/HUB-EXPECTED-BEHAVIOR.md`.
