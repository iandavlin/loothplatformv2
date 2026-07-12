# PLAN — messages-manage (group messaging management)

Lane: `messages-manage` off `main@155ce7c` (merged up from 268a7aa after hub-picker). Author: messages-manage. Status: **BUILDING** — both design rulings landed (keeper relayed Ian 2026-07-12 17:37 + 18:02), plan-gate satisfied.

## IAN RULINGS (binding)
- **Remove rights (17:37, verbatim):** "The person who started the chat can remove anyone, admin can remove anyone, anyone can remove themselves." → creator (any), site-admin (any, `Auth::isAdmin()`), self (always). Legacy threads with no recorded creator → admin+self only.
- **History on add (18:02):** newly added members see FULL prior history — no post-join window. (Drops the `joined_at` visibility filter; D3 below is superseded.)

Four features, BOTH surfaces (`lg-shared/social-modals.js` desktop + `webroot/messenger-sheet.js` mobile):
1. Start a group message (compose to N recipients)
2. Membership: add / remove others, leave self — visible as in-thread system lines
3. Edit own message (visible "(edited)")
4. Delete own message (soft tombstone "Message deleted" + media object GC via MessageR2)

Built ON merge f0109cd: all-peer rendering, navSeq stale guards, `findPairThread` exactly-2 gate, 1:1 send gate. Regressing any of it is a merge blocker.

---

## MODEL DECISIONS (proposed defaults — flagged for Ian)

**D1 — Membership = a `message_recipients` row.** Remove/leave = **DELETE the row**. This inherits the existing deny model unchanged (isRecipient → false → 404), the thread vanishes from their list, and re-add = a fresh row. The audit trail lives in system messages, not the row — so no "roles/permissions" table (flat, per Ian). (No `joined_at` needed — Ian ruled full history on add.)

**D2 — "once a group, always a group" flag.** Add `message_threads.is_group bool`. Set true when a thread is created with >2 members OR a member is ever added. `findPairThread()` gains `AND t.is_group = false`. **This is the guard that protects the 1:1 gate**: without it, a 3-person group that drops to 2 members would start matching the exactly-2 `count(*)=2` clause and a profile "Message" could silently resolve into it. Backfill: existing threads with >2 recipients → `is_group=true`.

**D3 — SUPERSEDED by Ian ruling:** added members see FULL prior history. No `joined_at`, no visibility horizon in the read path.

**D4 — Add 3rd to a 1:1 NEVER converts it.** "Add member" on a 1:1 (`is_group=false`) **forks a NEW group thread** (the 2 existing peers + the invitee, `is_group=true`, starts fresh with a system line); the old 1:1 stays private and untouched. "Add member" on a thread already a group adds in place.

**D5 — Removal semantics.** Removed/left user's row is deleted → 404 on read AND send (server-enforced). Their previously-sent messages **stay, attributed** to them (messages.sender_uuid persists independent of the recipient row). Thread disappears from their list.

**D6 — Edit/delete = own messages only, server-enforced.** `sender_uuid = actor` or 4xx (not just a hidden button). No time limit v1 (flag). System-kind lines are never editable/deletable.

**D7 — Delete = soft tombstone.** `messages.deleted_at` set; body withheld from the payload; media object removed via `MessageR2::delete()` (or local-fallback unlink) and media_* columns nulled. Thread flow survives ("Message deleted" bubble).

