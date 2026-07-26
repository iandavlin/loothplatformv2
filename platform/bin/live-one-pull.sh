#!/usr/bin/env bash
# live-one-pull.sh — bring LIVE to the single-pull deploy end state
# (deploy-one-pull 2026-07-26). RUN BY IAN ON LIVE, reviewed by keeper first.
# dev2 proved this shape; this script never trusts that proof — it re-audits every
# fact on the box it runs on and refuses any step whose precondition fails.
#
# TWO MODES, because the live vhost capture needs a commit in between:
#
#   sudo bash platform/bin/live-one-pull.sh audit
#       READ-ONLY. Inventories every live-only defect (webroot copies, lg-shared
#       site-header.php copy, lg-snippets 86.php copy, untracked vhost, FPM/opcache
#       posture), md5-compares everything against the repo, and CAPTURES the vhost +
#       FPM pool confs to /home/ubuntu/deploy-backups/live-audit-<ts>/ for keeper to
#       commit (platform/nginx/loothgroup.com.conf — keeper applies the same edits
#       dev2 got: bare /pwa.js sub_filter + pwa-loader location — and
#       platform/fpm/live/). Nothing on the box changes in this mode.
#
#   sudo bash platform/bin/live-one-pull.sh apply
#       Runs AFTER keeper's capture commit landed in main and `git pull` ran.
#       Converts copies to symlinks (md5-gated per file: ANY mismatch = skip + loud
#       report, never clobber), flips the vhost symlink to the tracked file ONLY if
#       byte-identical to what live serves today, installs /usr/local/bin/lg-deploy,
#       writes an exact ROLLBACK.sh as it goes, then proves with nginx -t + FULL
#       restart of nginx AND php8.3-fpm + smokes (reloads hide boot failures —
#       2026-07-26 outage lesson).
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
MODE="${1:-audit}"

REPO="/home/ubuntu/loothplatformv2-clean"
LG_WP_PATH="$(sed -n 's/^LG_WP_PATH=//p' /etc/looth/env 2>/dev/null | tr -d '"')"
[ -n "$LG_WP_PATH" ] || { echo "FATAL: LG_WP_PATH missing from /etc/looth/env — will not guess the docroot" >&2; exit 1; }
LG_ENV="$(sed -n 's/^LG_ENV=//p' /etc/looth/env 2>/dev/null | tr -d '"')"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="/home/ubuntu/deploy-backups/live-${MODE}-${STAMP}"
mkdir -p "$OUT"
RB="$OUT/ROLLBACK.sh"; echo "#!/usr/bin/env bash" > "$RB"; echo "set -x" >> "$RB"; chmod +x "$RB"
note() { echo "$*" | tee -a "$OUT/REPORT.txt"; }

note "== live-one-pull $MODE on $(hostname) LG_ENV=$LG_ENV docroot=$LG_WP_PATH repo=$REPO"

# ---- shared preflight (both modes) ------------------------------------------
BR="$(git -C "$REPO" rev-parse --abbrev-ref HEAD)"; HEADC="$(git -C "$REPO" rev-parse --short HEAD)"
DIRTY="$(git -C "$REPO" status --porcelain | head -5)"
note "checkout: $BR @ $HEADC dirty='$DIRTY'"
[ "$BR" = "main" ] || { note "FATAL: serving checkout not on main — stop, tell keeper"; exit 1; }
[ -z "$DIRTY" ] || { note "FATAL: serving checkout dirty — stop, tell keeper"; exit 1; }

# vhost: where does nginx actually serve loothgroup.com from?
VH_EN="/etc/nginx/sites-enabled/loothgroup.com.conf"
VH_REAL="$(readlink -f "$VH_EN" 2>/dev/null || true)"
note "vhost: sites-enabled -> ${VH_REAL:-MISSING}"
TRACKED_VH="$REPO/platform/nginx/loothgroup.com.conf"

# opcache posture decides whether live deploys ever need an fpm reload
note "opcache: $(php-fpm8.3 -i 2>/dev/null | grep -E '^opcache\.(validate_timestamps|revalidate_freq)' | tr '\n' ' ' || echo 'UNREADABLE')"

