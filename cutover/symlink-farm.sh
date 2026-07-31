#!/usr/bin/env bash
# SYMLINK FARM — makes the live box git-native: each live path becomes a symlink
# into the monorepo, so `git pull` = deployed. Idempotent + DRY-RUN by default.
# Run ON THE TARGET BOX (dev pilot or the cut box).
#
#   bash symlink-farm.sh                 # DRY RUN, all entries
#   bash symlink-farm.sh lg-shared       # DRY RUN, only matching entries
#   bash symlink-farm.sh --apply lg-shared   # APPLY, only lg-shared (the pilot)
#   REPO=/home/ubuntu/projects WP=/var/www/dev bash symlink-farm.sh --apply
#
# Behaviour per target:
#   - repo source missing  → SKIP (not captured/folded yet) — safe to re-run later
#   - already correct symlink → OK, skip
#   - real file/dir present → move aside to <target>.pre-symlink-<ts> (= rollback),
#                             then symlink
#   - wrong symlink        → repoint
set -uo pipefail

REPO="${REPO:-/home/ubuntu/projects}"
WP="${WP:-/var/www/dev}"                 # cut box: set to the live docroot
SUDO="${SUDO:-sudo}"
TS="$(date +%Y%m%d-%H%M%S 2>/dev/null || echo cut)"
APPLY=0; FPM_BOX=""; FILTERS=()
while [ $# -gt 0 ]; do
  case "$1" in
    --apply)   APPLY=1; shift ;;
    --fpm-box) FPM_BOX="${2:-}"; shift 2 ;;
    *)         FILTERS+=("$1"); shift ;;
  esac
done
echo "REPO=$REPO  WP=$WP  MODE=$([ $APPLY = 1 ] && echo APPLY || echo DRY-RUN)  filters=${FILTERS[*]:-<all>}"

