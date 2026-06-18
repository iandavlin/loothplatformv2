# Bootstrap — social / messaging / notifications lane

You are the **social lane**: **connections + messaging + notifications** for the
Looth strangler. You build the BuddyPress social layer's replacement, **in-house,
in the profile-app codebase**, on postgres, behind `/whoami` identity. **CUT-DAY-
REQUIRED** — you're on the critical path with the profile spine.

## Read first (decisions are LOCKED — build to them, don't re-litigate)
- `STRANGLER-COORDINATION.md` → the social-layer block ("Messaging — OFFER IT" /
  "It's the whole social LAYER"): build thin in-house, seed from BB, home =
  profile-app, UI split (buttons on `/u/` + header modals), WhatsApp-not-a-backend.
- `plan-profile-2.0-social-layer.md` → the build plan + schema the prior turn drafted.
- `plan-profile-block-system.md` → "Visibility model — FINAL" (header = ceiling)
  and the avatar single-source contract — your features gate on these.

## Build ON the existing scaffold — DO NOT rebuild
The profile-2.0 lane already scaffolded your starting point (stubs only, nothing
applied/run). Verify + build on:
- `profile-app/sql/2026-05-30-social-layer.sql` — connections + thin async
  `message_threads/messages/message_recipients` schema (NOT applied — review-first).
- `profile-app/src/Connections.php`, `profile-app/src/Messaging.php` — class stubs.
- `profile-app/api/v0/me-connections.php`, `me-messages.php`, `me-thread.php`,
  `me-social-counts.php` — endpoint stubs.
- `profile-app/bin/migrate-social-from-bb.php` — BB→social crib skeleton (refuses
  to write until you make it real).
- `profile-app/SOCIAL-LAYER-CHECKLIST.md` — your checklist.

## Scope reality (Ian) — this is PORT + WIRE, not greenfield
The schema is a **port** of BB's known shapes (friends, messages) into postgres +
`looth_id`; the APIs are **CRUD** against it; then **skin a messages page + the
connect/message widget + wire header notifications.** Don't over-engineer; the
scaffold already drafted most of it. The two parts that are NOT trivial — where the
bugs live, so where the "tested" stamp is earned: the **history migrations**
(`wp_bp_friends` + the 1,881-msg `wp_bp_messages_*`, idempotent, history intact) and
the **gating** (who-can-DM + visibility tied to the header-ceiling / connection
state). Plus the dev-test pass (governing invariant).

## Scope (what you own)
- **Connections**: `connections(requester/addressee uuid, status[pending|accepted|
  blocked], type[friend|follow])` keyed on `looth_id`; API; **seed from
  `wp_bp_friends`** (+ `wp_bp_follow` if it exists on live — verify) one-pass.
- **Messaging**: thin **async** DMs (NOT realtime) — threads/messages/recipients;
  API; **seed history from `wp_bp_messages_*`** one-pass, `bp_*` provenance for
  idempotent re-import. ~5 msgs/day volume — keep it simple.
- **Notifications**: a notifications backend (new message, new connection request/
  accept to start; extensible to other lanes later) + unread counts. The **bell +
  modals are lg-shell's UI** — you provide the backend they read.

## Ownership boundaries — COORDINATE, don't collide
You + profile-2.0 both edit `profile-app/` in the **shared working tree**. Stay in
your files (the social-layer set above); **commit by PATHSPEC, never `git add -A`**.
- **profile-2.0 (`1c98b564`)** owns the spine, blocks, profile/practice pages,
  directory. **Connect/Message buttons = YOU own the whole widget** (markup +
  state-driven labels [Connect→Requested→Connected, Message gated by who-can-DM] +
  actions). profile-2.0 only provides a **one-line SLOT** in the `/u/` header
  template — `Social::renderProfileActions($viewerUuid, $profileUuid)` — because the
  buttons render off social state, not page state ("dumb host" pattern, like the
  shared header). That one-liner is all profile-2.0 owes; coordinator relays it.
- **lg-shell (`1d248347`)** owns the header modals (messages/friends/notifications
  UI) — they CALL your backend. Agree the response shapes with them via coordinator.
- **shim-replacement (`d9380b73`)** shares `Whoami.php`/`config.php` — **flag
  coordinator BEFORE touching either** (e.g. if header counts fold into `/whoami`).

## Build order (review-first; spine-style hard stops apply)
1. Finalize the **schema** (connections + messaging + notifications) — dev-FINAL
   before any migration runs (it's a migration target; one pass, never two).
2. Connections (API + Connect button action + `wp_bp_friends` seed).
3. Messaging (API + thread UI + `wp_bp_messages_*` seed, history preserved).
4. Notifications backend + counts.
5. Wire UI: on-`/u/` buttons (with profile-2.0), header modals (with lg-shell).
Surface progress for reaction; **do NOT apply schema / run migrations / deploy
until Ian reviews dev-final.**

## Open decisions — route to Ian via coordinator (don't guess)
- **who-can-DM**: any member vs connections-only (shapes messaging↔connections).
- **ship `follow` now** (needs `wp_bp_follow` confirmed on live) vs friends-only first.
- **header counts**: dedicated `me-social-counts` (recommended) vs fold into `/whoami`.
- **contact-reveal hybrid** (connection reveals WhatsApp/email/phone) — timing.

## Mechanics
- Commit by pathspec + push at the end of each change set (§0).
- Report back to coordinator in the standard format; flag cross-cutting changes.
- At spawn, capture this session ID and pass it back to coordinator for the roster.
