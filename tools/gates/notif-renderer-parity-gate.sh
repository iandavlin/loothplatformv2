#!/bin/bash
# notif-renderer-parity-gate.sh — every notification type has a SENTENCE on BOTH bells.
#
#     bash tools/gates/notif-renderer-parity-gate.sh
#     bash tools/gates/notif-renderer-parity-gate.sh --prove   # red-first, against the defect
#
# notif-bridge lane, 2026-08-08.
#
# ── THE DEFECT CLASS ────────────────────────────────────────────────────────
# `forum.followed_topic` shipped on 2026-07-28. The DESKTOP bell got its sentence
# (lg-shared/social-modals.js). The MOBILE bell did not, and its `default:` branch
# renders a bare actor name — a row that says "Karl Borum" and nothing else. It stayed
# that way for eleven days, on live, and BOTH of the rows that type has ever produced
# went to real members, one of them Ian.
#
# The mobile file's own comment asserted the two surfaces "say the same sentence".
# A comment is not an assertion. This is.
#
# ── THE SOURCE OF TRUTH IS THE MODEL, NOT EITHER RENDERER ───────────────────
# Diffing the two switches against each other would go green the day someone adds a
# type to neither. So the required set is read from profile-app/src/Notifications.php
# (TYPES + HUB_TYPES) — the writer's own vocabulary and the thing the
# notifications_type_check constraint is kept in lockstep with. A type that can be
# STORED must be RENDERABLE on every surface that lists it.
#
# ── WHY IT READS `case '…':` AND NOTHING ELSE ───────────────────────────────
# feedback-red-first-that-stays-green: a gate here once matched a file's own prose
# instead of its code. Both renderers discuss these type names in comments, at length.
# So the extraction is anchored on the CASE LABEL SYNTAX, which only executable code
# has, and --prove breaks it deliberately to show the assertion can fail.
#
# Exit 0 = green, 1 = RED, 2 = CANNOT RUN.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MODEL="$ROOT/profile-app/src/Notifications.php"
DESKTOP="$ROOT/lg-shared/social-modals.js"
MOBILE="$ROOT/webroot/bottom-nav.js"

for f in "$MODEL" "$DESKTOP" "$MOBILE"; do
  [ -r "$f" ] || { echo "CANNOT RUN: unreadable $f"; exit 2; }
done

# Required set: the quoted string literals inside the TYPES and HUB_TYPES arrays.
# Bounded by the closing bracket of each so the rest of the file cannot leak in.
required=$(awk "
  /public const TYPES/,/\];/     { print }
  /public const HUB_TYPES/,/\];/ { print }
" "$MODEL" | grep -oE "'[a-z][a-z_]*(\.[a-z_]+)?'" | tr -d "'" | sort -u)

[ -n "$required" ] || { echo "CANNOT RUN: no types parsed from $MODEL"; exit 2; }
echo "required (from Notifications.php): $(echo "$required" | paste -sd' ' -)"

# ── THE PARSE MUST NOT SILENTLY UNDER-ASSERT ────────────────────────────────
# This gate shipped for about an hour with `[a-z]+` as the first segment, which
# does not match an underscore — so `connection_request` and `connection_accept`
# were dropped from `required` and NEVER CHECKED, while the gate printed a
# confident GREEN. A gate that asserts less than it claims is worse than no gate.
#
# The cross-check is mutual and needs no hardcoded list: anything either renderer
# has a case for, but the model parse does not contain, means one of the two is
# wrong — an incomplete parse here, or a stale type in a renderer. Both are worth
# stopping for, and this cannot rot the way a hardcoded floor would.

# Rendered set: case labels only. Comments cannot produce `case 'x':`.
cases_in() { grep -oE "case '[a-z][a-z_]*(\.[a-z_]+)?':" "$1" | sed "s/case '//; s/'://" | sort -u; }

if [ "${1:-}" = "--prove" ]; then
  # RED-FIRST against the real defect, with no file mutated: the mobile renderer as
  # it stood before this lane touched it. If the gate passes on THAT, it is decoration.
  tmp=$(mktemp); trap 'rm -f "$tmp"' EXIT
  if ! git -C "$ROOT" show 862feb9:webroot/bottom-nav.js > "$tmp" 2>/dev/null; then
    echo "CANNOT RUN: cannot retrieve the pre-fix bottom-nav.js"; exit 2
  fi
  missing=$(comm -23 <(echo "$required") <(cases_in "$tmp"))
  echo "--- red-first: the pre-fix mobile renderer (862feb9) ---"
  if [ -z "$missing" ]; then
    echo "RED: the gate PASSES on the known-defective file — it asserts nothing."
    exit 1
  fi
  echo "correctly RED, missing: $(echo "$missing" | tr '\n' ' ')"
  echo "$missing" | grep -qx 'forum.followed_topic' || {
    echo "RED: it went red, but not for the defect this gate is about."; exit 1; }
  echo "red-first OK"
  exit 0
fi

rc=0
stray=$(comm -13 <(echo "$required") <(cat <(cases_in "$DESKTOP") <(cases_in "$MOBILE") | sort -u))
if [ -n "$stray" ]; then
  rc=1
  echo "FAIL a renderer handles types the model parse does not list:"
  echo "$stray" | sed 's/^/       /'
  echo "       => either the parse above is incomplete, or a renderer carries a dead type."
fi

for pair in "desktop:$DESKTOP" "mobile:$MOBILE"; do
  name="${pair%%:*}"; file="${pair#*:}"
  missing=$(comm -23 <(echo "$required") <(cases_in "$file"))
  if [ -n "$missing" ]; then
    rc=1
    echo "FAIL $name ($(basename "$file")) has no sentence for:"
    echo "$missing" | sed 's/^/       /'
  else
    echo "PASS $name renders every storable type"
  fi
done

# ── THE GOAL, not the proxy ──────────────────────────────────────────────────
# A `case` label is only EVIDENCE of a sentence. `case 'forum.x': return esc(who);`
# would satisfy every check above and still be exactly the defect this gate exists
# for. So execute each label function for real and look at what a member would read.
if command -v node >/dev/null 2>&1; then
  types=$(echo "$required" | paste -sd, -)
  echo "--- executing the real label functions ---"
  for spec in "desktop:$DESKTOP:notifText:notifActors:esc" "mobile:$MOBILE:ntText:ntActors:ntEsc"; do
    IFS=: read -r nm f fn af ef <<< "$spec"
    echo "  [$nm]"
    node "$(dirname "$0")/lib/notif-text-exec.js" "$f" "$fn" "$af" "$ef" "$types"
    case $? in
      0) ;;
      2) echo "  CANNOT RUN: could not execute $nm's $fn"; exit 2 ;;
      *) rc=1 ;;
    esac
  done
else
  echo "CANNOT RUN: node is not on PATH (needed to execute the label functions)"
  exit 2
fi

# And the two must agree with each other, so one surface cannot quietly grow a type
# the other lacks even when both cover the model.
d=$(cases_in "$DESKTOP"); m=$(cases_in "$MOBILE")
if [ "$d" != "$m" ]; then
  rc=1
  echo "FAIL the two bells disagree:"
  diff <(echo "$d") <(echo "$m") | sed 's/^/       /'
else
  echo "PASS both bells cover an identical type set"
fi

[ $rc -eq 0 ] && echo "GREEN" || echo "RED"
exit $rc
