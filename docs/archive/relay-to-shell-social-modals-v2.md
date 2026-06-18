# → lg-shell: social-modals v2 (housekeeping first, then features)

## 0. HOUSEKEEPING FIRST — lock the verified baseline before new work
1. **Finalize the browser proof = Option 1** (accept curl + live in-browser paint as
   sufficient; write the report). Do NOT restart/re-seed the shared browser — it's in
   use by another lane, and the fixture mutation already proves the write-actions.
2. **Mirror `/srv/lg-shared/*` into the git-tracked `lg-shell/` dir and commit by
   pathspec** — `site-header.php`, `site-header.css`, `social-modals.js`, `jwt-verify.php`,
   `site-footer.php`. None of it is versioned today; that's how the wrong-contract file
   shipped unreviewed. Lock the known-good state before adding features.

Build everything below against the **endpoint source** (`api/v0/me-*.php` + `src/*.php`),
not assumptions. (That's how this file got fixed; keep that bar.)

## 1. Notification model — COORDINATOR RULING (clear = mark-read, user-controlled)
- **Remove auto-mark-read-on-open.** Opening the bell must NOT auto-clear — that's why
  Ian's 5 vanished. Notifications stay unread until the user acts.
- **Add explicit clear:** a "Mark all read" control (`POST /me/notifications/`
  `{action:'read_all'}`) + per-item click → `{action:'read', id}`. Both endpoints exist.
- **"Clear" = mark-read for v1** (badge clears, item de-emphasized; row stays in list).
  True *delete* (row vanishes) would need a new backend action — NOT building it now.
  ⚠️ Flag to Ian if he wants true-delete; that's a small profile-app add, separate ticket.
- **Bell shows only connection events.** The social lane is removing `message`
  notifications backend-side (see relay-to-social-message-notif.md); also skip any
  `type==='message'` defensively so it's correct even before that lands.

## 2. Message from the connections modal (frontend only)
Each accepted connection item already carries its `uuid`. Add a **"Message" button** per
connection that dispatches the existing event:
`document.dispatchEvent(new CustomEvent('lg:open-dm', { detail:{ uuid } }))`
— the messages modal already listens for it. No backend.

## 3. Search connections in the modal (frontend only)
Add a filter input at the top of the connections modal → **client-side filter** of the
already-loaded `accepted[]` (match `display_name`). No backend; backend search is a later
concern only if connection counts get large.

## Sequencing
#2 and #3 are self-contained — build immediately. #1 builds now too (existing endpoints).
The message-notif removal (#1 last bullet) lands via the social lane; your defensive
skip makes you correct either way.

— coordinator (relaying Ian)
