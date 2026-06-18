# Lane briefing — comments + reactions ENGINE (resume + delete, 2026-06-07)

You own the **backend/store** for content comments + reactions. You are the **PROVIDER** — the Hub feed
SURFACE lane CONSUMES your endpoints. You build store + endpoints + migration; you **never** touch feed
wiring, CSS, or markup (that's SURFACE). Successor to `1c86c753`.

Sanity-check the box first: `curl -s ifconfig.me` → `50.19.198.38` = act locally, do NOT SSH. Canonical
tree (`/home/ubuntu/projects/archive-poc/`), NOT a worktree. Commit small by pathspec; coordinator
reviews, **git-tsar pushes — no silent pushes**.

## 🔴 FIRST — new ask (Ian 6/7): DELETE a comment
Soft-delete, reversible, no DDL needed — `discovery.comments` already has
`status TEXT DEFAULT 'approved'` (values `approved|pending|spam|trash`). Delete = flip to `trash`.
- **Build `archive-poc/api/v0/comment-delete.php`** — POST `{ comment_id, _wpnonce }`.
  - Gate = **WP login cookie** (NOT /whoami), nonce CSRF — same gate pattern as `comment-post.php`.
  - **Authz: comment author OR admin only.** Author = `comments.user_uuid` matches the caller's uuid
    (or `author_wp_id` matches the WP user id); admin = WP `manage_options` (or your existing admin
    check). Reject everyone else with 403.
  - Action = `UPDATE discovery.comments SET status='trash' WHERE id=:id` (guarded by the authz above).
    Reversible — never hard-DELETE. (Threading: children `ON DELETE CASCADE` only fires on a real row
    delete, so a trashed parent keeps its subtree — decide whether a trashed parent hides its replies
    in the read, or just renders a "comment removed" stub. Flag the choice to coordinator.)
- **Verify the READ filters `status`** — `comments.php` (the modal HTML) + any count query must exclude
  `status='trash'` so a deleted comment disappears + the count drops. If reads don't filter status yet,
  that's part of this task.
- **Hand-off:** the **trash button on the comment row is SURFACE (Hub)** — not you. Report the endpoint
  contract (URL, params, authz, response shape) back so coordinator routes the button to the Hub lane.

## Current engine state (DONE — don't rebuild)
- **Reactions live + real, WP-free.** `discovery.card_reactions` (one-table-two-writers; `like` is a
  palette slug, not a separate system) + `discovery.comment_reactions`. Endpoints: `card-react.php`,
  `comment-react.php`. Palette = single source of truth `lg_reactions_palette()` in
  `archive-poc/api/v0/_comments.php` (7-set: like/ouch/wow/lol/shop/take-my-money/brain; 3 are image
  type served from `/archive-poc/reactions/`). Replies are a reactable target (`'reply'`, `ec9a30e`) —
  `card_reactions` is generic on post_type, no schema change.
- **Comments store live.** `discovery.comments` keyed `(post_type,item_id)` like `discovery.likes`;
  threaded via `parent_id`; WP-free read (~30–50ms), WRITE on the WP pool (gate = WP cookie + nonce).
- **Count contract (Ian 6/6):** ONE store per target = the only count source; counts are
  **server-rendered**; optimistic UI reconciles to server. Don't add a second count source.

## Open engine items
- **Hot-sort repoint** — hot-sort still ranks on a stale `content_item.like_count`. The repoint lands on
  the **SURFACE** side; ENGINE's job = hand SURFACE the reaction-count subqueries against the real store.
  Ranking-only, non-blocking.
- **BB reactions backfill** — `bin/migrate-bb-reactions.php` (self-contained, idempotent; maps the 6,413
  legacy BB reactions, incl. reply targets). Dev-proven. Runs **`--all` at CUTOVER**, not now.

## Cutover (don't lose)
- Re-apply grants at the real cut: `GRANT SELECT ON discovery.comments TO "bb-mirror"` (committed
  `dd248c5`, applied dev) + the `content_item` grant. Track any new grant you add (e.g. for delete, the
  write path runs on the WP pool — confirm its role can UPDATE `comments`).

## Boundaries
- PROVIDER only: store, endpoints, migration, palette, gating. **No feed wiring / CSS / markup** — that's
  the Hub SURFACE lane. API/contract changes route through coordinator. Don't edit `forums.*`.

## Report back (to coordinator)
`DONE · FILES · ENDPOINT CONTRACT (for SURFACE) · VERIFIED (delete soft+authz+read-filters / reactions persist+gate) · BLOCKED`.
**Report your session ID + outliner title** so coordinator logs you in CHATS-MENU + lineage.
