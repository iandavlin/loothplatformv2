#!/usr/bin/env bash
# install-config-symlinks.sh — one-time (idempotent) bootstrap that points the box's
# serving CONFIG at the repo checkout, so config changes ride `git pull` too
# (deploy-one-pull 2026-07-26). Covers what webroot/install-symlinks.sh does not:
#
#   1. nginx main vhost   /etc/nginx/sites-available/<vhost>.conf -> platform/nginx/
#   2. FPM posture        pool.d/<pool>.conf + conf.d/99-lg-tuning.ini -> platform/fpm/<box>/
#   3. mu-plugin copies   the handful of real-file mu-plugins whose source is tracked
#
#   sudo ./install-config-symlinks.sh [dev2|live]     (default: dev2)
#
# Safety model (same as install-symlinks.sh): converts only on byte-identical
# content (mismatch = SKIP + loud report), moves originals to
# /home/ubuntu/deploy-backups/, prints the per-file rollback line. Does NOT touch:
# box-local secrets/per-env (loothdev-tokens.conf, armed loothdev-auth.conf,
# wp-config.php), stock www.conf, lg-billing-dev.conf (separate app tree).
#
# After it runs: sudo nginx -t && sudo systemctl restart nginx &&
#                sudo systemctl restart php8.3-fpm   (restart, not reload — proof
#                standard from the 2026-07-26 outage: reloads hide boot failures).
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }

BOX="${1:-dev2}"
REPO="/home/ubuntu/loothplatformv2-clean"
# WP path comes from the established per-env file
LG_WP_PATH="$(sed -n 's/^LG_WP_PATH=//p' /etc/looth/env 2>/dev/null | tr -d '"')"
LG_WP_PATH="${LG_WP_PATH:-/var/www/dev}"
case "$BOX" in dev2|live) : ;; *) echo "unknown box '$BOX'" >&2; exit 1 ;; esac

