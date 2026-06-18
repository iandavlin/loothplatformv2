# Reply to coordinator — comments + reactions ENGINE (edit + delete)

**Lane:** comments + reactions ENGINE (backend provider). Successor to `1c86c753`.
**Outliner title:** `comments+reactions ENGINE (edit+delete)`
**Commit:** `5b262c0` — local on `main`, **not pushed** (git-tsar pushes after review).
**Date:** 2026-06-07

---

## DONE
Author/moderator **edit + soft-delete** for content comments. Built, tested end-to-end on dev.

## FILES
- `archive-poc/api/v0/comment-delete.php` — NEW (soft-delete / restore)
- `archive-poc/api/v0/comment-edit.php` — NEW (edit body)
- `archive-poc/api/v0/_comments.php` — `lg_comments_get` / `lg_comment_author_match` / `lg_comments_set_status` / `lg_comments_update_body`; thread read now selects `edited_at`
- `archive-poc/api/v0/comments.php` — renders `(edited)`; read already filtered `status='approved'`
- `/etc/nginx/snippets/strangler-archive-poc.conf` — routes for both endpoints, reloaded (INFRA, outside git)
- DDL: `discovery.comments.edited_at` added on dev; `looth-dev` already had UPDATE → no new grant

## ENDPOINT CONTRACT → relay to the Hub SURFACE lane
The trash/edit **buttons + confirm UX are SURFACE's** — engine only provides these endpoints. Nonce + `my_reactions` still come from the existing `comment-post.php` GET (action `lg_comment`).

```
POST /archive-api/v0/comment-delete         (also restore)
  body : { comment_id:int, _wpnonce:str, restore?:bool }
  gate : WP login cookie + nonce 'lg_comment'
  authz: comment author OR moderate_comments  → else 403
  resp : { ok:true, comment_id, status:"trash"|"approved" }
  errs : auth_required(401) bad_csrf(403) forbidden(403) not_found(404) method_not_allowed(405)

POST /archive-api/v0/comment-edit
  body : { comment_id:int, body:str, _wpnonce:str }
  gate : same
  authz: author OR moderate_comments; rejects already-trashed (409 not_editable)
  resp : { ok:true, comment_id, body, edited:true, when }
  errs : empty(400) + same set as above
```

SURFACE-side notes:
- Show edit/trash affordance only when the viewer is the author (compare against their wp_user_id from comment-post GET) or a moderator — the server enforces it anyway, this is just UX.
- After delete: drop the comment node (and its subtree — see below) and decrement the count.
- After edit: swap the body text and add the `(edited)` marker (read already renders it on reload).

## DECISIONS (locked with Ian 6/7)
- **Threading on delete:** a trashed parent **hides its whole reply subtree**. The read returns only `approved` rows and the renderer reaches nodes only from the root, so a trashed parent's replies become unreachable and the count drops accordingly. Reversible via restore. ✅ Ian: "Delete thread is good." — no stub.
- **Reactions scope:** users already toggle their own reaction off; **admin removal of *other* users' reactions is NOT in scope.** ✅ Ian: scope is fine as-is.

## VERIFIED
- Store ops + read-filter vs real DB: trash drops count + removes from thread; restore restores; edit updates body + stamps `edited_at`.
- `author_match`: real author → true, stranger → false.
- HTTP gating: GET→405, authed-less POST→401, no-dev-cookie→403 (WP boots clean, no 500).
- End-to-end as logged-in admin (moderator): edit→ok, delete→`status:trash`, DB confirmed; throwaway row removed.

## STANDBY (unchanged, non-blocking)
- Hot-sort repoint — engine hands SURFACE the reaction-count subqueries against the real store (ranking-only).
- BB reactions backfill — `bin/migrate-bb-reactions.php`, runs `--all` at CUTOVER.

## CUTOVER — don't lose
- Re-apply grants at the real cut (`comments`, `content_item` SELECT to `bb-mirror`, etc.).
- **The nginx routes for comment-delete / comment-edit live in `/etc` (outside the git repo)** — they will NOT ride along on a git-tsar push. Re-apply the two `location =` blocks + clean-URL rewrites in `snippets/strangler-archive-poc.conf` at cutover.
- `edited_at` column DDL must be applied on the cutover DB.

## BLOCKED
None.
