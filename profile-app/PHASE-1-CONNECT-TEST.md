# Phase 1 · connect block — test plan

The connect block = the person's **connections surface** (count + preview avatars +
mutuals-for-a-visitor + the owner's pending inbox), built ON the social-layer
`Connections` backend. Block-level pmp on `profile_sections key='connect'`,
ceiling-capped by the header. NO new schema (uses `connections` + `profile_sections`).

Run PHP as `sudo -u profile-app php`. Fixture user **id 3 ("Profile App Test", wp 1918)**.
HTTP pass is **unblocked** (mint CLI live) and was verified green when built.

## 0. No schema to apply
Depends on `sql/2026-05-30-social-layer.sql` (the `connections` table) — already
applied on dev. Verify: `sudo -u profile-app psql -d profile_app -tAc "SELECT to_regclass('public.connections')"` → `connections`.

## 1. Block logic in isolation (no HTTP)
```
sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
use Looth\ProfileApp\Block;
echo json_encode(Block::loadConnect(3, 3), JSON_UNESCAPED_SLASHES), "\n";   // owner view (pending counts)
echo json_encode(Block::loadConnect(3, null), JSON_UNESCAPED_SLASHES), "\n"; // anon view (no pending, no mutuals)
'
```
Expect `{block:"connect", vis:"member", fields:{count:int, connections:[{uuid,name,slug,avatar}…≤12],
mutuals:[…], pending_in:int, pending_out:int}}`. Anon view omits pending_*; mutuals only when a
non-owner viewer shares accepted connections with the subject.

## 2. Seed a couple of fixture connections (dev = fixtures only)
```
sudo -u profile-app psql -d profile_app -c "
WITH s AS (SELECT uuid FROM users WHERE id=3),
     o AS (SELECT uuid, row_number() OVER (ORDER BY id) rn FROM users
             WHERE id<>3 AND display_name IS NOT NULL AND uuid IS NOT NULL ORDER BY id LIMIT 3)
INSERT INTO connections (requester_uuid, addressee_uuid, status)
SELECT (SELECT uuid FROM s), o.uuid, 'accepted' FROM o WHERE o.rn<=2
ON CONFLICT (requester_uuid, addressee_uuid) DO NOTHING;"
```
(For an owner pending-inbox test, add one row with requester=another user, addressee=user 3, status='pending'.)

## 3. Render (ceiling-capped, owner sees pmp chip + pending hint)
```
sudo -u profile-app php -r 'require "/home/ubuntu/projects/profile-app/config.php";
require "/home/ubuntu/projects/profile-app/web/_render_blocks.php";
use Looth\ProfileApp\Block;
foreach (["public","member","me"] as $r) { echo "== $r ==\n";
  looth_render_connect_block(3, $r, Block::headerCeiling(3), 3); echo "\n"; }'
```
- vis=`member`, header=`members` → `public` viewer: nothing (capped); `member`/`me`: the block.
- `me` shows the `lg-pmp` chip + "N pending requests →" when pending_in>0.
- Empty + non-owner → no block; empty + owner → "No connections yet" copy.

## 4. HTTP round-trip — VERIFIED GREEN (mint live)
```
TOK=$(sudo -u profile-app php /home/ubuntu/projects/profile-app/bin/mint-dev-token.php 1918)
B=https://dev.loothgroup.com/profile-api/v0
curl -sS -H "Authorization: Bearer $TOK" $B/me/connect                                   # 200 assembled block
curl -sS -X PATCH -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"visibility":"public"}' $B/me/connect                                              # 200 {ok, connect:{vis:public}}
curl -sS -X PATCH -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' \
  -d '{"visibility":"bogus"}' $B/me/connect                                               # 400 invalid_visibility
```
Confirmed: GET 200 (count + connections + pending_in/out); PATCH public/member 200 + round-trips;
bad value 400. `/u/profileapp-test/` (owner Me view) renders `lg-block--connect` with avatars + pmp chip.

## nginx (already added, hands-on this turn)
- rewrite `^/profile-api/v0/me/connect/?$ → me-connect.php` (above the /me/connections rewrites).
- `me-connect` added to the authed-/me allowlist regex. `nginx -t` green, reloaded.

## Pass = §1 both views assemble · §3 ceiling-gated + owner extras · §4 GET/PATCH round-trip + 400.
