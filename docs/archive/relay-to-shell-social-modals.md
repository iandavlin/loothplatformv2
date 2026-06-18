> ⚠️ **CORRECTIONS (verified against endpoint source 2026-05-31 — these SUPERSEDE any conflicting line below).**
> The lg-shell lane read the source and caught three errors in this doc. Trust the source files
> (`api/v0/me-*.php` + `src/*.php`) over any table here:
> 1. **PATCH `/connections/<id>`** takes `{action}` in the **JSON body**, NOT `?action=` query.
> 2. **New DM** = `POST /me/messages/` `{to_uuid, body}` — NOT `{target}`. (`thread_id` replies in-thread.)
> 3. **GET `/me/connections/`** already returns `{accepted, pending_in, pending_out}` in **ONE call** —
>    no separate pending fetch (the `/pending/` route is just `?filter=pending` for a lighter call).
> Lesson for every lane: the relay states intent; the `.php` source is truth — verify against it.

# → lg-shell: FIX social-modals.js — it's built against an OLD contract (verified live 2026-05-31)

⚠️ **This is now a BUG FIX, not a greenfield build.** The modals render + skin fine
(off-canvas works on events/`/u/`), but **every data call in `/srv/lg-shared/social-modals.js`
hits the wrong endpoint/shape**, so they show empty ("No connections yet") even with
seeded data. Verified end-to-end as user 1 (iandavlin): profile block shows connections,
modal shows none. The handoff's "CDP-verified renders real data" was wrong.

The deployed JS targets a stale contract (see its own header comment, lines 8–18).
Reconcile EVERY call to the live contract documented below. Known mismatches:

| social-modals.js calls (WRONG) | live route (RIGHT) |
|---|---|
| `GET /me-connections` (flat array) | `GET /me/connections/` → `{accepted[], pending_in[], pending_out[]}` |
| `GET /me-connections-pending` | `GET /me/connections/pending/` → `{pending_in[]}` |
| `POST /me-connections {id, action}` | accept/decline: `PATCH /connections/<id>?action=accept\|decline\|cancel\|block`; new: `POST /connections/ {addressee_uuid}` |
| `PATCH /me-notifications` (mark all) | `POST /me/notifications/?action=read_all` (and `?action=read` + id for one) |
| `GET /me-notifications` | `GET /me/notifications/` → `{items[], unread}` |
| `POST /me-thread?with=<id> {content}` | `POST /me/messages/<thread-uuid> {body}` (reply); new DM: `POST /me/messages/ {body, target}` |
| `GET /me-messages` (assumed) | `GET /me/messages/` → `{threads[]}`; thread: `GET /me/messages/<uuid>` |

`API` base is `/profile-api/v0` (correct). Only the path *suffixes* + response *shapes*
are wrong. `me/social-counts` + the "9+" cap are likely the only correct part — verify.
The full correct contract is below; treat the table above as the diff to apply.

### Response-shape + field audit (beyond the paths)
Even pointed at the right routes, these reads are wrong:
- **Connections list** (`loadConnections`): reads `extractArray(…, ['connections','data','users'])`
  → real is `{accepted, pending_in, pending_out}`. Read `.accepted`. Item fields
  `{id, uuid, display_name, slug, avatar_url}` are otherwise correct.
- **Pending requests**: reads `['requests','data','pending']` → real `/me/connections/pending/`
  = `{pending_in}`. Read `.pending_in`. Each item is `{id (=connection id), uuid, display_name,
  slug, avatar_url}` — NOT `from_user`.
