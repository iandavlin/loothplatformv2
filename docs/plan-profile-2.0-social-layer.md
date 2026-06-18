# Social-layer build plan — connections + messaging (profile-2.0)

> Sibling to `plan-profile-2.0-phase1-build.md`. Status: **PLAN + SCAFFOLD for Ian
> to approve — nothing applied/run/deployed.** Same review-first discipline: the
> social schema joins the spine as a **dev-final migration target** before any crib
> runs. Canon: `STRANGLER-COORDINATION.md` → social-layer block ("Messaging — OFFER
> IT" / "the whole social LAYER"). Lane state: `docs/SESSION-HANDOFF-profile-2.0.md`.

## Scope & decisions already locked (Ian, 2026-05-30)

The BuddyPress social cluster — **connections** (friends / follow / requests) +
**messaging** (member↔member DMs) — is a **KEEP, rebuilt in-house**, not a
decommission. Locked:
- **Home = profile-app.** It owns identity (`/whoami`, `looth_id`, the spine);
  people-to-people data lives where the people do. profile-app's scope grows to
  spine + blocks + **connections + messaging + their migrations**.
- **Build THIN in-house on postgres** (no SaaS, no OTS server). Volume is ~5 msgs/day,
  async 1:1, **NOT realtime** — a `threads/messages/recipients` schema is right-sized.
  Identity via `/whoami` + `looth_id`; history imports SQL→SQL.
- **Storage = app-owned** (consistent with the avatar/media single-source direction).
- **History is a migration target** — users expect friend graphs + DMs to survive
  the cut. Dev snapshot: **1,881 msgs / 370 threads / 219 senders**, 5.1 msgs/thread.
- **Timing = CUT-DAY-REQUIRED.** On the critical path *with* the spine — a
  cutover-eligibility (P-list) blocker, NOT a fast-follow. The `wp_bp_friends` +
  `wp_bp_messages_*` migrations join the pre-cut crib.
- **UI in two surfaces, one backend:** **Connect + Message buttons on `/u/`**
  (rendered by profile-app natively) + **header modals** (messages / notifications /
  friends — lg-shell's P9). Both call the **one** profile-app social backend.
  profile-app owns data + on-profile buttons; lg-shell owns header-modal UI. No
  double ownership.

## 1. Connections schema

Stub: `profile-app/sql/2026-05-30-social-layer.sql` (written, **NOT applied**).

```sql
connections (
  id              bigserial PRIMARY KEY,
  requester_uuid  uuid NOT NULL REFERENCES users(uuid),   -- "a" (initiator)
  addressee_uuid  uuid NOT NULL REFERENCES users(uuid),   -- "b"
  status          text NOT NULL CHECK (status IN ('pending','accepted','blocked')),
  type            text NOT NULL DEFAULT 'friend' CHECK (type IN ('friend','follow')),
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  UNIQUE (requester_uuid, addressee_uuid, type)
);
```
- **Keyed on `looth_id` (= `users.uuid`)** so it's queryable next to the directory.
- **friend** = symmetric: ONE row; "are A & B connected" checks both directions
  (`(requester=A AND addressee=B) OR (requester=B AND addressee=A)` AND
  `status='accepted'`). **follow** = asymmetric/directional.
- `blocked` lives here too (a connection in the blocked state); blocks gate DMs.
- Indexes: `(addressee_uuid, status)` for incoming-request counts; `(requester_uuid,
  status)`; partial on `status='pending'`.
- **Gates** who-can-DM-whom (below) + the optional contact-reveal (hybrid).

## 2. Messaging schema — thin, async (NOT realtime)

```sql
message_threads (
  id              bigserial PRIMARY KEY,
  uuid            uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
  subject         text,
  created_at      timestamptz NOT NULL DEFAULT now(),
  last_message_at timestamptz NOT NULL DEFAULT now(),
  bp_thread_id    bigint UNIQUE          -- provenance; idempotent re-import
);
messages (
  id              bigserial PRIMARY KEY,
  thread_id       bigint NOT NULL REFERENCES message_threads(id) ON DELETE CASCADE,
  sender_uuid     uuid NOT NULL REFERENCES users(uuid),
  body            text NOT NULL,
  created_at      timestamptz NOT NULL DEFAULT now(),
  bp_message_id   bigint UNIQUE          -- provenance; idempotent re-import
);
message_recipients (
  thread_id       bigint NOT NULL REFERENCES message_threads(id) ON DELETE CASCADE,
  user_uuid       uuid NOT NULL REFERENCES users(uuid),
  unread_count    integer NOT NULL DEFAULT 0,
  is_deleted      boolean NOT NULL DEFAULT false,   -- per-user soft delete (BB sender_only/is_deleted)
  last_read_at    timestamptz,
  PRIMARY KEY (thread_id, user_uuid)
);
```
- Async only — no presence, no websockets. Optional notify channel (email/SMS/
  WhatsApp) is a **fast-follow**, not in this schema.
- `message_recipients.unread_count` feeds the header **messages** badge (the
  shared-header `data-lg-msg-count` already lazy-loads from a REST count endpoint).
- Identity via `/whoami` (`sub` = uuid). All writes assert the sender is a thread
  participant.

## 3. Migrations into the crib — one pass, history preserved

Stub: `profile-app/bin/migrate-social-from-bb.php` (written, **does nothing**;
`--commit` guarded). Reads BB MySQL via the established pattern (unix socket,
`LG_PROFILE_APP_MYSQL_DB`), maps `wp_user_id → users.uuid` via `wp_user_bridge`.
Joins the slice-4 crib (`bin/migrate-crib-slice4.php` orchestrates it), one pass:

- **connections** ← `wp_bp_friends` (`initiator_user_id`, `friend_user_id`,
  `is_confirmed`): confirmed→`status='accepted'`, unconfirmed→`'pending'`
  (requester=initiator). **`wp_bp_follow`** (BP-Follow plugin, *if present*) →
  `type='follow'` rows. Skip rows where either side has no bridge.
- **messaging** ← BP has no threads table; threads are implied by `thread_id`:
  - distinct `wp_bp_messages_messages.thread_id` → `message_threads`
    (`bp_thread_id`, subject from first message, `last_message_at` = max date).
  - `wp_bp_messages_messages` → `messages` (`bp_message_id`, `sender_uuid`,
    `body`, `created_at`).
  - `wp_bp_messages_recipients` → `message_recipients` (`unread_count`,
    `is_deleted`, `sender_only`). Skip non-bridged recipients.
  - `bp_thread_id`/`bp_message_id` UNIQUE → **idempotent** re-runs.
- **Rehearsal gate:** dry-run on dev, assert counts ≈ snapshot (1,881 msgs / 370
  threads / 219 senders; friend-graph count TBD from `wp_bp_friends`), spot-check a
  known thread end-to-end, THEN `--commit` on dev.

## 4. API — the one social backend (both UIs call this)

New `api/v0/` endpoints (stubs written, bodies TODO):
- `me-connections.php` — `GET` list (accepted / pending-in / pending-out / following),
  `POST {action: request|accept|decline|block|unfollow, target_uuid}`.
- `me-messages.php` — `GET` thread list (with `unread_count`, last snippet, peers),
  `POST {thread_id?|to_uuid, body}` (send; creates thread if none).
- `me-thread.php` — `GET ?id=` one thread's messages (paginated), marks read.
- `me-social-counts.php` — `GET` `{messages_unread, requests_pending}` → feeds the
  header **messages**/**friends** badges (lazy-load; cheap; cache 30s).
- On-`/u/` actions: the **Connect** + **Message** buttons POST to
  `me-connections.php` / open a composer that POSTs `me-messages.php`.

**Ownership boundary:** profile-app serves all the above + renders the on-profile
buttons. lg-shell's header modals (P9) are pure UI calling these endpoints — no DB
of their own. (Mirrors the BB split Ian named.)

## 5. Gating — connections × the visibility ceiling

- **Who-can-DM:** members only; logged-out get the join-gate (same header ceiling
  as the profile). **Knob for Ian:** any-member-can-DM vs connections-only-can-DM
  (lean: any member may *start*, blocks/`blocked` status hard-stop). Flagged.
- **Connect button** state derives from `connections`: none→"Connect", pending-out→
  "Requested", pending-in→"Accept", accepted→"Connected ✓ / Message".
- **Contact-reveal hybrid (Ian's angle):** a private contact field (WhatsApp /
  email / phone, member's choice) **revealed only to accepted connections** — the
  connection doubles as the reveal gate. Schema-light (a `profile_sections` row
  `key='contact'`, vis `private`, surfaced to accepted connections at render).
  Post-pilot; noted, not built this turn.
- Effective visibility everywhere = `Block::effectiveVisibility(header, block)` —
  the social buttons sit in the header, so a **private** header hides them entirely;
  a **member** header shows them to members, join-gates the public.

## 6. Build order (each step surfaces for reaction; review before crib)

1. ▢ Approve schemas (§1–2) → apply `…-social-layer.sql` on dev.
2. ▢ `src/Connections.php` — request/accept/block + symmetric-pair query.
3. ▢ `src/Messaging.php` — threads/send/mark-read + unread counts.
4. ▢ `api/v0/me-connections|me-messages|me-thread|me-social-counts` endpoints.
5. ▢ On-`/u/` Connect + Message buttons (profile-app render; in the header block).
6. ▢ Header badge count endpoint wired (shared-header lazy-load already expects it).
7. ▢ lg-shell header modals (P9, lg-shell lane) call the endpoints — **cross-lane;
   coordinate, don't build their UI here.**
8. ▢ **Social schema declared dev-FINAL with the spine — coordinator sign-off.**
9. ▢ `migrate-social-from-bb.php` dry-run → dev commit → spot-check history.
10. ▢ (cutover, coordinator-timed; social layer is a P-list blocker.)

Steps 1–8 freeze with the spine before step 9. CUT-DAY-REQUIRED: the social crib
runs in the same pre-cut window as the spine crib.

## HARD STOPS (this plan does not execute)
No migration run, no schema apply/commit, no deploy, no git commit, no edit of
`Whoami.php`/`config.php` (shared w/ shim-replacement `d9380b73` — flag coordinator
for any `/whoami` shape change; the `me-social-counts` read is additive but the
coordinator should bless the `/whoami` vs dedicated-endpoint split).
