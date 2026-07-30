#!/bin/bash
# react-types-cover-standalone-gate.sh — a page that RENDERS a react button must have
# an endpoint that ACCEPTS it.
#
# THE DEFECT CLASS (Ian's live report 2026-07-29, found on FOUR surfaces at once):
# archive-poc/standalone/render.php mints the sticky-dock react widget for ANY blob it
# serves, tagging it `data-pt` = that page's own post_type. The write door,
# archive-poc/api/v0/card-react.php, validates post_type against LG_CARD_REACT_TYPES
# and 400s bad_request on anything else. The two lists were never coupled, so every
# post type nginx routes to render.php but nobody thought to add to the constant
# shipped a reaction button that could ONLY fail:
#
#   /shorty/dan-erlewine-stewmac-purfling-jig-mod/  data-pt="shorty"        -> 400
#   /event/council-of-elders-2-0-4/                 data-pt="event"         -> 400
#   /document/57536/                                data-pt="document"      -> 400
#   /sponsors/stewmac/                              data-pt="sponsor-page"  -> 400
#
# The same constant gates the GET door (card-react.php:82), so those pages also showed
# NO count — live had 22 shorty reactions invisible on their own page.
#
# THE RULE: LG_CARD_REACT_TYPES must COVER every post_type nginx can hand to
# standalone/render.php. Onboarding a CPT to the standalone renderer is one word in an
# nginx alternation (by design — see the comment on that location block); this gate is
# what makes the second word, in the endpoint, non-optional.
#
# Deliberately STATIC: the failure needs a logged-in member tapping a real emoji on a
# type nobody tests, so a runtime probe would need an account, a browser seat and a
# fixture per type. Reading the routing table catches every type, including ones with
# no content yet.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1

red=0

ENDPOINT=archive-poc/api/v0/card-react.php
REACTORS=archive-poc/api/v0/card-reactors.php
RENDERER=archive-poc/standalone/render.php

for f in "$ENDPOINT" "$REACTORS" "$RENDERER"; do
  if [ ! -f "$f" ]; then
    echo "  CANNOT RUN — $f is missing; this gate has no subject."
    exit 2
  fi
done

# --- the coupling this gate rests on ----------------------------------------------
# If render.php stops emitting the raw post_type (e.g. starts mapping aliases), the
# routing table is no longer the right source of truth and this gate is reasoning
# about the wrong thing. Fail loudly rather than pass on a stale assumption.
echo "--- the standalone dock still tags its react widget with the page's own post_type ---"
if grep -qF 'data-pt="<?= htmlspecialchars($reactPt' "$RENDERER"; then
  echo "  OK   render.php emits data-pt = \$reactPt (= \$pc['post_type'])"
else
  echo "  RED  render.php no longer emits data-pt from the page post_type verbatim."
  echo "       This gate compares nginx's routed types against the endpoint whitelist on"
  echo "       the assumption they are the same strings. Re-derive it before trusting it."
  red=1
fi

# --- every post_type nginx routes into render.php ----------------------------------
# Two shapes in the conf: a literal (`LG_POST_TYPE post-imgcap`) and the catch-all
# alternation (`LG_POST_TYPE $1`, where $1 is the location regex's first group). For
# the latter, take the alternation from the nearest preceding `location` line.
confs=$(grep -rl 'standalone/render\.php' --include='*.conf' . 2>/dev/null | sort)
if [ -z "$confs" ]; then
  echo "  CANNOT RUN — no nginx conf in the repo routes standalone/render.php."
  exit 2
fi

