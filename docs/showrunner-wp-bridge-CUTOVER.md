# Showrunner Tracker → WordPress Event CPT — Cutover Doc

**Date:** 2026-05-16
**Status:** WP side built and tested. Apps Script side pending.

## Goal

Let the Google Sheet "Looth Group Live — Showrunner Tracker" publish rows directly to the WordPress `event` CPT on dev.loothgroup.com, with all required ACF fields populated and the featured image side-loaded from the row's Drive folder.

## What's deployed

### 1. WP user
- **Username:** `sheets-bot`
- **Email:** sheets-bot@loothgroup.com
- **ID:** 1901
- **Role:** Editor (has edit_posts / publish_posts / upload_files / manage_categories)
- **Application password (label "Showrunner Tracker Sheet"):** `MbnW ZJe4 TVnO EIcT 6MYv A4DM` (paste without spaces)

Store the app password in the Apps Script's Script Properties as `WP_APP_PASSWORD`. Username goes alongside as `WP_USERNAME = sheets-bot`.

### 2. mu-plugin
[/var/www/dev/wp-content/mu-plugins/loothdev-sheets-bridge.php](/var/www/dev/wp-content/mu-plugins/loothdev-sheets-bridge.php)

Registers two REST routes under `/wp-json/loothdev/v1/`:

#### `GET /user-search`
Query params:
- `q` — partial match against user_login, user_email, user_nicename, display_name
- `email` — exact email lookup (returns one user or empty array)
- `per_page` — 1..100, default 25

Auth: Basic auth, requires `edit_posts`.

Response: `[{id, username, email, display_name}, ...]`

#### `POST /events`
Auth: Basic auth, requires `publish_posts` + `upload_files`.

Body (JSON):
```
{
  "wp_post_id":    <int, optional, for updates>,
  "title":         "<string, required>",
  "author_id":     <int, required — WP user ID, becomes post_author>,
  "status":        "publish" | "draft",   // default draft
  "start_date":    "YYYY-MM-DD",          // required — stored as Ymd to match ACF
  "time_of_event": "h:i a",               // required — e.g. "7:30 pm"
  "tier":          "Public" | "Looth Lite" | "Looth Pro",   // required — by name or slug
  "blurb":         "<string>",            // → post_content
  "topic":         "<string>",            // → post_excerpt
  "region":        "<slug or name>",      // optional
  "languages":     ["English", ...],      // optional
  "zoom_url":      "<url>",               // optional
  "image": {
    "filename": "may-show-slug-1234.jpg",
    "mime":     "image/jpeg",
    "data_b64": "<base64-encoded bytes>"
  }                                       // optional
}
```

Response on success:
```
{ "ok": true, "wp_post_id": 12345, "edit_url": "...", "view_url": "...", "status": "draft" }
```

Errors: 400 (bad JSON), 422 (missing/invalid fields), 500 (upload failure).

### 3. nginx cookie-gate exemption
[/etc/nginx/sites-available/dev.loothgroup.com.conf](/etc/nginx/sites-available/dev.loothgroup.com.conf) has a new `location ^~ /wp-json/loothdev/` block that bypasses the cookie gate. Mirrors the existing `lg-member-sync` exemption.

If we later move this to dev.loothtool.com or production, the same block needs to be added there.

## ACF field-key cheat sheet (for `event` CPT, field group 26777)

| Label | Key | Type | Required |
|---|---|---|---|
| Event Tier | `field_69af0fa4a346d` | taxonomy `tier` | ✅ |
| Start Date | `field_66579fcdd963d` | date Ymd | ✅ |
| Time Of Event | `field_66647d708283b` | time `g:i a` | ✅ |
| Featured Image | `field_6657a3e243c6e` | image | ✅ |
| Region | `field_66587f95411e5` | taxonomy `region` | — |
| Language | `field_6658811487962` | taxonomy `language` (multi) | — |
| Zoom URL | `field_66647b9a6fd15` | url | — |

**Note:** broken conditional-logic rules on Time / Featured Image / Zoom URL were removed via wp-cli on 2026-05-16. They now show unconditionally.

