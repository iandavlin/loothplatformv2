#!/bin/bash
# front-banner-patreon-dark-redfirst.sh — prove GATE 80 can actually FAIL, and
# fail for the reason it claims.
#
# WHY THIS EXISTS. A gate that has never been seen red is decoration: it might be
# asserting nothing, or asserting a string that also appears in a comment. This
# repo has caught that six times (feedback-red-first-that-stays-green), including
# once where a "red-first" reddened a page that was working. So every mutation
# below must redden its OWN NAMED ASSERTION — not merely "the gate went red".
#
# ⚠️ EVERY MUTATION IS APPLIED TO A SNAPSHOT COPY AND RESTORED FROM IT.
# Never `git checkout --` (feedback-mutation-harness-must-snapshot-not-checkout):
# checkout-from-HEAD wipes uncommitted work under test, and one harness doing
# that turned a single bug into ten false "the assertion is decoration" verdicts.
# The snapshots are taken before anything is touched and restored by an EXIT trap,
# so an interrupted run cannot leave a mutated file on a serving box.
#
# TWO NO-OP MUTATIONS RUN TOO. A gate that reds on everything is as useless as one
# that reds on nothing, and a no-op that reds means an assertion is keyed to
# something incidental (whitespace, a comment) rather than to behaviour.
#
# COST CONTROL, STATED RATHER THAN HIDDEN: §D drives a real browser over 12
# surfaces, so source-only mutations run with GATE80_SKIP_D=1 and the four
# CSS/render mutations that MUST be seen through a browser run with GATE80_ONLY=D.
# Each mutation therefore runs the leg that owns it, and the pairing is named in
# the table below so nobody has to guess which leg caught what.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE="$REPO/tools/gates/front-banner-patreon-dark-gate.py"
SNAP="$(mktemp -d /tmp/gate80-redfirst-XXXXXX)"

INDEX="$REPO/archive-poc/web/index.php"
CSSM="$REPO/membership-pages/web/lg-shortcodes.css"
CSSP="$REPO/lg-patreon-stripe-poller/assets/lg-shortcodes.css"
CSSJ="$REPO/membership-pages/web/join.css"
CSSH="$REPO/lg-shared/site-header.css"
FILES=("$INDEX" "$CSSM" "$CSSP" "$CSSJ" "$CSSH")

for f in "${FILES[@]}"; do cp "$f" "$SNAP/$(basename "$f").$(echo "$f" | md5sum | cut -c1-6)"; done
restore() {
  for f in "${FILES[@]}"; do
    cp "$SNAP/$(basename "$f").$(echo "$f" | md5sum | cut -c1-6)" "$f"
  done
}
trap 'restore; rm -rf "$SNAP"; echo; echo "(tree restored from snapshots)"' EXIT

PASS=0; FAIL=0

# run_case <name> <expect red|green> <gate-env> <expect-substring>
run_case() {
  local name="$1" expect="$2" gateenv="$3" want="${4:-}"
  local out rc
  out="$(env $gateenv timeout 1800 python3 "$GATE" 2>&1)"; rc=$?
  restore
  if [ "$expect" = "red" ]; then
    if [ "$rc" -ne 1 ]; then
      echo "  ✗ $name — expected exit 1, got $rc"; FAIL=$((FAIL+1)); return
    fi
    if [ -n "$want" ] && ! grep -qF "$want" <<<"$out"; then
      echo "  ✗ $name — went red, but NOT for its own reason (wanted: $want)"
      grep '^FAIL' <<<"$out" | head -3 | sed 's/^/      /'
      FAIL=$((FAIL+1)); return
    fi
    echo "  ✓ $name — RED for its own reason"; PASS=$((PASS+1))
  else
    if [ "$rc" -ne 0 ]; then
      echo "  ✗ $name — a NO-OP reddened the gate (exit $rc); an assertion is keyed to something incidental"
      grep '^FAIL' <<<"$out" | head -3 | sed 's/^/      /'
      FAIL=$((FAIL+1)); return
    fi
    echo "  ✓ $name — stayed GREEN, as a no-op must"; PASS=$((PASS+1))
  fi
}

echo "=== GATE 80 red-first ==="
echo
echo "-- baseline: the unmutated tree must be GREEN, or every result below is noise --"
run_case "baseline (unmutated)" green ""

echo
echo "-- §A/§B: the flag and the render (source-only mutations, browser leg skipped) --"

# 1 — the retire never happens.
python3 - "$INDEX" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace("$signup_banner && !$signup_banner_retired","$signup_banner",1))
EOF
run_case "1 the ON guard is removed from the render site" red "GATE80_SKIP_D=1" \
  "flag ON still emits the banner"

# 2 — the flag eats the MEMBER greeting: the collateral this whole design is about.
python3 - "$INDEX" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace("<?php elseif ($is_member):","<?php elseif ($is_member && !$signup_banner_retired):",1))
EOF
run_case "2 the flag also gates the member greeting" red "GATE80_SKIP_D=1" \
  "removed the MEMBER greeting"

