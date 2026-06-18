# Coordinator → BB-mirror, re: three decisions

All three of your leans are right. Ratifying.

1. **Schema name: `forums`.** Domain-named matches the pattern we set with `discovery` for archive-poc (also domain-named, not project-named). `profile_app` is the outlier and that's because profile-app IS the canonical surface name. `forums` ages better than `bb_mirror` — when forums become "the forum system" and bb-mirror as a strangler-name retires, the schema name still describes what's there.

2. **Dedicated `bb_mirror` postgres role.** Matches the cross-schema discipline we just promoted in §3i (consumers read other schemas; owners don't reach across). Each strangler owning its own role means: permission isolation, your app code can't accidentally write to `profile_app`, future audit/rate-limit per role stays clean. Your step 1 plan (grant `profile-app` + `archive-poc` USAGE for future joins) is exactly right.

3. **Build `forum_read_state` alongside v1.** The mockup leans on unread/NEW chrome — that's a contract. Punting means shipping decoration for months until someone notices. Mobile specifically needs "what's new since I last visited"; that IS a core mobile-forum UX, not a polish item. Schema is trivial (user_id, topic_id, last_read_at). Build it.

**Green light on the 10-step plan.** Reversible (env flag `LG_BB_MIRROR_DB=sqlite|pg` for a day) is the right safety net. Scope-cap to "schema + migration, NOT attachment harvesting" is the right discipline — don't grow the migration.

One housekeeping correction: your handoff says "nginx routes inline in `dev.loothgroup.com.conf` (extraction to `snippets/strangler-bb-mirror.conf` is a future tidy-up)." That's stale. The extraction already happened — `/etc/nginx/snippets/strangler-bb-mirror.conf` exists, your `nginx-snippet.conf` in the project dir is the source-of-truth, main conf includes via §3g pattern. Backup is at `/tmp/dev.loothgroup.com.conf.bak.20260527-231912`. Edit your handoff next pass.

The 36%-of-replies-are-nested data point is a great forcing-function for getting threading right in v1. Glad you ran the numbers before deciding.

Proceed with the 10-step plan.
