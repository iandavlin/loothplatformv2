-- profile-app — CHAPTERS (the native strangler test: DMV Looths with zero BuddyBoss, zero WP).
--
-- Target DB: profile_app.  Apply as the schema OWNER so ownership + DML grants attach:
--   sudo -u profile-app psql -d profile_app -v ON_ERROR_STOP=1 -f sql/2026-07-12-chapters.sql
--
-- IDEMPOTENT: safe to re-run (IF NOT EXISTS everywhere; the seed upserts).
-- REVERSIBLE: paired down-migration 2026-07-12-chapters.down.sql. NOTE — this repo has NO
--   down-migration precedent (I checked: zero ROLLBACK/DROP sections in profile-app/sql,
--   archive-poc/sql, lg-stripe-billing/db/migrations). The lane brief requires reversible, so
--   this pair ESTABLISHES that convention rather than following one. Flagged in the report.
--
-- WHY profile_app AND NOT looth/discovery:
--   A chapter is a SOCIAL/IDENTITY object. Every single thing it depends on already lives here —
--   users (identity, slug, avatar, lat/lng + the 5-column privacy matrix), messages/message_threads
--   (the chat room), notifications, connections. Putting `chapter` in the `looth` DB would mean
--   NO foreign key to users(uuid) is even expressible (separate databases, no postgres_fdw, no
--   dblink — verified) and would push the chat room cross-DB. The one thing that DOES live in
--   `looth` is discovery.comments, and we reuse it over a second PDO connection rather than
--   standing up a second comments store (see 2026-07-12-chapters-comments-grants.looth.sql).
--
-- KEY CONVENTION FOLLOWED: profile_app splits its keys — social/graph tables FK to users(UUID)
--   (connections, messages, notifications, user_mutes); profile/attribute tables FK to users(id).
--   Chapters are social, so every member/author reference here is user_uuid. Matches the brief.

BEGIN;

-- ---------------------------------------------------------------------------------------------
-- 1. chapter — A CHAPTER IS A DATA ROW, NOT CODE.
--    Onboarding "Austin Looths" = INSERT one row (+ its room). Zero code changes, zero deploy.
--    See docs/atlas/CHAPTERS-RUNBOOK.md for the proof.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chapter (
    id          bigserial PRIMARY KEY,
    slug        text        NOT NULL UNIQUE,       -- URL: /g/<slug>
    name        text        NOT NULL,
    description text,
    center_lat  numeric(9,6) NOT NULL,             -- catchment circle centre. Matches users.lat/lng type.
    center_lng  numeric(9,6) NOT NULL,
    radius_km   integer     NOT NULL DEFAULT 160,
    is_active   boolean     NOT NULL DEFAULT true, -- false = hidden from the index, URL still 404s cleanly
    created_at  timestamptz NOT NULL DEFAULT now()
);

