# Showrunner Tracker → WP `event` bridge — Apps Script

Reference copy + runbook for the Google Apps Script bound to the Sheet
**"Looth Group Live — Showrunner Tracker"**. The live script lives in Google
(Extensions → Apps Script); `Code.gs` here is the canonical reference copy.

> **Environment: dev2.** All dev work targets `dev2.loothgroup.com`. The old
> `dev.loothgroup.com` box is decommissioned. dev2 serves from `main`.

## Files
- `Code.gs` — canonical reference copy of the bound Apps Script (keep in sync with Google).
- WP side (separate lane, in git): `platform/mu-plugins/loothdev-sheets-bridge.php`.
- Spec / cutover: `docs/showrunner-wp-bridge-CUTOVER.md`.

## What it does
Adds a `🎙️ Looth Showrunners` menu to the Sheet. The WP-bridge half:
- **Set WP Credentials…** — stores `WP_BASE_URL` / `WP_USERNAME` / `WP_APP_PASSWORD`
  in Script Properties (never hardcoded).
- **Test WP Connection** — `GET /wp-json/loothdev/v1/user-search?per_page=1`.
- **Resolve WP User IDs (Config)** — `GET …/user-search?email=` per showrunner → Config col D.
- **Publish Selected Row to WP** — maps the row → `POST /wp-json/loothdev/v1/events`,
  side-loads a `featured.*` image from the row's Drive folder as base64, writes the
  returned post URL back into the `WP Post URL` column (re-publish updates, no dupe).

## Column → payload map (`publishRowToWp_`)
| Sheet col | Payload field |
|---|---|
| Episode Title | `title` |
| Air Date (date) | `start_date` (Ymd) + `time_of_event` (`h:i a`) |
| Showrunner → Config WP User ID | `author_id` |
| Event Tier | `tier` |
| Blurb Text | `blurb` → post_content |
| Topic / Description | `topic` → post_excerpt |
| Region | `region` (optional) |
| Language (comma list) | `languages[]` (optional) |
| **Zoom Link** | `zoom_url` (optional) → gated Join CTA |
| Drive Folder → `featured.*` | `image{filename,mime,data_b64}` |

## Install (in the Sheet)
1. Extensions → Apps Script → paste `Code.gs` over the whole project. Save.
2. Reload the Sheet → the `🎙️ Looth Showrunners` menu appears.
3. **Set WP Credentials…** → Base URL `https://dev2.loothgroup.com`, user `sheets-bot`,
   app password (the **dev2** one — not dev's).
4. **Test WP Connection** → should return a sample user.
5. **Resolve WP User IDs (Config)** → fills Config col D from emails.
6. Per episode row: set **Event Tier**, drop a `featured.*` image in the row's Drive folder.
7. **Publish Selected Row to WP**.

## dev2 prerequisites (NOT in git — confirm before first publish)
- `sheets-bot` WP user exists on dev2 + a freshly-generated Application Password.
- nginx cookie-gate exemption for `/wp-json/loothdev/` on dev2.
- Cloudflare lets the bridge route through (dev2 is behind CF; it currently challenges it).

## Zoom Link (virtual-attend) — see `ZOOM-LINK.md`
Column **W / `Zoom Link`** (`CONFIG.COL.ZOOM_URL`) carries the per-event Zoom link.
New events default to `CONFIG.DEFAULT_ZOOM_URL` (the standing Looth Group room) and stay
editable. On publish it's sent as `zoom_url` → WP writes `zoom_url_for_looth_group_virtual_event`
→ the `event-header` block renders it as the **gated** "Join" CTA (shown only to satisfied
tiers; never emitted into an under-tier viewer's DOM). To roll the default room, edit
`CONFIG.DEFAULT_ZOOM_URL` once. Full chain + how-to in [`ZOOM-LINK.md`](ZOOM-LINK.md).

## Known issues / TODO
- **Re-publish dupe risk:** the script writes `view_url` (a pretty permalink with no post
  ID) back to the `WP Post URL` column, then re-parses `?p=` / `wp_post_id=` from it — a
  pretty permalink yields neither, so a second publish creates a **duplicate** instead of
  updating. Fix: append `#wp_post_id=<id>` when writing back (the parser already matches
  `wp_post_id=(\d+)`). Pending Ian's sign-off before patching the canonical script.
- ~~**`zoom_url` not wired**~~ — **DONE 2026-06-22:** Zoom column + default + modal field +
  publish payload all wired (this change). `sheets-zoom-url-patch.gs` is now superseded.
