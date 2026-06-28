# WEEKLY DIGEST — LIVE DEPLOY RUNBOOK (forum-URL fix + plugin→monorepo wiring)

**Goal:** ship the weekly-digest forum-URL fix to live and wire lg-weekly-digest so future deploys are
a plain `git pull` of the live serve clone. Keeper-reviewed · 2026-06-28 · Ian runs (live writes).

## What ships
- `lg-weekly-digest` forum posts now emit canonical **`/hub/<forum>/<topic>/`** URLs
  (`LG_WD_Query::hub_url`, commit `f5a8cbd`) instead of the legacy `get_permalink()` →301→PG-mirror chain.
- **Rides along** (same `git pull`): the gallery EXIF-orientation fix to profile-app
  (`4ebbfbc`+`1e3827b`) — tested; needs the `.cache` clear in step 5.
- **Wiring:** live's lg-weekly-digest stops being a standalone real-dir repo and becomes a **symlink
  to the serve clone** → from now on `git pull` on the serve clone deploys weekly changes automatically.

## Pre-facts (verified)
- Live serve clone `/home/ubuntu/loothplatformv2-serve` is **clean on `main`** (was `7bbaa0b`).
- Live's current plugin bytes == monorepo PRE-fix bytes (sha `6430562e…`) — so the ONLY intended change
  from the pull is the one-file URL fix. The bash aborts if anything *else* differs (unexpected drift).
- `origin/main` = `f5a8cbd` (URL fix merged).

## Known limitation (not a blocker)
- One weeklyyes topic (id **71710**) is missing from the bb-mirror, so its `/hub/` link renders
  "Topic not found" until a **bb-mirror resync** (separate task — see §Resync). 35 of 36 are fine, and
  NEW topics auto-sync via `bb-mirror-sync.php` (`bbp_new_topic` hook, ~2s). Low stakes for the test.

## DEPLOY BASH (run on live)
```bash
sudo bash -s <<'EOF'
set -uo pipefail
SC=/home/ubuntu/loothplatformv2-serve
PLG=/var/www/dev/wp-content/plugins/lg-weekly-digest
ARCH=/home/ubuntu/standalone-repo-archives; mkdir -p "$ARCH"
TS=weeklylive-20260628

echo "== 0. preflight: serve clone clean on main =="
B=$(sudo -u ubuntu git -C "$SC" rev-parse --abbrev-ref HEAD); echo "branch=$B"
[ "$B" = main ] || { echo "ABORT: serve clone not on main"; exit 1; }
[ -z "$(sudo -u ubuntu git -C "$SC" status --porcelain)" ] || { echo "ABORT: serve clone dirty"; exit 1; }

echo "== 1. pull serve clone -> origin/main (URL fix + plugin + gallery) =="
sudo -u ubuntu git -C "$SC" pull --ff-only origin main
sudo -u ubuntu git -C "$SC" log --oneline -1
test -d "$SC/lg-weekly-digest" || { echo "ABORT: serve clone has no lg-weekly-digest"; exit 1; }

echo "== 2. drift guard: ONLY class-lg-wd-query.php may differ live-vs-serveclone =="
DIFF=$(sudo diff -rq --exclude=.git --exclude=.claude --exclude=.gitignore "$PLG" "$SC/lg-weekly-digest" | grep -v 'class-lg-wd-query.php' || true)
[ -z "$DIFF" ] || { echo "ABORT: unexpected live plugin drift:"; echo "$DIFF"; exit 1; }
echo "ok: only the URL-fix file differs"

echo "== 3. wire plugin: backup + retire standalone .git + symlink -> serve clone =="
cp -a "$PLG" "$PLG.bak-$TS"
[ -d "$PLG/.git" ] && mv "$PLG/.git" "$ARCH/lg-weekly-digest-LIVE-standalone-git-20260628"
rm -rf "$PLG"
ln -s "$SC/lg-weekly-digest" "$PLG"
ls -ld "$PLG"

echo "== 4. opcache reload (web runs new code) =="
systemctl reload php8.3-fpm

echo "== 5. gallery fix rides this pull -> clear stale resize cache =="
rm -rf /srv/profile-app-media/.cache/* 2>/dev/null && echo "gallery cache cleared" || echo "(none)"

echo "== 6. verify =="
sudo -u looth-dev wp --path=/var/www/dev plugin list --status=active --field=name | grep -x lg-weekly-digest && echo "plugin ACTIVE via symlink" || echo "WARN not active"
sudo -u looth-dev wp --path=/var/www/dev eval 'echo class_exists("LG_WD_Query")?"class OK\n":"MISSING\n";'
sudo -u looth-dev wp --path=/var/www/dev eval '$p=get_post(71947); echo $p?("url: ".LG_WD_Query::normalize_post($p)["url"]."\n"):"(71947 n/a on live)\n";'
echo "DONE. Rollback: rm $PLG; mv $PLG.bak-$TS $PLG; systemctl reload php8.3-fpm"
EOF
```
**Expected:** branch=main; pull advances to `f5a8cbd`; drift guard "ok"; symlink created; plugin ACTIVE;
URL printed as `https://loothgroup.com/hub/<forum>/<topic>/`.

## Ian's test
Compose a weekly issue (or open an existing one), add a **From The Forum** weeklyyes topic, **Send Test**
to yourself, and confirm the forum link goes to `/hub/<forum>/<topic>/` (no old `/groups/` or `/members/`).

## Resync (separate, for the 71710 straggler / completeness)
The hub topic page reads the PG mirror, so a topic missing there renders "Topic not found" even with the
right URL. To pull stragglers in: a bb-mirror resync/backfill of `forums.topic` on live (keeper to scope
the exact command — reconcile runs incrementally and didn't cover 71710). Not required for the URL-fix test.

## Decommission (this runbook's cleanup)
- Live standalone plugin `.git` archived to `~/standalone-repo-archives/lg-weekly-digest-LIVE-standalone-git-20260628`.
- After soak: remove `lg-weekly-digest.bak-weeklylive-20260628`. The monorepo is now the sole source; the
  plugin is a serve-clone symlink (deploy = `git pull` on the serve clone).
