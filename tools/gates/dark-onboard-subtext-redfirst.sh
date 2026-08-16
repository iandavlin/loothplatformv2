#!/usr/bin/env bash
# RED-FIRST for GATE 53. Every assertion is broken on purpose and must produce a
# RED; a mutation that stays green is a decoration, not a check.
#
# SNAPSHOTS the target and restores from the snapshot — never `git checkout --`,
# which would wipe uncommitted work under test (that mistake once turned one
# harness bug into ten false "the assertion is decoration" verdicts).
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
TARGET="$HERE/../../lg-patreon-stripe-poller/lg-patreon-onboard.php"
GATE="$HERE/dark-onboard-subtext-gate.py"
SNAP="$(mktemp)"
cp "$TARGET" "$SNAP"
restore() { cp "$SNAP" "$TARGET"; }
trap 'restore; rm -f "$SNAP"' EXIT

fails=0
# A RED IS NOT ENOUGH — IT MUST BE RED FOR THE REASON CLAIMED.
# Pattern reported across four gates on this box (2026-08-16): an assertion goes
# red for something other than the property it names — a string that also lives
# in the CSS, or in a comment, or a downstream failure standing in for the fence
# you removed. "Something went red" then reads as proof of a check that is not
# actually working. So every mutation below names the phrase its own FAIL line
# must contain, and a red that does not say it counts as a failure.
expect_red() {
  local name="$1" want="$2"
  local out rc
  out="$(python3 "$GATE" 2>&1)"
  rc=$?
  if [ "$rc" -ne 1 ]; then
    echo "  FAIL mutation did NOT redden the gate (exit $rc): $name"
    fails=$((fails+1))
  elif ! printf '%s' "$out" | grep -qi -- "$want"; then
    echo "  FAIL reddened for the WRONG REASON: $name"
    echo "       expected a FAIL naming: $want"
    echo "       got: $(printf '%s' "$out" | grep -i '^  FAIL' | head -2 | tr '\n' ' ')"
    fails=$((fails+1))
  else
    echo "  ok   RED, and for the stated reason: $name"
  fi
  restore
}

echo "=== gate 53 red-first ==="
python3 "$GATE" >/dev/null 2>&1 || { echo "  FAIL baseline is not green — fix that first"; exit 1; }
echo "  ok   baseline green"

# 1. dark ink that does not clear AA
sed -i "s/color: #a8ada6/color: #666666/" "$TARGET"
expect_red "ON emits a sub-AA dark ink (#666 on #1b1e21 = 2.92:1)" "under the 4.5:1 AA text bar"

# 2. OFF stops being a no-op
sed -i "s/        : '';/        : \"        html[data-lguser-theme='dark'] .lgpo-subtext { color: #a8ada6; }\\\\n\";/" "$TARGET"
expect_red "OFF path emits the dark rule" "OFF must be a byte-identical no-op"

# 3. the dark rule is removed altogether
sed -i "s|html\[data-lguser-theme='dark'\] .lgpo-subtext { color: #a8ada6; }||" "$TARGET"
expect_red "dark rule deleted entirely" "does not emit"

# 4. the LIGHT value is changed out from under a passing surface
sed -i "s/font-size: 0.9em; color: #666;/font-size: 0.9em; color: #999;/" "$TARGET"
expect_red "LIGHT .lgpo-subtext colour changed" "LIGHT .lgpo-subtext colour is no longer #666"

# 5. the flag loses its $_SERVER read (fastcgi_param preview would lie)
sed -i "s/\$_SERVER\['LG_DARK_ONBOARD_SUBTEXT_FIX'\]/\$_SERVER['SOMETHING_ELSE']/g" "$TARGET"
expect_red "flag no longer reads \$_SERVER" "no longer reads \$_SERVER"

if [ "$fails" -ne 0 ]; then echo "RED-FIRST FAILED: $fails mutation(s) did not redden the gate"; exit 1; fi
echo "red-first: all 5 mutations reddened the gate"
