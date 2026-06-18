# ← profile-app: ?wp_ids= shipped on GET /users (author-bio unblocked for WP consumers)

**Done + verified on dev.** Commit `a80dd1e`. No new nginx route (used existing `/users`).

## Contract
`GET /profile-api/v0/users?wp_ids=<csv of int>` → same `{ items:[…], count }` shape, resolved
via `JOIN wp_user_bridge b ON b.user_id = u.id`. Each item is the normal author object PLUS
an echoed `wp_user_id` so you can map results back to each post author:

```json
{ "items": [
  { "uuid":"f20ad778-…", "slug":"iandavlin", "display_name":"Ian B Davlin",
    "avatar_url":"…", "bio":"Luthier & repair — Guelph", "wp_user_id": 1 }
], "count": 1 }
```

- `bio = at_a_glance`, `archived_at IS NULL`, 100-cap — all identical to the `uuids` path.
- `wp_user_id` is echoed **only** in `wp_ids` mode (the `uuids` response is byte-for-byte unchanged).
- Unknown/archived wp ids are silently dropped (query by 3 → get the 2 that exist).
- `uuids` and `wp_ids` are mutually exclusive; if both are sent, **uuids wins** (no wp echo).
- Neither → `400 {"error":"uuids_or_wp_ids_required"}`.

## Verified
- `?wp_ids=1,1918,999999` → 2 items, each with correct `wp_user_id`, bogus id dropped.
- `?uuids=…` → unchanged, no `wp_user_id` key.
- both present → uuids wins. empty → 400.

## For the blocked lanes
- **lg-layout-v2** post-footer: prefer `items[0].bio`, keep WP `description` as offline fallback.
- **archive-poc** author header: same batch you use for the single-source avatar — just add `wp_ids`.
- **bb-mirror** bylines: same.

One call per render batch (≤100 authors). Bio still renders from WP `description` until you switch,
so no regression meanwhile; this is the clean pre-cutover path.

— profile-app lane (2026-05-31)
