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
       "href resolved at render time" '
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
s=s.replace("""$on = $strict ? ($cfg[\x27enabled\x27] === true) : !empty($cfg[\x27enabled\x27]);""",
            """$on = !empty($cfg[\x27enabled\x27]);""")
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
s=s.replace("""    if ($state === null) $state = \x27off\x27;""",
            """    if ($state === null) $state = \x27on\x27;""")
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


# ── #170: the third state ────────────────────────────────────────────────────

mutate "13 the tester pill escapes allowlist and renders in ON too" \
       "gives a signed-in tester no pill" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""$join_pill_authed = ($join_state === \x27allowlist\x27 && $stripe_tester);""",
            """$join_pill_authed = ($join_state !== \x27off\x27 && $stripe_tester);""")
open(p,"w").write(s)'

mutate "14 the pill ignores the cohort — EVERY signed-in member gets one" \
       "a signed-in member NOT on the list gets no Join at all" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""$join_pill_authed = ($join_state === \x27allowlist\x27 && $stripe_tester);""",
            """$join_pill_authed = ($join_state === \x27allowlist\x27);""")
open(p,"w").write(s)'

# THE ONE THAT MATTERS MOST. A per-viewer decision leaking into the logged-out
# render is the difference between a soft launch and an announcement.
mutate "15 the ANON page leaks /lgjoin/ in allowlist (the caching law breaks)" \
       "THE CACHING LAW" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""|| ($join_state === \x27allowlist\x27 && $stripe_tester);""",
            """|| ($join_state === \x27allowlist\x27);""")
open(p,"w").write(s)'

# THE VERSION OF THIS ISSUE THAT WOULD HAVE SHIPPED without the measurement in
# the plan: the href logic is right, the state exists, and the control the test
# user was promised renders for nobody.
mutate "16 the tester pill never renders — the naive, vacuous implementation" \
       "allowlist ACTUALLY DIFFERS from off for a tester" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""$join_pill_authed = ($join_state === \x27allowlist\x27 && $stripe_tester);""",
            """$join_pill_authed = (false && $join_state === \x27allowlist\x27 && $stripe_tester);""")
open(p,"w").write(s)'

mutate "17 the legacy enabled key is tidied away (dev2 silently reverts)" \
       "ACTUAL .local.php" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""        if (array_key_exists(\x27enabled\x27, $cfg)) {""",
            """        if (false) {""")
open(p,"w").write(s)'

mutate "18 an unrecognised state word falls OPEN instead of closed" \
       "unrecognised state word falls CLOSED" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""return in_array($s, $valid, true) ? $s : \x27off\x27;""",
            """return in_array($s, $valid, true) ? $s : \x27on\x27;""")
open(p,"w").write(s)'

mutate "19 the header grows its OWN cohort list (a second definition)" \
       "defines NO second cohort list" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""$stripe_tester = ($caps[\x27stripe_testgroup\x27] ?? false) === true;""",
            """$stripe_tester = in_array(1, (array) get_option(\x27lgms_stripe_lifecycle_allowlist\x27), true);""")
open(p,"w").write(s)'

mutate "20 bottom-nav drops the tester row (no phone door at all)" \
       "tester row EXISTS only when the header drew a pill" '
p="'"$BOTTOM"'"; s=open(p).read()
s=s.replace("""    if (testerJoinHref) {""", """    if (false) {""")
open(p,"w").write(s)'

# THE DEFECT THIS LANE ACTUALLY MADE, kept as a mutation because it is the one
# no reviewer would catch by eye: an indented <?php if ?> tag emits its own
# leading spaces whether or not the branch is taken, adding 9 bytes to EVERY
# signed-in render in EVERY state.
mutate "21 the pill block is re-indented (9 stray bytes in every authed render)" \
       "authed (not listed)" '
p="'"$HEADER"'"; s=open(p).read()
s=s.replace("""\n<?php if ($join_pill_authed):""",
            """\n        <?php if ($join_pill_authed):""")
open(p,"w").write(s)'

echo
echo "── NO-OPS: each must redden NOTHING ──────────────────────────────────────"

noop "A  rename the reader closure (\$resolve -> \$readConfig)" '
import re
p="'"$HEADER"'"; s=open(p).read()
assert "$resolve" in s, "no-op A has no target — it would prove nothing"
s = re.sub(r"\$resolve\b", "$readConfig", s)
open(p,"w").write(s)'

noop "B  reflow the config docblock (prose only, no code)" '
p="'"$CONFIG"'"; s=open(p).read()
assert " * ── WHAT IT SWITCHES: THREE STATES (#170) ────────────────────────────────────" in s, \
    "no-op B has no target — it would prove nothing"
s=s.replace(" * ── WHAT IT SWITCHES: THREE STATES (#170) ────────────────────────────────────",
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
