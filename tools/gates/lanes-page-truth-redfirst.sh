#!/usr/bin/env bash
# red-first for GATE 77 — proof the gate is neither always-green nor always-red.
#
# Each mutation breaks ONE behaviour and must redden the assertion that NAMES
# that behaviour. A mutation that reddens something else is as much a finding as
# one that reddens nothing: it means the assertion is measuring the wrong thing.
#
# ⚠ EVERY MUTATION IS APPLIED TO A SNAPSHOT COPY OF THE FILES, NEVER TO THE TREE
# UNDER TEST, and never with `git checkout --` to undo. Checkout-from-HEAD wipes
# uncommitted work and once turned one harness bug into ten false "the assertion
# is decoration" verdicts (feedback-mutation-harness-must-snapshot-not-checkout).
#
# Two NO-OP mutations are included and must stay GREEN. Without them a gate that
# reddens on any edit at all would look perfectly discriminating here.
#
#   bash tools/gates/lanes-page-truth-redfirst.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/gate77-redfirst-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
pass=0; fail=0

snapshot() {   # snapshot <dir>
    local d="$1"
    mkdir -p "$d/tools/gates" "$d/tools/lanes" "$d/webroot"
    cp "$ROOT/tools/lanes-status.sh"      "$d/tools/"
    cp "$ROOT/tools/lanes-page.py"        "$d/tools/"
    cp "$ROOT/tools/lanes-poke-worker.sh" "$d/tools/"
    cp "$ROOT/tools/lanes/stall-watchdog.sh" "$d/tools/lanes/"
    cp "$ROOT/webroot/lanes-poke.php"     "$d/webroot/"
    cp "$ROOT/tools/gates/lanes-page-truth-gate.py" "$d/tools/gates/"
}

mutate() {     # mutate <dir> <file> <needle> <replacement>
    python3 - "$1/$2" "$3" "$4" <<'PY'
import sys, pathlib
p, old, new = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
s = p.read_text()
if old not in s:
    print(f"MUTATION-NEEDLE-MISSING in {p.name}: {old[:70]!r}"); sys.exit(3)
p.write_text(s.replace(old, new, 1))
PY
}

# run <expect green|red> <name> <must-name-this-check> <file> <needle> <repl>
run() {
    local expect="$1" name="$2" want="$3" file="$4" needle="$5" repl="$6"
    local d="$WORK/m$((pass+fail))"
    snapshot "$d"
    if [ -n "$needle" ]; then
        if ! mutate "$d" "$file" "$needle" "$repl"; then
            echo "  ✗ $name — the mutation could not be applied (stale needle)"
            fail=$((fail+1)); return
        fi
    fi
    local out rc
    out="$(python3 "$d/tools/gates/lanes-page-truth-gate.py" 2>&1)"; rc=$?
    if [ "$expect" = green ]; then
        if [ "$rc" -eq 0 ]; then echo "  ✓ $name — stayed GREEN, as it must"; pass=$((pass+1))
        else echo "  ✗ $name — went RED on a change that breaks nothing:"
             printf '%s\n' "$out" | grep '^  FAIL' | sed 's/^/        /'; fail=$((fail+1)); fi
        return
    fi
    if [ "$rc" -eq 0 ]; then
        echo "  ✗ $name — gate stayed GREEN with the behaviour BROKEN"; fail=$((fail+1)); return
    fi
    if [ "$rc" -ne 1 ]; then
        echo "  ✗ $name — gate exited $rc (expected 1); a CANNOT-RUN is not a finding"; fail=$((fail+1)); return
    fi
    if printf '%s\n' "$out" | grep -q "FAIL  $want"; then
        echo "  ✓ $name — RED, naming: $want"; pass=$((pass+1))
    else
        echo "  ✗ $name — RED, but NOT for the stated reason. It said:"
        printf '%s\n' "$out" | grep '^  FAIL' | sed 's/^/        /'
        echo "        expected: $want"
        fail=$((fail+1))
    fi
}

echo "══ GATE 77 red-first — mutating SNAPSHOTS, never the tree under test ══"
echo "control:"
run green "baseline, unmutated" "" "" "" ""

echo
echo "the three lies of #151:"
run red "unique==0 means finished again (the root cause)" \
    "#151 a branch cut minutes ago is NOT" tools/lanes-status.sh \
    'if   [[ "$unique" == "0" && "$ag" == "none" && "$age_min" -ge 60 ]]; then' \
    'if   [[ "$unique" == "0" ]]; then'
run red "AT RISK stops requiring real work" \
    "#151 an unpushed branch with NOTHING in it is not flagged AT RISK" tools/lanes-status.sh \
    'elif [[ "$push" == "NO REMOTE" && "$unique" != "0" ]]; then' \
    'elif [[ "$push" == "NO REMOTE" ]]; then'
run red "the seat map is built from the filtered list" \
    "#151 a FINISHED seat's issue is never APPROVED" tools/lanes-page.py \
    'seat_nums = {l["branch"].split("-")[0]: l["branch"] for l in rows' \
    'seat_nums = {l["branch"].split("-")[0]: l["branch"] for l in seats'
run red "riders stop counting as work in progress" \
    "#151 a RIDER's issue is never APPROVED" tools/lanes-page.py \
    'for r in l.get("riders", []):
            seat_nums.setdefault(str(r), l["branch"])' \
    'for r in []:
            seat_nums.setdefault(str(r), l["branch"])'
run red "a parked branch stops counting as started" \
    "#151 a PARKED branch's issue is never APPROVED" tools/lanes-page.py \
    'for p_ in parked:
        n = p_["branch"].split("-")[0]' \
    'for p_ in []:
        n = p_["branch"].split("-")[0]'

