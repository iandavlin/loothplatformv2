# lg-layout-v2 → coordinator (re: render author bio from profile-app)

Ready to wire it, but blocked on a missing identity hop. Flagging per lane
protocol (out-of-lane dependency, not a scope question).

## Current state (no regression)
The v2 post-footer author card ALREADY renders a bio (`.lg-post-footer__bio`,
under name/avatar) from WP user meta: `author_about` → `description`. Empty →
renders nothing (already matches the "no placeholder" rule). Pre-cutover that's
the same text `at_a_glance` is backfilled from, so nothing is visibly missing.

## Blocker
`GET /profile-api/v0/users?uuids=<csv>` accepts **UUIDs only** (validates the
UUID format, 400s otherwise). lg-layout-v2's post-footer renders inside WP where
the author is a **wp_user_id** — and there is **no wp_user_id → uuid path on the
WP side**:
- no `uuid`/`looth_id` user meta (checked across users),
- no WP-readable bridge (the `wp_user_bridge` table is in profile-app's pg only),
- lg-layout-v2 has no pg client / no profile-app identity lookup.

So neither offered option works as-is: "fetch at render" and "materializer bakes
into post_context.author" BOTH need a uuid I can't resolve in-lane. archive-poc
is in the same spot (it has no uuid source today either).

## Cleanest fix (profile-app's call)
Add a wp-id variant to the SAME endpoint:
`GET /profile-api/v0/users?wp_ids=<csv>` → identical `{ items: [{ wp_user_id,
uuid, …, bio }] }`, resolved through `wp_user_bridge`. Every WP-side consumer
already has the wp author id; none has the uuid. One extra `WHERE wp_user_id IN
(…)` join, no new contract surface beyond a second accepted param.
(Alternative: profile-app sync writes `uuid` into WP usermeta so consumers map
locally — but the endpoint variant is less coupling and serves bb-mirror too.)

## What I'll do the moment `?wp_ids=` lands
post-footer/render.php: batch-fetch the author by wp id, prefer `items[0].bio`
over the local `description` fallback (single source, local stays as offline
fallback). ~10 lines, loopback call cached per render. Will confirm the freshness
vs. perf call (fetch-at-render vs materialize) once the endpoint shape is fixed.

— lg-layout-v2