# Resolve the enabled vhost by what actually serves LG_PUBLIC_HOST — filenames lie
# (live's serving vhost is still NAMED dev2.loothgroup.com.conf, promotion leftover).
PUB="$(sed -n 's/^LG_PUBLIC_HOST=//p' /etc/looth/env 2>/dev/null | tr -d '"')"
[ -n "$PUB" ] || { echo "FATAL: LG_PUBLIC_HOST missing from /etc/looth/env" >&2; exit 1; }
# exact server_name TOKEN equality (regex boundaries false-matched buck-dev2 vs dev2)
vh_serves() { awk -v H="$1" '/^[[:space:]]*server_name[[:space:]]/ { for(i=2;i<=NF;i++){ n=$i; sub(/;$/,"",n); if(n==H){ found=1 } } } END{ exit !found }' "$2" 2>/dev/null; }
VH_EN=""
for f in /etc/nginx/sites-enabled/*; do
    [ -e "$f" ] || continue
    vh_serves "$PUB" "$f" && VH_EN="$VH_EN $f"
done
# shellcheck disable=SC2086
set -- $VH_EN
[ "$#" -eq 1 ] || { echo "FATAL: expected exactly ONE enabled vhost serving $PUB, found $#:$VH_EN" >&2; exit 1; }
VH_EN="$1"
# the box-level vhost file is one link-hop from sites-enabled (or the file itself)
if [ -L "$VH_EN" ]; then VH_SA="$(readlink "$VH_EN")"; case "$VH_SA" in /*) : ;; *) VH_SA="/etc/nginx/sites-enabled/$VH_SA" ;; esac; else VH_SA="$VH_EN"; fi
VHOST="$(basename "$VH_SA")"

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/home/ubuntu/deploy-backups/config-symlinks-$STAMP"
skipped=0 converted=0 repaired=0
bk() { mkdir -p "$BACKUP"; }

link_one() {  # name, live path, repo target — same-content-or-skip conversion
    local name="$1" t="$2" target="$3"
    [ -e "$target" ] || { echo "SKIPPED   $name — repo target missing: $target"; skipped=$((skipped+1)); return 0; }
    if [ -L "$t" ]; then
        [ "$(readlink -f "$t")" = "$(readlink -f "$target")" ] && return 0
        bk; echo "$t was -> $(readlink "$t")" >> "$BACKUP/RELINKED.txt"
        ln -sfn "$target" "$t"; repaired=$((repaired+1))
        echo "REPAIRED  $name (old target recorded in $BACKUP/RELINKED.txt)"
    elif [ -e "$t" ]; then
        if cmp -s "$t" "$target"; then
            bk; mv "$t" "$BACKUP/${name//\//_}"; ln -s "$target" "$t"; converted=$((converted+1))
            echo "CONVERTED $name  (rollback: mv $BACKUP/${name//\//_} $t)"
        else
            skipped=$((skipped+1))
            echo "SKIPPED   $name — box file differs from repo copy:"
            { diff -u "$target" "$t" 2>&1 || true; } | head -15 | sed 's/^/            /'
        fi
    else
        ln -s "$target" "$t"; converted=$((converted+1))
        echo "LINKED    $name (was absent)"
    fi
}

echo "== nginx main vhost ($VHOST, serving $PUB)"
VH_FINAL="$(readlink -f "$VH_EN")"
case "$VH_FINAL" in
    "$REPO"/platform/nginx/*)
        echo "OK        vhost already serves tracked $(basename "$VH_FINAL") from the checkout — pull deploys it" ;;
    *)
        if [ -e "$REPO/platform/nginx/$VHOST" ]; then
            link_one "sites-available/$VHOST" "$VH_SA" "$REPO/platform/nginx/$VHOST"
        else
            skipped=$((skipped+1))
            echo "SKIPPED   vhost — no tracked platform/nginx/$VHOST to link against (capture + commit it first)"
        fi ;;
esac

echo "== FPM posture (platform/fpm/$BOX)"
if [ -d "$REPO/platform/fpm/$BOX" ]; then
    for p in "$REPO/platform/fpm/$BOX"/*.conf; do
        n="$(basename "$p")"
        link_one "pool.d/$n" "/etc/php/8.3/fpm/pool.d/$n" "$p"
    done
    [ -f "$REPO/platform/fpm/$BOX/99-lg-tuning.ini" ] && \
        link_one "conf.d/99-lg-tuning.ini" "/etc/php/8.3/fpm/conf.d/99-lg-tuning.ini" "$REPO/platform/fpm/$BOX/99-lg-tuning.ini"
else
    echo "SKIPPED   no tracked FPM posture for '$BOX' (platform/fpm/$BOX missing)"
fi

echo "== mu-plugin real-file copies whose source is tracked"
MU="$LG_WP_PATH/wp-content/mu-plugins"
declare -A MU_MAP=(
    [buddyboss-performance-api.php]="$REPO/platform/mu-plugins/buddyboss-performance-api.php"
    [burst_rest_api_optimizer.php]="$REPO/platform/mu-plugins/burst_rest_api_optimizer.php"
    [lg-logout.php]="$REPO/platform/mu-plugins/lg-logout.php"
    [lg-dev-mail-containment.php]="$REPO/platform/mu-plugins/lg-dev-mail-containment.php"
    [lg-patreon-stripe-poller.php]="$REPO/lg-patreon-stripe-poller.php"
)
if [ "$BOX" = "live" ]; then
    # Keeper ruling 2026-07-26: live's real-file mu-plugins are REPORT-ONLY this
    # pass (tracking the two that are ours is queued, not cutover-window work).
    echo "REPORT    live mu-plugins left untouched by ruling; real files present:"
    find "$MU" -maxdepth 1 -type f -name "*.php" 2>/dev/null | sed 's/^/            /' || true
else
    for n in "${!MU_MAP[@]}"; do
        # only convert files the box actually has (e.g. live has no dev-mail-containment)
        [ -e "$MU/$n" ] || [ -L "$MU/$n" ] || continue
        link_one "mu-plugins/$n" "$MU/$n" "${MU_MAP[$n]}"
    done
fi

echo "----"
echo "converted=$converted repaired=$repaired skipped=$skipped"
[ -d "$BACKUP" ] && echo "originals: $BACKUP"
echo "NOW RUN: sudo nginx -t && sudo systemctl restart nginx && sudo systemctl restart php8.3-fpm"
[ "$skipped" -gt 0 ] && exit 2
exit 0