if [ "$MODE" = "audit" ]; then
    note "== A1. webroot copies vs repo webroot/ (md5)"
    same=0; diffn=0; missing=0
    for f in "$REPO/webroot"/*; do
        n="$(basename "$f")"; [ -f "$f" ] || continue
        case "$n" in README.md|install-symlinks.sh|.gitignore) continue ;; esac
        t="$LG_WP_PATH/$n"
        if [ -L "$t" ]; then note "  SYMLINK $n -> $(readlink "$t")";
        elif [ -f "$t" ]; then
            if cmp -s "$t" "$f"; then same=$((same+1)); else diffn=$((diffn+1)); note "  DIFF    $n (box $(md5sum "$t" | cut -c1-8) vs repo $(md5sum "$f" | cut -c1-8)) — REVERSE DRIFT? capture before apply"; fi
        else missing=$((missing+1)); note "  ABSENT  $n"; fi
    done
    note "  summary: same=$same diff=$diffn absent=$missing (apply converts only 'same')"

    note "== A2. /srv/lg-shared shape (live-only site-header.php copy?)"
    ls -la /srv/lg-shared/ | tee -a "$OUT/REPORT.txt" >/dev/null
    for f in /srv/lg-shared/*; do
        n="$(basename "$f")"; [ -f "$f" ] && [ ! -L "$f" ] || continue
        cmp -s "$f" "$REPO/lg-shared/$n" && note "  COPY-SAME $n (convertible)" || note "  COPY-DIFF $n — capture to repo first"
    done

    note "== A3. lg-snippets 86.php shape"
    SNIP="$LG_WP_PATH/wp-content/plugins/lg-snippets"
    if [ -L "$SNIP" ]; then note "  whole-dir symlink already: $(readlink "$SNIP")"
    else
        for f in "$SNIP"/snippets/*.php; do
            n="$(basename "$f")"; [ -L "$f" ] && continue
            cmp -s "$f" "$REPO/lg-snippets/snippets/$n" && note "  COPY-SAME snippets/$n" || note "  COPY-DIFF snippets/$n — capture first"
        done
    fi

    note "== A4. vhost capture (L-1: it was never tracked)"
    if [ -n "$VH_REAL" ] && [ -f "$VH_REAL" ]; then
        cp -a "$VH_REAL" "$OUT/loothgroup.com.conf.captured"
        if git -C "$REPO" ls-files --error-unmatch "platform/nginx/loothgroup.com.conf" >/dev/null 2>&1; then
            cmp -s "$VH_REAL" "$TRACKED_VH" && note "  vhost already tracked AND identical" || note "  vhost tracked but DIFFERS from serving copy — diff for keeper in $OUT"
        else
            note "  CAPTURED to $OUT/loothgroup.com.conf.captured — keeper: commit as platform/nginx/loothgroup.com.conf WITH the dev2-style pwa edits (bare /pwa.js sub_filter + pwa-loader.php location), then run apply"
        fi
        grep -n "pwa.js?v=" "$VH_REAL" | tee -a "$OUT/REPORT.txt" || note "  (no hand-carried pwa ?v= found in vhost)"
    else
        note "  WARN: could not resolve the serving vhost file"
    fi

    note "== A5. FPM pool capture -> commit as platform/fpm/live/"
    mkdir -p "$OUT/fpm-live"
    cp -a /etc/php/8.3/fpm/pool.d/*.conf "$OUT/fpm-live/" 2>/dev/null || true
    cp -a /etc/php/8.3/fpm/conf.d/99-lg-tuning.ini "$OUT/fpm-live/" 2>/dev/null || true
    note "  captured $(ls "$OUT/fpm-live" | wc -l) files to $OUT/fpm-live"

    note "== A6. mu-plugins real files (should be none per keeper's evidence)"
    find "$LG_WP_PATH/wp-content/mu-plugins" -maxdepth 1 -type f -name "*.php" | tee -a "$OUT/REPORT.txt" || true

    note "== AUDIT DONE — nothing changed. Send $OUT/REPORT.txt + captures to keeper."
    exit 0
fi

[ "$MODE" = "apply" ] || { echo "usage: $0 audit|apply" >&2; exit 1; }

note "== B1. webroot layer -> symlinks (md5-gated; skips are EXPECTED for any file keeper has not merged)"
bash "$REPO/webroot/install-symlinks.sh" "$LG_WP_PATH" | tee -a "$OUT/REPORT.txt" || note "  (installer exited $? — skips reported above)"

note "== B2. config layer (vhost if identical, FPM live posture, mu-plugin strays)"
bash "$REPO/platform/bin/install-config-symlinks.sh" live | tee -a "$OUT/REPORT.txt" || note "  (config installer exited $? — skips reported above)"

note "== B3. /srv/lg-shared -> whole-dir symlink (the site-header.php copy dies here)"
if [ -L /srv/lg-shared ]; then
    note "  already a symlink: $(readlink /srv/lg-shared)"
else
    ok=1
    for f in /srv/lg-shared/*; do
        n="$(basename "$f")"
        if [ -L "$f" ]; then [ "$(readlink -f "$f")" = "$(readlink -f "$REPO/lg-shared/$n")" ] || { ok=0; note "  BLOCK: $n symlinks elsewhere"; }
        elif [ -f "$f" ]; then cmp -s "$f" "$REPO/lg-shared/$n" || { ok=0; note "  BLOCK: $n differs from repo — capture first"; }
        elif [ -d "$f" ]; then diff -rq "$f" "$REPO/lg-shared/$n" >/dev/null 2>&1 || { ok=0; note "  BLOCK: dir $n differs"; }
        fi
    done
    if [ "$ok" = 1 ]; then
        mv /srv/lg-shared "$OUT/lg-shared.predir"
        ln -s "$REPO/lg-shared" /srv/lg-shared
        echo "rm /srv/lg-shared && mv $OUT/lg-shared.predir /srv/lg-shared" >> "$RB"
        note "  CONVERTED /srv/lg-shared (rollback in ROLLBACK.sh)"
    else
        note "  SKIPPED /srv/lg-shared — blocks above must clear first"
    fi
fi

note "== B4. lg-snippets -> whole-dir symlink (the 86.php copy dies here)"
SNIP="$LG_WP_PATH/wp-content/plugins/lg-snippets"
if [ -L "$SNIP" ]; then note "  already a symlink"
else
    if diff -rq "$SNIP" "$REPO/lg-snippets" >/dev/null 2>&1; then
        mv "$SNIP" "$OUT/lg-snippets.predir"
        ln -s "$REPO/lg-snippets" "$SNIP"
        echo "rm $SNIP && mv $OUT/lg-snippets.predir $SNIP" >> "$RB"
        note "  CONVERTED lg-snippets (rollback in ROLLBACK.sh)"
    else
        note "  SKIPPED lg-snippets — differs from repo:"; { diff -rq "$SNIP" "$REPO/lg-snippets" 2>&1 || true; } | head -8 | tee -a "$OUT/REPORT.txt"
    fi
fi

note "== B5. the one-line deploy"
ln -sfn "$REPO/platform/bin/lg-deploy" /usr/local/bin/lg-deploy
echo "rm /usr/local/bin/lg-deploy" >> "$RB"
note "  /usr/local/bin/lg-deploy installed — the deploy is now: lg-deploy  (routine case = git pull only)"

note "== B6. PROOF: nginx -t + FULL restarts + smokes (a reload proves nothing)"
nginx -t
systemctl restart nginx
systemctl restart php8.3-fpm
sleep 1
systemctl is-active nginx php8.3-fpm | tee -a "$OUT/REPORT.txt"
HOST="$(sed -n 's/^LG_PUBLIC_HOST=//p' /etc/looth/env | tr -d '"')"; HOST="${HOST:-loothgroup.com}"
for p in / /hub/ /pwa.js ; do
    code=$(curl -s -o /dev/null -w "%{http_code}" -k --resolve "$HOST:443:127.0.0.1" "https://$HOST$p")
    note "  smoke $code $p"
done
note "== APPLY DONE. Report + rollback: $OUT (send REPORT.txt to keeper)"
