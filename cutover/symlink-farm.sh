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
APPLY=0; FILTERS=()
for a in "$@"; do [ "$a" = "--apply" ] && APPLY=1 || FILTERS+=("$a"); done
echo "REPO=$REPO  WP=$WP  MODE=$([ $APPLY = 1 ] && echo APPLY || echo DRY-RUN)  filters=${FILTERS[*]:-<all>}"

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

echo "--- FPM pools (projects/platform/fpm -> pool.d) [reload after] ---"
if $SUDO test -d "$REPO/platform/fpm"; then
  for f in $($SUDO ls "$REPO/platform/fpm"/*.conf 2>/dev/null | xargs -n1 basename); do
    link "$f" "$REPO/platform/fpm/$f" "/etc/php/8.3/fpm/pool.d/$f"
  done
fi

echo "--- webroot loose assets (projects/platform/webroot/* -> docroot) [buck-owned] ---"
if $SUDO test -d "$REPO/platform/webroot"; then
  for f in $($SUDO ls "$REPO/platform/webroot" 2>/dev/null); do
    link "$f" "$REPO/platform/webroot/$f" "$WP/$f"
  done
fi

echo "=== done ($([ $APPLY = 1 ] && echo APPLIED || echo dry-run)). After config changes: nginx -t && reload; systemctl reload php8.3-fpm ==="
