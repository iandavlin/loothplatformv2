#!/usr/bin/env bash
# Shot generator for the profile-polish previs (captions + accordions). Mock tooling
# only — never shipped. Run from this dir: ./shoot.sh
# dev1 google-chrome, STRICTLY SERIAL — each shot is one headless chrome that exits
# before the next launches (one-chrome-at-a-time RAM courtesy; flag the board first).
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="$DIR/shots"
PROFILE="$(mktemp -d)"
trap 'rm -rf "$PROFILE"' EXIT
mkdir -p "$OUT"

shot() { # $1=file.html?params  $2=WxH  $3=outname
  local url="file://$DIR/$1" wh="$2" out="$3"
  google-chrome --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=2 \
    --user-data-dir="$PROFILE" --window-size="${wh/x/,}" --virtual-time-budget=1500 \
    --screenshot="$OUT/$out.png" "$url" >/dev/null 2>&1
  echo "shot $out"
}

for theme in light dark; do
  # (A) gallery captions — desktop 1280
  for state in viewer lightbox owner owner-edit owner-lightbox; do
    shot "gallery-captions.html?state=$state&theme=$theme" 1280x900 "cap-d-$state-$theme"
  done
  # (A) mobile 390
  for state in viewer lightbox owner owner-edit; do
    shot "gallery-captions.html?state=$state&theme=$theme" 390x1000 "cap-m-$state-$theme"
  done
  # (B) taxonomy accordions — desktop 1280
  shot "taxonomy-accordions.html?variant=rows1&state=collapsed&theme=$theme" 1280x950 "acc-d-rows1-$theme"
  shot "taxonomy-accordions.html?variant=rows2&state=collapsed&theme=$theme" 1280x950 "acc-d-rows2-$theme"
  shot "taxonomy-accordions.html?variant=rows1&state=expanded&theme=$theme"  1280x950 "acc-d-expanded-$theme"
  # (B) mobile 390
  shot "taxonomy-accordions.html?variant=rows1&state=collapsed&theme=$theme" 390x1300 "acc-m-rows1-$theme"
  shot "taxonomy-accordions.html?variant=rows2&state=collapsed&theme=$theme" 390x1300 "acc-m-rows2-$theme"
  shot "taxonomy-accordions.html?variant=rows1&state=expanded&theme=$theme"  390x1300 "acc-m-expanded-$theme"
done
echo "done: $(ls "$OUT" | wc -l) shots in $OUT"
