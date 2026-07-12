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

## Social Poster (Meta FB/IG) — auto-post events to Facebook + Instagram

**Status: Apps Script side BUILT + dry-run verified (2026-07-12). Live posting is
inert until the WP bridge social endpoints are built** (held pending Ian decision
(b) — which tiers post). Architecture **B** (keeper-approved): this Apps Script
*schedules* and *drafts*; the WP bridge (on LIVE) holds the Meta tokens + Anthropic
key, does the Graph calls, and hosts the public JPEG. **No secret ever lives in
Script Properties, sheet cells, or the repo.**

### New columns (additive, cols 24–29 — safe for existing sheets)
| Col | Header | Role |
|---|---|---|
| 24 | Social Approved | **HARD gate** checkbox — nothing posts unless TRUE (the mail-lock equivalent) |
| 25 | FB Caption | editable draft (Facebook) |
| 26 | IG Caption | editable draft (Instagram, hashtag-forward) |
| 27 | Social Posted | dupe-guard timestamp — once set, the row never re-posts |
| 28 | FB Post URL | written back after a live post |
| 29 | IG Post URL | written back after a live post |

Re-run **Setup Sheet Headers** to add the columns + the Social Approved checkbox
to an existing sheet (additive at the end; existing data untouched).

### Menu items (`🎙️ Looth Showrunners` → Social section)
- **Draft Social Captions for Selected Row** — builds the caption prompt from the
  row and (once the bridge caption endpoint exists) writes `FB`/`IG` drafts into
  the cells for you to edit. Model is a config value (`CONFIG.SOCIAL.CAPTION_MODEL`,
  default `claude-haiku-4-5`; flip to `claude-opus-4-8` after seeing drafts).
- **Post to Social Now (dry run)** — logs the exact bridge request + the modeled
  Graph calls for every eligible row. **Sends nothing.**
- **Post to Social Now (LIVE) ⚠** — CONFIRM-gated; only affects rows with Social
  Approved = TRUE and no prior Social Posted. Inert until the bridge endpoint exists.
- **Test Meta Connection** — (bridge `debug_token`) Page/IG IDs, token expiry, scopes.
- **Install / Remove Social Post Trigger** — daily time trigger in the
  `CONFIG.SOCIAL.POST_HOUR` (11:00) → 12:00 ET window.

### Eligibility (per row, `socialRowVerdict_`) — ALL must hold
`daysOut ≤ CONFIG.SOCIAL.DAYS_OUT (3)` and not past air · WP Post URL present ·
tier in `CONFIG.SOCIAL.TIERS` (**`null` = all**, pending Ian (b) — the one line to
flip to `['Public']`) · **Social Approved = TRUE** · both captions present ·
Social Posted blank (dupe guard) · a `featured_V.*` vertical image in the Drive folder.

### Triple safety gate on LIVE posting
1. **per-row:** Social Approved checkbox TRUE.
2. **global:** Script Property `SOCIAL_LIVE_ENABLED` must equal `'true'` — otherwise
   the scheduled trigger runs **dry-run** (logs only). Defaults off.
3. **reality:** the bridge `social-post` endpoint does not exist yet (built after
   decision (b)). Until all three hold, nothing can reach Meta.

### Bridge contract (to be built on the WP side, post-(b))
- `POST /wp-json/loothdev/v1/social-post` — body `{wp_post_id, fb_caption,
  ig_caption, image_vertical:{filename,mime,data_b64}, dry_run}`. The bridge resolves
  the horizontal featured image from `wp_post_id`, sideloads + PNG→JPEG-converts the
  vertical, does the FB `/photos` + IG `/media`→`/media_publish` calls, returns
  `{ok, fb_url, ig_url}` (or the exact call list when `dry_run`).
- `POST /wp-json/loothdev/v1/social-caption` — `{model, prompt}` → `{fb, ig}`
  (Claude call, server-held Anthropic key).
- `GET /wp-json/loothdev/v1/social-token-status` — Graph `debug_token` passthrough.

### featured_V aspect — OPEN
IG requires JPEG, aspect **0.8 (4:5) … 1.91:1**, ≤8MB. If the vertical asset is
**9:16 (0.56)** it is below the IG floor and would be rejected → the bridge needs a
one-line **4:5 center-crop** before upload (`wp_get_image_editor`→`resize/crop`).
**Blocked:** could not measure the 3 real Drive assets from dev2 (Google Drive MCP
token expired). Needs Drive re-auth or the assets dropped somewhere reachable.

## Known issues / TODO
- **Re-publish dupe risk:** the script writes `view_url` (a pretty permalink with no post
  ID) back to the `WP Post URL` column, then re-parses `?p=` / `wp_post_id=` from it — a
  pretty permalink yields neither, so a second publish creates a **duplicate** instead of
  updating. Fix: append `#wp_post_id=<id>` when writing back (the parser already matches
  `wp_post_id=(\d+)`). Pending Ian's sign-off before patching the canonical script.
- ~~**`zoom_url` not wired**~~ — **DONE 2026-06-22:** Zoom column + default + modal field +
  publish payload all wired (this change). `sheets-zoom-url-patch.gs` is now superseded.
