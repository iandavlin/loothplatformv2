-- 2026-07-13-message-reactions.sql — reactions on individual messages (lane: messages-reactions)
--
-- ONE STORE PER TARGET (Ian): the hub's BuddyBoss reactions (MySQL wp_bb_reactions_data /
-- wp_bb_user_reactions) cover WP posts only. Messages live in postgres (profile_app), so they
-- get their OWN reaction table here — counts and "did I react" are read from THIS table only,
-- never a denormalised counter.
--
-- message_reactions:
--   message_id  — FK -> messages(id) ON DELETE CASCADE. Deleting a message (a hard row delete)
--                 removes its reactions with it. NOTE: our "delete" is a SOFT tombstone (the row
--                 stays, body/media blanked), so the cascade is a belt-and-suspenders guard for a
--                 real row delete; the SERVER additionally refuses to react to a tombstone, and
--                 clears no reactions on soft-delete (a tombstone renders no strip anyway).
--   user_uuid   — FK -> users(uuid) ON DELETE CASCADE (a departed user's reactions vanish).
--   emoji       — the reaction glyph, stored as text (NOT a slug). Validated against the fixed
--                 allow-set SERVER-SIDE (Messaging::REACTION_EMOJI) on every write; the column is
--                 deliberately unconstrained here so the set can evolve without a migration.
--   UNIQUE(message_id, user_uuid, emoji) — one row per (message, user, emoji): the toggle is an
--                 INSERT ... ON CONFLICT DO NOTHING, and re-tapping the same emoji DELETEs it.
--                 A user may hold several DIFFERENT emoji on one message (distinct rows).
--
-- Additive + idempotent (twice-run proven): IF NOT EXISTS on the table + indexes.

CREATE TABLE IF NOT EXISTS message_reactions (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    message_id bigint      NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    user_uuid  uuid        NOT NULL REFERENCES users(uuid)  ON DELETE CASCADE,
    emoji      text        NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT message_reactions_uq UNIQUE (message_id, user_uuid, emoji)
);

-- The read path aggregates every reaction for a thread's messages by message_id (batch load),
-- ordered by created_at so "who reacted" reads oldest-first (stable facepile order).
CREATE INDEX IF NOT EXISTS message_reactions_msg_idx
    ON message_reactions (message_id, created_at);
