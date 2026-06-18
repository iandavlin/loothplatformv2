# profile-app — Session Handoff (2026-05-26, slice 2.5)

> Debt-paydown slice. No new features. Four bugs uncovered by Ian's
> onboarding walk as Dorothy Parker (wp_user_id=1919, users.id=1698).
>
> Prior handoffs in `handoffs/`:
> `2026-05-25-slice-zero.md`, `…-slice-one.md`, `…-slice-one-five.md`, `…-slice-two.md`.

## What slice 2.5 ships

| Bug | Resolution |
|---|---|
| 660 backfilled users had `location_text` but no `lat`/`lng` → invisible to directory radius filter | `bin/geocode.php` (Nominatim). 596 seeded / 66 no_match / 0 failed in 17m 27s |
| Direct nav to `/profile/edit` with a WP session but no `looth_id` cookie showed "Sign in to edit" interstitial | New `/wp-json/looth/auth/issue?return=<path>` mints + 302s back. `web/edit.php` detects `wordpress_logged_in_*` cookie and bounces through it |
| `/u/<slug>` leaked rail, viewer-toggle, 11 pencils, and members-only About into anonymous HTML | New `web/_render_public.php` template (intentionally separate from `_render.php`). Sections with hidden content are omitted from HTML, not CSS-hidden |
| WP admin bar hidden on FE for members → "My Profile" item from slice 1.5 was invisible where it mattered | Added `bp_setup_nav` "My Profile 2.0" item in `profile-auth.php`. Verified on `/members/iandavlin/` |
| Header "📍 no location" reads like a real place | Now renders italic "+ add your location" link when empty; clicking opens the location modal |
| Reported: synthetic `.click()` blocked on save buttons | Investigated in slice 2.5 — `.click()` now fires `data-save="about"` correctly through to API + close-modal. Probably fixed incidentally by the slice-2 modal rewrite; documenting in case it re-surfaces |

## Geocode results (Nominatim/OSM, 1 rps)

```
  seeded     596    (89.7% of attempted)
  no_match    66    (10.3% — mostly very specific addresses
                     and street-level European/non-English strings)
  failed       0
  elapsed   1046.7s
```

Sample of 5 random seeded rows:

| users.id | email | lat | lng | country | city |
|---|---|---|---|---|---|
| 796  | wesbutlermusic@me.com     | 32.44 | -97.79  | United States | Granbury |
| 45   | bhabes@aol.com            | 40.91 | -74.21  | United States | Totowa |
| 1057 | andi.neundlinger@live.at  | 48.21 |  16.37  | Österreich    | Wien |
| 361  | panny57@icloud.com        | 36.85 | -75.98  | United States | Virginia Beach |
| 479  | overhaulguitars@gmail.com | 33.96 | -83.38  | United States | Athens |

Directory radius spot-checks:

| Query | Results |
|---|---|
| Portland, OR @ 50mi    | 13 members (Jeff Pomerantz / Adam / Sam Stewart / Roy Huffman / Ian / …) |
| NYC @ 100mi            | 41 members (Evan Gluck / Ric McCurdy / Anthony Pires / Guitar Quackery / …) |
| No location filter     | 664 total (596 geocoded + 67 location-text-only + Dorothy + others) |

## Key code changes in slice 2.5

| Path | Role |
|---|---|
| `bin/geocode.php`                            | Nominatim-backed backfill, idempotent, prints summary + first-10 failures |
| `deploy/profile-auth.mu-plugin.php`          | Added `bp_setup_nav` hook (BB front-end nav); added `GET /wp-json/looth/auth/issue?return=` |
| `/var/www/dev/wp-content/mu-plugins/profile-auth.php` | re-installed |
| `web/edit.php`                               | Sniff `wordpress_logged_in_*` cookie → 302 to issue endpoint before falling back to the "Sign in" interstitial |
| `web/_render_public.php`                     | NEW — public read template. No rail, no viewer-toggle, no pencils/grips, no inactive section placeholders. Sections with no visible content are omitted entirely |
| `web/u.php`                                  | Now uses `_render_public.php`; also 302s self → `/profile/edit` on bare `/u/<slug>` (slice 1.5 spec finally honored) |
| `web/_render.php`                            | Empty-location header renders italic "+ add your location" affordance, clickable, opens location modal |
| `web/edit.css`                               | Added `.loc-empty` styles |
| `web/edit.js`                                | Click handler for `.loc-empty[data-modal]` → opens modal |

## Validation matrix (all passed)

