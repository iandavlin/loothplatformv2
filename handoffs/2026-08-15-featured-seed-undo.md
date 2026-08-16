# featured-members: dev2 seed data for Ian's look (2026-08-15) — UNDO MANIFEST

Ian, via keeper: "give me some members on dev2 with the featured member ticked.
Can you fake some profile stuff for a few of them too?" This is DEV2-ONLY DB
data (profile_app on dev2's local Postgres), never touched live, never touched
any DB other than dev2's `profile_app`. No `Feature` button was ever clicked —
the pool is populated, the front page still shows the hand-typed fallback
(Chip Tait) until Ian does that click himself.

## What was seeded

7 members opted in (`featured_opt_in=true`, `featured_opt_in_at=now()` at
write time, ~2026-08-15 03:20 UTC). 4 are natural — real profile content,
nothing faked, they already scored this way. 3 are sparse real members whose
profile content was topped up with fake-but-plausible data so the meter shows
a spread of percentages.

| id  | slug                            | pct  | what was touched |
|-----|----------------------------------|------|-------------------|
| 85  | beau-hannam-beau-hannam-guitars  | 100% | opt-in only, NATURAL |
| 550 | karl-borum-borum-acoustics       | 75%  | opt-in only, NATURAL |
| 667 | marius-mezei-guitarcarepro       | 62%  | opt-in only, NATURAL |
| 23  | rick-liftig-luthier-wannabe      | 62%  | opt-in only, NATURAL |
| 164 | carl-ioriatti                    | 25%  | opt-in + FAKE location |
| 196 | stephen-martin-atelier-guitar    | 50%  | opt-in + FAKE location, what-you-do, bio |
| 253 | eric-haskins                     | 75%  | opt-in + FAKE location, what-you-do, bio, one social link, one skill |

Fake location data is city/state only on all three (Portland OR / Austin TX /
Nashville TN) — never a street address, per backlog 20. No avatar/banner was
faked for anyone; all 7 already had a real uploaded photo (`avatar_version>0`),
so "reuse existing uploaded media" didn't end up needed — nobody's card is
missing a photo.

Excluded on purpose: id 4 (`ian-davlin-the-looth-group` — Ian's own account,
not touched). Excluded anyone with "buck" in the slug even though nothing here
is a repo/filesystem path — just avoided the name entirely out of caution.

## How this was done

Direct DB writes (not the HTTP `PUT /profile-api/v0/me/featured` path — keeper's
board message explicitly allowed "clean DB writes honoring your own
constraints"), honoring the same invariant the API and the DB CHECK constraint
both enforce: `featured_opt_in_at` is set if and only if `featured_opt_in=true`.
Every resulting row was re-read back through the real `Completeness::forUser()`
class (not reimplemented math) to confirm the percentages match what the dash
and the member's own profile page will actually show — same class, same query,
no drift.

Script run once as `profile-app` (peer-auth to the `profile_app` Postgres DB),
inside a single transaction. Full run log + the JSON manifest below are from
that one execution; it was not re-run (would have double-inserted the profile_sections/profile_socials/profile_skills rows).

## UNDO — exact reversal

Run each block below (as the `profile-app` role, e.g. `sudo -u profile-app
psql -d profile_app`) to put every touched row back to its exact prior value.
Everything here comes from the BEFORE snapshot taken immediately before the
write, not from memory or assumption.

### 1. Delete the fake content rows (sparse members only)

```sql
DELETE FROM profile_sections WHERE id IN (1985, 1986);   -- the two fake 'about' bios (196, 253)
DELETE FROM profile_socials  WHERE id IN (380);            -- the one fake social link (253)
DELETE FROM profile_skills   WHERE user_id = 253 AND skill_id = 6;  -- the one fake skill (no surrogate id on this table)
```

### 2. Restore every touched column to its BEFORE value, all 7 users

```sql
-- 85 — opt-in only, nothing else was touched
UPDATE users SET featured_opt_in = false, featured_opt_in_at = NULL WHERE id = 85;

-- 550 — opt-in only
UPDATE users SET featured_opt_in = false, featured_opt_in_at = NULL WHERE id = 550;

-- 667 — opt-in only
UPDATE users SET featured_opt_in = false, featured_opt_in_at = NULL WHERE id = 667;

-- 23 — opt-in only
UPDATE users SET featured_opt_in = false, featured_opt_in_at = NULL WHERE id = 23;

-- 164 — opt-in + fake location (was entirely NULL before)
UPDATE users SET
  featured_opt_in = false, featured_opt_in_at = NULL,
  location_city = NULL, location_region = NULL, location_country = NULL, location_text = NULL,
  location_public_precision = 'private', location_members_precision = 'city', location_visibility = 'members'
WHERE id = 164;

-- 196 — opt-in + fake location + fake at_a_glance (was entirely NULL before)
UPDATE users SET
  featured_opt_in = false, featured_opt_in_at = NULL,
  location_city = NULL, location_region = NULL, location_country = NULL, location_text = NULL,
  location_public_precision = 'private', location_members_precision = 'city', location_visibility = 'members',
  at_a_glance = NULL
WHERE id = 196;

-- 253 — opt-in + fake location + fake at_a_glance (was entirely NULL before)
UPDATE users SET
  featured_opt_in = false, featured_opt_in_at = NULL,
  location_city = NULL, location_region = NULL, location_country = NULL, location_text = NULL,
  location_public_precision = 'private', location_members_precision = 'city', location_visibility = 'members',
  at_a_glance = NULL
WHERE id = 253;
```

After running both blocks, `SELECT id, featured_opt_in, at_a_glance, location_city FROM users WHERE id IN (85,550,667,23,164,196,253);` should show every one of the 7 back to `featured_opt_in=false` and the 3 sparse ones with the location/glance columns NULL again, matching the BEFORE snapshot below byte-for-byte.

## Full BEFORE snapshot + write manifest (JSON, ground truth for the UNDO above)

```json
{
    "before": {
        "85": {
            "id": 85, "slug": "beau-hannam-beau-hannam-guitars",
            "display_name": "Beau Hannam - Beau Hannam Guitars",
            "featured_opt_in": false, "featured_opt_in_at": null,
            "location_city": "Grand Junction", "location_region": "Colorado", "location_country": "United States",
            "location_text": "North 24th Street, Grand Junction, Mesa County, Colorado, 81501, United States",
            "location_public_precision": "city", "location_members_precision": "city", "location_visibility": "members",
            "at_a_glance": "Guitar Maker and Repairer. Since 2003"
        },
        "550": {
            "id": 550, "slug": "karl-borum-borum-acoustics",
            "display_name": "Karl Borum - Borum Acoustics",
            "featured_opt_in": false, "featured_opt_in_at": null,
            "location_city": "Saint Charles", "location_region": "Missouri", "location_country": "United States",
            "location_text": "St Charles, MO, USA",
            "location_public_precision": "private", "location_members_precision": "city", "location_visibility": "members",
            "at_a_glance": "Acoustic Guitar Builder in St. Charles, MO"
        },
        "667": {
            "id": 667, "slug": "marius-mezei-guitarcarepro",
            "display_name": "Marius Mezei | GuitarCarePro",
            "featured_opt_in": false, "featured_opt_in_at": null,
            "location_city": "Marghita", "location_region": null, "location_country": "România",
            "location_text": "415300 Marghita, Romania",
            "location_public_precision": "private", "location_members_precision": "city", "location_visibility": "members",
            "at_a_glance": "Guitar repair technician from Romania. Specializing in guitar setups, fretwork, electronics and acoustic guitar repairs. Always happy to learn and collaborate with fellow luthiers."
        },
        "23": {
            "id": 23, "slug": "rick-liftig-luthier-wannabe",
            "display_name": "Rick Liftig luthier wannabe… slowly gettinthere",
            "featured_opt_in": false, "featured_opt_in_at": null,
            "location_city": "West Hartford", "location_region": "Connecticut", "location_country": "United States",
            "location_text": "75 Foxridge Rd, West Hartford, CT 06107, USA",
            "location_public_precision": "private", "location_members_precision": "city", "location_visibility": "members",
            "at_a_glance": "I'm a retired dentist who has come to the conclusion that Loothing has a lot in common with dentistry, but without the blood or guts. Sometimes there's pain, but it's usually self-inflicted. Plus, many of our customers think we charge too much."
        },
        "164": {
            "id": 164, "slug": "carl-ioriatti", "display_name": "Carl Ioriatti",
            "featured_opt_in": false, "featured_opt_in_at": null,
            "location_city": null, "location_region": null, "location_country": null, "location_text": null,
            "location_public_precision": "private", "location_members_precision": "city", "location_visibility": "members",
            "at_a_glance": null
        },
        "196": {
            "id": 196, "slug": "stephen-martin-atelier-guitar", "display_name": "Stephen Martin Atelier Guitar Repair",
            "featured_opt_in": false, "featured_opt_in_at": null,
            "location_city": null, "location_region": null, "location_country": null, "location_text": null,
            "location_public_precision": "private", "location_members_precision": "city", "location_visibility": "members",
            "at_a_glance": null
        },
        "253": {
            "id": 253, "slug": "eric-haskins", "display_name": "Eric Haskins",
            "featured_opt_in": false, "featured_opt_in_at": null,
            "location_city": null, "location_region": null, "location_country": null, "location_text": null,
            "location_public_precision": "private", "location_members_precision": "city", "location_visibility": "members",
            "at_a_glance": null
        }
    },
    "inserted_section_ids": [1985, 1986],
    "inserted_social_ids": [380],
    "inserted_skill_rows": [[253, 6]],
    "all_touched_ids": [85, 550, 667, 23, 164, 196, 253]
}
```

## Post-write verification (live, real dash render, 2026-08-15 ~03:22 UTC)

Curled `/wp-admin/admin.php?page=lg-featured-member` as `claude_admin` against
the box's own door (loopback + Host header, not public DNS). Pool shows
"7 members opted in", each row's % and card-ready state matches the table
above exactly, and the "Where" column shows city/state only for all three
faked rows (Portland, Oregon / Austin, Texas / Nashville, Tennessee) — no
street address leaked into any public-facing rendering. Carl Ioriatti (25%,
missing what-you-do) correctly shows a disabled Feature button with "Not
ready" — confirms the card-completeness gate on the Feature action is
independent of the opt-in tick, as ruled.

No `Feature` button was clicked. The front page still shows the hand-typed
fallback. That click is Ian's own test to make.

---
**STATE, 2026-08-15 ~03:25 UTC:** Seeding task COMPLETE (write + verify + undo manifest above + board report sent). Parked on keeper's FLEET-QUIET ORDER (dev2 saturated, Ian actively using the box) — not mid-build, nothing left in flight. Also separately parked earlier this session on keeper's merge review of commit 9ba5ee1 (gate 39 CSS/script fix) — that review is still outstanding independent of the quiet order.