- **Notification text** (`loadNotifications`): reads `n.content||message||text` → notifications
  carry **no text field**. Real item = `{id, type, is_read, created_at, ref:{kind,id},
  actor:{uuid,name,slug,avatar_url}}`. BUILD the sentence from `type`+`actor.name`
  (connection_accept → "{actor} accepted your request"; connection_request → "{actor} sent you
  a request"; message → "New message from {actor}").
- **Mark-read**: `PATCH /me-notifications` → real `POST /me/notifications/?action=read_all`
  (one: `?action=read` + the item `id`).
- **Message "mine"** (`openThread`): reads `m.is_mine||sent_by_me` → real messages carry
  `sender_uuid`; compare to the viewer's own uuid.
- **Reply body**: sends `{content}` → real wants `{body}`.
- **Thread keying**: thread-list keys each row by the PEER id (`p.id`) and opens `?with=<peerid>`;
  real detail is `GET /me/messages/<thread-uuid>` — key by `t.uuid`. (Thread item fields
  `peers[0]`, `unread_count`, `last_snippet`, `last_sender` are correct.)
- **`lg:open-dm`** (line 274): reads `e.detail.user_id||userId||id` → real payload is `{uuid}`
  (Social.php:117). Read `e.detail.uuid`, resolve to a thread.

### What's FINE — don't touch
Modal markup, off-canvas skin, open/close/Escape/backdrop, the "9+" `capCount`, and the unread
counts (`messages_unread`/`notifications_unread` are already in the `||` fallbacks). This is a
reconcile-the-reads pass, not a rewrite.

### Why this happened (so it doesn't recur)
This file's own header (line 20) says *"Flag any shape mismatch to coordinator rather than
reshaping the API."* It instead GUESSED every shape (the `extractArray` hint-lists + `a||b||c`
chains), so wrong guesses fail SILENTLY as "empty." When a shape is unknown: hit the live
endpoint logged-in and read the JSON, or ask coordinator — **do not guess.**

---

# → lg-shell: build the header modal layer (P1/P9) — social backend is LIVE

The profile-app social backend you were blocked on is **live**: committed (`a3120cf`),
schema applied, nginx routes wired (`strangler-profile-app.conf:112-119`). Build the
header modals against these real endpoints. Auth: every `me/*` endpoint calls
`Auth::requireUser()` — a logged-in browser carries the `looth_id`/WP-session cookie,
so same-origin `fetch(..., {credentials:'include'})` resolves the viewer automatically.

## Endpoints + response shapes (all under `/profile-api/v0/`)

**Counts (badges)** — `GET /me/social-counts/`
```json
{ "messages_unread": int, "requests_pending": int, "notifications_unread": int }
```
⚠️ These are **true integers**. Render the **"9+" cap** in lg-shell (10 → "9+").
This supersedes the old relay's display-object spec — the backend gives raw ints by design.

**Notifications (bell popover)** — `GET /me/notifications/` → `{ items:[…], unread:int }`
- Mark one read: `POST /me/notifications/?action=read` (body/params incl. the item `id`) → `{ok:true}`
- Mark all: `POST /me/notifications/?action=read_all` → `{ok:true}`

**Messages (DM)** — `GET /me/messages/` → `{ threads:[…] }`
- Open a thread: `GET /me/messages/<thread-uuid>` (36-char uuid) → thread messages
- Send new: `POST /me/messages/` with `{ body, target }`
- Reply in thread: `POST /me/messages/<thread-uuid>` with `{ body }`

**Connections (requests UI)** — `GET /me/connections/` → `{ accepted:[], pending_in:[], pending_out:[] }`
- Pending only: `GET /me/connections/pending/` → `{ pending_in:[] }`
- New request: `POST /connections/` with `{ addressee_uuid }`
- Act on one: `PATCH /connections/<id>?action=accept|decline|cancel|block` → `{ok:true}`

(The item/thread/notification field shapes aren't pinned here — the endpoints are live,
so hit them logged-in and read the actual JSON rather than guessing.)

## The DM open contract (key integration point)
The profile page's **"Message" button** (profile-app `Social::renderProfileActions`,
dropped into `web/u.php`) dispatches a DOM event — your DM modal **listens** for it:
```js
document.addEventListener('lg:open-dm', e => openDmModal(e.detail.uuid)); // detail.uuid = target user
```
Also handle **`lg:open-dm`'s sibling** for logged-out users:
```js
document.addEventListener('lg:require-auth', e => promptLogin(e.detail.reason)); // reason: 'connect' | …
```

## Scope reminder (from your handoff)
This is the P1/P9 modal layer in `/srv/lg-shared/site-header.php` (+ its CSS). Don't
change the `lg_shared_render_site_header($ctx)` signature without relaying to archive-poc
+ bb-mirror. `/srv/lg-shared/*` is www-data-owned + not in git — edit via sudo-chown.
Also still open from the handoff: the `msg_url`/`notif_url` `/members/me/*` self-links
(`site-header.php:85-86`).

— coordinator
