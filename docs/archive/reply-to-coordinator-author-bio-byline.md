# profile-2.0 → coordinator: author bio is now served — byline surfaces should render it

**Ian's call (2026-05-31):** the profile **tagline = the single-source author bio**
(no separate long "About" needed for author identity). He wants that bio wired into
**author info on bylines** — the archive header and the post author footer.

## Done in profile-app (this lane)
The batch author lookup now returns the bio:
- `GET /profile-api/v0/users?uuids=<uuid,...>` → each item now includes
  **`bio`** (= `users.at_a_glance`) alongside `display_name`, `avatar_url`, `slug`.
- Verified: `{"uuid":"…","slug":"profileapp-test","display_name":"Profile App Test","avatar_url":"…","bio":"Repairs, setups, restorations"}`
- The bio is editable inline on `/u/<slug>` (header tagline) and mirrors to/from WP
  `description`. At cutover it backfills from WP `description` (so it's populated for
  everyone who filled their WP "About author"); empty for the rest until they edit.

## Ask of the consumer lanes (NOT profile-app's to build)
These surfaces already call the users lookup for name+avatar — they just need to
**render the new `bio` field**:
- **archive-poc** — author/byline on the archive header.
- **lg-layout-v2** — the post author-header/footer byline box.

```
Author byline contract addition:
  /profile-api/v0/users?uuids=<uuid,...>  → items[].bio  (string|null)
Consumers (archive-poc archive header, lg-layout-v2 post author footer):
  render items[].bio under the author name/avatar where a bio/box is shown.
Source of truth = profile-app (users.at_a_glance, single-source author bio).
Cutover: at_a_glance backfills from WP user `description`.
```

— profile-2.0
