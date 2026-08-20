#!/bin/bash
# header-join-redfirst.sh — prove GATE 79 can actually FAIL, and fail for the
# reason it claims.
#
# A gate nobody has watched go red is decoration. This applies one mutation at a
# time and requires the gate to redden a NAMED assertion — not merely to go red,
# which any broken edit achieves (feedback-red-first-that-stays-green: four of
# the six cases in this repo were assertions matching a string that also lived
# in prose, and one blamed a working page).
#
# ⚠️ SNAPSHOT, NEVER `git checkout --`. Every mutated file is byte-copied to a
# temp dir first and restored from that copy, because checkout-from-HEAD wipes
# uncommitted work under test and once turned one harness bug into ten false
# "the assertion is decoration" verdicts
# (feedback-mutation-harness-must-snapshot-not-checkout). The trap below
# restores on ANY exit, including Ctrl-C.
#
# Runs the gate with --no-browser: every mutation here targets §A/§B/§C, which
# are pure source + php. No DB, no browser, no network, so this cannot flake
# under load and is safe to run while the fleet is busy.
#
# Usage: bash tools/gates/header-join-redfirst.sh
# Exit:  0 every mutation reddened its own assertion and both no-ops stayed
#        green; 1 otherwise.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE="$REPO/tools/gates/header-join-gate.py"
SNAP="$(mktemp -d -t hj-redfirst-XXXXXX)"

HEADER="$REPO/lg-shared/site-header.php"
CONFIG="$REPO/platform/config/header-join-stripe.php"
BOTTOM="$REPO/webroot/bottom-nav.js"
FLAGS="$REPO/docs/FLAGS.md"
FILES=("$HEADER" "$CONFIG" "$BOTTOM" "$FLAGS")

restore() {
    for f in "${FILES[@]}"; do
        cp -p "$SNAP/$(basename "$f")" "$f" 2>/dev/null
    done
}
cleanup() { restore; rm -rf "$SNAP"; }
trap cleanup EXIT INT TERM

for f in "${FILES[@]}"; do cp -p "$f" "$SNAP/$(basename "$f")"; done
echo "snapshot: $SNAP"
echo

pass=0; fail=0
declare -a REPORT

run_gate() { python3 "$GATE" --no-browser 2>&1; }

# A mutation is only interesting if the gate FAILS the assertion whose name we
# name. "The suite went red" is not evidence about a particular assertion.
mutate() {
    local name="$1" want="$2" script="$3"
    python3 - "$script" <<'PY' || { echo "  !! mutation script errored"; }
import sys, re, io
exec(sys.argv[1])
PY
    local out; out="$(run_gate)"
    local line; line="$(printf '%s\n' "$out" | grep -F "FAIL" | grep -F "$want" | head -1)"
    if [ -n "$line" ]; then
        pass=$((pass+1)); REPORT+=("RED-OK   $name")
        echo "  RED    $name"
        echo "         -> ${line#"${line%%[![:space:]]*}"}"
    else
        fail=$((fail+1)); REPORT+=("MISSED   $name  (wanted a FAIL naming: $want)")
        echo "  MISSED $name"
        echo "         wanted a FAIL naming: $want"
        printf '%s\n' "$out" | grep -F "FAIL" | head -3 | sed 's/^/         got: /'
        printf '%s\n' "$out" | tail -2 | sed 's/^/         /'
    fi
    restore
}

# A no-op must redden NOTHING. Without these the whole exercise proves only that
# the gate is capable of being red, which an always-red gate also satisfies.
noop() {
    local name="$1" script="$2"
    python3 - "$script" <<'PY' || { echo "  !! mutation script errored"; }
import sys
exec(sys.argv[1])
PY
    local out; out="$(run_gate)"
    local tail; tail="$(printf '%s\n' "$out" | grep -E '^\s+[0-9]+ passed, [0-9]+ failed' | tail -1)"
    if printf '%s\n' "$out" | grep -q "FAIL"; then
        fail=$((fail+1)); REPORT+=("NOOP-RED $name  ($tail)")
        echo "  RED    $name   <-- a NO-OP must not redden anything"
        printf '%s\n' "$out" | grep -F "FAIL" | head -3 | sed 's/^/         /'
    else
        pass=$((pass+1)); REPORT+=("NOOP-OK  $name  ($tail)")
        echo "  GREEN  $name  ($tail)"
    fi
    restore
}

echo "── BASELINE ─────────────────────────────────────────────────────────────"
base="$(run_gate)"
if printf '%s\n' "$base" | grep -q "FAIL"; then
    echo "  the UNMUTATED tree is already red — fix that before reading anything below"
    printf '%s\n' "$base" | grep -F "FAIL" | head -8 | sed 's/^/    /'
    exit 1
fi
echo "  GREEN  $(printf '%s\n' "$base" | grep -E '^\s+[0-9]+ passed' | tail -1 | sed 's/^ *//')"
echo

echo "── MUTATIONS: each must redden its OWN named assertion ───────────────────"

