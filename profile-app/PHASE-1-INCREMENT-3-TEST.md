# Phase 1 · increment 3 — test plan (craft + socials/links blocks)

Plus the **now-unblocked HTTP round-trips for inc 1 + inc 2** (mint CLI is live).
NO new schema this increment — craft/socials block data uses existing tables;
block-level vis lives on `profile_sections` (key `craft` / `socials`).

Run PHP as **`sudo -u profile-app php`** (peer-auth DB). Fixture user **id 3
("Profile App Test", wp 1918)** — `UID` is readonly, use the literal `3`.

## 0. Mint a dev token (now live)
```
TOKEN=$(sudo -u profile-app php /home/ubuntu/projects/profile-app/bin/mint-dev-token.php 1918 | tail -1)
echo "$TOKEN" | cut -c1-24      # sanity: looks like a JWT header
```
(`1918` = the fixture user's wp_user_id → looth_id for profile-app user 3.)

## 1. inc-3 block logic in isolation (no HTTP)
```
sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php"; use Looth\ProfileApp\Block;
echo json_encode(Block::loadCraft(3),   JSON_UNESCAPED_SLASHES), "\n";
echo json_encode(Block::loadSocials(3), JSON_UNESCAPED_SLASHES), "\n";
echo Block::blockVisibility(3, "craft", "members"), "\n";          // members (default until set)
echo var_export(Block::visFromInput("member"), true), "\n";        // members
'
```
Expect `loadCraft` → `{block:"craft", vis:"member", fields:{instruments[],skills[],highlights[]}}`
and `loadSocials` → `{block:"socials", vis:"member", fields:{website, links:[{kind,url}]}}`
(`web` social split out as `website`; `vis` normalized to `member`).

## 2. Seed craft + socials data for user 3, then assemble + render
```
# a skill (use a real skill_catalog id; pick one):
sudo -u profile-app psql -d profile_app -c \
 "INSERT INTO profile_skills(user_id,skill_id,sort_order)
    SELECT 3, id, 0 FROM skill_catalog WHERE active LIMIT 1
  ON CONFLICT DO NOTHING;"
# a couple of socials + a website:
sudo -u profile-app psql -d profile_app -c \
 "INSERT INTO profile_socials(user_id,kind,value,sort_order) VALUES
    (3,'web','https://maxmonteguitars.com',0),
    (3,'instagram','maxmonte',1) ON CONFLICT DO NOTHING;"
# block vis rows:
sudo -u profile-app psql -d profile_app -c \
 "INSERT INTO profile_sections(user_id,key,visibility,data,sort_order)
    VALUES (3,'craft','members','{}'::jsonb,20),(3,'socials','public','{}'::jsonb,30)
  ON CONFLICT (user_id,key) DO UPDATE SET visibility=EXCLUDED.visibility;"

# render craft + socials for each role (header ceiling from the seeded inc-1 header row):
sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php";
require "/home/ubuntu/projects/profile-app/web/_render_blocks.php";
foreach(["public","member","me"] as $r){echo "== $r ==\n";
  looth_render_craft_block(3,$r,Block::headerCeiling(3));
  looth_render_socials_block(3,$r,Block::headerCeiling(3)); echo "\n";}'
```
Expect: craft chips render where the role can see (member vis, ceiling-capped);
socials block (vis=public, but capped to the header ceiling — under a member
header a "public" socials block caps to member); owner (`me`) always sees both,
with vis chips.

## 3. HTTP round-trips — inc 1 + inc 2 + inc 3 (mint now live)
Bearer auth; base `https://dev.loothgroup.com/profile-api/v0`. (If the dev cookie
gate intercepts `/profile-api/`, add `--cookie "loothdev_auth=<v>"`; the API path is
normally ungated.)
```
H="Authorization: Bearer $TOKEN"; B=https://dev.loothgroup.com/profile-api/v0

# inc 1 — header
curl -sS -H "$H" $B/me/header
curl -sS -H "$H" -X PATCH $B/me/header -H 'Content-Type: application/json' \
  -d '{"at_a_glance":"Repairs, setups, restorations","visibility":"public"}'

# inc 2 — location (GET added this lane; exact vis / precision / pin writes)
curl -sS -H "$H" $B/me/location
curl -sS -H "$H" -X PUT $B/me/location -H 'Content-Type: application/json' \
  -d '{"location_exact_visibility":"member"}'
curl -sS -H "$H" -X PUT $B/me/location -H 'Content-Type: application/json' \
  -d '{"precision":"neighborhood"}'
curl -sS -H "$H" -X PUT $B/me/location -H 'Content-Type: application/json' \
  -d '{"pin":{"lat":43.55,"lng":-80.25}}'
# conflict guard (pin + nominatim in one call → 400 conflicting_fields):
curl -sS -H "$H" -X PUT $B/me/location -H 'Content-Type: application/json' \
  -d '{"pin":{"lat":43.5,"lng":-80.2},"nominatim":{"display_name":"x","lat":"43","lon":"-80"}}'

# inc 3 — socials (extended endpoint: GET + visibility)
curl -sS -H "$H" $B/me/socials
curl -sS -H "$H" -X PUT $B/me/socials -H 'Content-Type: application/json' \
  -d '{"visibility":"member"}'
curl -sS -H "$H" -X PUT $B/me/socials -H 'Content-Type: application/json' \
  -d '{"items":[{"kind":"web","value":"maxmonteguitars.com"},{"kind":"instagram","value":"@maxmonte"}]}'

# inc 3 — craft (NEW endpoint — needs the nginx route below; until then it 403s)
curl -sS -H "$H" $B/me/craft
curl -sS -H "$H" -X PATCH $B/me/craft -H 'Content-Type: application/json' \
  -d '{"visibility":"member"}'
```
Expect 200 JSON with the assembled block on each; bad `visibility` → 400; the
location conflict call → 400 `conflicting_fields`.

## 4. ⚠️ Coordinator: nginx for the NEW craft endpoint
`me-craft.php` is new, so add to `/etc/nginx/snippets/strangler-profile-app.conf`:
```
# in the rewrite list (near me-skills, ~L108):
rewrite "^/profile-api/v0/me/craft/?$"  /profile-api/v0/me-craft.php  last;
# add `me-craft` to the allowlist regex (~L147):
... |me-skills|me-craft|me-scenes| ...
```
then `nginx -t && systemctl reload nginx`. Until added, `/me/craft` 403s at nginx
— meanwhile §1–2 cover the craft logic + render directly via `php`.

## Pass = §1 both blocks assemble (web→website, vis normalized) · §2 render is
ceiling-gated + owner sees chips · §3 inc1/inc2/inc3 HTTP all round-trip 200
(craft after the nginx add). No new SQL to apply this increment.
