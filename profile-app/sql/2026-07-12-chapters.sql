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
-- SCOPE (Ian, mid-lane 2026-07-12): "Lets put the chats on hold. Everything can be done from
--   discussions." The chapter CHAT ROOM is DEFERRED, not cancelled. DISCUSSIONS are now the single
--   chapter content surface — they carry both durable announcements AND throwaway chatter. So this
--   migration stands up chapter + membership + discussions only. A room slots back in LATER as ONE
--   nullable column with no rewrite (see the DEFERRED block at §4) — the model is kept extensible
--   for it, but nothing is built for it now.
--
-- WHY profile_app AND NOT looth/discovery:
--   A chapter is a SOCIAL/IDENTITY object. Every single thing it depends on already lives here —
--   users (identity, slug, avatar, lat/lng + the 5-column privacy matrix), notifications,
--   connections. Putting `chapter` in the `looth` DB would mean NO foreign key to users(uuid) is
--   even expressible (separate databases, no postgres_fdw, no dblink — verified). The one thing
--   that DOES live in `looth` is discovery.comments, and we reuse it over a second PDO connection
--   rather than standing up a second comments store (see 2026-07-12-chapters-comments-grants.looth.sql).
--
-- KEY CONVENTION FOLLOWED: profile_app splits its keys — social/graph tables FK to users(UUID)
--   (connections, messages, notifications, user_mutes); profile/attribute tables FK to users(id).
--   Chapters are social, so every member/author reference here is user_uuid. Matches the brief.

BEGIN;

-- ---------------------------------------------------------------------------------------------
-- 1. chapter — A CHAPTER IS A DATA ROW, NOT CODE.
--    Onboarding "Austin Looths" = INSERT one row. Zero code changes, zero deploy.
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
-- 3. chapter_post — A CHAPTER DISCUSSION. This is the SINGLE chapter content surface (Ian
--    2026-07-12: "everything can be done from discussions"). One row = one discussion TOPIC; it
--    carries both the durable announcement ("DMV meetup Saturday the 14th, here's the address")
--    and the throwaway chatter ("anyone actually coming?"). The table name stays chapter_post for
--    schema stability; conceptually it is a discussion, and its REPLIES are comment rows.
--
--    Replies REUSE discovery.comments (post_type='chapter_post', item_id=chapter_post.id) — a
--    different DATABASE, reached over a second PDO. No second comments store. See the companion
--    grants migration. Reactions come free the same way (discovery.card_reactions, same key).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chapter_post (
    id          bigserial   PRIMARY KEY,
    chapter_id  bigint      NOT NULL REFERENCES chapter(id) ON DELETE CASCADE,
    author_uuid uuid        NOT NULL REFERENCES users(uuid),
    title       text,                              -- optional; a discussion may be titled or just a body
    body        text        NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    edited_at   timestamptz,
    deleted_at  timestamptz                        -- SOFT delete: hard-deleting would orphan the
                                                   -- reply rows in the other database.
);
CREATE INDEX IF NOT EXISTS idx_chapter_post_feed
    ON chapter_post (chapter_id, created_at DESC) WHERE deleted_at IS NULL;

COMMENT ON TABLE chapter_post IS 'A chapter discussion topic (the single chapter content surface). Replies live in discovery.comments (post_type=chapter_post) over a second PDO. Soft-delete only.';

-- ---------------------------------------------------------------------------------------------
-- 4. DEFERRED — THE CHAT ROOM (Ian put chats on hold 2026-07-12; discussions cover the need).
--
--    We are NOT painting ourselves into a corner. When a room is revived, it is ONE nullable
--    column, no rewrite of anything above:
--
--        ALTER TABLE message_threads
--            ADD COLUMN chapter_id bigint REFERENCES chapter(id) ON DELETE CASCADE;
--        CREATE UNIQUE INDEX uq_message_threads_chapter
--            ON message_threads (chapter_id) WHERE chapter_id IS NOT NULL;
--
--    A non-null chapter_id makes a message_thread a ROOM whose membership is DERIVED from
--    chapter_member (never enumerated into message_recipients — that is the whole trick and the
--    isolation proof: a room has zero recipient rows, so every DM endpoint rejects it by
--    construction and a 1,841-member room is a 1-INSERT send). Read-state would be a watermark
--    table (chapter_room_read), NOT a per-recipient counter, so the room writes zero notification
--    rows. The full design is preserved in git history at commits 2b3891d / 54b1828 if revived.
--
--    NOTHING for the room is created here. Discussions are the surface today.
-- ---------------------------------------------------------------------------------------------

-- ---------------------------------------------------------------------------------------------
-- 5. Grants. Owner (profile-app, the FPM pool user, peer auth) gets DML implicitly.
--    looth_ro is the read-only reporting role. NOTE: profile_app's default ACL only auto-grants
--    to looth_ro for tables created BY postgres — these are created by profile-app, so the grants
--    must be explicit or looth_ro silently cannot see chapters.
-- ---------------------------------------------------------------------------------------------
GRANT SELECT ON chapter, chapter_member, chapter_post TO looth_ro;

COMMIT;

-- ---------------------------------------------------------------------------------------------
-- 6. SEED: DMV Looths.  Idempotent (ON CONFLICT), and membership starts EMPTY (opt-in).
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

-- Verify post-apply:
--   \d chapter
--   SELECT id, slug, name, center_lat, center_lng, radius_km FROM chapter WHERE slug='dmv-looths';
--   SELECT count(*) FROM chapter_member;   -- expect 0 (opt-in, starts empty)