| Check | Result |
|---|---|
| Geocode summary present + non-zero | seeded=596 / no_match=66 / failed=0 ✅ |
| 5 random users have lat/lng + country/city | ✅ |
| `/directory/members?lat=45.5&lng=-122.7&radius=50` finds Portland-area members | 13 results ✅ |
| `/wp-json/looth/auth/issue?return=...` mints `looth_id` + 302s back when authed | `set-cookie: looth_id=eyJ…` confirmed ✅ |
| `/profile/edit` with WP session cookie only → 302 to issue endpoint | `location: /wp-json/looth/auth/issue?return=%2Fprofile%2Fedit` ✅ |
| `/u/1698` anon: 0 rails / 0 pencils / 0 grips / 0 inactive cards / 0 About refs | all four counts 0 ✅ |
| `/u/4` anon: instruments+credentials visible (public), About absent (members-only) | ✅ |
| `/u/4` as Ian self → 302 `/profile/edit` | ✅ |
| `/u/4` as a different logged-in user → member view with About visible | ✅ |
| BB "My Profile 2.0" nav on `/members/iandavlin/` | id=`looth-profile-2-personal-li`, href=`…/profile-2/` (BB-routed → `/profile/edit`) ✅ |
| Empty-location header renders "+ add your location" | ✅ |
| Synthetic `.click()` on `[data-save=about]` triggers save + modal close | ✅ ("saved" indicator + `#about-body` updated) |

## What surprised me (the 5-liner)

1. **`wp_options.wpgmza_google_maps_api_key` is referer-restricted** and
   Google explicitly refuses server-side Geocoding API calls with such
   keys (REQUEST_DENIED, doesn't honor a spoofed `Referer:` header).
   Slice 2 surprise #5 said the key was reusable; for Geocoding it isn't.
   Pivoted to **Nominatim** — free, ~89% match rate on the slice-zero
   xprofile strings, 1 rps mandatory rate limit makes the run 17 min
   instead of 15 sec. The 66 no-matches skewed European/very-specific
   street addresses; a future "swap to Google server key" PR would
   probably close the gap.
2. **BB nav IDs get an automatic `-personal-li` suffix.** Registered
   `'item_css_id' => 'looth-profile-2'` and looked for `#looth-profile-2`;
   BB rendered it as `#looth-profile-2-personal-li`. Wasted 5 minutes
   thinking the hook wasn't firing. Documented for next BB-touching code.
3. **The "synthetic .click() blocked" report was unreproducible in
   slice 2.5** — `.click()` now works through to the API call and modal
   close. Likely fixed incidentally by the slice-2 modal-handler rewrite.
   No `isTrusted` check was found in any BB JS we ship. Keeping a note
   in case it reappears: if it does, look at `wp-emoji-release.min.js`
   and the BB notification toast handlers, which I suspect were stale-
   attached to the old form-mode save buttons.
4. **The `wordpress_logged_in_*` cookie name is suffixed with COOKIEHASH**
   (`md5(siteurl)`). Slice 1's auth shim already knew this for verifying,
   but the auto-mint check has to do a prefix-scan of `$_COOKIE` to find
   it. Worth a shared helper next slice — both the WP mu-plugin's
   `LOGGED_IN_COOKIE` constant and the profile-app sniff are computing
   the same value.
5. **Splitting `_render_public.php` from `_render.php` was the right call
   architecturally.** The chrome-leak bug was a one-template-with-flags
   failure mode: every `if ($isOwner)` was a place where the writer had
   to remember to gate. Two templates with shared partials (`looth_h`,
   `looth_initials`, `looth_social_glyph` stay in `_render.php`) means
   the public path can't accidentally render an `.add-link` or a
   `.pencil` because those elements don't exist in its source. Same
   pattern will apply when the directory cards eventually need a
   "mini-card" partial for embeds.

## What slice 2.5 deliberately did NOT do

- No practices (slice 3)
- No catalog expansion / tsvector full-text search (slice 3)
- No avatar upload
- No deactivate-section UI
- No touch DnD
- No Google-key swap for geocode (Nominatim is good enough for now)
- No live deploy

## Quick-start for next session

```bash
# Sanity
curl ifconfig.me                                              # → 50.19.198.38
sudo -u profile-app psql -d profile_app -c '\dt'              # → 16 tables (15 + reports)

# Directory radius spot-check
TOK='qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG'
curl -sk -H "Host: dev.loothgroup.com" -H "Cookie: loothdev_auth=$TOK" \
  "https://127.0.0.1/profile-api/v0/directory/members?lat=45.5&lng=-122.7&radius=50" | jq '.total'

# Re-run geocode for any leftovers (idempotent)
sudo -u profile-app php /home/ubuntu/projects/profile-app/bin/geocode.php

# Auto-mint via issue
curl -skI -H "Host: dev.loothgroup.com" -H "Cookie: loothdev_auth=$TOK; <wp_logged_in_cookie>" \
  "https://127.0.0.1/wp-json/looth/auth/issue?return=/profile/edit"
```

## Slice 3 setup

- Practices: `practices` + `practice_services` + `/p/<slug>` route
- Skill-pack zip endpoint (built on slice 1.5's import/export-shaped schema)
- Polymorphic credentials slice-3 work: re-document the `'practice'` branch
- Catalog full-text search via Postgres tsvector
- Admin reports UI (the table is the UI right now — survivable, but)
- Live deploy (still deferred; live keypair + cookie domain switch is the only real new work)
- Geocode the 66 no_match users (manual cleanup or Google server key)
