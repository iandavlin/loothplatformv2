-- profile-app slice 2: catalogs + relation tables.
-- Curated catalogs (instruments / skills / credentials / scenes) and the
-- per-user join tables that hang off them. Credentials are polymorphic-prep
-- for practices in slice 3 (owner_type discriminator + nullable catalog_id).

BEGIN;

-- ----- catalogs --------------------------------------------------------
CREATE TABLE instrument_catalog (
    id          BIGSERIAL PRIMARY KEY,
    slug        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    type        TEXT,                -- 'guitar' | 'bass' | 'bowed' | …
    subtype     TEXT,
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order  INT NOT NULL DEFAULT 100
);

CREATE TABLE skill_catalog (
    id          BIGSERIAL PRIMARY KEY,
    slug        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    category    TEXT,                -- 'repair' | 'build' | 'electronics' | 'tour' | 'fabrication' | 'studio'
    parent_id   BIGINT REFERENCES skill_catalog(id),
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order  INT NOT NULL DEFAULT 100
);

CREATE TABLE credential_catalog (
    id          BIGSERIAL PRIMARY KEY,
    slug        TEXT NOT NULL UNIQUE,
    category    TEXT NOT NULL CHECK (category IN ('warranty','certification','education','membership','license')),
    issuer      TEXT NOT NULL,
    program     TEXT NOT NULL,
    logo_url    TEXT,
    active      BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE scene_tags (
    slug        TEXT PRIMARY KEY,
    name        TEXT NOT NULL,
    active      BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order  INT NOT NULL DEFAULT 100
);

-- ----- per-user relation tables ---------------------------------------
CREATE TABLE profile_instruments (
    user_id       BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    instrument_id BIGINT NOT NULL REFERENCES instrument_catalog(id),
    sort_order    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, instrument_id)
);
CREATE INDEX idx_profile_instruments_inst ON profile_instruments(instrument_id);

CREATE TABLE profile_skills (
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    skill_id   BIGINT NOT NULL REFERENCES skill_catalog(id),
    note       TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, skill_id)
);
CREATE INDEX idx_profile_skills_skill ON profile_skills(skill_id);

CREATE TABLE profile_scenes (
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scene_slug TEXT   NOT NULL REFERENCES scene_tags(slug),
    PRIMARY KEY (user_id, scene_slug)
);
CREATE INDEX idx_profile_scenes_slug ON profile_scenes(scene_slug);

-- Polymorphic-prep: slice 2 only writes owner_type='profile' rows;
-- slice 3 will add owner_type='practice' for practice credentials.
CREATE TABLE profile_credentials (
    id          BIGSERIAL PRIMARY KEY,
    owner_type  TEXT NOT NULL DEFAULT 'profile'
                  CHECK (owner_type IN ('profile','practice')),
    owner_id    BIGINT NOT NULL,
    catalog_id  BIGINT REFERENCES credential_catalog(id),
    raw_issuer  TEXT NOT NULL,
    raw_program TEXT NOT NULL,
    identifier  TEXT,
    issued_at   DATE,
    expires_at  DATE,
    evidence_url TEXT,
    visibility  TEXT NOT NULL DEFAULT 'members'
                  CHECK (visibility IN ('public','members','private')),
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_profile_credentials_owner ON profile_credentials(owner_type, owner_id, sort_order);

CREATE TABLE profile_highlights (
    user_id    BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT NOT NULL CHECK (kind IN ('instrument','skill')),
    ref_id     BIGINT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, kind, ref_id)
);
CREATE INDEX idx_profile_highlights_user ON profile_highlights(user_id, sort_order);

COMMIT;
