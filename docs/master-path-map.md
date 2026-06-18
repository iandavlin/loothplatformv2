# Master path-map — repo source → live target (symlink-farm manifest)

**Owner:** live-deploy / cutover lane · **2026-06-09** · **Status: v1 (reconciliation reference)**

The single source→target map for everything the monorepo deploys. Doubles as the
**symlink-farm manifest**: each live path is a symlink into the repo (or an nginx
`alias`/`root` pointed at it), so `git pull` on the box = live. Convention for
plugins/apps/services is **`projects/<name>`** (matches existing
`lg-layout-v2`/`lg-legacy-import`/`lg-snippets` symlinks). Config (nginx/FPM)
also needs a `reload` after pull.

**v1 = reconciliation reference.** Lanes use it to know canonical paths and ACK
their box-only files to coordinator. Finalized once lane ACKs are in (natural
gate, per coord — no calendar date).

Legend: ✅ in sync · 🔁 drift (capture current box bytes) · ➕ missing from repo
(capture/fold) · 🔗 already symlinked · ⛔ never git · ⏸ held.

---

## 1. WP plugins  →  symlink  →  `wp-content/plugins/<name>`
| Source (repo) | Live target | Status / action |
|---|---|---|
| `projects/lg-layout-v2` | `wp-content/plugins/lg-layout-v2` | 🔗 symlinked |
| `projects/lg-legacy-import` | `…/lg-legacy-import` | 🔗 symlinked |
| `projects/lg-snippets` | `…/lg-snippets` | 🔗 symlinked (snippet-fold target) |
| `projects/lg-patreon-stripe-poller` | `…/lg-patreon-stripe-poller` | ➕ in repo but deployed as **COPY** → swap to symlink |
| `projects/lg-apps` | `…/lg-apps` | ➕ box-only → **coord folds** → symlink |
| `projects/lg-anonymous-authors` | `…/lg-anonymous-authors` | ➕ box-only → **coord folds** → symlink |
| `projects/lg-recent-posts-widget` | `…/lg-recent-posts-widget` | ➕ box-only → **coord folds** → symlink |
| `projects/lg-weekly-digest` | `…/lg-weekly-digest` | ➕ box-only → **coord folds** → symlink |
| `projects/event-reminder-and-cleaner` | `…/event-reminder-and-cleaner` | ➕ box-only → **coord folds** → symlink |

## 2. mu-plugins  →  symlink each `.php` (FLAT)  →  `wp-content/mu-plugins/`
Source dir: `projects/platform/mu-plugins/`. WP auto-loads only top-level `.php`.
| File | Status / action |
|---|---|
| loothdev-sheets-bridge, profile-whoami-shim, lg-viewer-tier, lgms-admin-view-as-toggle, profile-auth, lg-admin-tools, bb-forum-author-delete | ✅ in sync |
| archive-poc-sync.php, bb-mirror-sync.php, profile-sync.php | 🔁 drift → capture box bytes |
| lg-article-materializer.php, lg-comments-frame.php | ➕ missing → capture |
| lg-user-audit.php | ⛔ **REMOVE** (temp Patreon logger — do not carry) |
| buddyboss-performance-api.php, burst_rest_api_optimizer.php | ⛔ 3rd-party — **exclude** (not ours) |
| lg-membership-chrome.php (+ dir) | ⛔ **retired** (§0b) — do not deploy |

## 3. Standalone apps  →  `/srv/<app>` (symlink on live; dev serves from repo path)
| Source (repo) | Live target | Status / action |
|---|---|---|
| `projects/archive-poc` | `/srv/archive-poc` | ✅ dev-served from repo |
| `projects/bb-mirror` | `/srv/bb-mirror` | ✅ dev-served from repo |
| `projects/profile-app` | `/srv/profile-app` | ✅ dev-served from repo |
| `projects/events` | `/srv/events` | ✅ dev-served from repo |
| `projects/membership-pages` | `/srv/membership-pages` | ✅ in repo; served via nginx **alias** → `…/web/` |
| `projects/lg-shared` | `/srv/lg-shared` | ⚠️ **PRIORITY** — load-bearing canonical site-header. Source confirmed = `projects/lg-shared` (NOT lg-shell). Deployed copy **DRIFTED** + `.bak` clutter → reconcile drift → symlink |

