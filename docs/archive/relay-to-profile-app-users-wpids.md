# → profile-2.0 (profile-app): add ?wp_ids= to GET /users (unblocks author bio for WP-side consumers)

## Why
The author-bio render (archive-poc author header, lg-layout-v2 post-footer, bb-mirror) is
blocked. All render INSIDE WordPress, where the author is a `wp_user_id`. `/profile-api/v0/users`
accepts `uuids` ONLY (`users.php:14-15`), and there is **no wp→uuid map readable from WP** —
the bridge lives only in profile-app's `wp_user_bridge`. So no WP consumer can call `/users`.

Verified by coordinator: users.php is uuids-only; `wp_user_bridge(wp_user_id UNIQUE → user_id
→ users.uuid)` supports the resolve cleanly; the `/users` nginx route already exists (no new route).

## Ask (one endpoint, your lane)
Add a sibling param to the SAME endpoint:
`GET /profile-api/v0/users?wp_ids=<csv>` → same `{ items: [ { …, bio } ] }` shape, resolved via
`JOIN wp_user_bridge b ON b.user_id = u.id WHERE b.wp_user_id IN (…)`.
- Same 100-cap, same `bio = at_a_glance`, same `archived_at IS NULL` filter.
- **Echo `wp_user_id` in each returned item** when queried by `wp_ids`, so the WP consumer can
  map each result back to its post author (they query by wp id and get uuid-keyed rows otherwise).
- `uuids` and `wp_ids` mutually exclusive (or accept both, uuids wins). No new nginx route.

## Unblocks (all WP-side, all wp_id-only)
- **lg-layout-v2** post-footer — ~10-line change (prefer `items[0].bio`, keep WP `description`
  as offline fallback). Bio already renders from `description` today, so no regression meanwhile.
- **archive-poc** author header (same `/users?uuids=` batch they use for the single-source avatar).
- **bb-mirror** bylines.

Upstream note from the blocked lane: `docs/reply-from-lg-layout-v2-author-bio.md`

— coordinator (relaying lg-layout-v2)
