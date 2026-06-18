# BATCH-06 Results — 2026-05-29

Raw paste-back from live (`ip-172-31-45-223`, `/var/www/html`, DB `wp_loothgroup`).
Run by Ian. Recon for the social + location migration sources (feeds
`docs/plan-social-consolidation.md` + the slice-4 location backfill).

> NB: first run failed — used `wp_bp_xprofile_field` (singular). Correct table
> is `wp_bp_xprofile_fields` (plural). Data table `wp_bp_xprofile_data` is correct.

## Tables (`SHOW TABLES LIKE '%xprofile%'`)
```
wp_bb_xprofile_visibility     ← legacy per-field vis; IGNORE (we use block-level pmp)
wp_bp_xprofile_data
wp_bp_xprofile_fields
wp_bp_xprofile_groups
wp_bp_xprofile_meta
```

## Relevant xprofile fields (from full field list)
| id | name | type | note |
|---|---|---|---|
| 1 | Full Name (First and Last) | textbox | → users.display_name (existing crib) |
| 2 | Business Name | textbox | → users.business_name (existing crib) |
| 3 | Handle | textbox | |
| 92, 95, 108, 272 | Website | web | multiple website fields (different groups) |
| 91, 94, 106 | Phone Number | telephone | contact (storefront-side) |
| 98, 100, 113, 273 | Email | email | contact (storefront-side) |
| **96** | **Your Location** | **location** | **→ users.location_address (slice-4); 726 users populated** |
| **266** | **Social Media** | **socialnetworks** | **→ profile_socials (primary); 123 users w/ ≥1 URL** |
| 84-87 | Shop/Project Pictures | image | (showcase — future) |
| 89 | Resume | file | (experience — future) |
| 120-244 | Employer / Job / Institution / Credentials | repeaters | experience+education repeater groups (NOT migrated; future) |

## Social field 266 — children (platform set)
```
274 facebook   275 instagram   276 twitter   277 reddit   278 youTube   (parent 266)
```

## Social values — serialized PHP, keyed by platform (empty string = unset)
```
user 1    : a:5:{...instagram→https://www.instagram.com/ianhatesguitars/; rest empty}
user 746  : a:5:{...instagram→https://www.instagram.com/mattgabsguitar?...}
user 1208 : a:5:{facebook→.../Pacific-Rim-Tonewoods...; instagram→...; youTube→...@InfoPacificRimTonewoods}
(many rows are all-empty shells)
```
**Coverage:** `field_id=266 AND value LIKE '%http%'` → **123 users**.

## ACF author socials (usermeta) — FALLBACK source
Counts: `author_instagram` 23 · `author_website` 16 · `author_youtube` 11 ·
`author_facebook` 10 · `author_linktree` 4. Full URLs, some dirty (user 717:
`https://linktr.ee/facebook.com/maxmonteguitars/` stored in `author_facebook`).

## Location field 96 — sample + count
```
1   : Ridgefield, NJ, USA
206 : 2031 Commercial St, San Diego, CA 92113, USA
332 : 41 Rue des Mazurières, 92500 Rueil-Malmaison, France
4   : Rossberry Ave, Esker South, Lucan, Co. Dublin, Ireland
```
**726 users** with a non-empty address. Free-text formatted addresses
(international, varied) — approximate/exact split needs address parsing
(no obvious structured geo-meta found; `wp_bp_xprofile_meta` JOIN check was
inconclusive — profile-app settles parse-vs-structured at build time).

## Decisions/mappings derived (see plan-social-consolidation.md)
- Sources: xprofile 266 (primary, 123) + ACF author_* (fallback, sparse). Precedence confirmed by coverage.
- Mapping → SOCIAL_KINDS: facebook/instagram/youtube/website clean; twitter→x;
  **linktree → NEW kind (added)**; **reddit → web (folded, preserve URL)**.
  `SOCIAL_KINDS` gains exactly one entry: `linktree`.
- Location: field 96 → `users.location_address` (exact) + city/region extraction (approximate), slice-4.
- Ignore `wp_bb_xprofile_visibility` (block-level pmp with fresh defaults).
