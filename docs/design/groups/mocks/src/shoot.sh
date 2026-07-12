#!/usr/bin/env bash
# groups-design lane — round-2 mock shooter. RUN ON dev2, as ubuntu.
#
#   bash shoot.sh [outdir]
#
# Read-only against the serve: launches its OWN headless chromes on ports 9700+,
# loads real dev2 pages, runtime-injects nav.js, screenshots, kills every chrome.
# NO overlay. NO symlink swap. NO FPM reload. Nothing on the box is modified.
#
# ⚠️ DO NOT RUN while another lane holds an overlay window on dev2 — you would be
#    shooting THEIR branch instead of main. Check the board first.
set -uo pipefail

HOST="${HOST:-http://127.0.0.1}"          # served vhost; Host header set below
VHOST="${VHOST:-dev2.loothgroup.com}"
OUT="${1:-$HOME/groups-mocks-r2}"
SRC="$(cd "$(dirname "$0")" && pwd)"
CHROME=/opt/lg-chrome/chrome-linux64/chrome
WORK=$(mktemp -d /tmp/gdmock.XXXXXX)
COOKIES="$WORK/cookies.json"
mkdir -p "$OUT"

cleanup() {
  # Never leak a headless chrome — a leaked one sits alive at 0% CPU forever
  # and trips the idle-detection work (proc-activity traps memory).
  for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null; done
  sleep 1
  for p in "${PIDS[@]:-}"; do kill -9 "$p" 2>/dev/null; done
  rm -rf "$WORK"
}
trap cleanup EXIT
PIDS=()

# ---- 1. mint a real WP logged-in cookie (the nav tray / You sheet only exist authed) ----
: "${WP_USER:=}"
if [ -z "$WP_USER" ]; then echo "set WP_USER=<login> (a real member, NOT an admin ideally)"; exit 1; fi
echo "minting cookie for $WP_USER …"
# NOTE: the login is interpolated, NOT read via getenv() — `sudo` resets the environment
# (env_reset), so a WP_USER exported here does not survive into `wp eval`.
# WP-CLI also emits DISABLE_WP_CRON warnings, hence tail -1.
COOKIE_KV=$(sudo -n -u looth-dev wp --path=/var/www/dev eval "
  \$u = get_user_by('login', '${WP_USER//\'/}');
  if (!\$u) { fwrite(STDERR, 'no such user'.PHP_EOL); exit(1); }
  \$exp = time() + 86400;
  \$tok = WP_Session_Tokens::get_instance(\$u->ID)->create(\$exp);
  echo LOGGED_IN_COOKIE . \"\t\" . wp_generate_auth_cookie(\$u->ID, \$exp, 'logged_in', \$tok);
" 2>/dev/null | tail -1) || { echo "cookie mint FAILED"; exit 1; }
[ -n "$COOKIE_KV" ] || { echo "cookie mint FAILED (empty)"; exit 1; }

CNAME="${COOKIE_KV%%$'\t'*}"; CVAL="${COOKIE_KV#*$'\t'}"
python3 - "$CNAME" "$CVAL" "$VHOST" > "$COOKIES" <<'PY'
import json, sys
name, val, host = sys.argv[1], sys.argv[2], sys.argv[3]
print(json.dumps([{"name": name, "value": val, "domain": host, "path": "/", "httpOnly": True}]))
PY
echo "cookie ok ($CNAME)"

# ---- 2. the shot list -------------------------------------------------------
# variant | path | label
SHOTS=(
  "before|/hub/|P0-composer-TODAY-forum-tree"
  "pick-group|/hub/|P1-composer-step1-groups"
  "pick-sub|/hub/|P2-composer-step2-subforums-scoped"
  "context|/hub/|P3-composer-in-context-minihub"
  "chip|/hub/|N1-hub-card-group-chip"
  "dir|/hub/|N2-groups-directory"
  "tray|/hub/|N3a-navtray-groups-tile-RECOMMENDED"
  "tray-merged|/hub/|N3b-navtray-merged-picker-ALTERNATIVE"
  "you|/hub/|N4-you-sheet-my-groups"
)
# desktop-only / mobile-only overrides: the tray, You sheet and merged picker are
# mobile surfaces; the desktop counterpart of nav is the header + lg-hubmenu.
MOBILE_ONLY="tray tray-merged you"

PORT=9700
shoot() { # variant path label width height
  local v=$1 p=$2 lab=$3 w=$4 h=$5
  local png="$OUT/${lab}-${w}.png"
  "$CHROME" --headless=new --no-sandbox --disable-gpu --disable-dev-shm-usage \
      --hide-scrollbars --force-device-scale-factor=2 \
      --remote-debugging-port=$PORT --remote-allow-origins='*' \
      --host-resolver-rules="MAP $VHOST 127.0.0.1" \
      about:blank >"$WORK/chrome.$PORT.log" 2>&1 &
  local cp=$!; PIDS+=("$cp")
  HOME="$WORK" TMPDIR="$WORK" \
    node --experimental-websocket "$SRC/cdp.js" \
       "$PORT" "https://$VHOST$p" "$WORK/inject.$v.js" "$png" "$w" "$h" "$COOKIES" \
    || echo "  !! FAILED $lab @$w"
  kill "$cp" 2>/dev/null; sleep 0.4; kill -9 "$cp" 2>/dev/null
  PORT=$((PORT + 1))
}

for s in "${SHOTS[@]}"; do
  IFS='|' read -r v p lab <<<"$s"
  # variant is selected by a prelude that sets __MOCK_VARIANT, then runs nav.js
  { echo "window.__MOCK_VARIANT=$(printf '%s' "$v" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))');"
    cat "$SRC/nav.js"; } > "$WORK/inject.$v.js"

  echo "── $lab"
  shoot "$v" "$p" "$lab" 390 844                                  # mobile
  if [[ " $MOBILE_ONLY " != *" $v "* ]]; then
    shoot "$v" "$p" "$lab" 1280 900                               # desktop
  else
    echo "  (mobile-only surface — desktop counterpart is the header nav / lg-hubmenu)"
  fi
done

echo
echo "done → $OUT"
ls -la "$OUT"
