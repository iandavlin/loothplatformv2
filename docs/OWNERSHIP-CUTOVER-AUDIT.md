# Ownership audit → game-day normalization

**Date:** 2026-06-02 · **By:** ubuntu (sysadmin) · **Scope:** dev box app + file trees
**Companion memory:** project_cutover_www_data_ownership

## TL;DR

The "weird ownerships" are real and they come from two distinct eras/mechanisms,
which means a single blanket `chown -R www-data` is the WRONG move **on dev** — it
would break the per-user code-server editing model the team relies on. The collapse
to stock `www-data` is a **cut-day action on the cut target**, not a dev action.
Below is the current state, the target state, and the safe sequence.

## Current state (census, node_modules/.git/vendor pruned)

### `/var/www/dev` (dev.loothgroup.com WordPress)
| owner:group | count | meaning |
|---|---|---|
| looth-dev:loothdevs | 46166 | runtime/FPM-written core+content — the bulk, correct-ish |
| **ian:loothdevs** | **5041** | **legacy: Ian's own user owns webroot files (live-era gating)** |
| www-data:loothdevs | 288 | mixed-era |
| looth-dev:looth-dev | 244 | inconsistent group |
| ubuntu:www-data | 198 | my sysadmin drops |
| www-data:www-data | 132 | the actual cut target shape |
| ubuntu:ubuntu | 90 | my drops, wrong group |
| root:looth-dev / root:loothdevs | 60 / 50 | root-placed |
| james:loothdevs | 1 | stray |

### `/var/www/dev.loothtool` (dev.loothtool.com WordPress)
| owner:group | count |
|---|---|
| tool-dev:loothdevs | 47693 |
| **buck:loothdevs** | **3235**  ← legacy team-user ownership in webroot |
| ian:loothdevs | 2 |

### `/srv` (backend apps)
| app | dominant owner | notes |
|---|---|---|
| lg-shared | www-data:www-data | **already the target shape** ✓ |
| lg-stripe-billing | ccdev:loothdevs (+1 ian) | runtime is ccdev |
| lg-sudo-queue | root:loothdevs | fine (privileged queue) |
| profile-app-media | profile-app:profile-app | uploads dir — runtime-owned ✓ |
| thumb-app | ccdev:loothdevs (+ian, +looth-dev) | mixed |

### `/home/buck/looth-platform` (profile-app source + relays)
buck:loothdevs (1042) / buck:buck (28) — **correct**: this is Buck's working tree,
edited via his code-server. Should NOT be touched on dev.

## Why blanket www-data is wrong *on dev*

Team users (ian/buck/…) own files in the webroots **on purpose**: each edits via
their per-user `code-server@<user>.service`. Chowning their files to www-data strips
their write access → breaks live-preview editing. The team-user ownership IS the dev
model. The shared `loothdevs` group (gid 988-ish family) + group-write is what lets
FPM and the editor coexist today.

So there are two valid target shapes depending on box:
- **Dev box (this machine):** keep team-user ownership for source trees; only fix the
  genuinely-wrong bits (root drops, mismatched groups, my ubuntu:ubuntu strays).
- **Cut target (loothgroup.com production):** collapse to stock `www-data:www-data`
  for webroots + service users for `/srv` apps, since no human edits in place there.

## Target state for game day (cut target)

> **Reconciled with `cutover/CUTOVER-PLAN.md` v0.3 (blue-green).** The cut box's WP
> runs as **`looth-live`** (see Step 7c `chown -R looth-live`), NOT www-data — so the
> webroot target is `looth-live`, not the "stock www-data" first guess. `/srv` apps
> get **per-app system users** (Step 4). www-data is only correct where an app already
> uses it (e.g. `/srv/lg-shared`). Net: "or whatever it takes to start fresh" = the
> right *service* user per surface, not one uniform owner.

| tree | target owner:group | mode posture |
|---|---|---|
| `/var/www/html` webroot (WP code) | `looth-live:looth-live` | 644/755, no group-write |
| `…/wp-content/uploads`, cache, any FPM-written dir | `looth-live:looth-live` | 664/775 (runtime writes) |
| `/srv/<app>` code | dedicated svc user per app (www-data where already used) | 644/755 |
| `/srv/*-media`, upload/cache dirs | runtime svc user (e.g. profile-app) | 775 |
| **`/etc/looth/jwt-private.pem`** (+ any key/secret) | **`root:<consuming-fpm-pool>` 640** | the dev-box bug class — verify `sudo -u <pool> test -r` |
| secrets / DSN configs | `root:<svc>` 640 | least-privilege |

The blue-green model means the dev legacy ownership below does **not** propagate to the
new box (only uploads + DB carry over; code is re-dropped). The audit's lasting value is
the **key/secret posture row** (P10 in the cutover plan) — that's the one that silently
broke for a week on dev.

## Prerequisites BEFORE any collapse (do not skip)

1. **Resolve postgres peer-auth → password DSNs.** Several apps connect via unix
   peer-auth tied to the *current* unix user. Chowning the process user breaks the
   DB connection silently. Convert to host+password DSNs first. (See cutover memo.)
2. **Inventory FPM pool users per site** and confirm which dirs each pool must write
   (uploads, cache, sessions, sqlite) — those stay runtime-writable, not 644.
3. **Snapshot current ownership** (`find … -printf '%p %u:%g %m\n'`) so the cut is
   reversible.

## Safe-now cleanups (dev box, low risk, reversible)

These fix genuine inconsistencies WITHOUT breaking the editing model:
- Normalize my own strays: `ubuntu:ubuntu` and `ubuntu:www-data` drops in
  `/var/www/dev` → match siblings (`looth-dev:loothdevs`).
- Unify group on the `looth-dev:looth-dev` (244) files → `loothdevs`.
- Re-home the `james:loothdevs` (1) stray and `ian:loothdevs` (2 in loothtool).

**Open decision for Ian:** do you want me to (a) just keep this as the game-day
runbook and execute it on the cut target at cut, (b) run the safe-now dev cleanups
listed above, or (c) build a dry-run `normalize-ownership.sh` (snapshot + chown +
rollback) to rehearse the full collapse against a copy?
