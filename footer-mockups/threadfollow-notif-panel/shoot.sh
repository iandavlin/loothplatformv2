#!/usr/bin/env bash
# Shot generator for the threadfollow v2 mocks (THREAD-FOLLOW-SPEC §7).
# Mock tooling only — never shipped. Run from this dir: ./shoot.sh
# dev1 google-chrome, STRICTLY SERIAL — each shot is one headless chrome that exits
# before the next launches (one-chrome-at-a-time RAM courtesy; flag the board first).
#
# Frames (spec §7):
#   card-*    mock-card.html   — feed card, two toggles + the one-control fallback (THE crowding test)
#   modal-*   mock-modal.html  — dmodal header cluster [🔔][✉][M][×] + the ≤640 sheet header
#   unsub-*   mock-unsub.html  — email unsubscribe confirmation page, ask + done states
#   notif-*   mock.html        — notifications panel, row ⋯ menu carrying BOTH bits
#
# Every mock re-applies its frame control on resize: headless chrome clamps windows to a
# 500px minimum then relayouts, so a load-time-only matchMedia/query check renders the
# WRONG variant on the narrow frames. That trap is encoded in each mock's JS — do not
# "simplify" it back to a one-shot check.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="$DIR/shots"
PROFILE="$(mktemp -d)"
trap 'rm -rf "$PROFILE"' EXIT
mkdir -p "$OUT"

shot() { # $1=file  $2=query  $3=WxH  $4=outname
  local url="file://$DIR/$1$2" wh="$3" out="$4"
  google-chrome --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=2 \
    --user-data-dir="$PROFILE" --window-size="${wh/x/,}" --virtual-time-budget=1500 \
    --screenshot="$OUT/$out.png" "$url" >/dev/null 2>&1
  echo "shot $out"
}

# 1 · feed card (frames 1 + 2)
shot mock-card.html  ""            1280x1500 "card-d-light"
shot mock-card.html  "?theme=dark" 1280x1500 "card-d-dark"
shot mock-card.html  ""            390x1250  "card-m-light"
shot mock-card.html  "?theme=dark" 390x1250  "card-m-dark"

# 2 · discussion modal header (frame 3)
shot mock-modal.html ""            1280x1250 "modal-d-light"
shot mock-modal.html "?theme=dark" 1280x1250 "modal-d-dark"

# 3 · email unsubscribe page (frame 4)
shot mock-unsub.html ""                       1280x900 "unsub-d-light"
shot mock-unsub.html "?theme=dark"            1280x900 "unsub-d-dark"
shot mock-unsub.html "?state=done"            1280x900 "unsub-d-done"
shot mock-unsub.html ""                       390x844  "unsub-m-light"

# 4 · notifications panel (frame 5, re-shot for the two-bit row menu)
shot mock.html       ""            1280x900 "notif-d-light"
shot mock.html       "?theme=dark" 1280x900 "notif-d-dark"
shot mock.html       ""            390x844  "notif-m-light"
shot mock.html       "?theme=dark" 390x844  "notif-m-dark"

echo "done: $(ls "$OUT" | wc -l) shots in $OUT"
