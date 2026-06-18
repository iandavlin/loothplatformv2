# looth-platform — deploy MANIFEST

Source-of-truth → live-target map for everything looth-platform deploys. The
repo (`github:iandavlin/looth-platform`, working tree `/home/ubuntu/projects`)
is authoritative; `deploy.sh` places each subtree at its target.

**Workflow rule:** edit in the repo, deploy to target. Do NOT edit live-serving
copies in place (that's what caused the 10:55 shim clobber). Commit at the end
of every change set (see STRANGLER-COORDINATION.md §Commit discipline).

## Map

| Repo path | Live target | Notes |
|---|---|---|
| `profile-app/` | `/srv/profile-app/` | standalone PHP+pg app; own FPM pool |
| `archive-poc/` | `/srv/archive-poc/` | standalone; `discovery` pg schema |
| `bb-mirror/` | `/srv/bb-mirror/` | standalone; `forums` pg schema |
| `lg-shared/` | `/srv/lg-shared/` | shared header/footer/css; nginx-served at `/lg-shared/` |
| `events/` | `/srv/events/` | standalone events-landing surface at `/events/`; reads WP MySQL read-only (no WP boot); own FPM pool (`events` user) + nginx route. Post-deploy: create `events` user, provision `/etc/lg-events-db`, then the FPM/nginx snippets below land it — add the `include …/strangler-events.conf;` line to the live site conf + reload. |
| `lg-layout-v2/` | `wp-content/plugins/lg-layout-v2/` | WP plugin (build versioned zip per deploy doc) |
| `lg-legacy-import/` | `wp-content/plugins/lg-legacy-import/` | WP plugin |
| `lg-patreon-stripe-poller/` | `wp-content/plugins/lg-patreon-stripe-poller/` | WP plugin; tier/role authority |
| `platform/mu-plugins/*.php` | `wp-content/mu-plugins/` | **must land FLAT** (WP auto-loads only top-level .php; subdirs need a loader) |
| `platform/nginx/*.conf` | `/etc/nginx/snippets/` | strangler snippets; `nginx -t && reload` after |
| `platform/fpm/*.conf` | `/etc/php/8.3/fpm/pool.d/` | app pools; `systemctl reload php8.3-fpm` after |
| `platform/systemd/*` | `/etc/systemd/system/` | `daemon-reload` + enable after |

## Secrets — PROVISION on target, NEVER committed
- `/etc/lg-internal-secret` (poller↔profile-app shared secret)
- `/etc/lg-archive-poc-secret`, `/etc/lg-archive-poc-db`
- `/etc/lg-events-db` (events app → WP MySQL read-only creds: `DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_HOST` lines; mode 640 `root:events`)
- per-app pg passwords / db secret files (peer-auth where possible → no file)
- the looth-platform deploy key (`~/.ssh/looth_platform_deploy`)
- `setfacl -m u:profile-app:r /etc/lg-internal-secret` (read gotcha)

## Separate repos (NOT part of looth-platform)
- `/srv/lg-stripe-billing` → `github:iandavlin/lg-stripe-billing`
- `/srv/thumb-app` → `github:iandavlin/thumbnail-gen-editor`

## ⏳ Scope-TBD (Ian to confirm in or out)
WP plugins of unclear status — NOT gathered yet:
`lg-apps`, `lg-media-tags`, `lg-recent-posts-widget`, `lg-weekly-digest`,
old `lg-layout` (pre-v2), and mu-plugin `bb-forum-author-delete.php`
(gathered provisionally — confirm it's ours).

## RETIRED — do NOT deploy (replaced by standalone surfaces, §0b)
- **`platform/mu-plugins/lg-membership-chrome.php`** (+ `lg-membership-chrome/`) —
  the retired `template_include` membership chrome. Kept on dev as a render
  reference for the standalone membership port to diff against; **do not deploy.**
  Delete (dev + repo) once the standalone membership surface lands.
- **`lg-events-landing.php`** (template_include events landing) — same: retired
  by §0b. Don't commit/deploy the template_include version; build standalone.
- deploy.sh should skip these; the cut never carries WP-templated chrome.

## Excluded (deliberately)
Third-party mu-plugins (`buddyboss-performance-api`, `burst_rest_api_optimizer`),
`looth-vendor/`, all `*.bak*`, `*.deprecated-*` plugins, dev-only infra
(idle-shutdown daemon, chrome-dev.service), `vendor/`+`node_modules/`
(composer/npm-regenerable).

## Env split
Apps key off env (e.g. profile-app's `LG_PROFILE_APP_ENV` → dev vs live hosts/
DBs/paths). nginx/FPM paths differ dev vs live (`/home/ubuntu/projects/<app>`
on dev served-from vs `/srv/<app>` on live) — `deploy.sh` targets live paths.
