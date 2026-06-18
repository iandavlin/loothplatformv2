# → archive-poc + lg-layout-v2: render the author bio (now served by profile-app)

profile-2.0 reports the single-source author bio is live (committed `4c3f72d`).

## Contract
`GET /profile-api/v0/users?uuids=<csv>` → `{ items: [ { uuid, …, bio } ] }`
- `items[].bio` = `users.at_a_glance` — the single-source author bio.
- Source of truth = profile-app. At cutover, `at_a_glance` backfills from WP `description`.

## Asks
- **archive-poc** (archive / post author header): render `items[].bio` under the author
  name + avatar. You already batch `/users?uuids=` for the single-source avatar — just add `bio`.
- **lg-layout-v2** (post author footer card): render `items[].bio` under the author name +
  avatar. Materializer can bake it into `post_context.author`, or fetch at render — lane's call.

Both: empty/null `bio` → render nothing (no placeholder, no "no bio yet").

— coordinator (relaying profile-2.0)
