# profile-app — slice 3 cutover checklist

> 6/12 doc audit: the CANONICAL cut document is `cutover/cut-day-runbook.md`
> (blue-green, adopt live DBs, PG rebuild). This checklist stays authoritative
> for the slice-3 DATA detail it owns (xprofile migration, avatar backfill,
> sponsor store re-apply, post-cutover smokes) — referenced from the runbook.

This is the working doc for the **slice 3** cutover. Populated in slice 2.75
so context isn't lost between sessions. None of this is run yet — slice 2.75
only *writes* the migration; slice 3 *runs* it.

## Cutover model (post-2.75 audit)

After visually auditing BB profile vs profile-app `/u/<id>` for real users,
Ian's call was to **drop almost everything** rather than try to carry forward
the rich BB profile data. The cutover ports only:

- **Display name** from xprofile field 1 (Full Name)
- **Business name** from xprofile field 2
- **Slug** from `wp_users.user_nicename` (preserves URLs people already have)
- **Location** (already done in 2.75 via `bin/snapshot-location-from-bb.php`)

Everything else — handle, work history, references, resume, shop pictures,
education, phone, website, instruments/skills/scenes — is dropped at cutover.
Users rebuild via the editor.

The `legacy_xprofile` jsonb dump column and the residual `location_grant_*`
columns were dropped from the schema in `sql/2026-05-27-slice-275-drop-vestigial.sql`.
A new `users.business_name text` column was added in the same migration.

## Pre-cutover sanity

- [x] `bin/walk-onboarding.sh` exits clean against fresh-user flow.
      Fixed in 2.75 follow-up: added a `POST /me/claim` between mint and
      `/profile/edit` navigation; the 22KB identical screenshots were
      the *claim* interstitial, not the login one. Also added a
      `loc-vis` DOM-presence assertion to fail loudly if the editor
      doesn't render. Walk now passes end-to-end (run 20260526T144135Z).
- [ ] `bin/triage-accounts.php > /tmp/triage.tsv && less /tmp/triage.tsv`
      reviewed by Ian. Pick rows to archive interactively (script doesn't
      mutate — set `archived_at = now()` by hand on the chosen ids).
- [ ] Confirm Nominatim host reachable: `curl -A 'looth-profile-app/0.3'
      'https://nominatim.openstreetmap.org/search?q=Portland&format=json'`.
- [ ] GeoLite2-City.mmdb present at `/var/lib/GeoIP/GeoLite2-City.mmdb` and
      <30 days old. Install via `deploy/geoipupdate.conf.example` +
      `deploy/geoipupdate.cron.example` (needs free MaxMind license key).
- [ ] Apply `deploy/nginx-rate-limit.example.conf` to nginx for the
      location-search proxy.

## Avatar files were not migrated to dev