echo
echo "the detector (#160, and the drift that has bitten twice in a day):"
run red "re-anchor the detector on the closing paren" \
    "a lane thinking at a raised effort reads as WORKING" tools/lanes-status.sh \
    'AGENT_RE="esc to interrupt|s · ↓ [0-9.,]+k? tokens"' \
    'AGENT_RE="esc to interrupt|s · ↓ [0-9.,]+k? tokens\\)"'
run red "the working chip stops carrying the verb" \
    "#160 the working chip mirrors the spinner verb" tools/lanes-page.py \
    '            if sb:
                label = f"{label} &middot;' \
    '            if not sb:
                label = f"{label} &middot;'
run red "the Building strip comes back" \
    "#160 there is no separate Building strip" tools/lanes-page.py \
    "    if cards:
        h.append('<h2>Seats</h2>')" \
    "    if cards:
        h.append('<b>Building</b>')
        h.append('<h2>Seats</h2>')"

echo
echo "the four chips (#159):"
run red "a PARKED: tip stops meaning retired" \
    "#159 a PARKED: tip ⇒ retired" tools/lanes-status.sh \
    'elif [[ "$subj" == "PARKED: "* ]]; then st4="retired"' \
    'elif [[ "$subj" == "PARKED: "* ]]; then st4="needs-keeper"'
run red "a lane naming Ian stops reaching him" \
    "#159 a QUESTION naming Ian ⇒ needs-you" tools/lanes-status.sh \
    'if [[ "$lreason" =~ (^|[^A-Za-z])[Ii]an([^A-Za-z]|$) ]]; then st4="needs-you"' \
    'if [[ "$lreason" =~ NEVERMATCHESANYTHING ]]; then st4="needs-you"'
run red "the verbatim reason is replaced by the bare word" \
    "#159 the lane's own question is carried verbatim" tools/lanes-status.sh \
    '                reason="$lreason"' \
    '                reason=""'
run red "a fifth chip wording appears" \
    "#159 no chip outside the ruled four exists" tools/lanes-page.py \
    '"needs-keeper": ("needs keeper", "#7fa8d9"),' \
    '"needs-keeper": ("idle — agent parked", "#7fa8d9"),'

echo
echo "the checklist (#155) and the workers view (#164):"
run red "the list renders even when nothing waits on him" \
    "nothing waiting on him ⇒ the list is ABSENT" tools/lanes-page.py \
    '    if todo:
        h.append' \
    '    if True:
        h.append'
run red "a GitHub failure goes quiet" \
    "GitHub unreadable ⇒ says so LOUDLY" tools/lanes-page.py \
    '    if not gh_ok:
        h.append' \
    '    if False:
        h.append'
run red "a question stops being mirrored as a bullet" \
    "#155 a lane's question is mirrored as a bullet" tools/lanes-page.py \
    '        if l.get("state") != "needs-you" or not l.get("reason"):' \
    '        if True:'
run red "the workers view starts printing desks" \
    "#164 a desk with no session is not listed as an agent" tools/lanes-page.py \
    '    live_agents = [l for l in seats if l.get("agent", "none") != "none"]' \
    '    live_agents = list(seats)'
run red "an idle agent goes back to saying nothing useful" \
    "#164 an idle-but-alive agent says what it waits FOR" tools/lanes-page.py \
    '    if ls == "DONE":
        return "waiting for the keeper to merge"' \
    '    if ls == "DONE":
        return "parked"'
run red "the casual form is forced onto a possessive" \
    "#164 a possessive keeps its capital" tools/lanes-page.py \
    '            and not any(q in t for q in APOSTROPHES)' \
    '            and True'

echo
echo "the poke button (#156):"
run red "the debounce is removed" \
    "#156 the immediate second tap is debounced" webroot/lanes-poke.php \
    'if (is_file($stamp) && (time() - (int)filemtime($stamp)) < DEBOUNCE) {' \
    'if (false) {'
run red "the seat name stops being validated" \
    "#156 a path-traversal seat name is refused" webroot/lanes-poke.php \
    "preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,63}\$/', \$seat) || strpos(\$seat, '..') !== false" \
    "preg_match('/^.*\$/', \$seat) && false"
run red "the nonce stops being checked" \
    "#156 a forged nonce is refused" webroot/lanes-poke.php \
    "if (!\$valid) fail(403, 'stale page — reload and retry');" \
    "\$valid = true;"
run red "the worker stops re-validating what it delivers" \
    "#156 a malformed seat in the spool is dropped" tools/lanes-poke-worker.sh \
    '    [[ "$seat" =~ ^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$ ]] || continue' \
    '    true'
run red "the watchdog stops advancing its watermark" \
    "#156 the SAME poke does not re-alarm" tools/lanes/stall-watchdog.sh \
    '      cp "$POKES" "$PMARK" 2>/dev/null' \
    '      true'

echo
echo "no-ops — these MUST stay green, or the gate is merely edit-sensitive:"
run green "a comment added to the renderer" "" tools/lanes-page.py \
    'def git_words(l):' \
    '# a comment that changes nothing at all
def git_words(l):'
run green "whitespace reflowed in the status script" "" tools/lanes-status.sh \
    'SEAT_CEILING=6     # worktrees existing' \
    'SEAT_CEILING=6         # worktrees existing'

echo
echo "──────────────────────────────────────────────────────────────"
echo "red-first: $pass proved, $fail unproved"
[ "$fail" -eq 0 ] || exit 1
