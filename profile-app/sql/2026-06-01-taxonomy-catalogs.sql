-- profile-2.0: split the "craft" block into Skills / Services / Instruments / Music.
-- Skills + Instruments already have catalogs (skill_catalog / instrument_catalog) and link
-- tables (profile_skills / profile_instruments). This adds the two new catalog-backed blocks:
--   Services  → service_catalog + profile_services   (curated, like skills)
--   Music     → genre_catalog   + profile_genres     (musical styles/genres, chip tags)
-- Admins can add/deactivate catalog rows from the front-end picker (active flag = soft delete).

-- ---------- Services ----------
CREATE TABLE IF NOT EXISTS service_catalog (
    id          bigserial PRIMARY KEY,
    slug        text NOT NULL UNIQUE,
    name        text NOT NULL,
    category    text,
    active      boolean NOT NULL DEFAULT true,
    sort_order  integer NOT NULL DEFAULT 100
);
CREATE TABLE IF NOT EXISTS profile_services (
    user_id     bigint  NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    service_id  bigint  NOT NULL REFERENCES service_catalog(id) ON DELETE CASCADE,
    sort_order  integer NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, service_id)
);

INSERT INTO service_catalog (slug, name, category, sort_order) VALUES
  ('setup-intonation','Setup & intonation','setup',10),
  ('fret-level-crown','Fret level, crown & polish','repair',20),
  ('refret','Refret','repair',30),
  ('partial-refret','Partial refret','repair',40),
  ('neck-reset','Neck reset','repair',50),
  ('nut-fabrication','Nut fabrication','repair',60),
  ('saddle-fabrication','Saddle fabrication','repair',70),
  ('bridge-reglue','Bridge reglue','repair',80),
  ('bridge-replacement','Bridge replacement','repair',90),
  ('crack-repair','Crack repair','repair',100),
  ('brace-repair','Brace repair / reglue','repair',110),
  ('headstock-repair','Headstock / break repair','repair',120),
  ('full-refinish','Full refinish','finish',130),
  ('finish-touchup','Finish touch-up','finish',140),
  ('french-polish','French polish','finish',150),
  ('electronics-wiring','Electronics & wiring','electronics',160),
  ('pickup-install','Pickup installation','electronics',170),
  ('pickguard-fabrication','Pickguard fabrication','fabrication',180),
  ('inlay-work','Inlay work','fabrication',190),
  ('restoration','Restoration','build',200),
  ('custom-build','Custom build','build',210),
  ('appraisal','Appraisal','business',220)
ON CONFLICT (slug) DO NOTHING;

-- ---------- Music (genres / styles) ----------
CREATE TABLE IF NOT EXISTS genre_catalog (
    id          bigserial PRIMARY KEY,
    slug        text NOT NULL UNIQUE,
    name        text NOT NULL,
    active      boolean NOT NULL DEFAULT true,
    sort_order  integer NOT NULL DEFAULT 100
);
CREATE TABLE IF NOT EXISTS profile_genres (
    user_id     bigint  NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    genre_id    bigint  NOT NULL REFERENCES genre_catalog(id) ON DELETE CASCADE,
    sort_order  integer NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, genre_id)
);

INSERT INTO genre_catalog (slug, name, sort_order) VALUES
  ('jazz','Jazz',10),('gypsy-jazz','Gypsy jazz',20),('blues','Blues',30),
  ('rock','Rock',40),('folk','Folk',50),('bluegrass','Bluegrass',60),
  ('country','Country',70),('classical','Classical',80),('flamenco','Flamenco',90),
  ('fingerstyle','Fingerstyle',100),('singer-songwriter','Singer-songwriter',110),
  ('metal','Metal',120),('punk','Punk',130),('funk','Funk',140),('soul','Soul / R&B',150),
  ('reggae','Reggae',160),('celtic','Celtic',170),('world','World',180),
  ('indie','Indie',190),('ambient','Ambient',200)
ON CONFLICT (slug) DO NOTHING;
