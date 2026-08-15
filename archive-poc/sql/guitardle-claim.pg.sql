-- archive-poc/sql/guitardle-claim.pg.sql
--
-- Backlog 22 (Ian 2026-08-14, "fixing the guitardle giving more chances on
-- different devices"). Makes the daily attempt an ACCOUNT-level claim taken at
-- the START of a game rather than a result recorded at the END.
--
-- ── WHY THE OLD SHAPE LEAKED ────────────────────────────────────────────────
-- guitardle_results already had UNIQUE (wp_user_id, play_date) + ON CONFLICT DO
-- NOTHING, so device-hopping AFTER finishing was already blocked (measured on
-- live: 93 successful POSTs -> 93 rows over 7 days, zero duplicate writes).
-- The hole was ABANDONING: nothing was written until handleWin/handleLoss, and
-- the mid-game snapshot lived in localStorage, i.e. per DEVICE. So a player
-- could reveal letters until the phrase was readable, close the tab (no row, no
-- lock, no trace), reopen in incognito and guess it in ONE move for 10 points --
-- 20 with hardcore. That emitted exactly one POST and one row, indistinguishable
-- from honest play, which is why the unique constraint never saw it. Live
-- evidence: WP 197 was 27 plays / 27 wins / every win <=4 moves / 7 of them in a
-- single move (guessed cold, zero reveals), against a field whose best average
-- is 4.1 moves.
--
-- ── WHAT CHANGES, AND WHAT DELIBERATELY DOES NOT ────────────────────────────
-- NO NEW CONSTRAINT AND NO NEW INDEX. The claim is just a row written earlier
-- with its result still NULL, so the EXISTING unique constraint delivers
-- one-allowance-per-member for free. That matters beyond tidiness: narrowing or
-- re-predicating a unique index would break every ON CONFLICT still compiled
-- against the old arbiter (42P10), which is a whole failure class avoided by
-- not touching it.
--
--   won / moves  DROP NOT NULL  -- a claimed-but-unfinished attempt has no result
--   claimed_at                  -- when the allowance was taken
--   resume_state                -- the mid-game position, so switching devices
--                                  RESUMES instead of blocking (the phone-dies
--                                  case; a pure lock would read as a bug)
--
-- "Unfinished" is `moves IS NULL`. A finished game always writes moves >= 1, and
-- the existing CHECK (moves BETWEEN 1 AND 99) still holds because a CHECK passes
-- on NULL -- so the constraint keeps its full strength for real results.
--
-- The BOARD needs no change and gets none: every aggregate it computes is
-- FILTER (WHERE r.won), and NULL is not true, so claim rows are invisible to it.
-- Its HAVING COUNT(*) FILTER (WHERE r.won) > 0 already drops a member who has
-- only claimed. Verified against guitardle-board.php before writing this.
--
-- Apply as the schema OWNER (archive-poc); search_path `discovery, public`.
-- Additive and idempotent -- safe to run on a box already carrying it.

ALTER TABLE guitardle_results ALTER COLUMN won   DROP NOT NULL;
ALTER TABLE guitardle_results ALTER COLUMN moves DROP NOT NULL;

ALTER TABLE guitardle_results ADD COLUMN IF NOT EXISTS claimed_at   TIMESTAMPTZ;
ALTER TABLE guitardle_results ADD COLUMN IF NOT EXISTS resume_state JSONB;

COMMENT ON COLUMN guitardle_results.claimed_at IS
  'When the daily allowance was taken (first move). NULL on rows written before backlog 22.';
COMMENT ON COLUMN guitardle_results.resume_state IS
  'Mid-game position for cross-device resume. Cleared when the game finishes.';

-- ── ROLLBACK HAZARD, READ BEFORE TURNING THE FLAG OFF ───────────────────────
-- The schema change is safe to leave in place with LG_GUITARDLE_DAILY_CLAIM OFF:
-- the OFF path never writes a claim row, and it reads finished results with an
-- explicit `moves IS NOT NULL`, so it cannot trip over one.
--
-- But if the flag is turned OFF while claim rows are OUTSTANDING (at most the
-- current day's), those members' real results would hit ON CONFLICT DO NOTHING
-- against their own claim row and be silently discarded. So the flag-OFF
-- procedure is TWO steps, not one:
--
--   1. set LG_GUITARDLE_DAILY_CLAIM false
--   2. DELETE FROM guitardle_results WHERE moves IS NULL;
--
-- Step 2 only ever removes attempts that were never finished, so it cannot
-- destroy a recorded score. Gate 37 asserts the OFF path is clean in both
-- states, including with a claim row present.
