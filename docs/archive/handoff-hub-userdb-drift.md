# Handoff — Hub (bb-mirror) user-DB drift + lost functionality

**Paste this into a fresh chat. You are the hub / bb-mirror investigation lane.**
Two symptoms Ian flagged 2026-06-03; diagnose root cause BEFORE any fix.

## Symptoms
1. **Name drift.** On a forum thread on the hub ("Help needed to pinpoint my mistakes
   please!" — REPAIR AND RESTORATION › ACOUSTIC REPAIR, started 7d ago), the thread-starter
   renders as just **"T"** (a single initial). They had a **full name before.** Other users
   in the SAME thread render full names fine (Jay Daniels, Murray FixingGuitars, John Lehmann,
   Ian B Davlin). So it's **one (or some) users' names that drifted — not all.**
2. **Lost hub functionality.** "We lost a bunch of functionality on hub." Not yet specified —
   **enumerate with Ian first** (what exactly is missing/broken), then bisect.

## What the hub is
**bb-mirror** = the community/forum surface (BuddyBoss/bbPress-derived). This session the hub
was lean-mirrored onto the archive-poc front, and `bb-mirror/web/_chrome.php` was converged in
"header Step 1" to source viewer identity from `/whoami` (display_name/avatar/tier verbatim,
dropping the old JWT-claim/lg_tier path). Forum *thread author* names are a separate render path
from the *viewer* chrome — trace which the "T" comes from.

## Where a user's name can live (the drift is in ONE of these — check all)
- **WP:** `wp_users.display_name`; `wp_usermeta` `first_name`/`last_name`/`nickname`.
- **BuddyBoss xprofile:** `wp_bp_xprofile_data` for `field_id = 1` (the "Name" field). BB/bbPress
  frequently renders THIS, not `display_name` — a prime suspect.
- **profile-app:** `users.display_name` (bridged by `uuid` ↔ `wp_user_id`; full backfill is a
  CUTOVER job — only fixtures bridged on dev).
- bb-mirror's forum-author render reads ONE of these (or a mirrored/cached copy). Find which.

## THE key question: data drift vs rendering bug
"T" = a single initial → bb-mirror is almost certainly **falling back to a first-initial because
the name field it reads is EMPTY** for that user. So determine:
- Is the user's name **actually missing/wiped in the DB** (data drift), or
- Is the name **present in one field but bb-mirror reads a different, empty one** (rendering bug)?

Query that user's name across ALL sources directly and compare. (Steps below.)

## Prime hypotheses (rank by what the queries show)
1. **CF DB reload (6/2) carried the name incompletely.** The reload replaced the WP DB; this
   user's `display_name` and/or xprofile `field_id=1` may have come back empty. Partial (some
   users) fits a reload/migration that didn't carry every name. See [[project_cf_reload_whoami_casualties]],
   [[feedback_db_reload_stale_sessions]].
2. **BB→pg / identity-snapshot literal gap.** If names were snapshotted source→target and this
   user's source was empty/odd, the literal copy preserved the emptiness — per the rule, don't
   derive/enrich. See [[feedback_migration_escape_hatch]].
3. **bb-mirror reads the wrong field** (e.g. xprofile field 1 when the name actually lives in
   `display_name`, or vice-versa) — a pure render bug, fixable in-lane without touching data.

## Investigation plan
1. **Identify the "T" user:** find the bbPress topic by title; get its author `wp_user_id`.
   `sudo -u www-data wp --path=/var/www/dev post list --post_type=topic --s="Help needed to pinpoint" --field=post_author` (or query the bbPress tables).
2. **Query that user's name everywhere:** `wp_users.display_name`, `wp_usermeta` (first/last/nickname),
   `wp_bp_xprofile_data` field_id=1, and the profile-app `users` row (by wp_user_id bridge). Compare —
   which are populated, which empty?
3. **Trace bb-mirror's author-name resolution:** which field does the forum render read? → confirms
   data-drift vs render-bug.
4. **Scope it:** how many users have an empty name in the field bb-mirror reads? One, or a batch
   from the reload? (`COUNT(*) WHERE display_name='' OR ...`).
5. **Lost functionality:** get Ian's specific list, then diff recent hub/bb-mirror changes (the
   header Step 1 `_chrome.php` repoint, the whoami repoint, the DB reload) to bisect.

## Constraints
- **Dev = fixtures only.** Do NOT mass-backfill names on dev — if it's data drift, the fix is a
  CUTOVER-time backfill, not a dev bulk update. First DIAGNOSE (data vs render).
- bb-mirror code is this lane; **header/whoami/nginx are cross-cutting → coordinator** (don't touch
  the shared header/footer or /whoami).
- Leave changes uncommitted for review-before-push.

## Report back to coordinator
- Root cause: **data drift** (name gone in the DB) vs **render bug** (name present, hub reads wrong
  field) — with the per-source query results.
- Scope: one user or a batch (and how many).
- The lost-functionality list + each cause (esp. anything regressed by the recent hub changes).
- Fix (or, if it's a data backfill, the cutover-deferred plan).

## Linked context
[[project_profile_app_buck_lineage_divergence]] · [[project_cf_reload_whoami_casualties]] ·
[[feedback_migration_escape_hatch]] · [[feedback_db_reload_stale_sessions]] ·
[[project_whoami_shim_bootstrap_cost]] · docs/relay-header-convergence.md · docs/STRANGLER-COORDINATION.md
