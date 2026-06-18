-- Header "status lights" (availability widgets): a small map of {light_key: state}, e.g.
-- {"work":"open","collab":"closed"}. Absent key = light not shown. NULL = no lights.
ALTER TABLE users ADD COLUMN IF NOT EXISTS header_lights jsonb;