# ═══ BOX DETECTION — evidence, never a flag ══════════════════════════════════════
# ⚠️ 2026-07-31: run unfiltered on LIVE, this script REPOINTED ALL SEVEN FPM POOLS
# from platform/fpm/live/*.conf to platform/fpm/*.conf — the dev variants, which set
# pm.max_children = 2 instead of 6-12 and drop LG_*_PUBLIC_HOST. It did no damage
# ONLY because php-fpm was never reloaded; the very next reload would have cut live
# to two workers per pool. Ian repaired all seven by hand.
#
# The script was written for the CUT BOX, where the top-level platform/fpm/*.conf
# WAS canonical. It is canonical on no serving box any more: live uses
# platform/fpm/live/ and dev2 uses platform/fpm/dev2/. Nothing in here said so.
#
# The box is identified from what the box ITSELF already says — the variant its pools
# are currently linked to — because a flag can be passed wrongly and a hostname can
# change. If the evidence says live and the operator says dev2, the operator loses.
detect_box() {
  local t seen_live=0 seen_dev2=0 f
  for f in $($SUDO ls -1 /etc/php/8.3/fpm/pool.d/ 2>/dev/null); do
    case "$f" in *.bak*|*.pre-symlink-*|*.dev-preflip|*.live-posture) continue;; esac
    t="$($SUDO readlink "/etc/php/8.3/fpm/pool.d/$f" 2>/dev/null)" || continue
    case "$t" in
      */platform/fpm/live/*) seen_live=1 ;;
      */platform/fpm/dev2/*) seen_dev2=1 ;;
    esac
  done
  if [ $seen_live = 1 ] && [ $seen_dev2 = 1 ]; then echo mixed; return; fi
  if [ $seen_live = 1 ]; then echo live; return; fi
  if [ $seen_dev2 = 1 ]; then echo dev2; return; fi
  echo unknown
}
DETECTED="$(detect_box)"
echo "DETECTED BOX=$DETECTED (from the FPM pool symlinks already on this machine)"

want(){ # name -> is it in the filter set (or no filter)?
  [ ${#FILTERS[@]} -eq 0 ] && return 0
  for f in "${FILTERS[@]}"; do [[ "$1" == *"$f"* ]] && return 0; done; return 1; }

link(){ # name  repo_src  live_target
  local name="$1" src="$2" tgt="$3"
  want "$name" || return 0
  if ! $SUDO test -e "$src"; then echo "  SKIP   $name — repo source absent ($src)"; return 0; fi
  if $SUDO test -L "$tgt"; then
    local cur; cur="$($SUDO readlink "$tgt")"
    if [ "$cur" = "$src" ]; then echo "  OK     $name — already linked"; return 0; fi
    echo "  REPOINT $name — $tgt: $cur -> $src"
    [ $APPLY = 1 ] && $SUDO ln -sfn "$src" "$tgt"
    return 0
  fi
  if $SUDO test -e "$tgt"; then
    # DRIFT GUARD: only convert a real copy to a symlink when repo ALREADY matches
    # live (i.e. it's been captured). Otherwise linking would silently revert live
    # to a stale repo copy. .bak/.git excluded so rollback files don't trip it.
    local same=1
    if $SUDO test -d "$src"; then
      $SUDO diff -rq --exclude='*.bak*' --exclude='.git' "$src" "$tgt" >/dev/null 2>&1 || same=0
    else
      $SUDO diff -q "$src" "$tgt" >/dev/null 2>&1 || same=0
    fi
    if [ $same = 0 ]; then
      echo "  DRIFT  $name — repo differs from live; CAPTURE repo first, NOT linking ($tgt)"
      return 0
    fi
    echo "  BACKUP+LINK $name — mv $tgt -> $tgt.pre-symlink-$TS ; ln -s $src"
    if [ $APPLY = 1 ]; then $SUDO mv "$tgt" "$tgt.pre-symlink-$TS" && $SUDO ln -s "$src" "$tgt"; fi
  else
    echo "  LINK   $name — ln -s $src $tgt"
    [ $APPLY = 1 ] && $SUDO ln -s "$src" "$tgt"
  fi
}

PLUG="$WP/wp-content/plugins"
MU="$WP/wp-content/mu-plugins"

echo "--- WP plugins (projects/<name> -> wp-content/plugins) ---"
for p in lg-layout-v2 lg-legacy-import lg-snippets lg-patreon-stripe-poller \
         lg-apps lg-anonymous-authors lg-recent-posts-widget lg-weekly-digest \
         event-reminder-and-cleaner; do
  link "$p" "$REPO/$p" "$PLUG/$p"
done

echo "--- mu-plugins (FLAT .php symlinks; excludes 3rd-party/retired/temp) ---"
EXCLUDE_MU="lg-user-audit.php lg-membership-chrome.php buddyboss-performance-api.php burst_rest_api_optimizer.php"
if $SUDO test -d "$REPO/platform/mu-plugins"; then
  for f in $($SUDO ls "$REPO/platform/mu-plugins"/*.php 2>/dev/null | xargs -n1 basename); do
    case " $EXCLUDE_MU " in *" $f "*) echo "  EXCLUDE $f"; continue;; esac
    link "$f" "$REPO/platform/mu-plugins/$f" "$MU/$f"
  done
fi

echo "--- standalone apps + lg-shared + folded svcs (projects/<name> -> /srv/<name>) [cut box] ---"
# NOTE: lg-stripe-billing + lg-push need box-local vendor/ (composer install) + .env
# (provisioned) INSIDE the repo dir; drift-guard SKIPs on dev (those box files differ).
# NOT farmed: lg-sudo-queue (dev-only infra), profile-app-media (user-media DATA, rsync).
for a in archive-poc bb-mirror profile-app events lg-shared lg-push lg-stripe-billing; do
  link "$a" "$REPO/$a" "/srv/$a"
done

echo "--- nginx snippets (projects/platform/nginx -> /etc/nginx/snippets) [reload after] ---"
if $SUDO test -d "$REPO/platform/nginx"; then
  for f in $($SUDO ls "$REPO/platform/nginx"/*.conf 2>/dev/null | xargs -n1 basename); do
    link "$f" "$REPO/platform/nginx/$f" "/etc/nginx/snippets/$f"
  done
fi

echo "--- FPM pools [GUARDED — see the box-detection block at the top] ---"
# Three refusals, loudest first. Silence here is not permission: if this section
# declines, it says so and says why, because "it printed nothing" is how the 7-pool
# repoint went unnoticed in the first place.
if [ "$DETECTED" = mixed ]; then
  echo "  ❌ REFUSING — this box has pools linked to BOTH live/ and dev2/ variants."
  echo "     That is already broken, and guessing which is right could halve the"
  echo "     worker count on a serving box. Fix by hand, then re-run."
elif [ "$DETECTED" = live ] || [ "$DETECTED" = dev2 ]; then
  if [ "$FPM_BOX" != "$DETECTED" ]; then
    echo "  ⛔ REFUSING to touch FPM pools on a SERVING box ($DETECTED)."
    echo ""
    echo "     On 2026-07-31 this section repointed all seven LIVE pools to the dev"
    echo "     variants (pm.max_children 2, no LG_*_PUBLIC_HOST). It was caught only"
    echo "     because php-fpm was never reloaded."
    echo ""
    echo "     Pools are per-box and already correct here. If you genuinely mean to"
    echo "     re-link them, say so explicitly AND match the detected box:"
    echo "         bash cutover/symlink-farm.sh --apply --fpm-box $DETECTED"
    echo "     Everything else in this script has already run; only pools were skipped."
  else
    echo "  ✔ --fpm-box $FPM_BOX matches the detected box; linking from platform/fpm/$FPM_BOX/"
    if $SUDO test -d "$REPO/platform/fpm/$FPM_BOX"; then
      for f in $($SUDO ls "$REPO/platform/fpm/$FPM_BOX"/*.conf 2>/dev/null | xargs -n1 basename); do
        link "$f" "$REPO/platform/fpm/$FPM_BOX/$f" "/etc/php/8.3/fpm/pool.d/$f"
      done
    else
      echo "  SKIP — $REPO/platform/fpm/$FPM_BOX does not exist"
    fi
  fi
else
  # unknown = the cut box, which is what this script was originally written for and
  # where the top-level variants ARE canonical.
  echo "  box not identified as live/dev2 — treating as the CUT BOX, where the"
  echo "  top-level platform/fpm/*.conf variants are canonical."
  if $SUDO test -d "$REPO/platform/fpm"; then
    for f in $($SUDO ls "$REPO/platform/fpm"/*.conf 2>/dev/null | xargs -n1 basename); do
      link "$f" "$REPO/platform/fpm/$f" "/etc/php/8.3/fpm/pool.d/$f"
    done
  fi
fi

echo "--- webroot loose assets (projects/platform/webroot/* -> docroot) [buck-owned] ---"
if $SUDO test -d "$REPO/platform/webroot"; then
  for f in $($SUDO ls "$REPO/platform/webroot" 2>/dev/null); do
    link "$f" "$REPO/platform/webroot/$f" "$WP/$f"
  done
fi

echo "=== done ($([ $APPLY = 1 ] && echo APPLIED || echo dry-run)). After config changes: nginx -t && reload; systemctl reload php8.3-fpm ==="
