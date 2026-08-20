#!/usr/bin/env bash
# red-first for GATE 82 — proof the gate is neither always-green nor always-red.
#
# Each mutation breaks ONE behaviour and must redden the assertion that NAMES
# that behaviour. A mutation that reddens something else is as much a finding as
# one that reddens nothing: it means the assertion is measuring the wrong thing.
#
# ⚠ EVERY MUTATION IS APPLIED TO A SNAPSHOT COPY, NEVER TO THE TREE UNDER TEST,
# and never undone with `git checkout --`: checkout-from-HEAD wipes uncommitted
# work and once turned one harness bug into ten false "the assertion is
# decoration" verdicts.
#
# ⚠ MUTATIONS ARE VALID CODE THAT IS WRONG, not code that is broken. A syntax
# error reddens everything and proves nothing.
#
# Two NO-OP mutations are included and must stay GREEN. Without them, a gate that
# reddened on any edit at all would look perfectly discriminating here.
#
#   bash tools/gates/approved-autospin-redfirst.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/gate82-redfirst-XXXXXX")"
# INT and TERM as well as EXIT: a `timeout` or a Ctrl-C otherwise leaves the
# snapshot tree behind, and this harness makes one per mutation.
trap 'rm -rf "$WORK"' EXIT INT TERM
pass=0; fail=0

snapshot() {
    local d="$1"
    mkdir -p "$d/tools/gates"
    cp "$ROOT/tools/approved-watcher.sh"                 "$d/tools/"
    cp "$ROOT/tools/gates/approved-autospin-gate.py"     "$d/tools/gates/"
}

mutate() {     # mutate <dir> <file> <needle> <replacement> [all]
    python3 - "$1/$2" "$3" "$4" "${5:-one}" <<'PY'
import sys, pathlib
p, old, new, how = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
s = p.read_text()
if old not in s:
    print(f"MUTATION-NEEDLE-MISSING in {p.name}: {old[:70]!r}"); sys.exit(3)
p.write_text(s.replace(old, new) if how == "all" else s.replace(old, new, 1))
PY
}

# run <green|red> <name> <must-name-this-check> <needle> <repl>
run() {
    local expect="$1" name="$2" want="$3" needle="$4" repl="$5" how="${6:-one}"
    local d="$WORK/m$((pass+fail))"
    snapshot "$d"
    if [ -n "$needle" ]; then
        if ! mutate "$d" "tools/approved-watcher.sh" "$needle" "$repl" "$how"; then
            echo "  ✗ $name — the mutation could not be applied (stale needle)"
            fail=$((fail+1)); return
        fi
    fi
    if ! bash -n "$d/tools/approved-watcher.sh" 2>/dev/null; then
        echo "  ✗ $name — the mutation produced INVALID bash; a syntax error reddens everything and proves nothing"
        fail=$((fail+1)); return
    fi
    local out rc
    out="$(python3 "$d/tools/gates/approved-autospin-gate.py" 2>&1)"; rc=$?
    if [ "$expect" = green ]; then
        if [ "$rc" -eq 0 ]; then echo "  ✓ $name — stayed GREEN, as it must"; pass=$((pass+1))
        else
            echo "  ✗ $name — a NO-OP turned the gate RED:"
            printf '%s\n' "$out" | grep -E "^  FAIL" | sed 's/^/        /'
            fail=$((fail+1))
        fi
        return
    fi
    if [ "$rc" -eq 0 ]; then
        echo "  ✗ $name — the gate stayed GREEN. The assertion named below is decoration:"
        echo "        wanted red: $want"
        fail=$((fail+1)); return
    fi
    if [ "$rc" -ne 1 ]; then
        echo "  ✗ $name — exit $rc, which is not a finding (2 = CANNOT RUN)"
        fail=$((fail+1)); return
    fi
    if printf '%s\n' "$out" | grep -E "^  FAIL" | grep -qF "$want"; then
        echo "  ✓ $name — RED, and on the assertion that names it"
        pass=$((pass+1))
    else
        echo "  ✗ $name — RED, but NOT on '$want'. It reddened:"
        printf '%s\n' "$out" | grep -E "^  FAIL" | sed 's/^/        /'
        fail=$((fail+1))
    fi
}

