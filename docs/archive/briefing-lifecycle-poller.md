# Lane briefing — poller: canonical user teardown + WP-dash nuke/tombstone

You're the **poller lane** for the user-lifecycle unification. Fresh chat. This is the bulk of
**Phase 1**.

## Read first (10 min)
1. This file.
2. `docs/USER-LIFECYCLE-AUDIT.md` — the full audit + plan. Read §2 (DELETE), §3 (gaps), §5 Phase 1-2,
   §6 (blast radius), and the file:line index. This is your spec.
3. `docs/STRANGLER-COORDINATION.md` §0 (commit discipline) + §1 (tiers) — skim.
4. Project `CLAUDE.md`.

## Why this exists
Three partial teardown tools exist and disagree (`MemberTools::doNuke` MemberTools.php:413,
`TestChecklist` wipe TestChecklist.php:226, `eraseBuddypressFootprint`), none is complete, none
tombstones, and the WP Users screen only does a WP-only delete that orphans everything else. Ian wants
**one** teardown with two modes, exposed where admins actually manage users.

## Decisions already made (Ian, 2026-06-04) — don't relitigate
- Real member removal = **tombstone** (keep their forum posts/comments, show "[deleted member]").
- Test wipe = **nuke** (erase the member AND everything they touched, incl. media).
- Both must be **actions on the WP Users dashboard**. **Nuke is the urgent one — build it first.**

## Deliverables

**1. `UserLifecycle::teardown($wpUserId, string $mode, bool $dryRun)`** — the one teardown path.
   Fold the existing `doNuke` + TestChecklist `wipeQueries` + `eraseBuddypressFootprint` into this so
   there is exactly ONE implementation. Keyed on **wp_user_id** (resolve customer_id via bridge
   internally). Full blast radius is in audit §6. Both modes: cancel Stripe subs; erase WP user +
   usermeta, all BuddyPress tables, all lg_membership rows, lg_role_sources, lg_patreon_members; call
   profile-app's erase endpoint (below); clean discovery (`discovery.person` + `content_item`).
   - **nuke:** also delete authored `wp_posts`/`wp_comments` + forum topics/replies + discovery rows.
     Refuse admins + user 1. This is the test path — build + prove first.
   - **tombstone:** instead of deleting authored content, **reassign** it to a sentinel
     "[deleted member]" WP user (create one stable sentinel acct on first use; reassign post_author /
     comment user_id / forum reply author → sentinel id; blank discovery author).
   - **dry_run:** return cross-store counts (call profile-app erase with `dry_run:true` too) so the
     dash can show a preview before the admin confirms.

**2. WP Users screen actions.** Per-row actions "Tombstone member" / "Nuke member" + a bulk action.
   `manage_options` only, nonce'd. JS confirm; nuke = type-to-confirm. Show the dry_run preview counts
   on the confirm step.

**3. `deleted_user` safety net.** `add_action('deleted_user', …)` → `teardown($id, 'nuke')` orphan
   fan-out, so even a native WP delete never orphans the other stores. (Content is already gone by
   then — this just cleans the cross-store rows.)

**4. Make the old tools thin wrappers** over `teardown()` (email-keyed wipe → resolve email→wp_id→
   teardown), or remove them, so there's no second teardown drifting.

## Cross-lane contract — you CALL this (profile-app lane is building it in parallel)
`POST https://127.0.0.1/profile-api/v0/internal/erase-user`
- Header: `X-LG-Internal-Auth: <LG_INTERNAL_SECRET>` (same secret/pattern as purge-whoami).
- Body: `{ "wp_user_id":int, "mode":"nuke"|"tombstone", "dry_run":bool }`
- Returns: `{ ok:true, deleted:{ users, profile_rows, social_rows, media_files } }` (idempotent;
  missing user → ok + zeros).
- `wp_remote_post`, short timeout. If it errors, **fail loud** in the teardown result (don't silently
  leave profile-app orphaned) — surface it in the dash so the admin knows the profile-app half didn't
  complete.

## Coordinator owns (not you)
- nginx localhost-only route for `/profile-api/v0/internal/erase-user`.
- confirming `/etc/lg-internal-secret` + `LG_INTERNAL_SECRET` are present.
- the joint before/after integration test + push.

## Verify before done (dev-complete AND dev-proven)
- **Nuke:** pick a throwaway user; capture row counts across WP + BP + lg_membership + profile-app +
  media + discovery before; nuke from the Users screen; show all are zero after.
- **Tombstone:** a member who authored a forum reply → after tombstone the reply still renders with
  "[deleted member]", but their identity rows + media are gone and billing is cancelled.
- Confirm nuke refuses an admin + user 1.

## Protocol
- **Burn in-lane.** Ping coordinator (via Ian) only for the cross-lane contract or an out-of-lane
  blocker (e.g. the erase endpoint not yet routed).
- **Commit by pathspec**, clean increments after tested change; coordinator reviews + pushes.
- **Report back:** `DONE · FILES (paths:lines) · VERIFIED (on dev, with counts) · CONTRACT (any
  deviation) · BLOCKED`.
