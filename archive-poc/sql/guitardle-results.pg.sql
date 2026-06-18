-- archive-poc/sql/guitardle-results.pg.sql
--
-- Daily Guitardle results for LOGGED-IN members (guitardle front-page block,
-- Ian 2026-06-11). The game itself is a static vendored app (web/guitardle/)
-- playable by everyone; only members get a row here, written by
-- api/v0/guitardle-score.php on the looth-dev WP pool (gate = the WP login
-- cookie + nonce, same door as comment-post/card-react — an unbridged member
-- is anon to /whoami but must still be able to score).
--
-- This is the FUTURE LEADERBOARD's source of truth: recording starts now, the
-- leaderboard UI ships later (Ian's call). One row per member per day; the
-- FIRST result wins (ON CONFLICT DO NOTHING in the endpoint) so replaying
-- after clearing localStorage can't overwrite a recorded score.
--
-- `streak` is the CLIENT's localStorage streak at play time — display data,
-- not authority. A real leaderboard streak can always be derived from the
-- (wp_user_id, play_date, won) history.
--
-- Apply as the schema OWNER (archive-poc) so the standing default privileges
-- give looth-dev (writer) and profile-app (reader) their grants automatically.
-- search_path assumed `discovery, public`.

CREATE TABLE IF NOT EXISTS guitardle_results (
  id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  wp_user_id  BIGINT  NOT NULL,                  -- get_current_user_id(), server-derived
  play_date   DATE    NOT NULL,                  -- server date, not client
  phrase_id   INTEGER NOT NULL DEFAULT 0,        -- which puzzle (sequence id)
  won         BOOLEAN NOT NULL,
  moves       INTEGER NOT NULL CHECK (moves BETWEEN 1 AND 99),
  streak      INTEGER NOT NULL DEFAULT 0 CHECK (streak >= 0),
  hardcore    BOOLEAN NOT NULL DEFAULT false,  -- opt-in capped-reveal mode; wins worth 2x on the board (client-claimed, like moves)
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (wp_user_id, play_date)
);

-- Leaderboard reads will be by day ("today's board") and by user (streak math).
CREATE INDEX IF NOT EXISTS idx_guitardle_results_date ON guitardle_results (play_date);