## 4. /srv support services  (box-only, NO .git → capture into monorepo)
| Source (to create) | Live target | Status / action |
|---|---|---|
| `projects/lg-push` | `/srv/lg-push` | ✅ CODE captured to repo (vendor excluded). Runtime: cron + /etc/lg-vapid + DB. |
| `lg-sudo-queue` | — | ⛔ DEV-ONLY coordination infra (queue data + notify svc) — NOT git, NOT cut (like chrome-dev.service) |
| `profile-app-media` | `/srv/profile-app-media` | ⛔ user-media DATA (15M avatars/banners/gallery/resumes) — NOT git; rsync as data at cut |
| `projects/lg-stripe-billing` | `/srv/lg-stripe-billing` | ✅ FOLDED to monorepo (Ian) — code in git, farm target. Box needs `composer install` + provisioned `.env` (test keys; live keys at Stripe-enable). |
| `thumb-app` (SEPARATE CLONE) | `/srv/thumb-app` | 🔗 KEEP separate (Ian). Clone-on-box: `iandavlin/thumbnail-gen-editor`, branch `feature/per-user-namespacing` @ `e31f3b5`. NOT folded. |

## 5. nginx snippets  →  symlink  →  `/etc/nginx/snippets/`  (`nginx -t && reload` after)
Source dir: `projects/platform/nginx/`.
| File | Status / action |
|---|---|
| strangler-archive-poc.conf | 🔁 drift **73→309 lines** → capture |
| strangler-profile-app.conf | 🔁 drift 185→237 → capture |
| strangler-bb-mirror.conf | 🔁 drift 100→108 → capture |
| strangler-membership.conf | ➕ missing from repo → capture |
| lg-shared.conf | 🔁 drift 25→29 → capture |
| strangler-events.conf | ✅ in sync |
| **cookie-gate block** | 🔁 lives in site conf `dev.loothgroup.com.conf` (token/claim/exempt) → capture site-conf gate block separately |

## 6. FPM pools  →  symlink  →  `/etc/php/8.3/fpm/pool.d/`  (`systemctl reload php8.3-fpm`)
Source dir: `projects/platform/fpm/`.
| File | Status / action |
|---|---|
| archive-poc.conf | 🔁 drift → capture |
| membership.conf | ➕ missing → capture |
| looth-dev.conf | ➕ missing → capture |
| bb-mirror.conf, events.conf, profile-app.conf | ✅ in sync |

## 7. systemd units  →  `/etc/systemd/system/`  (`daemon-reload`)
Source dir: `projects/platform/systemd/`.
| Unit | Status / action |
|---|---|
| bb-mirror-reconcile.service / .timer | ✅ tracked |
| lg-sudo-queue-notify.service | ➕ verify tracked → capture if box-only |

## 8. Loose webroot assets  →  symlink  →  docroot
Source dir (to create): `projects/platform/webroot/`.
| Item | Status / action |
|---|---|
| ~18 JS/CSS (mobile-hub, hub-polish, pwa, sw, directory/profile/events/bottom-nav/push, …) | ➕ box-only → **buck-COORD owns reconcile/ACK**; live-deploy publishes canonical path |

## 9. NEVER git — runbook (provisioned on box)
- **Secrets:** `/etc/lg-internal-secret`, `/etc/lg-archive-poc-secret`, `/etc/lg-profile-app-secret`, `/etc/lg-events-db`, `/etc/lg-membership-db`, `/etc/looth/jwt-*.pem`, `/etc/lg-vapid/*`, `/etc/nginx/loothdev_token`.
- **DB-stored state:** `wp_snippets`, plugin active/inactive state, active theme (cut-day: activate stock `twentytwentyfive`, drop BB child/parent).
- **Stripe/Patreon creds:** ships dormant (no creds) per coord §3h.

---

### Held / open
- `lg-stripe-billing`, `thumb-app` — fold-vs-separate-clone (Ian).
- Webroot file set — buck-COORD ACK pending (gates path-map finalize).
- LIVE recon (wp_snippets + active_plugins + active theme) — drafted separately
  for coord review → Ian runs read-only on live.
