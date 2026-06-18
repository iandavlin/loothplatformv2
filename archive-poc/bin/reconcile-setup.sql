-- archive-poc/bin/reconcile-setup.sql
-- One-time setup for bin/reconcile-pg.php's bookmark store. Apply ONCE per env
-- as the discovery schema owner (archive-poc):
--   sudo -u archive-poc psql "host=/var/run/postgresql dbname=looth" -f bin/reconcile-setup.sql
-- Idempotent: safe to re-run.

CREATE TABLE IF NOT EXISTS discovery.sync_state (
    key        text PRIMARY KEY,
    value      text,
    updated_at timestamptz DEFAULT now()
);

-- The reconcile runs as the WP writer (looth-dev on dev, looth-live on live) —
-- the same role the _sync / _materialize FPM pool uses. Grant whichever exists
-- the bookmark read/write it needs (it can't CREATE, only DML the row).
DO $$
DECLARE r text;
BEGIN
    FOREACH r IN ARRAY ARRAY['looth-dev','looth-live'] LOOP
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
            EXECUTE format('GRANT SELECT, INSERT, UPDATE ON discovery.sync_state TO %I', r);
        END IF;
    END LOOP;
END $$;