-- ⚠️ UNITS TRAP, READ THIS. radius_km is KILOMETRES (the brief's contract), but the ENTIRE geo
-- stack underneath is MILES: postgres earthdistance `<@>` returns miles, the directory pins API
-- takes `radius` in miles, and webroot/directory-mobile.js viewportRadiusMi() clamps 1..500 miles.
-- Conversion happens in EXACTLY ONE PLACE — Chapters::radiusMi() (src/Chapters.php). Never divide
-- by 1.609344 anywhere else, or a chapter's circle and its member list will silently disagree.

COMMENT ON TABLE  chapter            IS 'A local Looth chapter. PUBLIC + browsable; no privacy, no permissions, no access control (Ian 2026-07-12). Opt-IN: starts empty.';
COMMENT ON COLUMN chapter.radius_km  IS 'Catchment radius in KM. Converted to miles by Chapters::radiusMi() — the only conversion site.';

-- ---------------------------------------------------------------------------------------------
-- 2. chapter_member — opt-IN, self-serve, ONE TAP, NO APPROVAL.
--    There is deliberately NO status/role/approved column: a member is in, or is not in.
--    Legacy BuddyBoss membership is NOT imported (it is junk: 812 users in both NYC and SoCal).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chapter_member (
    chapter_id bigint      NOT NULL REFERENCES chapter(id)  ON DELETE CASCADE,
    user_uuid  uuid        NOT NULL REFERENCES users(uuid)  ON DELETE CASCADE,
    joined_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (chapter_id, user_uuid)   -- join is idempotent via ON CONFLICT DO NOTHING
);
-- "which chapters am I in?" — the reverse lookup (member count is the forward one, served by the PK).
CREATE INDEX IF NOT EXISTS idx_chapter_member_user ON chapter_member (user_uuid);

-- ---------------------------------------------------------------------------------------------
-- 3. chapter_post — ANNOUNCEMENTS. Durable, findable. "DMV meetup Saturday the 14th, here's
--    the address." (Chat is the throwaway half; it lives in messages, see §4.)
--    Comments on these REUSE discovery.comments (post_type='chapter_post', item_id=chapter_post.id)
--    — a different DATABASE, reached over a second PDO. No second comments store. See the
--    companion grants migration.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chapter_post (
    id          bigserial   PRIMARY KEY,
    chapter_id  bigint      NOT NULL REFERENCES chapter(id) ON DELETE CASCADE,
    author_uuid uuid        NOT NULL REFERENCES users(uuid),
    title       text,                              -- optional; announcements are meant to be FINDABLE
    body        text        NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    edited_at   timestamptz,
    deleted_at  timestamptz                        -- SOFT delete: hard-deleting would orphan the
                                                   -- comment rows in the other database.
);
CREATE INDEX IF NOT EXISTS idx_chapter_post_feed
    ON chapter_post (chapter_id, created_at DESC) WHERE deleted_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- 4. THE CHAT ROOM. A ROOM IS NOT A MULTI-RECIPIENT DM.
--
--    A non-null chapter_id makes a message_thread a ROOM. Membership is DERIVED from
--    chapter_member and is NEVER enumerated into message_recipients.
--
--    THIS IS THE WHOLE TRICK, AND IT IS ALSO THE ISOLATION PROOF:
--      * DM authorization goes through message_recipients (Messaging::isRecipient/canSendTo).
--      * A room has ZERO message_recipients rows.
--      * => every existing DM endpoint REJECTS a room by construction. No code change needed to
--           keep rooms out of the DM inbox, and no risk of a room leaking into someone's DMs.
--      * Conversely ChapterChat gates on chapter_member and REFUSES any thread with a NULL
--           chapter_id, so a room endpoint can never be pointed at someone's private DM.
--    The two models are mutually exclusive at the data layer, not by convention.
--
--    AND it is why a send is 1 INSERT: writing a message to a 1,841-member room does NOT touch
--    message_recipients.unread_count (a denormalized per-recipient counter — 1,841 UPDATEs per
--    message, which is exactly what we are refusing to build).
-- ---------------------------------------------------------------------------------------------
ALTER TABLE message_threads
    ADD COLUMN IF NOT EXISTS chapter_id bigint REFERENCES chapter(id) ON DELETE CASCADE;

-- One room per chapter. Partial, so the 445 existing DM threads (chapter_id NULL) are unaffected.
CREATE UNIQUE INDEX IF NOT EXISTS uq_message_threads_chapter
    ON message_threads (chapter_id) WHERE chapter_id IS NOT NULL;

COMMENT ON COLUMN message_threads.chapter_id IS 'Non-null => this thread IS a chapter chat room. Membership derived from chapter_member; NO message_recipients rows exist for rooms.';

-- ---------------------------------------------------------------------------------------------
-- 5. chapter_room_read — READ STATE AS A WATERMARK, NOT A COUNTER.
--
--    unread = COUNT(messages WHERE thread_id = room AND id > last_read_message_id)
--
--    Cardinality is one row per member per room — the same order as chapter_member — and rows are
--    written ONLY on join (watermark = current max, so a new joiner does not inherit a backlog)
--    and on read. NOT on send. Contrast message_recipients.unread_count, which must be UPDATEd
--    for every recipient on every message.
--
--    NOTIFICATION SAFETY: this is also why the chapter room writes ZERO rows to `notifications`.
--    The unread badge is computed on demand from this watermark, so a busy 1,841-member room
--    cannot produce an email/push/bell incident — there is nothing to fan out. (The notifications
--    lane owns notifications.type + the bell; per the board contract, chapter events will arrive
--    via THEIR ingest API, gated + muteable, rather than this lane widening their schema.)
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chapter_room_read (
    thread_id            bigint      NOT NULL REFERENCES message_threads(id) ON DELETE CASCADE,
    user_uuid            uuid        NOT NULL REFERENCES users(uuid)         ON DELETE CASCADE,
    last_read_message_id bigint      NOT NULL DEFAULT 0,
    muted                boolean     NOT NULL DEFAULT false,
    updated_at           timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (thread_id, user_uuid)          -- same key order as message_recipients
);

-- The unread COUNT above filters `thread_id = ? AND id > ?`. The existing index on messages is
-- idx_messages_thread (thread_id, created_at) — which cannot serve an `id >` range and would
-- degrade to scanning the whole room. This makes it an index-only range scan from the watermark.
CREATE INDEX IF NOT EXISTS idx_messages_thread_id ON messages (thread_id, id);

-- ---------------------------------------------------------------------------------------------
-- 6. Grants. Owner (profile-app, the FPM pool user, peer auth) gets DML implicitly.
--    looth_ro is the read-only reporting role. NOTE: profile_app's default ACL only auto-grants
--    to looth_ro for tables created BY postgres — these are created by profile-app, so the grants
--    must be explicit or looth_ro silently cannot see chapters.
-- ---------------------------------------------------------------------------------------------
GRANT SELECT ON chapter, chapter_member, chapter_post, chapter_room_read TO looth_ro;

COMMIT;

-- ---------------------------------------------------------------------------------------------
-- 7. SEED: DMV Looths.  Idempotent (ON CONFLICT), and membership starts EMPTY (opt-in).
--
--    Centre 38.9047,-77.0369 = Washington, DC.
--    Radius 160 km (~100 miles) — chosen to cover the actual DMV catchment: DC + the Maryland
--    suburbs + Baltimore (~63km) + the Northern Virginia crescent out past Fredericksburg, without
--    swallowing Philadelphia (~200km) or Richmond (~145km — inside 160km, and that is deliberate:
--    Richmond has no chapter of its own, and a Richmond luthier is a plausible DMV member).
--    100 miles is also comfortably inside the pins API's 1..500 mile clamp.
-- ---------------------------------------------------------------------------------------------
INSERT INTO chapter (slug, name, description, center_lat, center_lng, radius_km)
VALUES (
    'dmv-looths',
    'DMV Looths',
    'Luthiers, techs and players around DC, Maryland and Virginia. Meetups, benches, gear and local help.',
    38.904700, -77.036900, 160
)
ON CONFLICT (slug) DO NOTHING;

-- Its chat room. One per chapter, created with the chapter — a room with no messages is fine, a
-- chapter with no room is a 500 waiting to happen.
INSERT INTO message_threads (chapter_id, subject)
SELECT c.id, c.name
  FROM chapter c
 WHERE c.slug = 'dmv-looths'
   AND NOT EXISTS (SELECT 1 FROM message_threads t WHERE t.chapter_id = c.id);

-- Verify post-apply:
--   \d chapter
--   SELECT c.id, c.slug, c.name, c.center_lat, c.center_lng, c.radius_km,
--          t.id AS room_thread_id, t.uuid AS room_uuid
--     FROM chapter c JOIN message_threads t ON t.chapter_id = c.id WHERE c.slug='dmv-looths';
--   SELECT count(*) FROM chapter_member;                    -- expect 0 (opt-in, starts empty)
--   SELECT count(*) FROM message_recipients mr JOIN message_threads t ON t.id=mr.thread_id
--    WHERE t.chapter_id IS NOT NULL;                        -- expect 0 — rooms NEVER enumerate recipients
