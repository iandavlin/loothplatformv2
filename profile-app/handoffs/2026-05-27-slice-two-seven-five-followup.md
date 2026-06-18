# profile-app — Session Handoff (2026-05-27, post-2.75 audit + slim cutover)

> Prior handoff: `handoffs/2026-05-27-slice-two-seven-five.md` (the as-built
> 2.75 record). This handoff covers a follow-up session that audited the
> 2.75 work against real BB profiles, found significant divergence, and
> ratcheted the cutover scope way down to "name + business + slug + location."

## TL;DR for whoever's reading this next

- **Slice 2.75 is built AND a slim cutover has been run on dev.** Everyone
  has a slug, a display name, a business_name (where BB had one), and
  most have lat/lng + components. `/u/iandavlin` returns 200 now (was
  404 because slugs were empty).
- **Cutover scope shrank dramatically.** We no longer port: handle (as a
  separate field), work history, references, resume, shop pictures,
  education, phone, website. The `legacy_xprofile` jsonb column + all
  three `location_grant_*` columns were dropped from the schema. A new
  `users.business_name text` was added.
- **Next slice = practices (slice 3).** Practices CPT + `/p/<slug>` +
  catalog additions (Retail Sales + Tool Maker). Cutover-to-prod is its
  own slice after practices ship.

## What surprised me

1. **The audit-via-Chrome step that the prompt requires is the only thing
   that actually catches data-quality bugs.** Curl + counts looked great
   ("1813 candidates / 699 updated / 0 failures"). Loading the same user
   in two browser tabs (BB vs profile-app) made it instantly clear that
   PA was rendering test-data leftovers + missing the business name + had
   handle disagreement. The cold-walk passes; that's necessary, not
   sufficient. Add a real-user audit step to every slice's validation.
2. **The plan-of-record (handle → users.slug for the 598 distinct ones,
   work history/references → legacy_xprofile, etc) collapsed in
   ~10 minutes of Ian looking at one rendered profile.** The whole
   architecture for "lossless migration with section types later" was
   solving a problem he didn't want solved. The simplification:
   "name + business + location, users rebuild the rest" eliminates an
   entire jsonb column, the dedup logic, the section-type prerequisite
   for cutover, and ~150 lines of migration code.
3. **`/u/<login>` was secretly broken for everyone.** Pre-cutover, 1698 of
   1810 users had null/empty `users.slug`, so any URL using a friendly
   name 404'd. `/u/<numeric_id>` was the only working path. Slug backfill
   from `wp_users.user_nicename` (zero-cost — those are already populated)
   fixes it for everyone in one pass.
4. **BB avatars weren't migrated to dev at all.** `/wp-content/uploads/avatars/`
   doesn't exist on the dev box. `bin/backfill-avatars.php` ran cleanly
   (1813 → 0 BB / 1813 Gravatar) but the Looth-default fallback URL also
   404s on dev. Harmless until cutover; live still has the directory.
   Re-run the avatar backfill on prod after cutover.
5. **`html_entity_decode` keeps showing up as a 2-line fix that has
   massive blast radius.** BB stored `&amp;` in business names. Without
   decode, profile-app displays "Clipper Guitars: Repair &amp;amp;
   Restoration" forever. Now applied to display_name + business_name in
   the migration. Also already applied to location_text in the snapshot.
   If a string came from `wp_bp_xprofile_data`, it needs decode.

## What this follow-up session shipped

### DB schema

`sql/2026-05-27-slice-275-drop-vestigial.sql` (applied on dev):

```sql
ALTER TABLE users ADD COLUMN business_name text;
ALTER TABLE users DROP COLUMN legacy_xprofile;
ALTER TABLE users DROP COLUMN location_grant_public;
ALTER TABLE users DROP COLUMN location_grant_members;
ALTER TABLE users DROP COLUMN location_grant_friends;
```

(Zero code references to any of the dropped columns at the time of removal.)

### Slim migrate-from-xprofile.php

Now ~110 lines (was 260). Ports only:
- xprofile field 1 → `users.display_name` (if currently empty)
- xprofile field 2 → `users.business_name` (if currently empty)
- `wp_users.user_nicename` → `users.slug` (if currently empty)

`html_entity_decode` on both text fields. Idempotent — only fills empty
columns, never overwrites. Dry-run by default; `--commit` to mutate.

### Walk-onboarding fix

The flagged-but-unfixed JWT issue from the 2.75 handoff was a
misdiagnosis. Real cause: fresh subscriber's profile wasn't *claimed*, so
`/profile/edit` rendered the **claim** interstitial (not login). Two fixes:

- `bin/walk-onboarding.sh` now POSTs to `/profile-api/v0/me/claim` between
  JWT mint and CDP navigation.
- The CDP nav block in step 4 now asserts `input[name="loc-vis"]` is in
  the DOM. If not, it writes `01-edit-FAIL.txt` with the page title + URL
  and exits non-zero. No more silent pass-through on identical-bytes
  screenshots.

Walk now passes end-to-end. Latest run: `/var/www/dev/mockups/walks/20260526T144135Z`.

### Backfill scripts run on dev

All idempotent; safe to re-run on prod at cutover:

