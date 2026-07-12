# CHAPTERS RUNBOOK — adding a chapter is INSERTing a row

**Status: lane deliverable (dmv-native, 2026-07-12).** Proves the brief's core requirement — a
chapter is a **DATA ROW, not code**. Onboarding "Austin Looths" is one INSERT: **zero code changes,
zero deploy, zero restart.** This is the whole strangler thesis in one page.

## The claim, made concrete

Everything a chapter surface needs is derived from ONE row in `chapter` plus whatever members and
discussions accumulate against it. There is no per-chapter template, no per-chapter route, no
per-chapter config file, and no build step. The page at `/g/<slug>`, the one-tap join, the catchment
map, and the discussions surface are all generic over the `slug`.

## Add a chapter (the entire procedure)

Apply to `profile_app` as the schema owner (dev2; on live, hand Ian the SQL — never write live yourself):

```sql
INSERT INTO chapter (slug, name, description, center_lat, center_lng, radius_km)
VALUES (
    'austin-looths',                         -- slug -> URL /g/austin-looths  (a-z 0-9 - only)
    'Austin Looths',                         -- display name
    'Luthiers, techs and players around Austin and the Hill Country.',
    30.267200, -97.743100,                   -- centre: downtown Austin, TX
    120                                      -- catchment radius in KILOMETRES (~75 mi)
)
ON CONFLICT (slug) DO NOTHING;               -- idempotent: re-running is a no-op
```

That is it. There is **no second statement** — membership starts empty (opt-in), and a chapter with
no chat room is intentional (chat is deferred; discussions are the surface).

## What you get for free, immediately

| Surface | URL / mechanism | Notes |
|---|---|---|
| Chapter page | `GET /g/austin-looths` | header, member count, one-tap Join/Leave, map, discussions |
| Detail JSON | `GET /profile-api/v0/chapters/austin-looths` | `{chapter, member_count, is_member, can_post}` |
| Join / leave | `POST`/`DELETE /profile-api/v0/chapters/austin-looths/join` | one tap, idempotent, self-serve |
| Map pins | `GET /profile-api/v0/directory/members?pins=1&chapter=austin-looths` | the EXISTING clamped path — privacy enforced there, not re-implemented |
| Discussions | `GET`/`POST /profile-api/v0/chapters/austin-looths/posts` | read = anyone, post = members |
| Replies | `discovery.comments` keyed `(post_type='chapter_post', item_id)` | reuses the one comments store; no new table |
| Reactions | `discovery.card_reactions`, same key shape | free, when the UI wires it |

No file is edited to light these up. The nginx routes (`/g/<slug>`, `/profile-api/v0/chapters/…`)
are already generic over the slug; FPM serves the same `g.php` for every chapter.

## Picking the fields (the only judgement calls)

- **slug** — lowercase, `a-z 0-9 -` only (the nginx route is `^/g/([\w\-]+)`). Must be unique
  (`UNIQUE` constraint). This is the shareable URL; keep it short and place-like (`austin-looths`,
  not `austin-tx-luthiers-group`).
- **center_lat / center_lng** — the catchment circle centre. Use the metro's downtown point. Type
  is `numeric(9,6)`, matching `users.lat/lng`.
- **radius_km** — ⚠️ **KILOMETRES.** The entire geo stack underneath is MILES; the one conversion
  lives in `Chapters::radiusMi()` and nowhere else. Pick to cover the metro's real catchment without
  swallowing a neighbouring city that deserves its own chapter. DMV used 160 km (~100 mi); a tighter
  metro like Austin is fine at 100–120 km.
- **is_active** — defaults `true`. Set `false` to hide a chapter from the index while keeping its
  URL returning a clean 404 (see below). Never hard-delete a chapter that has content — the down-
  migration is the only destroyer, and it takes everything.

## Verify a new chapter

```sql
SELECT id, slug, name, center_lat, center_lng, radius_km, is_active
  FROM chapter WHERE slug = 'austin-looths';
SELECT count(*) FROM chapter_member WHERE chapter_id = (SELECT id FROM chapter WHERE slug='austin-looths');
-- expect 0: membership is opt-in, starts empty
```

Then, on a box where the branch is served: `GET /g/austin-looths` returns 200 with the header; the
detail JSON returns `member_count: 0`. An unknown slug returns a clean 404, not a 500.

## Deactivate / rename

- **Rename display name or description:** `UPDATE chapter SET name = …, description = … WHERE slug = …;`
  (Changing the **slug** breaks shared links — treat it as a new chapter, not an edit.)
- **Hide it:** `UPDATE chapter SET is_active = false WHERE slug = …;` — drops out of the index and
  the "near you" suggestion; `bySlug()` (which filters `is_active = true`) then 404s the page cleanly.

## Why this is the strangler working

None of the above touches BuddyBoss or WordPress. Identity, membership, geo (clamped), discussions
and their replies are all native Postgres. A new chapter is a row because the platform under it is
native — which is exactly the thing the DMV test set out to prove. See the lane report for the
full verdict.