## Apps Script side — code complete, deployment steps for the user

The full updated Apps Script lives in [/home/ubuntu/projects/Showrunner Automation.txt](Showrunner Automation.txt) (≈340 new lines appended; existing functions extended).

### Rollout steps (in the spreadsheet)

1. **Paste the updated code** into Extensions → Apps Script (replace the entire `Code.gs`).
2. **Set credentials** — menu `🎙️ Looth Showrunners → Set WP Credentials…`. Enter:
   - Base URL: `https://dev.loothgroup.com`
   - Username: `sheets-bot`
   - App password: `MbnWZJe4TVnOEIcT6MYvA4DM`
3. **Test connection** — menu `Test WP Connection`. Should return a sample user.
4. **Migrate Config sheet:**
   - Option A *(safe — preserves edits)*: manually add a header `WP User ID` to **cell D2** of the Config sheet. Leave column D blank for now.
   - Option B *(destructive — re-seeds the roster)*: run `Setup Config Sheet`. Overwrites custom rows.
5. **Resolve WP User IDs** — menu `Resolve WP User IDs (Config)`. For each showrunner with an email, it queries WP and fills column D. Showrunners without an email will be flagged.
6. **Migrate Episodes sheet** — run `Setup Sheet Headers`. This adds:
   - Column S: `Event Tier` (dropdown: Public / Looth Lite / Looth Pro)
   - Column T: `Region` (optional)
   - Column U: `Language` (optional, comma-separated like `English, Spanish`)
   - Column V: `WP Post URL` (admin-protected, filled by publish script)
7. **Per row, fill in Event Tier** before publishing.
8. **Featured image:** drop a file named `featured.jpg` (or `.png` / `.webp`) into the row's Drive folder. If no `featured.*` file is found, the publish script falls back to the largest image in the folder.
9. **Publish:** click a row → menu `Publish Selected Row to WP`. If validation passes → live publish. If not → prompts to post as draft.

### Filename convention applied at publish time

The Drive file is uploaded to WP media as `{month}-{show-slug}-{4digit-random}.{ext}` — e.g. `may-acoustic-guitar-builders-club-4729.jpg`. Source file in Drive keeps its original name.

### Re-publishing the same row

The script writes the WP post URL into column V on first publish. On subsequent runs, it parses the post ID from that URL and sends `wp_post_id` in the payload — so re-runs **update** the existing post instead of creating duplicates.

### Where credentials live

In Apps Script → Project Settings → Script Properties:
- `WP_BASE_URL`
- `WP_USERNAME`
- `WP_APP_PASSWORD`

Set/changed via the menu, never hard-coded in source.

## Quick test (curl) — known good

```
AUTH=$(echo -n "sheets-bot:MbnWZJe4TVnOEIcT6MYvA4DM" | base64 -w0)

# user lookup
curl -H "Authorization: Basic $AUTH" \
  "https://dev.loothgroup.com/wp-json/loothdev/v1/user-search?email=ian.davlin@gmail.com"

# create event (draft)
curl -X POST -H "Authorization: Basic $AUTH" -H "Content-Type: application/json" \
  "https://dev.loothgroup.com/wp-json/loothdev/v1/events" \
  -d '{"title":"X","author_id":1,"status":"draft","start_date":"2026-06-15","time_of_event":"7:30 pm","tier":"Public"}'
```

## Cutover to production (later)

When we're ready to flip this to live loothgroup.com:

1. Copy `loothdev-sheets-bridge.php` to the prod site's mu-plugins.
2. Create the `sheets-bot` user on prod, generate a new app password (don't reuse dev's).
3. If prod is also behind any access gate (it isn't currently, but check), exempt `/wp-json/loothdev/`.
4. Update the sheet's Script Properties to point `WP_BASE_URL` at prod.
5. **Re-resolve `WP User ID` in the Config sheet** — IDs are different between dev and prod.
6. Re-run "Publish to WP" on existing rows? Probably no — leave the dev-side `wp_post_id` blank in the WP Post URL column so a fresh publish creates new prod posts.
