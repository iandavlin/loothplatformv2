#!/usr/bin/env bash
# Red-first for back-pill-navigates-gate. An assertion nobody has broken is
# decoration — gate 19's inversion pass found three real bugs in gate 19 itself.
#
# SNAPSHOT AND RESTORE BY COPY, never `git checkout --`: checkout-from-HEAD wipes
# uncommitted work under test.
set -uo pipefail
cd "$(dirname "$0")/../.."

GATE="python3 tools/gates/back-pill-navigates-gate.py"
FILES=(webroot/bottom-nav.js bb-mirror/web/_chrome.php platform/config/back-pill.php)
SNAP=$(mktemp -d /tmp/bp-redfirst-XXXX)
for f in "${FILES[@]}"; do mkdir -p "$SNAP/$(dirname "$f")"; cp -p "$f" "$SNAP/$f"; done
restore() { for f in "${FILES[@]}"; do cp -p "$SNAP/$f" "$f"; done; }
trap 'restore; rm -rf "$SNAP"' EXIT

pass=0; fail=0
fingerprint() { md5sum "${FILES[@]}" 2>/dev/null | md5sum; }

try() {   # try <name> <want-rc> <expect-substring> <mutation...>
  local name="$1" want="$2" grepfor="$3"; shift 3
  local before after
  before=$(fingerprint); "$@" >/dev/null 2>&1; after=$(fingerprint)
  # A mutation that changed nothing proves nothing, and reads as "this assertion is
  # decoration" — a false accusation against working code.
  if [ "$before" = "$after" ]; then
    echo "  NO-OP MUTATION  $name — files unchanged. Fix the mutation, not the gate."
    fail=$((fail+1)); restore; return
  fi
  if ! node --check webroot/bottom-nav.js >/dev/null 2>&1; then
    echo "  INVALID MUTATION  $name — bottom-nav.js no longer parses; the gate would"
    echo "                    be answering a syntax error, not the defect."
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
  echo "  red ✔  $name"; pass=$((pass+1))
}

echo "=== red-first: back-pill-navigates-gate ==="

try "the control is not built at all" 1 "no buildBackPill" \
  bash -c "perl -0pi -e 's/function buildBackPill\\(\\)/function buildBackPillX()/' webroot/bottom-nav.js"

try "OFF stops being a no-op (flag guard removed)" 1 "does not return early" \
  bash -c "perl -0pi -e 's/if \\(!window\\.LG_BACK_PILL\\) return;.*\\n//' webroot/bottom-nav.js"

try "_chrome.php emits the bit as false instead of omitting" 1 "byte-identical" \
  bash -c "perl -0pi -e 's/window\\.LG_BACK_PILL = true;/window.LG_BACK_PILL = false;/' bb-mirror/web/_chrome.php"

try "_chrome.php stops emitting the bit at all" 1 "never emits" \
  bash -c "perl -0pi -e 's/LG_BACK_PILL/LG_NOPE_PILL/g' bb-mirror/web/_chrome.php"

# THE POINT OF THIS GATE: it renders, and goes nowhere.
try "the pill's href becomes '#' (renders, navigates nowhere)" 1 "renders, does nothing" \
  bash -c "perl -0pi -e \"s/a\\.href = '\\/hub\\/';/a.href = '#';/\" webroot/bottom-nav.js"

try "the pill points somewhere that is not the hub" 1 "not the hub" \
  bash -c "perl -0pi -e \"s/a\\.href = '\\/hub\\/';/a.href = '\\/events\\/';/\" webroot/bottom-nav.js"

try "it would be offered ON the hub too (path guard removed)" 1 "already on the Hub" \
  bash -c "perl -0pi -e 's{if \\(!/\\^\\\\/hub\\\\/\\.\\+/\\.test\\(path\\)\\) return;}{}' webroot/bottom-nav.js"

try "it hides and never comes back" 1 "hides and never returns" \
  bash -c "perl -0pi -e \"s/else if \\(y < last - 6\\) a\\.classList\\.remove\\('is-away'\\);//\" webroot/bottom-nav.js"

try "the hidden state stays tappable (invisible target)" 1 "INVISIBLE tap target" \
  bash -c "perl -0pi -e \"s/'pointer-events:none}' \\+//\" webroot/bottom-nav.js"

try "config 'enabled' becomes unreadable" 2 "must READ" \
  bash -c "perl -0pi -e \"s/'enabled' => false,/'enabled' => SOME_CONSTANT,/\" platform/config/back-pill.php"

echo
echo "=== $pass broke as claimed, $fail did not ==="
[ "$fail" = 0 ] || exit 1
