-- Slice 3: practices as first-class entities with their own /p/<slug>.
-- Many-to-many to users via practice_members.
--
-- Defaults to location_visibility='public' (vs users' 'members') because
-- a practice page is the storefront — the whole point is to be findable.

CREATE TABLE practices (
    id            bigserial PRIMARY KEY,
    uuid          uuid       NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    slug          text       NOT NULL UNIQUE,
    name          text       NOT NULL,
    tagline       text,
    about         text,
    website       text,
    location_text text,
    lat           numeric(9,6),
    lng           numeric(9,6),
    location_country  text,
    location_region   text,
    location_city     text,
    location_postcode text,
    location_visibility text NOT NULL DEFAULT 'public'
        CHECK (location_visibility IN ('public','members','private')),
    avatar_url    text,
    archived_at   timestamptz,
    created_by    bigint REFERENCES users(id),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_practices_slug ON practices(slug);
CREATE INDEX idx_practices_archived ON practices(archived_at) WHERE archived_at IS NULL;

CREATE TABLE practice_members (
    practice_id bigint NOT NULL REFERENCES practices(id) ON DELETE CASCADE,
    user_id     bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role        text   NOT NULL DEFAULT 'staff'
        CHECK (role IN ('owner','staff')),
    sort_order  integer NOT NULL DEFAULT 0,
    added_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (practice_id, user_id)
);
CREATE INDEX idx_practice_members_user ON practice_members(user_id);

CREATE TRIGGER practices_touch BEFORE UPDATE ON practices
  FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Catalog additions. skill_catalog is broad enough (already includes
-- tour-tech, machinist-work, etc.) that Retail Sales + Tool Maker land
-- here under a new "business" category rather than spinning a separate
-- specialties catalog.
INSERT INTO skill_catalog (slug, name, category, sort_order)
VALUES ('retail-sales', 'Retail Sales', 'business', 100),
       ('tool-maker',   'Tool Maker',   'business', 100);
