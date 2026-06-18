# Phase 1 · increment 2 — test plan (location block)

Run AFTER applying the pin-precision migration. Verifies: the two-tier location
block assembles from the spine, the header ceiling + per-tier visibility gate each
tier, the coarse-from-city derivation, the user-managed pin/precision, and the
`/me/location` write logic. Mirrors `PHASE-1-INCREMENT-1-TEST.md`.

Run PHP as **`sudo -u profile-app php`** (peer-auth DB; plain `php` as ubuntu has no
pg role). `UID` is a readonly shell var — use a literal `3` or `U=3`.

## 0. Apply the new schema (idempotent — safe to re-run)
```
sudo -u profile-app psql -d profile_app \
  -f /home/ubuntu/projects/profile-app/sql/2026-05-30-location-pin-precision.sql
```
(`location_exact_visibility` already landed in increment 1's
`2026-05-30-block-system-spine.sql` — only the precision column is new here.)

## 1. Schema present
```
sudo -u profile-app psql -d profile_app -c "\d users" | grep -E 'location_(exact_visibility|pin_precision|address)'
```
Expect: `location_exact_visibility text NOT NULL DEFAULT 'private'` (CHECK
members|private|on_request, from inc 1); `location_pin_precision text NOT NULL
DEFAULT 'exact'` (CHECK exact|neighborhood|city). Re-run §0 → no error.

## 2. Block logic in isolation (no HTTP, no DB)
```
sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php";
use Looth\ProfileApp\Block;
// ceiling rule + FAIL-CLOSED on the exact-tier on_request value:
echo Block::effectiveVisibility("public","on_request"), "\n";   // private  (unknown → most restrictive)
echo Block::effectiveVisibility("members","public"), "\n";      // members  (header caps public→member)
echo var_export(Block::canSee("public","public","on_request"),true), "\n"; // false (exact never auto-public)
echo var_export(Block::canSee("me","members","on_request"),true), "\n";    // true  (owner always)
// coarsening (no stored approx column):
echo Block::coarsen(43.5448,1), " ", Block::coarsen(-80.2482,1), "\n";     // 43.5 -80.2  (town)
echo Block::coarsen(43.5448,2), " ", Block::coarsen(-80.2482,2), "\n";     // 43.54 -80.25 (neighborhood)
// input validators:
echo Block::exactVisFromInput("member"), "/", var_export(Block::exactVisFromInput("public"),true), "\n"; // members/NULL
'
```
All lines must match the trailing comments. (`exactVisFromInput("public")` is NULL
— the exact tier can never be public.)

## 3. Seed fixture user (id 3) + assemble both tiers
```
sudo -u profile-app psql -d profile_app -c \
 "UPDATE users SET location_text='Guelph, Ontario, Canada',
    location_city='Guelph', location_region='Ontario', location_country='Canada',
    lat=43.5448, lng=-80.2482, location_address='14 Wyndham St N', location_postcode='N1H 4E9',
    location_visibility='members', location_exact_visibility='private',
    location_pin_precision='exact' WHERE id=3;"

sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php";
use Looth\ProfileApp\Block;
echo json_encode(Block::loadLocation(3), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";'
```
Expect `{block:"location", approximate:{vis:"member", city:"Guelph", region:"Ontario",
lat:43.5, lng:-80.2}, exact:{vis:"private", present:true, address:"14 Wyndham St N",
postcode:"N1H 4E9", lat:43.5448, lng:-80.2482}, precision:"exact"}` — approximate coord
is town-level (1 dp); exact coord is the full pin; vis NORMALIZED to "member".

## 4. Render honours ceiling + per-tier visibility
```
sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php";
require "/home/ubuntu/projects/profile-app/web/_render_blocks.php";
foreach (["public","member","me"] as $role) {
  echo "== role=$role ==\n";
  looth_render_location_block(3, $role, Block::headerCeiling(3));   // header ceiling from §3 seed = members
  echo "\n";
}'
```
With header=`members`, exact=`private`, approx=`members`:
- `public` → **nothing** (members header caps the whole block; the gate is handled
  upstream in `looth_render_profile_blocks`, and here approx member-capped is hidden).
- `member` → city line "📍 Guelph, Ontario, Canada"; **no** exact pin (private);
  an "Exact address available to members" note is NOT shown (it's private, not member).
- `me` (owner) → city line + the exact `🏠 14 Wyndham St N · N1H 4E9` + vis chips.

Then flip tiers and re-check:
```
# open approx to public, exact to on_request, fuzz precision to neighborhood:
sudo -u profile-app psql -d profile_app -c \
 "UPDATE users SET location_visibility='public', location_exact_visibility='on_request',
    location_pin_precision='neighborhood' WHERE id=3;"
```
- header must be `public` for public peek-through (set the header section row vis to
  'public' for user 3, or test role=`member`). With approx=`public`: `member`/`public`
  (under a public header) see the city line; exact stays hidden (on_request) with an
  "available on request" note; the pin, if shown to a permitted viewer, is at
  neighborhood precision (2 dp), never the full point.

## 5. /me/location write — round-trip
**HTTP authed round-trip is BLOCKED on shim `/mint-token`** (JWT key is the
`looth-dev` group, DB is `profile-app` peer — can't mint a `looth_id` on dev yet;
see `reply-to-shim-mint-dev-priority.md`). So the endpoint's HTTP path can't be
exercised until shim unblocks. Verify the WRITE LOGIC two ways instead:

a) **Validators (the endpoint's gates) directly** — §2 already covers
   `exactVisFromInput` + `PRECISION_VALUES`; pin range is a plain bounds check in
   `me-location.php`.

b) **Simulate the writes via SQL, then read back the block** (proves the columns +
   assembly the endpoint drives):
```
sudo -u profile-app psql -d profile_app -c \
 "UPDATE users SET lat=43.6000, lng=-80.3000, location_pin_precision='city',
    location_exact_visibility='private' WHERE id=3;"
sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/src/Block.php";
use Looth\ProfileApp\Block; $l=Block::loadLocation(3);
echo "exact.present=", var_export($l["exact"]["present"],true), "\n";   // false (precision=city → no precise pin)
echo "approx=", $l["approximate"]["lat"], ",", $l["approximate"]["lng"], "\n"; // 43.6,-80.3 (coarse only)
'
```
Expect: precision `city` folds the exact tier away (`present:false`) — only the
coarse approximate dot remains, exactly the "fuzz to town-level" behaviour.

When shim unblocks, the HTTP pass is:
`PUT /…/me/location {"location_exact_visibility":"member"}` → 200 + `location` block;
`{"precision":"neighborhood"}`; `{"pin":{"lat":..,"lng":..}}` (conflicts with a
nominatim pick in the same call → 400 `conflicting_fields`); `GET /…/me/location`
returns the assembled block.

## Pass = §2 truth-table (incl. fail-closed on_request) · §3 both tiers assemble
with coarse approx + full exact · §4 gate/precision branches correct · §5b
precision='city' folds exact. Then: craft + socials blocks, then the crib.
