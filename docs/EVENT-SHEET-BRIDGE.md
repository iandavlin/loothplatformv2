# Event Sheet → `event` CPT Bridge (Showrunner)

How the **Looth Group Live — Showrunner Tracker** Google Sheet publishes rows into
the WordPress `event` custom post type, how it deploys, and how to promote it
dev → live. Repo-first, env-driven, zero code edits to promote.

> Supersedes the dev1-era `docs/showrunner-wp-bridge-CUTOVER.md` (2026-05-16).

## Pieces

| Piece | Where | What it is |
|---|---|---|
| **Apps Script** (Sheet side) | `platform/mu-plugins/loothdev-sheets-bridge.apps-script.gs.txt` (source-of-record in repo) | Google-side script bound to the Sheet. Adds the publish menu + Zoom-link modal, reads each row, POSTs to the WP bridge. "Deploy" = paste into the Sheet's Apps Script editor. Authenticates as `sheets-bot` via Application Password (Basic auth) read from Script Properties. |
| **Bridge mu-plugin** (WP side) | `platform/mu-plugins/loothdev-sheets-bridge.php` → **symlinked** into `$LG_WP_PATH/wp-content/mu-plugins/` | Registers `GET /wp-json/loothdev/v1/user-search` and `POST /wp-json/loothdev/v1/events`. Creates/updates the `event` post, sets ACF fields + `tier`/`region`/`language` terms, sideloads the featured image, and does one **blocking** `_materialize` so the standalone `/event/` render is correct immediately. |
| **`sheets-bot`** WP user | box-local (NOT in git) | Dedicated author + Application Password. Provisioned by `platform/bin/provision-sheets-bot.sh`. Wiped by a DB reload — re-run after every reload. |

## Data flow

```
Sheet row --(Apps Script, Basic auth: sheets-bot)--> POST /wp-json/loothdev/v1/events
   bridge: wp_insert/update_post (event)  -> ACF fields + taxonomies + featured image
   bridge: blocking POST https://127.0.0.1/archive-api/v0/_materialize  (Host: $LG_PUBLIC_HOST)
   --> standalone /event/<slug> render is live immediately
```

`user-search` (`?q=` or `?email=`) is what the Sheet uses to resolve a human name
into a WP author ID before publishing.

## Env hygiene

The bridge takes **no hardcoded host**. Its loopback `_materialize` Host header is
`$_SERVER['HTTP_HOST'] ?? lgsb_public_host()`, and `lgsb_public_host()` reads
`LG_PUBLIC_HOST` from `/etc/looth/env` via `lg_env()` (see `lg-shared/lg-env.php`).
A box without `/etc/looth/env` falls through to a literal guard (absent-safe).
All box-varying values used here live in `/etc/looth/env` — see `env.template`
(policy: `docs/atlas/REPO-MANDATE.md`). The bridge introduces no new env keys.

| Key | Used for |
|---|---|
| `LG_PUBLIC_HOST` | the `_materialize` loopback Host header (dev2.loothgroup.com / loothgroup.com) |
| `LG_WP_USER`, `LG_WP_PATH` | which WordPress the installer + provisioner target |

## Deploy (on a box already running the platform)

```bash
# from the serve tree, after the change is merged to main
cd /home/ubuntu/loothplatformv2-serve && git pull

# 1) symlink the mu-plugin (live file == repo file). Idempotent.
sudo platform/bin/install-sheets-bridge.sh

# 2) (re)provision the bot + mint an app-password. Prints the secret ONCE.
bash platform/bin/provision-sheets-bot.sh
#    -> paste WP_USERNAME / WP_APP_PASSWORD / WP_HOST into the Sheet's
#       Apps Script -> Project Settings -> Script Properties

# 3) paste the Apps Script source (.gs.txt) into the Sheet's Apps Script editor
#    if it changed.
```

The mu-plugin is now a symlink into the serve tree, so subsequent code changes
ship by `git pull` alone — no file copy.

## Promote dev2 → live (ZERO code edits)

1. **Box env** — on the live box set `/etc/looth/env`:
   `LG_PUBLIC_HOST=loothgroup.com`, `LG_WP_USER=<live wp user>`,
   `LG_WP_PATH=<live docroot>` (+ the rest; see `env.template`).
2. **Deploy** — `git pull` on the live serve tree, then
   `sudo platform/bin/install-sheets-bridge.sh` (symlink into live mu-plugins).
3. **Bot** — `bash platform/bin/provision-sheets-bot.sh` on live → paste the
   printed `WP_*` values into the Sheet's Script Properties (point the Sheet at
   the live host).
4. **Re-resolve author IDs** — WP user IDs are **per-box**. In the Sheet's Config,
   **clear column D** (the cached WP author IDs) and re-run the row→user lookup so
   each event's author resolves against live's `wp_users`. The `sheets-bot` ID is
   likewise per-box; step 3 handles it.

No bridge code changes for promotion — only `/etc/looth/env` values, the symlink
install, and the Sheet's Script Properties / Config.

## Gotchas

- **DB reload wipes the bot.** A WP DB reload deletes Application Passwords and can
  recycle the user ID. Re-run `provision-sheets-bot.sh` and re-paste into the Sheet.
- **Per-box user IDs.** Never carry column-D author IDs across boxes — clear + re-resolve.
- **Secret never in git.** The Application Password lives only in the Sheet's Script
  Properties. The script prints it once; nothing on disk or in the repo holds it.
- **Author role.** `sheets-bot` is an `author` (has `publish_posts` + `upload_files`,
  which the bridge permission callback requires). Don't downgrade it.