`bin/backfill-avatars.php` ran on dev (1813 candidates) and produced 0 BB
hits, 1813 Gravatar URLs. The dev box has no `/wp-content/uploads/avatars/`
directory at all — BB avatar uploads were not copied over in the dev
migration. The Gravatar fallback uses
`/wp-content/uploads/avatars/0/674d94a75ed58-bpfull.jpg` as the `?d=` URL,
which 404s on dev (so non-Gravatar users see Gravatar's own mystery-person).

This self-resolves at cutover because live still has the BB avatar files
and the URLs will resolve normally. Confirm post-cutover:

```
ssh live 'ls /var/www/looth-live/wp-content/uploads/avatars/ | wc -l'
```

Expected: ≥ several hundred user-id directories. Re-run
`bin/backfill-avatars.php` against the production DB *after* cutover so
the `bb` column populates with real upload URLs instead of every user
hitting Gravatar fallback.

## Hand-jigger the 6 unresolved locations

From the slice-2.5 geocode pass, these user ids never matched. Hand-edit
their `users.location_*` columns post-snapshot:

- 342, 880, 889, 1076, 1163, 1347

Suggested fixes are in the NEXT-SESSION analysis (city centroids picked
manually from Google Maps).

## Run the migration

The slim post-audit version. Idempotent — only fills empty fields.

```
sudo -u profile-app php bin/migrate-from-xprofile.php             # dry-run
# Eyeball: slug collisions (expect 0 on dev), display_name/business deltas.
# On dev as of 2026-05-27: 1519 business, 1695 slug, 0 collisions.
sudo -u profile-app php bin/migrate-from-xprofile.php --commit
```

**Pre-cutover wipe of test-data residue (do BEFORE running migration).**
The slice 2.5/2.75 testing left synthetic data on a handful of real
accounts (Ian's About showed "synthetic-test 1779755413638"; education
section had Plek + Local Vintage Co. test rows; etc.). Identify and
clear these per-section before the migration so users get a clean rebuild
surface:

```sql
-- Anything mentioning the synthetic-test sentinel:
SELECT id, display_name FROM users WHERE about ILIKE '%synthetic-test%';
-- Education test data:
SELECT pc.owner_id, pc.raw_issuer, pc.raw_program
  FROM profile_credentials pc
 WHERE pc.raw_issuer IN ('Local Vintage Co.', 'Plek');
-- Any catalog tags applied during slice testing:
SELECT user_id, kind, label FROM profile_tags WHERE created_at < '2026-05-26';
```

Clear with targeted DELETEs by id; do NOT mass-truncate.

## Identity cleanup (done in slice 3)

- [x] `api/v0/me-name.php` now mirrors `display_name` to `wp_users.display_name`
      via the `wp_user_bridge` lookup. Best-effort: failures log but don't
      block the PATCH. wp-admin author bylines stay consistent with the
      profile-app source of truth.
- [x] Grep confirmed no profile-app PHP read path consults
      `wp_usermeta` for `first_name` / `last_name` / `nickname`. The only
      hit was the walk-onboarding helper, which is *creating* a test WP
      user, not reading identity from usermeta.
- [x] mu-plugin grep confirmed `profile-sync.php` already sources
      `display_name` from `$u->display_name` (wp_users column), not
      usermeta.

## Catalogs (done in slice 3)

- [x] Inserted **Retail Sales** and **Tool Maker** into `skill_catalog`
      with `category='business'` (slice-3 migration). The existing
      catalog is broad enough — tour-tech, machinist-work etc — that
      these fit alongside without a separate "specialties" table.

## Visibility default (defensive)

The migration sets `location_visibility = 'members'` by DEFAULT for new
rows. Existing rows received the same default in
`sql/2026-05-27-slice-275.sql`. Confirm in production DB:

```
SELECT location_visibility, COUNT(*) FROM users GROUP BY 1;
```

Expected: all 1696+ users on 'members' until they opt-change.

## BB @-mention compatibility

No longer a concern: the slim migration sources `users.slug` *from*
`wp_users.user_nicename` rather than the other way around. BB's existing
@-mention machinery resolves against `user_nicename` unchanged. The
slug → nicename mirror-back logic was removed when handle-porting was
dropped.

## Post-cutover smoke

- Run `bin/walk-onboarding.sh` again — should still pass.
- Hit `/profile-api/v0/directory/members?page_size=200&page=1` as anon
  and authed; confirm location-fields gate as expected.
- `/u/<slug>` with a private-visibility user shouldn't leak any
  location DOM (`class="loc"`).

## Sponsor brand store (sponsor-pages v2, Lane A) — "doesn't ride git" infra

⚠️ The `sponsor` table + its API route are NOT auto-created on live. Re-apply:

1. **Schema:** `psql -d profile_app -f sql/2026-06-09-sponsor-brand-store.sql`
   (apply as the `profile-app` role so the FPM pool can read it).
2. **Data:** `sudo -u profile-app php bin/migrate-sponsors.php --commit`
   — resolves attachment IDs to URLs against the LIVE uploads base
   (`https://loothgroup.com/wp-content/uploads`, via `LG_PROFILE_APP_HOST`).
   Idempotent; re-run anytime media moves.
3. **nginx:** the `/profile-api/v0/sponsor/<slug>` (+ `?wp_id=` / `?email=`)
   rewrites and the `sponsor` entry in the public-endpoint `location` group must
   be present in the deployed `strangler-profile-app.conf` (source-of-truth copy
   in repo `nginx-snippet.conf`). `nginx -t && systemctl reload nginx`.
4. **Retire source (already done on dev):** ACF group "Sponsor Brand Information"
   (#33147) set to `acf-disabled`; `brand_*` user-meta left DORMANT (rollback,
   not deleted). On live: `wp post update 33147 --post_status=acf-disabled`.

Smoke: `GET /profile-api/v0/sponsor/total-vise` → 200 brand JSON; logo/hero/
gallery URLs 200; all 5 slugs (total-vise, gluboost, strings-micro-factory,
go-acoustic-audio, stewmac) round-trip.