routed=$(awk '
  /location[[:space:]]*~/ { loc = $0 }
  /fastcgi_param[[:space:]]+LG_POST_TYPE/ {
    v = $3; gsub(/;/, "", v)
    if (v ~ /^\$[0-9]+$/) {
      # pull the first (…|…|…) group out of the remembered location regex
      if (match(loc, /\(([a-z0-9_|-]+)\)/)) {
        grp = substr(loc, RSTART + 1, RLENGTH - 2)
        n = split(grp, parts, "|")
        for (i = 1; i <= n; i++) if (parts[i] != "") print parts[i]
      }
    } else if (v != "") {
      print v
    }
  }
' $confs | sort -u)

# --- the endpoint whitelist --------------------------------------------------------
# Read the constant's quoted members, ignoring comment lines inside the array.
extract_types() { # <file> <const-name>
  awk -v c="$2" '
    index($0, "const " c) { on = 1 }
    on {
      line = $0
      sub(/\/\/.*/, "", line)          # strip trailing // comments
      while (match(line, /'"'"'[a-z0-9_-]+'"'"'/)) {
        print substr(line, RSTART + 1, RLENGTH - 2)
        line = substr(line, RSTART + RLENGTH)
      }
      if (index($0, "]")) exit
    }
  ' "$1" | sort -u
}

accepted=$(extract_types "$ENDPOINT" LG_CARD_REACT_TYPES)
reactors=$(extract_types "$REACTORS" LG_CARD_REACTORS_TYPES)

if [ -z "$routed" ] || [ -z "$accepted" ]; then
  echo "  CANNOT RUN — parsed 0 routed types or 0 accepted types; the parse is broken,"
  echo "               which is not the same as the code being right."
  exit 2
fi

echo "--- every standalone-routed post_type is accepted by card-react.php ---"
echo "     routed by nginx : $(echo "$routed"   | tr '\n' ' ')"
echo "     accepted by API : $(echo "$accepted" | tr '\n' ' ')"
missing=$(comm -23 <(echo "$routed") <(echo "$accepted"))
if [ -n "$missing" ]; then
  for t in $missing; do
    echo "  RED  '$t' is served as a standalone page but card-react.php refuses it"
    echo "       -> that page renders a react button whose POST can only 400 bad_request."
  done
  echo "       Add it to LG_CARD_REACT_TYPES ($ENDPOINT)."
  red=1
else
  echo "  OK   $(echo "$routed" | grep -c .) routed types, all accepted"
fi

# --- the who-reacted door must not be narrower than the write door -----------------
# A type you can react to but cannot read the reactors of is the same defect one click
# further in: the count opens a modal that 400s.
echo "--- card-reactors.php is not narrower than card-react.php ---"
narrower=$(comm -23 <(echo "$accepted") <(echo "$reactors"))
if [ -n "$narrower" ]; then
  for t in $narrower; do
    echo "  RED  '$t' is reactable but LG_CARD_REACTORS_TYPES rejects it (who-reacted 400s)"
  done
  red=1
else
  echo "  OK   reactors door covers all $(echo "$accepted" | grep -c .) reactable types"
fi

# --- the OTHER half of the 400: the slug must be in the server palette -------------
# card-react.php:137-140 fails on a bad post_type OR a bad slug. Both react widgets
# hardcode their own PALETTE client-side; if one drifts from lg_reactions_palette()
# the button 400s exactly the same way, with no clue which half was wrong.
echo "--- both react widgets' client palettes are subsets of lg_reactions_slugs() ---"
server_slugs=$(awk "/function lg_reactions_palette/,/^}/" archive-poc/api/v0/_comments.php \
                 | grep -o "'slug' *=> *'[a-z0-9-]*'" | sed "s/.*'\([a-z0-9-]*\)'$/\1/" | sort -u)
if [ -z "$server_slugs" ]; then
  echo "  CANNOT RUN — could not read lg_reactions_palette() from _comments.php."
  exit 2
fi
checked=0
for widget in "$RENDERER" lg-layout-v2/blocks/post-footer/render.php \
              archive-poc/standalone/engine/blocks/post-footer/render.php; do
  [ -f "$widget" ] || continue
  grep -q 'card-react' "$widget" || continue
  client=$(grep -o "{slug:[[:space:]]*['\"][a-z0-9-]*" "$widget" \
             | sed "s/.*['\"]//" | sort -u)
  if [ -z "$client" ]; then
    echo "  RED  $widget POSTs to card-react but no client PALETTE could be parsed."
    echo "       Either the palette moved or this check silently stopped checking."
    red=1
    continue
  fi
  checked=$((checked + 1))
  bad=$(comm -23 <(echo "$client") <(echo "$server_slugs"))
  if [ -n "$bad" ]; then
    echo "  RED  $widget offers slug(s) the server rejects: $(echo "$bad" | tr '\n' ' ')"
    echo "       -> tapping it 400s exactly like a bad post_type does."
    red=1
  else
    echo "  OK   $widget ($(echo "$client" | grep -c .) slugs, all in the server palette)"
  fi
done
# A check that inspected nothing is not a pass. This sub-check shipped as a silent
# no-op in its first draft (a sed that ate its own capture) and looked green.
if [ "$checked" -eq 0 ]; then
  echo "  RED  no react widget was actually inspected — this check was a no-op."
  red=1
fi

echo
if [ "$red" -ne 0 ]; then echo "react-types-cover-standalone gate: RED"; exit 1; fi
echo "react-types-cover-standalone gate: GREEN"
