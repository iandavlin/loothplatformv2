#!/usr/bin/env bash
# Shot generator for the threadfollow notif-panel mock (THREAD-FOLLOW-SPEC §4).
# Mock tooling only — never shipped. Run from this dir: ./shoot.sh
# dev1 google-chrome, STRICTLY SERIAL — each shot is one headless chrome that exits
# before the next launches (one-chrome-at-a-time RAM courtesy; flag the board first).
# 4 frames: desktop 1280 = followed-topic one-item Mute menu open;
#           mobile 390  = mention row two-item menu open (mock JS is resize-robust
#           against the headless 500px-clamp-then-relayout).
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="$DIR/shots"
PROFILE="$(mktemp -d)"
trap 'rm -rf "$PROFILE"' EXIT
mkdir -p "$OUT"

shot() { # $1=query  $2=WxH  $3=outname
  local url="file://$DIR/mock.html$1" wh="$2" out="$3"
  google-chrome --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=2 \
    --user-data-dir="$PROFILE" --window-size="${wh/x/,}" --virtual-time-budget=1500 \
    --screenshot="$OUT/$out.png" "$url" >/dev/null 2>&1
  echo "shot $out"
}

shot ""            1280x900 "notif-d-light"
shot "?theme=dark" 1280x900 "notif-d-dark"
shot ""            390x844  "notif-m-light"
shot "?theme=dark" 390x844  "notif-m-dark"
echo "done: $(ls "$OUT" | wc -l) shots in $OUT"
