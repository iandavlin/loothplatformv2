# → MAP chat: handoff of directory/map changes (editor-lane chat overstepped)

Ian started a **profile-app EDITOR-lane** chat and then asked it to fix the map "to make it work,"
so that chat (me) edited your files. We were **both live in `directory-members.php` at the same
time** — our commits have since merged in the tree, but please review. From here the editor chat
**stops touching the map**; this is yours. All my edits were committed **by pathspec**.

## What the editor chat changed in YOUR domain (commits on `main`)
1. **`0c22ad1`** — `web/directory-members.php`: replaced the **broken Google Places** location
   autocomplete with a self-contained **client-side OSM Nominatim** autocomplete on `#dir-loc`
   (CORS, no key, no auth → works for anon viewers). Debounced 500ms, fills `#dir-lat/#dir-lng`,
   calls `applyFilters()`. Removed the `maps.googleapis.com` script. *(Heads-up: your later edits
   added markercluster + `directory.css` + a sort param to this file; the Nominatim block lives in
   the inline `<script>` near the old Places init — make sure it survived your merge.)*
2. **`f2df89a`** — `api/v0/directory-members.php`: instrument & skill filters were always returning
   0 because `profile_instruments`/`profile_skills` are **empty on migrated profiles** (data is in
   `profile_highlights`). Filters now match the full list **OR** highlights (distinct param names
   per subquery). *(You independently mirrored this in the facet catalogs — good, keep in lockstep.)*
3. **`d7a7c2f`** — default **public** location precision → **city** (was private), so members are
   findable on the public map by default. Spans: SQL migration
   `sql/2026-06-01-public-precision-city-default.sql` (data UPDATE + `ALTER COLUMN ... SET DEFAULT
   'city'`), `src/Block.php` `loadLocation` fallback, and the directory's two `'private'` fallbacks.
4. **`5ed0e9b`** — `api/v0/directory-members.php`: **Location block removed from a profile →
   private on the map**. Added `loc_on_profile = (profile_layout IS NULL OR profile_layout @>
   '["location"]')` to the SELECT; `dir_member_display()` returns null when off-profile; geo-filter
   gained the same gate. Hidden for everyone incl. admin; member still appears in the LIST (no pin).

Shared-file edits (coordinate at cutover): `src/Block.php`, the two new `sql/` migrations.

## Privacy model now in effect (for your reference)
`dir_member_display()` is the single source for both card + pin. Precision: **public/members default
city** (coarsened ~1 decimal), **admin** sees exact unless the member set members-precision private,
**owner** sees self exact. `private` (any audience) or **location block off the profile** → no pin.

## "No dots on the map" — RESOLVED
Was a client-side issue during your in-progress markercluster work (API was always healthy:
`GET /profile-api/v0/directory/members?page_size=20` returns 20/20 items with `location.lat`).
Ian confirms dots are back. No action needed; noted only for history.

## Test fixture left on dev
`profileapp-test` (user id 3) was **claimed** (got a `profiles` row so it appears in the directory)
and populated with all taxonomy (instruments: acoustic-flattop/mandolin/violin; skills: setup/
fret-leveling/cnc-programming/accounting; services x3; genres: jazz/bluegrass/flamenco), location
Guelph. Verified all filters hit it. Strip it or keep it as you like.

```
MAP chat: an editor-lane chat fixed map things on Ian's say-so and edited YOUR files
(directory-members.php) while you were live — reconcile. Commits (by pathspec):
  0c22ad1  Google Places location search → client-side OSM Nominatim (no key/auth)
  f2df89a  instrument/skill filters match profile_highlights too (were always 0)
  d7a7c2f  default PUBLIC location precision → city  (+ sql/2026-06-01-public-precision-city-default.sql, Block.php)
  5ed0e9b  Location block off the profile (not in profile_layout) → private/no pin (incl. admin)
OPEN: "no dots" is CLIENT-side — API returns 20/20 items with location.lat; suspect your
markercluster init (cluster layer not addTo(dirMap) / no fitBounds on load). dir_member_display
is the single card+pin source. Test fixture: profileapp-test (id 3) claimed + full taxo on dev.
The editor chat is standing down from the map now.
```
