# Lane briefing — comments DB (pull comments out of WordPress)

You're the **comments** lane. Goal: move content comments out of WP into Postgres and serve them from
the standalone archive-poc stack — so the comment modal stops booting WordPress and goes from ~1–3s to
~50ms. This **supersedes** the comments-lean lane (which was making WP-boot leaner — a band-aid; we're
pulling comments out entirely instead). Work in the canonical tree
(`/home/ubuntu/projects/archive-poc/`) — NOT a worktree (isolation is shelved; dev serves from main).

## Read first
1. This file.
2. `docs/STRANGLER-COORDINATION.md` §3i (storage: one postgres, three schemas — comments lives in
   `discovery`), §0b (standalone serving, no WP boot), §0 (commit discipline).
3. The existing **likes** implementation in archive-poc (`api/v0/_likes.php`, `like.php`) — your write
   path mirrors it exactly (IDOR-proof, HMAC CSRF, identity via /whoami). This is your template.
4. `[[project_activity_stream_launch]]` — comments also feed inline `/stream/`; same store backs both.

## Decisions already made (Ian, 2026-06-04) — don't relitigate
- **Scope = content comments only** (~566 rows): loothprint, video, post-imgcap, post, shorty,
  coe-questions, ajde_events. **EXCLUDE `shop_order`** (454 WooCommerce order notes — internal order
  data, not community comments).
- **Reactions on comments = FAST-FOLLOW**, not v1. Design the schema so a `comment_reactions` table
  (mirror `discovery.likes`: comment_id, user_uuid, kind, created_at) drops in later — but don't build it.
- **Forum replies stay in bb-mirror** (`forums` schema). Out of scope; don't touch.

## Build

**1. Schema — `discovery.comments`** (same DB/schema as likes + content_item):
   - keyed to content the same way likes are (`post_type` + `item_id`), + `user_uuid` (author),
     `parent_id` (threading, nullable), `body`, `status`, `created_at`. Anonymous legacy commenters
     (no WP user) → store `author_name` with a null `user_uuid`.

**2. Read endpoint (standalone, no WP boot)** on the archive-poc stack — returns a content item's
   comment thread as JSON/HTML. This is what the modal calls. Replace the WP-booting
   `deploy/lg-comments-frame.php` path with this. Target ~50ms.

**3. Write endpoint** — new comments POST to Postgres (net-new, like likes). Mirror `like.php`'s
   security exactly (HMAC CSRF, IDOR-proof, identity from /whoami). **Gate writing on the WP login
   cookie** (per [[feedback_gate_posting_on_wp_cookie_not_whoami]] — don't gate on /whoami, which can
   read anon for unbridged members). Author identity card via the batch `/profile-api/v0/users` lookup.

**4. Backfill** `wp_comments` → `discovery.comments`:
   - only the content post-types above (exclude shop_order); `comment_approved=1`.
   - `comment_post_ID` → content item; `comment_parent` → `parent_id` (preserve threads);
     comment author `user_id` → `user_uuid` via the bridge (anon → author_name).
   - Small **dev fixture only** now; full run at **cutover** (per [[feedback_dev_fixtures_only]]).

## Verify (dev)
- Modal opens in well under half a second (measure before/after with perf-czar — the whole point).
- Backfilled thread renders with correct authors + threading.
- A logged-in member can post; a logged-out visitor cannot; CSRF/IDOR hold (reuse the likes tests).

## Protocol
Burn in-lane; ping coordinator only for the cross-cutting bits (the read endpoint's nginx location is
coordinator-owned; flag before shipping it). Commit by pathspec; coordinator reviews, tsar pushes.
Report: `DONE · FILES · VERIFIED (incl. before/after modal timing) · BLOCKED`.
