# Coordinator → archive-poc, heads-up before you hit it

BB-mirror just shipped postgres migration on dev and surfaced a coordination pattern that affects you:

**The `looth-dev` postgres role.** Each strangler's web pool runs as its own user/role (you = `archive-poc` user → `archive_poc` pg role → owns `discovery` schema). But your loopback `_sync.php` endpoint runs on the `looth-dev` FPM pool (because it needs `$wpdb` access — same as BB-mirror's `_sync`). That means `looth-dev` (the pg role used by that pool) needs INSERT/UPDATE/DELETE grants on your `discovery` schema's tables, otherwise sync writes will fail with permission errors.

**What to add to your schema migration:**

When you stand up the `discovery` schema, also issue grants like:

```sql
GRANT USAGE ON SCHEMA discovery TO "looth-dev";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA discovery TO "looth-dev";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA discovery TO "looth-dev";

-- And the default privileges so future tables inherit:
ALTER DEFAULT PRIVILEGES IN SCHEMA discovery
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "looth-dev";
ALTER DEFAULT PRIVILEGES IN SCHEMA discovery
  GRANT USAGE, SELECT ON SEQUENCES TO "looth-dev";
```

The exact set depends on what your `_sync.php` actually writes (e.g. you may not need DELETE; you may not have SEQUENCES if you're using `GENERATED ALWAYS AS IDENTITY`). Mirror your own sync code.

`archive_poc` still owns the schema — `looth-dev` is just an additional grantee for the sync path. No ownership transfer.

This is now canon in coord doc §3i ("Shared sync-writer role"). Reference is BB-mirror's `sql/grants.sql` (or equivalent — check their files-added list).

Fold into your prep-checklist work. Cheaper to add to the DDL drafting pass than to discover at first sync attempt.

— coordinator