echo "GATE 82 red-first — one mutation per behaviour, on a snapshot copy"
echo

# ── G1 the charter ───────────────────────────────────────────────────────────
# The plausible-helpful implementation: no charter? derive the seat from a
# worktree that matches the issue number. That is the 7/27 defect exactly — an
# agent started without ever being told what it is.
run red "G1 charterless issues get a seat derived from a stray worktree" \
    "a charterless approved issue does NOT spin" \
    '    (( ${#charters[@]} == 0 )) && continue                   # G1: bell only' \
    '    if (( ${#charters[@]} == 0 )); then
        shopt -s nullglob; _w=( "$WORKTREES/$n"-* ); shopt -u nullglob
        if (( ${#_w[@]} == 1 )); then charters=( "$PROMPTS/$(basename "${_w[0]}").md" ); else continue; fi
    fi'

run red "G1 two charters ⇒ pick the first instead of refusing" \
    "two charters for one issue ⇒ refuse, never guess" \
    '    if (( ${#charters[@]} > 1 )); then' \
    '    if (( ${#charters[@]} > 99 )); then'

# ── H4 the cap ───────────────────────────────────────────────────────────────
run red "cap is off by one (>= becomes >), at BOTH sites" \
    "at the cap, nothing spins" \
    '(( WORKING >= CAP ))' \
    '(( WORKING > CAP ))' all

run red "an unreadable cap is treated as no cap at all" \
    "an UNREADABLE capacity refuses — it is not a cap of zero" \
    'if [[ "$WORKING" == ERR || -z "${CAP:-}" || "$CAP" == ERR ]]; then' \
    'if [[ "$WORKING" == ERR-NEVER ]]; then'

# ── G3 one spin per issue ever ───────────────────────────────────────────────
run red "the one-spin log is never consulted" \
    "the second tick does NOT spin the same issue" \
    '    if grep -qx "$n" "$SPUN"; then continue; fi              # G3: one spin ever' \
    '    if grep -qx "$n-never" "$SPUN"; then continue; fi        # G3: one spin ever'

run red "the parked-branch backstop only looks at the remote" \
    "a branch that exists with NO worktree is a prior lane ⇒ refuse" \
    '        if git -C "$REPO" show-ref --verify -q "refs/heads/$lane" \
        || git -C "$REPO" show-ref --verify -q "refs/remotes/origin/$lane"; then' \
    '        if git -C "$REPO" show-ref --verify -q "refs/remotes/origin/$lane"; then'

run red "a seat that is already running is spun a second time" \
    "a seat with a live session is already running ⇒ no second spin" \
    '    if printf '"'"'%s\n'"'"' "$SESSIONS" | grep -qxF "$lane"; then continue; fi' \
    '    if printf '"'"'%s\n'"'"' "$SESSIONS" | grep -qxF "$lane-never"; then continue; fi'

# ── the mode ─────────────────────────────────────────────────────────────────
run red "the default is LIVE instead of dry-run" \
    "with no mode file at all, nothing spins" \
    'MODE="dry-run"
if [[ -f "$MODEF" ]] && grep -qx "live" "$MODEF"; then MODE="live"; fi' \
    'MODE="live"
if [[ -f "$MODEF" ]] && grep -qx "dry-run" "$MODEF"; then MODE="dry-run"; fi'

run red "a dry run burns the one-spin-ever record" \
    "…and it consumes NOTHING of the one-spin-ever record" \
    '        echo "$n" >> "$DRYLOG"' \
    '        echo "$n" >> "$DRYLOG"; echo "$n" >> "$SPUN"'

run red "any non-empty mode file arms it" \
    "stays dry — no fuzzy arming" \
    'if [[ -f "$MODEF" ]] && grep -qx "live" "$MODEF"; then MODE="live"; fi' \
    'if [[ -s "$MODEF" ]]; then MODE="live"; fi'

# ── the holds ────────────────────────────────────────────────────────────────
run red "keeper-quiet is not honoured" \
    "keeper-quiet blocks the spin (live mode)" \
    '    [[ -e "$q" ]] && HOLD="keeper-quiet is set' \
    '    [[ -e "$q.never" ]] && HOLD="keeper-quiet is set'

run red "the fleet-down (reboot) signature is not honoured" \
    "fleet-down (reboot signature) blocks the spin (live mode)" \
    'if [[ -z "$HOLD" && -s "$MANIFEST" && "$LANE_SESSIONS" -eq 0 ]]; then' \
    'if [[ -z "$HOLD" && -s "$MANIFEST" && "$LANE_SESSIONS" -eq -1 ]]; then'

run red "the load ceiling is unreachable" \
    "load over the ceiling blocks the spin" \
    "'BEGIN{exit !(l+0 > m+0)}'" \
    "'BEGIN{exit !(l+0 > m+0+1000)}'"

run red "a standing hold reposts on every tick" \
    "a standing hold is announced once, not on every tick" \
    '    [[ "$now" == "$was" ]] && return 0' \
    '    [[ "$now" == "$was" ]] && printf "" '

# ── the seat ─────────────────────────────────────────────────────────────────
run red "a worktree on the wrong branch is spun anyway" \
    "a worktree on the wrong branch ⇒ refuse, never repair" \
    '        if [[ "$br" != "$lane" ]]; then' \
    '        if false; then'

run red "the seat ceiling is off by one" \
    "a seat that must be CUT is refused at the seat ceiling" \
    '(( SEATS >= CEIL ))' \
    '(( SEATS > CEIL ))'

# 75a0fb6, live on lanes 165 and 170: a worktree cut from origin/main TRACKS
# MAIN, so a bare push from the lane targets main and bypasses the wall.
run red "the cut lane is pushed bare — its upstream stays origin/main" \
    "…and its upstream IS origin/<lane>, never origin/main" \
    'if ! git -C "$wt" push -q -u origin "$lane" >/dev/null 2>&1; then' \
    'if ! git -C "$wt" push -q origin "$lane" >/dev/null 2>&1; then'

# ── the production defaults ──────────────────────────────────────────────────
# THE REAL BUG, restored verbatim. It surfaces FIRST in leg 3, not leg 7 — the
# stray } is appended to the OVERRIDE too, so the override-driven legs are
# corrupted by it as well. Recorded here as measured, because the opposite was
# assumed while writing the gate and it was wrong.
run red "the tmux format brace closes the parameter expansion (the real bug)" \
    "a seat with a live session is already running" \
    'SESSIONS_CMD="${LG_AW_SESSIONS_CMD:-}"
[[ -n "$SESSIONS_CMD" ]] || SESSIONS_CMD="tmux list-sessions -F #{session_name}"' \
    'SESSIONS_CMD="${LG_AW_SESSIONS_CMD:-tmux list-sessions -F #{session_name}}"'

# ONLY the production default breaks here — every override-driven leg above is
# untouched, so this is the mutation that proves leg 7 is load-bearing rather
# than a restatement of leg 3.
run red "the default session command stops listing sessions (overrides untouched)" \
    "the DEFAULT session command sees a real tmux session" \
    '[[ -n "$SESSIONS_CMD" ]] || SESSIONS_CMD="tmux list-sessions -F #{session_name}"' \
    '[[ -n "$SESSIONS_CMD" ]] || SESSIONS_CMD="true"'

# ── the no-ops ───────────────────────────────────────────────────────────────
run green "NO-OP: the load ceiling written as 4.0 instead of 4" "" \
    'LOAD_MAX="${LG_AW_LOAD_MAX:-4}"' \
    'LOAD_MAX="${LG_AW_LOAD_MAX:-4.0}"'

run green "NO-OP: the two branch-existence reads swapped (an OR, either order)" "" \
    '        if git -C "$REPO" show-ref --verify -q "refs/heads/$lane" \
        || git -C "$REPO" show-ref --verify -q "refs/remotes/origin/$lane"; then' \
    '        if git -C "$REPO" show-ref --verify -q "refs/remotes/origin/$lane" \
        || git -C "$REPO" show-ref --verify -q "refs/heads/$lane"; then'

echo
echo "red-first: $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
