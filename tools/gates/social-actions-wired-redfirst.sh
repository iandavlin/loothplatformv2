#!/usr/bin/env bash
# Red-first for social-actions-wired-gate. An assertion nobody has broken is
# decoration — twice on 7/31 a gate stayed green through the mutation it claimed
# to catch, once because it was matching its own comment prose.
#
# SNAPSHOT AND RESTORE BY COPY, never `git checkout --`: checkout-from-HEAD wipes
# uncommitted work under test and turned one harness bug into ten false verdicts.
set -uo pipefail
cd "$(dirname "$0")/../.."

GATE="python3 tools/gates/social-actions-wired-gate.py"
FILES=(profile-app/src/Social.php webroot/lg-social-actions.js webroot/profile-sheet.js
       platform/config/social-actions.php)
SNAP=$(mktemp -d /tmp/sa-redfirst-XXXX)
for f in "${FILES[@]}"; do mkdir -p "$SNAP/$(dirname "$f")"; cp -p "$f" "$SNAP/$f"; done
restore() { for f in "${FILES[@]}"; do cp -p "$SNAP/$f" "$f"; done; }
trap 'restore; rm -rf "$SNAP"' EXIT

pass=0; fail=0

# expect: 1 = RED, 2 = CANNOT RUN
fingerprint() { md5sum "${FILES[@]}" 2>/dev/null | md5sum; }

try() {
  local name="$1" want="$2" grepfor="$3"; shift 3
  local before after
  before=$(fingerprint)
  "$@" >/dev/null 2>&1
  after=$(fingerprint)
  # A MUTATION THAT CHANGED NOTHING MUST FAIL LOUD. A perl one-liner whose pattern
  # has drifted silently does nothing, the gate stays green for the correct reason,
  # and the run reads as "this assertion is decoration" — which is a false accusation
  # against a working gate. Caught exactly that here: the call site is indented 8
  # spaces and the pattern said 4.
  if [ "$before" = "$after" ]; then
    echo "  NO-OP MUTATION  $name — the files are unchanged, so this proved NOTHING."
    echo "                  Fix the mutation, not the gate."
    fail=$((fail+1)); restore; return
  fi
  # A mutation must stay VALID, or every gate reports CANNOT RUN and we learn nothing
  # about the assertion we meant to test.
  if [[ " $* " == *"Social.php"* ]] && ! php -l profile-app/src/Social.php >/dev/null 2>&1; then
    echo "  INVALID MUTATION  $name — Social.php no longer parses; the gate would say"
    echo "                    CANNOT RUN for a syntax error, not for the defect."
    fail=$((fail+1)); restore; return
  fi
  out=$($GATE 2>&1); rc=$?
  restore
  if [ "$rc" != "$want" ]; then
    echo "  STAYED-$( [ $rc = 0 ] && echo GREEN || echo "rc$rc" )  $name  (wanted rc=$want)"
    fail=$((fail+1)); return
  fi
  if ! grep -qiF -- "$grepfor" <<<"$out"; then
    echo "  WRONG-REASON  $name — rc=$rc but nothing matching '$grepfor'"
    echo "$out" | grep -E "RED|DEAD" | sed 's/^/      /'
    fail=$((fail+1)); return
  fi
  echo "  red ✔  $name"
  pass=$((pass+1))
}

echo "=== red-first: social-actions-wired-gate ==="

# A — the drift check is the one making two copies of the wiring safe.
try "drift: change one byte of the shipped asset" 1 "DRIFT" \
  bash -c "printf '\n/*x*/\n' >> webroot/lg-social-actions.js"

try "drift: change the inline heredoc instead" 1 "DRIFT" \
  bash -c "perl -0pi -e \"s/var API = '\\/profile-api\\/v0';/var API = '\\/profile-api\\/v1';/\" profile-app/src/Social.php"

# B — OFF must stay a no-op.
try "OFF stamps anyway (OFF stops being a no-op)" 1 "OFF is not a no-op" \
  bash -c "perl -0pi -e 's/\\\$stamp = !empty\\(\\\$cfg\\[.enabled.\\]\\)/\\\$stamp = true \\|\\| !empty(\\\$cfg[\"enabled\"])/' profile-app/src/Social.php"

# C — ON must SWAP the source, not add one.
try "ON emits the stamp AND the inline block" 1 "two sources" \
  bash -c "perl -0pi -e 's/\\\$cfg = self::cfg\\(\\);\\n        if \\(!empty\\(\\\$cfg\\[.enabled.\\]\\)\\)/\\\$cfg = self::cfg();\n        if (false)/' profile-app/src/Social.php"

try "ON stops stamping (tray cannot find the wiring)" 1 "does not stamp" \
  bash -c "perl -0pi -e \"s/ data-lg-social-src=/ data-lg-nope=/\" profile-app/src/Social.php"

# D — a stamp pointing at nothing is worse than the bug.
try "the shipped asset is deleted" 1 "MISSING" \
  rm -f webroot/lg-social-actions.js

try "the stamp points somewhere the repo does not ship" 1 "404" \
  bash -c "perl -0pi -e \"s|'src' => '/lg-social-actions.js'|'src' => '/nope.js'|\" platform/config/social-actions.php"

# E — the client half.
try "loader no longer gated on the stamp" 1 "does not SELECT on [data-lg-social-src]" \
  bash -c "perl -0pi -e \"s/var widget = prof.querySelector\\('\\[data-lg-social-src\\]'\\);/var widget = prof.querySelector('.lg-social-actions');/\" webroot/profile-sheet.js"

try "loader drops the same-origin constraint" 1 "same-origin" \
  bash -c "perl -0pi -e \"s/if \\(src.charAt\\(0\\) !== '\\/' \\|\\| src.charAt\\(1\\) === '\\/'\\) return;//\" webroot/profile-sheet.js"

try "loader is defined but never called" 1 "never called" \
  bash -c "perl -0pi -e 's/^\\s*initSocialActions\\(prof\\);\\n//m' webroot/profile-sheet.js"

try "loader drops the already-wired check" 1 "second copy" \
  bash -c "perl -0pi -e 's/if \\(window.__lgSocialWired \\|\\| socialLoading\\) return;/if (socialLoading) return;/' webroot/profile-sheet.js"

# F — the gate must READ the flag, never assume it.
try "config 'enabled' becomes unreadable" 2 "must READ the flag" \
  bash -c "perl -0pi -e \"s/'enabled' => false,/'enabled' => THE_CONSTANT,/\" platform/config/social-actions.php"

# LIVENESS — an absence assertion on an empty widget is vacuous.
echo "  --- liveness (the gate must refuse a fixture that renders nothing) ---"
out=$(LG_SA_SUBJECT=4e9620c9-42eb-59ca-b350-85dceca5e801 $GATE 2>&1); rc=$?
if [ "$rc" = 2 ] && grep -qi "vacuously true" <<<"$out"; then
  echo "  red ✔  own-profile fixture (widget renders '') is refused, not passed"
  pass=$((pass+1))
else
  echo "  STAYED-rc$rc  own-profile fixture was NOT refused — every absence assertion"
  echo "               in this gate would be vacuously true against it"
  fail=$((fail+1))
fi

echo
echo "=== $pass broke as claimed, $fail did not ==="
[ "$fail" = 0 ] || exit 1