**D8 — System lines (transparency).** New `messages.kind` ('message' default | 'system'). System line body is server-rendered at write time ("Ian added Doug", "Sharon left", "Ian started a group with …"), `sender_uuid` = actor, rendered centered (not a bubble), never owned/editable. Names captured at write time (historical record; renames don't rewrite history — acceptable v1).

**D9 — Add / remove rights (RULED).** ADD: any participant may add someone they're connected to. REMOVE another: thread **creator** or **site-admin** only (`message_threads.created_by` + `Auth::isAdmin()`); legacy threads (`created_by IS NULL`) → admin only. LEAVE (remove self): always allowed. Server-enforced; a non-creator/non-admin remove of another = 403.

**D10 — Connection gate on group adds.** You may only ADD members you are connected to (consistent with the DM connections-only gate). Existing migrated groups may contain non-connections — gate applies to NEW adds only.

**D11 — Snippet / unread on edited-or-deleted newest.** Deleted newest → snippet "Message deleted". Edited newest → snippet = new body. System lines bump `last_message_at` (thread surfaces) but do **NOT** increment the unread badge (avoid membership-churn noise) — flag; easy to flip to "do count".

**D12 — Group naming.** Peer-name label already ships; custom titles = **out of scope v1** unless Ian asks.

---

## SCHEMA — `profile-app/sql/2026-07-12-message-management.sql` (idempotent, twice-run proven)

```sql
ALTER TABLE message_threads
  ADD COLUMN IF NOT EXISTS is_group   boolean NOT NULL DEFAULT false,
  ADD COLUMN IF NOT EXISTS created_by uuid REFERENCES users(uuid);   -- creator (null = legacy/migrated)

ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS kind       text        NOT NULL DEFAULT 'message',  -- 'message' | 'system'
  ADD COLUMN IF NOT EXISTS edited_at  timestamptz,
  ADD COLUMN IF NOT EXISTS deleted_at timestamptz;

-- Backfill (idempotent): flag existing multi-party threads as groups. created_by stays
-- NULL for all migrated threads (no creator was ever recorded) → remove falls back to
-- admin+self, per Ian's ruling. No joined_at (full history on add).
UPDATE message_threads t SET is_group = true
  WHERE is_group = false
    AND (SELECT count(*) FROM message_recipients r WHERE r.thread_id = t.id) > 2;
```
Postgres timestamps → `parseTs()` on the wire (mobile already normalises). Thread 367 + store thread ed23219e never touched.

---

## SERVER — `src/Messaging.php` + endpoints

- `send()` / `insertMessage()` — carry `kind`; system inserts skip unread fan-out (D11).
- `thread()` — return per-message `kind`, `edited_at`, `deleted` (bool), body withheld when deleted; return `is_group`, `created_by`, `can_manage` (viewer is creator/admin), + member list (for the manage UI). No joined_at filter (full history).
- `findPairThread()` — `AND t.is_group = false` (D2).
- New: `addMembers()` (any participant, connection-gated), `removeMember()` (creator/admin only — `created_by` + `Auth::isAdmin()`), `leave()` (self), `forkGroup()` (D4), `editMessage()`/`deleteMessage()` (owner only, media GC). Every one asserts participant / ownership / manage-right server-side; deny = 404/403/4xx.
- `threadsFor()` — snippet aware of deleted/system (D11).

Endpoints (2 new files, mirroring the one-file-per-endpoint convention):
- `POST /me/messages/` — extend to accept `to_uuids:[...]` for group create (keep `to_uuid` single for 1:1).
- `me-thread-members.php` → `POST /me/messages/<uuid>/members` body `{add:[uuid…]}` | `{remove:uuid}` | `{leave:true}`.
- `me-thread-entry.php` → `PATCH|DELETE /me/messages/<uuid>/entries/<id>` (edit / delete own).

**RUNBOOK (keeper — out-of-repo nginx):** 2 new rewrites, placed ABOVE the bare `/me/messages/<uuid>` rewrite, + allowlist regex additions — exact lines delivered with the preview request (same pattern as me-thread/me-message-image headers).

---

## FRONTEND — parity on both surfaces

Desktop `lg-shared/social-modals.js` (+ `site-header.php` markup / css):
- "＋ New message" → member picker (multi-select from `/me/connections/` accepted[]) → `to_uuids[]`.
- Thread header → "manage members" affordance: list members, add (connected), remove (✕), **Leave**.
- Own bubble hover menu → Edit / Delete. Render system lines centered; "(edited)"; "Message deleted" tombstone.

Mobile `webroot/messenger-sheet.js` (webroot copy — keeper does the `cp` + `?v=` bump in `pwa.js`; NOTED, I don't bump):
- Compose FAB on the Chats home → same picker.
- Chat header tap → members sheet (add/remove/leave).
- Long-press own bubble → Edit / Delete. Same system-line / tombstone / (edited) rendering.

Both keep: all-peer header, navSeq/curThread stale guards, stickBottom, 1:1 gate.

---

## PHASING (reviewable increments; first deliverable = mocks for Ian's eyeball)

- **P0** schema migration + backfill (idempotent, twice-run).
- **P1** server: membership + system lines + fork + gates + thread payload. Verify via API as 3+ test users.
- **P2** server: edit/delete + media GC. Verify owner-can / non-owner-4xx / object-gone.
- **P3** desktop FE — **mocks first** (group compose, member manager, edit/delete menu + system line + tombstone) → post for Ian BEFORE going deep, then build.
- **P4** mobile FE parity.
- **P4.5** image LIGHTBOX + ZOOM, both surfaces (Ian scope-add 18:26): replace the current `target=_blank` message-image links with an in-app lightbox — desktop sensible zoom affordance, mobile pinch-zoom. Loads via the SAME access-controlled `/message-media/` URL (web/message-media.php) — no new exposure path, no public copy. Applies to EXISTING images too. Own commit.
- **P5** verify (CDP 390/1280 + origin-direct curl; gates known-broken on dev2) → PREVIEW REQUEST to keeper.

## VERIFY (min, before preview)
- Group create/add/remove/leave exercised as 3+ distinct users; outsider + removed user 404 on read AND send; system lines render both surfaces.
- Edit/delete: non-owner 4xx server-side (not hidden button); tombstone + "(edited)" both surfaces; media object actually gone from the store.
- Regression: profile "Message" → true pair thread (never a group), all-peer header, unread, snippets, stickBottom, stale guards.
- Test threads created fresh + purged WHOLE (thread+recipients+messages+media). Thread 367 + ed23219e never mutated.