mutate "1  the ON href is hardcoded into the anchor" \
       "its href is resolved at render time" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""href="<?= $h($join_href) ?>\"""", """href="/lgjoin/\"""")
open(p,"w").write(s)'

mutate "2  the reader forgets \$_SERVER (fastcgi_param preview serves OFF)" \
       "read from getenv() AND \$_SERVER" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""[getenv(\x27LG_HEADER_JOIN_STRIPE\x27), $_SERVER[\x27LG_HEADER_JOIN_STRIPE\x27] ?? false]""",
            """[getenv(\x27LG_HEADER_JOIN_STRIPE\x27)]""")
open(p,"w").write(s)'

mutate "3  the .local.php box override is dropped" \
       "honours the gitignored .local.php box override" '
import re
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("header-join-stripe.local.php", "header-join-stripe.NOPE.php")
open(p,"w").write(s)'

mutate "4  the .local override wins on any TRUTHY value, not === true" \
       "wins only on an EXPLICIT boolean true" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""$on = ($local[\x27enabled\x27] === true);""",
            """$on = (bool)$local[\x27enabled\x27];""")
open(p,"w").write(s)'

mutate "5  the header opens our OWN page in a new tab (the PWA eject)" \
       "does NOT open a new tab" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""<?= $join_external ? \x27 target="_blank" rel="noopener"\x27 : \x27\x27 ?>""",
            """ target="_blank" rel="noopener\"""")
open(p,"w").write(s)'

mutate "6  the OFF href changes by ONE character" \
       "off: Join goes to patreon.com" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("https://www.patreon.com/c/theloothgroup/membership",
            "https://www.patreon.com/c/theloothgroup/memberships")
open(p,"w").write(s)'

mutate "7  the OFF path gains ONE blank line (byte-identity, not behaviour)" \
       "byte-identical to origin/main" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""        <a class="lg-chrome__connect\"""",
            """\n        <a class="lg-chrome__connect\"""")
open(p,"w").write(s)'

mutate "8  bottom-nav goes back to an unconditional target=_blank" \
       "target=_blank only for an EXTERNAL join href" '
p="'"$BOTTOM"'"; s=open(p).read()
s=s.replace("""    if (/^https?:\\/\\//i.test(joinHref)) { joinRow.target = \x27_blank\x27; joinRow.rel = \x27noopener\x27; }""",
            """    joinRow.target = \x27_blank\x27; joinRow.rel = \x27noopener\x27;""")
open(p,"w").write(s)'

mutate "9  bottom-nav stops reading the header (a second, drifting source)" \
       "anon sheet reads the header" '
p="'"$BOTTOM"'"; s=open(p).read()
s=s.replace("""hdrHref(\x27.lg-chrome__join\x27, """, """(function(f){return f;})(""")
open(p,"w").write(s)'

mutate "10 the tracked config defaults ON when it cannot be read" \
       "falls back to today" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""$on  = is_array($cfg) && !empty($cfg[\x27enabled\x27]);""",
            """$on  = !is_array($cfg) || !empty($cfg[\x27enabled\x27]);""")
open(p,"w").write(s)'

mutate "11 the FLAGS.md row is deleted" \
       "docs/FLAGS.md carries a row for header-join-stripe" '
p="'"$FLAGS"'"; s=open(p).read()
s="\n".join(l for l in s.split("\n") if "header-join-stripe" not in l)
open(p,"w").write(s)'

mutate "12 the FLAGS row stops naming the lgms_stripe_pages_live coupling" \
       "row names the lgms_stripe_pages_live coupling" '
p="'"$FLAGS"'"; s=open(p).read()
s=s.replace("lgms_stripe_pages_live", "some_other_option")
open(p,"w").write(s)'

echo
echo "── NO-OPS: each must redden NOTHING ──────────────────────────────────────"

noop "A  rename the reader local variable (\$on -> \$stripe_join)" '
import re
p="'"$HEADER"'"; s=open(p).read()
head, sep, rest = s.partition("function lg_shared_header_join_stripe_enabled(): bool")
body, sep2, tail = rest.partition("\n}\n}")
body = re.sub(r"\$on\b", "$stripe_join", body)
open(p,"w").write(head + sep + body + sep2 + tail)'

noop "B  reflow the config docblock (prose only, no code)" '
p="'"$CONFIG"'"; s=open(p).read()
s=s.replace(" * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────",
            " * ── WHAT IT SWITCHES (reflowed by the red-first no-op) ──────────────────────")
open(p,"w").write(s)'

echo
echo "══ RESULT ════════════════════════════════════════════════════════════════"
for r in "${REPORT[@]}"; do echo "  $r"; done
echo
echo "  $pass as expected, $fail not"
if [ "$fail" -ne 0 ]; then
    echo "  ############ RED-FIRST INCOMPLETE — an assertion did not behave as claimed"
    exit 1
fi
echo "  ############ RED-FIRST COMPLETE — every assertion proven able to fail,"
echo "  ############ for its own stated reason, and no-ops proven inert."
