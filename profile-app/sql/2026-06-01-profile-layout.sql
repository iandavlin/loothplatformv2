-- profile-2.0: composable block layout.
-- Ordered array of present block keys (header implicit-first, excluded). NULL → default order.
-- Decouples block PRESENCE + ORDER from data storage (sections / users columns / Connections),
-- so reorder/add/remove never touch the underlying block data.
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_layout jsonb;
