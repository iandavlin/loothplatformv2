-- profile-app — slice one schema additions.
--
-- Adds the editor's data plane:
--   profiles         — exists iff user has visited /profile/edit (lazy claim)
--   profile_sections — per-(user, key) jsonb section with visibility
--   profile_socials  — typed list of socials
--
-- Also extends users with location-precision grants + raw Google Places result.

BEGIN;

-- ----- users extensions ---------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS location_grant_public  TEXT NOT NULL DEFAULT 'city',
    ADD COLUMN IF NOT EXISTS location_grant_members TEXT NOT NULL DEFAULT 'city',
    ADD COLUMN IF NOT EXISTS location_grant_friends TEXT NOT NULL DEFAULT 'address',
    ADD COLUMN IF NOT EXISTS place_result           JSONB;

-- precision values: 'address' | 'city' | 'region' | 'country' | 'hidden'
ALTER TABLE users
    ADD CONSTRAINT users_loc_grant_public_ck
        CHECK (location_grant_public  IN ('address','city','region','country','hidden')),
    ADD CONSTRAINT users_loc_grant_members_ck
        CHECK (location_grant_members IN ('address','city','region','country','hidden')),
    ADD CONSTRAINT users_loc_grant_friends_ck
        CHECK (location_grant_friends IN ('address','city','region','country','hidden'));

-- ----- profiles -----------------------------------------------------------
CREATE TABLE profiles (
    user_id    BIGINT      PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    claimed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TRIGGER profiles_touch BEFORE UPDATE ON profiles
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ----- profile_sections ---------------------------------------------------
CREATE TABLE profile_sections (
    id           BIGSERIAL   PRIMARY KEY,
    user_id      BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key          TEXT        NOT NULL,                                 -- 'about' | future: 'credentials' | 'practices' | …
    visibility   TEXT        NOT NULL DEFAULT 'members',               -- 'public' | 'members' | 'private'
    data         JSONB       NOT NULL DEFAULT '{}'::jsonb,
    sort_order   INT         NOT NULL DEFAULT 0,
    enabled_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (user_id, key),
    CONSTRAINT profile_sections_vis_ck CHECK (visibility IN ('public','members','private'))
);
CREATE INDEX idx_profile_sections_user ON profile_sections(user_id);
CREATE TRIGGER profile_sections_touch BEFORE UPDATE ON profile_sections
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ----- profile_socials ----------------------------------------------------
CREATE TABLE profile_socials (
    id         BIGSERIAL   PRIMARY KEY,
    user_id    BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT        NOT NULL,
    value      TEXT        NOT NULL,
    sort_order INT         NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT profile_socials_kind_ck CHECK (kind IN (
        'instagram','youtube','bandcamp','web','email','phone',
        'x','tiktok','facebook','patreon'
    ))
);
CREATE INDEX idx_profile_socials_user ON profile_socials(user_id, sort_order);

COMMIT;
