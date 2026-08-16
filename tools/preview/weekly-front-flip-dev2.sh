#!/usr/bin/env bash
# weekly-front-flip-dev2 — switch backlog 8 ON for dev2 ONLY, and prove it.
#
# WHY A SCRIPT FOR ONE SMALL FILE. It switches a member-facing surface on the
# box Ian browses while the fleet runs. This validates that the CODE is actually
# there before it claims anything, is idempotent, has --off, and PROVES the
# outcome on both halves rather than assuming it.
#
# ── WHY A .local.php FILE AND NOT AN FPM env[] ──────────────────────────────
# This script used to append `env[LG_WEEKLY_FRONT] = "1"` to two pool files.
# That mechanism is WRONG ON THIS BOX and was replaced 2026-08-16:
#
#   /etc/php/8.3/fpm/pool.d/looth-dev.conf   -> ~/loothplatformv2-clean/platform/fpm/dev2/looth-dev.conf
#   /etc/php/8.3/fpm/pool.d/archive-poc.conf -> ~/loothplatformv2-clean/platform/fpm/dev2/archive-poc.conf
#
# The pool files are SYMLINKS INTO THE SERVING CHECKOUT, so a "dev2-only" env
# flip modifies two TRACKED files in the checkout every deploy pulls, and a
# later `git pull --ff-only` can refuse. It cannot be patched around either —
# env[] must live in its own pool section, and two .conf files cannot define one
# pool. (Checked while writing this: the serving checkout was ALREADY dirty in
# looth-dev.conf, left by an earlier flip/unflip cycle. Reported, not fixed here.)
#
# platform/config/weekly-front.local.php is the box-local switch instead — the
# same pattern keeper landed for back-pill at b3bbbf9 and compose uses. It is
# gitignored, read AFTER the tracked default and BEFORE the env overrides, and
# LIVE IS PROTECTED BY THE FILE BEING ABSENT rather than by a check in the code.
#
# ⚠️ ORDERING: THE READER MUST BE ON THE BOX BEFORE THE FILE IS PLACED. Reversed,
# the file sits there inert and the flip reads as "shipped and broken" — that is
# exactly how compose went dark on 8/16. Step 0 enforces it and refuses.
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

SERVE=$HOME/loothplatformv2-clean
LOCAL=$SERVE/platform/config/weekly-front.local.php
HOST=https://dev2.loothgroup.com

say() { printf '%s\n' "$*"; }

# ── 0. the code has to BE here. The flag is not the feature. ────────────────
missing=0
for f in archive-poc/web/_render-weekly-issue.php \
         lg-weekly-digest/includes/class-lg-wd-front-feed.php \
         platform/config/weekly-front.php; do
  [ -f "$SERVE/$f" ] || { say "MISSING from the serving checkout: $f"; missing=1; }
done
# The READER is a separate question from the feature files: this file could be
# present from an older commit that predates the .local.php override, in which
# case placing the file switches on exactly nothing. Assert the reader itself.
for f in archive-poc/web/index.php lg-weekly-digest/includes/class-lg-wd-front-feed.php; do
  if [ -f "$SERVE/$f" ] && ! grep -q 'weekly-front\.local\.php' "$SERVE/$f"; then
    say "READER NOT PRESENT in $f — it does not know about weekly-front.local.php"
    missing=1
  fi
done
if [ "$missing" = 1 ]; then
  say ""
  say "REFUSING TO FLIP. The serving checkout is at $(git -C "$SERVE" log --oneline -1 2>/dev/null)."
  say "Merge to main and pull the serving checkout FIRST. Placing the override"
  say "against code that cannot read it would look like a flip and do nothing,"
  say "which is a worse state than not having tried."
  exit 2
fi

# ── 1. place or remove the override, idempotently ──────────────────────────
if [ "$WANT" = 1 ]; then
  if [ -f "$LOCAL" ] && grep -q "'enabled' => true" "$LOCAL"; then
    say "already ON: $LOCAL"
  else
    say "$([ "$DRY" = 1 ] && echo 'WOULD write' || echo 'writing')  enabled => true  ->  $LOCAL"
    [ "$DRY" = 1 ] || cat > "$LOCAL" <<'PHPEOF'
<?php
/**
 * weekly-front — BOX-LOCAL OVERRIDE, dev2 only. NOT TRACKED (gitignored).
 *
 * Written by tools/preview/weekly-front-flip-dev2.sh so Ian can look at backlog
 * 8 on the real front page. The tracked default in weekly-front.php stays
 * false, so live is unaffected: it is protected by this file being ABSENT.
 *
 * Remove with: bash tools/preview/weekly-front-flip-dev2.sh --off
 */
return array( 'enabled' => true );
PHPEOF
  fi
else
  if [ ! -f "$LOCAL" ]; then say "already OFF (no override file)"; else
    say "$([ "$DRY" = 1 ] && echo 'WOULD remove' || echo 'removing')  $LOCAL"
    [ "$DRY" = 1 ] || rm -f "$LOCAL"
  fi
fi

if [ "$DRY" = 1 ]; then say ""; say "dry run — nothing changed, nothing reloaded"; exit 0; fi

# ── 2. make the change VISIBLE this second, not in two minutes ─────────────
# PHP's realpath cache (realpath_cache_ttl, 120s by default) can keep answering
# "no such file" for a file that now exists — so without this the proof step
# below would read the OLD state and report a perfectly good flip as a failure.
sudo systemctl reload php8.3-fpm || { say "fpm reload failed"; exit 1; }
say "php8.3-fpm reloaded"

# ── 3. PROVE IT, both halves, rather than assuming ─────────────────────────
# Both halves matter because they run as DIFFERENT POOL USERS: the front page is
# archive-poc, the feed is looth-dev. One alone is a half-on state.
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

# ── 4. the serving checkout must be no dirtier than we found it ────────────
# The whole reason this script stopped using env[] is that the old mechanism
# dirtied tracked files in the serve. Prove the new one does not.
dirty=$(git -C "$SERVE" status --porcelain -- platform/ | grep -v '^?? platform/config/weekly-front.local.php' || true)
if [ -n "$dirty" ]; then
  say ""
  say "⚠️ serving checkout has modifications under platform/ (NOT necessarily ours):"
  say "$dirty"
fi
say "done."
