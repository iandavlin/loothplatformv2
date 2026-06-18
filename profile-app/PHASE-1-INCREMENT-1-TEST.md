# Phase 1 · increment 1 — test plan (profile-header block)

Run AFTER applying the spine schema. Verifies: schema lands, the profile-header
block assembles from the spine, the header-as-ceiling caps a block, `at_a_glance`
shows, and the `/me` edit round-trips. (Coordinator's step — write-only this turn.)

## 0. Apply the schema (idempotent — safe to re-run)
```
sudo -u profile-app psql -d profile_app \
  -f /home/ubuntu/projects/profile-app/sql/2026-05-30-block-system-spine.sql
```
(Adjust the role if the pg owner isn't `profile-app`; peer-auth over
`/var/run/postgresql`, db `profile_app`.)

## 1. Schema present
```
sudo -u profile-app psql -d profile_app -c "\d users"      # at_a_glance, location_exact_visibility
sudo -u profile-app psql -d profile_app -c "\d practices"  # type
```
Expect: `users.at_a_glance text`; `users.location_exact_visibility text NOT NULL
DEFAULT 'private'` + CHECK in (members,private,on_request); `practices.type text` +
CHECK. Re-run the apply → no error (idempotent). NO `location_approx_*` column, NO
`header_visibility` column (header vis lives on the section row).

## 2. Block logic in isolation (no HTTP)
```
php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php";   // not yet in config.php
use Looth\ProfileApp\Block;
echo Block::effectiveVisibility("members","public"), "\n";   // members  (ceiling caps public→member)
echo Block::effectiveVisibility("public","members"), "\n";   // members  (block more restrictive than header)
echo Block::effectiveVisibility("public","public"), "\n";    // public   (peek-through)
echo var_export(Block::isCappedByHeader("members","public"),true), "\n"; // true
echo Block::gateDecision("public","members"), "\n";          // gate
echo Block::gateDecision("public","public"), "\n";           // render
echo Block::gateDecision("member","members"), "\n";          // render
echo Block::normalizeVis("members"), "/", Block::denormalizeVis("member"), "\n"; // member/members
'
```
All lines must match the trailing comments — that IS the header-ceiling rule.

## 3. Seed a test subject + assemble the header block
Pick a bridged dev user id (e.g. Ian). Seed bio + header-section vis, then load:
```
sudo -u profile-app psql -d profile_app -c \
 "UPDATE users SET at_a_glance='Acoustic builder & repair — offset soundholes' WHERE id=<UID>;"
sudo -u profile-app psql -d profile_app -c \
 "INSERT INTO profile_sections (user_id,key,visibility,data,sort_order)
  VALUES (<UID>,'header','members','{}'::jsonb,0)
  ON CONFLICT (user_id,key) DO UPDATE SET visibility='members';"

php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php";
use Looth\ProfileApp\Block; echo json_encode(Block::loadHeader(<UID>), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";'
```
Expect a `{block:"profile-header", subject:"person", vis:"member", fields:{display_name,
avatar, at_a_glance, website, socials[]}, tier_badge:"auto"}` — `vis` NORMALIZED to
`member`; `website` pulled from the `web` social; other socials as `{kind,url}`.

## 4. Render path
Render the block to HTML (the establishing card). Flip the header-section vis to
`private` then `public` and confirm `looth_render_profile_blocks`:
- header `private` + non-owner role → renders **nothing**.
- header `members` + `public` role → the **members gate** (`.lg-gate`).
- header `public` + `public` role → the **header card** renders (name, initials-or-
  avatar, at_a_glance, website, socials).
```
php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/web/_render_blocks.php";
looth_render_profile_blocks(<UID>, "public", "Pro");'
```

## 5. /me edit round-trip (HTTP, authed)
With a valid profile-app session (looth_id), against the dev host:
```
# write: set bio + open the ceiling to public
curl -sS -X PATCH https://dev.loothgroup.com/profile-api/v0/me/header \
  -H 'Content-Type: application/json' --cookie "<session>" \
  -d '{"at_a_glance":"Repairs, setups, restorations","visibility":"public"}'
# read back
curl -sS https://dev.loothgroup.com/profile-api/v0/me/header --cookie "<session>"
```
(Confirm the actual route prefix in the profile-app nginx snippet; the file is
`api/v0/me-header.php`.) Expect:
- PATCH `{ok:true, header:{… vis:"public", fields.at_a_glance:"Repairs…"}}`.
- `users.at_a_glance` updated; `profile_sections` header row `visibility='public'`.
- `wp_usermeta.description` for the bridged WP user mirrored to the new bio.
- invalid `visibility:"member-ish"` → 400 `invalid_visibility`; `at_a_glance` > 500
  chars → 400; API speaks **member** (singular), DB stores **members** (plural).

## Pass = §2 truth-table matches · §4 gate/render branches correct · §5 round-trips
+ mirrors. Then the next increment adds location + craft + the View-as toggle.
