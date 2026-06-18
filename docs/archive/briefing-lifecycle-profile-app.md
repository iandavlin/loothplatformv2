# Lane briefing — profile-app: erase-user endpoint

You're the **profile-app lane** for the user-lifecycle unification. Fresh chat, narrow scope.

## Read first (5 min)
1. This file.
2. `docs/USER-LIFECYCLE-AUDIT.md` — the full audit + plan. You're building one piece of **Phase 1**.
3. `docs/STRANGLER-COORDINATION.md` §2 (whoami contract) + §0 (commit discipline) — skim.
4. Project `CLAUDE.md`.

## Why this exists
Today **nothing ever deletes the profile-app `users` row**, and no cleanup touches the avatar/banner
files on disk. So deleting a WP user orphans the entire profile-app identity + its media. The poller
lane is building one canonical teardown that fans out to every store; it needs an internal endpoint
from you to erase the profile-app half.

## Your deliverable — ONE endpoint
`POST /profile-api/v0/internal/erase-user`

**Contract (fixed by coordinator — build to this exactly; poller + the WP dash depend on it):**
- **Auth:** header `X-LG-Internal-Auth: <LG_INTERNAL_SECRET>`, verified with `hash_equals()`. Reject
  otherwise. Mirror `internal-purge-whoami.php`'s auth exactly. (Coordinator wires the nginx route
  localhost-only, same as purge-whoami — you don't touch nginx.)
- **Request body (JSON):** `{ "wp_user_id": int, "mode": "nuke"|"tombstone", "dry_run": bool }`
- **Action:** resolve the user via `wp_user_bridge` (wp_user_id → user_id/uuid). Delete the `users`
  row → the existing `ON DELETE CASCADE` FKs clear `email_aliases`, `wp_user_bridge`, `profiles`,
  `profile_sections/socials/instruments/skills/scenes/credentials/highlights/genres/services`,
  `connections`, `messages`, `message_recipients`, `notifications`, `practice_members`. **Verify the
  cascade actually covers all of these** — if any FK is missing `ON DELETE CASCADE`, either add it or
  delete that table explicitly in the endpoint. Then delete the on-disk media for that user under
  `/srv/profile-app-media/{avatars,banners,gallery,resumes}/` (confirm whether keyed by `uuid` or
  numeric id — check `WpMedia`/avatar serving — and nuke the right dir).
- **mode:** profile-app holds *identity*, not authored content, so **nuke and tombstone behave the
  same here** — full identity + media erase either way. Accept `mode` for logging/symmetry only.
- **dry_run:true:** return the counts you *would* delete, delete nothing. (The WP dash shows this as a
  pre-delete preview, like the TestChecklist preview.)
- **Idempotent:** unknown/missing user → `{ok:true}` with all-zero counts, **not** an error.
- **Response (JSON):** `{ "ok":true, "wp_user_id":N, "uuid":"…", "mode":"…",
  "deleted":{ "users":1, "profile_rows":N, "social_rows":N, "media_files":N } }` or
  `{ "ok":false, "error":"…" }`.

## Verify before you call it done (governing invariant: dev-complete AND dev-proven)
- Create a throwaway profile-app user (+ a fake avatar file), run `dry_run`, confirm counts.
- Run for real, confirm the `users` row + all cascaded rows + the media files are gone.
- Confirm a missing wp_user_id returns ok with zeros (idempotent).
- Confirm a bad/missing `X-LG-Internal-Auth` is rejected.

## Protocol
- **Burn in-lane.** Only ping coordinator (via Ian) for a cross-lane *contract* change — e.g. if the
  request/response shape above needs to differ. Otherwise just build it.
- **Commit by pathspec** (never `git add -A` — shared tree), clean increments after tested change,
  message-only (coordinator reviews + pushes; don't push to GitHub).
- **Report back** to coordinator when done, in this shape:
  `DONE: <what> · FILES: <paths:lines> · VERIFIED: <how, on dev> · CONTRACT: <any deviation> · BLOCKED: <none|what>`
