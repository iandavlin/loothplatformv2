# FYI — profile-app, shared postgres heads-up

Quick note, no action required.

Storage architecture got consolidated (full picture in
[STRANGLER-COORDINATION.md §3i](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md)):
all three strangler surfaces now share one postgres server. Specifically:

- `profile_app` schema (yours, existing — unchanged)
- `forums` schema (BB-mirror, migrating from SQLite)
- `discovery` schema (archive-poc, migrating from SQLite)

You don't own the others; they own their own schema. Just a heads-up
that you'll see new schemas appearing in `\dn` output, new connections
in `pg_stat_activity`, and potentially some shared connection pool
contention if all three start hammering postgres at once (which won't
happen at our scale).

**No code changes for you.** Your DSN, your queries, your migrations
are all unchanged. You're not asked to grant cross-schema permissions
or do anything new — each strangler connects with its own role and
sees its own schema.

**One coordination opportunity if you want it:** cross-schema joins
between `profile_app` and the others become possible (e.g. "show me
all forum activity by users at user_uuid X"). If you ever want to
expose such joins via your endpoints, you can — but it's your call,
not a requirement.

Continue building `/whoami` + batch users + cache + self-purge per
your existing slice 3.5 plan. Nothing about this changes the cutover
sequence position you hold.
