# Git-native deploy model + "everything in git" mandate (ALL LANES)

**From:** live-deploy / cutover lane (via coordinator) · **2026-06-09**
**Status:** locked decision — broadcast to every lane, effective now.

This doc is the deliverable coordinator relays to all lanes. The fenced block
below is paste-ready. Rationale + the live-deploy specifics follow it.

---

```
╔═══════════════════════════════════════════════════════════════════╗
║  ALL LANES — DEPLOY MODEL CHANGE + "EVERYTHING IN GIT" MANDATE     ║
║  From: live-deploy/cutover lane (via coordinator) · 2026-06-09     ║
╚═══════════════════════════════════════════════════════════════════╝

WHY YOU'RE GETTING THIS
The cut is going git-native. We just locked the deploy model, and it
changes how every lane works starting now.

THE DECISIONS (locked)
• Cut shape: clone live → a NEW self-contained box (its own MariaDB +
  Postgres, local) → flip traffic → old box stays frozen = instant
  rollback. loothtool stays on the old box, untouched.
• Deploy model: ONE monorepo (looth-platform) + symlink farm on the box.
  Edit in the repo → push → `git pull` on live → it's live. The repo is
  the SINGLE SOURCE OF TRUTH. (Config zones — nginx/FPM — also need one
  `reload` after pull.)

THE MANDATE — every lane, every surface
1. STOP editing live-serving copies in place. No more hand-edits to
   wp-content/, /srv/, /etc/, or the webroot. Edit the REPO copy and let
   it deploy. In-place edits = drift = SILENTLY LOST AT CUT.
2. Everything your lane added that drives a live feature MUST be committed
   to the monorepo, staged BY PATHSPEC (never `git add -A` — it sweeps a
   neighbor's files). If it only exists on the box, the cut can't see it
   and it will be lost.
3. Before your lane is marked cut-eligible (dev-complete + dev-proven),
   it must also be GIT-COMPLETE: every file it relies on is in the repo at
   its canonical path. live-deploy will publish a master path-map so you
   know exactly where each file belongs.

WHAT DOES *NOT* RIDE GIT (don't rely on a pull for these)
• Secrets (/etc/lg-*, JWT/VAPID keys) — provisioned on the box, never
  committed. These live in live-deploy's runbook.
• DB-stored state — code-snippets, which plugins are active, the active
  theme. These are in the database, not files. Also runbook.
  → If your lane introduces a NEW secret or a NEW DB-state dependency,
    FLAG IT to coordinator so it gets into the runbook. Untracked = it
    won't exist on the new box.

KNOWN DRIFT ALREADY FOUND (owning lane: please reconcile into the repo)
• nginx snippets edited in /etc, stale/missing in git: archive-poc (73
  lines in git vs 309 live!), profile-app, bb-mirror, lg-shared,
  membership. → route through coordinator; live-deploy will capture the
  current /etc bytes.
• ~18 loose webroot JS/CSS files exist ONLY on the box (mobile-hub,
  hub-polish, pwa, sw, directory/profile/events/bottom-nav/push, etc).
  → front-end/mobile lane.
• mu-plugins missing from repo: lg-article-materializer, lg-comments-frame.
  Drifted: archive-poc-sync, bb-mirror-sync, profile-sync.
• Active custom plugins missing from repo: lg-apps, lg-anonymous-authors,
  lg-recent-posts-widget, lg-weekly-digest, event-reminder-and-cleaner.

ACK REQUESTED
Each lane: reply to coordinator with (a) any box-only/in-place files your
lane owns that aren't yet in the monorepo, and (b) confirmation you've
switched to edit-in-repo. live-deploy gathers the central drift; lanes
own their own surfaces.
```

---

## Rationale (for lanes that want the why)

The cut clones live onto a fresh box and flips traffic. Anything that isn't
in the monorepo doesn't ride the `git pull` onto that box — so an in-place
edit on the dev box is invisible to the cut and is lost. The audit that
prompted this found real drift: an nginx snippet 73 lines in git vs **309**
live, 18 front-end files existing **only** on the box, and several active
plugins/mu-plugins not in the repo at all.

The fix is to make the repo the single source of truth and deploy it
**in place** via a symlink farm: each live path (a plugin dir, a config
file, a webroot asset) is a symlink into the repo. Then `git pull` updates
the repo and the change is live with no copy step. Dev is already ~40% there
organically — `lg-layout-v2`, `lg-snippets`, `lg-legacy-import` are already
symlinks into the repo, and the standalone apps are served straight from the
repo path.

## The irreducible "doesn't ride git" layer (live-deploy's runbook)

Even with perfect push-pull, two classes never live in files:

- **Secrets** — `/etc/lg-*`, `/etc/looth/*.pem` (JWT), `/etc/lg-vapid/*`
  (web-push). Provisioned on the box.
- **DB-stored state** — `wp_snippets`, plugin active/inactive state, the
  active theme (cut-day: activate stock `twentytwentyfive`, drop the
  BuddyBoss child/parent theme — RESOLVED 2026-06-09).

These stay in the live-deploy cut-day runbook. Lanes only owe a **flag** when
they introduce a new one.

## What live-deploy does next

1. Publish the **master path-map** — every repo source path → its canonical
   live target — which doubles as the symlink-farm manifest.
2. Capture the central drift (nginx snippets, mu-plugins) into the repo.
3. Script the one-time symlink-farm provisioning (idempotent, dry-run first).