# 3 — fails OPEN instead of closed: an unreadable config would launch the change.
python3 - "$INDEX" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace('$on  = is_array($cfg) && !empty($cfg[\'enabled\']);','$on  = true;',1))
EOF
run_case "3 the reader fails OPEN" red "GATE80_SKIP_D=1" "fail CLOSED"

# 4 — $_SERVER dropped: a lane-preview fastcgi_param would serve the OFF path.
python3 - "$INDEX" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace("foreach ([getenv('LG_FRONT_SIGNUP_BANNER_RETIRE'), $_SERVER['LG_FRONT_SIGNUP_BANNER_RETIRE'] ?? false] as $o)",
                       "foreach ([getenv('LG_FRONT_SIGNUP_BANNER_RETIRE')] as $o)",1))
EOF
run_case "4 the reader stops consulting \$_SERVER" red "GATE80_SKIP_D=1" "no longer consults"

echo
echo "-- §C: the stylesheets (source-only) --"

# 5 — the SECOND copy goes dark-blind. The one most likely to be forgotten.
python3 - "$CSSP" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t[:t.index("/* ══")] if "/* ══" in t else t.replace("data-lguser-theme","data-NOPE"))
EOF
run_case "5 the poller copy loses its dark block" red "GATE80_SKIP_D=1" "lg-shortcodes.css"

# 6 — the light-mode pill nudge is reverted to the 2.72:1 value.
python3 - "$CSSH" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace("box-shadow: 0 0 0 1px #6b7c52 inset;","box-shadow: 0 0 0 1px var(--lg-sage) inset;",1))
EOF
run_case "6 the pill's light outline reverts to 2.72:1" red "GATE80_SKIP_D=1" "#6b7c52"

# 7 — the dark restore is dropped, so the light nudge follows the pill into dark.
python3 - "$CSSH" <<'EOF'
import sys,pathlib,re
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(re.sub(r'html\[data-lguser-theme="dark"\] \.lg-chrome__connect \{ box-shadow[^\n]*\n','',t,count=1))
EOF
run_case "7 the pill's dark outline restore is deleted" red "GATE80_SKIP_D=1" "DARK outline restore"

# 8 — the Patreon page's CTA loses its dark boundary rule.
python3 - "$CSSJ" <<'EOF'
import sys,pathlib,re
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(re.sub(r"html\[data-lguser-theme='dark'\] \.lg-join__cta \{.*?\}\n","",t,count=1,flags=re.S))
EOF
run_case "8 join.css loses the .lg-join__cta dark rule" red "GATE80_SKIP_D=1" "1.05:1 boundary"

echo
echo "-- §D: the browser leg (these MUST be seen through a real render) --"

# 9 — the headline defect, restored: the buttons go white-on-white again.
python3 - "$CSSM" <<'EOF'
import sys,pathlib,re
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(re.sub(r"html\[data-lguser-theme='dark'\] \.lg-join__buy \{.*?\}\n","",t,count=1,flags=re.S))
EOF
run_case "9 the Subscribe buttons lose their dark fill (1.25:1 returns)" red "GATE80_ONLY=D" \
  "lg-join__buy"

# 10 — the boundary regression THIS GATE ALREADY CAUGHT ONCE, in my own first
# pass: a border too dark to separate the control from its card.
python3 - "$CSSM" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace("    border-color: #767c76;","    border-color: #3a4148;",1))
EOF
run_case "10 the button border drops back to 1.56:1" red "GATE80_ONLY=D" "no visible boundary"

# 11 — the ink the DARKENED CARD exposed. Not in the original findings; it only
# exists because the fix above it landed, which is why it is worth a mutation.
python3 - "$CSSM" <<'EOF'
import sys,pathlib,re
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(re.sub(r"html\[data-lguser-theme='dark'\] \.lg-join__feature\s*\{[^}]*\}\n","",t,count=1))
EOF
run_case "11 the feature list loses its dark ink (#444 on a dark card)" red "GATE80_ONLY=D" \
  "lg-join__feature"

echo
echo "-- no-ops: these must NOT redden anything --"

# 12 — pure whitespace + a comment. Touches no behaviour at all. This is the one
# that matters: mutation 7 of gate 79 was exactly this shape and was the only
# thing that caught a real defect in a lane's OFF path.
python3 - "$INDEX" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace("function lg_front_signup_banner_retired(): bool {",
                       "function lg_front_signup_banner_retired(): bool {\n    /* gate-80 no-op probe */",1))
EOF
run_case "12 a comment is added to the reader (no-op)" green "GATE80_SKIP_D=1"

# 13 — a CSS comment reflow. Proves §C matches RULES, not prose: if an assertion
# were grepping for a word that lives in a comment, this would move it.
python3 - "$CSSM" <<'EOF'
import sys,pathlib
p=pathlib.Path(sys.argv[1]);t=p.read_text()
p.write_text(t.replace("/* ── DARK: the tier card.","/* ── DARK: the tier card (reflowed comment, no-op).",1))
EOF
run_case "13 a CSS comment is reworded (no-op)" green "GATE80_SKIP_D=1"

echo
echo "=== $PASS passed, $FAIL failed ==="
[ "$FAIL" -eq 0 ] || exit 1
exit 0
