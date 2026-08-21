#!/usr/bin/env bash
# install-auto-ban.sh — #162 — arm (or disarm) the login-door blocklist on THIS box.
#
#   sudo tools/infra/install-auto-ban.sh            # arm
#   sudo tools/infra/install-auto-ban.sh --check    # report, change nothing
#   sudo tools/infra/install-auto-ban.sh --uninstall# disarm, remove every trace
#
# WHY THIS EXISTS AT ALL, when everything else here deploys by one pull: the
# three things it installs are the three kinds of file a pull cannot deliver.
# nginx config in /etc/nginx is a root-owned copy on a box whose vhost has
# already drifted from the repo; systemd units in /etc/systemd/system are
# deliberate copies (verified 2026-08-20, and lg-deploy only WARNS about drift);
# and /var/lib state has to exist and be owned by the web user before WordPress
# can write a byte. A pull changes none of them, so a merge arms nothing —
# which is the whole point. Live stays protected by absence until Ian runs this.
#
# ARMS AND DISARMS AS A SET. The doors snippet uses variables the maps file
# defines; either alone is a config nginx rejects. So both go in together, both
# come out together, and nothing is left behind on a failed test.
set -uo pipefail

MODE="arm"
case "${1:-}" in
    --check)     MODE="check" ;;
    --uninstall) MODE="uninstall" ;;
    "")          ;;
    *) echo "usage: $0 [--check|--uninstall]" >&2; exit 2 ;;
esac

# ─────────────────────────────────────────────────────────────────────────────
# REDIRECTABLE ROOT — so this script can be TESTED, which is the point.
#
# This is the only piece of #162 a human runs on LIVE, by hand, with sudo, and
# until now it was the only piece with no coverage at all: the gate proved the
# templates and the renderer, and then trusted the script that installs them.
# Untested install scripts are how a "deploy step" turns out to have been broken
# since the day it was written.
#
# So every absolute path this touches goes through $ROOT, defaulting to "" —
# with nothing set, every path below is byte-for-byte the production path it
# always was, and gate 84 §H8 asserts exactly that so these hooks can never
# quietly become the shipped behaviour. With $LG_AB_ROOT set, the whole install
# happens inside a temp dir owned by the caller: no root, no /etc, no systemd,
# no nginx reload, and the gate can then break it on purpose.
#
# Root is required for a real install and NOT for a redirected one — needing sudo
# to run the tests would mean the tests never run.
ROOT="${LG_AB_ROOT:-}"
NGINX_TEST_CMD="${LG_AB_NGINX_TEST-nginx -t}"
NGINX_RELOAD_CMD="${LG_AB_NGINX_RELOAD-systemctl reload nginx}"
SYSTEMCTL="${LG_AB_SYSTEMCTL-systemctl}"
SITES_ENABLED="${LG_AB_SITES_ENABLED:-$ROOT/etc/nginx/sites-enabled}"
BANPAGE="${LG_AB_BANPAGE:-$ROOT/srv/lg-shared/errors/login-blocked.html}"

# An empty command is a DECISION (do nothing), not an absence — the same rule the
# renderer had to learn when an un-disableable `nginx -t` made it roll its own
# work back. `${VAR-default}` and not `${VAR:-default}`, deliberately: the colon
# form treats "set to empty" as unset and there would be no way to switch these
# off.
runcmd() { [ -n "${1:-}" ] || return 0; eval "$1"; }

if [ -z "$ROOT" ]; then
    [ "$(id -u)" = 0 ] || { echo "run as root: sudo $0 ${1:-}" >&2; exit 1; }
fi

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENVF="${LG_AB_ENVFILE:-$ROOT/etc/looth/env}"
getenvv() { sed -n "s/^$1=//p" "$ENVF" 2>/dev/null | tr -d '"' | head -1; }

PUB="$(getenvv LG_PUBLIC_HOST)"
WP_PATH="$(getenvv LG_WP_PATH)"
WP_USER="$(getenvv LG_WP_USER)"
[ -n "$PUB" ] && [ -n "$WP_PATH" ] && [ -n "$WP_USER" ] || {
    echo "FATAL: $ENVF must define LG_PUBLIC_HOST, LG_WP_PATH and LG_WP_USER" >&2; exit 1; }

# ⚠️ LG_ENV SAYS "dev2" ON LIVE TOO — it cannot tell the boxes apart and a lane
# has been burnt by trusting it. The public host can.
BOX="other"
case "$PUB" in
    dev2.loothgroup.com) BOX="dev2" ;;
    loothgroup.com|www.loothgroup.com) BOX="live" ;;
esac
echo "== box: $PUB ($BOX), hostname $(hostname), docroot $WP_PATH, web user $WP_USER"

