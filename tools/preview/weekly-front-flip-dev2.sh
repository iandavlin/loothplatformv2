#!/usr/bin/env bash
# weekly-front-flip-dev2 — switch backlog 8 ON for dev2 ONLY, and prove it.
#
# WHY A SCRIPT FOR TWO LINES IN TWO FILES. It edits php-fpm pool config and
# reloads the service every request on this box goes through, and Ian browses
# dev2 while the fleet runs. Doing that by hand at speed is how a box breaks:
# this validates the config BEFORE reloading, is idempotent, has --off, and
# proves the outcome rather than assuming it.
#
# ── WHY THE ENV AND NOT THE TRACKED CONFIG ──────────────────────────────────
# platform/config/weekly-front.php stays `false` in the repo. Flipping it there
# would reach LIVE on Ian's next paste, and he has approved a LOOK on dev2, not
# a release. The env override exists precisely for this: dev2 says yes, the repo
# still says no, and nothing has to be remembered before the next deploy.
#
# ── WHY BOTH POOLS ──────────────────────────────────────────────────────────
# The block is drawn by archive-poc (the front page) and its content is served
# by WordPress (the admin-ajax feed), and they run as different pool users. One
# pool alone gives a half-on state: an enabled front page fetching a 404, or a
# live endpoint nobody reads.
#
# Usage:  bash tools/preview/weekly-front-flip-dev2.sh [--dry-run] [--off]
set -u

WANT=1; DRY=0
for a in "$@"; do
  case "$a" in
    --off)     WANT=0 ;;
    --dry-run) DRY=1 ;;
    *) echo "unknown argument: $a" >&2; exit 2 ;;
  esac
done

POOLS=(/etc/php/8.3/fpm/pool.d/looth-dev.conf /etc/php/8.3/fpm/pool.d/archive-poc.conf)
LINE='env[LG_WEEKLY_FRONT] = "1"'
HOST=https://dev2.loothgroup.com

say() { printf '%s\n' "$*"; }

# ── 0. the code has to BE here. The flag is not the feature. ────────────────
SERVE=$HOME/loothplatformv2-clean
missing=0
for f in archive-poc/web/_render-weekly-issue.php \
         lg-weekly-digest/includes/class-lg-wd-front-feed.php \
         platform/config/weekly-front.php; do
  [ -f "$SERVE/$f" ] || { say "MISSING from the serving checkout: $f"; missing=1; }
done
if [ "$missing" = 1 ]; then
  say ""
  say "REFUSING TO FLIP. The serving checkout does not have the feature yet —"
  say "it is at $(git -C "$SERVE" log --oneline -1 2>/dev/null)."
  say "Merge to main and pull the serving checkout first. Setting the env against"
  say "code that is not there would look like a flip and do nothing, which is a"
  say "worse state than not having tried."
  exit 2
fi

# ── 1. edit the pools, idempotently ────────────────────────────────────────
for p in "${POOLS[@]}"; do
  [ -f "$p" ] || { say "pool not found: $p"; exit 2; }
  has=$(grep -c "^env\[LG_WEEKLY_FRONT\]" "$p" || true)
  if [ "$WANT" = 1 ]; then
    if [ "$has" != 0 ]; then say "already set: $p"; continue; fi
    say "$([ "$DRY" = 1 ] && echo 'WOULD add' || echo 'adding')  $LINE  ->  $p"
    [ "$DRY" = 1 ] || printf '%s\n' "$LINE" | sudo tee -a "$p" >/dev/null
  else
    if [ "$has" = 0 ]; then say "already absent: $p"; continue; fi
    say "$([ "$DRY" = 1 ] && echo 'WOULD remove' || echo 'removing')  LG_WEEKLY_FRONT  <-  $p"
    [ "$DRY" = 1 ] || sudo sed -i '/^env\[LG_WEEKLY_FRONT\]/d' "$p"
  fi
done

if [ "$DRY" = 1 ]; then say ""; say "dry run — nothing changed, nothing reloaded"; exit 0; fi

# ── 2. VALIDATE BEFORE RELOADING. A bad pool file takes every site down. ───
if ! sudo php-fpm8.3 -t 2>&1 | tail -2; then
  say "php-fpm config test FAILED — NOT reloading. Undo with --off."
  exit 1
fi
sudo systemctl reload php8.3-fpm || { say "reload failed"; exit 1; }
say "php8.3-fpm reloaded"

# ── 3. PROVE IT, both halves, rather than assuming ─────────────────────────
sleep 2
feed=$(curl -s -o /dev/null -w '%{http_code}' --resolve dev2.loothgroup.com:443:127.0.0.1 -k \
        "$HOST/wp-admin/admin-ajax.php?action=lg_wd_front_feed")
say "feed endpoint: HTTP $feed  $([ "$WANT" = 1 ] && echo '(want 200)' || echo '(want 404)')"

# The front page caches the feed in /tmp; a stale file would make this read the
# OLD state and call the flip a success or a failure for the wrong reason.
sudo rm -f /tmp/lg_weekly_front_*.json
blocks=$(curl -s --resolve dev2.loothgroup.com:443:127.0.0.1 -k "$HOST/" | grep -c 'data-row-id="weekly-issue"' || true)
say "front page anon: $blocks weekly block(s)  $([ "$WANT" = 1 ] && echo '(want 1)' || echo '(want 0)')"

if [ "$WANT" = 1 ] && { [ "$feed" != 200 ] || [ "$blocks" != 1 ]; }; then
  say "FLIP DID NOT TAKE — see above. --off puts it back."
  exit 1
fi
if [ "$WANT" = 0 ] && [ "$blocks" != 0 ]; then
  say "STILL ON after --off — see above."
  exit 1
fi
say "done."