| script | result |
|---|---|
| `bin/reconcile-bridge.php` | +115 ghost users created |
| `bin/snapshot-location-from-bb.php` | 699 updated / 5 skipped / 0 failures (17 min via public Nominatim 1 rps) |
| `bin/backfill-avatars.php` | 1813 → 0 BB / 1813 Gravatar (BB avatar files absent on dev — see CUTOVER-CHECKLIST) |
| `bin/migrate-from-xprofile.php --commit` | 1519 business / 1695 slug / 0 collisions / 0 display_name |

### Post-state on dev

```
SELECT
  COUNT(*) AS total,                                       -- 1813
  COUNT(business_name) AS with_business,                   -- 1519
  COUNT(slug) FILTER (WHERE slug<>'') AS with_slug,        -- 1810
  COUNT(display_name) FILTER (WHERE display_name<>'') AS with_name, -- 1813
  COUNT(lat) AS with_coords                                -- 698
FROM users;
```

## What's still owed before live cutover

These are documented in `CUTOVER-CHECKLIST.md`; summarized here:

- [ ] **Triage-review with Ian.** `/tmp/triage.tsv` exists from this session
      (128 archive candidates: 115 no_email, 30 never_login_2y, 15
      no_last_activity, 14 dup_name, 13 no_xprofile). Ian eyeballs and
      picks ids to set `archived_at`.
- [ ] **Test-data residue wipe on real accounts.** Ian's PA profile has
      synthetic-test entries (about section, education credentials, maybe
      catalog tags). Targeted DELETEs *before* re-running the migration
      on prod, so prod users start fresh.
- [ ] **Hand-jigger the 6 unresolved locations** (342, 880, 889, 1076,
      1163, 1347) — these have BB text but no Google geocode either. Pick
      city centroids manually.
- [ ] **GeoLite2 + nginx rate-limit deploy** before the Nominatim editor
      ships to prod (cosmetic for now — public Nominatim works for dev).
- [ ] **Re-run `backfill-avatars.php` on prod** after cutover so it can
      find the real BB upload files (absent on dev).
- [ ] **Identity cleanup**: stop reading first_name/last_name/nickname
      from `wp_usermeta` post-cutover. profile-app is sole identity
      source. Add a one-way mirror `users.display_name` → `wp_users.display_name`
      so wp-admin author bylines stay sane.

## Slice 3.5 — app-ready APIs + webhooks (queued, post-slice-3)

Today's API is **internal** — cookie-authed, browser-driven. To host
third-party apps (mobile/native or external integrations), three gaps:

1. **Token auth.** Personal Access Tokens (user-issued, scoped, revocable).
   Bearer header on `/profile-api/v0/*` (additive — cookies keep working
   for the editor).
2. **Scoped OAuth-style consent flow.** App requests `read:profile`,
   `write:practice`, etc; user approves; PAT returned to app. Avoids the
   "every app gets god-mode" problem.
3. **Outbound webhooks.** Today only `/profile-api/v0/hooks/user-created`
   exists (inbound from WP). No outbound dispatch. Add subscription
   table (`app_id`, `target_url`, `event_types[]`, `signing_secret`)
   and a dispatcher. Initial events: `user.created`,
   `user.profile_updated`, `user.location_changed`, `user.archived`,
   `practice.created`, `practice.updated`.

Plus: CORS allowlist, nginx rate limiting on `/profile-api/v0/`,
minimal API docs page rendered from `schema.php`.

Sequence: do AFTER slice 3 (schema stable) and BEFORE the live cutover
(so the public API has dev-traffic shakedown).

## Slice 3 — what's next

Practices. The bigger feature that unblocks "business_name parks somewhere
useful" (we put it on the users table for now, but the long-term home is
the first auto-created practice). Scope per prior conversations:

- Practices CPT in profile-app (separate table, not WP post type)
- `/p/<slug>` public route with rendering
- Editor UI to create / edit a practice
- "My practices" attached to a user via a join table; first-class section
  on `/u/<slug>` profile pages
- Catalog: add Retail Sales + Tool Maker
- Optional: on user's first practice creation, prefill business_name into
  the practice name field (then we can leave or drop users.business_name)

That's a multi-session slice. Suggest writing the slice-3 prompt next and
treating this handoff as the closing chapter of 2.75.

## Pointers

- Code: `/home/ubuntu/projects/profile-app/`
- Prior slice prompts: `/home/ubuntu/projects/profile-app-slice-*.prompt.md`
- Rotated handoffs: `handoffs/2026-05-25-slice-{zero,one,one-five,two}.md`,
  `2026-05-26-slice-two-five.md`, `2026-05-27-slice-two-seven-five.md`
- CUTOVER-CHECKLIST: `CUTOVER-CHECKLIST.md`
- Walk transcript: `/var/www/dev/mockups/walks/20260526T144135Z/`
- Audit screenshots (BB vs PA, Ian): `/var/www/dev/mockups/audit/ian-{bb,pa}.png`
- Triage TSV (this session): `/tmp/triage.tsv` (will be lost on box reboot;
  re-run `bin/triage-accounts.php > /tmp/triage.tsv` if needed)

## Next-session opening move

1. Read this file.
2. Confirm Ian's three slice-3 decisions:
   - Does first-practice-creation prefill `business_name` (then drop the
     users column), or does the column stay forever?
   - Are "practices" a separate entity attached to users (1 user → N
     practices), or is each user 0-or-1 practice?
   - Public `/p/<slug>` route on day one, or just `/u/<slug>` shows the
     attached practice inline?
3. Draft `profile-app-slice-three.prompt.md` reflecting answers.
4. Paste to terminal Claude.
