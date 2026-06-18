-- profile-app — SOCIAL LAYER schema (connections + messaging + notifications).
--
-- ⚠️ STUB — NOT YET APPLIED. Joins the spine as a dev-FINAL migration target,
-- reviewed before any crib runs. CUT-DAY-REQUIRED (P-list blocker, on the
-- critical path with the spine). Plan: docs/plan-profile-2.0-social-layer.md.
-- Do not run until Ian approves.
--
-- Keyed on looth_id (= users.uuid) so the graph is queryable next to the
-- directory. Build-thin in-house on postgres; async, NOT realtime. History is a
-- migration target — bp_* provenance columns make the import idempotent.

BEGIN;

-- ---------- connections (mutual friends / requests / blocks) ----------
CREATE TABLE connections (
    id              bigserial PRIMARY KEY,
    requester_uuid  uuid NOT NULL REFERENCES users(uuid),   -- "a" (initiator)
    addressee_uuid  uuid NOT NULL REFERENCES users(uuid),   -- "b"
    status          text NOT NULL CHECK (status IN ('pending','accepted','blocked')),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (requester_uuid <> addressee_uuid),
    UNIQUE (requester_uuid, addressee_uuid)
);
-- Connections are MUTUAL ONLY (Ian, 2026-05-30) — symmetric: ONE row per pair,
-- queried both directions. The `follow` type/graph is DROPPED; if a feature ever
-- needs a follow signal it is AUTO-DERIVED from the connection (accepted = mutual
-- follow), never stored as a row or surfaced as UI. `wp_bp_follow` is NOT migrated.
-- PORT NOTE (wp_bp_friends, 10,978 edges = 7,346 accepted / 3,632 pending): BB
-- stores ONE row per friendship → maps 1:1, no reciprocal-row dedup needed. The
-- UNIQUE(requester,addressee) blocks exact dupes but NOT the reversed pair; BB
-- source is clean (one row/pair) and app-layer request() rejects an existing edge in
-- either direction, so reversed-pair dupes can't arise. BB `is_limited` dropped on import.
CREATE INDEX idx_connections_addressee ON connections (addressee_uuid, status);
CREATE INDEX idx_connections_requester ON connections (requester_uuid, status);
CREATE INDEX idx_connections_pending   ON connections (addressee_uuid) WHERE status = 'pending';

CREATE TRIGGER connections_touch BEFORE UPDATE ON connections
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------- messaging (thin, async) ----------
CREATE TABLE message_threads (
    id              bigserial PRIMARY KEY,
    uuid            uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    subject         text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    last_message_at timestamptz NOT NULL DEFAULT now(),
    bp_thread_id    bigint UNIQUE          -- provenance for idempotent re-import
);

CREATE TABLE messages (
    id            bigserial PRIMARY KEY,
    thread_id     bigint NOT NULL REFERENCES message_threads(id) ON DELETE CASCADE,
    sender_uuid   uuid NOT NULL REFERENCES users(uuid),
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    bp_message_id bigint UNIQUE            -- provenance for idempotent re-import
);
CREATE INDEX idx_messages_thread ON messages (thread_id, created_at);

CREATE TABLE message_recipients (
    thread_id    bigint NOT NULL REFERENCES message_threads(id) ON DELETE CASCADE,
    user_uuid    uuid NOT NULL REFERENCES users(uuid),
    unread_count integer NOT NULL DEFAULT 0,
    is_deleted   boolean NOT NULL DEFAULT false,   -- per-user soft delete
    last_read_at timestamptz,
    PRIMARY KEY (thread_id, user_uuid)
);
CREATE INDEX idx_recipients_user ON message_recipients (user_uuid) WHERE is_deleted = false;

-- ---------- notifications (bell backend; lg-shell renders the UI) ----------
-- Third pillar of this lane. Modeled on BP's proven wp_bp_notifications envelope
-- (user / component+action / item / is_new), trimmed to looth_id + typed referents.
-- profile-app owns the DATA + counts; lg-shell's bell + modal are the UI that READ
-- this. Extensible to other lanes later via the `type` namespace (a new lane adds
-- its type + its own nullable referent column; don't pre-build columns we don't use).
--
-- NOTE: BB notification HISTORY (49,603 rows, mostly groups/activity/mentions we do
-- NOT own) is NOT a migration target — notifications are ephemeral UI state. The
-- bell fills live from unread DMs + pending requests. (Open decision: optionally
-- seed current-unread message/friends notices so the bell isn't empty at cut.)
CREATE TABLE notifications (
    id           bigserial PRIMARY KEY,
    user_uuid    uuid NOT NULL REFERENCES users(uuid),            -- recipient
    actor_uuid   uuid REFERENCES users(uuid),                     -- who triggered it (null = system)
    type         text NOT NULL CHECK (type IN ('message','connection_request','connection_accept')),
    -- typed, nullable referents (clean cascades; better-DB over a polymorphic blob).
    thread_id    bigint REFERENCES message_threads(id) ON DELETE CASCADE,
    connection_id bigint REFERENCES connections(id)    ON DELETE CASCADE,
    is_read      boolean NOT NULL DEFAULT false,
    created_at   timestamptz NOT NULL DEFAULT now(),
    read_at      timestamptz
);
-- unread bell count (cheap, hot path); recent-first feed for the modal.
CREATE INDEX idx_notifications_unread ON notifications (user_uuid) WHERE is_read = false;
CREATE INDEX idx_notifications_feed   ON notifications (user_uuid, created_at DESC);
-- Dedup targets for Notifications::push() ON CONFLICT (atomic upsert, no race):
--   message            → ONE row per (user, thread); a re-fire bumps it unread+top.
--   request/accept      → ONE row per (user, connection).
CREATE UNIQUE INDEX uq_notifications_message    ON notifications (user_uuid, thread_id)     WHERE type = 'message';
CREATE UNIQUE INDEX uq_notifications_connection ON notifications (user_uuid, connection_id) WHERE connection_id IS NOT NULL;

-- RETENTION (30-day ruling, Ian 2026-05-30): a cron (bin/prune-notifications, NOT
-- built this turn) runs this prune. The DM/connection itself persists — only the
-- bell alert is pruned, keeping the table lean (unlike BB's unbounded growth):
--   DELETE FROM notifications WHERE created_at < now() - interval '30 days';
-- Implemented as Notifications::prune($days=30); coordinator schedules the job.

COMMIT;