STATE_DIR=$ROOT/var/lib/lg-auto-ban
LIST_DIR=$ROOT/etc/nginx/lg-auto-ban
LIST=$LIST_DIR/list.conf
MAPS=$ROOT/etc/nginx/conf.d/lg-auto-ban-maps.conf
DOORS=$ROOT/etc/nginx/snippets/lg-auto-ban-doors.conf
UNIT_DIR=$ROOT/etc/systemd/system
UNITS=(lg-auto-ban.service lg-auto-ban.path lg-auto-ban.timer)
RENDER="$REPO/tools/infra/lg-auto-ban-render.py"

# ─────────────────────────────────────────────────────────────────────────────
# Resolve the ENABLED vhost by what it actually serves. Filenames lie here: live's
# serving vhost is still NAMED dev2.loothgroup.com.conf.
# ─────────────────────────────────────────────────────────────────────────────
vh_serves() { awk -v H="$1" '/^[[:space:]]*server_name[[:space:]]/ { for(i=2;i<=NF;i++){ n=$i; sub(/;$/,"",n); if(n==H) found=1 } } END{ exit !found }' "$2" 2>/dev/null; }
VHOST=""
for f in "$SITES_ENABLED"/*; do
    [ -e "$f" ] || continue
    vh_serves "$PUB" "$f" && VHOST="$f"
done

report() {
    echo
    echo "== current state"
    printf '   %-46s %s\n' "$STATE_DIR (ban store)"      "$([ -d "$STATE_DIR" ] && echo "present, owner $(stat -c %U "$STATE_DIR")" || echo ABSENT)"
    printf '   %-46s %s\n' "$MAPS (vocabulary)"          "$([ -f "$MAPS" ] && echo present || echo ABSENT)"
    printf '   %-46s %s\n' "$DOORS (the login doors)"    "$([ -f "$DOORS" ] && echo present || echo ABSENT)"
    printf '   %-46s %s\n' "$LIST (the deny list)"       "$([ -f "$LIST" ] && echo "present, $(grep -c '^"' "$LIST" 2>/dev/null || echo 0) address(es)" || echo ABSENT)"
    for u in "${UNITS[@]}"; do
        if [ -n "$ROOT" ]; then
            st="$([ -f "$UNIT_DIR/$u" ] && echo installed || echo 'not installed')"
        else
            st="$($SYSTEMCTL is-enabled "$u" 2>/dev/null | head -1)"
        fi
        printf '   %-46s %s\n' "$u" "${st:-not installed}"
    done
    if [ -n "$VHOST" ]; then
        if grep -q 'include /etc/nginx/snippets/lg-auto-ban-\*\.conf;' "$VHOST"; then
            printf '   %-46s %s\n' "vhost include line" "PRESENT in $(basename "$VHOST")"
        else
            printf '   %-46s %s\n' "vhost include line" "MISSING from $(basename "$VHOST") — the doors file would never be read"
        fi
    else
        printf '   %-46s %s\n' "vhost" "could not resolve an enabled vhost serving $PUB"
    fi
    echo
    echo "   The flag is separate and lives in the repo:"
    echo "     $REPO/platform/config/auto-ban.php  (+ auto-ban.local.php beside it, box-local)"
    echo "   Flag OFF => nothing is ever written down. This installer => nothing is ever blocked."
    echo "   BOTH must be on for an address to be refused."
}

if [ "$MODE" = "check" ]; then report; exit 0; fi

if [ "$MODE" = "uninstall" ]; then
    echo "== disarming (the ban store and its data are LEFT ALONE — remove $STATE_DIR by hand if you mean to)"
    for u in "${UNITS[@]}"; do
        [ -n "$ROOT" ] || $SYSTEMCTL disable --now "$u" >/dev/null 2>&1 || true
        rm -f "$UNIT_DIR/$u"
    done
    [ -n "$ROOT" ] || $SYSTEMCTL daemon-reload
    rm -f "$MAPS" "$DOORS" "$LIST"
    rmdir "$LIST_DIR" 2>/dev/null || true
    if runcmd "$NGINX_TEST_CMD"; then
        runcmd "$NGINX_RELOAD_CMD" && echo "   nginx reloaded — nothing is blocked any more"
    else
        echo "FATAL: nginx -t fails AFTER removal — that is not this feature; fix it before reloading" >&2
        exit 1
    fi
    report
    exit 0
fi

# ─────────────────────────────────────────────────────────────────────────────
# ARM
# ─────────────────────────────────────────────────────────────────────────────

# The FPM socket is read off the vhost's own PHP handler rather than guessed, so
# this cannot arm a door that points at a pool the box does not run.
FPM_SOCK=""
if [ -n "$VHOST" ]; then
    FPM_SOCK="$(awk '/location[[:space:]]*~[[:space:]]*\\\.php\$/,/^[[:space:]]*}/' "$VHOST" | sed -n 's|.*fastcgi_pass[[:space:]]*unix:\([^;]*\);.*|\1|p' | head -1)"
    [ -n "$FPM_SOCK" ] || FPM_SOCK="$(sed -n 's|.*fastcgi_pass[[:space:]]*unix:\([^;]*\);.*|\1|p' "$VHOST" | grep -v billing | head -1)"
fi
[ -n "$FPM_SOCK" ] || { echo "FATAL: could not find the WordPress FPM socket in $VHOST" >&2; exit 1; }
echo "== FPM socket: $FPM_SOCK"

[ -S "$FPM_SOCK" ] || echo "   WARN: $FPM_SOCK is not a live socket right now"

if [ -n "$VHOST" ] && ! grep -q 'include /etc/nginx/snippets/lg-auto-ban-\*\.conf;' "$VHOST"; then
    echo
    echo "STOP: $VHOST does not contain the line"
    echo "        include /etc/nginx/snippets/lg-auto-ban-*.conf;"
    echo "      Without it nginx never reads the doors file and this installs a"
    echo "      blocklist that blocks nobody — the exact false-green this feature"
    echo "      must not ship. The line is tracked in"
    echo "        platform/nginx/dev2.loothgroup.com.conf"
    echo "      so on a box whose vhost is a symlink into the checkout it arrives"
    echo "      by pull; dev2's is a drifted COPY and needs:"
    echo "        sudo cp $REPO/platform/nginx/$(basename "$VHOST") $VHOST && sudo nginx -t && sudo systemctl reload nginx"
    exit 1
fi

# The polite page has to be THERE, not merely written. It is served by an
# internal alias into /srv/lg-shared/errors/, which is a symlink into the serving
# checkout — so on a box that has armed this before pulling the commit that adds
# the page, a blocked member gets nginx's bare 404 instead of an explanation.
# That is precisely the blank-refusal Ian's revision ruled out, and it is
# invisible from the config: `nginx -t` passes either way.
if [ ! -r "$BANPAGE" ]; then
    echo
    echo "STOP: $BANPAGE is missing."
    echo "      Blocked members would get a bare 404 with no explanation, which is"
    echo "      the thing this feature was explicitly revised NOT to do. Pull the"
    echo "      commit that adds lg-shared/errors/login-blocked.html into the"
    echo "      serving checkout first, then run this again."
    exit 1
fi
echo "== polite page present: $BANPAGE"

echo "== ban store"
if [ -n "$ROOT" ]; then
    install -d -m 0755 "$STATE_DIR"          # redirected: no chown, no root
else
    install -d -o "$WP_USER" -g "$WP_USER" -m 0755 "$STATE_DIR"
fi
# Root's own allowlist: WordPress cannot write here, so an address in this file is
# immune to the dashboard as well as to the detector.
if [ ! -f "$STATE_DIR/allowlist.local" ]; then
    cat > "$STATE_DIR/allowlist.local" <<'ALLOWEOF'
# Addresses (or CIDRs) that must NEVER be blocked, whatever the detector or the
# dashboard says. Root-owned: WordPress cannot edit this file.
#
# The boxes themselves, Cloudflare's ranges and every private/loopback address are
# refused structurally by the renderer and do NOT need a line here.
#
# One entry per line, e.g.
#   203.0.113.9          # Ian's home
#   198.51.100.0/24      # the venue
ALLOWEOF
    chmod 0644 "$STATE_DIR/allowlist.local"
fi
echo "   $STATE_DIR ready (owner $WP_USER)"

echo "== nginx vocabulary -> $MAPS"
CF_LIST="$REPO/tools/infra/cloudflare-ranges.txt"
[ -f "$CF_LIST" ] || { echo "FATAL: missing $CF_LIST" >&2; exit 1; }
CF_BLOCK="$(grep -E '^[0-9a-fA-F]' "$CF_LIST" | sed 's/^/    /; s/$/ 1;/')"
[ -n "$CF_BLOCK" ] || { echo "FATAL: $CF_LIST yielded no ranges — refusing to install a config that would trust nothing" >&2; exit 1; }

TMPMAPS="$(mktemp)"; TMPDOORS="$(mktemp)"
CF_BLOCK="$CF_BLOCK" LIST_INCLUDE="$LIST_DIR/list*.conf" \
python3 - "$REPO/platform/nginx/lg-auto-ban-maps.conf.template" "$TMPMAPS" <<'PYEOF'
import os, sys
src, dst = sys.argv[1], sys.argv[2]
body = open(src).read()
body = body.replace('@CF_RANGES@', os.environ['CF_BLOCK'])
body = body.replace('@LIST_INCLUDE@', os.environ['LIST_INCLUDE'])
open(dst, 'w').write(body)
PYEOF

sed -e "s|@FPM_SOCK@|$FPM_SOCK|g" -e "s|@DOCROOT@|$WP_PATH|g" \
    "$REPO/platform/nginx/lg-auto-ban-doors.conf.template" > "$TMPDOORS"
# ⚠️ `grep '@'` would match info@loothgroup.com in the JSON refusal body. Only a
# @SHOUTY@ token is a placeholder.
for f in "$TMPMAPS" "$TMPDOORS"; do
    if grep -qE '@[A-Z_]+@' "$f"; then
        echo "FATAL: unsubstituted placeholder left in $f:"; grep -nE '@[A-Z_]+@' "$f"
        rm -f "$TMPMAPS" "$TMPDOORS"; exit 1
    fi
done

# Keep whatever is there now so a rejected config can be put back exactly.
PREV_MAPS=""; PREV_DOORS=""; PREV_LIST=""
[ -f "$MAPS" ]  && PREV_MAPS="$(mktemp)"  && cp "$MAPS"  "$PREV_MAPS"
[ -f "$DOORS" ] && PREV_DOORS="$(mktemp)" && cp "$DOORS" "$PREV_DOORS"
[ -f "$LIST" ]  && PREV_LIST="$(mktemp)"  && cp "$LIST"  "$PREV_LIST"

install -d -m 0755 "$LIST_DIR"
install -m 0644 "$TMPMAPS" "$MAPS"
install -m 0644 "$TMPDOORS" "$DOORS"
rm -f "$TMPMAPS" "$TMPDOORS"

echo "== deny list -> $LIST (rendering from the current store)"
LG_AB_OUT="$LIST" LG_AB_STATE="$STATE_DIR/state.json" LG_AB_STATUS="$STATE_DIR/render-status.json" \
    LG_AB_OP_ALLOW="$STATE_DIR/allowlist.local" LG_AB_DOORS="$DOORS" LG_AB_MAPS="$MAPS" \
    LG_AB_NGINX_TEST= LG_AB_NGINX_RELOAD= "$RENDER" || true
[ -f "$LIST" ] || printf '# no bans yet\n' > "$LIST"

echo "== nginx -t"
if ! runcmd "$NGINX_TEST_CMD"; then
    echo
    echo "REJECTED — rolling every piece of this back, nothing is armed."
    if [ -n "$PREV_MAPS" ];  then cp "$PREV_MAPS" "$MAPS";   else rm -f "$MAPS"; fi
    if [ -n "$PREV_DOORS" ]; then cp "$PREV_DOORS" "$DOORS"; else rm -f "$DOORS"; fi
    if [ -n "$PREV_LIST" ];  then cp "$PREV_LIST" "$LIST";   else rm -f "$LIST"; rmdir "$LIST_DIR" 2>/dev/null || true; fi
    runcmd "$NGINX_TEST_CMD" && echo "   config is valid again; nothing changed on this box"
    exit 1
fi
runcmd "$NGINX_RELOAD_CMD" && echo "   nginx reloaded — the doors are live"

echo "== units (COPIES in /etc/systemd/system, never symlinks — a pull does not deploy these)"
install -d -m 0755 "$UNIT_DIR"
for u in "${UNITS[@]}"; do
    install -m 0644 "$REPO/platform/systemd/$u" "$UNIT_DIR/$u"
done
if [ -z "$ROOT" ]; then
    $SYSTEMCTL daemon-reload
    $SYSTEMCTL enable --now lg-auto-ban.path lg-auto-ban.timer >/dev/null 2>&1
    $SYSTEMCTL start lg-auto-ban.service >/dev/null 2>&1 || true
fi
echo "   path + timer enabled"

report

cat <<EOS
== what happens next
  1. This box now BLOCKS anyone on the list. The list is empty until the flag is on.
  2. Turn recording on for this box only:
       printf '<?php return array( "enabled" => true );\\n' > $REPO/platform/config/auto-ban.local.php
     (.local.php is gitignored — it cannot travel to the other box by accident.)
  3. Watch it work:
       wp-admin -> Login bans        (the page says whether it is recording AND blocking)
       sudo cat $STATE_DIR/state.json
       sudo cat $LIST
       journalctl -u lg-auto-ban.service -n 30
  4. Undo any of it:
       one address      wp-admin -> Login bans -> Remove
       stop recording   rm $REPO/platform/config/auto-ban.local.php
       stop blocking    sudo $0 --uninstall
EOS
