#!/usr/bin/env bash
# RED-FIRST for GATE 74. Each mutation must turn the gate RED *for its own reason*,
# and a NO-OP mutation must leave it GREEN — a gate that reds on everything asserts
# nothing, and one that greens on everything asserts less.
#
# ⚠️ MUTATES A SNAPSHOT COPY, NEVER THE WORKING TREE, and never `git checkout --`:
# checkout-from-HEAD wipes uncommitted work under test, which once turned one harness
# bug into ten false "the assertion is decoration" verdicts.
set -uo pipefail
SRC="$(cd "$(dirname "$0")/../.." && pwd)"
PASS=0; FAIL=0

snapshot() {
  local d; d="$(mktemp -d)"
  mkdir -p "$d/tools/gates" "$d/platform/config" "$d/platform/mu-plugins" \
           "$d/bb-mirror/web" "$d/webroot" "$d/docs"
  cp "$SRC/tools/gates/categorize-last-gate.py"                    "$d/tools/gates/"
  cp "$SRC/platform/config/composer-categorize-last.php"           "$d/platform/config/"
  cp "$SRC/platform/mu-plugins/lg-composer-categorize-last.php"    "$d/platform/mu-plugins/"
  cp "$SRC/bb-mirror/config.php"                                   "$d/bb-mirror/"
  cp "$SRC/bb-mirror/web/_chrome.php" "$SRC/bb-mirror/web/forums.js" \
     "$SRC/bb-mirror/web/forums.css"                               "$d/bb-mirror/web/"
  cp "$SRC/webroot/hub-polish.js"                                  "$d/webroot/"
  cp "$SRC/docs/FLAGS.md"                                          "$d/docs/"
  echo "$d"
}

# check <name> <expect red|green> <substring the RED must mention> <mutation cmd...>
check() {
  local name="$1" expect="$2" needle="$3"; shift 3
  local d; d="$(snapshot)"
  ( cd "$d" && "$@" ) >/dev/null 2>&1
  local out rc
  out="$(python3 "$d/tools/gates/categorize-last-gate.py" 2>&1)"; rc=$?
  local got; [ "$rc" -eq 0 ] && got=green || got=red
  if [ "$got" != "$expect" ]; then
    printf '  ✘ %-38s expected %-5s got %s\n' "$name" "$expect" "$got"; FAIL=$((FAIL+1))
  elif [ "$expect" = red ] && ! grep -qF "$needle" <<<"$out"; then
    printf '  ✘ %-38s RED but for the WRONG reason (no %%s)\n' "$name"
    printf '     wanted mention of: %s\n' "$needle"
    printf '     got: %s\n' "$(grep FAIL: <<<"$out" | head -2)"; FAIL=$((FAIL+1))
  else
    printf '  ✔ %-38s %s\n' "$name" "$expect"; PASS=$((PASS+1))
  fi
  rm -rf "$d"
}

echo "RED-FIRST — gate 74"

check "baseline, untouched"        green "" true

check "no-op whitespace mutation"  green "" \
  bash -c 'printf "\n" >> bb-mirror/web/forums.css'

check "taxonomy attach un-gated"   red  "silently extend the taxonomy" \
  bash -c "perl -0pi -e 's/if \(!lg_ccl_enabled\(\)\) return;\n(\s+)if \(!taxonomy_exists/\$1if (!taxonomy_exists/' platform/mu-plugins/lg-composer-categorize-last.php"

check "reply loop stops bumping"   red  "never reaches the forum mirror" \
  bash -c "perl -0pi -e 's/lg_ccl_touch_modified\(\(int\)\\\$rid\);//' platform/mu-plugins/lg-composer-categorize-last.php"

check "non-postable lists disagree" red "DISAGREE" \
  bash -c "perl -0pi -e 's/return \[3876, 67251\];/return [3876];/' platform/mu-plugins/lg-composer-categorize-last.php"

check "mobile guard removed"       red  "platform most members post from" \
  bash -c "perl -0pi -e 's/fbcCcl/fbcXXXX/g' webroot/hub-polish.js"

check "landing forum hardcoded"    red  "hardcodes the landing forum id" \
  bash -c "perl -0pi -e 's/lg_ccl_default_forum_id\(\);/73564;/' bb-mirror/web/_chrome.php"

check "picker z-index too low"     red  "invisible on phones" \
  bash -c "perl -0pi -e 's/(\.lgtp \{ position: fixed; inset: 0; z-index: )2147483600/\${1}100000/' bb-mirror/web/forums.css"

check "OFF-path markup deleted"    red  "no longer verbatim" \
  bash -c "perl -0pi -e 's/\(pick one\)/(choose)/' bb-mirror/web/_chrome.php"

check "Skip button orphaned"       red  "never reaches the DOM" \
  bash -c "perl -0pi -e 's/footBtns\.appendChild\(btnSkip\);//' bb-mirror/web/forums.js"

check "FLAGS.md row removed"       red  "no row for this flag" \
  bash -c "perl -0pi -e 's/^.*composer-categorize-last.*\$//m' docs/FLAGS.md"

echo
echo "red-first: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] || exit 1
